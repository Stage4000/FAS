<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/Timezone.php';
require_once __DIR__ . '/../src/utils/CSRF.php';
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';

use FAS\Config\Database;
use FAS\Utils\CSRF;
use FAS\Utils\ErrorMonitor;
use FAS\Utils\Timezone;

$auth = new AdminAuth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$monitor = new ErrorMonitor($db);
$monitor->ensureTables();

$allowedDays = [1, 7, 30, 90];
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
if (!in_array($days, $allowedDays, true)) {
    $days = 30;
}

$allowedAreas = ['', ErrorMonitor::AREA_CHECKOUT, ErrorMonitor::AREA_PAYPAL, ErrorMonitor::AREA_SHIPPING, ErrorMonitor::AREA_EBAY_SYNC, ErrorMonitor::AREA_ANALYTICS];
$area = $_GET['area'] ?? '';
if (!in_array($area, $allowedAreas, true)) {
    $area = '';
}

$allowedStatuses = ['', 'open', 'resolved'];
$status = $_GET['status'] ?? '';
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'resolve_event') {
            $id = (int)($_POST['event_id'] ?? 0);
            $success = $monitor->markResolved($id) ? 'Error marked resolved.' : 'No open error was updated.';
        } elseif ($action === 'resolve_area') {
            $targetArea = $_POST['area'] ?? '';
            if (in_array($targetArea, $allowedAreas, true) && $targetArea !== '') {
                $count = $monitor->markAreaResolved($targetArea);
                $success = number_format($count) . ' open ' . $targetArea . ' errors marked resolved.';
            }
        }
    }
}

$summary = $monitor->getSummary($days);
$areaCounts = $monitor->getCountsByArea($days);
$events = $monitor->getRecentEvents($days, 100, $area, $status);
$csrfToken = CSRF::generateToken();

function emSafe($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function emNumber($value): string
{
    return number_format((float)$value);
}

function emDate($value): string
{
    if (empty($value)) {
        return 'Never';
    }

    $timestamp = strtotime((string)$value);
    return $timestamp ? Timezone::timestampElement($value) : emSafe($value);
}

function emMetadata(array $event): array
{
    $metadata = json_decode((string)($event['metadata'] ?? ''), true);
    return is_array($metadata) ? $metadata : [];
}

function emMetaValue(array $metadata, string $key)
{
    if (array_key_exists($key, $metadata) && $metadata[$key] !== null && $metadata[$key] !== '') {
        return $metadata[$key];
    }

    $context = $metadata['context'] ?? null;
    if (is_array($context) && array_key_exists($key, $context) && $context[$key] !== null && $context[$key] !== '') {
        return $context[$key];
    }

    return null;
}

function emCompactUrl($value, int $limit = 80): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $parts = parse_url($text);
    if (is_array($parts) && !empty($parts['path'])) {
        $text = $parts['path'] . (!empty($parts['query']) ? '?' . $parts['query'] : '');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function emSourceLocation(array $event, array $metadata): string
{
    $file = emMetaValue($metadata, 'filename') ?? emMetaValue($metadata, 'file') ?? ($event['source'] ?? '');
    $file = emCompactUrl($file, 100);
    if ($file === '') {
        return '';
    }

    $line = emMetaValue($metadata, 'line') ?? emMetaValue($metadata, 'lineno');
    $column = emMetaValue($metadata, 'column') ?? emMetaValue($metadata, 'colno');
    if ($line) {
        $file .= ':' . (int)$line;
        if ($column) {
            $file .= ':' . (int)$column;
        }
    }

    return $file;
}

function emStackPreview(array $metadata): string
{
    $stack = trim((string)(emMetaValue($metadata, 'stack') ?? ''));
    if ($stack === '') {
        return '';
    }

    foreach (preg_split('/\R/', $stack) as $line) {
        $line = trim($line);
        if ($line !== '') {
            return $line;
        }
    }

    return '';
}

function emPrettyJson($value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json === false ? '' : $json;
}

function emAreaLabel(string $area): string
{
    return match ($area) {
        'checkout' => 'Checkout',
        'paypal' => 'PayPal',
        'shipping' => 'Shipping',
        'ebay_sync' => 'eBay Sync',
        'analytics' => 'Analytics',
        default => 'Other',
    };
}

function emAreaIcon(string $area): string
{
    return match ($area) {
        'checkout' => 'fas fa-cart-shopping',
        'paypal' => 'fa-brands fa-paypal',
        'shipping' => 'fas fa-truck-fast',
        'ebay_sync' => 'fa-brands fa-ebay',
        'analytics' => 'fas fa-chart-line',
        default => 'fas fa-circle-exclamation',
    };
}

function emBadgeClass(string $value): string
{
    return match ($value) {
        'critical', 'fatal', 'open' => 'danger',
        'warning' => 'warning',
        'resolved' => 'success',
        'info' => 'info',
        default => 'secondary',
    };
}

function emFilterUrl(array $updates): string
{
    $params = $_GET;
    foreach ($updates as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'error-monitor.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Error Monitor - Admin</title>
<link rel="shortcut icon" href="../gallery/favicons/favicon.png">
<link rel="manifest" href="/admin/manifest.json">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
<link rel="stylesheet" href="css/admin-style.css">
<style>
.error-monitor-stat {
    border: 0;
    border-radius: 1rem;
    box-shadow: 0 0.75rem 1.8rem rgba(15, 23, 42, 0.08);
}
.error-monitor-stat .icon {
    align-items: center;
    border-radius: 999px;
    display: inline-flex;
    height: 44px;
    justify-content: center;
    width: 44px;
}
.error-message-cell {
    max-width: 420px;
}
.error-meta {
    font-size: 0.78rem;
}
.error-detail summary {
    cursor: pointer;
}
.error-detail pre {
    background: rgba(15, 23, 42, 0.06);
    border-radius: 0.4rem;
    margin: 0.35rem 0 0;
    max-height: 220px;
    overflow: auto;
    padding: 0.55rem;
    white-space: pre-wrap;
    word-break: break-word;
}
[data-theme="dark"] .error-monitor-stat,
[data-theme="dark"] .error-monitor-card {
    background: linear-gradient(180deg, #333 0%, #2d2d2d 100%);
    border-color: rgba(255, 255, 255, 0.14) !important;
    color: var(--admin-text);
    box-shadow: 0 0.75rem 1.8rem rgba(0, 0, 0, 0.24) !important;
}
[data-theme="dark"] .error-monitor-stat .text-muted,
[data-theme="dark"] .error-monitor-card .text-muted,
[data-theme="dark"] .error-meta {
    color: #c5cbd3 !important;
}
[data-theme="dark"] .error-monitor-card .card-header {
    background: #333 !important;
    border-bottom-color: rgba(255, 255, 255, 0.14);
    color: var(--admin-text);
}
[data-theme="dark"] .error-monitor-card .form-label {
    color: var(--admin-text);
}
[data-theme="dark"] .error-monitor-card .form-select {
    background-color: #242629;
    border-color: rgba(255, 255, 255, 0.2);
    color: var(--admin-text);
}
[data-theme="dark"] .error-detail pre {
    background: rgba(255, 255, 255, 0.08);
    color: var(--admin-text);
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
    <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
        <div>
            <p class="text-uppercase fw-semibold small mb-2 text-muted">Operations</p>
            <h1 class="display-6 fw-bold mb-2"><i class="fas fa-triangle-exclamation me-2"></i>Error Monitor</h1>
            <p class="mb-0 text-muted">One place for checkout, PayPal, shipping, eBay sync, and analytics failures.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="ebay-sync-health.php" class="btn btn-light text-danger fw-semibold">
                <i class="fab fa-ebay me-2"></i>eBay Sync Health
            </a>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?php echo emSafe($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?php echo emSafe($error); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card error-monitor-stat error-monitor-card">
            <div class="card-body d-flex justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">Total Errors</div>
                    <div class="h3 mb-0"><?php echo emNumber($summary['total']); ?></div>
                </div>
                <span class="icon bg-primary-subtle text-primary"><i class="fas fa-layer-group"></i></span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card error-monitor-stat error-monitor-card">
            <div class="card-body d-flex justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">Open</div>
                    <div class="h3 mb-0 text-danger"><?php echo emNumber($summary['open']); ?></div>
                </div>
                <span class="icon bg-danger-subtle text-danger"><i class="fas fa-fire"></i></span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card error-monitor-stat error-monitor-card">
            <div class="card-body d-flex justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">Critical</div>
                    <div class="h3 mb-0 text-warning"><?php echo emNumber($summary['critical']); ?></div>
                </div>
                <span class="icon bg-warning-subtle text-warning"><i class="fas fa-bolt"></i></span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card error-monitor-stat error-monitor-card">
            <div class="card-body d-flex justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">Resolved</div>
                    <div class="h3 mb-0 text-success"><?php echo emNumber($summary['resolved']); ?></div>
                </div>
                <span class="icon bg-success-subtle text-success"><i class="fas fa-check-circle"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 error-monitor-card">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Date Range</label>
                <select class="form-select" name="days">
                    <?php foreach ($allowedDays as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $days === $option ? 'selected' : ''; ?>>Last <?php echo $option; ?> day<?php echo $option === 1 ? '' : 's'; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Area</label>
                <select class="form-select" name="area">
                    <option value="">All Areas</option>
                    <?php foreach (array_filter($allowedAreas) as $option): ?>
                    <option value="<?php echo emSafe($option); ?>" <?php echo $area === $option ? 'selected' : ''; ?>><?php echo emSafe(emAreaLabel($option)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Statuses</option>
                    <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="resolved" <?php echo $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-danger flex-fill"><i class="fas fa-filter me-2"></i>Filter</button>
                <a href="error-monitor.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-5 g-4 mb-4">
    <?php foreach (array_filter($allowedAreas) as $areaKey): ?>
    <?php
    $areaRow = null;
    foreach ($areaCounts as $countRow) {
        if (($countRow['area'] ?? '') === $areaKey) {
            $areaRow = $countRow;
            break;
        }
    }
    ?>
    <div class="col">
        <a href="<?php echo emSafe(emFilterUrl(['area' => $areaKey])); ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 error-monitor-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <i class="<?php echo emSafe(emAreaIcon($areaKey)); ?> text-danger"></i>
                        <span class="badge bg-<?php echo ((int)($areaRow['open_total'] ?? 0) > 0) ? 'danger' : 'secondary'; ?>"><?php echo emNumber($areaRow['open_total'] ?? 0); ?> open</span>
                    </div>
                    <div class="fw-bold"><?php echo emSafe(emAreaLabel($areaKey)); ?></div>
                    <div class="text-muted small"><?php echo emNumber($areaRow['total'] ?? 0); ?> total</div>
                    <div class="text-muted small">Last: <?php echo emDate($areaRow['last_seen'] ?? null); ?></div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm error-monitor-card">
    <div class="card-header bg-white d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
        <h5 class="mb-0"><i class="fas fa-list text-danger me-2"></i>Recent Error Events</h5>
        <div class="small text-muted">Showing up to 100 events</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Area</th>
                    <th>Severity</th>
                    <th>Error</th>
                    <th>Context</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($events)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No matching errors recorded.</td></tr>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                <?php
                $metadata = emMetadata($event);
                $sourceLocation = emSourceLocation($event, $metadata);
                $stack = trim((string)(emMetaValue($metadata, 'stack') ?? ''));
                $stackPreview = emStackPreview($metadata);
                $detailJson = emPrettyJson($metadata);
                $eventUrl = (string)($event['url'] ?? '');
                $hasBrowserSource = (bool)(emMetaValue($metadata, 'filename') ?? emMetaValue($metadata, 'file'));
                $isOpaqueScriptError = trim((string)($event['message'] ?? '')) === 'Script error.' && !$hasBrowserSource && $stack === '';
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?php echo emDate($event['created_at'] ?? null); ?></div>
                        <small class="text-muted" title="<?php echo emSafe($sourceLocation ?: ($event['source'] ?? 'unknown')); ?>"><?php echo emSafe($sourceLocation ?: ($event['source'] ?? 'unknown')); ?></small>
                    </td>
                    <td><span class="badge bg-secondary"><i class="<?php echo emSafe(emAreaIcon($event['area'] ?? '')); ?> me-1"></i><?php echo emSafe(emAreaLabel($event['area'] ?? '')); ?></span></td>
                    <td><span class="badge bg-<?php echo emSafe(emBadgeClass($event['severity'] ?? 'error')); ?>"><?php echo emSafe($event['severity'] ?? 'error'); ?></span></td>
                    <td class="error-message-cell">
                        <div class="fw-semibold"><?php echo emSafe($event['message'] ?? 'Unknown error'); ?></div>
                        <?php if (!empty($event['exception_class'])): ?>
                        <div class="text-muted small"><?php echo emSafe($event['exception_class']); ?></div>
                        <?php endif; ?>
                        <?php if ($sourceLocation !== ''): ?>
                        <div class="text-muted small"><i class="fas fa-code me-1"></i><?php echo emSafe($sourceLocation); ?></div>
                        <?php endif; ?>
                        <?php if ($stackPreview !== ''): ?>
                        <div class="text-muted small text-truncate" title="<?php echo emSafe($stackPreview); ?>"><i class="fas fa-layer-group me-1"></i><?php echo emSafe($stackPreview); ?></div>
                        <?php endif; ?>
                        <?php if ($isOpaqueScriptError): ?>
                        <div class="text-warning small"><i class="fas fa-triangle-exclamation me-1"></i>Browser hid script details. Check the page URL and third-party scripts loaded there.</div>
                        <?php endif; ?>
                    </td>
                    <td class="error-meta">
                        <?php if (!empty($event['order_id'])): ?><div>Order: <?php echo emSafe($event['order_id']); ?></div><?php endif; ?>
                        <?php if (!empty($event['paypal_order_id'])): ?><div>PayPal: <?php echo emSafe($event['paypal_order_id']); ?></div><?php endif; ?>
                        <?php if (!empty($event['product_id'])): ?><div>Product: <?php echo (int)$event['product_id']; ?></div><?php endif; ?>
                        <?php if (!empty($event['ebay_item_id'])): ?><div>eBay: <?php echo emSafe($event['ebay_item_id']); ?></div><?php endif; ?>
                        <?php if ($eventUrl !== ''): ?>
                        <div class="text-truncate" style="max-width: 260px;" title="<?php echo emSafe($eventUrl); ?>">
                            <?php if (preg_match('/^https?:\/\//i', $eventUrl)): ?>
                            <a href="<?php echo emSafe($eventUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo emSafe(emCompactUrl($eventUrl, 90)); ?></a>
                            <?php else: ?>
                            <?php echo emSafe(emCompactUrl($eventUrl, 90)); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <details class="error-detail mt-1">
                            <summary>Details</summary>
                            <div class="mt-2">
                                <div><span class="text-muted">Event:</span> #<?php echo (int)$event['id']; ?></div>
                                <?php if (!empty($event['source'])): ?><div><span class="text-muted">Logger:</span> <?php echo emSafe($event['source']); ?></div><?php endif; ?>
                                <?php if ($sourceLocation !== ''): ?><div><span class="text-muted">Source:</span> <?php echo emSafe($sourceLocation); ?></div><?php endif; ?>
                                <?php if (!empty($metadata['page'])): ?><div><span class="text-muted">Page:</span> <?php echo emSafe($metadata['page']); ?></div><?php endif; ?>
                                <?php if (!empty($event['session_id'])): ?><div><span class="text-muted">Session:</span> <?php echo emSafe($event['session_id']); ?></div><?php endif; ?>
                                <?php if (!empty($event['request_method'])): ?><div><span class="text-muted">Method:</span> <?php echo emSafe($event['request_method']); ?></div><?php endif; ?>
                                <?php if (!empty($event['ip_address'])): ?><div><span class="text-muted">IP:</span> <?php echo emSafe($event['ip_address']); ?></div><?php endif; ?>
                                <?php if (!empty($event['user_agent'])): ?><div><span class="text-muted">User agent:</span> <?php echo emSafe($event['user_agent']); ?></div><?php endif; ?>
                                <?php if ($stack !== ''): ?><div class="mt-2"><span class="text-muted">Stack:</span><pre><?php echo emSafe($stack); ?></pre></div><?php endif; ?>
                                <?php if ($detailJson !== '' && $detailJson !== '[]'): ?><div class="mt-2"><span class="text-muted">Metadata:</span><pre><?php echo emSafe($detailJson); ?></pre></div><?php endif; ?>
                            </div>
                        </details>
                    </td>
                    <td class="text-end">
                        <?php if (($event['status'] ?? 'open') === 'open'): ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo emSafe($csrfToken); ?>">
                            <input type="hidden" name="action" value="resolve_event">
                            <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                            <button class="btn btn-sm btn-outline-success">Resolve</button>
                        </form>
                        <?php else: ?>
                        <span class="badge bg-success">Resolved</span>
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
</div>
</div>

<script src="../public/js/runtime-guard.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="../public/js/timezone.js?v=<?php echo filemtime(__DIR__ . '/../public/js/timezone.js'); ?>"></script>
<script src="../public/js/theme-toggle.js"></script>
<script src="js/pwa-installer.js"></script>
<script>AOS.init({ duration: 700, once: true });</script>
</body>
</html>
