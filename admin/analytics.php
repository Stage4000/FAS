<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/Analytics.php';

$auth = new AdminAuth();
$auth->requireLogin();

use FAS\Config\Database;
use FAS\Utils\Analytics;

$db = Database::getInstance()->getConnection();
$analytics = new Analytics($db);
$analytics->ensureTables();

$allowedDays = [7, 30, 90, 365];
$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
if (!in_array($days, $allowedDays, true)) {
    $days = 30;
}

$overview = $analytics->getOverview($days);
$topPages = $analytics->getTopPages($days, 12);
$exitPages = $analytics->getExitPages($days, 12);
$topProducts = $analytics->getTopProducts($days, 12);
$trafficSources = $analytics->getTrafficSources($days, 12);
$searchTerms = $analytics->getSearchTerms($days, 12);
$recentEvents = $analytics->getRecentEvents(30);

function fmtNumber($value): string
{
    return number_format((float) $value);
}

function fmtMoney($value): string
{
    return '$' . number_format((float) $value, 2);
}

function fmtSeconds($seconds): string
{
    $seconds = max(0, (int) $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }

    return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
}

function safe($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function percentageBar($value): string
{
    $width = max(0, min(100, (float) $value));
    return '<div class="progress" style="height: 6px;"><div class="progress-bar bg-danger" style="width: ' . $width . '%"></div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Flip and Strip Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="manifest" href="/admin/manifest.json">
    <meta name="theme-color" content="#db0335">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FAS Admin">
    <link rel="apple-touch-icon" href="/gallery/favicons/favicon-180x180.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <div class="d-flex justify-content-between align-items-center mb-4" data-aos="fade-down">
        <div>
            <h1 class="mb-1"><i class="fas fa-chart-line me-2"></i>Analytics</h1>
            <p class="text-muted mb-0">First-party traffic, product, cart, checkout, and exit tracking.</p>
        </div>
        <form method="get" class="d-flex align-items-center gap-2">
            <label for="days" class="form-label mb-0">Range</label>
            <select class="form-select" id="days" name="days" onchange="this.form.submit()">
                <?php foreach ($allowedDays as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $days === $option ? 'selected' : ''; ?>>
                        Last <?php echo $option; ?> days
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Sessions</div>
                    <div class="display-6 fw-bold"><?php echo fmtNumber($overview['sessions']); ?></div>
                    <div class="small text-muted"><?php echo fmtNumber($overview['visitors']); ?> unique visitors</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Page Views</div>
                    <div class="display-6 fw-bold"><?php echo fmtNumber($overview['page_views']); ?></div>
                    <div class="small text-muted">Avg session <?php echo fmtSeconds($overview['avg_duration']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Cart Adds</div>
                    <div class="display-6 fw-bold"><?php echo fmtNumber($overview['cart_adds']); ?></div>
                    <div class="small text-muted"><?php echo safe($overview['cart_rate']); ?>% of sessions</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Orders</div>
                    <div class="display-6 fw-bold"><?php echo fmtNumber($overview['orders']); ?></div>
                    <div class="small text-muted"><?php echo fmtMoney($overview['revenue']); ?> revenue</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Sales Funnel</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2">
                    <div class="text-muted small">Sessions</div>
                    <h4><?php echo fmtNumber($overview['sessions']); ?></h4>
                    <?php echo percentageBar(100); ?>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Product Views</div>
                    <h4><?php echo fmtNumber($overview['product_views']); ?></h4>
                    <?php echo percentageBar($overview['sessions'] ? ($overview['product_views'] / $overview['sessions']) * 100 : 0); ?>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Cart Adds</div>
                    <h4><?php echo fmtNumber($overview['cart_adds']); ?></h4>
                    <?php echo percentageBar($overview['cart_rate']); ?>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Cart Changes</div>
                    <h4><?php echo fmtNumber($overview['cart_changes']); ?></h4>
                    <?php echo percentageBar($overview['sessions'] ? ($overview['cart_changes'] / $overview['sessions']) * 100 : 0); ?>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Checkouts</div>
                    <h4><?php echo fmtNumber($overview['checkout_starts']); ?></h4>
                    <?php echo percentageBar($overview['checkout_rate']); ?>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Orders</div>
                    <h4><?php echo fmtNumber($overview['orders']); ?></h4>
                    <?php echo percentageBar($overview['order_rate']); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h5 class="mb-0">Top Pages</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Page</th><th class="text-end">Views</th><th class="text-end">Sessions</th></tr></thead>
                        <tbody>
                        <?php foreach ($topPages as $row): ?>
                            <tr>
                                <td class="text-break"><?php echo safe($row['page_path']); ?></td>
                                <td class="text-end"><?php echo fmtNumber($row['views']); ?></td>
                                <td class="text-end"><?php echo fmtNumber($row['sessions']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topPages)): ?><tr><td colspan="3" class="text-muted">No page views tracked yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h5 class="mb-0">Exit Pages</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Page</th><th class="text-end">Exits</th><th class="text-end">Avg Time</th></tr></thead>
                        <tbody>
                        <?php foreach ($exitPages as $row): ?>
                            <tr>
                                <td class="text-break"><?php echo safe($row['page_path']); ?></td>
                                <td class="text-end"><?php echo fmtNumber($row['exits']); ?></td>
                                <td class="text-end"><?php echo fmtSeconds($row['avg_seconds']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($exitPages)): ?><tr><td colspan="3" class="text-muted">No exits tracked yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h5 class="mb-0">Product Performance</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Impressions</th>
                                <th class="text-end">Views</th>
                                <th class="text-end">Cart Adds</th>
                                <th class="text-end">Removes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topProducts as $row): ?>
                            <tr>
                                <td class="text-break">
                                    <?php if (!empty($row['product_id'])): ?>
                                        <a href="../product/<?php echo urlencode($row['product_id']); ?>" target="_blank" rel="noopener">
                                            <?php echo safe($row['product_name']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo safe($row['product_name']); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo fmtNumber($row['impressions']); ?></td>
                                <td class="text-end"><?php echo fmtNumber($row['views']); ?></td>
                                <td class="text-end"><?php echo fmtNumber($row['cart_adds']); ?></td>
                                <td class="text-end"><?php echo fmtNumber($row['cart_removes']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topProducts)): ?><tr><td colspan="5" class="text-muted">No product events tracked yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h5 class="mb-0">Traffic Sources</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Source</th><th class="text-end">Sessions</th></tr></thead>
                        <tbody>
                        <?php foreach ($trafficSources as $row): ?>
                            <tr>
                                <td class="text-break"><?php echo safe($row['source']); ?></td>
                                <td class="text-end"><?php echo fmtNumber($row['sessions']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($trafficSources)): ?><tr><td colspan="2" class="text-muted">No sessions tracked yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h5 class="mb-0">Top Searches</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Search Term</th><th class="text-end">Searches</th></tr></thead>
                        <tbody>
                        <?php foreach ($searchTerms as $row): ?>
                            <tr>
                                <td><?php echo safe($row['search_term']); ?></td>
                                <td class="text-end"><?php echo fmtNumber($row['searches']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($searchTerms)): ?><tr><td colspan="2" class="text-muted">No searches tracked yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h5 class="mb-0">Recent Events</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Time</th><th>Event</th><th>Page/Product</th><th class="text-end">Value</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentEvents as $row): ?>
                            <tr>
                                <td class="text-nowrap"><?php echo safe($row['created_at']); ?></td>
                                <td><?php echo safe($row['event_type']); ?></td>
                                <td class="text-break"><?php echo safe($row['product_name'] ?: $row['page_path']); ?></td>
                                <td class="text-end"><?php echo fmtMoney($row['cart_value']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentEvents)): ?><tr><td colspan="4" class="text-muted">No events tracked yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
