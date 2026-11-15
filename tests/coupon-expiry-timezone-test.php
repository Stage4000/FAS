<?php
/** Synthetic UTC-expiry boundaries. No server, external data, or real clock mutation. */
declare(strict_types=1);
namespace FAS\Models {
    function date($format) { return \date($format, $GLOBALS['coupon_fixture_now']); }
    function gmdate($format) { return \gmdate($format, $GLOBALS['coupon_fixture_now']); }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $root = $argv[1] ?? dirname(__DIR__);
    require_once $root . '/src/utils/Timezone.php';
    require_once $root . '/src/models/Coupon.php';
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE coupons(code TEXT PRIMARY KEY,description TEXT,discount_type TEXT,discount_value REAL,minimum_purchase REAL,max_uses INTEGER,expires_at TEXT,is_active INTEGER,times_used INTEGER DEFAULT 0)');
    $model = new \FAS\Models\Coupon($db);
    $model->create(['code'=>'SYNTH_EXPIRY','description'=>'In-memory fixture','discount_type'=>'percentage','discount_value'=>10,'minimum_purchase'=>0,'is_active'=>1]);
    $update=$db->prepare('UPDATE coupons SET expires_at=?');
    $zones=['UTC','America/Chicago','America/Los_Angeles','America/New_York','Europe/London','Europe/Berlin','Asia/Tokyo','Asia/Kolkata','Pacific/Kiritimati'];
    $instants=['2026-01-15T18:05:00Z','2026-07-15T18:05:00Z','2026-03-08T07:59:59Z','2026-03-08T08:00:01Z','2026-11-01T06:59:59Z','2026-11-01T07:00:01Z'];
    $checks=0;$failures=[];$failed=0;
    foreach($instants as $instant) {
        $GLOBALS['coupon_fixture_now']=(new DateTimeImmutable($instant))->getTimestamp();
        foreach($zones as $siteZone) {
            date_default_timezone_set($siteZone);
            foreach($zones as $userZone) {
                $_COOKIE['fas_user_timezone']=$userZone;
                foreach([-3601,-1,0,1,3601] as $delta) {
                    $userInput=(new DateTimeImmutable('@'.($GLOBALS['coupon_fixture_now']+$delta)))->setTimezone(new DateTimeZone($userZone))->format('Y-m-d\TH:i:s');
                    $stored=\FAS\Utils\Timezone::fromUserInput($userInput);
                    if($stored===null)throw new RuntimeException('Synthetic input conversion unexpectedly failed');
                    // Use the actual UTC conversion as the oracle, preserving current
                    // handling of ambiguous wall times without introducing a new policy.
                    $expected=(new DateTimeImmutable($stored,new DateTimeZone('UTC')))->getTimestamp()>$GLOBALS['coupon_fixture_now'];
                    $update->execute([$stored]);$actual=$model->validateCoupon('SYNTH_EXPIRY',80)['valid'];$checks++;
                    if($actual!==$expected){$failed++;if(count($failures)<12)$failures[]=['now'=>$instant,'site'=>$siteZone,'user'=>$userZone,'input'=>$userInput,'stored_utc'=>$stored,'expected'=>$expected,'actual'=>$actual];}
                }
            }
            $update->execute([null]);$checks++;
            if($model->validateCoupon('SYNTH_EXPIRY',80)['valid']!==true){$failed++;$failures[]=['null_expiry'=>$siteZone];}
        }
    }
    echo json_encode(['checks'=>$checks,'failed'=>$failed,'failures'=>$failures,'scope'=>'In-memory actual model and actual UTC input conversion, fixed namespaced test clock; includes exact expiry, before/after, null expiry, different site/user zones and DST transitions. No production data or network.'],JSON_PRETTY_PRINT)."\n";
    exit($failed?1:0);
}
