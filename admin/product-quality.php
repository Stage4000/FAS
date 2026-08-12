<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/utils/Seo.php';

use FAS\Config\Database;
use FAS\Models\Product;
use FAS\Utils\Seo;

$auth = new AdminAuth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$productModel = new Product($db);

function pqSafe($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pqCleanText($value): string
{
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/\s+/', ' ', $text));
}

function pqMissing($value): bool
{
    return trim((string)($value ?? '')) === '';
}

function pqPositive($value): bool
{
    return is_numeric($value) && (float)$value > 0;
}

function pqIssueDefinitions(): array
{
    return [
        'photos' => [
            'label' => 'Missing Photos',
            'icon' => 'fa-image',
            'class' => 'danger',
            'note' => 'No primary image or gallery images.',
        ],
        'dimensions' => [
            'label' => 'Missing Dimensions',
            'icon' => 'fa-ruler-combined',
            'class' => 'warning',
            'note' => 'Length, width, or height is missing.',
        ],
        'weight' => [
            'label' => 'Missing Weight',
            'icon' => 'fa-weight-hanging',
            'class' => 'warning',
            'note' => 'Shipping weight is missing.',
        ],
        'fitment' => [
            'label' => 'Missing Fitment',
            'icon' => 'fa-motorcycle',
            'class' => 'primary',
            'note' => 'Manufacturer and model are both blank.',
        ],
        'sku' => [
            'label' => 'Missing SKU',
            'icon' => 'fa-barcode',
            'class' => 'secondary',
            'note' => 'SKU is blank.',
        ],
        'category' => [
            'label' => 'Missing Category',
            'icon' => 'fa-folder-open',
            'class' => 'secondary',
            'note' => 'Local product category is blank.',
        ],
        'seo' => [
            'label' => 'Missing SEO Text',
            'icon' => 'fa-magnifying-glass-chart',
            'class' => 'info',
            'note' => 'Description is blank or too thin.',
        ],
        'hidden' => [
            'label' => 'Hidden Products',
            'icon' => 'fa-eye-slash',
            'class' => 'dark',
            'note' => 'Product is not visible on the storefront.',
        ],
    ];
}

function pqProductImages(array $product): array
{
    $images = [];
    $primaryImage = trim((string)($product['image_url'] ?? ''));

    if ($primaryImage !== '') {
        $images[] = $primaryImage;
    }

    $galleryImages = $product['images'] ?? null;
    if (is_string($galleryImages) && trim($galleryImages) !== '') {
        $decodedImages = json_decode($galleryImages, true);
        if (is_array($decodedImages)) {
            $galleryImages = $decodedImages;
        }
    }

    if (is_array($galleryImages)) {
        foreach ($galleryImages as $image) {
            $image = trim((string)$image);
            if ($image !== '') {
                $images[] = $image;
            }
        }
    }

    return array_values(array_unique($images));
}

function pqProductIssues(array $product): array
{
    $definitions = pqIssueDefinitions();
    $issues = [];
    $images = pqProductImages($product);
    $missingDimensions = [];
    $descriptionText = pqCleanText($product['description'] ?? '');

    if (empty($images)) {
        $issues['photos'] = $definitions['photos'];
    }

    foreach (['length' => 'length', 'width' => 'width', 'height' => 'height'] as $field => $label) {
        if (!pqPositive($product[$field] ?? null)) {
            $missingDimensions[] = $label;
        }
    }

    if (!empty($missingDimensions)) {
        $issues['dimensions'] = $definitions['dimensions'];
        $issues['dimensions']['note'] = 'Missing ' . implode(', ', $missingDimensions) . '.';
    }

    if (!pqPositive($product['weight'] ?? null)) {
        $issues['weight'] = $definitions['weight'];
    }

    if (pqMissing($product['manufacturer'] ?? '') && pqMissing($product['model'] ?? '')) {
        $issues['fitment'] = $definitions['fitment'];
    }

    if (pqMissing($product['sku'] ?? '')) {
        $issues['sku'] = $definitions['sku'];
    }

    if (pqMissing($product['category'] ?? '')) {
        $issues['category'] = $definitions['category'];
    }

    if ($descriptionText === '' || strlen($descriptionText) < 80) {
        $issues['seo'] = $definitions['seo'];
        $issues['seo']['note'] = $descriptionText === ''
            ? 'Description is blank.'
            : 'Description is under 80 characters.';
    }

    if ((int)($product['show_on_website'] ?? 0) !== 1) {
        $issues['hidden'] = $definitions['hidden'];
    }

    return $issues;
}

function pqQualityScore(array $issues): int
{
    $weights = [
        'photos' => 25,
        'dimensions' => 15,
        'weight' => 15,
        'fitment' => 15,
        'sku' => 10,
        'category' => 10,
        'seo' => 10,
        'hidden' => 0,
    ];
    $score = 100;

    foreach (array_keys($issues) as $issueKey) {
        $score -= $weights[$issueKey] ?? 0;
    }

    return max(0, $score);
}

function pqScoreClass(int $score): string
{
    if ($score >= 90) {
        return 'success';
    }

    if ($score >= 70) {
        return 'warning';
    }

    return 'danger';
}

function pqIssueUrl(array $baseParams, array $overrides): string
{
    $params = array_merge($baseParams, $overrides);
    $params['page'] = $params['page'] ?? 1;

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || $value === 'all') {
            unset($params[$key]);
        }
    }

    return 'product-quality.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

function pqFieldValue($value, string $suffix = ''): string
{
    return pqPositive($value) ? rtrim(rtrim(number_format((float)$value, 2), '0'), '.') . $suffix : 'Missing';
}

$issueDefinitions = pqIssueDefinitions();
$allowedIssues = array_merge(['all', 'ready'], array_keys($issueDefinitions));
$allowedSources = ['', 'ebay', 'manual'];
$allowedVisibility = ['', 'visible', 'hidden'];

$search = trim((string)($_GET['search'] ?? ''));
$sourceFilter = (string)($_GET['source'] ?? '');
$visibilityFilter = (string)($_GET['visibility'] ?? '');
$issueFilter = (string)($_GET['issue'] ?? 'all');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

if (!in_array($sourceFilter, $allowedSources, true)) {
    $sourceFilter = '';
}

if (!in_array($visibilityFilter, $allowedVisibility, true)) {
    $visibilityFilter = '';
}

if (!in_array($issueFilter, $allowedIssues, true)) {
    $issueFilter = 'all';
}

$allProducts = $productModel->getProductsForQualityAudit();
$summary = [
    'total' => count($allProducts),
    'visible' => 0,
    'hidden' => 0,
    'ready' => 0,
    'needs_cleanup' => 0,
    'average_score' => 0,
];
$issueCounts = array_fill_keys(array_keys($issueDefinitions), 0);
$auditedProducts = [];
$totalScore = 0;

foreach ($allProducts as $product) {
    $issues = pqProductIssues($product);
    $score = pqQualityScore($issues);
    $isHidden = (int)($product['show_on_website'] ?? 0) !== 1;
    $blockingIssues = array_diff(array_keys($issues), ['hidden']);
    $images = pqProductImages($product);

    $totalScore += $score;
    $summary[$isHidden ? 'hidden' : 'visible']++;

    foreach (array_keys($issues) as $issueKey) {
        $issueCounts[$issueKey]++;
    }

    if (!$isHidden && empty($blockingIssues)) {
        $summary['ready']++;
    } else {
        $summary['needs_cleanup']++;
    }

    $auditedProducts[] = [
        'product' => $product,
        'issues' => $issues,
        'score' => $score,
        'score_class' => pqScoreClass($score),
        'images' => $images,
        'blocking_issue_count' => count($blockingIssues),
    ];
}

$summary['average_score'] = $summary['total'] > 0 ? (int)round($totalScore / $summary['total']) : 0;

$filteredProducts = array_values(array_filter($auditedProducts, static function (array $row) use ($search, $sourceFilter, $visibilityFilter, $issueFilter): bool {
    $product = $row['product'];
    $isHidden = (int)($product['show_on_website'] ?? 0) !== 1;

    if ($sourceFilter !== '' && (string)($product['source'] ?? '') !== $sourceFilter) {
        return false;
    }

    if ($visibilityFilter === 'visible' && $isHidden) {
        return false;
    }

    if ($visibilityFilter === 'hidden' && !$isHidden) {
        return false;
    }

    if ($issueFilter === 'ready') {
        if ($isHidden || $row['blocking_issue_count'] > 0) {
            return false;
        }
    } elseif ($issueFilter !== 'all' && !isset($row['issues'][$issueFilter])) {
        return false;
    }

    if ($search !== '') {
        $haystack = strtolower(implode(' ', [
            $product['name'] ?? '',
            $product['sku'] ?? '',
            $product['manufacturer'] ?? '',
            $product['model'] ?? '',
            $product['category'] ?? '',
            $product['ebay_item_id'] ?? '',
        ]));

        if (strpos($haystack, strtolower($search)) === false) {
            return false;
        }
    }

    return true;
}));

usort($filteredProducts, static function (array $left, array $right): int {
    if ($left['score'] !== $right['score']) {
        return $left['score'] <=> $right['score'];
    }

    $leftProduct = $left['product'];
    $rightProduct = $right['product'];
    return strcmp((string)($rightProduct['updated_at'] ?? ''), (string)($leftProduct['updated_at'] ?? ''));
});

$filteredCount = count($filteredProducts);
$totalPages = max(1, (int)ceil($filteredCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$pageProducts = array_slice($filteredProducts, $offset, $perPage);
$pageStart = $filteredCount > 0 ? $offset + 1 : 0;
$pageEnd = min($filteredCount, $offset + $perPage);
$baseParams = [
    'search' => $search,
    'source' => $sourceFilter,
    'visibility' => $visibilityFilter,
    'issue' => $issueFilter,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Product Data Quality - Flip and Strip Admin</title>
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
.quality-card {
    background: linear-gradient(180deg, #ffffff 0%, #fbfbfc 100%);
    border: 1px solid rgba(31, 31, 36, .08);
    border-radius: 1rem;
    box-shadow: 0 .5rem 1.25rem rgba(31, 31, 36, .04);
}
.quality-issue-card {
    color: inherit;
    display: block;
    text-decoration: none;
    transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}
.quality-issue-card:hover,
.quality-issue-card.active {
    border-color: rgba(219, 3, 53, .35);
    box-shadow: 0 .75rem 1.5rem rgba(31, 31, 36, .08);
    transform: translateY(-1px);
}
.quality-icon {
    align-items: center;
    background: rgba(219, 3, 53, .1);
    border-radius: .85rem;
    color: #db0335;
    display: inline-flex;
    height: 2.35rem;
    justify-content: center;
    width: 2.35rem;
}
.quality-product-thumb {
    align-items: center;
    background: #f2f4f7;
    border-radius: .75rem;
    display: inline-flex;
    height: 3.25rem;
    justify-content: center;
    overflow: hidden;
    width: 3.25rem;
}
.quality-product-thumb img {
    height: 100%;
    object-fit: cover;
    width: 100%;
}
.quality-table {
    min-width: 1100px;
}
.quality-table td,
.quality-table th {
    vertical-align: middle;
}
.quality-product-name {
    display: block;
    font-weight: 700;
    max-width: 21rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.quality-muted-line {
    color: #667085;
    display: block;
    font-size: .78rem;
    max-width: 21rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.quality-issue-pill {
    display: inline-flex;
    margin: .12rem;
}
.quality-score {
    min-width: 8rem;
}
[data-theme="dark"] .quality-card {
    background: linear-gradient(180deg, #363636 0%, #2d2d2d 100%);
    border-color: rgba(255, 255, 255, .14);
    color: #f3f4f6;
}
[data-theme="dark"] .quality-card .text-muted,
[data-theme="dark"] .quality-muted-line {
    color: #c5cbd3 !important;
}
[data-theme="dark"] .quality-product-thumb {
    background: #242629;
}
</style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="admin-hero d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-4">
    <div>
        <h1 class="mb-2"><i class="fas fa-clipboard-check me-2"></i>Product Data Quality</h1>
        <p class="mb-0 text-muted">Find products missing photos, shipping data, fitment, SKU, category, SEO text, or storefront visibility.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="products.php" class="btn btn-outline-secondary">
            <i class="fas fa-box me-1"></i>Products
        </a>
        <a href="products.php?action=create" class="btn btn-primary">
            <i class="fas fa-plus-circle me-1"></i>Add Product
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3" data-aos="fade-up">
        <div class="quality-card p-3 h-100">
            <div class="d-flex justify-content-between gap-3">
                <div>
                    <div class="small text-muted">Average Quality Score</div>
                    <div class="h3 mb-0"><?php echo number_format($summary['average_score']); ?>%</div>
                </div>
                <span class="quality-icon"><i class="fas fa-gauge-high"></i></span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="50">
        <div class="quality-card p-3 h-100">
            <div class="d-flex justify-content-between gap-3">
                <div>
                    <div class="small text-muted">Ready Visible Products</div>
                    <div class="h3 mb-0 text-success"><?php echo number_format($summary['ready']); ?></div>
                </div>
                <span class="quality-icon"><i class="fas fa-circle-check"></i></span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="100">
        <div class="quality-card p-3 h-100">
            <div class="d-flex justify-content-between gap-3">
                <div>
                    <div class="small text-muted">Needs Cleanup</div>
                    <div class="h3 mb-0 text-danger"><?php echo number_format($summary['needs_cleanup']); ?></div>
                </div>
                <span class="quality-icon"><i class="fas fa-triangle-exclamation"></i></span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="150">
        <div class="quality-card p-3 h-100">
            <div class="d-flex justify-content-between gap-3">
                <div>
                    <div class="small text-muted">Visibility Split</div>
                    <div class="h5 mb-0"><?php echo number_format($summary['visible']); ?> visible</div>
                    <div class="small text-muted"><?php echo number_format($summary['hidden']); ?> hidden</div>
                </div>
                <span class="quality-icon"><i class="fas fa-eye"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" data-aos="fade-up">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label fw-semibold" for="search">Search</label>
                <input type="text" class="form-control" id="search" name="search" placeholder="Name, SKU, fitment, category..." value="<?php echo pqSafe($search); ?>">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label fw-semibold" for="issue">Issue</label>
                <select class="form-select" id="issue" name="issue">
                    <option value="all" <?php echo $issueFilter === 'all' ? 'selected' : ''; ?>>All Issues</option>
                    <option value="ready" <?php echo $issueFilter === 'ready' ? 'selected' : ''; ?>>Ready Products</option>
                    <?php foreach ($issueDefinitions as $issueKey => $definition): ?>
                        <option value="<?php echo pqSafe($issueKey); ?>" <?php echo $issueFilter === $issueKey ? 'selected' : ''; ?>><?php echo pqSafe($definition['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label fw-semibold" for="visibility">Visibility</label>
                <select class="form-select" id="visibility" name="visibility">
                    <option value="" <?php echo $visibilityFilter === '' ? 'selected' : ''; ?>>All Visibility</option>
                    <option value="visible" <?php echo $visibilityFilter === 'visible' ? 'selected' : ''; ?>>Visible</option>
                    <option value="hidden" <?php echo $visibilityFilter === 'hidden' ? 'selected' : ''; ?>>Hidden</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label fw-semibold" for="source">Source</label>
                <select class="form-select" id="source" name="source">
                    <option value="" <?php echo $sourceFilter === '' ? 'selected' : ''; ?>>All Sources</option>
                    <option value="ebay" <?php echo $sourceFilter === 'ebay' ? 'selected' : ''; ?>>eBay</option>
                    <option value="manual" <?php echo $sourceFilter === 'manual' ? 'selected' : ''; ?>>Manual</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter me-1"></i>Filter
                </button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <a class="quality-card quality-issue-card p-3 h-100 <?php echo $issueFilter === 'ready' ? 'active' : ''; ?>" href="<?php echo pqSafe(pqIssueUrl($baseParams, ['issue' => 'ready', 'page' => 1])); ?>">
            <div class="d-flex justify-content-between gap-3">
                <div>
                    <div class="small text-muted">Ready Products</div>
                    <div class="h4 mb-0"><?php echo number_format($summary['ready']); ?></div>
                    <div class="small text-muted">Visible with required data</div>
                </div>
                <span class="quality-icon"><i class="fas fa-circle-check"></i></span>
            </div>
        </a>
    </div>
    <?php foreach ($issueDefinitions as $issueKey => $definition): ?>
        <div class="col-sm-6 col-xl-3">
            <a class="quality-card quality-issue-card p-3 h-100 <?php echo $issueFilter === $issueKey ? 'active' : ''; ?>" href="<?php echo pqSafe(pqIssueUrl($baseParams, ['issue' => $issueKey, 'page' => 1])); ?>">
                <div class="d-flex justify-content-between gap-3">
                    <div>
                        <div class="small text-muted"><?php echo pqSafe($definition['label']); ?></div>
                        <div class="h4 mb-0"><?php echo number_format($issueCounts[$issueKey] ?? 0); ?></div>
                        <div class="small text-muted"><?php echo pqSafe($definition['note']); ?></div>
                    </div>
                    <span class="quality-icon"><i class="fas <?php echo pqSafe($definition['icon']); ?>"></i></span>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm" data-aos="fade-up">
    <div class="card-header bg-white d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
        <div>
            <h5 class="mb-1"><i class="fas fa-list-check text-danger me-2"></i>Products To Review</h5>
            <div class="small text-muted">
                Showing <?php echo number_format($pageStart); ?>&ndash;<?php echo number_format($pageEnd); ?> of <?php echo number_format($filteredCount); ?> matching products.
            </div>
        </div>
        <a href="product-quality.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-rotate-left me-1"></i>Reset
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle quality-table mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Score</th>
                        <th>Missing / Review Items</th>
                        <th>Shipping Data</th>
                        <th>Fitment / Category</th>
                        <th>Visibility</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pageProducts as $row): ?>
                        <?php
                        $product = $row['product'];
                        $issues = $row['issues'];
                        $score = $row['score'];
                        $image = $row['images'][0] ?? '';
                        $visibilityLabel = (int)($product['show_on_website'] ?? 0) === 1 ? 'Visible' : 'Hidden';
                        $visibilityClass = $visibilityLabel === 'Visible' ? 'success' : 'secondary';
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <span class="quality-product-thumb">
                                        <?php if ($image !== ''): ?>
                                            <img src="<?php echo pqSafe($image); ?>" alt="">
                                        <?php else: ?>
                                            <i class="fas fa-image text-muted"></i>
                                        <?php endif; ?>
                                    </span>
                                    <span>
                                        <span class="quality-product-name" title="<?php echo pqSafe($product['name'] ?? ''); ?>"><?php echo pqSafe($product['name'] ?? 'Untitled product'); ?></span>
                                        <span class="quality-muted-line">
                                            SKU: <?php echo pqSafe(trim((string)($product['sku'] ?? '')) !== '' ? $product['sku'] : 'Missing'); ?>
                                            &middot; <?php echo pqSafe($product['source'] ?? 'manual'); ?>
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td class="quality-score">
                                <div class="fw-bold text-<?php echo pqSafe($row['score_class']); ?>"><?php echo number_format($score); ?>%</div>
                                <div class="progress" style="height: .45rem;">
                                    <div class="progress-bar bg-<?php echo pqSafe($row['score_class']); ?>" role="progressbar" style="width: <?php echo (int)$score; ?>%;" aria-valuenow="<?php echo (int)$score; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </td>
                            <td>
                                <?php if (empty($issues)): ?>
                                    <span class="badge text-bg-success">Complete</span>
                                <?php else: ?>
                                    <?php foreach ($issues as $issue): ?>
                                        <span class="badge text-bg-<?php echo pqSafe($issue['class']); ?> quality-issue-pill" title="<?php echo pqSafe($issue['note']); ?>"><?php echo pqSafe($issue['label']); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="small">
                                    Weight: <strong><?php echo pqSafe(pqFieldValue($product['weight'] ?? null, ' lb')); ?></strong>
                                </div>
                                <div class="small text-muted">
                                    L/W/H:
                                    <?php echo pqSafe(pqFieldValue($product['length'] ?? null, ' in')); ?> /
                                    <?php echo pqSafe(pqFieldValue($product['width'] ?? null, ' in')); ?> /
                                    <?php echo pqSafe(pqFieldValue($product['height'] ?? null, ' in')); ?>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <?php echo pqSafe(trim(implode(' ', array_filter([$product['manufacturer'] ?? '', $product['model'] ?? '']))) ?: 'Missing fitment'); ?>
                                </div>
                                <div class="small text-muted">
                                    <?php echo pqSafe(trim((string)($product['category'] ?? '')) !== '' ? $product['category'] : 'Missing category'); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge text-bg-<?php echo pqSafe($visibilityClass); ?>"><?php echo pqSafe($visibilityLabel); ?></span>
                            </td>
                            <td class="text-end text-nowrap">
                                <div class="btn-group btn-group-sm">
                                    <a href="products.php?action=edit&id=<?php echo (int)$product['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="<?php echo pqSafe(Seo::productUrl($product)); ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener" title="View">
                                        <i class="fas fa-up-right-from-square"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pageProducts)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">No products match the current quality filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white">
            <nav aria-label="Product quality pages">
                <ul class="pagination justify-content-center mb-0 flex-wrap">
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo pqSafe(pqIssueUrl($baseParams, ['page' => $pageNumber])); ?>"><?php echo $pageNumber; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<div class="alert alert-light border small text-muted mt-4">
    Use this dashboard before pushing product updates live. Fix photos and shipping data first, then fitment, SKU/category, and SEO text.
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
