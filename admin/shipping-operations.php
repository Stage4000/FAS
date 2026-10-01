<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';

use FAS\Config\Database;
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingLabelOperations};

$auth=new AdminAuth();
$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function shipmentQueueHtml($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

$error=''; $rows=[]; $states=['submitted'=>0,'review'=>0];
try {
    $config=ShippingConfig::load();
    if ($config['cache_path']==='') throw new RuntimeException('Private shipping storage is not configured.');
    $cache=new ShippingCache($config['cache_path']);
    $operations=new ShippingLabelOperations($cache->database());
    $states=$operations->health();
    $rows=$operations->attention();
    $lookup=Database::getInstance()->getConnection()->prepare('SELECT order_number FROM orders WHERE id=?');
    foreach ($rows as &$row) {
        $lookup->execute([(int)$row['order_id']]);
        $row['order_number']=$lookup->fetchColumn() ?: null;
    }
    unset($row);
} catch (Throwable $e) {
    $error='Private shipping storage is unavailable. Run the shipping health check.';
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
</head>
<body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="container-fluid px-3 px-lg-4 pb-5" style="max-width:1200px">
    <div class="admin-hero d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
        <div><h1 class="mb-1"><i class="fas fa-truck me-2"></i>Shipping Review</h1>
            <p class="mb-0 opacity-75">Carrier label outcomes that need attention</p></div>
        <a class="btn btn-outline-secondary" href="orders.php">View orders</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo shipmentQueueHtml($error); ?></div><?php endif; ?>
    <?php if (!$error): ?>
        <div class="row g-3 mb-4">
            <div class="col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><span class="text-muted d-block small">Awaiting carrier outcome</span><strong class="fs-3"><?php echo (int)($states['submitted'] ?? 0); ?></strong></div></div></div>
            <div class="col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><span class="text-muted d-block small">Needs reconciliation</span><strong class="fs-3"><?php echo (int)($states['review'] ?? 0); ?></strong></div></div></div>
        </div>
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
    <?php endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>
