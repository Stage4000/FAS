<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/utils/Timezone.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingOrder.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingLabelCancellations.php';
require_once __DIR__.'/../src/shipping/ShippingTracking.php';
require_once __DIR__.'/../src/shipping/ShippingLabelService.php';

use FAS\Config\Database;
use FAS\Utils\CSRF;
use FAS\Utils\Timezone;
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingOrder,ShippingLabelOperations,ShippingLabelCancellations,ShippingTracking,ShippingLabelService};

$auth=new AdminAuth();
$admin=$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function shippingLabelHtml($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$orderId=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$orderId) { http_response_code(400); exit('Choose an order.'); }
$db=Database::getInstance()->getConnection();
$stmt=$db->prepare('SELECT * FROM orders WHERE id=?');
$stmt->execute([$orderId]);
$order=$stmt->fetch(PDO::FETCH_ASSOC);
$shipping=$order ? ShippingOrder::find($db,(int)$orderId) : null;
if (!$order || !$shipping || !in_array($shipping['provider'],['usps','ups'],true)) {
    http_response_code(404); exit('Direct carrier order not found.');
}
$error=''; $notice=$_SESSION['shipping_label_notice'] ?? '';
unset($_SESSION['shipping_label_notice']);
$config=null; $cache=null; $operations=null; $cancellations=null; $trackingStore=null;
$handoffStorage=false;
$reprintStorage=false;
$resolutionStorage=false;
try {
    $config=ShippingConfig::load();
    if ($config['cache_path']!=='') {
        $cache=new ShippingCache($config['cache_path']);
        $operations=new ShippingLabelOperations($cache->database());
        $handoffStorage=(bool)$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_handoffs'")->fetchColumn();
        $reprintStorage=(bool)$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_reprints'")->fetchColumn();
        $resolutionStorage=(bool)$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_resolutions'")->fetchColumn();
        $cancellations=new ShippingLabelCancellations($cache->database());
        $trackingTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_tracking'")->fetchColumn();
        if ($trackingTable) $trackingStore=new ShippingTracking($cache->database());
    }
} catch (Throwable $e) { $error='Private shipping storage is unavailable. Ask an administrator to run the shipping health check.'; }
$provider=$shipping['provider'];
$carrier=$config['carriers'][$provider] ?? [];
$purchaseEnabled=$operations && $handoffStorage && $carrier
    && ($carrier['enabled'] ?? false)===true
    && ($carrier['label_purchasing_enabled'] ?? false)===true
    && !empty($carrier['client_id']) && !empty($carrier['client_secret'])
    && (($carrier['environment'] ?? '')==='sandbox' || ($carrier['production_verified'] ?? false)===true)
    && !empty($config['shipper_name'])
    && ($provider==='usps' || (!empty($config['shipper_phone']) && !empty($carrier['account_number'])));
$reprintEnabled=$provider==='usps' && $operations && $reprintStorage && $carrier
    && ($carrier['enabled'] ?? false)===true && ($carrier['label_reprint_enabled'] ?? false)===true
    && !empty($carrier['client_id']) && !empty($carrier['client_secret'])
    && (($carrier['environment'] ?? '')==='sandbox' || ($carrier['production_verified'] ?? false)===true);
$savedPackages=is_array($shipping['packages'] ?? null) ? $shipping['packages'] : [];
$destination=json_decode((string)($order['shipping_address'] ?? ''),true);
$destination=is_array($destination) ? $destination : [];
$origin=is_array($shipping['origin'] ?? null) ? $shipping['origin'] : [];
$scopes=$provider==='usps' ? count($savedPackages) : 1;
if ($scopes<1 || $scopes>10) {
    $purchaseEnabled=false;
    $reprintEnabled=false;
}
$prices=[];
for ($index=0;$index<$scopes;$index++) {
    $prices[$index]=$provider==='usps'
        ? ($shipping['fulfillment_options'][$index]['quoted_cents'] ?? null)
        : ($shipping['carrier_quote_cents'] ?? $shipping['quoted_cents'] ?? null);
}
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    fas_security_body();
    try {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('Your session expired. Refresh the page and try again.');
        }
        $index=filter_var($_POST['package_index'] ?? null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0,'max_range'=>9]]);
        $action=$_POST['action'] ?? 'purchase';
        if ($action==='reprint') {
            if ($index===false || !array_key_exists($index,$prices)
                || !$reprintEnabled || ($_POST['confirm_reprint'] ?? '')!=='yes') {
                throw new InvalidArgumentException('Review the USPS parcel and confirm the reprint request.');
            }
            $operation=$operations->find((int)$orderId,$index);
            if (!$operation || !ShippingLabelOperations::reprintEligible($operation)
                || $operations->reprint((int)$orderId,$index)) {
                throw new DomainException('USPS reprint is unavailable or already attempted.');
            }
            if (!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password'] ?? null)) {
                throw new InvalidArgumentException($auth->lastError ?: 'Password could not be verified.');
            }
            $result=(new ShippingLabelService($db,$cache,$config))->reprint((int)$orderId,$index,(int)$admin['id']);
            $_SESSION['shipping_label_notice']=$result['state']==='ready'
                ? 'USPS label recovered. Download and verify it before shipping.'
                : 'USPS reprint needs reconciliation. Do not send another request.';
            header('Location: shipping-label.php?id='.(int)$orderId,true,303); exit;
        }
        if ($action!=='purchase') throw new InvalidArgumentException('Unknown shipment action.');
        if ($index===false || !array_key_exists($index,$prices)
            || ($_POST['confirm_charge'] ?? '')!=='yes') {
            throw new InvalidArgumentException('Select a parcel and confirm the carrier charge.');
        }
        if (!$purchaseEnabled || !is_int($prices[$index])) {
            throw new RuntimeException('Carrier label purchasing is not ready.');
        }
        $confirmed=filter_var($_POST['confirmed_cents'] ?? null,FILTER_VALIDATE_INT);
        if ($confirmed===false || $confirmed!==$prices[$index]) {
            throw new InvalidArgumentException('The saved shipping price changed. Review this order again.');
        }
        if (!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password'] ?? null)) {
            throw new InvalidArgumentException($auth->lastError ?: 'Password could not be verified.');
        }
        $service=new ShippingLabelService($db,$cache,$config);
        $result=$service->purchase((int)$orderId,$index,(int)$admin['id'],$confirmed,
            is_string($_POST['mailing_date'] ?? null) ? $_POST['mailing_date'] : '');
        $_SESSION['shipping_label_notice']=$result['state']==='ready'
            ? 'Carrier label confirmed. Download and verify every package label before shipping.'
            : 'This shipment needs reconciliation. Do not submit another purchase.';
        header('Location: shipping-label.php?id='.(int)$orderId,true,303); exit;
    } catch (InvalidArgumentException|DomainException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) {
        $index=isset($index) && is_int($index) ? $index : 0;
        $record=$operations ? $operations->find((int)$orderId,$index) : null;
        $error=($action ?? '')==='reprint'
            ? 'USPS reprint needs review. Check the carrier account; do not send another request.'
            : ($record && in_array($record['state'],['submitted','review','ready'],true)
            ? 'The carrier outcome needs review. Do not purchase this shipment again.'
            : 'The label could not be prepared. Check the order and carrier account settings.');
    }
}
$rows=[];
$reprintRows=[];
$resolutionRows=[];
$cancelRows=[];
$trackingRows=[];
$handoffRows=[];
for ($index=0;$index<$scopes;$index++) {
    $rows[$index]=$operations ? $operations->find((int)$orderId,$index) : null;
    $reprintRows[$index]=$operations && $reprintStorage ? $operations->reprint((int)$orderId,$index) : null;
    $resolutionRows[$index]=$operations && $resolutionStorage ? $operations->resolution((int)$orderId,$index) : null;
    $handoffRows[$index]=$operations && $handoffStorage ? $operations->handoffs((int)$orderId,$index) : [];
    $cancelRows[$index]=$cancellations ? $cancellations->find((int)$orderId,$index) : null;
    $trackingRows[$index]=[];
    if ($rows[$index] && $rows[$index]['state']==='ready' && $cache) {
        $stmt=$cache->database()->prepare('SELECT p.tracking_number FROM shipping_label_packages p
            JOIN shipping_label_operations o ON o.id=p.operation_id
            WHERE o.order_id=? AND o.package_index=? ORDER BY p.shipment_package_index');
        $stmt->execute([(int)$orderId,$index]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
            $trackingRows[$index][]=['number'=>$number,
                'status'=>$trackingStore ? $trackingStore->find($number) : null];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shipping Labels - Flip and Strip Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="container-fluid px-3 px-lg-4 pb-5" style="max-width:1200px">
    <div class="admin-hero d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div><h1 class="mb-1"><i class="fas fa-box me-2"></i>Shipping Labels</h1>
            <p class="mb-0 opacity-75">Order <?php echo shippingLabelHtml($order['order_number']); ?></p></div>
        <a class="btn btn-outline-secondary" href="order-details.php?id=<?php echo (int)$orderId; ?>">Back to order</a>
    </div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?php echo shippingLabelHtml($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo shippingLabelHtml($error); ?></div><?php endif; ?>
    <?php if (!$purchaseEnabled && !$reprintEnabled): ?>
        <div class="alert alert-warning" role="status">Carrier label purchasing is unavailable for this order. Review the carrier account, shipper details, and private storage before proceeding.</div>
    <?php elseif (!$purchaseEnabled): ?>
        <div class="alert alert-info" role="status">New label purchases are disabled. Eligible uncertain USPS labels can be recovered below.</div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h2 class="h5">Saved shipping choice</h2>
        <div class="row g-3">
            <div class="col-sm-6"><span class="text-muted d-block small">Service</span><strong><?php echo shippingLabelHtml($shipping['courier_name'].' — '.$shipping['service_name']); ?></strong></div>
            <div class="col-sm-6"><span class="text-muted d-block small">Customer shipping quote</span><strong>$<?php echo number_format((int)$shipping['quoted_cents']/100,2); ?></strong></div>
            <div class="col-sm-6"><span class="text-muted d-block small">Carrier estimate</span><strong>$<?php echo number_format((int)($shipping['carrier_quote_cents'] ?? $shipping['quoted_cents'])/100,2); ?></strong></div>
            <div class="col-sm-6"><span class="text-muted d-block small">Ship from</span><strong><?php echo shippingLabelHtml(implode(', ',array_filter([$origin['address1'] ?? '',$origin['address2'] ?? '',$origin['city'] ?? '',$origin['state'] ?? '',$origin['zip'] ?? ''],static fn($part)=>is_string($part) && $part!==''))); ?></strong></div>
            <div class="col-sm-6"><span class="text-muted d-block small">Ship to</span><strong><?php echo shippingLabelHtml(implode(', ',array_filter([$destination['address1'] ?? '',$destination['address2'] ?? '',$destination['city'] ?? '',$destination['state'] ?? '',$destination['zip'] ?? ''],static fn($part)=>is_string($part) && $part!==''))); ?></strong></div>
        </div>
        <p class="text-muted small mt-3 mb-0">The carrier’s final charge may differ from the customer’s shipping quote. Check the parcel and address before purchasing.</p>
    </div></div>
    <div class="row g-3">
    <?php foreach ($rows as $index=>$operation):
        $state=$operation['state'] ?? 'not prepared';
        $cancelState=$cancelRows[$index]['state'] ?? null;
        $pieces=$provider==='ups' ? count($savedPackages) : 1;
        $price=$prices[$index] ?? null;
        $canPurchase=$purchaseEnabled && !$operation && is_int($price)
            && $order['payment_status']==='completed' && $order['order_status']==='processing';
        if ($purchaseEnabled && $operation && $state==='reserved') {
            $canPurchase=is_int($price) && ((int)$operation['operator_id']===(int)$admin['id']
                || ShippingLabelOperations::handoffEligible($operation,(int)$admin['id']));
        }
    ?>
        <div class="col-12 col-lg-6"><section class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h2 class="h5 mb-1"><?php echo $provider==='usps'?'Parcel '.($index+1):'UPS shipment'; ?></h2>
                <span class="badge <?php echo $state==='ready'?'text-bg-success':($state==='review'||$state==='submitted'?'text-bg-warning':'text-bg-secondary'); ?>"><?php echo shippingLabelHtml(ucfirst($state)); ?></span>
            </div>
            <p class="text-muted small mb-2"><?php echo (int)$pieces; ?> <?php echo $pieces===1?'package':'packages'; ?><?php if (is_int($price)): ?> · Quoted $<?php echo number_format($price/100,2); ?><?php endif; ?></p>
            <ul class="small text-muted ps-3 mb-3">
            <?php foreach ($savedPackages as $pieceIndex=>$parcel): ?>
                <?php if ($provider==='usps' && $pieceIndex!==$index) continue; ?>
                <li>Package <?php echo (int)$pieceIndex+1; ?>: <?php echo shippingLabelHtml($parcel['weight'] ?? '?'); ?> lb, <?php echo shippingLabelHtml($parcel['length'] ?? '?'); ?> × <?php echo shippingLabelHtml($parcel['width'] ?? '?'); ?> × <?php echo shippingLabelHtml($parcel['height'] ?? '?'); ?> in</li>
            <?php endforeach; ?>
            </ul>
            <?php if ($state==='ready'): ?>
                <p class="mb-2">Tracking: <strong><?php echo shippingLabelHtml($operation['tracking_number']); ?></strong></p>
                <?php if ($operation['billed_cents']!==null): ?><p class="mb-3">Carrier charged $<?php echo number_format((int)$operation['billed_cents']/100,2); ?></p><?php endif; ?>
                <?php if ($resolutionRows[$index]): ?><p class="small text-muted">Original label confirmed from carrier evidence. <a href="shipping-label-reconcile.php?id=<?php echo (int)$orderId; ?>&amp;package=<?php echo $index; ?>">View review record</a>.</p><?php endif; ?>
                <?php if ($cancelState): ?><p class="alert alert-warning py-2">Cancellation: <?php echo shippingLabelHtml(str_replace('_',' ',$cancelState)); ?>. This label is unavailable after a carrier cancellation request.</p><?php endif; ?>
                <?php if (!$cancelState && $trackingRows[$index]): ?>
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6 mb-2">Carrier tracking</h3>
                        <?php foreach ($trackingRows[$index] as $pieceIndex=>$item):
                            $status=$item['status']; ?>
                            <div class="<?php echo $pieceIndex ? 'border-top pt-2 mt-2' : ''; ?>">
                                <div class="small text-muted">Package <?php echo $pieceIndex+1; ?> · <span class="text-break"><?php echo shippingLabelHtml($item['number']); ?></span></div>
                                <?php if ($status && $status['status_text']): ?>
                                    <strong><?php echo shippingLabelHtml($status['status_text']); ?></strong>
                                    <span class="text-muted small d-block">Checked <?php echo shippingLabelHtml(date('M j, Y g:i A',(int)$status['checked_at'])); ?><?php echo $status['last_result']==='error' ? ' · Latest check unavailable' : ''; ?></span>
                                <?php else: ?><span class="text-muted small">Status has not been checked yet.</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                <?php if (!$cancelState): ?>
                <?php for($piece=0;$piece<$pieces;$piece++): ?>
                    <a class="btn btn-outline-primary btn-sm" href="shipping-label-download.php?id=<?php echo (int)$orderId; ?>&amp;package=<?php echo $index; ?>&amp;piece=<?php echo $piece; ?>">Download label <?php echo $piece+1; ?></a>
                <?php endfor; ?>
                <?php endif; ?>
                    <a class="btn btn-outline-secondary btn-sm" href="shipping-label-cancel.php?id=<?php echo (int)$orderId; ?>&amp;package=<?php echo $index; ?>">Review cancellation</a>
                </div>
            <?php elseif ($state==='review'||$state==='submitted'): ?>
                <p class="mb-2">The carrier outcome is uncertain. Reconcile this shipment before any further purchase.</p>
                <?php if ($resolutionStorage): ?><a class="btn btn-outline-secondary btn-sm mb-3" href="shipping-label-reconcile.php?id=<?php echo (int)$orderId; ?>&amp;package=<?php echo $index; ?>">Record verified carrier label</a><?php endif; ?>
                <?php if ($reprintRows[$index]): ?>
                    <p class="small text-muted mb-0">USPS reprint: <?php echo shippingLabelHtml(str_replace('_',' ',$reprintRows[$index]['state'])); ?>. Check the carrier account; this request cannot be sent again automatically.</p>
                <?php elseif ($reprintEnabled && !$cancelRows[$index]
                    && $operation['carrier_environment']===$carrier['environment']
                    && ShippingLabelOperations::reprintEligible($operation)
                    && $order['payment_status']==='completed' && $order['order_status']==='processing'): ?>
                    <form method="post" class="border-top mt-3 pt-3">
                        <?php echo CSRF::tokenField(); ?>
                        <input type="hidden" name="action" value="reprint">
                        <input type="hidden" name="package_index" value="<?php echo $index; ?>">
                        <p class="small text-muted">Request the original USPS label image using its saved purchase key. This does not submit another label purchase. The request is recorded before it is sent.</p>
                        <div class="mb-3"><label class="form-label" for="reprint-password-<?php echo $index; ?>">Current password</label>
                            <input class="form-control" type="password" id="reprint-password-<?php echo $index; ?>" name="password" autocomplete="current-password" required></div>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="reprint-confirm-<?php echo $index; ?>" name="confirm_reprint" value="yes" required>
                            <label class="form-check-label" for="reprint-confirm-<?php echo $index; ?>">I checked the original purchase and authorize one USPS reprint request.</label></div>
                        <button class="btn btn-outline-primary" type="submit">Request original USPS label</button>
                    </form>
                <?php endif; ?>
            <?php elseif ($state==='reserved' && !$canPurchase && (int)$operation['operator_id']!==(int)$admin['id']): ?>
                <p class="text-muted mb-0">Another administrator is preparing this shipment. If it remains reserved, you can take over after <?php echo Timezone::timestampElement(new DateTimeImmutable('@'.((int)$operation['updated_at']+ShippingLabelOperations::HANDOFF_DELAY_SECONDS))); ?>. No carrier purchase has been sent.</p>
            <?php elseif ($canPurchase): ?>
                <form method="post" class="mt-3">
                    <?php echo CSRF::tokenField(); ?>
                    <input type="hidden" name="action" value="purchase">
                    <input type="hidden" name="package_index" value="<?php echo $index; ?>">
                    <input type="hidden" name="confirmed_cents" value="<?php echo $price; ?>">
                    <?php if ($provider==='usps'): ?>
                        <div class="mb-3"><label class="form-label" for="mailing-<?php echo $index; ?>">Mailing date</label>
                            <input class="form-control" type="date" id="mailing-<?php echo $index; ?>" name="mailing_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <?php else: ?><input type="hidden" name="mailing_date" value="<?php echo date('Y-m-d'); ?>"><?php endif; ?>
                    <div class="mb-3"><label class="form-label" for="password-<?php echo $index; ?>">Current password</label>
                        <input class="form-control" type="password" id="password-<?php echo $index; ?>" name="password" autocomplete="current-password" required></div>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="confirm-<?php echo $index; ?>" name="confirm_charge" value="yes" required>
                        <label class="form-check-label" for="confirm-<?php echo $index; ?>">I reviewed this shipment and authorize a carrier label purchase. The final carrier charge may differ from $<?php echo number_format($price/100,2); ?>.</label></div>
                    <button class="btn btn-primary" type="submit"><?php echo $state==='reserved' && (int)$operation['operator_id']!==(int)$admin['id'] ? 'Take over and purchase label'.($pieces>1?'s':'') : 'Purchase label'.($pieces>1?'s':''); ?></button>
                </form>
            <?php else: ?><p class="text-muted mb-0">A label cannot be purchased for this order yet.</p><?php endif; ?>
            <?php if ($handoffRows[$index]): ?>
                <p class="small text-muted border-top pt-2 mt-3 mb-0">Reservation handoffs: <?php echo count($handoffRows[$index]); ?> recorded. Latest <?php echo Timezone::timestampElement(new DateTimeImmutable('@'.(int)$handoffRows[$index][0]['transferred_at'])); ?>.</p>
            <?php endif; ?>
        </div></section></div>
    <?php endforeach; ?>
    </div>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>
