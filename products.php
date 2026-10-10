<?php
require_once __DIR__ . '/includes/storefront-access.php';
$canViewHiddenProducts = fasCanViewHiddenProducts();
$includeHiddenProducts = $canViewHiddenProducts && (($_GET['show_hidden'] ?? '') === '1');

// Now load dependencies and continue normal page rendering
require_once __DIR__ . '/includes/sale-helper.php';
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';
require_once __DIR__ . '/src/models/HomepageCategoryMapping.php';
require_once __DIR__ . '/src/utils/ProductAltText.php';
require_once __DIR__ . '/src/utils/Seo.php';
require_once __DIR__ . '/src/utils/ShippingRules.php';
require_once __DIR__ . '/src/utils/SyncLogger.php';
require_once __DIR__ . '/src/integrations/EbayAPI.php';
require_once __DIR__ . '/includes/product-merchandising.php';

use FAS\Config\Database;
use FAS\Models\Product;
use FAS\Models\HomepageCategoryMapping;
use FAS\Utils\ProductAltText;
use FAS\Utils\Seo;
use FAS\Utils\ShippingRules;
use FAS\Integrations\EbayAPI;

// Normalize image paths to ensure they start with / for local images
function normalizeImagePath($path) {
    if (empty($path)) {
        return $path;
    }
    // If it's an external URL, return as-is
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }
    // If it's a local path without leading /, add it
    if (strpos($path, '/') !== 0) {
        return '/' . $path;
    }
    return $path;
}

function pruneEmptyEbayCategories(array $categories, array $visibleCategoryIds): array
{
    $filtered = [];

    foreach ($categories as $category) {
        $children = !empty($category['children'])
            ? pruneEmptyEbayCategories($category['children'], $visibleCategoryIds)
            : [];

        $categoryId = isset($category['id']) ? (string)$category['id'] : null;
        $hasVisibleProducts = $categoryId !== null && isset($visibleCategoryIds[$categoryId]);

        if ($hasVisibleProducts || !empty($children)) {
            $category['children'] = $children;
            $filtered[] = $category;
        }
    }

    return $filtered;
}

require __DIR__ . '/includes/catalog-load.php';
if ($catalogNotFound) {
    require_once __DIR__ . '/includes/storefront-not-found.php';
    fasStorefrontNotFound();
}
require __DIR__ . '/includes/catalog-meta.php';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid my-5">
    <div class="row">
        <!-- Sidebar with eBay Categories -->
        <div class="col-lg-3 col-md-4 mb-4">
            <div class="card">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Categories</h5>
                    <button class="btn btn-sm btn-outline-light d-md-none" type="button" id="categoryToggle" aria-controls="categoryMenu" aria-expanded="true" aria-label="Toggle categories">
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="card-body p-0 collapse show" id="categoryMenu">
                    <div class="list-group list-group-flush">
                        <!-- All Products Link -->
                        <a href="/products" data-category="" class="category-link list-group-item list-group-item-action <?php echo (!$ebayCat1 && !$ebayCat2 && !$ebayCat3 && !$homepageCategory && $discoveryCollection === '') ? 'active' : ''; ?>">
                            <i class="fas fa-th"></i> All Products
                            <?php if (!$ebayCat1 && !$ebayCat2 && !$ebayCat3 && !$homepageCategory && $discoveryCollection === ''): ?>
                                <span class="badge bg-danger float-end"><?php echo $totalProducts; ?></span>
                            <?php endif; ?>
                        </a>
<?php if ($homepageCategory): ?>
<a href="/products/<?php echo htmlspecialchars($homepageCategory); ?>" class="list-group-item list-group-item-action active"><?php echo htmlspecialchars(\FAS\Models\HomepageCategoryMapping::HOMEPAGE_CATEGORIES[$homepageCategory]); ?></a>
<?php endif; ?>
<a href="/products/trending" data-collection="trending" class="category-link list-group-item list-group-item-action ps-4 <?php echo $discoveryCollection === 'trending' ? 'active' : ''; ?>">
<i class="fas fa-chart-line"></i> Trending Parts
</a>
<a href="/products/best-sellers" data-collection="best" class="category-link list-group-item list-group-item-action ps-4 <?php echo $discoveryCollection === 'best' ? 'active' : ''; ?>">
<i class="fas fa-star"></i> Best Sellers
</a>
<a href="/products/recent-arrivals" data-collection="recent" class="category-link list-group-item list-group-item-action ps-4 <?php echo $discoveryCollection === 'recent' ? 'active' : ''; ?>">
<i class="fas fa-clock"></i> Recent Arrivals
</a>
<a href="/products/free-shipping" data-collection="free_shipping" class="category-link list-group-item list-group-item-action ps-4 <?php echo $discoveryCollection === 'free_shipping' ? 'active' : ''; ?>">
<i class="fas fa-truck-fast"></i> Free Shipping Eligible
</a>
<a href="/products/sale" data-collection="sale" class="category-link list-group-item list-group-item-action ps-4 <?php echo $discoveryCollection === 'sale' ? 'active' : ''; ?>">
<i class="fas fa-percent"></i> On Sale
</a>

                        <?php if (!empty($ebayCategories)): ?>
                            <?php foreach ($ebayCategories as $cat1): ?>
                                <!-- Level 1 Category -->
                                <a href="#" data-cat1="<?php echo $cat1['id']; ?>"
                                   class="category-link list-group-item list-group-item-action <?php echo $ebayCat1 == $cat1['id'] && !$ebayCat2 ? 'active' : ''; ?>"
                                   style="font-weight: bold;">
                                    <i class="fas fa-folder"></i> <?php echo htmlspecialchars($cat1['name']); ?>
                                </a>

                                <!-- Level 2 Categories (show ONLY if THIS level 1 is selected) -->
                                <?php if ($ebayCat1 == $cat1['id'] && !empty($cat1['children'])): ?>
                                    <?php foreach ($cat1['children'] as $cat2): ?>
                                        <a href="#" data-cat1="<?php echo $cat1['id']; ?>" data-cat2="<?php echo $cat2['id']; ?>"
                                           class="category-link list-group-item list-group-item-action ps-4 <?php echo $ebayCat2 == $cat2['id'] && !$ebayCat3 ? 'active' : ''; ?>">
                                            <i class="fas fa-folder-open"></i> <?php echo htmlspecialchars($cat2['name']); ?>
                                        </a>

                                        <!-- Level 3 Categories (show ONLY if THIS level 2 is selected) -->
                                        <?php if ($ebayCat2 == $cat2['id'] && !empty($cat2['children'])): ?>
                                            <?php foreach ($cat2['children'] as $cat3): ?>
                                                <a href="#" data-cat1="<?php echo $cat1['id']; ?>" data-cat2="<?php echo $cat2['id']; ?>" data-cat3="<?php echo $cat3['id']; ?>"
                                                   class="category-link list-group-item list-group-item-action ps-5 <?php echo $ebayCat3 == $cat3['id'] ? 'active' : ''; ?>">
                                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($cat3['name']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="list-group-item text-muted">
                                <small>Categories will appear after eBay sync</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9 col-md-8" id="productsContent">
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <h1 class="fw-bold">
<?php if ($search): ?>
Search Results for "<?php echo htmlspecialchars($search); ?>"
<?php elseif ($manufacturer || $fitmentModel): ?>
<?php echo htmlspecialchars(trim(($manufacturer ?? '') . ' ' . ($fitmentModel ?? ''))); ?> Parts
<?php else: ?>
<?php echo htmlspecialchars($currentCategoryName); ?>
<?php endif; ?>
                    </h1>
<p class="text-muted mb-2">
    <?php echo $totalProducts; ?> products found<?php echo $includeHiddenProducts ? ' including hidden products' : ''; ?>
</p>
<?php if ($canViewHiddenProducts): ?>
    <?php
    $hiddenToggleParams = $_GET;
    unset($hiddenToggleParams['page']);
    if ($includeHiddenProducts) {
        unset($hiddenToggleParams['show_hidden']);
        $hiddenToggleLabel = 'Hide hidden products';
        $hiddenToggleClass = 'btn-outline-secondary';
    } else {
        $hiddenToggleParams['show_hidden'] = '1';
        $hiddenToggleLabel = 'Show hidden products';
        $hiddenToggleClass = 'btn-outline-danger';
    }
    $hiddenToggleUrl = '/products' . (!empty($hiddenToggleParams) ? '?' . http_build_query($hiddenToggleParams) : '');
    ?>
    <a href="<?php echo htmlspecialchars($hiddenToggleUrl); ?>" class="btn btn-sm <?php echo $hiddenToggleClass; ?>">
        <i class="fas fa-eye-slash me-1"></i><?php echo htmlspecialchars($hiddenToggleLabel); ?>
    </a>
<?php endif; ?>
</div>
                <div class="col-md-6">
                    <!-- Search Box -->
                    <form method="get" action="/products" id="search-form">
<?php if ($homepageCategory): ?><input type="hidden" name="category" value="<?php echo htmlspecialchars($homepageCategory); ?>"><?php endif; ?>
                        <?php if ($ebayCat1): ?><input type="hidden" name="cat1" value="<?php echo htmlspecialchars($ebayCat1, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
<?php if ($ebayCat2): ?><input type="hidden" name="cat2" value="<?php echo htmlspecialchars($ebayCat2, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
<?php if ($ebayCat3): ?><input type="hidden" name="cat3" value="<?php echo htmlspecialchars($ebayCat3, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
<?php if ($manufacturer): ?><input type="hidden" name="manufacturer" value="<?php echo htmlspecialchars($manufacturer); ?>"><?php endif; ?>
<?php if ($fitmentModel): ?><input type="hidden" name="model" value="<?php echo htmlspecialchars($fitmentModel); ?>"><?php endif; ?>
<?php if ($includeHiddenProducts): ?><input type="hidden" name="show_hidden" value="1"><?php endif; ?>
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Search products..." id="product-search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>">
                            <?php if ($search): ?>
                                <?php
                                    $clearUrl = '/products';
                                    $clearParams = [];
if ($homepageCategory) $clearParams[] = 'category=' . urlencode($homepageCategory);
                                    if ($ebayCat1) $clearParams[] = 'cat1=' . urlencode($ebayCat1);
if ($ebayCat2) $clearParams[] = 'cat2=' . urlencode($ebayCat2);
if ($ebayCat3) $clearParams[] = 'cat3=' . urlencode($ebayCat3);
if ($manufacturer) $clearParams[] = 'manufacturer=' . urlencode($manufacturer);
if ($fitmentModel) $clearParams[] = 'model=' . urlencode($fitmentModel);
if ($includeHiddenProducts) $clearParams[] = 'show_hidden=1';
if (!empty($clearParams)) $clearUrl .= '?' . implode('&', $clearParams);
                                ?>
                                <a href="<?php echo htmlspecialchars($clearUrl); ?>" class="btn btn-outline-secondary" title="Clear search">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            <?php endif; ?>
<button class="btn btn-danger" type="submit">
<i class="fas fa-search"></i> Search
</button>
<button class="btn btn-outline-danger saved-search-save" type="button">
<i class="fas fa-bookmark"></i> Save
</button>
</div>
</form>
</div>
</div>

<!-- Fitment Filters -->
<?php if (!empty($allManufacturers) || !empty($allModels)): ?>
<div class="row mb-4 g-3">
<div class="col-md-6">
                            <label class="form-label fw-bold" for="manufacturerFilter">Make / Manufacturer</label>
                            <select class="form-select" id="manufacturerFilter">
                                <option value="">All Makes / Manufacturers</option>
<?php foreach ($allManufacturers as $mfg): ?>
<option value="<?php echo htmlspecialchars($mfg); ?>"
<?php echo $manufacturer === $mfg ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($mfg); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
                            <label class="form-label fw-bold" for="modelFilter">Model / part number</label>
                            <select class="form-select" id="modelFilter" aria-describedby="modelFilterHelp" <?php echo empty($allModels) ? 'disabled' : ''; ?>>
                                <option value="">All models / part numbers</option>
<?php foreach ($allModels as $modelOption): ?>
<option value="<?php echo htmlspecialchars($modelOption); ?>"
<?php echo $fitmentModel === $modelOption ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($modelOption); ?>
</option>
<?php endforeach; ?>
</select>
<p class="form-text mb-0" id="modelFilterHelp">Matches source listing values. A matching model or part number does not confirm vehicle compatibility.</p>
</div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4 saved-searches-card">
<div class="card-body py-3">
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
<div>
<h2 class="h6 fw-bold mb-1"><i class="fas fa-bookmark text-danger me-2"></i>Saved Searches</h2>
<div class="small text-muted">Save model, part number, keyword, and category searches on this device for quick return visits.</div>
</div>
<div id="savedSearches" class="d-flex flex-wrap gap-2 justify-content-lg-end"></div>
</div>
</div>
</div>

<?php require __DIR__ . '/includes/catalog-intro.php'; ?>

<!-- Products Grid -->
<?php if (empty($products)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-flex align-items-start gap-3">
                        <i class="fas fa-search text-danger fs-3 mt-1"></i>
                        <div>
                            <h4 class="mb-2">No exact matches found</h4>
                            <p class="text-muted mb-3">
                                We checked close spellings and related terms. Try one related search, remove a filter, or browse high-demand categories below.
                                Inventory changes often, so recently added and popular parts may still fit your project.
                            </p>
                        <?php if (!empty($searchSuggestions)): ?>
                            <div class="mb-3">
                                <div class="small fw-semibold text-muted mb-2">Try a related search:</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($searchSuggestions as $suggestion): ?>
                                        <?php
                                        $suggestionParams = $_GET;
                                        $suggestionParams['search'] = $suggestion;
                                        unset($suggestionParams['page'], $suggestionParams['collection']);
                                        ?>
                                        <a href="/products?<?php echo htmlspecialchars(http_build_query($suggestionParams)); ?>" class="btn btn-outline-secondary btn-sm">
                                            <?php echo htmlspecialchars($suggestion); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
<?php endif; ?>
<form class="saved-search-notify-form border rounded-3 p-3 mb-3" data-saved-search-notify>
<div class="small fw-semibold mb-2"><i class="fas fa-bell text-danger me-1"></i>Notify me when similar parts arrive</div>
<div class="row g-2 align-items-start">
<div class="col-lg">
<input type="email" class="form-control form-control-sm" name="email" placeholder="Email address" autocomplete="email" required>
</div>
<div class="col-lg-auto">
<button class="btn btn-danger btn-sm w-100" type="submit">Notify Me</button>
</div>
</div>
<label class="form-check small text-muted mt-2 mb-0">
<input class="form-check-input" type="checkbox" name="consent" value="1" required>
I agree to be contacted only about matching Flip and Strip inventory.
</label>
<div class="saved-search-notify-status small mt-2" aria-live="polite"></div>
</form>
<div class="d-flex flex-wrap gap-2">
<a href="/products/motorcycle" class="btn btn-outline-danger btn-sm">Motorcycle Parts</a>
                                <a href="/products/atv" class="btn btn-outline-danger btn-sm">ATV / UTV Parts</a>
                                <a href="/products/boat" class="btn btn-outline-danger btn-sm">Boat Parts</a>
                                <a href="/products/automotive" class="btn btn-outline-danger btn-sm">Automotive Parts</a>
                                <a href="/products" class="btn btn-danger btn-sm">View All Inventory</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if (!empty($noResultsProducts)): ?>
            <section class="mb-5" aria-labelledby="no-results-recommendations">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <p class="text-danger text-uppercase fw-semibold small mb-1">Recommended Starting Points</p>
                        <h2 id="no-results-recommendations" class="h4 fw-bold mb-0">Popular Parts Shoppers Are Viewing</h2>
                    </div>
                    <a href="/products" class="btn btn-outline-danger btn-sm">Browse All</a>
                </div>
                <div class="row g-4">
                    <?php foreach ($noResultsProducts as $index => $recommendedProduct): ?>
                        <?php echo fasProductCard($recommendedProduct, 'col-lg-4 col-md-6 col-sm-12', min($index * 50, 300)); ?>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            <?php else: ?>
            <div class="row g-4">
                <?php foreach ($products as $index => $product): ?>
<?php $displayIdentifiers = \FAS\Utils\ProductIdentifierOutput::forProduct($product, (string)($product['manufacturer'] ?? ''), (string)($product['model'] ?? '')); ?>
                    <?php
                    // Staggered animation with max delay cap of 400ms
                    $delay = min(($index % 8) * 50, 400);
                    $productFreeShipping = ShippingRules::productQualifiesForFreeShipping($product);
                    $productUrl = fasProductCardUrl($product);

                    // Normalize image path for display
                            $imageUrl = normalizeImagePath($product['image_url'] ?? null);
                            if (empty($imageUrl)) {
                                $imageUrl = '/gallery/default.jpg';
                            }
                            $imageAltText = ProductAltText::forProductImage($product);
                            ?>
                    <div class="col-lg-4 col-md-6 col-sm-12" data-aos="fade-up" data-aos-delay="<?php echo $delay; ?>">
                        <div class="card product-card h-100">
                            <a href="<?php echo htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">
                                <div class="position-relative">
                                    <?php
                                    // Check if image is external or local
                                    $isExternal = strpos($imageUrl, 'http://') === 0 || strpos($imageUrl, 'https://') === 0;
                                    $hasImage = !empty($imageUrl) && (
                                        $isExternal ||
                                        file_exists(__DIR__ . $imageUrl)
                                    );
                                    ?>
                                    <?php if ($hasImage): ?>
<img <?php echo \FAS\Utils\ResponsiveImage::attributes($imageUrl, '(min-width: 992px) 25vw, (min-width: 768px) 38vw, 100vw', 0, null, (string)($product['id'] ?? '')); ?>
class="card-img-top product-image"
alt="<?php echo htmlspecialchars($imageAltText); ?>"
loading="lazy"
decoding="async"
style="cursor: pointer;">
                                    <?php else: ?>
                                        <div class="product-image bg-light d-flex align-items-center justify-content-center" style="cursor: pointer;">
                                            <i class="fas fa-image text-muted display-4"></i>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($product['condition_name'])): ?>
                                        <span class="badge bg-info product-badge"><?php echo htmlspecialchars($product['condition_name']); ?></span>
                                    <?php endif; ?>
                                    <?php
                                    $priceInfo = getEffectivePrice((float)$product['price'], !empty($product['sale_price']) ? (float)$product['sale_price'] : null);
                                    if ($priceInfo['on_sale']):
                                    ?>
<span class="badge bg-danger product-badge" style="top: <?php echo !empty($product['condition_name']) ? '50px' : '10px'; ?>;"><?php echo htmlspecialchars($priceInfo['sale_label']); ?></span>
<?php endif; ?>
<?php if ($productFreeShipping): ?>
<span class="badge bg-success product-badge" style="top: <?php echo !empty($product['condition_name']) && $priceInfo['on_sale'] ? '90px' : (!empty($product['condition_name']) || $priceInfo['on_sale'] ? '50px' : '10px'); ?>;">
<i class="fas fa-truck-fast me-1"></i>Free Ship*
</span>
<?php endif; ?>
</div>
                            </a>
                            <div class="card-body d-flex flex-column">
                                <h6 class="card-title">
                                    <a href="<?php echo htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none text-dark">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </a>
                                </h6>
                                <p class="card-text text-muted small flex-grow-1">
                                    <?php
                                    // Show eBay store category path if available
                                    $catPath = $productModel->getEbayStoreCategoryPath($product);
                                    if ($catPath): ?>
                                        <strong>Category:</strong> <?php echo htmlspecialchars($catPath); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($displayIdentifiers['brand'])): ?>
                                        <strong>Mfg:</strong> <?php echo htmlspecialchars($displayIdentifiers['brand']); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($displayIdentifiers['mpn'])): ?>
                                        <strong>Model / part number:</strong> <?php echo htmlspecialchars($displayIdentifiers['mpn']); ?>
                                    <?php endif; ?>
                                </p>
                                <div class="mt-auto">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <?php if ($priceInfo['on_sale']): ?>
                                                <span class="product-price text-danger">$<?php echo number_format($priceInfo['effective_price'], 2); ?></span>
                                                <small class="text-muted text-decoration-line-through ms-1">$<?php echo number_format($priceInfo['original_price'], 2); ?></small>
                                            <?php else: ?>
                                                <span class="product-price">$<?php echo number_format($priceInfo['original_price'], 2); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                                    </div>
                                    <button class="btn btn-danger w-100 add-to-cart" <?php echo (int)($product['quantity']??0)>0?'':'disabled'; ?>
                                            data-id="<?php echo $product['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                            data-price="<?php echo $priceInfo['effective_price']; ?>"
                                            data-image="<?php echo htmlspecialchars($imageUrl); ?>"
                                            data-image-alt="<?php echo htmlspecialchars($imageAltText); ?>"
                                data-sku="<?php echo htmlspecialchars($product['sku']); ?>"
data-category="<?php echo htmlspecialchars($product['ebay_store_cat3_name'] ?? $product['ebay_store_cat2_name'] ?? $product['ebay_store_cat1_name'] ?? $product['category'] ?? ''); ?>"
data-manufacturer="<?php echo htmlspecialchars($displayIdentifiers['brand'] ?? ''); ?>"
data-source="<?php echo htmlspecialchars($product['source'] ?? ''); ?>"
data-condition="<?php echo htmlspecialchars($product['condition_name'] ?? ''); ?>"
data-weight="<?php echo !empty($product['weight']) ? floatval($product['weight']) : 1.0; ?>"
                                            data-length="<?php echo !empty($product['length']) ? floatval($product['length']) : 10.0; ?>"
data-width="<?php echo !empty($product['width']) ? floatval($product['width']) : 10.0; ?>"
data-height="<?php echo !empty($product['height']) ? floatval($product['height']) : 10.0; ?>"
data-free-shipping="<?php echo $productFreeShipping ? '1' : '0'; ?>"
data-stock="<?php echo isset($product['quantity']) ? intval($product['quantity']) : 999; ?>">
                                        <i class="fas fa-cart-plus"></i> <?php echo (int)($product['quantity']??0)>0?'Add to Cart':'Out of stock'; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Product pagination" class="mt-5">
                    <ul class="pagination justify-content-center flex-wrap">
                        <?php

                        // Smart pagination: show first, last, current and nearby pages with ellipsis
                        $paginationRange = 2;
                        $maxPagesToShowAll = 7;
                        $showPages = [];

                        if ($totalPages <= $maxPagesToShowAll) {
                            for ($i = 1; $i <= $totalPages; $i++) {
                                $showPages[] = $i;
                            }
                        } else {
                            $showPages[] = 1;
                            for ($i = max(2, $page - $paginationRange); $i <= min($totalPages - 1, $page + $paginationRange); $i++) {
                                $showPages[] = $i;
                            }
                            if ($totalPages > 1) {
                                $showPages[] = $totalPages;
                            }
                            $showPages = array_unique($showPages);
                            sort($showPages);
                        }
                        ?>

                        <!-- Previous Button -->
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($paginationUrl($page - 1), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>

                        <?php
                        $prevPage = 0;
                        foreach ($showPages as $i):
                            if ($i - $prevPage > 1): ?>
                                <li class="page-item disabled d-none d-sm-block">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>

                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($paginationUrl($i), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                            </li>

                            <?php $prevPage = $i; ?>
                        <?php endforeach; ?>

                        <!-- Next Button -->
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($paginationUrl($page + 1), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="/public/js/catalog-navigation.js?v=<?php echo filemtime(__DIR__ . '/public/js/catalog-navigation.js'); ?>"></script>
<script>
window.FAS_PRODUCTS_PAGE_DATA = <?php echo Seo::schemaJson([
    'page_type' => $discoveryCollection !== '' ? 'collection' : ($isCuratedFitmentLanding ? 'fitment_landing' : ($homepageCategory ? 'category' : 'products')),
    'collection' => $discoveryCollection,
    'category' => $homepageCategory,
    'manufacturer' => $manufacturerName,
    'model' => $fitmentModelName,
    'product_count' => $totalProducts,
    'canonical_url' => $canonicalUrl,
]); ?>;

function trackProductsLandingPage() {
    if (window.fasAnalytics && typeof window.fasAnalytics.track === 'function' && window.FAS_PRODUCTS_PAGE_DATA.page_type !== 'products') {
        window.fasAnalytics.track('landing_page_view', window.FAS_PRODUCTS_PAGE_DATA, { immediate: true });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', trackProductsLandingPage);
} else {
    setTimeout(trackProductsLandingPage, 0);
}

// Mobile category menu toggle
document.getElementById('categoryToggle').addEventListener('click', function() {
    const menu = document.getElementById('categoryMenu');
    const icon = this.querySelector('i');

    if (menu.classList.contains('show')) {
        menu.classList.remove('show');
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    } else {
        menu.classList.add('show');
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
    }
    this.setAttribute('aria-expanded', String(menu.classList.contains('show')));
});

const savedSearchStorageKey = 'fas_saved_product_searches';

function getSavedSearches() {
    try {
        return JSON.parse(localStorage.getItem(savedSearchStorageKey) || '[]').filter(item => item && item.url && item.label);
    } catch (error) {
        return [];
    }
}

function setSavedSearches(searches) {
    localStorage.setItem(savedSearchStorageKey, JSON.stringify(searches.slice(0, 8)));
}

function currentSavedSearch() {
    const params = fasCatalogParamsFromUrl(window.location.href);
    params.delete('page');
    const hasSearch = ['search', 'manufacturer', 'model', 'cat1', 'cat2', 'cat3'].some(key => params.has(key));
    if (!hasSearch) {
        return null;
    }

    const title = document.querySelector('#productsContent h1')?.textContent.trim().replace(/\s+/g, ' ') || 'Saved parts search';
    const keyword = params.get('search');
    const fitment = [params.get('manufacturer'), params.get('model')].filter(Boolean).join(' ');
    const label = keyword ? `Search: ${keyword}` : (fitment || title || 'Saved parts search');
    return {
        label,
        url: fasCatalogNavigationUrl(params),
        saved_at: Date.now()
    };
}

function renderSavedSearches() {
    const container = document.getElementById('savedSearches');
    if (!container) return;

    const searches = getSavedSearches();
    container.innerHTML = '';

    if (searches.length === 0) {
        container.innerHTML = '<span class="small text-muted">No saved searches yet.</span>';
        return;
    }

    searches.forEach((search, index) => {
        const wrapper = document.createElement('span');
        wrapper.className = 'btn-group btn-group-sm saved-search-pill';
        wrapper.innerHTML = `
            <a class="btn btn-outline-danger" href="${search.url}">${search.label}</a>
            <button class="btn btn-outline-secondary saved-search-remove" type="button" data-saved-search-index="${index}" aria-label="Remove saved search">&times;</button>
        `;
        container.appendChild(wrapper);
    });
}

document.addEventListener('click', event => {
    const saveButton = event.target.closest('.saved-search-save');
    if (saveButton) {
        const savedSearch = currentSavedSearch();
        if (!savedSearch) {
            saveButton.blur();
            return;
        }

        const searches = getSavedSearches().filter(item => item.url !== savedSearch.url);
        searches.unshift(savedSearch);
        setSavedSearches(searches);
        renderSavedSearches();
        if (window.fasAnalytics && typeof window.fasAnalytics.track === 'function') {
            window.fasAnalytics.track('saved_search_saved', {
                label: savedSearch.label,
                search_url: savedSearch.url,
                source: 'products_page'
            });
        }
        saveButton.innerHTML = '<i class="fas fa-check"></i> Saved';
        setTimeout(() => {
            saveButton.innerHTML = '<i class="fas fa-bookmark"></i> Save';
        }, 1600);
        return;
    }

    const removeButton = event.target.closest('.saved-search-remove');
    if (removeButton) {
        const index = Number.parseInt(removeButton.dataset.savedSearchIndex || '-1', 10);
        const searches = getSavedSearches();
        if (index >= 0) {
            searches.splice(index, 1);
            setSavedSearches(searches);
            renderSavedSearches();
        }
    }
});

document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-saved-search-notify]');
    if (!form) {
        return;
    }

    event.preventDefault();
    const status = form.querySelector('.saved-search-notify-status');
    const submitButton = form.querySelector('[type="submit"]');
    const params = fasCatalogParamsFromUrl(window.location.href);
    const savedSearch = currentSavedSearch() || {
        label: document.querySelector('#productsContent h1')?.textContent.trim() || 'Parts request',
        url: window.location.pathname + window.location.search
    };
    const payload = {
        email: form.email.value.trim(),
        consent: form.consent.checked,
        label: savedSearch.label,
        url: savedSearch.url,
        search: params.get('search') || '',
        manufacturer: params.get('manufacturer') || '',
        model: params.get('model') || '',
        category: params.get('category') || window.FAS_PRODUCTS_PAGE_DATA?.category || '',
        collection: params.get('collection') || window.FAS_PRODUCTS_PAGE_DATA?.collection || '',
        no_results: true,
        page_url: window.location.href,
        source_page: window.location.pathname
    };

    if (status) {
        status.className = 'saved-search-notify-status small mt-2 text-muted';
        status.textContent = 'Saving request...';
    }
    if (submitButton) {
        submitButton.disabled = true;
    }

    try {
        const response = await fetch('/api/saved-search.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Unable to save request.');
        }

        if (status) {
            status.className = 'saved-search-notify-status small mt-2 text-success';
            status.textContent = data.message || 'Saved. We will use this request when similar parts arrive.';
        }
        form.reset();
        if (window.fasAnalytics && typeof window.fasAnalytics.track === 'function') {
            window.fasAnalytics.track('saved_search_submitted', {
                label: payload.label,
                search_url: payload.url,
                search_query: payload.search,
                source: 'no_results'
            }, { immediate: true });
        }
    } catch (error) {
        if (status) {
            status.className = 'saved-search-notify-status small mt-2 text-danger';
            status.textContent = error.message || 'Unable to save request right now.';
        }
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
});

// AJAX category filtering
function attachCategoryHandlers() {
    document.querySelectorAll('.category-link').forEach(link => {
        link.addEventListener('click', function(e) {
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            e.preventDefault();

            // Build URL parameters
            const params = fasCatalogParamsFromUrl(window.location.href);

            // Reset category parameters
            params.delete('category');
            params.delete('cat1');
            params.delete('cat2');
            params.delete('cat3');
            params.delete('collection');
            params.delete('page'); // Reset to first page

            // Add new category parameters
            const cat1 = this.dataset.cat1;
            const cat2 = this.dataset.cat2;
            const cat3 = this.dataset.cat3;
            const collection = this.dataset.collection;

            if (collection) {
                params.set('collection', collection);
                params.delete('search');
                params.delete('manufacturer');
                params.delete('model');
            } else {
                if (cat1) params.set('cat1', cat1);
                if (cat2) params.set('cat2', cat2);
                if (cat3) params.set('cat3', cat3);
            }

            // Update browser URL without refresh
            const newUrl = fasCatalogNavigationUrl(params);
            window.history.pushState({}, '', newUrl);

            // Load products AND sidebar via AJAX
            loadProductsAndSidebar(params);

            // Scroll to products on mobile for better UX
            if (window.innerWidth < 768) {
                setTimeout(() => {
                    document.getElementById('productsContent').scrollIntoView({ behavior: 'smooth' });
                }, 100);
            }
        });
    });
}

// Initialize category handlers on page load
attachCategoryHandlers();

// Load products and sidebar via AJAX
let catalogRequestController;
async function loadProductsAndSidebar(params) {
    if (catalogRequestController) catalogRequestController.abort();
    const controller = new AbortController();
    catalogRequestController = controller;
    const content = document.getElementById('productsContent');
    content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-danger" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    try {
        const response = await fetch('/api/products.php?' + params.toString() + '&include_sidebar=1', {signal: controller.signal});
        if (!response.ok) throw new Error('Catalog request failed');
        const data = await response.json();
        if (!data.success || !data.html) throw new Error('Catalog response incomplete');
        if (controller !== catalogRequestController) return;
        content.innerHTML = data.html;
        content.querySelectorAll('[data-aos]').forEach(el => {
            ['data-aos','data-aos-delay','data-aos-duration','data-aos-offset'].forEach(attr => el.removeAttribute(attr));
            el.classList.remove('aos-init', 'aos-animate');
            ['opacity','transform','transition-property'].forEach(prop => el.style.removeProperty(prop));
        });
        if (data.sidebar) {
            const sidebar = document.querySelector('#categoryMenu .list-group');
            if (sidebar) sidebar.innerHTML = data.sidebar;
            attachCategoryHandlers();
        }
        fasApplyCatalogMetadata(data.metadata);
        if (data.landing) window.FAS_PRODUCTS_PAGE_DATA = data.landing;
        attachPaginationHandlers();
        attachManufacturerFilterHandler();
        attachModelFilterHandler();
        renderSavedSearches();
        if (window.fasAnalytics?.refreshProductImpressions) window.fasAnalytics.refreshProductImpressions();
    } catch (error) {
        if (error.name === 'AbortError' || controller !== catalogRequestController) return;
        // Ordinary navigation remains the fallback when the optional AJAX request fails.
        window.location.assign(window.location.href);
    }
}

// Attach pagination handlers
function attachPaginationHandlers() {
    document.querySelectorAll('.pagination .page-link').forEach(link => {
        link.addEventListener('click', function(e) {
            if (this.parentElement.classList.contains('disabled')) {
                e.preventDefault();
                return;
            }

            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
            e.preventDefault();
            const url = new URL(this.href);
            const params = fasCatalogParamsFromUrl(url);

            // Update browser URL
            window.history.pushState({}, '', url.pathname + url.search);

            // Load products
            loadProductsAndSidebar(params);

            // Scroll to top of products
            document.getElementById('productsContent').scrollIntoView({ behavior: 'smooth' });
        });
    });
}

// Handle browser back/forward buttons
window.addEventListener('popstate', function() {
    const params = fasCatalogParamsFromUrl(window.location.href);
    loadProductsAndSidebar(params);
});

// Manufacturer filter change handler function
function handleManufacturerChange() {
    const params = fasCatalogParamsFromUrl(window.location.href);
    const mfg = this.value;

    if (mfg) {
        params.set('manufacturer', mfg);
    } else {
        params.delete('manufacturer');
    }
    params.delete('collection');
    params.delete('manufacturer_slug');
    params.delete('model_slug');
    params.delete('model');
    params.delete('page'); // Reset to first page

    // Update URL and load products
    const newUrl = fasCatalogNavigationUrl(params);
    window.history.pushState({}, '', newUrl);
    loadProductsAndSidebar(params);
}

function handleModelChange() {
    const params = fasCatalogParamsFromUrl(window.location.href);
    const model = this.value;

    if (model) {
        params.set('model', model);
    } else {
        params.delete('model');
    }
    params.delete('collection');
    params.delete('model_slug');
    params.delete('page');

    const newUrl = fasCatalogNavigationUrl(params);
    window.history.pushState({}, '', newUrl);
    loadProductsAndSidebar(params);
}

// Attach manufacturer filter handler
function attachManufacturerFilterHandler() {
    const filterElement = document.getElementById('manufacturerFilter');
    if (filterElement) {
        // Remove any existing listener by cloning and replacing the element
        const newElement = filterElement.cloneNode(true);
        filterElement.parentNode.replaceChild(newElement, filterElement);

        // Add event listener to the new element
        newElement.addEventListener('change', handleManufacturerChange);
    }
}

function attachModelFilterHandler() {
    const filterElement = document.getElementById('modelFilter');
    if (filterElement) {
        const newElement = filterElement.cloneNode(true);
        filterElement.parentNode.replaceChild(newElement, filterElement);

        newElement.addEventListener('change', handleModelChange);
    }
}

// Handle manufacturer filter change
attachManufacturerFilterHandler();
attachModelFilterHandler();
renderSavedSearches();

// Handle search form submission
document.addEventListener('submit', function(e) {
    if (e.target.id !== 'search-form') return;
    e.preventDefault();

    const params = fasCatalogParamsFromUrl(window.location.href);
    const searchTerm = document.getElementById('product-search').value;

    if (searchTerm) {
        params.set('search', searchTerm);
    } else {
        params.delete('search');
    }
    params.delete('collection');
    params.delete('page'); // Reset to first page

    // Update URL and load products
    const newUrl = fasCatalogNavigationUrl(params);
    window.history.pushState({}, '', newUrl);
    loadProductsAndSidebar(params);
});

// Initial pagination handlers
attachPaginationHandlers();
</script>

<style>
/* Mobile category menu styles */
@media (max-width: 767.98px) {
    #categoryMenu.collapse:not(.show) {
        display: none;
    }

    #categoryMenu.collapse.show {
        display: block;
    }
}

/* The mobile toggle is hidden from md upward; always expose its links there. */
@media (min-width: 768px) {
    #categoryMenu.collapse {
        display: block;
    }
}

/* Smooth transition for category menu */
#categoryMenu {
    transition: all 0.3s ease;
}

/* Loading spinner styles */
.spinner-border {
    width: 3rem;
    height: 3rem;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
