<?php
declare(strict_types=1);
// Contract double: no production auth/account bootstrap or database is loaded.
class AdminAuth {
 public $allowed=true; public $calls=0; public $error=false;
 public function isLoggedIn() { $this->calls++; if($this->error)throw new RuntimeException('private');return $this->allowed; }
}
require __DIR__.'/../src/showcase/ShowcaseAdminAuthorization.php';
use FAS\Showcase\ShowcaseAdminAuthorization;
$n=0;function ok($v){global $n;if(!$v)throw new RuntimeException('check failed');$n++;}
$a=new AdminAuth();$bridge=new ShowcaseAdminAuthorization($a);
$_SESSION=['admin_id'=>7];ok(!$bridge(7,'list',[])); // No active session.
$temp=sys_get_temp_dir().'/showcase-auth-'.bin2hex(random_bytes(8));mkdir($temp);
session_start(['use_cookies'=>false,'cache_limiter'=>'','save_path'=>$temp]);$_SESSION=['admin_id'=>7];
foreach(['list','review','moderate'] as $action)ok($bridge(7,$action,[])===true);
ok($a->calls===3);ok(!$bridge(8,'list',[]));ok(!$bridge(7,'delete',[]));ok(!$bridge(0,'review',[]));
foreach([0,-1,true,7.0,'07','7 ','7.0','999999999999999999999999',null,[]] as $bad){$_SESSION['admin_id']=$bad;ok(!$bridge(7,'list',[]));}
$_SESSION['admin_id']='7';ok($bridge->actor()===7);
foreach([false,1,'true',null] as $bad){$a->allowed=$bad;ok(!$bridge(7,'review',[]));}
$a->allowed=true;ok($bridge(7,'moderate',[]));$a->allowed=false;ok(!$bridge(7,'moderate',[]));
$a->allowed=true;$a->error=true;ok(!$bridge(7,'review',[]));$a->error=false;
$_SESSION['admin_id']=8;ok(!$bridge(7,'review',[]));ok($bridge(8,'review',[]));
$sid=session_id();session_write_close();unlink($temp.'/sess_'.$sid);rmdir($temp);ok(!$bridge(8,'review',[]));
echo "Admin bridge: $n assertions passed (contract double, not full app auth).\n";
