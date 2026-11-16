<?php
declare(strict_types=1);
// Synthetic loopback router ONLY. Never copy to any hosted directory.
$fixtureKey=getenv('TEST_KEY');
if (PHP_SAPI!=='cli-server' || !is_string($fixtureKey) || !preg_match('/\A[a-f0-9]{64}\z/D',$fixtureKey) || !hash_equals($fixtureKey,$_SERVER['HTTP_X_FIXTURE']??'')) {http_response_code(404);exit;}
$d=getenv('TEST_DATA');
require $d.'/admin/auth.php'; // Exact app class, fixture dependency implementations.
foreach(['Photo','Submission','Repository','Request','Views','AdminAuthorization','AdminController'] as $name) require_once __DIR__.'/../src/showcase/Showcase'.$name.'.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/fixture-login'){
 session_regenerate_id(true);$_SESSION=['admin_id'=>7,'admin_logged_in'=>true,'admin_session_token'=>'synthetic-v1','showcase_test_csrf'=>bin2hex(random_bytes(32))];session_write_close();echo 'logged in';return;
}
if($path==='/fixture-logout'){$_SESSION=[];session_destroy();echo 'logged out';return;}
$auth=(new ReflectionClass(AdminAuth::class))->newInstanceWithoutConstructor();
$authorization=new FAS\Showcase\ShowcaseAdminAuthorization($auth);
$db=new PDO('sqlite:'.$d.'/store.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');
$repo=new FAS\Showcase\ShowcaseRepository($db,$authorization,50,10000000);
$request=new FAS\Showcase\ShowcaseRequest($repo,'test-v1','Synthetic consent.',fn()=>true);
$paths=['queue'=>'/admin/queue.php','detail'=>'/admin/detail.php','photo'=>'/admin/photo.php','moderate'=>'/admin/moderate.php'];
$controller=new FAS\Showcase\ShowcaseAdminController($repo,$request,[$authorization,'actor'],fn()=>($_SERVER['HTTP_X_DISABLED']??'')!=='1',$paths);
$endpoint=array_search($path,$paths,true);
$response=$controller->handle($endpoint===false?'missing':$endpoint,$_SERVER,$_GET,$_POST,$_SESSION['showcase_test_csrf']??'');
if(!session_write_close()){http_response_code(503);exit;}
http_response_code($response['status']);foreach($response['headers'] as $name=>$value)header($name.': '.$value);echo $response['body'];
