<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/sale-helper.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__ . '/../src/utils/Seo.php';
require_once __DIR__ . '/../src/utils/ShippingRules.php';

use FAS\Config\Database;
use FAS\Models\Product;
use FAS\Utils\MerchantFeedBuilder;
use FAS\Utils\Seo;
use FAS\Utils\ShippingRules;

$auth = new AdminAuth();
$auth->requireLogin();

$configPath = __DIR__ . '/../src/config/config.php';
$config = file_exists($configPath) ? require $configPath : [];
$db = Database::getInstance()->getConnection();
$productModel = new Product($db);
$feedBuilder = new MerchantFeedBuilder($productModel, is_array($config) ? $config : []);
$products = $productModel->getAllVisibleForFeed();
$feedResult = $feedBuilder->buildItems($products);
$skippedByProductId = [];
foreach ($feedResult['skipped'] as $skipped) {
    $skippedByProductId[(string)($skipped['product_id'] ?? '')] = $skipped['reason'] ?? 'Skipped by feed builder';
}

function mfhSafe($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mfhClean($value): string
{
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/\s+/', ' ', $text));
}

function mfhHasImage(array $product): bool
{
    if (trim((string)($product['image_url'] ?? '')) !== '') {
        return true;
    }
    $images = $product['images'] ?? [];
    if (is_string($images)) {
        $decoded = json_decode($images, true);
        $images = is_array($decoded) ? $decoded : [];
    }
    return is_array($images) && count(array_filter($images)) > 0;
}

function mfhPositive($value): bool
{
    return is_numeric($value) && (float)$value > 0;
}

function mfhProductIssues(array $product, array $skippedByProductId): array
{
    $issues = [];
    $productId = (string)($product['id'] ?? '');
    $title = mfhClean($product['name'] ?? '');
    $description = mfhClean($product['description'] ?? '');
    $brand = mfhClean($product['manufacturer'] ?? '');
    $mpn = mfhClean($product['model'] ?? '');
    $sku = mfhClean($product['sku'] ?? '');

    if (isset($skippedByProductId[$productId])) {
        $issues[] = ['label' => 'Skipped', 'class' => 'danger', 'note' => $skippedByProductId[$productId]];
    }
    if ($title === '' || strlen($title) < 20 || strlen($title) > 150) {
        $issues[] = ['label' => 'Weak Title', 'class' => 'warning', 'note' => 'Title should be specific, readable, and not excessively long.'];
    }
    if ($description === '' || strlen($description) < 80) {
        $issues[] = ['label' => 'Thin Description', 'class' => 'warning', 'note' => 'Description should explain condition, fitment, and buyer-relevant details.'];
    }
    if (!mfhHasImage($product)) {
        $issues[] = ['label' => 'Missing Image', 'class' => 'danger', 'note' => 'Merchant feed needs a valid product image.'];
    }
    if (!mfhPositive($product['weight'] ?? null)) {
        $issues[] = ['label' => 'No Weight', 'class' => 'warning', 'note' => 'Shipping weight improves Shopping eligibility and estimates.'];
    }
    if (!mfhPositive($product['length'] ?? null) || !mfhPositive($product['width'] ?? null) || !mfhPositive($product['height'] ?? null)) {
        $issues[] = ['label' => 'No Dimensions', 'class' => 'warning', 'note' => 'Package dimensions improve rate accuracy.'];
    }
    if ($brand === '' && $mpn === '' && $sku === '') {
        $issues[] = ['label' => 'No Identifier', 'class' => 'secondary', 'note' => 'Brand, MPN/model, or SKU helps Google classify the item.'];
    }

    return $issues;
}

$audits = [];
$issueCounts = [
    'skipped' => 0,
    'weak_title' => 0,
    'thin_description' => 0,
    'missing_image' => 0,
    'shipping_data' => 0,
    'identifier' => 0,
];
$freeShippingCount = 0;
$saleCount = 0;

foreach ($products as $product) {
    $priceInfo = getEffectivePrice((float)($product['price'] ?? 0), !empty($product['sale_price']) ? (float)$product['sale_price'] : null);
    if (!empty($priceInfo['on_sale'])) {
        $saleCount++;
    }
    if (ShippingRules::productQualifiesForFreeShipping($product)) {
        $freeShippingCount++;
    }

    $issues = mfhProductIssues($product, $skippedByProductId);
    foreach ($issues as $issue) {
        if ($issue['label'] === 'Skipped') {
            $issueCounts['skipped']++;
        } elseif ($issue['label'] === 'Weak Title') {
            $issueCounts['weak_title']++;
        } elseif ($issue['label'] === 'Thin Description') {
            $issueCounts['thin_description']++;
        } elseif ($issue['label'] === 'Missing Image') {
            $issueCounts['missing_image']++;
        } elseif ($issue['label'] === 'No Weight' || $issue['label'] === 'No Dimensions') {
            $issueCounts['shipping_data']++;
        } elseif ($issue['label'] === 'No Identifier') {
            $issueCounts['identifier']++;
        }
    }

    if (!empty($issues)) {
        $audits[] = [
            'product' => $product,
            'issues' => $issues,
            'price_info' => $priceInfo,
        ];
    }
}

usort($audits, function ($a, $b) {
    return count($b['issues']) <=> count($a['issues']);
});

$totalProducts = count($products);
$readyProducts = max(0, $totalProducts - count($audits));
$riskProducts = count($audits);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Merchant Feed Health - Flip and Strip Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="css/admin.css" rel="stylesheet">
<style>
.feed-health-hero {
    background: linear-gradient(135deg, #dc3545 0%, #111827 100%);
    color: #fff;
    border-radius: 1.25rem;
    padding: 2rem;
}
.feed-health-card,
.feed-health-table-card {
    border: 0;
    box-shadow: 0 0.75rem 2rem rgba(15, 23, 42, 0.08);
}
.feed-health-icon {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(220, 53, 69, 0.12);
    color: #dc3545;
}
.feed-health-muted {
    color: #6c757d;
}
[data-theme="dark"] body,
body[data-theme="dark"] {
    background: #111827 !important;
    color: #e5e7eb;
}
[data-theme="dark"] .feed-health-card,
[data-theme="dark"] .feed-health-table-card,
[data-theme="dark"] .card,
body[data-theme="dark"] .feed-health-card,
body[data-theme="dark"] .feed-health-table-card,
body[data-theme="dark"] .card {
    background: #1f2937;
    color: #e5e7eb;
}
[data-theme="dark"] .feed-health-muted,
[data-theme="dark"] .text-muted,
body[data-theme="dark"] .feed-health-muted,
body[data-theme="dark"] .text-muted {
    color: #aab4c3 !important;
}
[data-theme="dark"] .table,
body[data-theme="dark"] .table {
    color: #e5e7eb;
}
[data-theme="dark"] .table td,
[data-theme="dark"] .table th,
body[data-theme="dark"] .table td,
body[data-theme="dark"] .table th {
    border-color: #374151;
}
</style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="container-fluid py-4">
<div class="feed-health-hero d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-4">
<div>
<h1 class="display-6 fw-bold mb-2"><i class="fas fa-store me-2"></i>Merchant Feed Health</h1>
<p class="mb-0 text-white-50">Checks visible inventory for Google Shopping readiness, richer feed fields, and avoidable listing risks.</p>
</div>
<a href="../google-merchant-feed.php" class="btn btn-light text-danger fw-semibold" target="_blank" rel="noopener">
<i class="fas fa-rss me-2"></i>Open Feed
</a>
</div>

<div class="row g-3 mb-4">
<div class="col-xl-3 col-md-6">
<div class="card feed-health-card h-100">
<div class="card-body d-flex justify-content-between align-items-start">
<div><div class="feed-health-muted small">Visible Products</div><div class="h3 fw-bold mb-0"><?php echo number_format($totalProducts); ?></div></div>
<span class="feed-health-icon"><i class="fas fa-boxes-stacked"></i></span>
</div>
</div>
</div>
<div class="col-xl-3 col-md-6">
<div class="card feed-health-card h-100">
<div class="card-body d-flex justify-content-between align-items-start">
<div><div class="feed-health-muted small">Feed-Ready</div><div class="h3 fw-bold mb-0 text-success"><?php echo number_format($readyProducts); ?></div></div>
<span class="feed-health-icon"><i class="fas fa-circle-check"></i></span>
</div>
</div>
</div>
<div class="col-xl-3 col-md-6">
<div class="card feed-health-card h-100">
<div class="card-body d-flex justify-content-between align-items-start">
<div><div class="feed-health-muted small">Needs Review</div><div class="h3 fw-bold mb-0 text-warning"><?php echo number_format($riskProducts); ?></div></div>
<span class="feed-health-icon"><i class="fas fa-triangle-exclamation"></i></span>
</div>
</div>
</div>
<div class="col-xl-3 col-md-6">
<div class="card feed-health-card h-100">
<div class="card-body d-flex justify-content-between align-items-start">
<div><div class="feed-health-muted small">Sale / Free Shipping</div><div class="h3 fw-bold mb-0"><?php echo number_format($saleCount); ?> / <?php echo number_format($freeShippingCount); ?></div></div>
<span class="feed-health-icon"><i class="fas fa-tags"></i></span>
</div>
</div>
</div>
</div>

<div class="row g-3 mb-4">
<?php foreach ([
    'skipped' => ['Skipped', 'danger'],
    'weak_title' => ['Weak Titles', 'warning'],
    'thin_description' => ['Thin Descriptions', 'warning'],
    'missing_image' => ['Missing Images', 'danger'],
    'shipping_data' => ['Shipping Data Issues', 'warning'],
    'identifier' => ['Identifier Gaps', 'secondary'],
] as $key => [$label, $class]): ?>
<div class="col-xl-2 col-md-4 col-6">
<div class="card feed-health-card h-100">
<div class="card-body py-3">
<div class="feed-health-muted small"><?php echo mfhSafe($label); ?></div>
<div class="h5 fw-bold text-<?php echo mfhSafe($class); ?> mb-0"><?php echo number_format($issueCounts[$key]); ?></div>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<div class="card feed-health-table-card">
<div class="card-header bg-transparent d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-2">
<div>
<h2 class="h5 fw-bold mb-1">Products That Need Feed Improvements</h2>
<div class="small feed-health-muted">Fix missing images, shipping data, identifiers, and thin copy first because those are the most likely to reduce Shopping visibility.</div>
</div>
<a href="product-quality.php" class="btn btn-outline-danger btn-sm"><i class="fas fa-clipboard-check me-1"></i>Product Quality Dashboard</a>
</div>
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead>
<tr>
<th>Product</th>
<th>Merchant Issues</th>
<th>Price</th>
<th>Updated</th>
<th class="text-end">Action</th>
</tr>
</thead>
<tbody>
<?php foreach (array_slice($audits, 0, 100) as $audit): ?>
<?php $product = $audit['product']; ?>
<tr>
<td>
<div class="fw-semibold"><?php echo mfhSafe($product['name'] ?? 'Untitled product'); ?></div>
<div class="small feed-health-muted">SKU: <?php echo mfhSafe($product['sku'] ?? ''); ?> · <?php echo mfhSafe($product['manufacturer'] ?? ''); ?> <?php echo mfhSafe($product['model'] ?? ''); ?></div>
</td>
<td>
<div class="d-flex flex-wrap gap-1">
<?php foreach ($audit['issues'] as $issue): ?>
<span class="badge text-bg-<?php echo mfhSafe($issue['class']); ?>" title="<?php echo mfhSafe($issue['note']); ?>"><?php echo mfhSafe($issue['label']); ?></span>
<?php endforeach; ?>
</div>
</td>
<td>
<?php if (!empty($audit['price_info']['on_sale'])): ?>
<span class="fw-semibold text-danger">$<?php echo number_format((float)$audit['price_info']['effective_price'], 2); ?></span>
<span class="small text-muted text-decoration-line-through">$<?php echo number_format((float)$audit['price_info']['original_price'], 2); ?></span>
<?php else: ?>
<span class="fw-semibold">$<?php echo number_format((float)($product['price'] ?? 0), 2); ?></span>
<?php endif; ?>
</td>
<td><time class="fas-local-time" datetime="<?php echo mfhSafe(gmdate('c', strtotime((string)($product['updated_at'] ?? $product['created_at'] ?? 'now')))); ?>" data-format="datetime"><?php echo mfhSafe($product['updated_at'] ?? $product['created_at'] ?? ''); ?></time></td>
<td class="text-end"><a href="products.php?action=edit&id=<?php echo (int)($product['id'] ?? 0); ?>" class="btn btn-sm btn-outline-danger">Edit</a></td>
</tr>
<?php endforeach; ?>
<?php if (empty($audits)): ?>
<tr><td colspan="5" class="text-center text-muted py-5">All visible products pass the current feed-health checks.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
