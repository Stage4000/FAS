<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ImmutableImageSnapshot.php';
require_once __DIR__.'/../image-auto-pilot/ImageSourceSelection.php';
require_once __DIR__.'/../image-auto-pilot/ImageSourceIntake.php';
require_once __DIR__.'/../image-auto-pilot/ResponsiveImage.php';

/** Default-off one immutable LOCAL image job. No network or queue/ledger caller. */
class ImmutableLocalPilot
{
    private ImmutableImageSnapshot $reader;
    public function __construct(private \PDO $db,private string $site,private string $state,private bool $enabled=false)
    {
        if(!$enabled||PHP_SAPI!=='cli')throw new \RuntimeException('Local pilot disabled');
        $this->reader=new ImmutableImageSnapshot($site,true);
    }
    private function ready():void
    {
        if(!$this->enabled||!function_exists('fsync')||!function_exists('posix_geteuid')||$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='sqlite'
            ||$this->db->getAttribute(\PDO::ATTR_ERRMODE)!==\PDO::ERRMODE_EXCEPTION||$this->db->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES)||$this->db->inTransaction())throw new \RuntimeException('Owned strict POSIX SQLite runtime required');
        foreach([$this->state,$this->state.'/tmp',$this->site.'/gallery/responsive']as$p){
            clearstatcache(true,$p);if(is_link($p)||!is_dir($p)||realpath($p)!==$p)throw new \RuntimeException('Canonical directories required');
            for($a=$p;$a!==dirname($a);$a=dirname($a))if(is_link($a)||(fileperms($a)&0022)!==0||!in_array(fileowner($a),[0,posix_geteuid()],true))throw new \RuntimeException('Trusted ancestors required');
            if(disk_free_space($p)<67108864)throw new \RuntimeException('64MiB filesystem headroom required');
        }
        if($this->state===$this->site||str_starts_with($this->state,$this->site.'/')||str_starts_with($this->site,$this->state.'/')
            ||(fileperms($this->state)&0077)!==0||(fileperms($this->state.'/tmp')&0077)!==0||sys_get_temp_dir()!==$this->state.'/tmp')throw new \RuntimeException('Private state/temp isolation required');
        $wait=$this->db->query('PRAGMA busy_timeout')->fetchColumn();if(!is_int($wait)||$wait<0||$wait>1000)throw new \RuntimeException('Bounded SQLite wait required');
    }
    private static function id(string$id):void{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \RuntimeException('Fixed job ID required');}
    private function job(string$id):string{self::id($id);return $this->state.'/local-job-'.$id.'.json';}
    private static function read(string$p,int$max):?string{
        clearstatcache(true,$p);if(is_link($p))throw new \RuntimeException('Symlink refused');if(!file_exists($p))return null;
        if(!is_file($p))throw new \RuntimeException('Regular file required');$b=file_get_contents($p,false,null,0,$max+1);if(!is_string($b)||strlen($b)>$max)throw new \RuntimeException('Read bound');return$b;
    }
    private static function sync(string$p):void{$h=fopen($p,'r');if(!$h)throw new \RuntimeException('Sync open');try{if(!fsync($h))throw new \RuntimeException('Sync failed');}finally{fclose($h);}}
    private static function exclusive(string$p,string$b,int$mode):void{
        if(file_exists($p)||is_link($p))throw new \RuntimeException('Existing output preserved');$h=fopen($p,'x+b');if(!$h)throw new \RuntimeException('Exclusive open');
        try{if(!chmod($p,$mode)||fwrite($h,$b)!==strlen($b)||!fflush($h)||!fsync($h))throw new \RuntimeException('Durable write failed');}finally{fclose($h);}self::sync(dirname($p));
    }
    private function save(string$id,array$j):void{
        $raw=json_encode($j,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($raw)>262144)throw new \RuntimeException('Journal bound');
        $tmp=$this->state.'/.local-journal-'.bin2hex(random_bytes(16));self::exclusive($tmp,$raw,0600);$path=$this->job($id);
        if(is_link($path)||!rename($tmp,$path))throw new \RuntimeException('Journal replacement failed');self::sync($this->state);
    }
    private static function lock(string$p){if(is_link($p))throw new \RuntimeException('Lock symlink');$h=fopen($p,'c');if(!$h)throw new \RuntimeException('Lock open');if(!flock($h,LOCK_EX|LOCK_NB)){fclose($h);throw new \RuntimeException('Busy');}return$h;}
    private function product(string$id):array{
        if(!preg_match('/^[1-9][0-9]{0,18}$/D',$id))throw new \RuntimeException('Product identity bound');
        $q=$this->db->prepare("SELECT id,is_active,show_on_website,CASE WHEN typeof(image_url) IN ('text','null') AND COALESCE(length(CAST(image_url AS BLOB)),0)<=2048 THEN image_url ELSE NULL END image_url,CASE WHEN typeof(images) IN ('text','null') AND COALESCE(length(CAST(images AS BLOB)),0)<=131072 THEN images ELSE NULL END images,CASE WHEN typeof(image_url) IN ('text','null') AND COALESCE(length(CAST(image_url AS BLOB)),0)<=2048 AND typeof(images) IN ('text','null') AND COALESCE(length(CAST(images AS BLOB)),0)<=131072 THEN 1 ELSE 0 END valid FROM main.products WHERE id=?");
        $q->execute([$id]);$p=$q->fetch(\PDO::FETCH_ASSOC);if(!$p||$p['valid']!==1||$p['is_active']!==1||$p['show_on_website']!==1)throw new \RuntimeException('Visible bounded catalog required');unset($p['valid']);$p['id']=(string)$p['id'];return$p;
    }
    public function enqueue(string$id,string$productId,string$source,array$selector,array$provenance):array{
        $this->ready();self::id($id);$lock=self::lock($this->state.'/.local-worker.lock');
        try{
            if(self::read($this->job($id),262144)!==null)throw new \RuntimeException('Existing job preserved');
            if(($provenance['optimization_authorized']??null)!==true||($provenance['excluded']??null)!==false||!is_string($provenance['reference']??null)||trim($provenance['reference'])===''||strlen($provenance['reference'])>2048)throw new \RuntimeException('Explicit provenance required');
            $selection=['source_selector'=>$selector];ImageSourceSelection::selector($selection);$p=$this->product($productId);if(ImageSourceSelection::selected($p,$selection)!==$source)throw new \RuntimeException('Selected catalog source differs');
            $s=$this->reader->read($source);$snapshot=$this->state.'/local-source-'.$id;self::exclusive($snapshot,$s['bytes'],0600);unset($s['bytes']);
            $j=['version'=>1,'id'=>$id,'state'=>'queued','product'=>$p,'selection'=>$selection,'snapshot'=>$s,'provenance'=>$provenance,'queued_at'=>time()];$this->save($id,$j);
            return ['id'=>$id,'state'=>'queued','retry_authorized'=>false];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    protected function checkpoint(string$p):void{}
    private function original(array$j):array{
        $s=$j['snapshot'];$b=self::read($this->state.'/local-source-'.$j['id'],2097152);if($b===null)throw new \RuntimeException('Private original missing');$s['bytes']=$b;$this->reader->assertCurrent($s);return$s;
    }
    private static function verifyVariant(string$p,array$m):string{
        $b=self::read($p,2097152);$s=$b===null?false:getimagesizefromstring($b);if($b===null||strlen($b)!==$m['bytes']||hash('sha256',$b)!==$m['sha256']||!$s||$s[2]!==IMAGETYPE_WEBP||$s[0]!==$m['width']||$s[1]!==$m['height'])throw new \RuntimeException('Variant verification failed');return$b;
    }
    public function run(string$id):array{
        $this->ready();$worker=self::lock($this->state.'/.local-worker.lock');$public=null;$transaction=false;
        try{
            $raw=self::read($this->job($id),262144);if($raw===null)throw new \RuntimeException('Unknown job');$j=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
            if(($j['version']??null)!==1||($j['id']??null)!==$id||!in_array($j['state']??null,['queued','running','publishing','done'],true))throw new \RuntimeException('Invalid journal');
            if($j['state']!=='queued')return ['id'=>$id,'state'=>$j['state'],'retry_authorized'=>false];
            if(!is_int($j['queued_at'])||$j['queued_at']>time()||$j['queued_at']<time()-900)throw new \RuntimeException('Stale queued proof');
            $v=$j['provenance']??null;if(!is_array($v)||($v['optimization_authorized']??null)!==true||($v['excluded']??null)!==false||!is_string($v['reference']??null)||trim($v['reference'])===''||strlen($v['reference'])>2048)throw new \RuntimeException('Queued provenance invalid');
            if($this->product($j['product']['id'])!==$j['product']||ImageSourceSelection::selected($j['product'],$j['selection'])!==$j['snapshot']['source'])throw new \RuntimeException('Catalog changed before claim');
            $s=$this->original($j);$j['state']='running';$this->save($id,$j);$this->checkpoint('running');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($s['bytes']);$image=ImageSourceIntake::inspect($s['bytes'],$mime);
            $stage=$this->state.'/local-stage-'.$id;if(file_exists($stage)||is_link($stage))throw new \RuntimeException('Existing stage preserved');
            foreach([$stage,$stage.'/gallery',$stage.'/gallery/uploads',$stage.'/gallery/uploads/immutable',$stage.'/gallery/responsive']as$p)if(!mkdir($p,0700))throw new \RuntimeException('Stage mkdir');
            $copy=$stage.$s['source'];self::exclusive($copy,$s['bytes'],0600);if(!touch($copy,$s['identity']['mtime']))throw new \RuntimeException('Snapshot mtime');self::sync($copy);
            $key=hash('sha256',$s['source'].'|'.$s['identity']['mtime'].'|'.strlen($s['bytes']));$built=ResponsiveImage::build($s['source'],$stage);
            if(($built['status']??null)!=='ready'||!$built['variant_bytes'])throw new \RuntimeException('No beneficial supported variants');
            $files=[];$total=0;
            foreach($built['variant_bytes']as$width=>$length){$width=(int)$width;if(!in_array($width,ResponsiveImage::WIDTHS,true)||$width>=$image['width']||$length<1||$length>=strlen($s['bytes']))throw new \RuntimeException('Stage bounds');$name=$key.'-'.$width.'.webp';$b=self::read($stage.'/gallery/responsive/'.$name,2097152);if($b===null||strlen($b)!==$length||strlen($b)>=strlen($s['bytes']))throw new \RuntimeException('Stage readback size differs');$m=['bytes'=>strlen($b),'sha256'=>hash('sha256',$b),'width'=>$width,'height'=>max(1,(int)round($image['height']*$width/$image['width']))];self::verifyVariant($stage.'/gallery/responsive/'.$name,$m);$files[$name]=$m;$total+=strlen($b);}
            if($total>16777216)throw new \RuntimeException('Aggregate bound');$this->checkpoint('prepared');
            $public=self::lock($this->site.'/gallery/responsive/.build.lock');$this->db->exec('BEGIN IMMEDIATE');$transaction=true;
            if($this->product($j['product']['id'])!==$j['product'])throw new \RuntimeException('Catalog changed before publication');$this->original($j);
            $j['state']='publishing';$j['stage']=$stage;$j['files']=$files;$this->save($id,$j);$this->checkpoint('publishing');
            foreach($files as$name=>$m){$b=self::verifyVariant($stage.'/gallery/responsive/'.$name,$m);$target=$this->site.'/gallery/responsive/'.$name;$existing=self::read($target,2097152);
                if($existing!==null){if($existing!==$b)throw new \RuntimeException('Existing derivative collision');self::sync($target);}
                else{$tmp=$this->site.'/gallery/responsive/.local-'.bin2hex(random_bytes(16));self::exclusive($tmp,$b,0644);if(!link($tmp,$target))throw new \RuntimeException('No-clobber publication uncertain');self::sync(dirname($target));if(!unlink($tmp))throw new \RuntimeException('Owned temporary unlink failed');self::sync(dirname($target));}
                self::verifyVariant($target,$m);$this->checkpoint('variant');}
            $this->original($j);$this->db->exec('COMMIT');$transaction=false;$j['state']='done';$this->save($id,$j);
            return ['id'=>$id,'state'=>'done','variants'=>count($files),'retry_authorized'=>false];
        }finally{if($transaction)try{$this->db->exec('ROLLBACK');}catch(\Throwable$e){}if(is_resource($public)){flock($public,LOCK_UN);fclose($public);}flock($worker,LOCK_UN);fclose($worker);}
    }
    public function inspect(string$id):array{
        $this->ready();$h=self::lock($this->state.'/.local-worker.lock');try{$raw=self::read($this->job($id),262144);if($raw===null)throw new \RuntimeException('Unknown job');$j=json_decode($raw,true,24,JSON_THROW_ON_ERROR);return ['id'=>$id,'state'=>$j['state'],'files'=>$j['files']??[],'retry_authorized'=>false];}finally{flock($h,LOCK_UN);fclose($h);}
    }
}
