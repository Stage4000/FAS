<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/models/Coupon.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE coupons(code TEXT PRIMARY KEY,is_active INTEGER,expires_at TEXT,
    max_uses INTEGER,times_used INTEGER,minimum_purchase REAL,discount_type TEXT,discount_value REAL)');
$db->exec("INSERT INTO coupons VALUES('FIXTURE',1,NULL,NULL,0,0,'percentage',10)");
$model=new FAS\Models\Coupon($db);
$checks=0; $failureCount=0; $failures=[];
function couponBoundaryCheck(bool $ok,string $message): void {
    global $checks,$failureCount,$failures; $checks++;
    if(!$ok){$failureCount++;if(count($failures)<12)$failures[]=$message;}
}
$invalid=[null,true,false,[],[1],new stdClass(),'','abc','1x',"1\0",'NaN','INF','1e9999',INF,-INF,NAN,-1,-0.01,'-1'];
for($i=0;$i<128;$i++)$invalid[]='100'.chr($i).'x';
$before=$db->query('SELECT * FROM coupons')->fetchAll(PDO::FETCH_ASSOC);
foreach($invalid as $input){
    try{$result=$model->validateCoupon('FIXTURE',$input);couponBoundaryCheck($result['valid']===false,'malformed subtotal rejected');}
    catch(Throwable $e){couponBoundaryCheck(false,'malformed subtotal threw '.get_class($e));}
}
// No database work should be needed to reject malformed input.
$noDb=new FAS\Models\Coupon(null);
foreach($invalid as $input){
    try{$result=$noDb->validateCoupon('FIXTURE',$input);couponBoundaryCheck($result['valid']===false,'rejected before DB');}
    catch(Throwable $e){couponBoundaryCheck(false,'malformed subtotal reached DB');}
}
// Reproducible valid-value oracle: currency cents and rate basis points.
$seed=20261030;
for($i=0;$i<400;$i++){
    $seed=($seed*48271)%2147483647;$cents=$seed%1000001;
    $seed=($seed*48271)%2147483647;$value=1+$seed%100;
    foreach(['percentage','fixed'] as $type){
        $db->prepare('UPDATE coupons SET discount_type=?,discount_value=?')->execute([$type,$value]);
        $expected=$type==='percentage'?($cents*$value)/10000:min($value,$cents/100);
        foreach([$cents/100,number_format($cents/100,2,'.','')] as $subtotal){
            $r=$model->validateCoupon('FIXTURE',$subtotal);
            couponBoundaryCheck($r['valid']===true,'valid numeric amount accepted');
            couponBoundaryCheck(abs($r['discount']-$expected)<0.0000001,'discount arithmetic preserved');
        }
    }
}
foreach([0,'0','1e2',' 100 ',100] as $input)couponBoundaryCheck($model->validateCoupon('FIXTURE',$input)['valid']===true,'existing numeric forms preserved');
$db->exec("UPDATE coupons SET discount_type='percentage',discount_value=100");
foreach([PHP_FLOAT_MAX,(string)PHP_FLOAT_MAX,'1e308'] as $input){
    $r=$model->validateCoupon('FIXTURE',$input);
    couponBoundaryCheck($r['valid']===false,'overflowing discount rejected');
}
$db->exec("UPDATE coupons SET discount_type='percentage',discount_value=10,minimum_purchase=50");
couponBoundaryCheck($model->validateCoupon('FIXTURE',49.99)['valid']===false,'minimum purchase preserved');
couponBoundaryCheck($model->validateCoupon('FIXTURE',50)['valid']===true,'minimum equality preserved');
$db->exec('UPDATE coupons SET max_uses=1,times_used=1');
couponBoundaryCheck($model->validateCoupon('FIXTURE',100)['valid']===false,'exhausted coupon rejected');
$db->exec('UPDATE coupons SET times_used=0,is_active=0');
couponBoundaryCheck($model->validateCoupon('FIXTURE',100)['valid']===false,'inactive coupon rejected');
$db->exec("UPDATE coupons SET is_active=1,expires_at='2000-01-01 00:00:00'");
couponBoundaryCheck($model->validateCoupon('FIXTURE',100)['valid']===false,'expired coupon rejected');
$db->exec("UPDATE coupons SET expires_at=NULL,max_uses=NULL,minimum_purchase=0,discount_type='percentage',discount_value=10");
couponBoundaryCheck($db->query('SELECT * FROM coupons')->fetchAll(PDO::FETCH_ASSOC)===$before,'validation never increments usage or mutates coupon');
echo json_encode(['seed'=>20261030,'invalidInputs'=>count($invalid),'validScenarios'=>1600,'checks'=>$checks,'failureCount'=>$failureCount,'failures'=>$failures],JSON_PRETTY_PRINT)."\n";
exit($failureCount?1:0);
