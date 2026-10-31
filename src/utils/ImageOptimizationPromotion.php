<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ImageQueuePromotionSchema.php';

/** Source-only offline receipt activation. Requires separately installed journal-capable schema. */
class ImageOptimizationPromotion
{
    private ImageOptimizationQueue $queue;
    private string $directory;
    private string $privateRoot;
    public function __construct(private \PDO $db, string $applicationRoot, string $privateRoot)
    {
        $this->queue=new ImageOptimizationQueue($db);
        ImageQueuePromotionSchema::requireReady($db);
        if ($db->inTransaction() || (int)$db->query('PRAGMA synchronous')->fetchColumn()<2
            || !in_array(strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn()),['delete','truncate','persist','wal'],true)) {
            throw new \RuntimeException('Durable standalone SQLite context required.');
        }
        $app=realpath($applicationRoot);$private=realpath($privateRoot);
        clearstatcache(true,$privateRoot);
        if (!$app || !$private || $private!==rtrim($privateRoot,'/') || is_link($privateRoot)
            || $private===$app || str_starts_with($private,$app.'/') || str_starts_with($app,$private.'/')
            || (fileperms($private)&0077)!==0) throw new \RuntimeException('Invalid private staging root.');
        foreach ([$app.'/gallery',$app.'/gallery/responsive'] as $path) {
            if (is_link($path) || !is_dir($path) || realpath($path)!==$path) throw new \RuntimeException('Regular existing output directories required.');
        }
        $this->directory=$app.'/gallery/responsive';$this->privateRoot=$private;
        $db->query('SELECT activation_id,job_id,lease_token,catalog_binding,target_name,before_bytes,before_sha256,after_bytes,after_sha256,variants_json,state,created_at,resolved_at FROM image_optimization_activations LIMIT 0');
        self::syncDirectory($this->directory); // No degraded durability mode.
    }

    public function promote(array $claim, array $stage): string
    {
        $lock=$this->lock();
        try {
            $intent=$this->queue->currentIntent($claim,time());
            if (!$intent || $intent['kind']!=='ebay') throw new \DomainException('Lease/catalog no longer current.');
            [$receipt,$variants,$workspace]=$this->verifyStage($stage,$intent);
            $target='remote-'.hash('sha256',$intent['product_id']."\n".$intent['source']).'.json';
            $this->writer($claim);
            $before=$this->readReceipt($target);
            $this->checkPrior($target,$before,$claim['id']);
            foreach($variants as $name=>$meta) $this->checkCollision($name,$meta);
            $activation=bin2hex(random_bytes(16));$receipt['activation_id']=$activation;
            $after=json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if(strlen($after)>16384)throw new \RuntimeException('Receipt exceeds bound.');
            $insert=$this->db->prepare("INSERT INTO image_optimization_activations(activation_id,job_id,lease_token,catalog_binding,target_name,before_bytes,before_sha256,after_bytes,after_sha256,variants_json,state,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'prepared',?)");
            $insert->execute([$activation,$claim['id'],$claim['token'],$intent['catalog_binding'],$target,$before,$before===null?null:hash('sha256',$before),$after,hash('sha256',$after),json_encode($variants,JSON_THROW_ON_ERROR),time()]);
            $this->commit();$this->checkpoint('prepared');
            $this->writer($claim);
            if($this->readReceipt($target)!==$before)throw new \RuntimeException('Receipt changed after preparation.');
            foreach($variants as$name=>$meta){
                $source=$workspace.'/gallery/responsive/'.$name;
                if(!$this->validVariant($source,$meta))throw new \RuntimeException('Stage changed.');
                if(!$this->checkCollision($name,$meta))$this->publishAbsent($source,$name,$meta);
                else { self::syncFile($this->directory.'/'.$name); self::syncDirectory($this->directory); }
                $this->checkpoint('variant');
            }
            // Lease may have expired during copies. Revalidate again before receipt commit point.
            if($this->queue->currentIntent($claim,time())!==$intent)throw new \DomainException('Lease/catalog expired before receipt.');
            if($receipt['valid_until']<=time())throw new \DomainException('Provenance expired before receipt publication.');
            $this->atomicReceipt($target,$after);$this->checkpoint('receipt');
            $this->resolve($activation,'completed',$claim,'done');
            $this->checkpoint('before_commit');$this->commit();$this->checkpoint('completed');
            return $activation;
        }catch(\Throwable $e){
            // A commit exception is uncertain. Never infer that public files must be rolled back.
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** Invoke using a fresh PDO connection after an uncertain prior outcome. */
    public function reconcile(string $activation): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$activation))throw new \InvalidArgumentException('Invalid activation identity.');
        $lock=$this->lock();
        try{
            $this->db->beginTransaction();
            ImageQueuePromotionSchema::requireReady($this->db);
            // Writer reservation before observing activation/job state.
            $write=$this->db->prepare('UPDATE image_optimization_activations SET state=state WHERE activation_id=?');$write->execute([$activation]);
            $read=$this->db->prepare('SELECT * FROM image_optimization_activations WHERE activation_id=?');$read->execute([$activation]);$a=$read->fetch(\PDO::FETCH_ASSOC);
            if(!$a)throw new \DomainException('Unknown activation.');
            if($a['state']!=='prepared'){$this->commit();return $a['state'];}
            $job=$this->db->prepare('SELECT * FROM image_optimization_jobs WHERE id=?');$job->execute([$a['job_id']]);$j=$job->fetch(\PDO::FETCH_ASSOC);
            $this->validateJournal($a,$j);
            $claim=['id'=>(int)$a['job_id'],'token'=>$a['lease_token']];
            $current=$this->readReceipt($a['target_name']);
            if($current!==null && hash_equals($a['after_sha256'],hash('sha256',$current))){
                $r=json_decode($a['after_bytes'],true,16,JSON_THROW_ON_ERROR);
                $intent=$j?json_decode($j['intent_json'],true,16,JSON_THROW_ON_ERROR):null;
                $catalog=$this->db->prepare('SELECT id,is_active,show_on_website,image_url,images FROM products WHERE id=?');$catalog->execute([$j['product_id']??0]);$product=$catalog->fetch(\PDO::FETCH_ASSOC);
                $valid=$j && $j['state']==='leased' && hash_equals($a['lease_token'],(string)$j['lease_token'])
                    && is_array($intent) && ($intent['catalog_binding']??null)===$a['catalog_binding'] && $product && ImageOptimizationPlan::isCurrent($intent,$product)
                    && ($r['activation_id']??null)===$activation && ($r['valid_until']??0)>time();
                foreach(json_decode($a['variants_json'],true,16,JSON_THROW_ON_ERROR)as$name=>$meta)$valid=$valid&&$this->validVariant($this->directory.'/'.$name,$meta);
                if($valid){
                    foreach(json_decode($a['variants_json'],true,16,JSON_THROW_ON_ERROR)as$name=>$meta)self::syncFile($this->directory.'/'.$name);
                    self::syncFile($this->directory.'/'.$a['target_name']);self::syncDirectory($this->directory);
                    $this->resolve($activation,'completed',$claim,'done');$state='completed';
                }
                else{
                    // Restore only this exact generation; all shared variant files remain untouched.
                    if($a['before_bytes']===null){if(!unlink($this->directory.'/'.$a['target_name']))throw new \RuntimeException('Cannot remove owned receipt.');self::syncDirectory($this->directory);}
                    else $this->atomicReceipt($a['target_name'],$a['before_bytes']);
                    $this->resolve($activation,'aborted',$claim,'superseded');$state='aborted';
                }
            }elseif($current===$a['before_bytes']){
                $this->resolve($activation,'aborted',$claim,null);$state='aborted';
            }else{
                // An exclusion or newer/unknown writer wins. Preserve its bytes and quarantine ours.
                $this->resolve($activation,'conflict',$claim,'quarantined');$state='conflict';
            }
            $this->commit();return $state;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    protected function checkpoint(string $name):void {} // Overridden only by disposable failure/crash tests.
    private function writer(array $claim):void
    {
        if($this->db->inTransaction())throw new \LogicException('Nested promotion transaction prohibited.');
        $this->db->beginTransaction();ImageQueuePromotionSchema::requireReady($this->db);$s=$this->db->prepare('UPDATE image_optimization_jobs SET state=state WHERE id=?');$s->execute([$claim['id']]);
        if(!$this->queue->currentIntent($claim,time()))throw new \DomainException('Activation fence failed.');
    }
    private function commit():void{if(!$this->db->commit())throw new \RuntimeException('SQLite commit outcome uncertain; reconcile using fresh connection.');}
    private function lock()
    {
        $path=$this->directory.'/.remote.lock';if(is_link($path))throw new \RuntimeException('Symlink lock refused.');
        $h=fopen($path,'c');if(!$h)throw new \RuntimeException('Cannot open publication lock.');
        if(!flock($h,LOCK_EX|LOCK_NB)){fclose($h);throw new \RuntimeException('Publication busy; retry later.');}return $h;
    }
    private static function syncFile(string $path):void
    {
        $h=fopen($path,'rb');if(!$h)throw new \RuntimeException('Cannot sync existing output.');
        try{if(!fsync($h))throw new \RuntimeException('Existing output durability unavailable.');}finally{fclose($h);}
    }
    private static function syncDirectory(string $path):void
    {
        $h=fopen($path,'r');if(!$h)throw new \RuntimeException('Directory sync unavailable.');
        try{if(!fsync($h))throw new \RuntimeException('Directory durability unavailable.');}finally{fclose($h);}
    }
    private function readReceipt(string $name):?string
    {
        if(!preg_match('/^remote-[a-f0-9]{64}\.json$/D',$name))throw new \RuntimeException('Invalid receipt name.');
        $p=$this->directory.'/'.$name;if(is_link($p))throw new \RuntimeException('Symlink receipt refused.');
        if(!file_exists($p))return null;if(!is_file($p))throw new \RuntimeException('Nonfile receipt.');
        $b=file_get_contents($p,false,null,0,16385);if(!is_string($b)||strlen($b)<1||strlen($b)>16384)throw new \RuntimeException('Invalid receipt bound.');return $b;
    }
    private function checkPrior(string $target,?string $before,int $job):void
    {
        $s=$this->db->prepare("SELECT MAX(job_id) FROM image_optimization_activations WHERE target_name=? AND state='completed'");$s->execute([$target]);
        if((int)$s->fetchColumn()>=$job)throw new \DomainException('A newer or same generation already completed.');
        if($before===null)return;
        $r=json_decode($before,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($r)||($r['excluded']??false)===true)throw new \DomainException('Excluded or invalid prior receipt cannot be replaced.');
        $s=$this->db->prepare("SELECT after_sha256 FROM image_optimization_activations WHERE activation_id=? AND target_name=? AND state='completed'");$s->execute([$r['activation_id']??'',$target]);$hash=$s->fetchColumn();
        if(!is_string($hash)||!hash_equals($hash,hash('sha256',$before)))throw new \DomainException('Unknown legacy generation requires explicit adoption.');
    }
    private function validVariant(string $path,array $m):bool
    {
        if(is_link($path)||!is_file($path)||!is_int($m['bytes']??null)||$m['bytes']<1||$m['bytes']>20971520||!is_string($m['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$m['sha256']))return false;
        clearstatcache(true,$path);$size=@getimagesize($path);
        return $size&&$size[2]===IMAGETYPE_WEBP&&$size[0]===($m['width']??null)&&$size[1]===($m['height']??null)&&filesize($path)===$m['bytes']&&hash_file('sha256',$path)===$m['sha256'];
    }
    private function checkCollision(string $name,array $meta):bool
    {
        $path=$this->directory.'/'.$name;
        if(!file_exists($path)&&!is_link($path))return false;
        if(!$this->validVariant($path,$meta))throw new \RuntimeException('Existing derivative collision; no overwrite permitted.');return true;
    }
    private function publishAbsent(string $source,string $name,array $meta):void
    {
        $bytes=file_get_contents($source,false,null,0,20971521);
        $size=is_string($bytes)?getimagesizefromstring($bytes):false;
        if(!is_string($bytes)||strlen($bytes)!==$meta['bytes']||strlen($bytes)>20971520||hash('sha256',$bytes)!==$meta['sha256']||!$size||$size[2]!==IMAGETYPE_WEBP||$size[0]!==$meta['width']||$size[1]!==$meta['height'])throw new \RuntimeException('Exact source snapshot changed.');
        $temp=$this->writeTemp($bytes);
        try{if(!link($temp,$this->directory.'/'.$name))throw new \RuntimeException('Exclusive derivative publication failed.');self::syncDirectory($this->directory);if(!$this->validVariant($this->directory.'/'.$name,$meta))throw new \RuntimeException('Published derivative invalid.');}
        finally{if(is_file($temp))unlink($temp);}
    }
    private function writeTemp(string $bytes):string
    {
        $temp=tempnam($this->directory,'.activation-');if($temp===false)throw new \RuntimeException('Cannot stage publication.');
        if(realpath(dirname($temp))!==$this->directory){unlink($temp);throw new \RuntimeException('Publication temporary directory mismatch.');}
        $h=fopen($temp,'wb');
        try{if(!$h||fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!chmod($temp,0644)||!fsync($h))throw new \RuntimeException('Output durability failed.');}
        catch(\Throwable $e){if(is_resource($h))fclose($h);unlink($temp);throw $e;}fclose($h);return $temp;
    }
    private function atomicReceipt(string $target,string $bytes):void
    {
        $this->readReceipt($target);$temp=$this->writeTemp($bytes);
        try{if(!rename($temp,$this->directory.'/'.$target))throw new \RuntimeException('Receipt rename failed.');self::syncDirectory($this->directory);}
        finally{if(is_file($temp))unlink($temp);}
    }
    private function resolve(string $id,string $state,array $claim,?string $jobState):void
    {
        $s=$this->db->prepare('UPDATE image_optimization_activations SET state=?,resolved_at=? WHERE activation_id=? AND state=\'prepared\'');$s->execute([$state,time(),$id]);if($s->rowCount()!==1)throw new \RuntimeException('Journal changed.');
        if($jobState!==null){$s=$this->db->prepare("UPDATE image_optimization_jobs SET state=?,lease_token=NULL,lease_until=NULL WHERE id=? AND state='leased' AND lease_token=?");$s->execute([$jobState,$claim['id'],$claim['token']]);if($s->rowCount()!==1)throw new \RuntimeException('Job ownership changed.');}
    }
    private function validateJournal(array $a,array|false $job):void
    {
        if(!$job || $job['state']!=='leased' || !is_string($job['lease_token']) || !hash_equals($a['lease_token'],$job['lease_token'])
            || !preg_match('/^[a-f0-9]{32}$/D',$a['activation_id']) || !preg_match('/^[a-f0-9]{48}$/D',$a['lease_token'])
            || !preg_match('/^[a-f0-9]{64}$/D',$a['catalog_binding']) || !preg_match('/^[a-f0-9]{64}$/D',$a['after_sha256'])
            || strlen($a['after_bytes'])<1 || strlen($a['after_bytes'])>16384 || !hash_equals($a['after_sha256'],hash('sha256',$a['after_bytes']))
            || strlen($a['variants_json'])>16384 || (int)$a['created_at']<1)throw new \RuntimeException('Corrupt or uncertain activation ownership; manual recovery required.');
        $intent=json_decode($job['intent_json'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($intent) || ($intent['kind']??null)!=='ebay' || ($intent['version']??null)!==ImageOptimizationPlan::VERSION || ($intent['product_id']??null)!==(string)$job['product_id'] || ($intent['catalog_binding']??null)!==$a['catalog_binding']
            || ($intent['work_key']??null)!==$job['work_key'] || !is_string($intent['source']??null)
            || $a['target_name']!=='remote-'.hash('sha256',$intent['product_id']."\n".$intent['source']).'.json')throw new \RuntimeException('Journal/catalog identity invalid.');
        if(!hash_equals(hash('sha256',$intent['version']."\n".$intent['product_id']."\n".$intent['catalog_binding']."\n".$intent['source']),$intent['work_key']))throw new \RuntimeException('Journal work identity invalid.');
        $r=json_decode($a['after_bytes'],true,16,JSON_THROW_ON_ERROR);
        $this->receiptShape($r,$intent,true,$a['activation_id']);
        $inventory=json_decode($a['variants_json'],true,16,JSON_THROW_ON_ERROR);$expected=[];
        foreach($r['variants']as$width=>$meta)$expected['remote-'.$r['source_sha256'].'-'.$width.'.webp']=$meta+['width'=>$width,'height'=>max(1,(int)round($r['height']*$width/$r['width']))];
        if($inventory!==$expected)throw new \RuntimeException('Journal inventory is not canonical.');
        if($a['before_bytes']===null){if($a['before_sha256']!==null)throw new \RuntimeException('Invalid absent backup.');}
        else{
            if(strlen($a['before_bytes'])<1||strlen($a['before_bytes'])>16384||!is_string($a['before_sha256'])||!hash_equals($a['before_sha256'],hash('sha256',$a['before_bytes'])))throw new \RuntimeException('Invalid prior receipt backup.');
            $prior=json_decode($a['before_bytes'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($prior)||!is_string($prior['activation_id']??null)||($prior['excluded']??false)!==false)throw new \RuntimeException('Invalid prior generation.');
            $q=$this->db->prepare("SELECT after_sha256 FROM image_optimization_activations WHERE activation_id=? AND target_name=? AND state='completed'");$q->execute([$prior['activation_id'],$a['target_name']]);$known=$q->fetchColumn();
            if(!is_string($known)||!hash_equals($known,$a['before_sha256']))throw new \RuntimeException('Prior generation backup is unrecognized.');
        }
    }
    private function receiptShape(mixed $r,array $intent,bool $allowExpired,?string $activation):void
    {
        $allowed=['version','product_id','source_url','source_sha256','verified_at','valid_until','optimization_authorized','provenance_basis','original_bytes','width','height','variants'];if($activation!==null)$allowed[]='activation_id';
        if(!is_array($r)||count($r)!==count($allowed)||array_diff(array_keys($r),$allowed)
            ||($r['version']??null)!==1||($r['product_id']??null)!==$intent['product_id']||($r['source_url']??null)!==$intent['source']
            ||($activation!==null&&($r['activation_id']??null)!==$activation)||($r['optimization_authorized']??null)!==true||($r['provenance_basis']??null)!=='existing_catalog'
            ||!is_string($r['source_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['source_sha256'])
            ||!is_int($r['verified_at']??null)||$r['verified_at']<1||$r['verified_at']>time()||!is_int($r['valid_until']??null)||$r['valid_until']<=$r['verified_at']||$r['valid_until']>$r['verified_at']+30*86400||(!$allowExpired&&$r['valid_until']<=time())
            ||!is_int($r['width']??null)||!is_int($r['height']??null)||$r['width']<1||$r['height']<1||$r['width']>20000000||$r['height']>20000000||$r['width']*$r['height']>20000000
            ||!is_int($r['original_bytes']??null)||$r['original_bytes']<1||$r['original_bytes']>20971520||!is_array($r['variants']??null)||!$r['variants']||count($r['variants'])>count(ResponsiveImage::WIDTHS))throw new \RuntimeException('Invalid canonical receipt.');
        $plan=ImageOptimizationPlan::forProduct(['id'=>$intent['product_id'],'is_active'=>1,'show_on_website'=>1,'image_url'=>$intent['source'],'images'=>[]]);
        if(count($plan['jobs'])!==1||$plan['jobs'][0]['kind']!=='ebay')throw new \RuntimeException('Invalid receipt source.');
        foreach($r['variants']as$width=>$meta)if(!is_int($width)||!in_array($width,ResponsiveImage::WIDTHS,true)||$width>=$r['width']||!is_array($meta)||array_keys($meta)!==['bytes','sha256']||!is_int($meta['bytes'])||$meta['bytes']<1||$meta['bytes']>=$r['original_bytes']||!is_string($meta['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$meta['sha256']))throw new \RuntimeException('Invalid canonical variant.');
    }
    private function verifyStage(array $stage,array $intent):array
    {
        $work=$stage['workspace']??'';
        if(!is_string($work)||realpath($work)!==$work||!str_starts_with($work,$this->privateRoot.'/image-stage-')||is_link($work)||($stage['intent']??null)!==$intent)throw new \RuntimeException('Invalid private stage binding.');
        foreach(['gallery','gallery/responsive']as$suffix)if(is_link($work.'/'.$suffix)||realpath($work.'/'.$suffix)!==$work.'/'.$suffix)throw new \RuntimeException('Invalid staging directory.');
        $name='remote-'.hash('sha256',$intent['product_id']."\n".$intent['source']).'.json';$path=$work.'/gallery/responsive/'.$name;
        if(is_link($path)||!is_file($path))throw new \RuntimeException('Missing stage receipt.');$b=file_get_contents($path,false,null,0,16385);
        if(!is_string($b)||strlen($b)>16384)throw new \RuntimeException('Receipt bound exceeded.');$r=json_decode($b,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($r)||($r['version']??null)!==1||($r['product_id']??null)!==$intent['product_id']||($r['source_url']??null)!==$intent['source']||($r['excluded']??false)!==false||isset($r['activation_id'])
            ||($r['optimization_authorized']??null)!==true||($r['provenance_basis']??null)!=='existing_catalog'||!is_string($r['source_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['source_sha256'])
            ||!is_int($r['verified_at']??null)||$r['verified_at']<1||$r['verified_at']>time()||!is_int($r['valid_until']??null)||$r['valid_until']<=time()||$r['valid_until']>$r['verified_at']+30*86400
            ||!is_int($r['width']??null)||!is_int($r['height']??null)||$r['width']<1||$r['height']<1||$r['width']>20000000||$r['height']>20000000||$r['width']*$r['height']>20000000
            ||!is_int($r['original_bytes']??null)||$r['original_bytes']<1||$r['original_bytes']>20971520||!is_array($r['variants']??null)||!$r['variants']||count($r['variants'])>count(ResponsiveImage::WIDTHS))throw new \RuntimeException('Invalid staged receipt.');
        $this->receiptShape($r,$intent,false,null);
        $files=$stage['files']??null;if(!is_array($files)||count($files)!==count($r['variants'])+1||($files['gallery/responsive/'.$name]??null)!==['bytes'=>strlen($b),'sha256'=>hash('sha256',$b)])throw new \RuntimeException('Stage inventory changed.');
        $variants=[];foreach($r['variants']as$width=>$meta){
            if(!is_int($width)||!in_array($width,ResponsiveImage::WIDTHS,true)||$width>=$r['width']||!is_array($meta)||($meta['bytes']??PHP_INT_MAX)>=$r['original_bytes'])throw new \RuntimeException('Variant metadata invalid.');
            $name='remote-'.$r['source_sha256'].'-'.$width.'.webp';$full=$meta+['width'=>$width,'height'=>max(1,(int)round($r['height']*$width/$r['width']))];
            if(($files['gallery/responsive/'.$name]??null)!==$meta||!$this->validVariant($work.'/gallery/responsive/'.$name,$full))throw new \RuntimeException('Variant verification failed.');$variants[$name]=$full;
        }return[$r,$variants,$work];
    }
}
