<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/models/Coupon.php';
require_once __DIR__ . '/../src/utils/Seo.php';

use FAS\Config\Database;
use FAS\Models\Coupon;
use FAS\Models\Product;
use FAS\Utils\Seo;

$auth = new AdminAuth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$productModel = new Product($db);
$couponModel = new Coupon($db);

$success = '';
$error = '';

function siSafe($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function siMoney($value): string
{
    return '$' . number_format((float)$value, 2);
}

function siDate($value): string
{
    if (empty($value)) {
        return 'Never';
    }

    $timestamp = strtotime((string)$value);
    return $timestamp ? date('M j, Y', $timestamp) : (string)$value;
}

function siCategoryLabel(array $product): string
{
    return (string)($product['ebay_store_cat3_name']
        ?? $product['ebay_store_cat2_name']
        ?? $product['ebay_store_cat1_name']
        ?? $product['category']
        ?? 'Uncategorized');
}

function siCampaignSummary(array $recommendations): array
{
    $summary = [];

    foreach ($recommendations as $product) {
        $discount = (int)($product['recommended_discount_percent'] ?? 0);
        if ($discount <= 0) {
            continue;
        }

        if (!isset($summary[$discount])) {
            $summary[$discount] = [
                'discount' => $discount,
                'count' => 0,
                'value' => 0.0,
                'code' => 'OLDSTOCK' . $discount,
            ];
        }

        $summary[$discount]['count']++;
        $summary[$discount]['value'] += (float)($product['price'] ?? 0);
    }

    usort($summary, function ($a, $b) {
        return $b['count'] <=> $a['count'] ?: $b['discount'] <=> $a['discount'];
    });

    return array_slice($summary, 0, 3);
}

function siAverage(array $values): float
{
    $values = array_values(array_filter($values, function ($value) {
        return is_numeric($value);
    }));

    return empty($values) ? 0.0 : array_sum($values) / count($values);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postAction = $_POST['action'] ?? '';

        if ($postAction === 'apply_markdowns') {
            $selectedProducts = $_POST['selected_products'] ?? [];
            $recommendedSalePrices = $_POST['recommended_sale_price'] ?? [];

            if (!is_array($selectedProducts) || empty($selectedProducts) || !is_array($recommendedSalePrices)) {
                $error = 'Select at least one stale product before applying markdowns.';
            } else {
                $salePricesById = [];
                foreach ($selectedProducts as $productId) {
                    $productId = (int)$productId;
                    if ($productId > 0 && isset($recommendedSalePrices[$productId])) {
                        $salePricesById[$productId] = (float)$recommendedSalePrices[$productId];
                    }
                }

                $updatedCount = $productModel->applySalePrices($salePricesById);
                $success = $updatedCount > 0
                    ? "Applied recommended markdowns to {$updatedCount} selected products."
                    : 'No selected products needed a sale-price update.';
            }
        } elseif ($postAction === 'create_coupon_campaign') {
            $discount = max(1, min(90, (int)($_POST['discount'] ?? 0)));
            $campaignCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_POST['campaign_code'] ?? 'OLDSTOCK' . $discount)));
            $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

            if ($discount <= 0) {
                $error = 'Select a valid discount percent for the campaign.';
            } elseif ($campaignCode === '') {
                $error = 'Enter a coupon code for the campaign.';
            } elseif ($couponModel->getByCode($campaignCode)) {
                $error = "Coupon code {$campaignCode} already exists.";
            } else {
                $couponModel->create([
                    'code' => $campaignCode,
                    'description' => "{$discount}% stale inventory campaign. Promote this code on old listings, banners, email, or social posts.",
                    'discount_type' => 'percentage',
                    'discount_value' => $discount,
                    'minimum_purchase' => (float)($_POST['minimum_purchase'] ?? 0),
                    'max_uses' => !empty($_POST['max_uses']) ? (int)$_POST['max_uses'] : null,
                    'expires_at' => $expiresAt !== '' ? $expiresAt : date('Y-m-d H:i:s', strtotime('+14 days')),
                    'is_active' => 1,
                ]);
                $success = "Created coupon campaign {$campaignCode}. Add it to a banner or product copy before promoting.";
            }
        }
    } catch (Throwable $e) {
        $error = 'Promotion action failed: ' . $e->getMessage();
    }
}

$minimumAgeDays = max(30, min(1000, (int)($_GET['min_age_days'] ?? 90)));
$limit = max(25, min(500, (int)($_GET['limit'] ?? 150)));
$visibilityFilter = $_GET['visibility'] ?? 'visible';
if (!in_array($visibilityFilter, ['visible', 'hidden', 'all'], true)) {
    $visibilityFilter = 'visible';
}

$recommendations = [];
try {
    $recommendations = $productModel->getStaleInventoryRecommendations($minimumAgeDays, $limit, $visibilityFilter);
} catch (Throwable $e) {
    $error = $error ?: 'Could not load stale inventory recommendations: ' . $e->getMessage();
}

$candidateCount = count($recommendations);
$candidateValue = array_sum(array_map(function ($product) {
    return (float)($product['price'] ?? 0);
}, $recommendations));
$averageDaysListed = siAverage(array_map(function ($product) {
    return (int)($product['days_listed'] ?? 0);
}, $recommendations));
$averageDiscount = siAverage(array_map(function ($product) {
    return (int)($product['recommended_discount_percent'] ?? 0);
}, $recommendations));
$campaigns = siCampaignSummary($recommendations);
$defaultExpiry = date('Y-m-d\TH:i', strtotime('+14 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stale Inventory Promotions - Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="manifest" href="/admin/manifest.json">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-style.css">
    <style>
        .stale-card {
            border: 1px solid rgba(15, 23, 42, .08);
            border-radius: 18px;
        }

        .stale-product-thumb {
            background: #f3f4f6;
            border-radius: 12px;
            height: 58px;
            object-fit: cover;
            width: 58px;
        }

        .stale-muted-line {
            color: #667085;
            display: block;
            font-size: .78rem;
            max-width: 26rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .stale-table th,
        .stale-table td {
            vertical-align: middle;
        }

        [data-theme="dark"] .stale-card {
            background: linear-gradient(180deg, #363636 0%, #2d2d2d 100%);
            border-color: rgba(255, 255, 255, .14);
            color: #f3f4f6;
        }

        [data-theme="dark"] .stale-card .text-muted,
        [data-theme="dark"] .stale-muted-line {
            color: #c5cbd3 !important;
        }

        [data-theme="dark"] .stale-product-thumb {
            background: #242629;
        }
    </style>
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <div class="admin-hero d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="mb-2"><i class="fas fa-fire me-2"></i>Stale Inventory Promotions</h1>
            <p class="mb-0 text-muted">Auto-identify old listings and turn them into markdown or coupon campaign recommendations.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="coupons.php" class="btn btn-outline-secondary">
                <i class="fas fa-tags me-1"></i>Coupons
            </a>
            <a href="banners.php" class="btn btn-outline-secondary">
                <i class="fas fa-bullhorn me-1"></i>Banners
            </a>
            <a href="products.php" class="btn btn-primary">
                <i class="fas fa-box me-1"></i>Products
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo siSafe($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo siSafe($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3" data-aos="fade-up">
            <div class="card stale-card h-100 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Stale Candidates</div>
                    <div class="display-6 fw-bold"><?php echo number_format($candidateCount); ?></div>
                    <div class="small text-muted"><?php echo siSafe($minimumAgeDays); ?>+ days old</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="50">
            <div class="card stale-card h-100 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Candidate Retail Value</div>
                    <div class="display-6 fw-bold"><?php echo siMoney($candidateValue); ?></div>
                    <div class="small text-muted">Before any sale pricing</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="100">
            <div class="card stale-card h-100 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Average Listing Age</div>
                    <div class="display-6 fw-bold"><?php echo number_format($averageDaysListed, 0); ?></div>
                    <div class="small text-muted">Days listed</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="150">
            <div class="card stale-card h-100 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Average Recommendation</div>
                    <div class="display-6 fw-bold"><?php echo number_format($averageDiscount, 0); ?>%</div>
                    <div class="small text-muted">Suggested markdown/coupon</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card stale-card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label fw-semibold" for="min_age_days">Minimum Listing Age</label>
                    <select class="form-select" id="min_age_days" name="min_age_days">
                        <?php foreach ([60, 90, 120, 180, 365] as $ageOption): ?>
                            <option value="<?php echo $ageOption; ?>" <?php echo $minimumAgeDays === $ageOption ? 'selected' : ''; ?>><?php echo $ageOption; ?>+ days</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label fw-semibold" for="visibility">Visibility</label>
                    <select class="form-select" id="visibility" name="visibility">
                        <option value="visible" <?php echo $visibilityFilter === 'visible' ? 'selected' : ''; ?>>Visible inventory</option>
                        <option value="hidden" <?php echo $visibilityFilter === 'hidden' ? 'selected' : ''; ?>>Hidden inventory</option>
                        <option value="all" <?php echo $visibilityFilter === 'all' ? 'selected' : ''; ?>>All active inventory</option>
                    </select>
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label fw-semibold" for="limit">Max Results</label>
                    <select class="form-select" id="limit" name="limit">
                        <?php foreach ([50, 100, 150, 250, 500] as $limitOption): ?>
                            <option value="<?php echo $limitOption; ?>" <?php echo $limit === $limitOption ? 'selected' : ''; ?>><?php echo $limitOption; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-rotate me-1"></i>Refresh Recommendations
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($campaigns)): ?>
        <div class="row g-3 mb-4">
            <?php foreach ($campaigns as $campaign): ?>
                <div class="col-lg-4" data-aos="fade-up">
                    <div class="card stale-card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="text-muted small">Recommended Campaign</div>
                                    <h2 class="h5 fw-bold mb-0"><?php echo siSafe($campaign['code']); ?></h2>
                                </div>
                                <span class="badge bg-danger"><?php echo (int)$campaign['discount']; ?>% off</span>
                            </div>
                            <p class="small text-muted mb-3">
                                Covers <?php echo number_format((int)$campaign['count']); ?> stale candidates worth <?php echo siMoney($campaign['value']); ?> retail value.
                                Existing coupons apply cart-wide, so use this as a targeted promo code in banners, emails, and old-listing copy.
                            </p>
                            <form method="POST" class="row g-2">
                                <input type="hidden" name="action" value="create_coupon_campaign">
                                <input type="hidden" name="discount" value="<?php echo (int)$campaign['discount']; ?>">
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Coupon Code</label>
                                    <input type="text" class="form-control form-control-sm" name="campaign_code" value="<?php echo siSafe($campaign['code']); ?>" maxlength="40">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Max Uses</label>
                                    <input type="number" class="form-control form-control-sm" name="max_uses" min="1" value="50">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Expires</label>
                                    <input type="datetime-local" class="form-control form-control-sm" name="expires_at" value="<?php echo siSafe($defaultExpiry); ?>">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                        <i class="fas fa-tags me-1"></i>Create Coupon Campaign
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="staleMarkdownForm">
        <input type="hidden" name="action" value="apply_markdowns">
        <div class="card stale-card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">Product-Level Markdown Recommendations</h2>
                        <p class="text-muted small mb-0">Select specific products to apply the suggested sale price. Review each item first; this changes live product pricing.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllStale">
                            <i class="fas fa-check-square me-1"></i>Select All
                        </button>
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="fas fa-percent me-1"></i>Apply Selected Markdowns
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle stale-table">
                        <thead>
                            <tr>
                                <th style="width: 42px;"></th>
                                <th>Product</th>
                                <th>Age</th>
                                <th>Signals</th>
                                <th>Pricing</th>
                                <th>Recommendation</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recommendations as $product): ?>
                                <?php
                                $productId = (int)$product['id'];
                                $imageUrl = trim((string)($product['image_url'] ?? ''));
                                $imageSrc = $imageUrl !== '' && (strpos($imageUrl, 'http://') === 0 || strpos($imageUrl, 'https://') === 0)
                                    ? $imageUrl
                                    : '../' . ltrim($imageUrl, '/');
                                $productUrl = Seo::productUrl($product);
                                ?>
                                <tr>
                                    <td>
                                        <input class="form-check-input stale-product-select" type="checkbox" name="selected_products[]" value="<?php echo $productId; ?>">
                                        <input type="hidden" name="recommended_sale_price[<?php echo $productId; ?>]" value="<?php echo siSafe($product['recommended_sale_price']); ?>">
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if ($imageUrl !== ''): ?>
                                                <img src="<?php echo siSafe($imageSrc); ?>" alt="" class="stale-product-thumb">
                                            <?php else: ?>
                                                <div class="stale-product-thumb d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-image text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-semibold"><?php echo siSafe($product['name']); ?></div>
                                                <span class="stale-muted-line">SKU: <?php echo siSafe($product['sku'] ?? 'N/A'); ?> · <?php echo siSafe(siCategoryLabel($product)); ?></span>
                                                <span class="stale-muted-line"><?php echo siSafe(trim(($product['manufacturer'] ?? '') . ' ' . ($product['model'] ?? '')) ?: 'No fitment listed'); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo number_format((int)$product['days_listed']); ?> days</div>
                                        <div class="small text-muted">Updated <?php echo number_format((int)$product['days_since_update']); ?> days ago</div>
                                        <div class="small text-muted">Last sold: <?php echo siSafe(siDate($product['last_order_at'] ?? null)); ?></div>
                                    </td>
                                    <td>
                                        <div class="small"><strong><?php echo number_format((int)$product['views_30']); ?></strong> views / 30d</div>
                                        <div class="small"><strong><?php echo number_format((int)$product['views_90']); ?></strong> views / 90d</div>
                                        <div class="small"><strong><?php echo number_format((int)$product['carts_90']); ?></strong> carts / 90d</div>
                                        <div class="small"><strong><?php echo number_format((int)$product['units_sold']); ?></strong> sold</div>
                                    </td>
                                    <td>
                                        <div class="small text-muted">Current</div>
                                        <div class="fw-semibold"><?php echo siMoney($product['price']); ?></div>
                                        <?php if ((int)$product['current_discount_percent'] > 0): ?>
                                            <div class="small text-success">Current sale: <?php echo siMoney($product['sale_price']); ?> (<?php echo (int)$product['current_discount_percent']; ?>% off)</div>
                                        <?php endif; ?>
                                        <div class="small text-danger mt-1">Suggested: <?php echo siMoney($product['recommended_sale_price']); ?> (<?php echo (int)$product['recommended_discount_percent']; ?>% off)</div>
                                    </td>
                                    <td>
                                        <div class="mb-1">
                                            <span class="badge bg-<?php echo siSafe($product['priority_class']); ?>"><?php echo siSafe($product['priority_label']); ?></span>
                                            <span class="badge bg-light text-dark border"><?php echo siSafe($product['recommendation_type']); ?></span>
                                        </div>
                                        <div class="small text-muted"><?php echo siSafe($product['recommendation_reason']); ?></div>
                                        <div class="small mt-1">Campaign code idea: <strong><?php echo siSafe($product['campaign_code']); ?></strong></div>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="products.php?action=edit&id=<?php echo $productId; ?>" class="btn btn-outline-primary" title="Edit product">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="<?php echo siSafe($productUrl); ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener" title="View product">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (empty($recommendations)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5">
                                        No stale inventory matches the current filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>

    <div class="alert alert-info mt-4 small">
        <strong>How to use this:</strong> Apply product-level markdowns when old parts need a clear price change.
        Use coupon campaigns when products have views/cart activity but need a temporary incentive.
        Existing coupons are cart-wide, so campaign copy should direct shoppers toward the stale parts you want to move.
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>
    <script>
        const selectAllButton = document.getElementById('selectAllStale');
        const markdownForm = document.getElementById('staleMarkdownForm');

        if (selectAllButton) {
            selectAllButton.addEventListener('click', function () {
                const checkboxes = Array.from(document.querySelectorAll('.stale-product-select'));
                const shouldCheck = checkboxes.some(checkbox => !checkbox.checked);
                checkboxes.forEach(checkbox => {
                    checkbox.checked = shouldCheck;
                });
                this.innerHTML = shouldCheck
                    ? '<i class="fas fa-square me-1"></i>Clear Selection'
                    : '<i class="fas fa-check-square me-1"></i>Select All';
            });
        }

        if (markdownForm) {
            markdownForm.addEventListener('submit', function (event) {
                const selectedCount = document.querySelectorAll('.stale-product-select:checked').length;
                if (selectedCount === 0) {
                    event.preventDefault();
                    alert('Select at least one product before applying markdowns.');
                    return;
                }

                if (!confirm(`Apply recommended sale prices to ${selectedCount} selected product${selectedCount === 1 ? '' : 's'}?`)) {
                    event.preventDefault();
                }
            });
        }
    </script>
</body>
</html>
