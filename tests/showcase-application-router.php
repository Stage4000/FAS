<?php
declare(strict_types=1);
// Synthetic loopback fixture only. Never include from or deploy as an application route.
$key = getenv('FAS_SHOWCASE_FIXTURE_KEY');
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($key) || strlen($key) !== 64 || !is_string($_SERVER['HTTP_X_SHOWCASE_FIXTURE'] ?? null)
    || !hash_equals($key, $_SERVER['HTTP_X_SHOWCASE_FIXTURE'])) { http_response_code(404); exit; }
foreach (['Photo','Submission','Repository','Request','Form','Views'] as $name) { require_once __DIR__ . '/../src/showcase/Showcase' . $name . '.php'; }
use FAS\Showcase\ShowcaseRepository;
use FAS\Showcase\ShowcaseRequest;
use FAS\Showcase\ShowcaseForm;
use FAS\Showcase\ShowcaseViews;
session_start();
header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');
$db = new PDO('sqlite:' . getenv('FAS_SHOWCASE_FIXTURE_DB'), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');


foreach (['SessionForms','IntakeController','RateAdmission','Concurrency','IntakeApplication'] as $name) require_once __DIR__.'/../src/showcase/Showcase'.$name.'.php';
$root=dirname(getenv('FAS_SHOWCASE_FIXTURE_DB'));
$clock=fn()=>1000;
$rate=new FAS\Showcase\ShowcaseRateAdmission($root.'/quota.sqlite',[$root.'/public'],str_repeat('x',32),4,10,20,100,$clock);
$slots=new FAS\Showcase\ShowcaseConcurrency([$root.'/slot'],[$root.'/public'],fn()=>true);
$app=new FAS\Showcase\ShowcaseIntakeApplication(new ShowcaseRepository($db,fn()=>false,20,20000000),$rate,$slots,'test-v1','Synthetic permission fixture.','/submit',10,3,$clock,
 fn()=>($_SERVER['HTTP_X_ENABLED']??'yes')==='truthy' ? 1 : (($_SERVER['HTTP_X_ENABLED']??'yes')==='yes'),fn()=>session_id());
$response=$app->handle($_SERVER,$_POST,$_FILES,$_SESSION);
header('X-Fixture-Has-Pool: '.(isset($_SESSION['showcase_forms'])?'yes':'no'));
http_response_code($response['status']);foreach($response['headers'] as $name=>$value)header($name.': '.$value);echo $response['body'];
