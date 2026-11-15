<?php
declare(strict_types=1);
/** Explicit gallery intake -> durable store -> bound pilot; synthetic PNG and fake transport only. */
require_once __DIR__.'/fixtures/image-auto-pilot/ImageSourceIntake.php';
require_once __DIR__.'/fixtures/image-auto-pilot/PosixImageIntakeStore.php';
require_once __DIR__.'/fixtures/image-auto-pilot/ImagePilot.php';
use FAS\Utils\ImageSourceIntake;
use FAS\Utils\PosixImageIntakeStore;
use FAS\Utils\ImageIntakeTransport;
use FAS\Utils\ImagePilot;
use FAS\Utils\ResponsiveImage;
if(PHP_SAPI!=='cli'||!extension_loaded('pdo_sqlite')||!function_exists('imagewebp')||!function_exists('posix_geteuid'))throw new RuntimeException('Actual PDO/GD/POSIX runtime required');
$root=getcwd().'/gallery-pipeline-synthetic-'.bin2hex(random_bytes(8));
foreach([$root,$root.'/private',$root.'/private/inputs',$root.'/private/tmp',$root.'/site',$root.'/site/gallery',$root.'/site/gallery/responsive']as$p)if(!mkdir($p,0700))throw new RuntimeException('Fixture mkdir');
$private=$root.'/private';$site=$root.'/site';
putenv('TMPDIR='.$private.'/tmp');if(sys_get_temp_dir()!==$private.'/tmp')throw new RuntimeException('Private tmp required');
$db=new PDO('sqlite:'.$root.'/catalog.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$db->exec('PRAGMA busy_timeout=250');
$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url TEXT,images TEXT)');
$url='https://i.ebayimg.com/images/g/SYNTHETIC/s-l1600.png';$primary='https://i.ebayimg.com/images/g/OTHER/s-l1600.png';$gallery=json_encode([$url,$url,$primary]);$db->prepare('INSERT INTO products VALUES(1,1,1,?,?)')->execute([$primary,$gallery]);
$image=imagecreatetruecolor(640,480);for($y=0;$y<480;$y++)for($x=0;$x<640;$x++)imagesetpixel($image,$x,$y,(($x*19+$y*7)%256)<<16|(($x*3+$y*11)%256)<<8|(($x+$y*5)%256));
ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
$transport=new class($bytes) implements ImageIntakeTransport {
    public int $calls=0;
    public function __construct(private string $bytes){}
    public function get(string $url,callable $headers,callable $chunk):void{$this->calls++;$headers(200,['Content-Type'=>'image/png','Content-Length'=>(string)strlen($this->bytes)]);foreach(str_split($this->bytes,16384)as$piece)$chunk($piece);}
};
$id=str_repeat('a',32);$snapshot=ImageSourceIntake::snapshot($db,'1');
$job=['job'=>$id,'product_id'=>'1','source_url'=>$url,'reference'=>'Synthetic pipeline acceptance only','intent'=>'initial','catalog_snapshot'=>$snapshot,'receipt_preimage_sha256'=>null,'optimization_authorized'=>true,'source_selector'=>['kind'=>'gallery','index'=>0]];
$store=new PosixImageIntakeStore($private,$site);$intake=new ImageSourceIntake($db,$store,$transport,true);
$checks=0;function check(bool $v,string $label):void{global$checks;$checks++;if(!$v)throw new RuntimeException($label);}
function refuses(callable $f,string $label):void {try{$f();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
foreach([null,['kind'=>'gallery','index'=>3],['kind'=>'gallery','index'=>2],['kind'=>'gallery','index'=>'0'],['kind'=>'gallery','index'=>-1]]as$selector){$bad=$job;$bad['source_selector']=$selector;refuses(fn()=>$intake->acquire($bad),'invalid/mismatched index before fetch');}
$bad=$job;unset($bad['source_selector']);refuses(fn()=>$intake->acquire($bad),'missing selector cannot substitute gallery for primary');
check($transport->calls===0&&!file_exists($private.'/intake-attempt.jsonl'),'selection refusals precede claim/fetch');
$record=$intake->acquire($job);

$receipt=$site.'/gallery/responsive/remote-'.hash('sha256',"1\n".$url).'.json';
check($transport->calls===1&&!file_exists($receipt),'intake does not publish images');
check(file_get_contents($private.'/inputs/'.$record['local_file'])===$bytes,'exact source retained');
$beforeJournal=file_get_contents($private.'/intake-attempt.jsonl');
$pilot=new ImagePilot($db,$site,$private,$private.'/inputs',true,true);

check($record['source_selector']===['kind'=>'gallery','index'=>0],'selector retained in record');
$manifest=file_get_contents($private.'/approved-record.json');
// Even the same URL at a duplicate slot is not permission to change selection.
foreach([['kind'=>'gallery','index'=>1],['kind'=>'primary']]as$selector){
 $tampered=$record;$tampered['source_selector']=$selector;
 file_put_contents($private.'/approved-record.json',json_encode($tampered,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
 refuses(fn()=>$pilot->enqueue($id,$tampered),'coherent manifest/selector tamper disagrees with receiving context');
 file_put_contents($private.'/approved-record.json',$manifest);
}
check($pilot->enqueue($id,$record)['state']==='queued','durable gallery intake accepted without rebasing');
foreach(['[]',json_encode([$primary,$url,$url]),json_encode([$url]),json_encode([$primary,$primary,$primary])]as$changed){
 $db->prepare('UPDATE products SET images=? WHERE id=1')->execute([$changed]);
 refuses(fn()=>$pilot->run($id),'changed index/removal/source/duplicate count refuses before encoding');
 check(json_decode(file_get_contents($private.'/job-'.$id.'.json'),true)['state']==='queued','refusal before running');
 $db->prepare('UPDATE products SET images=? WHERE id=1')->execute([$gallery]);
}

$out=$pilot->run($id);check($out['state']==='done'&&$out['variants']>0,'actual image lifecycle done');
check($pilot->run($id)['state']==='done','completed job does not encode twice');
$r=json_decode(file_get_contents($receipt),true,16,JSON_THROW_ON_ERROR);
check($r['source_sha256']===hash('sha256',$bytes)&&$r['source_url']===$url,'public receipt exact gallery source');
check(!file_exists($site.'/gallery/responsive/remote-'.hash('sha256',"1\n".$primary).'.json'),'primary receipt untouched');
check(str_contains(ResponsiveImage::attributes($url,'100vw',0,$site,'1'),'srcset='),'actual renderer accepts variants');
check(ImageSourceIntake::snapshot($db,'1')===$snapshot,'catalog untouched');
check(file_get_contents($private.'/intake-attempt.jsonl')===$beforeJournal,'intake evidence immutable');
check(hash_file('sha256',$private.'/inputs/'.$record['local_file'])===hash('sha256',$bytes),'original unchanged after publication');
$rejected=false;try{$intake->acquire($job);}catch(Throwable $e){$rejected=true;}
check($rejected&&$transport->calls===1,'repeat intake cannot redownload');
check(!$db->inTransaction(),'no catalog lock leaked');
echo json_encode(['status'=>'PASS','checks'=>$checks,'php'=>PHP_VERSION,'sqlite'=>$db->query('SELECT sqlite_version()')->fetchColumn(),'transport'=>'fake only','fixture_root'=>$root],JSON_THROW_ON_ERROR)."\n";
