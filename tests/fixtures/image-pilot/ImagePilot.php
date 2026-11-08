<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ResponsiveImage.php';
require_once __DIR__.'/ImportedImage.php';

/** Default-off ONE primary eBay image pilot. No schema changes or automatic retries. */
class ImagePilot
{
    public function __construct(private \PDO $db,private string $site,private string $private,private string $input,private bool $enabled=false) {}
    private function ready():void {
        if(!$this->enabled || PHP_SAPI!=='cli')throw new \RuntimeException('Pilot disabled or non-CLI');
        if(DIRECTORY_SEPARATOR!=='/' || !function_exists('fsync'))throw new \RuntimeException('Verified POSIX fsync runtime required');
        if($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='sqlite' || $this->db->getAttribute(\PDO::ATTR_ERRMODE)!==\PDO::ERRMODE_EXCEPTION || $this->db->inTransaction())throw new \RuntimeException('Owned SQLite connection required');
        foreach([$this->site,$this->private,$this->input,$this->private.'/tmp',$this->site.'/gallery',$this->site.'/gallery/responsive']as$p){clearstatcache(true,$p);if(!is_dir($p)||is_link($p)||realpath($p)!==$p)throw new \RuntimeException('Regular canonical directories required');}
        if($this->private===$this->site || str_starts_with($this->private,$this->site.'/') || str_starts_with($this->site,$this->private.'/') || !str_starts_with($this->input,$this->private.'/') || (fileperms($this->private)&0077)!==0 || (fileperms($this->input)&0077)!==0)throw new \RuntimeException('Private staging/input isolation required');
        if(sys_get_temp_dir()!==$this->private.'/tmp' || (fileperms($this->private.'/tmp')&0077)!==0)throw new \RuntimeException('Dedicated private PHP temp directory required');
        $wait=$this->db->query('PRAGMA busy_timeout')->fetchColumn();if(!is_int($wait)||$wait<0||$wait>1000)throw new \RuntimeException('SQLite busy timeout must be <=1second');
        if(disk_free_space($this->private)<67108864 || disk_free_space($this->private.'/tmp')<67108864 || disk_free_space($this->site.'/gallery/responsive')<67108864)throw new \RuntimeException('64MiB free-space headroom required');
    }
    private static function id(string $id):void {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid job identity');}
    private function job(string $id):string {self::id($id);return $this->private.'/job-'.$id.'.json';}
    private static function read(string $path,int $max):?string {
        clearstatcache(true,$path);if(is_link($path))throw new \RuntimeException('Symlink refused');
        if(!file_exists($path))return null;if(!is_file($path))throw new \RuntimeException('Regular file required');
        $v=file_get_contents($path,false,null,0,$max+1);if(!is_string($v)||strlen($v)>$max)throw new \RuntimeException('Bounded file read failed');return $v;
    }
    private static function syncDir(string $path):void {$h=fopen($path,'r');if(!$h)throw new \RuntimeException('Directory sync open failed');try{if(!fsync($h))throw new \RuntimeException('Directory sync failed');}finally{fclose($h);}}
    private static function syncFile(string $path):void {$h=fopen($path,'r');if(!$h)throw new \RuntimeException('File sync open failed');try{if(!fsync($h))throw new \RuntimeException('File sync failed');}finally{fclose($h);}}
    private static function write(string $target,string $bytes,int $mode):void {
        if(is_link($target))throw new \RuntimeException('Symlink output refused');
        $temp=dirname($target).'/.pilot-'.bin2hex(random_bytes(16));$h=fopen($temp,'x+b');if(!$h)throw new \RuntimeException('Exclusive temporary file failed');
        try{if(!chmod($temp,$mode)||fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!fsync($h))throw new \RuntimeException('Durable file write failed');}finally{fclose($h);}
        if(!rename($temp,$target))throw new \RuntimeException('Atomic rename failed');self::syncDir(dirname($target));
    }
    private function save(string $id,array $job):void {$raw=json_encode($job,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($raw)>262144)throw new \RuntimeException('Journal bound');self::write($this->job($id),$raw,0600);}
    private static function lock(string $path,bool $nonblock=false) {
        if(is_link($path))throw new \RuntimeException('Symlink lock refused');$h=fopen($path,'c');if(!$h)throw new \RuntimeException('Lock open failed');
        if(!flock($h,LOCK_EX|($nonblock?LOCK_NB:0))){fclose($h);throw new \RuntimeException('Worker/publication lock busy');}return $h;
    }
    private function product(string $id):array {
        if(!preg_match('/^[1-9][0-9]{0,18}$/D',$id))throw new \RuntimeException('Product identity bound');
        $q=$this->db->prepare('SELECT typeof(image_url),length(CAST(image_url AS BLOB)),typeof(images),length(CAST(images AS BLOB)) FROM products WHERE id=?');$q->execute([$id]);$b=$q->fetch(\PDO::FETCH_NUM);
        if(!$b || !in_array($b[0],['text','null'],true) || !in_array($b[2],['text','null'],true) || ($b[1]??0)>2048 || ($b[3]??0)>131072)throw new \RuntimeException('Product snapshot bound');
        $q=$this->db->prepare('SELECT id,is_active,show_on_website,image_url,images FROM products WHERE id=?');$q->execute([$id]);$p=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$p || (int)$p['is_active']!==1 || (int)$p['show_on_website']!==1)throw new \RuntimeException('Current visible product required');
        $p['id']=(string)$p['id'];$p['is_active']=(int)$p['is_active'];$p['show_on_website']=(int)$p['show_on_website'];return $p;
    }
    private static function receiptName(array $r):string {return 'remote-'.hash('sha256',$r['product_id']."\n".$r['source_url']).'.json';}
    private static function exclusion(?string $raw):void {if($raw===null)return;$r=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($r)||($r['excluded']??false)===true)throw new \RuntimeException('Existing exclusion/invalid receipt blocks pilot');}
    private function inputCheck(array $r):void {
        $url=$r['source_url']??null;$p=is_string($url)?parse_url($url):false;
        if(!is_string($r['product_id']??null)||!is_string($url)||strlen($url)>2048||!is_array($p)||($p['scheme']??'')!=='https'||($p['host']??'')!=='i.ebayimg.com'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment'])||preg_match('~[\x00-\x20\x7f\\\\]~',$url))throw new \RuntimeException('Exact eBay source required');
        $v=$r['provenance']??[];if(($v['basis']??null)!=='existing_catalog'||($v['optimization_authorized']??null)!==true||($v['excluded']??null)!==false||!is_string($v['reference']??null)||trim($v['reference'])==='')throw new \RuntimeException('Fresh verified provenance required');
        $at=strtotime((string)($r['verified_at']??''));$until=strtotime((string)($r['valid_until']??''));$now=time();if(!$at||!$until||$at>$now||$at<$now-900||$until<=$now||$until>$at+86400)throw new \RuntimeException('Pilot verification must be within15min and expire within24h');
        $rel=$r['local_file']??null;if(!is_string($rel)||!preg_match('/^[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$/D',$rel))throw new \RuntimeException('One simple input basename required');
        $bytes=self::read($this->input.'/'.$rel,2097152);if($bytes===null||hash('sha256',$bytes)!==($r['source_sha256']??null))throw new \RuntimeException('Verified source bytes missing/changed');
        $size=getimagesizefromstring($bytes);if(!$size||$size[0]<1||$size[1]<1||$size[0]*$size[1]>4000000)throw new \RuntimeException('Pilot pixel bound');
    }
    public function enqueue(string $id,array $record):array {
        $this->ready();$lock=self::lock($this->private.'/.worker.lock',true);
        try{if(self::read($this->job($id),262144)!==null)throw new \RuntimeException('Job already exists');$this->inputCheck($record);$p=$this->product($record['product_id']);if($p['image_url']!==$record['source_url'])throw new \RuntimeException('Primary catalog source changed');
            $before=self::read($this->site.'/gallery/responsive/'.self::receiptName($record),16384);self::exclusion($before);
            $job=['version'=>1,'id'=>$id,'state'=>'queued','record'=>$record,'product'=>$p,'before_receipt'=>$before,'queued_at'=>time()];$this->save($id,$job);return ['id'=>$id,'state'=>'queued'];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    protected function checkpoint(string $point):void {} // Test-only subclass fault injection; no callback input.
    private function stage(array $job,string $work):array {
        $r=$job['record'];$result=ImportedImage::import($r,$this->input,$work);
        if(($result['status']??null)!=='ready')throw new \RuntimeException('Private builder did not produce ready output');
        $raw=self::read($work.'/gallery/responsive/'.self::receiptName($r),16384);if($raw===null)throw new \RuntimeException('Stage receipt missing');$receipt=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($receipt)||($receipt['product_id']??null)!==$r['product_id']||($receipt['source_url']??null)!==$r['source_url']||($receipt['source_sha256']??null)!==$r['source_sha256']||($receipt['optimization_authorized']??null)!==true||($receipt['provenance_basis']??null)!=='existing_catalog'||($receipt['excluded']??false)!==false||($receipt['verified_at']??null)!==strtotime($r['verified_at'])||($receipt['valid_until']??null)!==strtotime($r['valid_until'])||$receipt['valid_until']<=time()||!is_int($receipt['width']??null)||!is_int($receipt['height']??null)||$receipt['width']<1||$receipt['height']<1||$receipt['width']*$receipt['height']>4000000||!is_int($receipt['original_bytes']??null)||$receipt['original_bytes']>2097152||!is_array($receipt['variants']??null)||count($receipt['variants'])<1||count($receipt['variants'])>6)throw new \RuntimeException('Stage receipt binding failed');
        $files=[];$total=0;
        foreach($receipt['variants']as$width=>$meta){if(!is_int($width)||!in_array($width,ResponsiveImage::WIDTHS,true)||$width>=$receipt['width']||!is_array($meta)||!is_int($meta['bytes']??null)||$meta['bytes']<1||$meta['bytes']>=$receipt['original_bytes']||!is_string($meta['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$meta['sha256']))throw new \RuntimeException('Variant metadata');
            $name='remote-'.$r['source_sha256'].'-'.$width.'.webp';$meta['width']=$width;$meta['height']=max(1,(int)round($receipt['height']*$width/$receipt['width']));self::variant($work.'/gallery/responsive/'.$name,$meta);$total+=$meta['bytes'];if($total>16777216)throw new \RuntimeException('Stage aggregate byte bound');$files[$name]=$meta;
        }
        return ['receipt'=>$raw,'files'=>$files];
    }
    private static function variant(string $path,array $m):string {$bytes=self::read($path,2097152);$size=$bytes===null?false:getimagesizefromstring($bytes);if($bytes===null||strlen($bytes)!==$m['bytes']||hash('sha256',$bytes)!==$m['sha256']||!$size||$size[2]!==IMAGETYPE_WEBP||$size[0]!==$m['width']||$size[1]!==$m['height'])throw new \RuntimeException('Variant verification failed');return $bytes;}
    public function run(string $id):array {
        $this->ready();$worker=self::lock($this->private.'/.worker.lock',true);$publication=null;$catalog=false;
        try{
            $raw=self::read($this->job($id),262144);if($raw===null)throw new \RuntimeException('No queued job');$job=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
            if(!is_array($job)||($job['version']??null)!==1||($job['id']??null)!==$id||!in_array($job['state']??null,['queued','running','publishing','done'],true))throw new \RuntimeException('Invalid job');
            if($job['state']!=='queued')return ['id'=>$id,'state'=>$job['state'],'retry_authorized'=>false];
            $r=$job['record'];$this->inputCheck($r);if($this->product($r['product_id'])!==$job['product'])throw new \RuntimeException('Catalog changed before claim');
            $target=$this->site.'/gallery/responsive/'.self::receiptName($r);if(self::read($target,16384)!==$job['before_receipt'])throw new \RuntimeException('Receipt changed before claim');self::exclusion($job['before_receipt']);
            $job['state']='running';$job['started_at']=time();$this->save($id,$job);$this->checkpoint('running');
            $work=$this->private.'/stage-'.$id;if(file_exists($work)||is_link($work)||!mkdir($work,0700))throw new \RuntimeException('Fresh private stage required');
            $stage=$this->stage($job,$work);$this->checkpoint('prepared');
            $publication=self::lock($this->site.'/gallery/responsive/.remote.lock',true);
            $this->db->exec('BEGIN IMMEDIATE');$catalog=true;
            if($this->product($r['product_id'])!==$job['product'])throw new \RuntimeException('Catalog changed before publication');
            $this->inputCheck($r);if(self::read($target,16384)!==$job['before_receipt'])throw new \RuntimeException('Receipt/exclusion changed before publication');self::exclusion($job['before_receipt']);
            $job['state']='publishing';$job['stage']=$work;$job['after_receipt']=$stage['receipt'];$job['files']=$stage['files'];$job['publishing_at']=time();$this->save($id,$job);$this->checkpoint('publishing');
            foreach($stage['files']as$name=>$m){$bytes=self::variant($work.'/gallery/responsive/'.$name,$m);$out=$this->site.'/gallery/responsive/'.$name;$existing=self::read($out,2097152);if($existing!==null){if($existing!==$bytes)throw new \RuntimeException('Content-addressed collision');self::syncFile($out);self::syncDir(dirname($out));}else self::write($out,$bytes,0644);self::variant($out,$m);$this->checkpoint('variant');}
            if(strtotime($r['valid_until'])<=time()||self::read($target,16384)!==$job['before_receipt'])throw new \RuntimeException('Receipt became stale/changed');
            self::write($target,$stage['receipt'],0644);$this->checkpoint('receipt');if(self::read($target,16384)!==$stage['receipt'])throw new \RuntimeException('Public receipt readback failed');
            $this->db->exec('COMMIT');$catalog=false;$job['state']='done';$job['completed_at']=time();$this->save($id,$job);return ['id'=>$id,'state'=>'done','variants'=>count($stage['files']),'retry_authorized'=>false];
        }finally{
            if($catalog){try{$this->db->exec('ROLLBACK');}catch(\Throwable $e){/* state remains unknown; never requeue */}}
            if(is_resource($publication)){flock($publication,LOCK_UN);fclose($publication);}flock($worker,LOCK_UN);fclose($worker);
        }
    }
    /** Observation only. No automatic recovery, rollback, deletion or retry permission. */
    public function inspect(string $id):array {
        $this->ready();$lock=self::lock($this->private.'/.worker.lock',true);
        try{$raw=self::read($this->job($id),262144);if($raw===null)throw new \RuntimeException('Unknown job');$j=json_decode($raw,true,24,JSON_THROW_ON_ERROR);$out=['id'=>$id,'state'=>$j['state'],'retry_authorized'=>false];
            if(isset($j['after_receipt'])){$current=self::read($this->site.'/gallery/responsive/'.self::receiptName($j['record']),16384);$out['receipt_observation']=$current===$j['after_receipt']?'matches_after':($current===$j['before_receipt']?'matches_before':'conflict');}
            return $out;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
