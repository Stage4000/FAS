<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/config/Database.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingOrder.php';
require_once __DIR__.'/../src/shipping/ShippingCatalogReadiness.php';
require_once __DIR__.'/../src/shipping/ShippingReadiness.php';

use FAS\Config\Database;
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingOrder,ShippingCatalogReadiness,ShippingReadiness};

$auth=new AdminAuth();
$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function shippingReadyHtml($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

$error=''; $storageError=false; $health=[]; $readiness=null;
try {
    $config=ShippingConfig::load();
    $orders=Database::getInstance()->getConnection();
    $health=['direct_order_storage'=>ShippingOrder::directReady($orders),
        'catalog'=>ShippingCatalogReadiness::report($orders),
        'cache'=>['healthy'=>false]];
    if ($config['cache_path']!=='') {
        try {
            $cache=new ShippingCache($config['cache_path']);
            $health['cache']=$cache->health();
            $tables=$cache->database()->query("SELECT name FROM sqlite_master WHERE type='table'")
                ->fetchAll(PDO::FETCH_COLUMN);
            $has=static fn(string $table): bool => in_array($table,$tables,true);
            $health['label_storage']=['initialized'=>$has('shipping_label_operations') && $has('shipping_label_packages')];
            $health['reservation_handoff_storage']=['initialized'=>$has('shipping_label_handoffs')];
            $health['label_reprint_storage']=['initialized'=>$has('shipping_label_reprints')];
            $health['label_resolution_storage']=['initialized'=>$has('shipping_label_resolutions')];
            $health['label_cancellation_storage']=['initialized'=>$has('shipping_label_cancellations')];
            $health['cancellation_review_storage']=['initialized'=>$has('shipping_cancellation_resolutions')];
            $health['tracking_storage']=['initialized'=>$has('shipping_tracking')];
            $health['notifications']=['initialized'=>$has('shipping_notifications')
                && $has('shipping_notification_scans') && $has('shipping_notification_resolutions')];
        } catch (Throwable $e) {
            $storageError=true;
        }
    } else {
        $storageError=true;
    }
    $readiness=ShippingReadiness::report($config,$health);
} catch (Throwable $e) {
    $error='Shipping readiness could not be loaded. Check the site configuration and inventory database.';
}
$catalog=$readiness['catalog'] ?? [];
$issueLabels=['measurements'=>'Packed measurements missing or invalid',
    'size'=>'Parcel exceeds direct size limits','origin'=>'Usable ship-from address missing',
    'usps_weight'=>'Over the USPS 70 lb parcel limit',
    'usps_size'=>'Over the USPS 130 in length and girth limit'];
$modeLabel=['easyship'=>'Easyship','direct_with_fallback'=>'Direct with Easyship fallback',
    'direct'=>'Direct only'][$readiness['current_mode'] ?? ''] ?? 'Unavailable';
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Shipping Readiness - Flip and Strip Admin</title>
<link rel="shortcut icon" href="../gallery/favicons/favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/admin-style.css">
<link rel="stylesheet" href="css/shipping-review.css?v=<?= (int)filemtime(__DIR__.'/css/shipping-review.css') ?>">
</head><body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="shipping-review container-fluid px-3 px-lg-4 pb-5" style="max-width:1200px">
    <div class="admin-hero d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
        <div><h1 class="mb-1"><i class="fas fa-clipboard-check me-2" aria-hidden="true"></i>Shipping Readiness</h1>
            <p class="mb-0 opacity-75">USPS and UPS setup, inventory and storage checks</p></div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-light" href="shipping-readiness.php" data-admin-refresh>Refresh checks</a>
            <a class="btn btn-outline-light" href="shipping-settings.php">Shipping Settings</a>
            <a class="btn btn-outline-secondary" href="shipping-operations.php">Shipping Review</a>
        </div>
    </div>
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= shippingReadyHtml($error) ?></div>
    <?php else: ?>
        <p class="small text-muted">These checks read local settings and records. Carrier access, prices and label behavior still require separate account tests.</p>
        <div class="row g-3 mb-4">
            <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h6 text-muted">Selected shipping mode</h2><p class="fs-5 fw-semibold mb-0"><?= shippingReadyHtml($modeLabel) ?></p>
            </div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h6 text-muted">Direct order storage</h2><p class="fs-5 fw-semibold mb-0"><?= $readiness['storage_configured']?'Available':'Needs setup' ?></p>
                <?php if ($storageError): ?><p class="small text-danger mb-0">Private shipping storage is unavailable.</p><?php endif; ?>
            </div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h6 text-muted">Saleable products scanned</h2><p class="fs-5 fw-semibold mb-0"><?= !empty($catalog['schema_initialized'])?(int)$catalog['saleable_products']:'Unavailable' ?></p>
            </div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <?php foreach (['usps'=>'USPS','ups'=>'UPS'] as $key=>$name): $carrier=$readiness['carriers'][$key]; ?>
            <section class="col-lg-6" aria-labelledby="<?= $key ?>-readiness"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h5 mb-0" id="<?= $key ?>-readiness"><?= $name ?></h2>
                    <span class="badge <?= $carrier['checkout_configuration_ready']?'text-bg-success':'text-bg-warning' ?>"><?= $carrier['checkout_configuration_ready']?'Local configuration ready':'Setup needed' ?></span></div>
                <?php if ($carrier['configuration_blockers']): ?>
                    <ul class="mb-3 ps-3"><?php foreach ($carrier['configuration_blockers'] as $blocker): ?><li><?= shippingReadyHtml($blocker) ?></li><?php endforeach; ?></ul>
                <?php else: ?><p class="mb-3">No local configuration blockers found. Complete the carrier-account and checkout tests before activation.</p><?php endif; ?>
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge text-bg-secondary">Labels <?= $carrier['label_purchasing_enabled']?'on':'off' ?></span>
                    <span class="badge text-bg-secondary">Cancellation <?= $carrier['label_cancellation_enabled']?'on':'off' ?></span>
                    <span class="badge text-bg-secondary">Tracking <?= $carrier['tracking_enabled']?'on':'off' ?></span>
                    <?php if ($key==='usps'): ?><span class="badge text-bg-secondary">Original-label recovery <?= $carrier['label_reprint_enabled']?'on':'off' ?></span><?php endif; ?>
                </div>
            </div></div></section>
            <?php endforeach; ?>
        </div>
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="fulfillment-readiness"><div class="card-body">
            <h2 class="h5" id="fulfillment-readiness">Fulfillment storage</h2>
            <p class="small text-muted">These checks show installed private records only. Carrier actions and customer messages still need separate activation and testing.</p>
            <div class="row g-2">
                <?php foreach ([
                    'Label operations'=>$readiness['fulfillment_storage_configured'],
                    'USPS label recovery'=>$readiness['reprint_storage_configured'],
                    'Cancellation review'=>$readiness['cancellation_storage_configured'],
                    'Tracking status'=>$readiness['tracking_storage_configured'],
                    'Tracking emails'=>$readiness['notifications']['storage_initialized'],
                ] as $label=>$installed): ?>
                    <div class="col-sm-6 col-lg-4"><div class="border rounded p-3 h-100 d-flex justify-content-between align-items-center gap-2">
                        <span><?= shippingReadyHtml($label) ?></span>
                        <span class="badge <?= $installed?'text-bg-success':'text-bg-warning' ?>"><?= $installed?'Installed':'Needs setup' ?></span>
                    </div></div>
                <?php endforeach; ?>
            </div>
        </div></section>
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="catalog-readiness"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="h5 mb-0" id="catalog-readiness">Inventory packing and origins</h2>
                <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary btn-sm" href="products.php">Manage products</a>
                    <a class="btn btn-outline-primary btn-sm" href="warehouses.php">Manage warehouses</a></div></div>
            <?php if (empty($catalog['schema_initialized'])): ?>
                <p class="text-danger mt-3 mb-0">Inventory measurements could not be scanned. Check the inventory schema before enabling direct rates.</p>
            <?php else: ?>
                <p class="mt-3 mb-2"><?= (int)$catalog['complete_measurements'] ?> of <?= (int)$catalog['saleable_products'] ?> saleable products have complete numeric measurements.
                    <?= (int)$catalog['assigned_origin'] ?> use an assigned origin; <?= (int)$catalog['default_origin'] ?> use the default origin.</p>
                <p class="small text-muted">Direct rates currently treat each unit as one packed parcel. Confirm these are measured shipping packages, including free-shipping products.</p>
                <div class="row g-3"><?php foreach ($issueLabels as $issue=>$label): $count=(int)($catalog['issues'][$issue] ?? 0); ?>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6 mb-1"><?= shippingReadyHtml($label) ?> <span class="badge <?= $count?'text-bg-warning':'text-bg-success' ?>"><?= $count ?></span></h3>
                        <?php if ($count): ?><p class="small mb-0">Examples:
                            <?php foreach (($catalog['example_product_ids'][$issue] ?? []) as $id): ?><a class="me-2" href="products.php?action=edit&amp;id=<?= (int)$id ?>">#<?= (int)$id ?></a><?php endforeach; ?>
                            <?php if ($count>count($catalog['example_product_ids'][$issue] ?? [])): ?>and more<?php endif; ?>
                        </p><?php endif; ?></div></div>
                <?php endforeach; ?></div>
                <?php if (!empty($catalog['potential_mixed_origin_carts'])): ?>
                    <div class="alert alert-warning mt-3 mb-0" role="status">Products ship from <?= (int)$catalog['distinct_origin_addresses'] ?> distinct addresses. A cart combining origins cannot receive a direct rate yet.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div></section>
        <section class="card border-0 shadow-sm" aria-labelledby="activation-checks"><div class="card-body">
            <h2 class="h5" id="activation-checks">Checks before activation</h2>
            <p class="small text-muted">Local configuration checks cannot confirm a carrier account or a successful customer order.</p>
            <ul class="mb-0"><?php foreach ($readiness['remaining_acceptance'] as $item): ?><li><?= shippingReadyHtml($item) ?></li><?php endforeach; ?></ul>
        </div></section>
    <?php endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body></html>
