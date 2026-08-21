<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/Timezone.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/integrations/EbayAPI.php';
require_once __DIR__ . '/../src/utils/CSRF.php';
require_once __DIR__ . '/../src/utils/EbaySyncHealth.php';
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';

use FAS\Config\Database;
use FAS\Integrations\EbayAPI;
use FAS\Models\Product;
use FAS\Utils\CSRF;
use FAS\Utils\EbaySyncHealth;
use FAS\Utils\ErrorMonitor;
use FAS\Utils\Timezone;

$auth = new AdminAuth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$syncHealth = new EbaySyncHealth($db);
$syncHealth->ensureTables();
$productModel = new Product($db);

$configFile = __DIR__ . '/../src/config/config.php';
$config = file_exists($configFile) ? require $configFile : [];
$syncApiKey = $config['security']['sync_api_key'] ?? 'fas_sync_key_2026';

$success = '';
$error = '';

function eshSafe($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function eshNumber($value): string
{
    return number_format((float)$value);
}

function eshDate($value): string
{
    if (empty($value)) {
        return 'Never';
    }

    $timestamp = strtotime((string)$value);
    return $timestamp ? Timezone::timestampElement($value) : (string)$value;
}

function eshDuration($startedAt, $completedAt): string
{
    if (!$startedAt) {
        return '—';
    }

    $start = strtotime((string)$startedAt);
    $end = $completedAt ? strtotime((string)$completedAt) : time();
    if (!$start || !$end || $end < $start) {
        return '—';
    }

    $seconds = $end - $start;
    if ($seconds < 60) {
        return $seconds . 's';
    }

    $minutes = floor($seconds / 60);
    $remainingSeconds = $seconds % 60;
    if ($minutes < 60) {
        return $minutes . 'm ' . $remainingSeconds . 's';
    }

    $hours = floor($minutes / 60);
    return $hours . 'h ' . ($minutes % 60) . 'm';
}

function eshBadgeClass($status): string
{
    return match ((string)$status) {
        'completed', 'resolved', 'hidden', 'removed' => 'success',
        'running' => 'primary',
        'failed', 'open', 'retry_failed' => 'danger',
        default => 'secondary',
    };
}

function eshRetryItem(PDO $db, EbaySyncHealth $syncHealth, array $config, string $configFile, string $ebayItemId): array
{
    $ebayItemId = trim($ebayItemId);
    if ($ebayItemId === '') {
        return [false, 'Missing eBay item ID.'];
    }

    $ebayAPI = new EbayAPI($config, $configFile);
    $productModel = new Product($db);
    $errorMonitor = new ErrorMonitor($db);
    $details = $ebayAPI->getItemDetails($ebayItemId);

    if (!$details) {
        if (method_exists($ebayAPI, 'getLastApiError') && $ebayAPI->getLastApiError()) {
            $apiError = $ebayAPI->getLastApiError();
            $syncHealth->recordApiError(null, $apiError['api_call'] ?? 'GetItem', $apiError['message'] ?? 'Retry failed', $apiError['code'] ?? null, array_merge($apiError['context'] ?? [], [
                'ebay_item_id' => $ebayItemId,
                'retry_source' => 'admin_dashboard',
            ]));
            $errorMonitor->record(ErrorMonitor::AREA_EBAY_SYNC, $apiError['message'] ?? 'eBay item retry failed.', [
                'source' => 'admin/ebay-sync-health.php',
                'severity' => 'error',
                'error_code' => $apiError['code'] ?? null,
                'ebay_item_id' => $ebayItemId,
                'metadata' => array_merge($apiError['context'] ?? [], [
                    'retry_source' => 'admin_dashboard',
                ]),
            ]);
        }
        $syncHealth->markItemRetried($ebayItemId, false, 'Retry failed: item details could not be fetched from eBay.');
        return [false, 'Retry failed. eBay did not return item details.'];
    }

    $rawItem = $details['item'] ?? [];
    $title = $rawItem['Title'] ?? ('eBay Item ' . $ebayItemId);
    $price = $rawItem['SellingStatus']['CurrentPrice']['__value__']
        ?? $rawItem['StartPrice']['__value__']
        ?? 0;
    $quantity = $rawItem['Quantity'] ?? 1;
    $url = $rawItem['ListingDetails']['ViewItemURL'] ?? ('https://www.ebay.com/itm/' . rawurlencode($ebayItemId));

    $itemData = [
        'id' => $ebayItemId,
        'title' => $title,
        'price' => $price,
        'quantity' => $quantity,
        'image' => $details['image'] ?? null,
        'images' => $details['images'] ?? [],
        'url' => $url,
        'brand' => $details['brand'] ?? null,
        'mpn' => $details['mpn'] ?? null,
        'weight' => $details['weight'] ?? null,
        'length' => $details['length'] ?? null,
        'width' => $details['width'] ?? null,
        'height' => $details['height'] ?? null,
        'description' => $details['description'] ?? '',
        'condition' => $details['condition'] ?? 'Used',
        'store_category_id' => $details['store_category_id'] ?? null,
        'store_category2_id' => $details['store_category2_id'] ?? null,
    ];

    try {
        $productModel->syncFromEbay($itemData, $ebayAPI);
        $syncHealth->markItemRetried($ebayItemId, true, 'Retry succeeded from eBay sync health dashboard.');
        return [true, 'Item retry completed successfully.'];
    } catch (Throwable $e) {
        $syncHealth->markItemRetried($ebayItemId, false, 'Retry failed: ' . $e->getMessage());
        $syncHealth->recordFailedItem(null, $ebayItemId, $title, $e->getMessage(), $url, null, [
            'action' => 'retry_item',
            'source' => 'admin_dashboard',
        ]);
        $errorMonitor->recordThrowable(ErrorMonitor::AREA_EBAY_SYNC, $e, [
            'source' => 'admin/ebay-sync-health.php',
            'severity' => 'error',
            'ebay_item_id' => $ebayItemId,
            'metadata' => [
                'action' => 'retry_item',
            ],
        ]);
        return [false, 'Retry failed: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'retry_item') {
            [$ok, $message] = eshRetryItem($db, $syncHealth, $config, $configFile, $_POST['ebay_item_id'] ?? '');
            if ($ok) {
                $success = $message;
            } else {
                $error = $message;
            }
        } elseif ($action === 'resolve_api_errors') {
            $lastSync = $syncHealth->getLastSync();
            if ($lastSync && !empty($lastSync['id'])) {
                $syncHealth->markApiErrorsResolvedForSync($lastSync['id']);
                $success = 'Open API errors from the latest sync were marked resolved.';
            } else {
                $error = 'No sync log was found.';
            }
        } elseif ($action === 'purge_hidden_ebay') {
            $purged = $productModel->purgeHiddenProducts('ebay');
            $success = number_format($purged) . ' hidden eBay product' . ($purged === 1 ? '' : 's') . ' removed from active inventory.';
        }
    }
}

$stats = $syncHealth->getSyncStats();
$lastSync = $syncHealth->getLastSync();
$recentSyncs = $syncHealth->getRecentSyncs(12);
$failedItems = $syncHealth->getFailedItems(25);
$hiddenSoldItems = $syncHealth->getHiddenSoldItems(25);
$currentHiddenEbayProducts = $syncHealth->getCurrentHiddenEbayProducts(25);
$apiErrors = $syncHealth->getApiErrors(25);
$csrfToken = CSRF::generateToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>eBay Sync Health - Admin</title>
<link rel="shortcut icon" href="../gallery/favicons/favicon.png">
<link rel="manifest" href="/admin/manifest.json">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
<link rel="stylesheet" href="css/admin-style.css">
<style>
.sync-health-stat {
    border: 0;
    border-radius: 1rem;
    box-shadow: 0 0.75rem 1.8rem rgba(15, 23, 42, 0.08);
}
.sync-health-stat .icon {
    align-items: center;
    border-radius: 999px;
    display: inline-flex;
    height: 44px;
    justify-content: center;
    width: 44px;
}
.sync-health-table td,
.sync-health-table th {
    vertical-align: middle;
}
.sync-health-message {
    max-width: 360px;
}
.sync-health-subrow {
    background: #f8fafc;
}
[data-theme="dark"] .sync-health-stat,
[data-theme="dark"] .sync-health-card {
    background: linear-gradient(180deg, #333 0%, #2d2d2d 100%);
    border-color: rgba(255, 255, 255, 0.14) !important;
    color: var(--admin-text);
    box-shadow: 0 0.75rem 1.8rem rgba(0, 0, 0, 0.24) !important;
}
[data-theme="dark"] .sync-health-stat .text-muted,
[data-theme="dark"] .sync-health-card .text-muted,
[data-theme="dark"] .sync-health-message {
    color: #c5cbd3 !important;
}
[data-theme="dark"] .sync-health-card .card-header {
    background: #333 !important;
    border-bottom-color: rgba(255, 255, 255, 0.14);
    color: var(--admin-text);
}
[data-theme="dark"] .sync-health-card .list-group-item {
    background: transparent;
    border-color: rgba(255, 255, 255, 0.12);
    color: var(--admin-text);
}
[data-theme="dark"] .sync-health-subrow {
    background: rgba(255, 255, 255, 0.05);
}
[data-theme="dark"] .table {
    --bs-table-bg: #2d2d2d;
    --bs-table-color: var(--admin-text);
    --bs-table-border-color: var(--admin-border);
    --bs-table-hover-bg: rgba(219, 3, 53, 0.14);
    --bs-table-hover-color: var(--admin-text);
}
[data-theme="dark"] .table thead th {
    background: #242629;
    color: #c5cbd3;
}
[data-theme="dark"] .btn-outline-secondary {
    border-color: rgba(255, 255, 255, 0.28);
    color: #d0d5dd;
}
[data-theme="dark"] .btn-outline-secondary:hover {
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
}
</style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="admin-hero mb-4" data-aos="fade-down">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
        <div>
            <p class="text-uppercase fw-semibold small mb-2 text-muted">Operations Dashboard</p>
            <h1 class="display-6 fw-bold mb-2"><i class="fas fa-rotate me-2"></i>eBay Sync Health</h1>
            <p class="mb-0 text-muted">Monitor sync freshness, failed items, removed sold listings, API errors, and retry actions.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-light text-danger fw-semibold" id="sync-ebay-health-btn">
                <i class="fas fa-sync-alt me-2"></i>Run Full Sync
            </button>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?php echo eshSafe($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?php echo eshSafe($error); ?></div>
<?php endif; ?>
<div id="sync-health-status"></div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card sync-health-stat sync-health-card">
            <div class="card-body d-flex justify-content-between gap-3">
                <div>
                    <div class="text-muted small fw-semibold">Last Sync</div>
                    <div class="h5 mb-1"><?php echo eshSafe(eshDate($lastSync['completed_at'] ?? $lastSync['started_at'] ?? null)); ?></div>
                    <span class="badge bg-<?php echo eshSafe(eshBadgeClass($lastSync['status'] ?? 'never')); ?>"><?php echo eshSafe($lastSync['status'] ?? 'Never'); ?></span>
                </div>
                <span class="icon bg-primary-subtle text-primary"><i class="fas fa-clock"></i></span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card sync-health-stat sync-health-card">
            <div class="card-body d-flex justify-content-between gap-3">
                <div>
                    <div class="text-muted small fw-semibold">Open Failed Items</div>
                    <div class="h3 mb-0 text-danger"><?php echo eshNumber($stats['open_failed_items']); ?></div>
                </div>
                <span class="icon bg-danger-subtle text-danger"><i class="fas fa-triangle-exclamation"></i></span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card sync-health-stat sync-health-card">
            <div class="card-body d-flex justify-content-between gap-3">
                <div>
                    <div class="text-muted small fw-semibold">Hidden eBay Products</div>
                    <div class="h3 mb-0 text-warning"><?php echo eshNumber($stats['current_hidden_ebay']); ?></div>
                    <small class="text-muted"><?php echo eshNumber($stats['hidden_sold_30d']); ?> sold/ended removed in 30 days</small>
                </div>
                <span class="icon bg-warning-subtle text-warning"><i class="fas fa-eye-slash"></i></span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card sync-health-stat sync-health-card">
            <div class="card-body d-flex justify-content-between gap-3">
                <div>
                    <div class="text-muted small fw-semibold">Open API Errors</div>
                    <div class="h3 mb-0 text-danger"><?php echo eshNumber($stats['open_api_errors']); ?></div>
                </div>
                <span class="icon bg-danger-subtle text-danger"><i class="fas fa-plug-circle-exclamation"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100 sync-health-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list-check text-danger me-2"></i>Recent Sync Runs</h5>
                <span class="badge bg-secondary"><?php echo eshNumber($stats['syncs_30d']); ?> in 30 days</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 sync-health-table">
                    <thead>
                        <tr>
                            <th>Started</th>
                            <th>Status</th>
                            <th>Processed</th>
                            <th>Failed</th>
                            <th>Removed</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recentSyncs)): ?>
                        <tr><td colspan="6" class="text-muted text-center py-4">No sync history yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentSyncs as $sync): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo eshSafe(eshDate($sync['started_at'] ?? null)); ?></div>
                                <small class="text-muted"><?php echo eshSafe($sync['sync_type'] ?? 'sync'); ?></small>
                            </td>
                            <td><span class="badge bg-<?php echo eshSafe(eshBadgeClass($sync['status'] ?? '')); ?>"><?php echo eshSafe($sync['status'] ?? 'unknown'); ?></span></td>
                            <td><?php echo eshNumber($sync['items_processed'] ?? 0); ?></td>
                            <td class="<?php echo ((int)($sync['items_failed'] ?? 0) > 0) ? 'text-danger fw-semibold' : ''; ?>"><?php echo eshNumber($sync['items_failed'] ?? 0); ?></td>
                            <td><?php echo eshNumber($sync['items_hidden'] ?? 0); ?></td>
                            <td><?php echo eshSafe(eshDuration($sync['started_at'] ?? null, $sync['completed_at'] ?? null)); ?></td>
                        </tr>
                        <?php if (!empty($sync['error_message'])): ?>
                        <tr>
                            <td colspan="6" class="small text-danger"><?php echo eshSafe($sync['error_message']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100 sync-health-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-plug-circle-exclamation text-danger me-2"></i>API Errors</h5>
                <?php if (!empty($apiErrors)): ?>
                <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo eshSafe($csrfToken); ?>">
                    <input type="hidden" name="action" value="resolve_api_errors">
                    <button class="btn btn-sm btn-outline-secondary">Mark Latest Resolved</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($apiErrors)): ?>
                    <div class="text-muted text-center py-4">No API errors recorded.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                    <?php foreach ($apiErrors as $apiError): ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between gap-3">
                                <div>
                                    <div class="fw-semibold"><?php echo eshSafe($apiError['api_call'] ?: 'eBay API'); ?></div>
                                    <div class="small text-muted"><?php echo eshSafe(eshDate($apiError['created_at'] ?? null)); ?></div>
                                </div>
                                <span class="badge bg-<?php echo eshSafe(eshBadgeClass($apiError['status'] ?? 'open')); ?>"><?php echo eshSafe($apiError['status'] ?? 'open'); ?></span>
                            </div>
                            <div class="small mt-2 sync-health-message"><?php echo eshSafe($apiError['error_message'] ?? 'Unknown eBay API error'); ?></div>
                            <?php if (!empty($apiError['error_code'])): ?>
                            <div class="small text-muted mt-1">Code: <?php echo eshSafe($apiError['error_code']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm sync-health-card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-screwdriver-wrench text-danger me-2"></i>Failed Items</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 sync-health-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Status</th>
                            <th>Error</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($failedItems)): ?>
                        <tr><td colspan="4" class="text-muted text-center py-4">No failed item history.</td></tr>
                    <?php else: ?>
                        <?php foreach ($failedItems as $item): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo eshSafe($item['product_name'] ?: ('eBay Item ' . $item['ebay_item_id'])); ?></div>
                                <small class="text-muted"><?php echo eshSafe($item['ebay_item_id']); ?><?php echo $item['product_sku'] ? ' · SKU ' . eshSafe($item['product_sku']) : ''; ?></small>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo eshSafe(eshBadgeClass($item['status'] ?? 'open')); ?>"><?php echo eshSafe($item['status'] ?? 'open'); ?></span>
                                <?php if ((int)($item['retry_count'] ?? 0) > 0): ?>
                                <div class="small text-muted mt-1"><?php echo eshNumber($item['retry_count']); ?> retries</div>
                                <?php endif; ?>
                            </td>
                            <td class="small sync-health-message"><?php echo eshSafe($item['error_message'] ?: $item['message']); ?></td>
                            <td class="text-end">
                                <?php if (!empty($item['ebay_url'])): ?>
                                <a class="btn btn-sm btn-outline-secondary mb-1" href="<?php echo eshSafe($item['ebay_url']); ?>" target="_blank" rel="noopener">View</a>
                                <?php endif; ?>
                                <?php if (in_array($item['status'], ['open', 'retry_failed'], true)): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo eshSafe($csrfToken); ?>">
                                    <input type="hidden" name="action" value="retry_item">
                                    <input type="hidden" name="ebay_item_id" value="<?php echo eshSafe($item['ebay_item_id']); ?>">
                                    <button class="btn btn-sm btn-danger">Retry</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm sync-health-card">
            <div class="card-header bg-white d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
                <div>
                    <h5 class="mb-0"><i class="fas fa-eye-slash text-danger me-2"></i>Hidden / Removed Items</h5>
                    <div class="small text-muted">Sold eBay listings are now removed from active inventory during sync.</div>
                </div>
                <?php if (!empty($currentHiddenEbayProducts)): ?>
                <form method="post" class="m-0" onsubmit="return confirm('Remove all currently hidden eBay products from active inventory? This keeps order history intact.');">
                    <input type="hidden" name="csrf_token" value="<?php echo eshSafe($csrfToken); ?>">
                    <input type="hidden" name="action" value="purge_hidden_ebay">
                    <button class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-broom me-1"></i>Purge Hidden eBay
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 sync-health-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($hiddenSoldItems) && empty($currentHiddenEbayProducts)): ?>
                        <tr><td colspan="2" class="text-muted text-center py-4">No hidden or removed item history yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($hiddenSoldItems as $item): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo eshSafe($item['product_name'] ?: ('eBay Item ' . $item['ebay_item_id'])); ?></div>
                                <small class="text-muted"><?php echo eshSafe($item['ebay_item_id']); ?></small>
                            </td>
                            <td>
                                <div><?php echo eshSafe(eshDate($item['created_at'] ?? null)); ?></div>
                                <small class="text-muted"><?php echo eshSafe($item['message'] ?? 'Sold or ended on eBay and removed'); ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!empty($currentHiddenEbayProducts)): ?>
                        <tr>
                            <td colspan="2" class="small text-muted fw-semibold sync-health-subrow">Currently hidden eBay products ready to purge</td>
                        </tr>
                        <?php foreach ($currentHiddenEbayProducts as $product): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo eshSafe($product['name'] ?: ('eBay Item ' . $product['ebay_item_id'])); ?></div>
                                <small class="text-muted"><?php echo eshSafe($product['ebay_item_id']); ?><?php echo $product['sku'] ? ' ? SKU ' . eshSafe($product['sku']) : ''; ?></small>
                            </td>
                            <td>
                                <div><?php echo eshSafe(eshDate($product['updated_at'] ?? $product['created_at'] ?? null)); ?></div>
                                <small class="text-muted">Hidden on website</small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</div>
</div>
</div>

<script src="../public/js/runtime-guard.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="../public/js/timezone.js?v=<?php echo filemtime(__DIR__ . '/../public/js/timezone.js'); ?>"></script>
<script src="../public/js/theme-toggle.js"></script>
<script src="js/pwa-installer.js"></script>
<script>
AOS.init({ duration: 700, once: true });

document.getElementById('sync-ebay-health-btn')?.addEventListener('click', function () {
    const button = this;
    const status = document.getElementById('sync-health-status');
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Syncing...';
    status.innerHTML = '<div class="alert alert-info">Starting eBay sync. This can take a few minutes for large date ranges.</div>';

    fetch('../api/ebay-sync.php?key=<?php echo eshSafe(rawurlencode($syncApiKey)); ?>')
        .then(response => response.json().then(data => ({ ok: response.ok, data })))
        .then(({ ok, data }) => {
            if (!ok || data.error) {
                throw new Error(data.message || data.error || 'eBay sync failed.');
            }

            status.innerHTML = `<div class="alert alert-success">
                <strong>Sync completed.</strong>
                Processed: ${data.processed || 0},
                Added: ${data.added || 0},
                Updated: ${data.updated || 0},
                Failed: ${data.failed || 0},
                Removed: ${data.removed || data.hidden || 0}.
                <a href="ebay-sync-health.php" class="alert-link">Refresh dashboard</a>
            </div>`;
        })
        .catch(error => {
            status.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
        })
        .finally(() => {
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Run Full Sync';
        });
});
</script>
</body>
</html>
