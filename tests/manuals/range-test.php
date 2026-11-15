<?php
declare(strict_types=1);
require_once __DIR__.'/../../src/manuals/ManualByteRange.php';
require_once __DIR__.'/../../src/manuals/ManualLibrary.php';
use FAS\Manuals\ManualByteRange;
use FAS\Manuals\ManualStorage;
$checks=0;
function check($condition,string $message):void {global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$message);$checks++;echo "PASS $message\n";}
function rejects(callable $fn,string $message):void {try{$fn();}catch(Throwable $e){check(true,$message);return;}check(false,$message);}
function expected(int $status,int $offset,int $length,?string $range):array {return ['status'=>$status,'offset'=>$offset,'length'=>$length,'content_range'=>$range];}
$full=expected(200,0,100,null);$invalid=expected(416,0,0,'bytes */100');
check(ManualByteRange::plan(100,null)===$full,'No Range returns full representation');
foreach([
    'bytes=0-0'=>[0,1,'bytes 0-0/100'], 'bytes=0-9'=>[0,10,'bytes 0-9/100'],
    'bytes=99-99'=>[99,1,'bytes 99-99/100'], 'bytes=4-'=>[4,96,'bytes 4-99/100'],
    'bytes=-1'=>[99,1,'bytes 99-99/100'], 'bytes=-20'=>[80,20,'bytes 80-99/100'],
    'bytes=-100'=>[0,100,'bytes 0-99/100'], 'bytes=-101'=>[0,100,'bytes 0-99/100'],
    'bytes=1-100'=>[1,99,'bytes 1-99/100'], 'bytes=0-99999999999999999999999999999'=>[0,100,'bytes 0-99/100'],
    'bytes=-99999999999999999999999999999'=>[0,100,'bytes 0-99/100'],
    'bytes=0001-0002'=>[1,2,'bytes 1-2/100'], " \tBYTES=5-9\t "=>[5,5,'bytes 5-9/100'],
] as $header=>$parts){check(ManualByteRange::plan(100,$header)===expected(206,...$parts),'Valid range: '.trim($header));}
foreach(['','bytes=','bytes=-','bytes=-0','bytes=-000','bytes=100-','bytes=100-101','bytes=99-98','bytes=99999999999999999999999999999-',
    'bytes=0-1,4-5','bytes=0-1,','bytes=0-1, bytes=4-5','items=0-2','bytes=+1-2','bytes=1-+2','bytes=1.0-2','bytes=1e1-20',
    'bytes= 0-1','bytes=0 -1',"bytes=0-1\r\n",'bytes=0-1X','bytes=0--1','bytes=0-'.str_repeat('9',256)] as $header){
    check(ManualByteRange::plan(100,$header)===$invalid,'Invalid range rejected: '.substr(json_encode($header),0,90));
}
foreach([null,'bytes=0-3','bytes=999-','garbage'] as $range)check(ManualByteRange::plan(100,$range,'HEAD')===$full,'HEAD ignores Range '.json_encode($range));
foreach(['"unadvertised-etag"','W/"weak"','Wed, 21 Oct 2015 07:28:00 GMT',''] as $ifRange)check(ManualByteRange::plan(100,'bytes=4-8','GET',$ifRange)===$full,'If-Range returns full without validators '.json_encode($ifRange));
check(ManualByteRange::plan(1,'bytes=-1')===expected(206,0,1,'bytes 0-0/1'),'One-byte representation');
check(ManualByteRange::plan(PHP_INT_MAX,'bytes=0-')===expected(206,0,PHP_INT_MAX,'bytes 0-'.(PHP_INT_MAX-1).'/'.PHP_INT_MAX),'Maximum integer representation does not overflow');
check(ManualByteRange::plan(PHP_INT_MAX,'bytes='.(PHP_INT_MAX-1).'-'.PHP_INT_MAX)===expected(206,PHP_INT_MAX-1,1,'bytes '.(PHP_INT_MAX-1).'-'.(PHP_INT_MAX-1).'/'.PHP_INT_MAX),'Maximum integer end clamps safely');
rejects(fn()=>ManualByteRange::plan(0,'bytes=0-0'),'Empty representation refused');
rejects(fn()=>ManualByteRange::plan(-1,null),'Negative representation refused');

// Independent small-file oracle for every bounded start/end pair, including bad bounds.
for($size=1;$size<=20;$size++)for($start=0;$start<=$size+1;$start++)for($end=0;$end<=$size+1;$end++){
    $plan=ManualByteRange::plan($size,"bytes=$start-$end");
    $ok=$start<$size && $end>=$start;
    if($plan['status']!==($ok?206:416) || ($ok && ($plan['offset']!==$start || $plan['length']!==min($end,$size-1)-$start+1)))throw new RuntimeException('Small range oracle failed.');
}
check(true,'Exhaustive small-file bounds match independent oracle');

$stream=fopen('php://temp','w+b');$source=str_repeat('0123456789',20000);fwrite($stream,$source);fseek($stream,11);
$out='';$largest=0;$chunks=0;
ManualByteRange::copy($stream,150000,function(string $chunk)use(&$out,&$largest,&$chunks):void {$out.=$chunk;$largest=max($largest,strlen($chunk));$chunks++;});
check($out===substr($source,11,150000) && ftell($stream)===150011,'Bounded copy emits exact requested bytes and no tail');
check($largest<=65536 && $chunks===3,'Copy reads at most 64 KiB per chunk');
$called=false;ManualByteRange::copy($stream,0,function()use(&$called):void{$called=true;});check(!$called,'Zero-length copy emits nothing');
rejects(fn()=>ManualByteRange::copy($stream,-1,static function(){}),'Negative copy length rejected');
fseek($stream,-2,SEEK_END);$short='';
rejects(function()use($stream,&$short):void {ManualByteRange::copy($stream,3,function($chunk)use(&$short):void{$short.=$chunk;});},'Unexpected EOF does not silently succeed');
check($short===substr($source,-2),'Unexpected EOF never fabricates extra bytes');fclose($stream);

// Native read failures must not inject PHP diagnostics into a PDF response.
// On the supported Linux runtime, a directory opens as a stream but fread
// raises an I/O notice. Production storage rejects directories before copy.
$priorReporting=error_reporting(E_ALL);$priorDisplay=ini_set('display_errors','1');
$callerNotices=0;$callerHandler=static function(int $severity,string $message)use(&$callerNotices):bool {$callerNotices++;return true;};
set_error_handler($callerHandler);
try {
    $directory=fopen(__DIR__,'rb');check(is_resource($directory),'Native error fixture opens a directory stream');
    $failed=false;$written='';ob_start();
    try {ManualByteRange::copy($directory,1,function(string $chunk)use(&$written):void{$written.=$chunk;});}
    catch(RuntimeException $e){$failed=true;}
    finally {$diagnostics=ob_get_clean();fclose($directory);}
    check($failed,'Native fread I/O failure becomes a controlled exception');
    check($written==='' && $diagnostics==='','Failed read emits neither PDF bytes nor PHP diagnostics');
    check($callerNotices===0,'Read diagnostic is not delegated to a potentially printing caller handler');
    trigger_error('Caller handler remains installed after a failed read.',E_USER_NOTICE);
    check($callerNotices===1,'Caller error handler is restored after read failure');

    $stream=fopen('php://temp','w+b');fwrite($stream,'PDF');rewind($stream);$written='';ob_start();$failed=false;
    try {ManualByteRange::copy($stream,4,function(string $chunk)use(&$written):void{$written.=$chunk;trigger_error('Write callback uses caller handler.',E_USER_NOTICE);});}
    catch(RuntimeException $e){$failed=true;}
    finally {$diagnostics=ob_get_clean();fclose($stream);}
    check($failed && $written==='PDF' && $diagnostics==='','Short read preserves only real bytes without diagnostics');
    check($callerNotices===2,'Caller handler is restored before the write callback');
    trigger_error('Caller handler remains installed after EOF.',E_USER_NOTICE);
    check($callerNotices===3,'Caller error handler is restored after short read');
    check(error_reporting()===E_ALL && ini_get('display_errors')==='1','Read handling leaves PHP reporting and display settings unchanged');
} finally {
    restore_error_handler();error_reporting($priorReporting);ini_set('display_errors',$priorDisplay);
}

// Synthetic storage only. A replaced path cannot change an already verified descriptor.
$tmp=sys_get_temp_dir().'/fas-manual-range-'.bin2hex(random_bytes(8));mkdir($tmp);mkdir($tmp.'/web');mkdir($tmp.'/private');
$original="%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";
file_put_contents($tmp.'/source.pdf',$original);$storage=new ManualStorage($tmp.'/private',$tmp.'/web');$file=$storage->importLocal($tmp.'/source.pdf');
$path=$storage->verifiedPath($file);$verified=$storage->openVerified($file);
check(ftell($verified)===0 && stream_get_contents($verified)===$original,'Verified descriptor is rewound and contains the complete original');rewind($verified);
rename($path,$path.'.old');file_put_contents($path,str_replace('Catalog','Changed',$original));
check(stream_get_contents($verified)===$original,'Path replacement cannot substitute bytes on a verified open descriptor');fclose($verified);
rejects(fn()=>$storage->openVerified($file),'Same-length replacement fails complete descriptor hash');
unlink($path);rename($path.'.old',$path);
file_put_contents($path,substr($original,0,-1).'X');rejects(fn()=>$storage->openVerified($file),'Tail tamper rejected even when requested prefix would be unchanged');
file_put_contents($path,$original);$bad=$file;$bad['bytes']++;
rejects(fn()=>$storage->openVerified($bad),'Recorded byte length must match opened file');
$bad=$file;$bad['sha256']=str_repeat('0',64);rejects(fn()=>$storage->openVerified($bad),'Recorded full hash must match opened file');
check(ManualStorage::MAX_BYTES===52428800,'Existing 50 MiB intake ceiling unchanged');
echo "\n$checks range and descriptor checks passed. Synthetic fixture only: $tmp\n";
