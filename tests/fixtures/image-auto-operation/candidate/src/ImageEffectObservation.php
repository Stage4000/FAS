<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ImageIntakeBinding.php';
require_once __DIR__.'/ImageSourceIntake.php';

/** Read-only remote effect observation. Locks remain held until release/destruction. */
final class ImageEffectObservation
{
    private ?array $verifiedContext=null;
    private $worker=null;
    private $remote=null;
    private ?ImageIntakeBinding $intake=null;
    public function __construct(private string $state,private string $site,bool $enabled=false)
    {
        if(!$enabled||PHP_SAPI!=='cli'||DIRECTORY_SEPARATOR!=='/'||!function_exists('posix_geteuid')||!class_exists('finfo'))throw new \RuntimeException('Explicit POSIX/fileinfo observer required');
        foreach([$state,$state.'/inputs',$site,$site.'/gallery',$site.'/gallery/responsive']as$p){
            clearstatcache(true,$p);if(is_link($p)||!is_dir($p)||realpath($p)!==$p)throw new \RuntimeException('Canonical observation roots required');
            for($a=$p;$a!==dirname($a);$a=dirname($a)){ $m=fileperms($a);if(is_link($a)||!is_int($m)||($m&0022)!==0||!in_array(fileowner($a),[0,posix_geteuid()],true))throw new \RuntimeException('Trusted observation ancestors required'); }
        }
        if($state===$site||str_starts_with($state,$site.'/')||str_starts_with($site,$state.'/')||(fileperms($state)&0077)!==0||(fileperms($state.'/inputs')&0077)!==0)throw new \RuntimeException('Private observation isolation required');
        try{$this->worker=self::lock($state.'/.worker.lock');$this->intake=new ImageIntakeBinding($state,$state.'/inputs');$this->remote=self::lock($site.'/gallery/responsive/.remote.lock');}catch(\Throwable$e){$this->release();throw$e;}
    }
    private static function lock(string$p){
        clearstatcache(true,$p);if(is_link($p)||!is_file($p))throw new \RuntimeException('Existing observation lock required');$h=fopen($p,'rb');if(!$h)throw new \RuntimeException('Read-only lock open');
        if(!flock($h,LOCK_SH|LOCK_NB)){fclose($h);throw new \RuntimeException('Effect writer busy');}return$h;
    }
    private static function read(string$p,int$max,bool$private=false):?string{
        clearstatcache(true,$p);if(is_link($p))throw new \RuntimeException('Observation symlink refused');if(!file_exists($p))return null;$a=lstat($p);
        if(!is_array($a)||($a['mode']&0170000)!==0100000||($a['mode']&0022)!==0||($private&&($a['mode']&0077)!==0)||!in_array($a['uid'],[0,posix_geteuid()],true)||$a['size']<0||$a['size']>$max)throw new \RuntimeException('Bounded trusted regular observation required');
        $h=fopen($p,'rb');if(!$h)throw new \RuntimeException('Observation open');try{$b=fstat($h);$raw=stream_get_contents($h,$max+1);$c=fstat($h);}finally{fclose($h);}clearstatcache(true,$p);$d=lstat($p);
        foreach(['dev','ino','mode','uid','size','mtime','ctime']as$k)if(!is_array($b)||!is_array($c)||!is_array($d)||$a[$k]!==$b[$k]||$a[$k]!==$c[$k]||$a[$k]!==$d[$k])throw new \RuntimeException('Observation generation changed');
        if(!is_string($raw)||strlen($raw)!==$a['size']||is_link($p))throw new \RuntimeException('Observation bytes changed');return$raw;
    }
    private static function fields(array$v,array$keys):void{$actual=array_keys($v);sort($actual);sort($keys);if($actual!==$keys)throw new \RuntimeException('Exact observation schema required');}
    private static function canonical($v):string{
        $sort=function($x)use(&$sort){if(is_array($x)){if(!array_is_list($x))ksort($x,SORT_STRING);foreach($x as$k=>$a)$x[$k]=$sort($a);}return$x;};
        return json_encode($sort($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS);
    }
    private static function selector($v):array{
        if($v===['kind'=>'primary'])return$v;
        if(is_array($v)){self::fields($v,['kind','index']);if($v['kind']==='gallery'&&is_int($v['index'])&&$v['index']>=0&&$v['index']<128)return['kind'=>'gallery','index'=>$v['index']];}
        throw new \RuntimeException('Canonical source selector required');
    }
    private static function hex($v,int$n):bool{return is_string($v)&&preg_match('/^[a-f0-9]{'.$n.'}$/D',$v)===1;}
    /** Supplied ledger pins are not authority. Final current-proof transaction remains mandatory. */
    public function observe(array$p,string$sourceRaw,string$sourceProofSha):array
    {
        $this->verifiedContext=null;
        if(!is_resource($this->worker)||!is_resource($this->remote)||$this->intake===null)throw new \RuntimeException('Observer locks released');
        self::fields($p,['attempt_id','product_id','revision','snapshot_sha256','operation_key','source_selector','source_url','receipt_preimage_sha256']);
        if(!self::hex($p['attempt_id'],32)||!self::hex($p['snapshot_sha256'],64)||!self::hex($p['operation_key'],64)||!is_int($p['product_id'])||$p['product_id']<1||!is_int($p['revision'])||$p['revision']<1||($p['receipt_preimage_sha256']!==null&&!self::hex($p['receipt_preimage_sha256'],64)))throw new \RuntimeException('Invalid expected ledger pins');
        $selector=self::selector($p['source_selector']);if(!is_string($p['source_url']))throw new \RuntimeException('Exact source required');ImageSourceIntake::source($p['source_url']);
        $operation=['version'=>1,'product_id'=>$p['product_id'],'revision'=>$p['revision'],'snapshot_sha256'=>$p['snapshot_sha256'],'source_url'=>$p['source_url'],'source_selector'=>$selector,'intent'=>$p['receipt_preimage_sha256']===null?'initial':'renew','receipt_preimage_sha256'=>$p['receipt_preimage_sha256']];if(hash('sha256',self::canonical($operation))!==$p['operation_key'])throw new \RuntimeException('Operation identity hash differs');
        if(strlen($sourceRaw)>8192||!self::hex($sourceProofSha,64)||hash('sha256',$sourceRaw)!==$sourceProofSha)throw new \RuntimeException('Pinned acquisition bytes/hash required');
        $src=json_decode($sourceRaw,true,16,JSON_THROW_ON_ERROR);if(!is_array($src)||self::canonical($src)!==$sourceRaw)throw new \RuntimeException('Canonical acquisition proof required');
        self::fields($src,explode(' ','version attempt_id operation_key snapshot_sha256 source_selector source_url source_sha256 source_bytes mime width height verified_at valid_until intake_journal_sha256 intake_manifest_sha256'));
        if($src['version']!==1||$src['attempt_id']!==$p['attempt_id']||$src['operation_key']!==$p['operation_key']||$src['snapshot_sha256']!==$p['snapshot_sha256']||self::selector($src['source_selector'])!==$selector||$src['source_url']!==$p['source_url'])throw new \RuntimeException('Acquisition ledger binding differs');
        foreach(['source_sha256','intake_journal_sha256','intake_manifest_sha256']as$k)if(!self::hex($src[$k],64))throw new \RuntimeException('Source pin hash');
        $now=time();if(!is_int($src['verified_at'])||!is_int($src['valid_until'])||$src['verified_at']<0||$src['verified_at']>$now||$src['valid_until']<=$now||$src['valid_until']-$src['verified_at']!==86400)throw new \RuntimeException('Source freshness invalid; never renew timestamps');
        foreach(['source_bytes','width','height']as$k)if(!is_int($src[$k])||$src[$k]<1)throw new \RuntimeException('Source metadata type');
        if($src['source_bytes']>2097152||$src['width']>4000000||$src['height']>4000000||$src['width']*$src['height']>4000000||!in_array($src['mime'],['image/png','image/jpeg','image/webp'],true))throw new \RuntimeException('Source metadata bound');
        $raw=self::read($this->state.'/job-'.$p['attempt_id'].'.json',262144,true);if($raw===null)throw new \RuntimeException('Pilot journal missing');$j=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
        if(!is_array($j)||json_encode($j,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)!==$raw||($j['version']??null)!==1||($j['id']??null)!==$p['attempt_id']||!in_array($j['state']??null,['queued','running','publishing','done'],true))throw new \RuntimeException('Canonical pilot journal identity required');
        $r=$j['record']??null;$catalog=$j['product']??null;if(!is_array($r)||!is_array($catalog)||!array_key_exists('before_receipt',$j)||($j['before_receipt']!==null&&!is_string($j['before_receipt'])))throw new \RuntimeException('Complete pilot binding required');
        if(($r['product_id']??null)!==(string)$p['product_id']||($r['source_url']??null)!==$p['source_url']||ImageSourceSelection::selector($r)!==$selector||($catalog['id']??null)!==(string)$p['product_id']||($catalog['is_active']??null)!==1||($catalog['show_on_website']??null)!==1)throw new \RuntimeException('Pilot catalog/source binding differs');
        self::fields($catalog,['id','is_active','show_on_website','image_url','images']);
        $lifecycle=['version'=>2,'product_id'=>$p['product_id'],'revision'=>$p['revision'],'deleted'=>false,'image_url'=>$catalog['image_url'],'images'=>$catalog['images'],'is_active'=>1,'show_on_website'=>1];
        if(hash('sha256',self::canonical($lifecycle))!==$p['snapshot_sha256'])throw new \RuntimeException('Pilot snapshot differs from lifecycle pin');
        $before=$j['before_receipt'];if(($before===null?null:hash('sha256',$before))!==$p['receipt_preimage_sha256'])throw new \RuntimeException('Receipt preimage differs');
        $pins=$this->intake->verify($p['attempt_id'],$r,$catalog,$before);
        if(($j['intake_binding']??null)!==$pins||$pins!==['journal_sha256'=>$src['intake_journal_sha256'],'manifest_sha256'=>$src['intake_manifest_sha256']])throw new \RuntimeException('Intake pins differ');
        $v=$r['provenance']??null;$image=$r['intake']['image']??null;
        if(!is_array($v)||($v['basis']??null)!=='existing_catalog'||($v['optimization_authorized']??null)!==true||($v['excluded']??null)!==false||!is_string($v['reference']??null)||trim($v['reference'])===''||!is_array($image)||($image['orientation']??null)!==1)throw new \RuntimeException('Verified provenance required');
        foreach(['sha256'=>'source_sha256','bytes'=>'source_bytes','mime'=>'mime','width'=>'width','height'=>'height']as$a=>$b)if(($image[$a]??null)!==$src[$b])throw new \RuntimeException('Intake metadata differs');
        if(strtotime($r['verified_at']??'')!==$src['verified_at']||strtotime($r['valid_until']??'')!==$src['valid_until'])throw new \RuntimeException('Original source timestamps differ');
        $original=self::read($this->state.'/inputs/'.$r['local_file'],2097152,true);$size=$original===null?false:getimagesizefromstring($original);
        if($original===null||strlen($original)!==$src['source_bytes']||hash('sha256',$original)!==$src['source_sha256']||!$size||$size[0]!==$src['width']||$size[1]!==$src['height']||(new \finfo(FILEINFO_MIME_TYPE))->buffer($original)!==$src['mime'])throw new \RuntimeException('Original bytes/metadata changed');
        $receiptName='remote-'.hash('sha256',(string)$p['product_id']."\n".$p['source_url']).'.json';$public=self::read($this->site.'/gallery/responsive/'.$receiptName,16384);
        $base=['pilot_state'=>$j['state'],'dispatch_authorized'=>false,'settlement_authorized'=>false,'locks_held'=>true,'requires'=>'same-process current-lifecycle settlement transaction before release'];
        if(!isset($j['after_receipt'])||!is_string($j['after_receipt'])||$public!==$j['after_receipt'])return$base+['observation'=>$public===$before?'matches_before':'conflict'];
        if(!in_array($j['state'],['publishing','done'],true))throw new \RuntimeException('Uncertain journal cannot claim after-effect');
        $receipt=json_decode($public,true,16,JSON_THROW_ON_ERROR);if(!is_array($receipt)||json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n"!==$public)throw new \RuntimeException('Exact canonical public receipt required');
        self::fields($receipt,explode(' ','version product_id source_url source_sha256 verified_at valid_until width height original_bytes variants optimization_authorized provenance_basis'));
        $expected=['version'=>1,'product_id'=>(string)$p['product_id'],'source_url'=>$p['source_url'],'source_sha256'=>$src['source_sha256'],'verified_at'=>$src['verified_at'],'valid_until'=>$src['valid_until'],'width'=>$src['width'],'height'=>$src['height'],'original_bytes'=>$src['source_bytes'],'optimization_authorized'=>true,'provenance_basis'=>'existing_catalog'];foreach($expected as$k=>$value)if($receipt[$k]!==$value)throw new \RuntimeException('Receipt source metadata changed');
        if(!is_array($receipt['variants'])||count($receipt['variants'])<1||count($receipt['variants'])>6||!is_array($j['files']??null)||count($j['files'])!==count($receipt['variants']))throw new \RuntimeException('Complete bounded variant set required');
        $variants=[];$total=$src['source_bytes']+strlen($public);
        foreach($receipt['variants']as$width=>$m){if(!is_int($width)||!in_array($width,[80,160,320,640,960,1440],true)||$width>=$src['width']||!is_array($m))throw new \RuntimeException('Variant width');self::fields($m,['bytes','sha256']);
            if(!is_int($m['bytes'])||$m['bytes']<1||$m['bytes']>=$src['source_bytes']||!self::hex($m['sha256'],64))throw new \RuntimeException('Variant size/hash');
            $name='remote-'.$src['source_sha256'].'-'.$width.'.webp';$height=max(1,(int)round($src['height']*$width/$src['width']));
            if(($j['files'][$name]??null)!==['bytes'=>$m['bytes'],'sha256'=>$m['sha256'],'width'=>$width,'height'=>$height])throw new \RuntimeException('Pilot variant pins differ');
            $bytes=self::read($this->site.'/gallery/responsive/'.$name,2097152);$sz=$bytes===null?false:getimagesizefromstring($bytes);
            if($bytes===null||strlen($bytes)!==$m['bytes']||hash('sha256',$bytes)!==$m['sha256']||substr($bytes,0,4)!=='RIFF'||substr($bytes,8,4)!=='WEBP'||strlen($bytes)<12||unpack('V',substr($bytes,4,4))[1]+8!==strlen($bytes)||!$sz||$sz[2]!==IMAGETYPE_WEBP||$sz[0]!==$width||$sz[1]!==$height||(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!=='image/webp')throw new \RuntimeException('Public variant verification failed');
            $variants[]=['name'=>$name,'width'=>$width,'height'=>$height,'bytes'=>strlen($bytes),'sha256'=>$m['sha256'],'mime'=>'image/webp'];$total+=strlen($bytes);
        }
        if($total>16777216)throw new \RuntimeException('Aggregate artifact bound');
        $now=time();if($src['valid_until']<=$now)throw new \RuntimeException('Source expired during observation');
        $e=['version'=>'synthetic-settlement-v1','attempt_id'=>$p['attempt_id'],'operation_key'=>$p['operation_key'],'snapshot_sha256'=>$p['snapshot_sha256'],'source_selector'=>$selector,'source_url'=>$p['source_url'],'receipt_preimage_sha256'=>$p['receipt_preimage_sha256'],'acquisition_proof_sha256'=>$sourceProofSha,'receipt_json'=>$public,'observed_receipt_sha256'=>hash('sha256',$public),'variants'=>$variants,'observed_at'=>$now];
        $this->verifiedContext=['record'=>$r,'catalog'=>$catalog,'intake_pins'=>$pins,'before_receipt'=>$before];
        return$base+['observation'=>'matches_after_complete','evidence'=>$e];
    }
    /** Private verified inputs, never authority; usable only while this observer retains its locks. */
    public function privateContext():array
    {
        if($this->verifiedContext===null||!is_resource($this->worker)||!is_resource($this->remote)||$this->intake===null)throw new \RuntimeException('Complete locked observation required');
        return $this->verifiedContext;
    }
    public function release():void{$this->verifiedContext=null;if(is_resource($this->remote)){flock($this->remote,LOCK_UN);fclose($this->remote);}$this->remote=null;if($this->intake!==null)$this->intake->release();$this->intake=null;if(is_resource($this->worker)){flock($this->worker,LOCK_UN);fclose($this->worker);}$this->worker=null;}
    public function __destruct(){$this->release();}
}
