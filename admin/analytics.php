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

function safe($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function metricCard(string $label, string $value, string $note, string $icon): string
{
    return '
        <div class="col-sm-6 col-xl-2">
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
                                        <div class="small text-muted mb-1"><?php echo safe($product['product_sku'] ?: $product['category']); ?></div>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
