<?php
require __DIR__.'/fixture-config-reload.php';
$n=0;
function verifyReload(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function deniesReload(callable $f,string $label):void {try{$f();}catch(Throwable $e){verifyReload(true,$label);return;}throw new RuntimeException($label);}
verifyReload(fasFixtureInvalidate(false,null)==='no_opcache','No OPcache explicitly records no invalidation');
verifyReload(fasFixtureInvalidate(false,static function(){throw new RuntimeException('must not call');})==='no_opcache','Absent OPcache never invokes an invalidator');
$calls=0;verifyReload(fasFixtureInvalidate(true,static function()use(&$calls):bool{$calls++;return true;})==='forced_invalidation'&&$calls===1,'Active cache requests exactly one successful invalidation');
deniesReload(static function(){fasFixtureInvalidate(true,null);},'Active cache with unavailable API fails closed');
deniesReload(static function(){fasFixtureInvalidate(true,static function(){return false;});},'API denial fails closed');
deniesReload(static function(){fasFixtureInvalidate(true,static function(){return 1;});},'Truthy non-boolean API result is not success');
$seen=[];$reporting=error_reporting();$handler=static function(int $severity,string $message)use(&$seen):bool{$seen[]=$message;return true;};
set_error_handler($handler);
try {
 deniesReload(static function(){fasFixtureInvalidate(true,static function(){trigger_error('synthetic restricted API',E_USER_WARNING);return true;});},'Restricted API warning becomes a closed failure');
 trigger_error('after-warning',E_USER_WARNING);verifyReload($seen===['after-warning'],'Prior handler restored after warning');
 deniesReload(static function(){fasFixtureInvalidate(true,static function(){throw new RuntimeException('synthetic');});},'Thrown invalidation failure remains failure');
 trigger_error('after-exception',E_USER_WARNING);verifyReload($seen===['after-warning','after-exception'],'Prior handler restored after exception');
 fasFixtureInvalidate(true,static function(){return true;});trigger_error('after-success',E_USER_WARNING);
 verifyReload($seen===['after-warning','after-exception','after-success'],'Prior handler restored after success');
 verifyReload(error_reporting()===$reporting,'Error-reporting policy unchanged');
}finally{restore_error_handler();}
echo "$n fixture invalidation unit checks passed; injected callbacks, not an OPcache integration proof.\n";
