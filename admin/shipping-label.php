<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingOrder.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingLabelService.php';

use FAS\Config\Database;
use FAS\Utils\CSRF;
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingOrder,ShippingLabelOperations,ShippingLabelService};

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
$config=null; $cache=null; $operations=null;
try {
    $config=ShippingConfig::load();
    if ($config['cache_path']!=='') {
        $cache=new ShippingCache($config['cache_path']);
        $operations=new ShippingLabelOperations($cache->database());
    }
} catch (Throwable $e) { $error='Private shipping storage is unavailable. Ask an administrator to run the shipping health check.'; }
$provider=$shipping['provider'];
$carrier=$config['carriers'][$provider] ?? [];
$purchaseEnabled=$operations && $carrier
    && ($carrier['enabled'] ?? false)===true
    && ($carrier['label_purchasing_enabled'] ?? false)===true
    && !empty($carrier['client_id']) && !empty($carrier['client_secret'])
    && (($carrier['environment'] ?? '')==='sandbox' || ($carrier['production_verified'] ?? false)===true)
    && !empty($config['shipper_name'])
    && ($provider==='usps' || (!empty($config['shipper_phone']) && !empty($carrier['account_number'])));
$savedPackages=is_array($shipping['packages'] ?? null) ? $shipping['packages'] : [];
$destination=json_decode((string)($order['shipping_address'] ?? ''),true);
$destination=is_array($destination) ? $destination : [];
$origin=is_array($shipping['origin'] ?? null) ? $shipping['origin'] : [];
$scopes=$provider==='usps' ? count($savedPackages) : 1;
if ($scopes<1 || $scopes>10) $purchaseEnabled=false;
$prices=[];
for ($index=0;$index<$scopes;$index++) {
    $prices[$index]=$provider==='usps'
        ? ($shipping['fulfillment_options'][$index]['quoted_cents'] ?? null)
        : ($shipping['quoted_cents'] ?? null);
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
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) {
        $index=isset($index) && is_int($index) ? $index : 0;
        $record=$operations ? $operations->find((int)$orderId,$index) : null;
        $error=$record && in_array($record['state'],['submitted','review','ready'],true)
            ? 'The carrier outcome needs review. Do not purchase this shipment again.'
            : 'The label could not be prepared. Check the order and carrier account settings.';
    }
}
$rows=[];
for ($index=0;$index<$scopes;$index++) {
    $rows[$index]=$operations ? $operations->find((int)$orderId,$index) : null;
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
    <?php if (!$purchaseEnabled): ?>
        <div class="alert alert-warning" role="status">Carrier label purchasing is unavailable for this order. Review the carrier account, shipper details, and private storage before proceeding.</div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h2 class="h5">Saved shipping choice</h2>
        <div class="row g-3">
            <div class="col-sm-6"><span class="text-muted d-block small">Service</span><strong><?php echo shippingLabelHtml($shipping['courier_name'].' — '.$shipping['service_name']); ?></strong></div>
            <div class="col-sm-6"><span class="text-muted d-block small">Customer shipping quote</span><strong>$<?php echo number_format((int)$shipping['quoted_cents']/100,2); ?></strong></div>
            <div class="col-sm-6"><span class="text-muted d-block small">Ship from</span><strong><?php echo shippingLabelHtml(implode(', ',array_filter([$origin['address1'] ?? '',$origin['address2'] ?? '',$origin['city'] ?? '',$origin['state'] ?? '',$origin['zip'] ?? ''],static fn($part)=>is_string($part) && $part!==''))); ?></strong></div>
            <div class="col-sm-6"><span class="text-muted d-block small">Ship to</span><strong><?php echo shippingLabelHtml(implode(', ',array_filter([$destination['address1'] ?? '',$destination['address2'] ?? '',$destination['city'] ?? '',$destination['state'] ?? '',$destination['zip'] ?? ''],static fn($part)=>is_string($part) && $part!==''))); ?></strong></div>
        </div>
        <p class="text-muted small mt-3 mb-0">The carrier’s final charge may differ from the customer’s shipping quote. Check the parcel and address before purchasing.</p>
    </div></div>
    <div class="row g-3">
    <?php foreach ($rows as $index=>$operation):
        $state=$operation['state'] ?? 'not prepared';
        $pieces=$provider==='ups' ? count($savedPackages) : 1;
        $price=$prices[$index] ?? null;
        $canPurchase=$purchaseEnabled && !$operation && is_int($price)
            && $order['payment_status']==='completed' && $order['order_status']==='processing';
        if ($purchaseEnabled && $operation && $state==='reserved') $canPurchase=is_int($price);
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
                <div class="d-flex flex-wrap gap-2">
                <?php for($piece=0;$piece<$pieces;$piece++): ?>
                    <a class="btn btn-outline-primary btn-sm" href="shipping-label-download.php?id=<?php echo (int)$orderId; ?>&amp;package=<?php echo $index; ?>&amp;piece=<?php echo $piece; ?>">Download label <?php echo $piece+1; ?></a>
                <?php endfor; ?>
                </div>
            <?php elseif ($state==='review'||$state==='submitted'): ?>
                <p class="mb-0">The carrier outcome is uncertain. Reconcile this shipment before any further purchase.</p>
            <?php elseif ($canPurchase): ?>
                <form method="post" class="mt-3">
                    <?php echo CSRF::tokenField(); ?>
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
                    <button class="btn btn-primary" type="submit">Purchase label<?php echo $pieces>1?'s':''; ?></button>
                </form>
            <?php else: ?><p class="text-muted mb-0">A label cannot be purchased for this order yet.</p><?php endif; ?>
        </div></section></div>
    <?php endforeach; ?>
    </div>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>
