<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingLabelCancellations.php';
use FAS\Shipping\{ShippingLabelOperations,ShippingLabelCancellations};
$checks=0;
function verify(bool $ok,string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function refuses(callable $call,string $message): void {
    try { $call(); } catch (Throwable $e) { verify(true,$message); return; }
    throw new RuntimeException($message);
}
$path=tempnam(sys_get_temp_dir(),'fas-cancel-review-');
try {
    $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA foreign_keys=ON');
    ShippingLabelOperations::install($db);
    ShippingLabelOperations::install($db);
    $orders=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $orders->exec("CREATE TABLE admin_users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
        INSERT INTO admin_users VALUES (1,'admin',1),(2,'viewer',1),(3,'admin',0)");
    $now=time();
    foreach ([1=>'review',2=>'submitted',3=>'submitted',4=>'refund_pending',5=>'reserved',6=>'cancelled',7=>'review',8=>'review',9=>'review'] as $id=>$state) {
        $provider=in_array($id,[4,7],true)?'usps':'ups';
        $tracking=$provider==='usps'?'940011189922384719999'.$id:'1Z123456789012345'.$id;
        $db->prepare("INSERT INTO shipping_label_operations
            (id,order_id,package_index,provider,service_code,expected_packages,fingerprint,idempotency_key,state,tracking_number,operator_id,created_at,updated_at)
            VALUES (?,?,0,?,'03',1,'fixture',?,'ready',?,1,?,?)")
            ->execute([$id,$id,$provider,'fixture-'.$id,$tracking,$now,$now]);
        $db->prepare('INSERT INTO shipping_label_cancellations(operation_id,state,operator_id,created_at,submitted_at,updated_at) VALUES (?,?,1,?,?,?)')
            ->execute([$id,$state,$now,$id===2?$now:$now-901,$now]);
    }
    $service=new ShippingLabelCancellations($db);
    $resolve=static function(int $id,string $state='review',string $outcome='cancelled',int $actor=1,
        bool $verified=true,?string $tracking=null,string $ref='CASE-123') use ($service,$orders): void {
        $service->reconcile($orders,$id,0,$actor,$state,$outcome,
            $tracking ?? $service->find($id,0)['tracking_number'],$ref,$verified);
    };
    refuses(fn()=>$resolve(1,actor:2),'Viewer denied');
    refuses(fn()=>$resolve(1,actor:3),'Inactive admin denied');
    refuses(fn()=>$resolve(1,verified:false),'Evidence attestation required');
    refuses(fn()=>$resolve(1,tracking:'1Z1234567890123459'),'Wrong shipment denied');
    refuses(fn()=>$resolve(1,ref:"CASE\nsecret"),'Reference cannot carry control characters');
    refuses(fn()=>$resolve(1,ref:str_repeat('A',101)),'Oversized evidence rejected');
    refuses(fn()=>$resolve(1,outcome:'refund_pending'),'UPS cannot be reconciled as a USPS refund request');
    refuses(fn()=>$resolve(2,state:'submitted'),'Active submission protected');
    refuses(fn()=>$resolve(5,state:'reserved'),'Unsent reservation protected');
    refuses(fn()=>$resolve(6,state:'cancelled'),'Completed state protected');
    verify((int)$db->query('SELECT COUNT(*) FROM shipping_cancellation_resolutions')->fetchColumn()===0,'Rejections leave no history');
    $resolve(1);
    $history=$service->history(1);
    verify($service->find(1,0)['state']==='cancelled' && count($history)===1
        && $history[0]['previous_state']==='review' && (int)$history[0]['actor_id']===1
        && $history[0]['evidence_reference']==='CASE-123','Outcome and administrator evidence saved');
    $other=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $otherService=new ShippingLabelCancellations($other);
    refuses(fn()=>$otherService->reconcile($orders,1,0,1,'review','cancelled','1Z1234567890123451','OTHER',true),'Competing connection cannot overwrite');
    verify(count($service->history(1))===1,'Repeated review does not duplicate history');
    $resolve(3,state:'submitted');
    verify($service->find(3,0)['state']==='cancelled','Stalled submission may be reconciled');
    $resolve(7,outcome:'refund_pending');
    verify($service->find(7,0)['state']==='refund_pending','USPS accepted request remains pending');
    refuses(fn()=>$resolve(7),'Stale earlier form cannot overwrite pending outcome');
    $resolve(7,state:'refund_pending',ref:'CASE-CLOSED');
    verify(count($service->history(7))===2 && $service->find(7,0)['state']==='cancelled','Pending request can later be confirmed with both history entries');
    verify($service->history(7)[0]['previous_reference']==='CASE-123','Original refund request reference survives reconciliation');
    $resolve(4,state:'refund_pending');
    verify($service->find(4,0)['state']==='cancelled','Existing carrier refund request can be reconciled');
    $db->exec("CREATE TRIGGER fail_review_update BEFORE UPDATE ON shipping_label_cancellations
        WHEN NEW.operation_id=8 BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
    refuses(fn()=>$resolve(8),'Failed status write rejects reconciliation');
    verify($service->find(8,0)['state']==='review' && $service->history(8)===[],'Failed update rolls back audit insertion');
    $db->exec('DROP TRIGGER fail_review_update');
    $db->exec('ALTER TABLE shipping_cancellation_resolutions RENAME TO hidden_resolutions');
    refuses(fn()=>$resolve(9),'Missing migration rejects write');
    verify($service->find(9,0)['state']==='review','Missing history table preserves outcome');
    $db->exec('ALTER TABLE hidden_resolutions RENAME TO shipping_cancellation_resolutions');
    verify(count($service->attention())===3,'Confirmed cancellations leave follow-up queue; unresolved requests remain');
    echo "PASS $checks cancellation review assertions. No carrier or mail calls.\n";
} finally {
    unset($service,$otherService,$other,$db,$resolve);
    @unlink($path);
}
