<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ImageSourceIntake.php';

/** One fixed private pilot state directory; never creates or modifies public receipts. */
final class PosixImageIntakeStore implements ImageIntakeStore
{
    private $lock=null;
    private $journal=null;
    private string $job='';
    public function __construct(private string $state,private string $site)
    {
        if(DIRECTORY_SEPARATOR!=='/' || !function_exists('posix_geteuid') || !function_exists('fsync'))throw new \RuntimeException('Verified POSIX runtime required');
        foreach([$state,$state.'/inputs',$site,$site.'/gallery',$site.'/gallery/responsive']as$p){
            if(is_link($p)||!is_dir($p)||realpath($p)!==$p)throw new \RuntimeException('Canonical directories required');
            for($a=$p;$a!==dirname($a);$a=dirname($a)){if(is_link($a)||(fileperms($a)&0022)!==0||!in_array(fileowner($a),[0,posix_geteuid()],true))throw new \RuntimeException('Trusted ancestors required');}
        }
        if(str_starts_with($state,$site.'/')||str_starts_with($site,$state.'/')||$state===$site||(fileperms($state)&0077)!==0||(fileperms($state.'/inputs')&0077)!==0)throw new \RuntimeException('Private state isolation required');
        if(disk_free_space($state)<67108864)throw new \RuntimeException('64MiB filesystem headroom required');
    }
    private static function sync(string $dir):void{$h=fopen($dir,'r');try{if(!$h||!fsync($h))throw new \RuntimeException('Directory sync failed');}finally{if(is_resource($h))fclose($h);}}
    private static function exclusive(string $path,string $data):void
    {
        if(file_exists($path)||is_link($path))throw new \RuntimeException('Existing/uncertain output preserved');
        $h=fopen($path,'x+b');if(!$h)throw new \RuntimeException('Exclusive output failed');
        try{if(!chmod($path,0600)||fwrite($h,$data)!==strlen($data)||!fflush($h)||!fsync($h))throw new \RuntimeException('Durable private output failed');}finally{fclose($h);}self::sync(dirname($path));
    }
    public function receipt(string $productId,string $source):?string
    {
        $p=$this->site.'/gallery/responsive/remote-'.hash('sha256',$productId."\n".$source).'.json';
        if(is_link($p))throw new \RuntimeException('Receipt symlink');if(!file_exists($p))return null;if(!is_file($p))throw new \RuntimeException('Receipt is not regular');
        $v=file_get_contents($p,false,null,0,16385);if($v===false||strlen($v)>16384)throw new \RuntimeException('Receipt read bound');return $v;
    }
    public function claim(string $job,array $context):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$job))throw new \RuntimeException('Invalid job identity');
        if($this->lock!==null)throw new \RuntimeException('Store already claimed');$path=$this->state.'/.intake.lock';
        if(is_link($path))throw new \RuntimeException('Lock symlink');$h=fopen($path,'c');if(!$h)throw new \RuntimeException('Private lock open failed');
        if(!flock($h,LOCK_EX|LOCK_NB)){fclose($h);throw new \RuntimeException('Intake busy');}
        $this->lock=$h;
        try{
            foreach(['intake-attempt.jsonl','approved-record.json','intake-record-staged.json','job-'.$job.'.json']as$n)if(file_exists($this->state.'/'.$n)||is_link($this->state.'/'.$n))throw new \RuntimeException('Existing attempt/output: no retry');
            if(array_values(array_diff(scandir($this->state.'/inputs'),['.','..']))!==[])throw new \RuntimeException('Uncertain input output preserved');
            $this->job=$job;self::exclusive($this->state.'/intake-attempt.jsonl',json_encode(['state'=>'receiving','job'=>$job,'context'=>$context,'retry_authorized'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
            $this->journal=fopen($this->state.'/intake-attempt.jsonl','ab');if(!$this->journal)throw new \RuntimeException('Journal reopen failed');
        }catch(\Throwable $e){$this->release();throw $e;}
    }
    public function event(string $state,array $evidence=[]):void
    {
        if(!is_resource($this->journal))throw new \RuntimeException('No owned intake journal');$line=json_encode(['state'=>$state,'job'=>$this->job,'utc'=>gmdate('c'),'evidence'=>$evidence,'retry_authorized'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        if(strlen($line)>262144||fwrite($this->journal,$line)!==strlen($line)||!fflush($this->journal)||!fsync($this->journal))throw new \RuntimeException('Intake journal durability failed');
    }
    public function original(string $job,string $extension,string $bytes):string
    {
        if(!is_resource($this->lock)||!is_resource($this->journal)||$job!==$this->job||!preg_match('/^[a-f0-9]{32}$/D',$job)||!in_array($extension,['png','jpg','webp'],true)||$bytes===''||strlen($bytes)>ImageSourceIntake::MAX_BYTES)throw new \RuntimeException('Fixed original output required');
        $name='source-'.$job.'.'.$extension;self::exclusive($this->state.'/inputs/'.$name,$bytes);return $name;
    }
    public function ready(array $record):void
    {
        if(!is_resource($this->lock)||!is_resource($this->journal)||($record['intake']['job']??null)!==$this->job)throw new \RuntimeException('Owned matching claim required');
        $target=$this->state.'/approved-record.json';$tmp=$this->state.'/intake-record-staged.json';
        self::exclusive($tmp,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
        // link is atomic and fails if a concurrent writer created target. No replace/overwrite.
        if(!link($tmp,$target))throw new \RuntimeException('Record target exists or publication uncertain');self::sync($this->state);
        // Keep the staged record as evidence. The ready event is the intake commit marker.
    }
    public function release():void
    {
        if(is_resource($this->journal))fclose($this->journal);$this->journal=null;
        if(is_resource($this->lock)){flock($this->lock,LOCK_UN);fclose($this->lock);}$this->lock=null;$this->job='';
    }
}

