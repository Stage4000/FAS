<?php
declare(strict_types=1);
namespace FAS\Utils {
    // Namespace-local cURL doubles: enabling the adapter here cannot perform a network request.
    function curl_init(string $url): object|false { if($GLOBALS['curl_init_fail']??false)return false; return (object)['url'=>$url,'options'=>[]]; }
    function curl_setopt_array(object $h,array $options): bool { $h->options=$options; $GLOBALS['curl_options']=$options; return !($GLOBALS['curl_setopt_fail']??false); }
    function curl_exec(object $h): bool {
        foreach($GLOBALS['curl_headers'] as $line) ($h->options[CURLOPT_HEADERFUNCTION])($h,$line);
        ($h->options[CURLOPT_WRITEFUNCTION])($h,$GLOBALS['fixture_png']); return true;
    }
    function curl_getinfo(object $h,int $option): string { return $GLOBALS['curl_effective']??$h->url; }
    function curl_close(object $h): void {}
}
namespace {
require_once __DIR__.'/fixtures/image-auto-pilot/ImageSourceIntake.php';
require_once __DIR__.'/fixtures/image-auto-pilot/PosixImageIntakeStore.php';
use FAS\Utils\ImageSourceIntake as Intake;
use FAS\Utils\ImageIntakeStore;
use FAS\Utils\ImageIntakeTransport;
use FAS\Utils\CurlImageIntakeTransport;

final class MemoryStore implements ImageIntakeStore {
    public ?string $raw=null; public array $events=[]; public array $files=[]; public ?array $record=null;
    public ?Closure $onOriginal=null; public ?Closure $onReady=null;
    public bool $claimed=false; public bool $failReady=false; public bool $failReadyEvent=false; public int $releases=0;
    public function receipt(string $id,string $url):?string{return $this->raw;}
    public function claim(string $job,array $context):void{if($this->events||$this->files||$this->record!==null)throw new RuntimeException('Existing attempt/output: no retry');$this->claimed=true;$this->events[]=['receiving',$context];}
    public function event(string $state,array $evidence=[]):void{if($state==='ready'&&$this->failReadyEvent)throw new RuntimeException('Journal sync failed');$this->events[]=[$state,$evidence];}
    public function original(string $job,string $extension,string $bytes):string{$n='source-'.$job.'.'.$extension;if(isset($this->files[$n]))throw new RuntimeException('Existing original');$this->files[$n]=$bytes;if($this->onOriginal)($this->onOriginal)();return $n;}
    public function ready(array $record):void{if($this->failReady)throw new RuntimeException('Publication uncertain');if($this->record!==null)throw new RuntimeException('Existing record');$this->record=$record;if($this->onReady)($this->onReady)();}
    public function release():void{$this->claimed=false;$this->releases++;}
}
final class FakeTransport implements ImageIntakeTransport {
    public int $calls=0;
    public function __construct(public Closure $action){}
    public function get(string $url,callable $headers,callable $chunk):void{$this->calls++;($this->action)($url,$headers,$chunk);}
}
$assertions=0;$cases=[];
function check(bool $value,string $label):void{global$assertions;if(!$value)throw new RuntimeException('FAIL '.$label);$assertions++;}
function test(string $name,Closure $body):void{global$cases;$body();$cases[]=$name;}
function denied(Closure $body,string $needle=''):void{try{$body();}catch(Throwable $e){check($needle===''||str_contains($e->getMessage(),$needle),'expected rejection '.$needle.' got '.$e->getMessage());return;}throw new RuntimeException('Expected rejection '.$needle);}
function imageFixture(string $format):string{$im=imagecreatetruecolor(120,80);imagefill($im,0,0,imagecolorallocate($im,40,120,80));ob_start();match($format){'png'=>imagepng($im),'jpg'=>imagejpeg($im),'webp'=>imagewebp($im)};return ob_get_clean();}
$png=imageFixture('png');$jpg=imageFixture('jpg');$webp=imageFixture('webp');$GLOBALS['fixture_png']=$png;
function env(?Closure $action=null):array{
    global$png;
    $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url TEXT,images TEXT)');
    $url='https://i.ebayimg.com/images/g/synthetic/s-l1600.png?set_id=fixture';
    $q=$db->prepare('INSERT INTO products VALUES(7,1,1,?,?)');$q->execute([$url,json_encode([$url])]);
    $store=new MemoryStore();$transport=new FakeTransport($action??static function($url,$head,$chunk)use($png){$head(200,['Content-Type'=>'image/png','Content-Length'=>(string)strlen($png)]);$chunk($png);});
    $job=['job'=>str_repeat('a',32),'product_id'=>'7','source_url'=>$url,'reference'=>'Synthetic explicit authorization; no real product','optimization_authorized'=>true,'intent'=>'initial','receipt_preimage_sha256'=>null,'catalog_snapshot'=>Intake::snapshot($db,'7')];
    return [$db,$store,$transport,$job];
}
function acquire(array $e):array{return(new Intake($e[0],$e[1],$e[2],true))->acquire($e[3]);}

test('default off: zero transport and writes',function(){[$d,$s,$t,$j]=env();denied(fn()=>(new Intake($d,$s,$t))->acquire($j),'disabled');check($t->calls===0&&$s->events===[],'no effects');});
test('network adapter independently default off',function(){[$d,$s,$t,$j]=env();$i=new Intake($d,$s,new CurlImageIntakeTransport(),true);denied(fn()=>$i->acquire($j),'disabled');check(end($s->events)[0]==='failed_or_unknown','failed attempt retained');denied(fn()=>$i->acquire($j),'no retry');});
test('PNG: fresh bound record, original preserved, one call, no catalog mutation',function()use($png){$e=env();$r=acquire($e);check($e[2]->calls===1,'one transfer');check($e[1]->files[$r['local_file']]===$png,'original exact');check($r['source_sha256']===hash('sha256',$png),'hash');check($r['intake']['image']['orientation']===1,'orientation');check($r['intake']['image']['width']===120,'width');check($r['intake']['catalog_snapshot_sha256']===Intake::snapshotHash($e[3]['catalog_snapshot']),'snapshot hash');check(Intake::snapshot($e[0],'7')===$e[3]['catalog_snapshot'],'catalog untouched');check(strtotime($r['valid_until'])-strtotime($r['verified_at'])===86400,'TTL bound');check(end($e[1]->events)[0]==='ready','ready marker');});
test('supported JPEG and WebP metadata',function()use($jpg,$webp){check(Intake::inspect($jpg,'image/jpeg')['orientation']===1,'JPEG orientation');check(Intake::inspect($webp,'image/webp')['extension']==='webp','WebP supported');});
$badUrls=['http://i.ebayimg.com/a','https://i.ebayimg.com.attacker.test/a','https://attacker.test/i.ebayimg.com/a','https://i.ebayimg.com@attacker.test/a','https://name:secret@i.ebayimg.com/a','https://i.ebayimg.com:443/a','https://i.ebayimg.com/a#fragment','https://i.ebayimg.com./a','https://I.EBAYIMG.COM/a','https://%69.ebayimg.com/a','https://i.ebayimg.com\\@attacker.test/a','https://i.ebayimg.com/a b','https://i.ebayimg.com','https://i.ebayimg.com/a,','//i.ebayimg.com/a'];
foreach($badUrls as$n=>$url)test('exact source rejection '.$n,function()use($url){$e=env();$e[3]['source_url']=$url;denied(fn()=>acquire($e),'Exact HTTPS');check($e[2]->calls===0&&$e[1]->events===[],'reject before transport');});
foreach([301,302,303,307,308,404,500]as$status)test('HTTP status rejected '.$status,function()use($status){$e=env(static function($u,$h,$c)use($status){$h($status,['content-type'=>'image/png','location'=>'https://i.ebayimg.com/elsewhere']);});denied(fn()=>acquire($e),'HTTP200');check($e[2]->calls===1&&$e[1]->files===[],'no follow/output');});
test('200 Location rejected',function(){$e=env(static fn($u,$h,$c)=>$h(200,['content-type'=>'image/png','location'=>'https://attacker.test/a']));denied(fn()=>acquire($e),'Redirect');});
test('oversize announced response rejected',function(){$e=env(static fn($u,$h,$c)=>$h(200,['content-type'=>'image/png','content-length'=>'2097153']));denied(fn()=>acquire($e),'length bound');check($e[1]->files===[],'no original');});
test('oversize streamed response rejected',function(){$e=env(static function($u,$h,$c){$h(200,['content-type'=>'image/png']);for($n=0;$n<33;$n++)$c(str_repeat('x',65536));});denied(fn()=>acquire($e),'byte/order');check($e[1]->files===[],'no original');});
test('large individual chunk rejected',function(){$e=env(static function($u,$h,$c){$h(200,['content-type'=>'image/png']);$c(str_repeat('x',65537));});denied(fn()=>acquire($e),'byte/order');});
test('body before headers rejected',function()use($png){$e=env(static fn($u,$h,$c)=>$c($png));denied(fn()=>acquire($e),'byte/order');});
test('truncated body rejected',function()use($png){$e=env(static function($u,$h,$c)use($png){$h(200,['content-type'=>'image/png','content-length'=>(string)(strlen($png)+1)]);$c($png);});denied(fn()=>acquire($e),'Incomplete');});
test('compressed response rejected',function(){$e=env(static fn($u,$h,$c)=>$h(200,['content-type'=>'image/png','content-encoding'=>'gzip']));denied(fn()=>acquire($e),'encoding');});
test('ambiguous MIME headers rejected',function(){$e=env(static fn($u,$h,$c)=>$h(200,['Content-Type'=>'image/png','content-type'=>'image/jpeg']));denied(fn()=>acquire($e),'Ambiguous');});
test('wrong MIME rejected',function()use($png){$e=env(static function($u,$h,$c)use($png){$h(200,['content-type'=>'image/jpeg']);$c($png);});denied(fn()=>acquire($e),'MIME/dimension');});
test('invalid image rejected',function(){$e=env(static function($u,$h,$c){$h(200,['content-type'=>'image/png']);$c('not an image');});denied(fn()=>acquire($e),'MIME/dimension');});
test('incomplete PNG rejected',function()use($png){denied(fn()=>Intake::inspect(substr($png,0,-12),'image/png'),'incomplete');});
test('PNG trailing bytes rejected',function()use($png){denied(fn()=>Intake::inspect($png.'junk','image/png'),'trailing');});
test('pixel bomb rejected before decode',function()use($png){$huge=substr_replace($png,pack('NN',3000,3000),16,8);$huge=substr_replace($huge,hash('crc32b',substr($huge,12,17),true),29,4);denied(fn()=>Intake::inspect($huge,'image/png'),'dimension');});
test('PNG orientation chunk rejected',function()use($png){$data='test';$part=pack('N',4).'eXIf'.$data.hash('crc32b','eXIf'.$data,true);denied(fn()=>Intake::inspect(substr($png,0,-12).$part.substr($png,-12),'image/png'),'metadata');});
test('WebP EXIF chunk rejected',function()use($webp){$bytes=$webp.'EXIF'.pack('V',4).'test';$bytes=substr_replace($bytes,pack('V',strlen($bytes)-8),4,4);denied(fn()=>Intake::inspect($bytes,'image/webp'),'metadata');});
test('JPEG rotation rejected',function()use($jpg){$tiff="II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00\x00\x00\x00\x00";$app="Exif\0\0".$tiff;$rot=substr($jpg,0,2)."\xff\xe1".pack('n',strlen($app)+2).$app.substr($jpg,2);denied(fn()=>Intake::inspect($rot,'image/jpeg'),'Rotated');});
test('elapsed stream deadline enforced',function()use($png){$clock=0;$e=env(static function($u,$h,$c)use(&$clock,$png){$h(200,['content-type'=>'image/png']);$clock=10000000001;$c($png);});$i=new Intake($e[0],$e[1],$e[2],true,null,static function()use(&$clock){return$clock;});denied(fn()=>$i->acquire($e[3]),'deadline');check($e[1]->files===[],'deadline no stage');});
test('changed pre-job catalog rejected before call',function(){$e=env();$e[0]->exec("UPDATE products SET image_url='https://i.ebayimg.com/changed'");denied(fn()=>acquire($e),'changed');check($e[2]->calls===0,'no transfer');});
foreach(['image_url','images','is_active','show_on_website']as$field)test('catalog change during response '.$field,function()use($field,$png){$e=env();$e[2]->action=static function($u,$h,$c)use($e,$field,$png){$h(200,['content-type'=>'image/png']);$value=in_array($field,['is_active','show_on_website'],true)?'0':"'changed'";$e[0]->exec('UPDATE products SET '.$field.'='.$value);$c($png);};denied(fn()=>acquire($e));check($e[1]->files===[],'changed binding not staged');});
test('exclusion before transfer rejected',function(){$e=env();$e[1]->raw=json_encode(['product_id'=>'7','source_url'=>$e[3]['source_url'],'excluded'=>true]);$e[3]['receipt_preimage_sha256']=hash('sha256',$e[1]->raw);$e[3]['intent']='renew';denied(fn()=>acquire($e),'Excluded');check($e[2]->calls===0,'excluded no call');});
test('exclusion changed during transfer rejected',function()use($png){$e=env();$e[2]->action=static function($u,$h,$c)use($e,$png){$h(200,['content-type'=>'image/png']);$e[1]->raw=json_encode(['product_id'=>'7','source_url'=>$u,'excluded'=>true]);$c($png);};denied(fn()=>acquire($e),'Excluded');check($e[1]->files===[],'no excluded output');});
test('invalid receipt preserved',function(){$e=env();$e[1]->raw='{broken';denied(fn()=>acquire($e));check($e[1]->raw==='{broken'&&$e[2]->calls===0,'bad receipt untouched');});
test('fresh renewal requires exact receipt preimage and download',function(){$e=env();$e[1]->raw=json_encode(['version'=>1,'product_id'=>'7','source_url'=>$e[3]['source_url'],'valid_until'=>1]);$old=$e[1]->raw;$e[3]['receipt_preimage_sha256']=hash('sha256',$old);$e[3]['intent']='renew';$r=acquire($e);check($e[2]->calls===1&&$e[1]->raw===$old,'fresh transfer but no receipt write');check($r['intake']['receipt_preimage_sha256']===hash('sha256',$old),'renew bound');});
test('blind initial overwrite refused',function(){$e=env();$e[1]->raw=json_encode(['product_id'=>'7','source_url'=>$e[3]['source_url']]);$e[3]['receipt_preimage_sha256']=hash('sha256',$e[1]->raw);denied(fn()=>acquire($e),'intent');});
test('success cannot be retried',function(){$e=env();acquire($e);denied(fn()=>acquire($e),'no retry');check($e[2]->calls===1,'no second call');});
test('uncertain existing original preserved',function(){$e=env();$e[1]->files['unknown.png']='retained';denied(fn()=>acquire($e),'no retry');check($e[1]->files['unknown.png']==='retained'&&$e[2]->calls===0,'uncertain retained');});
test('uncertain existing manifest preserved',function(){$e=env();$e[1]->record=['unknown'=>true];denied(fn()=>acquire($e),'no retry');check($e[1]->record===['unknown'=>true],'manifest retained');});
test('mid-transfer failure retained and no retry',function(){$e=env(static function($u,$h,$c){$h(200,['content-type'=>'image/png']);$c('partial');throw new RuntimeException('Connection lost');});denied(fn()=>acquire($e),'Connection');denied(fn()=>acquire($e),'no retry');check($e[2]->calls===1&&$e[1]->files===[],'failure no restart');});
test('failure after stage retains original and blocks retry',function(){$e=env();$e[1]->failReady=true;denied(fn()=>acquire($e),'uncertain');check(count($e[1]->files)===1,'stage retained');denied(fn()=>acquire($e),'no retry');});
test('uncertain commit is not marked ready',function(){$e=env();$e[1]->failReadyEvent=true;denied(fn()=>acquire($e),'Journal');check($e[1]->record!==null&&end($e[1]->events)[0]==='failed_or_unknown','uncertain record retained');denied(fn()=>acquire($e),'no retry');});
test('cURL adapter strict config through namespace fake only',function()use($png){$GLOBALS['curl_headers']=["HTTP/2 200\r\n","Content-Type: image/png\r\n","Content-Length: ".strlen($png)."\r\n","\r\n"];$e=env();$r=(new Intake($e[0],$e[1],new CurlImageIntakeTransport(true),true))->acquire($e[3]);$o=$GLOBALS['curl_options'];check($o[CURLOPT_FOLLOWLOCATION]===false&&$o[CURLOPT_MAXREDIRS]===0,'no redirects');check($o[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS,'HTTPS only');check($o[CURLOPT_TIMEOUT_MS]===10000&&$o[CURLOPT_CONNECTTIMEOUT_MS]===3000,'timeouts');check($o[CURLOPT_SSL_VERIFYPEER]===true&&$o[CURLOPT_SSL_VERIFYHOST]===2,'TLS verified');check($o[CURLOPT_MAXFILESIZE]===2097152&&$o[CURLOPT_HTTP_CONTENT_DECODING]===false,'byte and decompression limits');check($r['source_sha256']===hash('sha256',$png),'adapter streamed exact');});
test('cURL fake redirect refused before chunk',function(){$GLOBALS['curl_headers']=["HTTP/2 302\r\n","Location: https://attacker.test/image\r\n","\r\n"];$e=env();denied(fn()=>(new Intake($e[0],$e[1],new CurlImageIntakeTransport(true),true))->acquire($e[3]),'HTTP200');});
test('POSIX store fails closed on Windows',function(){if(DIRECTORY_SEPARATOR==='\\')denied(fn()=>new FAS\Utils\PosixImageIntakeStore('C:/not-used','C:/not-used'),'POSIX');else check(true,'POSIX disk test separate');});
test('slow original persistence cannot mint new freshness',function(){
    $clock=0;$wall=1700000000;$e=env();
    $e[1]->onOriginal=static function()use(&$clock,&$wall){$clock=10000000001;$wall+=3600;};
    $i=new Intake($e[0],$e[1],$e[2],true,static function()use(&$wall){return $wall;},static function()use(&$clock){return $clock;});
    denied(fn()=>$i->acquire($e[3]),'deadline');check($e[1]->record===null&&end($e[1]->events)[0]==='failed_or_unknown','delayed input retained without manifest');
});
test('freshness is captured at validation not persistence completion',function(){
    $clock=0;$wall=1700000000;$e=env();$e[1]->onOriginal=static function()use(&$wall){$wall+=100;};
    $i=new Intake($e[0],$e[1],$e[2],true,static function()use(&$wall){return $wall;},static function()use(&$clock){return $clock;});
    $r=$i->acquire($e[3]);check(strtotime($r['verified_at'])===1700000000,'validation timestamp preserved');
});
test('slow manifest persistence lacks ready commit marker',function(){
    $clock=0;$e=env();$e[1]->onReady=static function()use(&$clock){$clock=10000000001;};
    $i=new Intake($e[0],$e[1],$e[2],true,null,static function()use(&$clock){return $clock;});
    denied(fn()=>$i->acquire($e[3]),'deadline');check($e[1]->record!==null&&end($e[1]->events)[0]==='failed_or_unknown','uncommitted manifest preserved');
});
test('cURL option configuration failure refuses transfer',function(){
    $GLOBALS['curl_setopt_fail']=true;$e=env();
    try{denied(fn()=>(new Intake($e[0],$e[1],new CurlImageIntakeTransport(true),true))->acquire($e[3]),'limits configuration');check($e[1]->record===null&&$e[1]->files===[],'no download output');}
    finally{$GLOBALS['curl_setopt_fail']=false;}
});
test('cURL init failure is retained without output',function(){
    $GLOBALS['curl_init_fail']=true;$e=env();try{denied(fn()=>(new Intake($e[0],$e[1],new CurlImageIntakeTransport(true),true))->acquire($e[3]),'initialization');check($e[1]->files===[],'no initialization output');}finally{$GLOBALS['curl_init_fail']=false;}
});
foreach([
    ['HTTP/2 200', 'Content-Type: image/png','Content-Type: image/png',''],
    ['HTTP/2 100','','HTTP/2 200','Content-Type: image/png',''],
    ['HTTP/2 200','Content-Type: image/png','','Trailer: unexpected'],
]as$headers)test('cURL rejects ambiguous headers '.json_encode($headers),function()use($headers){$GLOBALS['curl_headers']=array_map(fn($h)=>$h."\r\n",$headers);$e=env();denied(fn()=>(new Intake($e[0],$e[1],new CurlImageIntakeTransport(true),true))->acquire($e[3]));check($e[1]->files===[],'no ambiguous output');});
test('cURL effective destination mismatch refuses stage',function()use($png){$GLOBALS['curl_headers']=["HTTP/2 200\r\n","Content-Type: image/png\r\n","\r\n"];$GLOBALS['curl_effective']='https://attacker.test/x';$e=env();try{denied(fn()=>(new Intake($e[0],$e[1],new CurlImageIntakeTransport(true),true))->acquire($e[3]),'incomplete');check($e[1]->files===[],'wrong destination no stage');}finally{unset($GLOBALS['curl_effective']);}});
test('cURL incomplete length refuses stage',function()use($png){$GLOBALS['curl_headers']=["HTTP/2 200\r\n","Content-Type: image/png\r\n","Content-Length: ".(strlen($png)+1)."\r\n","\r\n"];$e=env();denied(fn()=>(new Intake($e[0],$e[1],new CurlImageIntakeTransport(true),true))->acquire($e[3]),'Incomplete');check($e[1]->files===[],'incomplete no stage');});
test('JPEG ASCII orientation is not integer normalization',function()use($jpg){$tiff="II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x02\x00\x02\x00\x00\x00"."1\x00\x00\x00\x00\x00\x00\x00";$app="Exif\0\0".$tiff;$image=substr($jpg,0,2)."\xff\xe1".pack('n',strlen($app)+2).$app.substr($jpg,2);denied(fn()=>Intake::inspect($image,'image/jpeg'));});
echo json_encode(['status'=>'passed','cases'=>count($cases),'assertions'=>$assertions,'runtime'=>PHP_VERSION,'transport'=>'fake only; namespace cURL interception; real curl_exec disabled by test command','cases_passed'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}
