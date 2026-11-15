<?php
declare(strict_types=1);
namespace FAS\Manuals {
    // Observe native hashing, introducing a real file mutation only after the
    // application has checked its opened descriptor. No hash result is stubbed.
    function hash_update_stream($context,$stream,int $length=-1):int {
        if(isset($GLOBALS['beforeHash']))($GLOBALS['beforeHash'])();
        $read=\hash_update_stream($context,$stream,$length);
        $GLOBALS['hashRead']=$read;$GLOBALS['hashLimit']=$length;
        return $read;
    }
}
namespace {
require_once __DIR__.'/../../src/manuals/ManualLibrary.php';
use FAS\Manuals\ManualStorage;
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function rejects(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$root=sys_get_temp_dir().'/fas-hash-bound-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/site');mkdir($root.'/private');
$key=str_repeat('a',48).'.pdf';$path=$root.'/private/'.$key;$bytes="%PDF-1.4\n".str_repeat('x',65527);$file=['key'=>$key,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
try {
 $storage=new ManualStorage($root.'/private',$root.'/site');file_put_contents($path,$bytes);
 $fp=$storage->openVerified($file);check(ftell($fp)===0,'valid descriptor rewinds');check(stream_get_contents($fp)===$bytes,'valid descriptor returns complete exact file');fclose($fp);
 check($GLOBALS['hashRead']===$file['bytes'],'valid file fully hashed');check($GLOBALS['hashLimit']===$file['bytes']+1,'integrity work capped to expected length plus one');
 file_put_contents($path,str_replace('PDF','BAD',$bytes));rejects(fn()=>$storage->openVerified($file),'same-length corruption still rejected');
 file_put_contents($path,$bytes);$GLOBALS['beforeHash']=static function()use($path):void{file_put_contents($path,str_repeat('y',8*1024*1024),FILE_APPEND);};
 rejects(fn()=>$storage->openVerified($file),'growth after descriptor check rejected');
 check($GLOBALS['hashRead']===$file['bytes']+1,'8 MiB appended growth does not force reading its tail');
 unset($GLOBALS['beforeHash']);file_put_contents($path,$bytes);
 $GLOBALS['beforeHash']=static function()use($path):void{$fp=fopen($path,'c+b');ftruncate($fp,1024);fclose($fp);};
 rejects(fn()=>$storage->openVerified($file),'truncation after descriptor check rejected');check($GLOBALS['hashRead']===1024,'short native read observed');
 unset($GLOBALS['beforeHash']);file_put_contents($path,$bytes);$fp=$storage->openVerified($file);check(stream_get_contents($fp)===$bytes,'valid file works after rejected mutations');fclose($fp);
} finally {unset($GLOBALS['beforeHash']);if(is_file($path))unlink($path);rmdir($root.'/private');rmdir($root.'/site');rmdir($root);}
echo "$checks native bounded-integrity checks passed; no production files used.\n";
}
