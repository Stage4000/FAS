<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingLabelCancellations.php';
require_once __DIR__.'/../src/shipping/ShippingNotifications.php';
require_once __DIR__.'/../src/shipping/ShippingMonitor.php';

use FAS\Config\Database;
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingLabelOperations,ShippingLabelCancellations,ShippingNotifications,ShippingMonitor};

$auth=new AdminAuth();
$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function shipmentQueueHtml($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

$storageError=''; $rows=[]; $cancelRows=[]; $states=['submitted'=>0,'review'=>0];
$cancelStates=['submitted'=>0,'review'=>0,'refund_pending'=>0];
$tracking=null; $trackingError=''; $notificationHealth=null; $notificationRows=[]; $resolvedRows=[]; $notificationError='';
try {
    $config=ShippingConfig::load();
    if ($config['cache_path']==='') throw new RuntimeException('Private shipping storage is not configured.');
    $cache=new ShippingCache($config['cache_path']);
    $operations=new ShippingLabelOperations($cache->database());
    $states=$operations->health();
    $rows=$operations->attention();
    $cancellations=new ShippingLabelCancellations($cache->database());
    $cancelStates=$cancellations->health();
    $cancelRows=$cancellations->attention();
    $lookup=Database::getInstance()->getConnection()->prepare('SELECT order_number FROM orders WHERE id=?');
    foreach ($rows as &$row) {
        $lookup->execute([(int)$row['order_id']]);
        $row['order_number']=$lookup->fetchColumn() ?: null;
    }
    unset($row);
    foreach ($cancelRows as &$row) {
        $lookup->execute([(int)$row['order_id']]);
        $row['order_number']=$lookup->fetchColumn() ?: null;
    }
    unset($row);
    try {
        $tracking=ShippingMonitor::tracking($cache->database(),time());
        foreach ($tracking['rows'] as &$row) {
            $lookup->execute([(int)$row['order_id']]);
            $row['order_number']=$lookup->fetchColumn() ?: null;
        }
        unset($row);
    } catch (Throwable $e) {
        $trackingError='Tracking status is unavailable. Check shipping storage before relying on these counts.';
    }
    try {
        $notifications=new ShippingNotifications($cache->database(),Database::getInstance()->getConnection(),$config);
        $notificationHealth=$notifications->health();
        $notificationRows=$notifications->attention(50);
        $resolvedRows=$notifications->recentResolutions(10);
        foreach ($notificationRows as &$row) {
            $lookup->execute([(int)$row['order_id']]);
            $row['order_number']=$lookup->fetchColumn() ?: null;
        }
        unset($row);
        foreach ($resolvedRows as &$row) {
            $lookup->execute([(int)$row['order_id']]);
            $row['order_number']=$lookup->fetchColumn() ?: null;
        }
        unset($row);
    } catch (Throwable $e) {
        $notificationHealth=null;
        $notificationError='Tracking email status is unavailable. Check notification storage before relying on these counts.';
    }
} catch (Throwable $e) {
    $storageError='Private shipping storage is unavailable. Run the shipping health check.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shipping Review - Flip and Strip Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/admin-style.css">
    <link rel="stylesheet" href="css/shipping-review.css?v=<?= filemtime(__DIR__.'/css/shipping-review.css') ?>">
</head>
<body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="shipping-review container-fluid px-3 px-lg-4 pb-5" style="max-width:1200px">
    <div class="admin-hero d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
        <div><h1 class="mb-1"><i class="fas fa-truck me-2"></i>Shipping Review</h1>
            <p class="mb-0 opacity-75">Labels, tracking and customer updates that need attention</p></div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-light" href="shipping-operations.php" data-admin-refresh><i class="fas fa-rotate me-1" aria-hidden="true"></i>Refresh status</a>
            <a class="btn btn-outline-light" href="shipping-readiness.php">Shipping Readiness</a>
            <a class="btn btn-outline-secondary" href="orders.php">View orders</a>
        </div>
    </div>
    <?php if ($storageError): ?><div class="alert alert-danger" role="alert"><?php echo shipmentQueueHtml($storageError); ?></div><?php endif; ?>
    <?php if (!$storageError): ?>
        <div class="row g-3 mb-4">
            <div class="col-sm-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><span class="text-muted d-block small">Label outcome pending</span><strong class="fs-3"><?php echo (int)($states['submitted'] ?? 0); ?></strong></div></div></div>
            <div class="col-sm-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><span class="text-muted d-block small">Label needs reconciliation</span><strong class="fs-3"><?php echo (int)($states['review'] ?? 0); ?></strong></div></div></div>
            <div class="col-sm-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><span class="text-muted d-block small">Cancellation follow-ups</span><strong class="fs-3"><?php echo (int)($cancelStates['submitted'] ?? 0)+(int)($cancelStates['review'] ?? 0)+(int)($cancelStates['refund_pending'] ?? 0); ?></strong></div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <section class="col-lg-6" aria-labelledby="tracking-summary"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h5" id="tracking-summary">Package tracking</h2>
                <?php if ($trackingError): ?><p class="text-danger mb-0" role="alert"><?= shipmentQueueHtml($trackingError) ?></p>
                <?php else: ?>
                    <p class="mb-2"><strong class="fs-3"><?= (int)$tracking['attention'] ?></strong> <?= $tracking['attention']===1?'package needs':'packages need' ?> a tracking check</p>
                    <p class="text-muted small mb-2"><?= (int)$tracking['packages'] ?> recent confirmed <?= $tracking['packages']===1?'package':'packages' ?> · Last successful check:
                        <?= $tracking['last_success_at'] ? shipmentQueueHtml(gmdate('M j, Y H:i',(int)$tracking['last_success_at']).' UTC') : 'None recorded' ?></p>
                    <div class="d-flex flex-wrap gap-2"><?php foreach (['usps','ups'] as $provider): $carrier=$config['carriers'][$provider];
                        $polling=$carrier['enabled'] && $carrier['tracking_enabled'] && !empty($carrier['client_id']) && !empty($carrier['client_secret']) && ($carrier['environment']==='sandbox' || $carrier['production_verified']); ?>
                        <span class="badge text-bg-secondary"><?= strtoupper($provider) ?>: <?= $polling ? ($carrier['environment']==='sandbox'?'Sandbox polling':'Polling enabled') : 'Polling off' ?></span>
                    <?php endforeach; ?></div>
                <?php endif; ?>
            </div></div></section>
            <section class="col-lg-6" aria-labelledby="notification-summary"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h5" id="notification-summary">Customer tracking emails</h2>
                <?php if ($notificationError): ?><p class="text-danger mb-0" role="alert"><?= shipmentQueueHtml($notificationError) ?></p>
                <?php else: ?>
                    <?php $emailAttention=(int)$notificationHealth['review']+(int)$notificationHealth['stalled']; ?>
                    <p class="mb-2"><strong class="fs-3"><?= $emailAttention ?></strong> <?= $emailAttention===1?'email outcome needs':'email outcomes need' ?> review</p>
                    <p class="text-muted small mb-2"><?= (int)$notificationHealth['queued'] ?> queued · <?= (int)$notificationHealth['accepted'] ?> accepted by the mail transport</p>
                    <span class="badge text-bg-secondary"><?= $notificationHealth['enabled']?'Tracking emails enabled':'Tracking emails off' ?></span>
                    <p class="small text-muted mt-2 mb-0">Mail transport acceptance does not confirm inbox delivery. Refreshing this page does not send emails.</p>
                <?php endif; ?>
            </div></div></section>
        </div>
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="tracking-followups"><div class="card-body">
            <h2 class="h5 mb-3" id="tracking-followups">Tracking follow-ups</h2>
            <?php if ($trackingError): ?><p class="text-muted mb-0">Tracking follow-ups could not be loaded.</p>
            <?php elseif (!$tracking['rows']): ?><p class="text-muted mb-0">No tracking follow-ups are currently due.</p>
            <?php else: ?>
                <p class="text-muted small">Check carrier access and the scheduled tracking worker. A failed or missing check is not a delivery outcome. Cancelled labels and labels older than 120 days are excluded.</p>
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead><tr><th scope="col">Order</th><th scope="col">Carrier / tracking</th><th scope="col">Needs attention</th><th scope="col">Last checked (UTC)</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                    <tbody><?php foreach ($tracking['rows'] as $row): ?>
                        <tr><th scope="row"><?= shipmentQueueHtml($row['order_number'] ?: '#'.$row['order_id']) ?></th>
                            <td><?= shipmentQueueHtml(strtoupper($row['provider'])) ?><span class="d-block small"><?= shipmentQueueHtml($row['tracking_number']) ?></span></td>
                            <td><?= shipmentQueueHtml(['failed'=>'Last check failed','interrupted'=>'Check did not finish','awaiting_first'=>'Awaiting first update','stale'=>'No fresh update for 24 hours'][$row['issue']]) ?></td>
                            <td><?= $row['checked_at'] ? shipmentQueueHtml(gmdate('M j, Y H:i',(int)$row['checked_at'])) : 'No successful check' ?></td>
                            <td><?php if ($row['order_number']): ?><a class="btn btn-outline-primary btn-sm" href="shipping-label.php?id=<?= (int)$row['order_id'] ?>">View tracking</a><?php else: ?>Order unavailable<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php if ($tracking['attention']>count($tracking['rows'])): ?><p class="small text-muted mt-3 mb-0">Showing the oldest <?= count($tracking['rows']) ?> of <?= (int)$tracking['attention'] ?> tracking follow-ups.</p><?php endif; ?>
            <?php endif; ?>
        </div></section>
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="email-followups"><div class="card-body">
            <h2 class="h5 mb-3" id="email-followups">Email delivery follow-ups</h2>
            <?php if ($notificationError): ?><p class="text-muted mb-0">Email follow-ups could not be loaded.</p>
            <?php elseif (!$notificationRows): ?><p class="text-muted mb-0">No tracking email outcomes need review.</p>
            <?php else: ?>
                <p class="text-muted small">Check mail transport logs before resolving an uncertain send. Sends still in progress appear here only after 15 minutes. Resolution records the outcome without sending the email again.</p>
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead><tr><th scope="col">Order</th><th scope="col">Email reference</th><th scope="col">Status</th><th scope="col">Attempt (UTC)</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                    <tbody><?php foreach ($notificationRows as $row): ?>
                        <tr><th scope="row"><?= shipmentQueueHtml($row['order_number'] ?: '#'.$row['order_id']) ?></th><td>#<?= (int)$row['id'] ?></td>
                            <td><span class="badge text-bg-warning"><?= $row['state']==='review'?'Transport outcome uncertain':'Send did not finish' ?></span></td>
                            <td><?= $row['attempted_at'] ? shipmentQueueHtml(gmdate('M j, Y H:i',(int)$row['attempted_at'])) : 'No attempt recorded' ?></td>
                            <td><a class="btn btn-outline-primary btn-sm" href="shipping-notification.php?id=<?= (int)$row['id'] ?>">Review email</a></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php if ((int)$notificationHealth['review']+(int)$notificationHealth['stalled']>count($notificationRows)): ?><p class="small text-muted mt-3 mb-0">Showing the first 50 email follow-ups. Resolve these to reach later entries.</p><?php endif; ?>
            <?php endif; ?>
            <?php if (!$notificationError && $resolvedRows): ?>
                <details id="email-review-history" class="mt-3"><summary class="fw-semibold">Recent email reviews</summary>
                    <ul class="list-unstyled mt-3 mb-0"><?php foreach ($resolvedRows as $row): ?>
                        <li class="border-top py-2"><a href="shipping-notification.php?id=<?= (int)$row['id'] ?>">Email #<?= (int)$row['id'] ?> · <?= shipmentQueueHtml($row['order_number'] ?: '#'.$row['order_id']) ?></a>
                            <span class="d-block small"><?= $row['outcome']==='accepted'?'Mail transport accepted':'Closed without another send' ?> · <?= shipmentQueueHtml(gmdate('M j, Y H:i',(int)$row['resolved_at'])) ?> UTC ·
                                <?= $row['source']==='admin'?'Administrator #'.(int)$row['actor_id']:'Server operator' ?></span></li>
                    <?php endforeach; ?></ul>
                </details>
            <?php endif; ?>
        </div></section>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h2 class="h5 mb-3">Shipments to check</h2>
            <?php if (!$rows): ?>
                <p class="text-muted mb-0">No carrier label outcomes need review.</p>
            <?php else: ?>
                <p class="text-muted small">Check the carrier account before taking any further action. An uncertain request is never submitted again automatically.</p>
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead><tr><th scope="col">Order</th><th scope="col">Carrier</th><th scope="col">Scope</th><th scope="col">Status</th><th scope="col">Last update</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <th scope="row"><?php echo shipmentQueueHtml($row['order_number'] ?: '#'.$row['order_id']); ?></th>
                            <td><?php echo shipmentQueueHtml(strtoupper($row['provider'])); ?></td>
                            <td><?php echo $row['provider']==='usps' ? 'Parcel '.((int)$row['package_index']+1) : 'Shipment'; ?></td>
                            <td><span class="badge text-bg-warning"><?php echo $row['state']==='submitted'?'Awaiting outcome':'Review'; ?></span></td>
                            <td><?php echo shipmentQueueHtml(date('M j, Y g:i A',(int)$row['updated_at'])); ?></td>
                            <td><?php if ($row['order_number']): ?><a class="btn btn-outline-primary btn-sm" href="shipping-label.php?id=<?php echo (int)$row['order_id']; ?>">Review order</a><?php else: ?><span class="text-muted">Order unavailable</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if (count($rows)===100): ?><p class="text-muted small mt-3 mb-0">Showing the 100 most recent outcomes. Use the shipping health check for full counts.</p><?php endif; ?>
            <?php endif; ?>
        </div></div>
        <div class="card border-0 shadow-sm mt-4"><div class="card-body">
            <h2 class="h5 mb-3">Cancellation and refund follow-ups</h2>
            <?php if (!$cancelRows): ?>
                <p class="text-muted mb-0">No carrier cancellation outcomes need review.</p>
            <?php else: ?>
                <p class="text-muted small">A refund request is pending until the carrier confirms its result. Do not submit another cancellation for an uncertain outcome.</p>
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead><tr><th scope="col">Order</th><th scope="col">Carrier</th><th scope="col">Tracking</th><th scope="col">Status</th><th scope="col">Last update</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                    <tbody><?php foreach ($cancelRows as $row): ?>
                        <tr>
                            <th scope="row"><?php echo shipmentQueueHtml($row['order_number'] ?: '#'.$row['order_id']); ?></th>
                            <td><?php echo shipmentQueueHtml(strtoupper($row['provider'])); ?></td>
                            <td><?php echo shipmentQueueHtml($row['tracking_number']); ?></td>
                            <td><span class="badge text-bg-warning"><?php echo shipmentQueueHtml(str_replace('_',' ',$row['state'])); ?></span></td>
                            <td><?php echo shipmentQueueHtml(date('M j, Y g:i A',(int)$row['updated_at'])); ?></td>
                            <td><?php if ($row['order_number']): ?><a class="btn btn-outline-primary btn-sm" href="shipping-label-cancel.php?id=<?php echo (int)$row['order_id']; ?>&amp;package=<?php echo (int)$row['package_index']; ?>">Review request</a><?php else: ?><span class="text-muted">Order unavailable</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php if (count($cancelRows)===100): ?><p class="text-muted small mt-3 mb-0">Showing the 100 most recent requests. Use the shipping health check for full counts.</p><?php endif; ?>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>
