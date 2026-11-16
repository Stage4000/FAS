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

foreach (['SessionForms','IntakeController'] as $name) require_once __DIR__.'/../src/showcase/Showcase'.$name.'.php';
$bucket=$_SESSION['forms'] ?? [];
$permission=isset($_SERVER['HTTP_X_CONSENT_TEXT']) ? 'Changed synthetic wording' : 'Synthetic permission fixture.';
$repo=new ShowcaseRepository($db,fn()=>false,20,20000000);
$request=new ShowcaseRequest($repo,isset($_SERVER['HTTP_X_CONSENT_VERSION']) ? 'test-v2' : 'test-v1',$permission,fn()=>true);
$forms=new FAS\Showcase\ShowcaseSessionForms($bucket,10,3,fn()=>(int)($_SERVER['HTTP_X_TIME'] ?? 1000));
$controller=new FAS\Showcase\ShowcaseIntakeController($request,$forms,'/submit',
 static function() {if (isset($_SERVER['HTTP_X_THROW'])) throw new RuntimeException('secret-internal-path');return ($_SERVER['HTTP_X_ENABLED'] ?? 'yes')==='truthy' ? 1 : (($_SERVER['HTTP_X_ENABLED'] ?? 'yes')==='yes');},
 fn()=>($_SERVER['HTTP_X_ADMIT'] ?? 'yes')==='truthy' ? 1 : (($_SERVER['HTTP_X_ADMIT'] ?? 'yes')==='yes'));
$response=$controller->handle($_SERVER,$_POST,$_FILES);
if (($_SERVER['REQUEST_METHOD'] ?? '')==='GET' && $response['status']===200) $_SESSION['forms']=$bucket;
header('X-Fixture-Has-Pool: '.(isset($_SESSION['forms']) ? 'yes' : 'no'));
header('X-Fixture-Forms: '.count($_SESSION['forms'] ?? []));http_response_code($response['status']); foreach ($response['headers'] as $name=>$value) header($name.': '.$value);
echo $response['body'];
