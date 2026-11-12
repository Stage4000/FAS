<?php
declare(strict_types=1);
/** Fixed-path private source-acquisition CLI. Separate from the network-disabled encoder. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/src/ImageSourceIntake.php';
require_once __DIR__.'/src/PosixImageIntakeStore.php';
if(count($argv)!==2||$argv[1]!=='acquire')throw new RuntimeException('Only explicit acquire mode is supported');
$file=__DIR__.'/config.json';
if(is_link($file)||!is_file($file)||(fileperms($file)&0077)!==0)throw new RuntimeException('Private fixed configuration required');
$raw=file_get_contents($file,false,null,0,16385);
if(!is_string($raw)||strlen($raw)>16384)throw new RuntimeException('Configuration byte bound');
$c=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
if(($c['enabled']??false)!==true||($c['network_enabled']??false)!==true)throw new RuntimeException('Source acquisition disabled');
foreach(['site','private','input','database','job']as$key)if(!is_string($c[$key]??null)||$c[$key]==='')throw new RuntimeException('Missing fixed configuration');
if(!preg_match('/^[a-f0-9]{32}$/D',$c['job'])||$c['input']!==$c['private'].'/inputs')throw new RuntimeException('Fixed job/input binding required');
if(!is_file($c['database'])||is_link($c['database'])||realpath($c['database'])!==$c['database'])throw new RuntimeException('Existing canonical database required');
$store=new \FAS\Utils\PosixImageIntakeStore($c['private'],$c['site']);
$f=$c['private'].'/intake-request.json';
if(is_link($f)||!is_file($f)||(fileperms($f)&0077)!==0)throw new RuntimeException('Private fixed intake request required');
$raw=file_get_contents($f,false,null,0,262145);
if(!is_string($raw)||strlen($raw)>262144)throw new RuntimeException('Intake request byte bound');
$job=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
if(!is_array($job)||($job['job']??null)!==$c['job'])throw new RuntimeException('Intake identity differs from fixed configuration');
if(!defined('PDO::SQLITE_ATTR_OPEN_FLAGS')||!defined('PDO::SQLITE_OPEN_READONLY'))throw new RuntimeException('SQLite read-only open flags required');
$db=new PDO('sqlite:'.$c['database'],null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$db->exec('PRAGMA busy_timeout=250');
$intake=new \FAS\Utils\ImageSourceIntake($db,$store,new \FAS\Utils\CurlImageIntakeTransport(true),true);
$record=$intake->acquire($job);
echo json_encode(['job'=>$c['job'],'state'=>'ready','record_sha256'=>hash('sha256',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),'retry_authorized'=>false],JSON_THROW_ON_ERROR)."\n";
