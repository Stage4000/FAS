<?php
declare(strict_types=1);
// Pure default-off guard check; no driver, transport, filesystem or production acceptance.
if(!class_exists('PDO')){class PDO{public function inTransaction():bool{throw new RuntimeException('Default-off must not touch DB');}}}
require_once __DIR__.'/ImageRemoteAcquisition.php';
use FAS\Utils\{ImageRemoteAcquisition,ImageIntakeTransport};
class GuardOnlyPDO extends PDO{public function __construct(){}public function inTransaction():bool{throw new RuntimeException('Default-off must not touch DB');}}
$db=new GuardOnlyPDO();
$transport=new class implements ImageIntakeTransport{public int$calls=0;public function get(string$url,callable$headers,callable$chunk):void{$this->calls++;throw new RuntimeException('Transport must remain unused');}};
try{(new ImageRemoteAcquisition($db,[],'/missing-private','/missing-site'))->acquire([],$transport);throw new RuntimeException('Default-off accepted');}catch(RuntimeException$e){if($e->getMessage()!=='Explicit isolated source adapter required')throw$e;}
if($transport->calls!==0)throw new RuntimeException('Transport invoked');echo "PASS default-off short-circuit: no DB/path/transport use (pure guard only)\n";
