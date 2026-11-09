<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;} 
require_once __DIR__.'/CycleAdmissionBridge.php';
require_once __DIR__.'/../front-end-vnext/ImageOperationFrontEnd.php';
require_once __DIR__.'/../coordinator-vnext/ImageRemoteAcquisition.php';
require_once __DIR__.'/../coordinator-vnext/ImageOperationPilot.php';
require_once __DIR__.'/../operation-v6/ImageObservedSettlement.php';
use FAS\Utils\{ImageOperationFrontEnd,ImageOperationBinding,ImageRemoteAcquisition,ImageOperationPilot,ImageObservedSettlement,ImageSourceIntake,ImageIntakeTransport};
if(!class_exists('PDO')||!in_array('sqlite',PDO::getAvailableDrivers(),true)||!function_exists('imagewebp')||!function_exists('posix_geteuid')||!class_exists('finfo')||!function_exists('proc_open')){fwrite(STDERR,"UNACCEPTED: actual PDO/GD/POSIX/fileinfo/process runtime required\n");exit(2);}
function db(string$p):PDO{$d=new PDO('sqlite:'.$p);$d->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$d->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$d->exec('PRAGMA busy_timeout=250');$d->exec('PRAGMA foreign_keys=ON');return$d;}
function quota():array{$extra=\FAS\Utils\CycleAdmissionBridge::EXTRA_BYTES;return['observed_at'=>time(),'quota_limit'=>5*1024**3,'quota_used'=>1024**3+$extra,'private_free'=>2*1024**3-$extra,'public_free'=>2*1024**3-$extra,'tmp_free'=>2*1024**3-$extra];}
$n=0;function ok(bool$v):void{global$n;$n++;if(!$v)throw new RuntimeException('Composition assertion '.$n);}function refuses(callable$f):void{try{$f();}catch(Throwable){ok(true);return;}ok(false);}
// Child process exists only to provide each owned job's fixed PHP temp directory.
// Fake network and decoder coexist here: this is NOT production confinement acceptance.
if(($argv[1]??'')==='child'){
 $root=$argv[2];$state=$argv[3];$failure=($argv[4]??'')==='fail';$d=db($root.'/catalog.sqlite');$b=json_decode(file_get_contents($state.'/binding.json'),true,32,JSON_THROW_ON_ERROR);$site=$root.'/site';
 $source=base64_decode(file_get_contents(__DIR__.'/../tests/effect-fixtures/source.png.base64'),true);if(!is_string($source)||$source==='')throw new RuntimeException('Pinned fixture unavailable');
 $transport=new class($source,$failure)implements ImageIntakeTransport{public int$calls=0;public function __construct(private string$bytes,private bool$fail){}public function get(string$url,callable$h,callable$c):void{$this->calls++;if($this->fail)throw new RuntimeException('Synthetic provider interruption');$h(200,['Content-Type'=>'image/png','Content-Length'=>(string)strlen($this->bytes)]);foreach(str_split($this->bytes,16384)as$piece)$c($piece);}};
 $request=['job'=>$b['attempt_id'],'product_id'=>(string)$b['product_id'],'source_url'=>$b['source_url'],'source_selector'=>$b['source_selector'],'reference'=>'Explicit synthetic composition authorization','intent'=>$b['intent'],'catalog_snapshot'=>ImageSourceIntake::snapshot($d,(string)$b['product_id']),'receipt_preimage_sha256'=>$b['receipt_preimage_sha256'],'optimization_authorized'=>true];
 $a=new ImageRemoteAcquisition($d,$b,$state,$site,true);
 if($failure){refuses(fn()=>$a->acquire($request,$transport));refuses(fn()=>$a->acquire($request,$transport));ok($transport->calls===1);$q=$d->prepare('SELECT state FROM ledger_capacity WHERE attempt_id=?');$q->execute([$b['attempt_id']]);ok($q->fetchColumn()==='running');echo json_encode(['failed_unknown'=>true,'calls'=>$transport->calls,'checks'=>$n])."\n";exit;}
 ok($a->acquire($request,$transport)['state']==='acquired_and_pinned');refuses(fn()=>$a->acquire($request,$transport));ok($transport->calls===1);
 $p=new ImageOperationPilot($d,$b,$state,$site,true);ok($p->prepare()['state']==='queued');ok($p->run($b['attempt_id'])['state']==='done');
 $o=new ImageObservedSettlement($d,$b,$state,$site,true);$d->beginTransaction();ok($o->apply(quota(),time())['state']==='settlement_staged');$d->commit();$o->close();
 ok(hash_file('sha256',$state.'/inputs/source-'.$b['attempt_id'].'.png')===hash('sha256',$source));
 echo json_encode(['settled'=>true,'calls'=>$transport->calls,'checks'=>$n])."\n";exit;
}
$root=getcwd().'/composition-synthetic-'.bin2hex(random_bytes(8));foreach([$root,$root.'/jobs',$root.'/site',$root.'/site/gallery',$root.'/site/gallery/responsive']as$p)mkdir($p,0700);
$d=db($root.'/catalog.sqlite');chmod($root.'/catalog.sqlite',0600);$d->exec(file_get_contents(__DIR__.'/../operation-v6/fixtures/schema-empty.sql'));
// Exact referenced table DDL from pinned repository schema; retain foreign_keys=ON.
$d->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS warehouses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    code TEXT UNIQUE,
    location TEXT,
    address_line1 TEXT,
    address_line2 TEXT,
    city TEXT,
    state TEXT,
    postal_code TEXT,
    country_code TEXT DEFAULT 'US',
    phone TEXT,
    email TEXT,
    is_default INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);
SQL);
ok($d->query('PRAGMA foreign_keys')->fetchColumn()===1);
mkdir($root.'/controller',0700);$package=hash_file('sha256',__DIR__.'/CycleAdmissionBridge.php');$policyPin=hash('sha256','synthetic coherent source-policy contract');$bridge=new \FAS\Utils\CycleAdmissionBridge($root.'/controller',$package,$policyPin,true);$bridge->initialize();$cycleRequests=[];
$url='https://i.ebayimg.com/composition.png';$selector=['kind'=>'gallery','index'=>0];$gallery=json_encode([$url,$url],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$d->prepare("INSERT INTO products(id,name,price,image_url,images)VALUES(7,'composition',1,'https://i.ebayimg.com/original.png',?)")->execute([$gallery]);
$original=$d->query('SELECT * FROM products WHERE id=7')->fetch(PDO::FETCH_ASSOC);$f=new ImageOperationFrontEnd($d,true);ok($f->consume(1,quota(),time())['handed_off']===1);ok($d->query('SELECT count(*) FROM ledger_attempts')->fetchColumn()===0);
function cycleArgs(PDO$d,string$url,array$selector,?string$receipt,?int$start=null):array{
 global$package,$policyPin;$now=time();$start??=$now;$h=$d->query('SELECT revision,snapshot_sha256 FROM image_snapshot_handoff WHERE product_id=7')->fetch(PDO::FETCH_NUM);$s=['product_id'=>7,'revision'=>$h[0],'snapshot_sha256'=>$h[1],'source_selector'=>$selector,'source_url'=>$url,'intent'=>$receipt===null?'initial':'renew','receipt_preimage_sha256'=>$receipt===null?null:hash('sha256',$receipt)];
 $b=['job'=>bin2hex(random_bytes(16)),'handoff_sha256'=>$h[1],'package_sha256'=>$package,'policy_sha256'=>$policyPin];$measurement=bin2hex(random_bytes(32));$r=['version'=>1,'receipt_id'=>bin2hex(random_bytes(16)),'subscription'=>9001,'domain'=>'quota-fixture.invalid','active'=>true,'refresh_action'=>'synthetic-statistics-recalculate','completion'=>'positively_completed','started_at'=>$start,'completed_at'=>$now,'read_at'=>$now,'measurement_id'=>$measurement,'completion_measurement_id'=>$measurement,'unit_contract'=>'exact-byte-integers-v1','quota_limit'=>5368709120,'quota_used'=>1073741824,'limit_text'=>'5368709120 bytes','used_text'=>'1073741824 bytes','binding'=>$b];$fs=['observed_at'=>$now,'private_free'=>2147483648,'public_free'=>2147483648,'tmp_free'=>2147483648];$policy=array_intersect_key($s,array_flip(['product_id','revision','snapshot_sha256','source_selector','source_url']))+['state'=>'allowed','observed_at'=>$now,'reference'=>'Explicit synthetic source policy'];return[$r,$b,$fs,$s,$policy,$receipt];
}
function register(PDO$d,ImageOperationFrontEnd$f,string$url,array$selector,?string$receipt):array{
 global$bridge,$cycleRequests;
 // Fixture models a genuinely later supported recalculation, never rewrites old
 // receipt times. No provider action occurs: evidence is explicitly synthetic.
 $seen=$d->query("SELECT max(json_extract(evidence_json,'$.observed_at')) FROM ledger_settlements")->fetchColumn();if(is_int($seen))do{usleep(100000);}while(time()<=$seen);
 $args=cycleArgs($d,$url,$selector,$receipt);$out=$bridge->admit($d,...$args);ok($out['state']==='admission_joined'&&!$out['dispatch_authorized']&&!$out['acquisition_authorized']);$cycleRequests[]=$args;return$out['binding'];
}
function child(string$root,array$b,bool$fail=false):array{$state=$root.'/jobs/'.$b['attempt_id'];foreach([$state,$state.'/inputs',$state.'/tmp']as$p)mkdir($p,0700);file_put_contents($state.'/binding.json',json_encode($b,JSON_THROW_ON_ERROR));chmod($state.'/binding.json',0600);$pipes=[];$p=proc_open([PHP_BINARY,'-d','sys_temp_dir='.$state.'/tmp',__FILE__,'child',$root,$state,$fail?'fail':'success'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Child unavailable');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0)throw new RuntimeException('Child failed '.$exit.': '.$err.' '.$out);return json_decode(trim($out),true,32,JSON_THROW_ON_ERROR);}
function attempt(PDO$d,string$id):array{$q=$d->prepare('SELECT * FROM ledger_attempts WHERE attempt_id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC);}
$first=register($d,$f,$url,$selector,null);ok(child($root,$first)['settled']===true);$firstRow=attempt($d,$first['attempt_id']);$firstAcq=$d->query('SELECT * FROM ledger_acquisitions')->fetchAll(PDO::FETCH_ASSOC);$firstSource=$root.'/jobs/'.$first['attempt_id'].'/inputs/source-'.$first['attempt_id'].'.png';$sourceHash=hash_file('sha256',$firstSource);
$receiptPath=$root.'/site/gallery/responsive/remote-'.hash('sha256',"7\n".$url).'.json';$before=file_get_contents($receiptPath);$receipt1=json_decode($before,true,32,JSON_THROW_ON_ERROR);
$firstCycle=$cycleRequests[0];ok($bridge->admit($d,...$firstCycle)['state']==='cycle_already_consumed');ok($d->query('SELECT count(*) FROM ledger_attempts')->fetchColumn()===1);$stale=cycleArgs($d,$url,$selector,$before,$firstCycle[0]['started_at']-1);ok($bridge->admit($d,...$stale)['state']==='fresh_cycle_after_prior_effect_required');ok($d->query('SELECT count(*) FROM ledger_attempts')->fetchColumn()===1);
// Real time advance only; never rewrite receipt expiry or pin timestamps.
$started=time();do{usleep(100000);}while(time()<=$started);
$second=register($d,$f,$url,$selector,$before);ok($second['revision']===$first['revision']);ok($second['operation_key']!==$first['operation_key']);ok($second['attempt_id']!==$first['attempt_id']);ok($second['receipt_preimage_sha256']===hash('sha256',$before));ok(child($root,$second)['settled']===true);
$after=file_get_contents($receiptPath);ok($after!==$before);ok(attempt($d,$first['attempt_id'])===$firstRow);$q=$d->prepare('SELECT * FROM ledger_acquisitions WHERE attempt_id=?');$q->execute([$first['attempt_id']]);ok($q->fetchAll(PDO::FETCH_ASSOC)===$firstAcq);ok(hash_file('sha256',$firstSource)===$sourceHash);ok($d->query("SELECT count(*) FROM ledger_capacity WHERE state<>'released'")->fetchColumn()===0);$q=$d->prepare('SELECT proof_json FROM ledger_acquisitions WHERE attempt_id=?');$q->execute([$second['attempt_id']]);$secondProof=json_decode($q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);$q->closeCursor();$firstProof=json_decode($firstAcq[0]['proof_json'],true,32,JSON_THROW_ON_ERROR);ok($secondProof['verified_at']>$firstProof['verified_at']);ok($secondProof['valid_until']===$secondProof['verified_at']+86400);ok($d->query('SELECT count(*) FROM ledger_acquisitions')->fetchColumn()===2);ok($d->query('SELECT * FROM products WHERE id=7')->fetch(PDO::FETCH_ASSOC)===$original);
// Genuine third registration followed by a failed transfer holds capacity: no manual phase seeding.
$third=register($d,$f,$url,$selector,$after);ok(child($root,$third,true)['failed_unknown']===true);ok(file_get_contents($receiptPath)===$after);ok($f->consume(1,quota(),time())['state']==='pause');ok($d->query('SELECT count(*) FROM ledger_acquisitions')->fetchColumn()===2);ok($d->query("SELECT count(*) FROM ledger_capacity WHERE state='running'")->fetchColumn()===1);ok(attempt($d,$first['attempt_id'])===$firstRow);
$blocked=cycleArgs($d,$url,$selector,$after,time()+1);$blocked[0]['completed_at']=$blocked[0]['read_at']=$blocked[0]['started_at'];do{usleep(100000);}while(time()<$blocked[0]['started_at']);ok($bridge->admit($d,...$blocked)['state']==='prior_effect_not_reconciled');ok($d->query('SELECT count(*) FROM ledger_attempts')->fetchColumn()===3);
$control=new PDO('sqlite:'.$root.'/controller/control.sqlite',null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);ok($control->query('SELECT count(*) FROM cycles')->fetchColumn()===3);foreach($control->query('SELECT immutable_json,state,attempt FROM cycles')->fetchAll(PDO::FETCH_ASSOC)as$row){$im=json_decode($row['immutable_json'],true,32,JSON_THROW_ON_ERROR);ok($im['quota']['quota_used']===$im['receipt']['quota_used']+\FAS\Utils\CycleAdmissionBridge::EXTRA_BYTES);ok($row['state']==='joined'&&is_string($row['attempt']));}ok($control->query('PRAGMA page_count')->fetchColumn()*4096<=1048576);$control=null;foreach($d->query('SELECT quota_json FROM ledger_settlements')->fetchAll(PDO::FETCH_COLUMN)as$q){$q=json_decode($q,true,16,JSON_THROW_ON_ERROR);ok($q['quota_used']===1073741824+\FAS\Utils\CycleAdmissionBridge::EXTRA_BYTES);}ok($d->query('PRAGMA integrity_check(1)')->fetchColumn()==='ok');ok($d->query('PRAGMA foreign_key_check')->fetch()===false);
echo json_encode(['portable_cycle_lifecycle'=>true,'status'=>'PASS','checks'=>$n,'real_pdo_gd'=>true,'source_calls'=>3,'fresh_successful_acquisitions'=>2,'unknown_retains_capacity'=>true,'process_confinement_acceptance'=>false,'network'=>false,'root'=>$root],JSON_THROW_ON_ERROR)."\n";
