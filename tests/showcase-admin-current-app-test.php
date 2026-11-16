<?php
declare(strict_types=1);
// Exact current app class, synthetic account lookup/token dependencies only.
$source=file_get_contents(__DIR__.'/fixtures/admin-auth-main.php');
if(sha1('blob '.strlen($source).chr(0).$source)!=='f387c7c6657d247e0709951b17f5da45f160aa74')throw new RuntimeException('Fixture source mismatch');
$d=sys_get_temp_dir().'/showcase-current-auth-'.bin2hex(random_bytes(8));
foreach(['','/admin','/includes','/src','/src/utils','/sessions'] as $p)mkdir($d.$p);
file_put_contents($d.'/admin/auth.php',$source);
file_put_contents($d.'/includes/security.php','<?php // No live security initialization.');
file_put_contents($d.'/includes/admin-accounts.php', '<?php function fas_admin_account($db,$id) {return $GLOBALS["fixture_accounts"][$id] ?? null;} function fas_admin_session_token($admin) {return $admin["fixture_token"];}');
file_put_contents($d.'/src/utils/Timezone.php','<?php namespace FAS\\Utils; class Timezone {static function apply(){}}');
ini_set('session.save_path',$d.'/sessions');ini_set('session.use_cookies','0');ini_set('session.cache_limiter','');
try {
 require $d.'/admin/auth.php';
 require __DIR__.'/../src/showcase/ShowcaseAdminAuthorization.php';
 // Bypass constructor's app migrations; only isLoggedIn method is under integration test.
 $auth=(new ReflectionClass(AdminAuth::class))->newInstanceWithoutConstructor();
 $bridge=new FAS\Showcase\ShowcaseAdminAuthorization($auth);
 $n=0;$ok=function($v)use(&$n){if(!$v)throw new RuntimeException('check failed');$n++;};
 $account=['is_active'=>1,'role'=>'admin','fixture_token'=>'synthetic-v1','username'=>'Fixture','email'=>'fixture@example.invalid'];
 $GLOBALS['fixture_accounts']=[7=>$account];
 $_SESSION=['admin_id'=>7,'admin_logged_in'=>true,'admin_session_token'=>'synthetic-v1'];
 $ok($bridge->actor()===7);$ok($bridge(7,'list',[]));$ok(!$bridge(8,'list',[]));
 $GLOBALS['fixture_accounts'][7]['is_active']=0;$ok(!$bridge(7,'review',[]));
 $GLOBALS['fixture_accounts'][7]=$account;$GLOBALS['fixture_accounts'][7]['role']='staff';$ok(!$bridge(7,'moderate',[]));
 $GLOBALS['fixture_accounts'][7]=$account;$GLOBALS['fixture_accounts'][7]['fixture_token']='synthetic-v2';$ok(!$bridge(7,'moderate',[]));
 $_SESSION['admin_session_token']='synthetic-v2';$ok($bridge(7,'review',[]));
 unset($GLOBALS['fixture_accounts'][7]);$ok(!$bridge(7,'list',[]));
 $GLOBALS['fixture_accounts'][7]=$account;$_SESSION['admin_session_token']='synthetic-v1';$_SESSION['admin_logged_in']=1;$ok(!$bridge(7,'review',[]));
 echo "Current app admin bridge: $n assertions passed; exact auth class, synthetic dependencies.\n";
}finally{
 if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 foreach(glob($d.'/sessions/*') as $file)unlink($file);
 foreach(['/admin/auth.php','/includes/security.php','/includes/admin-accounts.php','/src/utils/Timezone.php'] as $file)unlink($d.$file);
 foreach(['/sessions','/admin','/includes','/src/utils','/src',''] as $p)rmdir($d.$p);
}
