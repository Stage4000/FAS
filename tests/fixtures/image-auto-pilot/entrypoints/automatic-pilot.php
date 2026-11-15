<?php
declare(strict_types=1);
/** Private encoding CLI: requires committed intake; never enables source network access. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/src/ImagePilot.php';
if(count($argv)!==2||!in_array($argv[1],['enqueue','run','inspect'],true))throw new RuntimeException('Explicit supported mode required');
$file=__DIR__.'/config.json';
if(is_link($file)||!is_file($file)||(fileperms($file)&0077)!==0)throw new RuntimeException('Private fixed configuration required');
$raw=file_get_contents($file,false,null,0,16385);
if(!is_string($raw)||strlen($raw)>16384)throw new RuntimeException('Configuration byte bound');
$c=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
if(($c['enabled']??false)!==true)throw new RuntimeException('Automatic image pilot disabled');
foreach(['site','private','input','database','job']as$key)if(!is_string($c[$key]??null)||$c[$key]==='')throw new RuntimeException('Missing fixed configuration');
if(!preg_match('/^[a-f0-9]{32}$/D',$c['job'])||$c['input']!==$c['private'].'/inputs')throw new RuntimeException('Fixed job/input binding required');
if(!is_file($c['database'])||is_link($c['database'])||realpath($c['database'])!==$c['database'])throw new RuntimeException('Existing canonical database required');
if(!defined('PDO::SQLITE_ATTR_OPEN_FLAGS')||!defined('PDO::SQLITE_OPEN_READWRITE'))throw new RuntimeException('SQLite no-create open flags required');
$db=new PDO('sqlite:'.$c['database'],null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READWRITE]);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$db->exec('PRAGMA busy_timeout=250');
$pilot=new \FAS\Utils\ImagePilot($db,$c['site'],$c['private'],$c['input'],true,true);
if($argv[1]==='enqueue'){
    $f=$c['private'].'/approved-record.json';
    if(is_link($f)||!is_file($f)||(fileperms($f)&0077)!==0)throw new RuntimeException('Private intake manifest required');
    $raw=file_get_contents($f,false,null,0,16385);
    if(!is_string($raw)||strlen($raw)>16384)throw new RuntimeException('Manifest byte bound');
    $result=$pilot->enqueue($c['job'],json_decode($raw,true,16,JSON_THROW_ON_ERROR));
}elseif($argv[1]==='run')$result=$pilot->run($c['job']);
else $result=$pilot->inspect($c['job']);
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
