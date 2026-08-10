<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/Analytics.php';

use FAS\Config\Database;
use FAS\Utils\Analytics;

$auth = new AdminAuth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$analytics = new Analytics($db);
$analytics->ensureTables();

$allowedDays = [7, 30, 90, 365];
$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
if (!in_array($days, $allowedDays, true)) {
    $days = 30;
}

$overview = $analytics->getOverview($days);
$funnel = $analytics->getFunnel($days);
$checkoutDropoff = $analytics->getCheckoutDropoff($days);
$abandonedCarts = $analytics->getAbandonedCarts($days, 12);
$ebayLinkClicks = $analytics->getEbayLinkClicks($days, 12);
$topProducts = $analytics->getTopProducts($days, 12);
$couponPerformance = $analytics->getCouponPerformance($days, 12);
$bannerPerformance = $analytics->getBannerPerformance($days, 12);
$revenueByCategory = $analytics->getRevenueByCategory($days, 10);
$topPages = $analytics->getTopPages($days, 10);
$trafficSources = $analytics->getTrafficSources($days, 10);
$searchTerms = $analytics->getSearchTerms($days, 10);
$recentEvents = $analytics->getRecentEvents(30);
$recentSessions = $analytics->getRecentSessions($days, 50);
$selectedSessionId = isset($_GET['session']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['session']) : '';
$selectedSession = $selectedSessionId !== '' ? $analytics->getSessionSummary($selectedSessionId) : null;
$selectedSessionEvents = ($selectedSessionId !== '' && $selectedSession) ? $analytics->getSessionEvents($selectedSessionId, 250) : [];
$averageOrderValue = ((int) ($overview['orders'] ?? 0)) > 0 ? ((float) ($overview['revenue'] ?? 0) / (int) $overview['orders']) : 0;
$revenuePerSession = ((int) ($overview['sessions'] ?? 0)) > 0 ? ((float) ($overview['revenue'] ?? 0) / (int) $overview['sessions']) : 0;
$abandonedCartRate = (((int) ($overview['abandoned_carts'] ?? 0)) + ((int) ($overview['orders'] ?? 0))) > 0
    ? round((((int) $overview['abandoned_carts']) / (((int) $overview['abandoned_carts']) + ((int) $overview['orders']))) * 100, 1)
    : null;
$sessionExplorerStats = [
    'sessions' => count($recentSessions),
    'potential_bots' => 0,
    'human_sessions' => 0,
    'total_length_seconds' => 0,
    'cart_value' => 0.0,
    'checkout_starts' => 0,
];

foreach ($recentSessions as $sessionRow) {
    $sessionLength = (int) (($sessionRow['max_session_age_seconds'] ?? 0) ?: ($sessionRow['duration_seconds'] ?? 0));
    $sessionExplorerStats['total_length_seconds'] += $sessionLength;
    $sessionExplorerStats['cart_value'] += (float) ($sessionRow['cart_value'] ?? 0);
    $sessionExplorerStats['checkout_starts'] += (int) ($sessionRow['checkout_starts'] ?? 0);

    if ((int) ($sessionRow['is_potential_bot'] ?? 0) === 1) {
        $sessionExplorerStats['potential_bots']++;
    } else {
        $sessionExplorerStats['human_sessions']++;
    }
}

$sessionExplorerStats['avg_length_seconds'] = $sessionExplorerStats['sessions'] > 0
    ? (int) round($sessionExplorerStats['total_length_seconds'] / $sessionExplorerStats['sessions'])
    : 0;


function fmtNumber($value): string
{
    return number_format((float) $value);
}

function fmtMoney($value): string
{
    return '$' . number_format((float) $value, 2);
}

function fmtPercent($value): string
{
    return $value === null ? '—' : number_format((float) $value, 1) . '%';
}

function fmtSeconds($seconds): string
{
    $seconds = max(0, (int) $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }

    return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
}

function compactLabel(array $parts): string
{
    $labels = [];
    foreach ($parts as $part) {
        $label = trim((string) $part);
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    return implode(' · ', $labels);
}

function sessionGeoLabel(array $session): string
{
    $region = $session['cf_region_code'] ?? '';
    if ($region === '') {
        $region = $session['cf_region'] ?? '';
    }

    return compactLabel([
        $session['cf_city'] ?? '',
        $region,
        $session['cf_country'] ?? '',
    ]) ?: 'Unknown';
}

function sessionBotLabel(array $session): string
{
    if ((int) ($session['is_potential_bot'] ?? 0) === 1) {
        return trim((string) ($session['bot_reason'] ?? '')) ?: 'Potential bot';
    }

    if (($session['cf_bot_score'] ?? null) !== null && $session['cf_bot_score'] !== '') {
        return 'No bot signal - CF ' . (int) $session['cf_bot_score'];
    }

    return 'No bot signal';
}

function sessionBotBadgeClass(array $session): string
{
    return ((int) ($session['is_potential_bot'] ?? 0) === 1) ? 'text-bg-warning' : 'text-bg-light';
}

function sessionIpSourceLabel(array $session): string
{
    $source = (string) ($session['client_ip_source'] ?? '');
    $labels = [
        'cloudflare_connecting_ip' => 'Cloudflare visitor IP',
        'cloudflare_connecting_ipv6' => 'Cloudflare visitor IPv6',
        'cloudflare_true_client_ip' => 'Cloudflare True-Client-IP',
        'cloudflare_proxy_remote_addr' => 'Cloudflare edge IP only',
        'cloudflare_headers_missing_ip' => 'Cloudflare IP header missing',
        'x_forwarded_for' => 'X-Forwarded-For',
        'x_real_ip' => 'X-Real-IP',
        'true_client_ip' => 'True-Client-IP',
        'remote_addr' => 'Server remote address',
    ];

    return $labels[$source] ?? ($source !== '' ? str_replace('_', ' ', $source) : 'Unknown');
}

function safe($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function metricCard(string $label, string $value, string $note, string $icon): string
{
    return '
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small">' . safe($label) . '</span>
                        <i class="fas ' . safe($icon) . ' text-danger"></i>
                    </div>
                    <h3 class="mb-1">' . safe($value) . '</h3>
                    <div class="small text-muted">' . safe($note) . '</div>
                </div>
            </div>
        </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics - Flip and Strip Admin</title>
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
    <style>
        .analytics-hero {
            background: linear-gradient(135deg, #1f1f24 0%, #3b0b16 48%, #db0335 100%);
            border-radius: 1.25rem;
            color: #fff;
            overflow: hidden;
            position: relative;
        }
        .analytics-hero::after {
            background: radial-gradient(circle at top right, rgba(255, 255, 255, .22), transparent 32rem);
            content: "";
            inset: 0;
            position: absolute;
        }
        .analytics-hero > * {
            position: relative;
            z-index: 1;
        }
        .table-fixed {
            min-width: 720px;
        }
        .progress-thin {
            height: .45rem;
        }
        .analytics-mini-card {
            background: linear-gradient(180deg, #ffffff 0%, #fbfbfc 100%);
            border: 1px solid rgba(31, 31, 36, .08);
            border-radius: 1rem;
            box-shadow: 0 .5rem 1.25rem rgba(31, 31, 36, .04);
        }
        .analytics-mini-card .analytics-icon {
            align-items: center;
            background: rgba(219, 3, 53, .1);
            border-radius: .85rem;
            color: #db0335;
            display: inline-flex;
            height: 2.25rem;
            justify-content: center;
            width: 2.25rem;
        }
        .analytics-session-search {
            min-width: min(100%, 28rem);
        }
        .analytics-session-table {
            min-width: 1080px;
        }
        .analytics-session-row {
            cursor: pointer;
            transition: background-color .15s ease, box-shadow .15s ease;
        }
        .analytics-session-row:hover td,
        .analytics-session-row:focus-within td {
            background-color: #fff7f9;
        }
        .analytics-session-id {
            background: transparent;
            border: 0;
            color: #b8022d;
            font: inherit;
            font-weight: 700;
            padding: 0;
            text-align: left;
        }
        .analytics-session-id:hover,
        .analytics-session-id:focus {
            color: #db0335;
            text-decoration: underline;
        }
        .analytics-action-cell {
            background: #fff;
            box-shadow: -10px 0 16px rgba(31, 31, 36, .06);
            position: sticky;
            right: 0;
            z-index: 2;
        }
        .analytics-session-row:hover .analytics-action-cell,
        .analytics-session-row:focus-within .analytics-action-cell {
            background-color: #fff7f9;
        }
        .analytics-event-badge {
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: .01em;
        }
        .analytics-modal-panel {
            background: #fff;
            border: 1px solid rgba(31, 31, 36, .08);
            border-radius: 1rem;
        }
        @media (max-width: 991.98px) {
            .analytics-session-search {
                width: 100%;
            }
            .analytics-session-search .input-group {
                width: 100%;
            }
        }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="analytics-hero p-4 p-lg-5 mb-4 shadow-sm" data-aos="fade-down">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
            <p class="text-white-50 text-uppercase fw-semibold small mb-2">Website Sales Analytics</p>
            <h1 class="display-6 fw-bold mb-2">Conversion and merchandising dashboard</h1>
            <p class="mb-0 text-white-50">Tracks traffic quality, product demand, cart behavior, checkout friction, coupons, and completed-order revenue.</p>
        </div>
<form method="get" class="align-self-lg-start">
<label for="days" class="form-label text-white-50 small mb-1">Reporting range</label>
<?php if ($selectedSessionId !== ''): ?>
<input type="hidden" name="session" value="<?php echo safe($selectedSessionId); ?>">
<?php endif; ?>
<select class="form-select" id="days" name="days" onchange="this.form.submit()">
                <?php foreach ($allowedDays as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $days === $option ? 'selected' : ''; ?>>
                        Last <?php echo $option; ?> days
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    echo metricCard('Sessions', fmtNumber($overview['sessions']), fmtNumber($overview['visitors']) . ' visitors', 'fa-users');
    echo metricCard('Product Views', fmtNumber($overview['product_views']), fmtNumber($overview['product_impressions']) . ' impressions', 'fa-eye');
    echo metricCard('Add To Cart', fmtNumber($overview['cart_adds']), fmtPercent($overview['product_view_to_cart_rate']) . ' view-to-cart', 'fa-cart-plus');
    echo metricCard('Checkout Starts', fmtNumber($overview['checkout_starts']), fmtPercent($overview['cart_to_checkout_rate']) . ' cart-to-checkout', 'fa-credit-card');
    echo metricCard('Orders', fmtNumber($overview['orders']), fmtPercent($overview['checkout_to_order_rate']) . ' checkout-to-order', 'fa-receipt');
    echo metricCard('Revenue', fmtMoney($overview['revenue']), 'Completed orders', 'fa-dollar-sign');
    echo metricCard('Abandoned Carts', fmtNumber($overview['abandoned_carts']), fmtMoney($overview['abandoned_cart_value']) . ' at risk', 'fa-cart-arrow-down');
    echo metricCard('eBay Exits', fmtNumber($overview['ebay_link_clicks']), 'Outbound eBay clicks', 'fa-up-right-from-square');
    ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="analytics-mini-card p-3 h-100">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                <div>
                    <div class="small text-muted">Funnel Health</div>
                    <div class="h5 mb-0"><?php echo fmtPercent($overview['product_view_to_cart_rate']); ?> view-to-cart</div>
                </div>
                <span class="analytics-icon"><i class="fas fa-filter-circle-dollar"></i></span>
            </div>
            <div class="small text-muted">
                <?php echo fmtPercent($overview['cart_to_checkout_rate']); ?> cart-to-checkout &middot;
                <?php echo fmtPercent($overview['checkout_to_order_rate']); ?> checkout-to-order
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="analytics-mini-card p-3 h-100">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                <div>
                    <div class="small text-muted">Revenue Quality</div>
                    <div class="h5 mb-0"><?php echo fmtMoney($averageOrderValue); ?> avg order</div>
                </div>
                <span class="analytics-icon"><i class="fas fa-chart-line"></i></span>
            </div>
            <div class="small text-muted"><?php echo fmtMoney($revenuePerSession); ?> revenue per tracked session</div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="analytics-mini-card p-3 h-100">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                <div>
                    <div class="small text-muted">Recovery Opportunity</div>
                    <div class="h5 mb-0"><?php echo fmtMoney($overview['abandoned_cart_value']); ?> at risk</div>
                </div>
                <span class="analytics-icon"><i class="fas fa-cart-arrow-down"></i></span>
            </div>
            <div class="small text-muted">
                <?php echo fmtNumber($overview['abandoned_carts']); ?> abandoned carts
                <?php if ($abandonedCartRate !== null): ?>
                    &middot; <?php echo fmtPercent($abandonedCartRate); ?> of carts with outcome
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4" id="session-explorer">
    <div class="col-12">
        <div class="card border-0 shadow-sm" data-aos="fade-up">
            <div class="card-header bg-white d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <h5 class="mb-1"><i class="fas fa-timeline text-danger me-2"></i>Session Explorer</h5>
                    <div class="small text-muted">Open any session to inspect visitor context, cart/revenue activity, bot signals, and chronological actions.</div>
                </div>
                <form method="get" class="analytics-session-search">
                    <input type="hidden" name="days" value="<?php echo $days; ?>">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="session" class="form-control" placeholder="Paste a session ID" value="<?php echo safe($selectedSessionId); ?>" aria-label="Session ID">
                        <button class="btn btn-danger" type="submit"><i class="fas fa-up-right-from-square me-1"></i>Open Session</button>
                    </div>
                </form>
            </div>
            <div class="card-body">
                <?php if ($selectedSessionId !== '' && !$selectedSession): ?>
                    <div class="alert alert-warning small">No session found for <code><?php echo safe($selectedSessionId); ?></code>.</div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-xl-3">
                        <div class="analytics-mini-card p-3 h-100">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="small text-muted">Sessions Shown</div>
                                    <div class="h4 mb-0"><?php echo fmtNumber($sessionExplorerStats['sessions']); ?></div>
                                    <div class="small text-muted"><?php echo fmtNumber($sessionExplorerStats['human_sessions']); ?> likely human</div>
                                </div>
                                <span class="analytics-icon"><i class="fas fa-users"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="analytics-mini-card p-3 h-100">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="small text-muted">Avg Active Time</div>
                                    <div class="h4 mb-0"><?php echo fmtSeconds($sessionExplorerStats['avg_length_seconds']); ?></div>
                                    <div class="small text-muted">Based on quiet heartbeats</div>
                                </div>
                                <span class="analytics-icon"><i class="fas fa-stopwatch"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="analytics-mini-card p-3 h-100">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="small text-muted">Cart Value In Sessions</div>
                                    <div class="h4 mb-0"><?php echo fmtMoney($sessionExplorerStats['cart_value']); ?></div>
                                    <div class="small text-muted"><?php echo fmtNumber($sessionExplorerStats['checkout_starts']); ?> checkout starts</div>
                                </div>
                                <span class="analytics-icon"><i class="fas fa-cart-shopping"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="analytics-mini-card p-3 h-100">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="small text-muted">Potential Bots</div>
                                    <div class="h4 mb-0"><?php echo fmtNumber($sessionExplorerStats['potential_bots']); ?></div>
                                    <div class="small text-muted">Flagged for review</div>
                                </div>
                                <span class="analytics-icon"><i class="fas fa-robot"></i></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-2">
                    <div>
                        <h6 class="fw-bold mb-0">Recent Sessions</h6>
                        <div class="small text-muted">Click a session ID, row, or Open button to view the full session timeline.</div>
                    </div>
                    <span class="badge text-bg-light border">Last <?php echo $days; ?> days &middot; <?php echo fmtNumber(count($recentSessions)); ?> shown</span>
                </div>
                <div class="table-responsive mb-4 border rounded">
                    <table class="table table-sm align-middle analytics-session-table mb-0">
                        <thead>
                            <tr>
                                <th>Session</th>
                                <th>Location</th>
                                <th>Bot Signal</th>
                                <th class="text-end">Length</th>
                                <th class="text-end">Events</th>
                                <th class="text-end">Cart</th>
                                <th class="text-end">Revenue</th>
                                <th>Last Seen</th>
                            <th class="text-end analytics-action-cell">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentSessions as $row): ?>
                                <?php
                                $sessionLength = (int) (($row['max_session_age_seconds'] ?? 0) ?: ($row['duration_seconds'] ?? 0));
                                ?>
                        <tr class="analytics-session-row <?php echo $selectedSessionId === ($row['session_id'] ?? '') ? 'table-light' : ''; ?>" data-session-id="<?php echo safe($row['session_id']); ?>" tabindex="0" role="button" aria-label="Open analytics session <?php echo safe($row['session_id']); ?>">
                            <td class="text-break">
                                <button type="button" class="analytics-session-id js-session-view" data-session-id="<?php echo safe($row['session_id']); ?>">
                                    <?php echo safe($row['session_id']); ?>
                                </button>
                                <div class="small text-muted"><?php echo safe($row['visitor_id'] ?? ''); ?></div>
                            </td>
                                    <td>
                                        <div><?php echo safe(sessionGeoLabel($row)); ?></div>
                                        <div class="small text-muted"><?php echo safe($row['client_ip'] ?? 'IP unknown'); ?> &middot; <?php echo safe(sessionIpSourceLabel($row)); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo sessionBotBadgeClass($row); ?>"><?php echo safe(sessionBotLabel($row)); ?></span>
                                    </td>
                                    <td class="text-end text-nowrap"><?php echo fmtSeconds($sessionLength); ?></td>
                                    <td class="text-end">
                                        <div><?php echo fmtNumber($row['events'] ?? 0); ?></div>
                                        <div class="small text-muted"><?php echo fmtNumber($row['page_views'] ?? 0); ?> pages</div>
                                    </td>
                                    <td class="text-end">
                                        <div><?php echo fmtMoney($row['cart_value'] ?? 0); ?></div>
                                        <div class="small text-muted"><?php echo fmtNumber($row['cart_adds'] ?? 0); ?> adds &middot; <?php echo fmtNumber($row['checkout_starts'] ?? 0); ?> checkout</div>
                                    </td>
                                    <td class="text-end">
                                        <div><?php echo fmtMoney($row['revenue'] ?? 0); ?></div>
                                        <div class="small text-muted"><?php echo fmtNumber($row['purchases'] ?? 0); ?> orders</div>
                                    </td>
                                    <td class="text-nowrap">
                                        <div><?php echo safe($row['last_seen_at'] ?? ''); ?></div>
                                        <div class="small text-muted"><?php echo safe($row['landing_page'] ?? ''); ?></div>
                                    </td>
                            <td class="text-end analytics-action-cell">
                                <button type="button" class="btn btn-sm btn-danger js-session-view" data-session-id="<?php echo safe($row['session_id']); ?>">
                                    <i class="fas fa-up-right-from-square me-1"></i>Open
                                </button>
                            </td>
                        </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentSessions)): ?>
                                <tr><td colspan="9" class="text-muted">No recent sessions recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>


            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-filter text-danger me-2"></i>Sales Funnel</h5>
                <span class="badge text-bg-light">Distinct sessions</span>
            </div>
            <div class="card-body table-responsive">
                <table class="table align-middle table-fixed">
                    <thead>
                    <tr>
                        <th>Stage</th>
                        <th class="text-end">Count</th>
                        <th class="text-end">From Previous</th>
                        <th class="text-end">Drop-Off</th>
                        <th style="width: 24%">Progress</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($funnel as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo safe($row['stage']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['count']); ?></td>
                            <td class="text-end"><?php echo fmtPercent($row['conversion_rate']); ?></td>
                            <td class="text-end"><?php echo $row['dropoff'] === null ? '—' : fmtNumber($row['dropoff']) . ' / ' . fmtPercent($row['dropoff_rate']); ?></td>
                            <td>
                                <div class="progress progress-thin">
                                    <div class="progress-bar bg-danger" style="width: <?php echo $row['conversion_rate'] === null ? 100 : max(0, min(100, (float) $row['conversion_rate'])); ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-truck text-danger me-2"></i>Checkout Drop-Off</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Step</th>
                        <th class="text-end">Sessions</th>
                        <th class="text-end">Drop-Off</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($checkoutDropoff as $row): ?>
                        <tr>
                            <td><?php echo safe($row['stage']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['count']); ?></td>
                            <td class="text-end"><?php echo $row['dropoff'] === null ? '—' : fmtPercent($row['dropoff_rate']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-cart-arrow-down text-danger me-2"></i>Abandoned Carts</h5>
                <span class="badge text-bg-light">Inactive 30+ minutes</span>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle table-fixed">
                    <thead>
                    <tr>
                        <th>Last Page / Source</th>
                        <th>Products</th>
                        <th class="text-end">Items</th>
                        <th class="text-end">Value</th>
                        <th class="text-end">Last Activity</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($abandonedCarts as $row): ?>
                        <tr>
                            <td class="text-break">
                                <div class="fw-semibold"><?php echo safe($row['last_page'] ?: $row['page_path']); ?></div>
                                <div class="small text-muted"><?php echo safe($row['source']); ?></div>
                            </td>
                            <td class="text-break">
                                <?php if (!empty($row['products'])): ?>
                                    <?php foreach ($row['products'] as $product): ?>
                                        <div><?php echo safe($product['product_name']); ?></div>
                                        <div class="small text-muted mb-1">
                                            <?php
                                            echo safe(compactLabel([
                                                $product['product_sku'] ?? '',
                                                $product['condition_name'] ?? '',
                                                !empty($product['stock_quantity']) ? 'Stock ' . (int) $product['stock_quantity'] : '',
                                                $product['category'] ?? '',
                                            ]));
                                            ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">Cart contents unavailable</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo fmtNumber($row['cart_items_count']); ?></td>
                            <td class="text-end"><?php echo fmtMoney($row['cart_value']); ?></td>
                            <td class="text-end text-nowrap"><?php echo safe($row['last_activity']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($abandonedCarts)): ?>
                        <tr><td colspan="5" class="text-muted">No abandoned carts detected for this range.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-up-right-from-square text-danger me-2"></i>eBay Outbound Clicks</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Clicked From</th>
                        <th>Destination</th>
                        <th class="text-end">Clicks</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ebayLinkClicks as $row): ?>
                        <tr>
                            <td class="text-break">
                                <div class="fw-semibold"><?php echo safe($row['product_name'] ?: $row['page_path']); ?></div>
                                <div class="small text-muted"><?php echo safe($row['product_sku'] ?: $row['link_text']); ?></div>
                            </td>
                            <td class="text-break">
                                <div><?php echo safe($row['target_host']); ?></div>
                                <div class="small text-muted"><?php echo safe($row['target_url']); ?></div>
                            </td>
                            <td class="text-end"><?php echo fmtNumber($row['clicks']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ebayLinkClicks)): ?>
                        <tr><td colspan="3" class="text-muted">No eBay outbound clicks tracked yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white">
<h5 class="mb-0"><i class="fas fa-box text-danger me-2"></i>Trending Products</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle table-fixed">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-end">Views</th>
                        <th class="text-end">Carts</th>
                        <th class="text-end">Orders</th>
                        <th class="text-end">Revenue</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topProducts as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-break"><?php echo safe($row['product_name'] ?: 'Unknown product'); ?></div>
                                <div class="small text-muted"><?php echo safe($row['product_sku'] ?: $row['product_id']); ?></div>
                            </td>
                            <td>
                                <div><?php echo safe($row['category'] ?: 'Uncategorized'); ?></div>
                                <div class="small text-muted"><?php echo safe($row['manufacturer'] ?: $row['product_source']); ?></div>
                            </td>
                            <td class="text-end"><?php echo fmtNumber($row['views']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['cart_adds']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['purchases']); ?></td>
                            <td class="text-end"><?php echo fmtMoney($row['revenue']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($topProducts)): ?>
                        <tr><td colspan="6" class="text-muted">No product activity tracked yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-layer-group text-danger me-2"></i>Revenue By Category</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Category</th>
                        <th class="text-end">Orders</th>
                        <th class="text-end">Revenue</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($revenueByCategory as $row): ?>
                        <tr>
                            <td><?php echo safe($row['category']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['orders']); ?></td>
                            <td class="text-end"><?php echo fmtMoney($row['revenue']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($revenueByCategory)): ?>
                        <tr><td colspan="3" class="text-muted">No completed-order revenue yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
</div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm" data-aos="fade-up">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-bullhorn text-danger me-2"></i>Banner / Campaign Performance</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Link</th>
                            <th class="text-end">Views</th>
                            <th class="text-end">Clicks</th>
                            <th class="text-end">CTR</th>
                            <th class="text-end">Last Activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bannerPerformance as $row): ?>
                        <?php
                        $bannerViews = (int) ($row['views'] ?? 0);
                        $bannerClicks = (int) ($row['clicks'] ?? 0);
                        $bannerCtr = $bannerViews > 0 ? ($bannerClicks / $bannerViews) * 100 : null;
                        $campaignName = trim((string) ($row['campaign_name'] ?? ''));
                        if ($campaignName === '') {
                            $campaignName = 'Banner #' . ($row['banner_id'] ?? '');
                        }
                        ?>
                        <tr>
                            <td class="text-break">
                                <div><?php echo safe($campaignName); ?></div>
                                <div class="small text-muted">Banner ID: <?php echo safe($row['banner_id'] ?? ''); ?></div>
                            </td>
                            <td class="text-break">
                                <div><?php echo safe($row['link_text'] ?: 'No link text'); ?></div>
                                <div class="small text-muted"><?php echo safe($row['target_url'] ?: 'No target URL'); ?></div>
                            </td>
                            <td class="text-end"><?php echo fmtNumber($bannerViews); ?></td>
                            <td class="text-end"><?php echo fmtNumber($bannerClicks); ?></td>
                            <td class="text-end"><?php echo fmtPercent($bannerCtr); ?></td>
                            <td class="text-end text-nowrap"><?php echo safe($row['last_activity'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($bannerPerformance)): ?>
                        <tr><td colspan="6" class="text-muted">No banner views or clicks tracked yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="small text-muted">Use this to compare promotion visibility against actual click-through before changing coupon or sale strategy.</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-tags text-danger me-2"></i>Coupon Performance</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Code</th>
                        <th class="text-end">Attempts</th>
                        <th class="text-end">Applied</th>
                        <th class="text-end">Rejected</th>
                        <th class="text-end">Revenue</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($couponPerformance as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo safe($row['coupon_code']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['attempts']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['applied']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['rejected']); ?></td>
                            <td class="text-end"><?php echo fmtMoney($row['revenue']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($couponPerformance)): ?>
                        <tr><td colspan="5" class="text-muted">No coupon activity tracked yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-route text-danger me-2"></i>Traffic Sources</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Source</th>
                        <th>Campaigns</th>
                        <th class="text-end">Sessions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($trafficSources as $row): ?>
                        <tr>
                            <td class="text-break"><?php echo safe($row['source']); ?></td>
                            <td class="text-break small text-muted"><?php echo safe($row['campaigns']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['sessions']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($trafficSources)): ?>
                        <tr><td colspan="3" class="text-muted">No traffic sources tracked yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-file-alt text-danger me-2"></i>Top Pages</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Page</th>
                        <th class="text-end">Views</th>
                        <th class="text-end">Sessions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topPages as $row): ?>
                        <tr>
                            <td class="text-break"><?php echo safe($row['page_path']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['views']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['sessions']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($topPages)): ?>
                        <tr><td colspan="3" class="text-muted">No page views tracked yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100" data-aos="fade-up">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-search text-danger me-2"></i>Search Terms</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Search Term</th>
                        <th class="text-end">Searches</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($searchTerms as $row): ?>
                        <tr>
                            <td><?php echo safe($row['search_term']); ?></td>
                            <td class="text-end"><?php echo fmtNumber($row['searches']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($searchTerms)): ?>
                        <tr><td colspan="2" class="text-muted">No searches tracked yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" data-aos="fade-up">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-clock text-danger me-2"></i>Recent Events</h5>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle table-fixed">
            <thead>
            <tr>
                <th>Time</th>
                <th>Event</th>
                <th>Page / Product</th>
                <th>Coupon / Order</th>
                <th class="text-end">Value</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recentEvents as $row): ?>
                <tr>
                    <td class="text-nowrap"><?php echo safe($row['created_at']); ?></td>
                    <td><span class="badge text-bg-light"><?php echo safe($row['event_type']); ?></span></td>
                    <td class="text-break">
                        <div><?php echo safe($row['product_name'] ?: $row['page_path']); ?></div>
                        <div class="small text-muted"><?php echo safe($row['product_sku'] ?: $row['category']); ?></div>
                    </td>
                    <td>
                        <div><?php echo safe($row['coupon_code'] ?: $row['target_host']); ?></div>
                        <div class="small text-muted"><?php echo safe($row['order_number'] ?: $row['link_text']); ?></div>
                    </td>
                    <td class="text-end"><?php echo fmtMoney($row['revenue'] ?: $row['cart_value']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentEvents)): ?>
                <tr><td colspan="5" class="text-muted">No events tracked yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-light border small text-muted">
    Use this report weekly to identify high-demand products, category revenue patterns, coupon effectiveness, and checkout steps where shoppers leave before buying.
</div>


<div class="modal fade" id="sessionDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="small text-muted text-uppercase fw-semibold">Analytics Session</div>
                    <h5 class="modal-title mb-0" id="sessionDetailsTitle">Session Details</h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="sessionDetailsCopy">
                        <i class="fas fa-link me-1"></i>Copy Link
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
                <div id="sessionDetailsLoading" class="alert alert-light border small mb-0">Loading session details...</div>
                <div id="sessionDetailsError" class="alert alert-danger small d-none mb-0"></div>
                <div id="sessionDetailsContent" class="d-none">
                    <div class="row g-3 mb-4" id="sessionDetailsStats"></div>
                    <div class="row g-4 mb-4">
                    <div class="col-lg-6">
                        <div class="analytics-modal-panel p-3 h-100">
                                <h6 class="fw-bold mb-3">User / Visitor</h6>
                                <dl class="row small mb-0" id="sessionDetailsUser"></dl>
                            </div>
                        </div>
                    <div class="col-lg-6">
                        <div class="analytics-modal-panel p-3 h-100">
                                <h6 class="fw-bold mb-3">Session Context</h6>
                                <dl class="row small mb-0" id="sessionDetailsContext"></dl>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0">Chronological Action History</h6>
                        <span class="small text-muted" id="sessionDetailsEventCount"></span>
                    </div>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Event</th>
                                    <th>Page / Step</th>
                                    <th>Product / Link</th>
                                    <th class="text-end">Value</th>
                                </tr>
                            </thead>
                            <tbody id="sessionDetailsEvents"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
(function () {
    const modalElement = document.getElementById('sessionDetailsModal');
    if (!modalElement) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const loading = document.getElementById('sessionDetailsLoading');
    const errorBox = document.getElementById('sessionDetailsError');
    const content = document.getElementById('sessionDetailsContent');
    const title = document.getElementById('sessionDetailsTitle');
    const stats = document.getElementById('sessionDetailsStats');
    const userDetails = document.getElementById('sessionDetailsUser');
    const contextDetails = document.getElementById('sessionDetailsContext');
    const eventCount = document.getElementById('sessionDetailsEventCount');
    const eventsBody = document.getElementById('sessionDetailsEvents');
    const copyButton = document.getElementById('sessionDetailsCopy');
    let activeSessionId = '';

    function text(value, fallback = 'Unknown') {
        if (value === null || value === undefined || value === '') return fallback;
        return String(value);
    }

    function number(value) {
        const parsed = Number(value || 0);
        return new Intl.NumberFormat().format(Number.isFinite(parsed) ? parsed : 0);
    }

    function money(value) {
        const parsed = Number(value || 0);
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(Number.isFinite(parsed) ? parsed : 0);
    }

    function seconds(value) {
        const total = Math.max(0, Number.parseInt(value || 0, 10));
        if (total < 60) return total + 's';
        const minutes = Math.floor(total / 60);
        const remaining = total % 60;
        if (minutes < 60) return minutes + 'm ' + remaining + 's';
        const hours = Math.floor(minutes / 60);
        return hours + 'h ' + (minutes % 60) + 'm';
    }

    function compact(parts) {
        return parts.map(part => text(part, '')).filter(Boolean).join(' \u00b7 ') || 'Unknown';
    }

    function botLabel(summary) {
        if (Number(summary.is_potential_bot || 0) === 1) return text(summary.bot_reason, 'Potential bot');
        if (summary.cf_bot_score !== null && summary.cf_bot_score !== undefined && summary.cf_bot_score !== '') return 'No bot signal \u00b7 CF ' + Number(summary.cf_bot_score);
        return 'No bot signal';
    }

    function ipSourceLabel(summary) {
        const source = text(summary.client_ip_source, '');
        const labels = {
            cloudflare_connecting_ip: 'Cloudflare visitor IP',
            cloudflare_connecting_ipv6: 'Cloudflare visitor IPv6',
            cloudflare_true_client_ip: 'Cloudflare True-Client-IP',
            cloudflare_proxy_remote_addr: 'Cloudflare edge IP only',
            cloudflare_headers_missing_ip: 'Cloudflare IP header missing',
            x_forwarded_for: 'X-Forwarded-For',
            x_real_ip: 'X-Real-IP',
            true_client_ip: 'True-Client-IP',
            remote_addr: 'Server remote address'
        };

        return labels[source] || (source ? source.replace(/_/g, ' ') : 'Unknown');
    }

    function eventLabel(type) {
        return text(type, 'event').replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
    }

    function eventBadgeClass(type) {
        const eventType = text(type, '').toLowerCase();
        if (['purchase_completed', 'checkout_completed'].includes(eventType)) return 'text-bg-success';
        if (['checkout_start', 'shipping_rate_requested', 'shipping_rate_selected'].includes(eventType)) return 'text-bg-primary';
        if (['add_to_cart', 'cart_view', 'cart_quantity_changed', 'cart_abandonment_signal'].includes(eventType)) return 'text-bg-warning';
        if (['ebay_link_click', 'external_link_click'].includes(eventType)) return 'text-bg-info';
        if (['coupon_attempted', 'coupon_applied', 'coupon_rejected', 'banner_click'].includes(eventType)) return 'text-bg-secondary';
        return 'text-bg-light';
    }

    function eventValue(value) {
        return Number(value || 0) > 0 ? money(value) : '—';
    }

    function setState(state, message = '') {
        loading.classList.toggle('d-none', state !== 'loading');
        errorBox.classList.toggle('d-none', state !== 'error');
        content.classList.toggle('d-none', state !== 'ready');
        if (message) errorBox.textContent = message;
    }

    function clearNode(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function appendText(parent, value) {
        parent.appendChild(document.createTextNode(value));
    }

    function statCard(label, value, note) {
        const col = document.createElement('div');
        col.className = 'col-sm-6 col-lg-3';
        col.innerHTML = '<div class="analytics-mini-card p-3 h-100"><div class="small text-muted"></div><div class="h4 mb-0"></div><div class="small text-muted mt-1"></div></div>';
        col.querySelectorAll('div')[1].textContent = label;
        col.querySelector('.h4').textContent = value;
        col.querySelectorAll('div')[3].textContent = note;
        return col;
    }

    function detailRow(label, value) {
        const fragment = document.createDocumentFragment();
        const dt = document.createElement('dt');
        dt.className = 'col-sm-4 text-muted';
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.className = 'col-sm-8 text-break';
        dd.textContent = text(value);
        fragment.appendChild(dt);
        fragment.appendChild(dd);
        return fragment;
    }

    function renderDetails(summary, events) {
        clearNode(stats);
        clearNode(userDetails);
        clearNode(contextDetails);
        clearNode(eventsBody);

        const activeSeconds = Number(summary.max_session_age_seconds || summary.duration_seconds || 0);
        const location = compact([summary.cf_city, summary.cf_region_code || summary.cf_region, summary.cf_country]);
        const source = summary.utm_source || summary.referrer || 'Direct / unknown';
        const device = compact([summary.device_type, summary.browser, summary.os]);

        title.textContent = 'Session ' + text(summary.session_id, 'Unknown');
        stats.appendChild(statCard('Active Time', seconds(activeSeconds), 'Last seen ' + text(summary.last_seen_at)));
        stats.appendChild(statCard('Events', number(summary.events), number(summary.page_views) + ' page views'));
        stats.appendChild(statCard('Cart Value', money(summary.cart_value), number(summary.cart_adds) + ' adds \u00b7 ' + number(summary.checkout_starts) + ' checkout'));
        stats.appendChild(statCard('Revenue', money(summary.revenue), number(summary.purchases) + ' orders \u00b7 ' + number(summary.ebay_clicks) + ' eBay exits'));

        userDetails.appendChild(detailRow('Visitor ID', summary.visitor_id));
        userDetails.appendChild(detailRow('Visitor Type', Number(summary.is_returning_visitor || 0) === 1 ? 'Returning visitor' : 'New visitor'));
        userDetails.appendChild(detailRow('Visitor Pageviews', number(summary.visitor_pageviews)));
        userDetails.appendChild(detailRow('Device', device));
        userDetails.appendChild(detailRow('Language', summary.language));
        userDetails.appendChild(detailRow('Timezone', summary.timezone || summary.cf_timezone));
        userDetails.appendChild(detailRow('Location', location));
        userDetails.appendChild(detailRow('IP Address', summary.client_ip));
        userDetails.appendChild(detailRow('IP Source', ipSourceLabel(summary)));
        userDetails.appendChild(detailRow('Bot Signal', botLabel(summary)));

        contextDetails.appendChild(detailRow('Started', summary.started_at));
        contextDetails.appendChild(detailRow('Last Seen', summary.last_seen_at));
        contextDetails.appendChild(detailRow('Landing Page', summary.landing_page));
        contextDetails.appendChild(detailRow('Last Page', summary.last_page));
        contextDetails.appendChild(detailRow('Traffic Source', source));
        contextDetails.appendChild(detailRow('Campaign', compact([summary.utm_campaign, summary.utm_medium, summary.utm_term])));
        contextDetails.appendChild(detailRow('Browser Signals', compact([summary.viewport_orientation, summary.connection_type, summary.color_scheme, Number(summary.cookies_enabled || 0) === 1 ? 'Cookies on' : ''])));
        contextDetails.appendChild(detailRow('Cloudflare', compact([summary.cf_ray, summary.cf_bot_score !== null && summary.cf_bot_score !== undefined && summary.cf_bot_score !== '' ? 'Bot score ' + summary.cf_bot_score : ''])));

        eventCount.textContent = number(events.length) + ' events shown';
        if (events.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = '<td colspan="5" class="text-muted">No non-heartbeat actions recorded for this session.</td>';
            eventsBody.appendChild(row);
            return;
        }

        events.forEach(event => {
            const row = document.createElement('tr');
            const value = Number(event.revenue || event.cart_value || event.event_value || 0);
            const product = event.product_name || event.link_text || event.search_term || event.campaign_name || '';
            const productMeta = compact([event.product_sku, event.condition_name, event.stock_quantity ? 'Stock ' + event.stock_quantity : '', event.target_host, event.coupon_code, event.list_name]);
            const pageMeta = event.checkout_step || event.previous_page_path || event.referrer_host || '';

            [
                text(event.created_at, ''),
                text(event.event_type, ''),
                compact([event.page_path || 'Unknown page', pageMeta]),
                compact([product, productMeta]),
                eventValue(value)
            ].forEach((cellValue, index) => {
                const cell = document.createElement('td');
                if (index === 1) {
                    const badge = document.createElement('span');
                    badge.className = 'badge analytics-event-badge ' + eventBadgeClass(cellValue);
                    badge.textContent = eventLabel(cellValue);
                    cell.appendChild(badge);
                    const meta = document.createElement('div');
                    meta.className = 'small text-muted';
                    meta.textContent = 'Seq ' + number(event.page_sequence) + ' \u00b7 ' + seconds(event.session_age_seconds);
                    cell.appendChild(meta);
                } else {
                    cell.className = index === 4 ? 'text-end text-nowrap' : 'text-break';
                    appendText(cell, cellValue);
                }
                row.appendChild(cell);
            });
            eventsBody.appendChild(row);
        });
    }

    function sessionLink(sessionId) {
        const url = new URL(window.location.href);
        url.searchParams.set('days', String(<?php echo json_encode($days); ?>));
        url.searchParams.set('session', sessionId);
        return url.toString();
    }

    function rememberSession(sessionId) {
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', sessionLink(sessionId));
        }
    }

    async function copySessionLink() {
        if (!activeSessionId || !copyButton) return;

        const link = sessionLink(activeSessionId);
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(link);
            } else {
                const field = document.createElement('textarea');
                field.value = link;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.left = '-9999px';
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                document.body.removeChild(field);
            }
            copyButton.innerHTML = '<i class="fas fa-check me-1"></i>Copied';
            window.setTimeout(() => {
                copyButton.innerHTML = '<i class="fas fa-link me-1"></i>Copy Link';
            }, 1600);
        } catch (error) {
            copyButton.innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i>Copy Failed';
        }
    }

    async function openSession(sessionId) {
        sessionId = text(sessionId, '').trim();
        if (!sessionId) return;

        activeSessionId = sessionId;
        title.textContent = 'Session ' + sessionId;
        setState('loading');
        if (copyButton) {
            copyButton.disabled = true;
            copyButton.innerHTML = '<i class="fas fa-link me-1"></i>Copy Link';
        }
        modal.show();
        rememberSession(sessionId);

        try {
            const response = await fetch('session-details.php?session=' + encodeURIComponent(sessionId), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.error || 'Unable to load session details.');
            renderDetails(payload.summary || {}, payload.events || []);
            setState('ready');
            if (copyButton) copyButton.disabled = false;
        } catch (error) {
            setState('error', error.message || 'Unable to load session details.');
            if (copyButton) copyButton.disabled = false;
        }
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('.js-session-view');
        if (button) {
            event.preventDefault();
            event.stopPropagation();
            openSession(button.dataset.sessionId || '');
            return;
        }

        const row = event.target.closest('.analytics-session-row');
        if (row && !event.target.closest('a, button, input, select, textarea')) {
            openSession(row.dataset.sessionId || '');
        }
    });

    document.querySelectorAll('.analytics-session-row').forEach(row => {
        row.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openSession(row.dataset.sessionId || '');
            }
        });
    });

    if (copyButton) {
        copyButton.addEventListener('click', copySessionLink);
    }

    const initialSessionId = <?php echo json_encode($selectedSessionId); ?>;
    if (initialSessionId) {
        openSession(initialSessionId);
    }
})();
</script>

</body>
</html>
