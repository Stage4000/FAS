<?php
declare(strict_types=1);
/** Operator CLI only. Config path is fixed at package/config.json; no arbitrary commands. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/src/ImagePilot.php';
$file=__DIR__.'/config.json';
if(is_link($file)||!is_file($file)||(fileperms($file)&0077)!==0)throw new RuntimeException('Private fixed config required');
$raw=file_get_contents($file,false,null,0,16385);if($raw===false||strlen($raw)>16384)throw new RuntimeException('Config bound');$c=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
if(($c['enabled']??false)!==true)throw new RuntimeException('Pilot disabled');
foreach(['site','private','input','database','job']as$key)if(!is_string($c[$key]??null)||$c[$key]==='')throw new RuntimeException('Missing fixed config');
if(!is_file($c['database'])||is_link($c['database'])||realpath($c['database'])!==$c['database'])throw new RuntimeException('Existing canonical database required');
$db=new PDO('sqlite:'.$c['database']);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$db->exec('PRAGMA busy_timeout=250');
$pilot=new \FAS\Utils\ImagePilot($db,$c['site'],$c['private'],$c['input'],true);
$mode=$argv[1]??'inspect';if(count($argv)>2)throw new RuntimeException('Unexpected CLI arguments');
if($mode==='enqueue'){
    $f=$c['private'].'/approved-record.json';if(is_link($f)||!is_file($f))throw new RuntimeException('Approved record missing');$v=file_get_contents($f,false,null,0,16385);if($v===false||strlen($v)>16384)throw new RuntimeException('Record bound');$result=$pilot->enqueue($c['job'],json_decode($v,true,16,JSON_THROW_ON_ERROR));
}elseif($mode==='run')$result=$pilot->run($c['job']);elseif($mode==='inspect')$result=$pilot->inspect($c['job']);else throw new RuntimeException('Unknown mode');
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
