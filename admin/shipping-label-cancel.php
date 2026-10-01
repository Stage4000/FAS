<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingLabelCancellations.php';
require_once __DIR__.'/../src/shipping/ShippingLabelCancellationService.php';

use FAS\Config\Database;
use FAS\Utils\CSRF;
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingLabelOperations,
    ShippingLabelCancellations,ShippingLabelCancellationService};

$auth=new AdminAuth();
$admin=$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function shippingCancelHtml($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

$orderId=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$packageIndex=filter_var($_GET['package'] ?? null,FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>0,'max_range'=>9]]);
if (!$orderId || $packageIndex===false) { http_response_code(400); exit('Choose a shipment.'); }
$db=Database::getInstance()->getConnection();
$stmt=$db->prepare('SELECT order_number,payment_status FROM orders WHERE id=?');
$stmt->execute([$orderId]);
$order=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); exit('Order not found.'); }
$error=''; $notice=$_SESSION['shipping_cancel_notice'] ?? '';
unset($_SESSION['shipping_cancel_notice']);
$config=null; $cache=null; $operations=null; $cancellations=null; $operation=null; $cancellation=null;
try {
    $config=ShippingConfig::load();
    $cache=new ShippingCache($config['cache_path']);
    $operations=new ShippingLabelOperations($cache->database());
    $cancellations=new ShippingLabelCancellations($cache->database());
    $operation=$operations->find((int)$orderId,$packageIndex);
    $cancellation=$cancellations->find((int)$orderId,$packageIndex);
} catch (Throwable $e) {
    $error='Private shipping storage is unavailable. Run the shipping health check.';
}
if (!$error && (!$operation || $operation['state']!=='ready')) {
    http_response_code(404); exit('Confirmed shipment not found.');
}
$carrier=$operation ? ($config['carriers'][$operation['provider']] ?? []) : [];
$enabled=$operation && $carrier && ($carrier['enabled'] ?? false)===true
    && ($carrier['label_cancellation_enabled'] ?? false)===true
    && !empty($carrier['client_id']) && !empty($carrier['client_secret'])
    && (($carrier['environment'] ?? '')==='sandbox' || ($carrier['production_verified'] ?? false)===true);
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    fas_security_body();
    try {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('Your session expired. Refresh the page and try again.');
        }
        if (!$enabled || $cancellation || $order['payment_status']!=='completed') {
            throw new RuntimeException('Carrier cancellation is unavailable for this shipment.');
        }
        if (($_POST['confirm_unused'] ?? '')!=='yes'
            || ($_POST['confirm_carrier_action'] ?? '')!=='yes') {
            throw new InvalidArgumentException('Confirm the unused label and carrier action.');
        }
        if (!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password'] ?? null)) {
            throw new InvalidArgumentException($auth->lastError ?: 'Password could not be verified.');
        }
        $service=new ShippingLabelCancellationService($db,$cache,$config);
        $result=$service->cancel((int)$orderId,$packageIndex,(int)$admin['id']);
        $_SESSION['shipping_cancel_notice']=$result['state']==='cancelled'
            ? 'The carrier confirmed this label was canceled. Do not use the saved label.'
            : ($result['state']==='refund_pending'
                ? 'A carrier refund request is pending. Do not use the saved label.'
                : 'The carrier outcome needs reconciliation. Do not submit another request.');
        header('Location: shipping-label-cancel.php?id='.(int)$orderId.'&package='.$packageIndex,true,303); exit;
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) {
        $cancellation=$cancellations ? $cancellations->find((int)$orderId,$packageIndex) : null;
        $error=$cancellation && in_array($cancellation['state'],['submitted','review','cancelled','refund_pending'],true)
            ? 'The carrier outcome needs review. Do not submit another cancellation.'
            : 'The cancellation could not be prepared. Check the carrier account and shipment.';
    }
}
$cancellation=$cancellations ? $cancellations->find((int)$orderId,$packageIndex) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cancel Shipping Label - Flip and Strip Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="container-fluid px-3 px-lg-4 pb-5" style="max-width:1000px">
    <div class="admin-hero d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
        <div><h1 class="mb-1"><i class="fas fa-ban me-2"></i>Label Cancellation</h1>
            <p class="mb-0 opacity-75">Order <?php echo shippingCancelHtml($order['order_number']); ?></p></div>
        <a class="btn btn-outline-secondary" href="shipping-label.php?id=<?php echo (int)$orderId; ?>">Back to labels</a>
    </div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?php echo shippingCancelHtml($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo shippingCancelHtml($error); ?></div><?php endif; ?>
    <?php if ($operation): ?>
        <section class="card border-0 shadow-sm"><div class="card-body">
            <h2 class="h5">Shipment to review</h2>
            <div class="row g-3 mb-3">
                <div class="col-sm-6"><span class="text-muted d-block small">Carrier</span><strong><?php echo shippingCancelHtml(strtoupper($operation['provider'])); ?></strong></div>
                <div class="col-sm-6"><span class="text-muted d-block small">Scope</span><strong><?php echo $operation['provider']==='usps' ? 'Parcel '.($packageIndex+1) : 'Entire UPS shipment'; ?></strong></div>
                <div class="col-12"><span class="text-muted d-block small">Tracking</span><strong><?php echo shippingCancelHtml($operation['tracking_number']); ?></strong></div>
            </div>
            <?php if ($cancellation): ?>
                <div class="alert alert-warning mb-0" role="status">
                    <?php echo $cancellation['state']==='cancelled'
                        ? 'The carrier confirmed cancellation. This label must not be used.'
                        : ($cancellation['state']==='refund_pending'
                            ? 'A refund request is pending with the carrier. This is not a confirmed refund.'
                            : 'The cancellation outcome needs carrier reconciliation. Do not submit another request.'); ?>
                    <?php if ($cancellation['state']==='refund_pending' && $cancellation['carrier_reference']): ?>
                        <span class="d-block mt-1">Carrier reference: <?php echo shippingCancelHtml($cancellation['carrier_reference']); ?></span>
                    <?php endif; ?>
                </div>
            <?php elseif (!$enabled): ?>
                <div class="alert alert-warning mb-0" role="status">Carrier cancellation is unavailable. Verify the carrier account and private storage before proceeding.</div>
            <?php else: ?>
                <p class="text-muted">Only request cancellation for an unused label. USPS may accept a refund request instead of canceling immediately; UPS voids the shipment. Carrier acceptance and any refund are not guaranteed.</p>
                <form method="post">
                    <?php echo CSRF::tokenField(); ?>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="confirm-unused" name="confirm_unused" value="yes" required>
                        <label class="form-check-label" for="confirm-unused">I confirmed this label has not been handed to the carrier or used.</label></div>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="confirm-action" name="confirm_carrier_action" value="yes" required>
                        <label class="form-check-label" for="confirm-action">I authorize one carrier cancellation or refund request for this shipment.</label></div>
                    <div class="mb-3"><label class="form-label" for="cancel-password">Current password</label>
                        <input class="form-control" type="password" id="cancel-password" name="password" autocomplete="current-password" required></div>
                    <button class="btn btn-danger" type="submit">Submit carrier request</button>
                </form>
            <?php endif; ?>
        </div></section>
    <?php endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>
