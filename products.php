<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';
require_once __DIR__ . '/src/utils/SyncLogger.php';
require_once __DIR__ . '/src/integrations/EbayAPI.php';

use FAS\Config\Database;
use FAS\Models\Product;
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

// Get filter parameters
$ebayCat1 = $_GET['cat1'] ?? null;  // Level 1 eBay category ID
$ebayCat2 = $_GET['cat2'] ?? null;  // Level 2 eBay category ID
$ebayCat3 = $_GET['cat3'] ?? null;  // Level 3 eBay category ID
$manufacturer = $_GET['manufacturer'] ?? null;
$search = $_GET['search'] ?? null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 24;

// Initialize database and product model
$db = Database::getInstance()->getConnection();
$productModel = new Product($db);

// Get eBay categories for sidebar
try {
    $config = require __DIR__ . '/src/config/config.php';
    $ebayAPI = new EbayAPI($config);
    $ebayCategories = $ebayAPI->getStoreCategoriesHierarchical();
    
    // Debug logging
    if (empty($ebayCategories)) {
        error_log("DEBUG: eBay categories are empty after getStoreCategoriesHierarchical()");
        $flatCategories = $ebayAPI->getStoreCategories();
        if ($flatCategories === null) {
            error_log("DEBUG: getStoreCategories() returned NULL - likely token/API issue");
        } elseif (empty($flatCategories)) {
            error_log("DEBUG: getStoreCategories() returned empty array - no categories in eBay store");
        } else {
            error_log("DEBUG: getStoreCategories() returned " . count($flatCategories) . " categories but hierarchy is empty");
        }
    }
} catch (Exception $e) {
    $ebayCategories = [];
    error_log("Failed to load eBay categories: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

// Get products from database using eBay category filter
$products = $productModel->getAllByEbayCategory($page, $perPage, $ebayCat1, $ebayCat2, $ebayCat3, $search, $manufacturer);
$totalProducts = $productModel->getCountByEbayCategory($ebayCat1, $ebayCat2, $ebayCat3, $search, $manufacturer);

// Get unique manufacturers for filter (from all products)
$allManufacturers = $productModel->getManufacturers();

$totalPages = ceil($totalProducts / $perPage);

// Get current category name for display
$currentCategoryName = 'All Products';
if ($ebayCat3 || $ebayCat2 || $ebayCat1) {
    $flatCategories = $ebayAPI->getStoreCategories();
    if ($ebayCat3 && isset($flatCategories[$ebayCat3])) {
        $cat = $flatCategories[$ebayCat3];
        $currentCategoryName = ($cat['topLevel'] ?? '') . ' > ' . ($cat['parent'] ?? '') . ' > ' . $cat['name'];
    } elseif ($ebayCat2 && isset($flatCategories[$ebayCat2])) {
        $cat = $flatCategories[$ebayCat2];
        $currentCategoryName = ($cat['topLevel'] ?? '') . ' > ' . $cat['name'];
    } elseif ($ebayCat1 && isset($flatCategories[$ebayCat1])) {
        $currentCategoryName = $flatCategories[$ebayCat1]['name'] ?? 'Category';
    }
}
?>

<div class="container-fluid my-5">
    <div class="row">
        <!-- Sidebar with eBay Categories -->
        <div class="col-lg-3 col-md-4 mb-4">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Categories</h5>
                </div>
                <div class="card-body p-0" style="max-height: 600px; overflow-y: auto;">
                    <div class="list-group list-group-flush">
                        <!-- All Products Link -->
                        <a href="/products" class="list-group-item list-group-item-action <?php echo (!$ebayCat1 && !$ebayCat2 && !$ebayCat3) ? 'active' : ''; ?>">
                            <i class="fas fa-th"></i> All Products
                            <?php if (!$ebayCat1 && !$ebayCat2 && !$ebayCat3): ?>
                                <span class="badge bg-danger float-end"><?php echo $totalProducts; ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <?php if (!empty($ebayCategories)): ?>
                            <?php foreach ($ebayCategories as $cat1): ?>
                                <!-- Level 1 Category -->
                                <a href="/products?cat1=<?php echo $cat1['id']; ?>" 
                                   class="list-group-item list-group-item-action <?php echo $ebayCat1 == $cat1['id'] && !$ebayCat2 ? 'active' : ''; ?>"
                                   style="font-weight: bold;">
                                    <i class="fas fa-folder"></i> <?php echo htmlspecialchars($cat1['name']); ?>
                                </a>
                                
                                <!-- Level 2 Categories (show if level 1 is selected) -->
                                <?php if (($ebayCat1 == $cat1['id'] || $ebayCat2 || $ebayCat3) && !empty($cat1['children'])): ?>
                                    <?php foreach ($cat1['children'] as $cat2): ?>
                                        <a href="/products?cat1=<?php echo $cat1['id']; ?>&cat2=<?php echo $cat2['id']; ?>" 
                                           class="list-group-item list-group-item-action ps-4 <?php echo $ebayCat2 == $cat2['id'] && !$ebayCat3 ? 'active' : ''; ?>">
                                            <i class="fas fa-folder-open"></i> <?php echo htmlspecialchars($cat2['name']); ?>
                                        </a>
                                        
                                        <!-- Level 3 Categories (show if level 2 is selected) -->
                                        <?php if ($ebayCat2 == $cat2['id'] && !empty($cat2['children'])): ?>
                                            <?php foreach ($cat2['children'] as $cat3): ?>
                                                <a href="/products?cat1=<?php echo $cat1['id']; ?>&cat2=<?php echo $cat2['id']; ?>&cat3=<?php echo $cat3['id']; ?>" 
                                                   class="list-group-item list-group-item-action ps-5 <?php echo $ebayCat3 == $cat3['id'] ? 'active' : ''; ?>">
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
        <div class="col-lg-9 col-md-8">
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <h1 class="fw-bold">
                        <?php if ($search): ?>
                            Search Results for "<?php echo htmlspecialchars($search); ?>"
                        <?php else: ?>
                            <?php echo htmlspecialchars($currentCategoryName); ?>
                        <?php endif; ?>
                    </h1>
                    <p class="text-muted"><?php echo $totalProducts; ?> products found</p>
                </div>
                <div class="col-md-6">
                    <!-- Search Box -->
                    <form method="get" action="/products" id="search-form">
                        <?php if ($ebayCat1): ?><input type="hidden" name="cat1" value="<?php echo $ebayCat1; ?>"><?php endif; ?>
                        <?php if ($ebayCat2): ?><input type="hidden" name="cat2" value="<?php echo $ebayCat2; ?>"><?php endif; ?>
                        <?php if ($ebayCat3): ?><input type="hidden" name="cat3" value="<?php echo $ebayCat3; ?>"><?php endif; ?>
                        <?php if ($manufacturer): ?><input type="hidden" name="manufacturer" value="<?php echo htmlspecialchars($manufacturer); ?>"><?php endif; ?>
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Search products..." id="product-search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>">
                            <button class="btn btn-danger" type="submit">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Manufacturer Filter -->
            <?php if (!empty($allManufacturers)): ?>
            <div class="row mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Filter by Manufacturer</label>
                    <select class="form-select" id="manufacturerFilter">
                        <option value="">All Manufacturers</option>
                        <?php foreach ($allManufacturers as $mfg): ?>
                            <option value="<?php echo htmlspecialchars($mfg); ?>" 
                                    <?php echo $manufacturer === $mfg ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($mfg); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <script>
            document.getElementById('manufacturerFilter').addEventListener('change', function() {
                const mfg = this.value;
                const params = new URLSearchParams(window.location.search);
                if (mfg) {
                    params.set('manufacturer', mfg);
                } else {
                    params.delete('manufacturer');
                }
                params.delete('page'); // Reset to first page
                window.location.href = '/products?' + params.toString();
            });
            </script>
            <?php endif; ?>

            <!-- Products Grid -->
            <?php if (empty($products)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No products found in this category. Try browsing other categories or use the search function.
                </div>
            <?php else: ?>
            <div class="row g-4">
                <?php foreach ($products as $index => $product): ?>
                    <?php 
                    // Staggered animation with max delay cap of 400ms
                    $delay = min(($index % 8) * 50, 400); 
                    
                    // Normalize image path for display
                    $imageUrl = normalizeImagePath($product['image_url'] ?? null);
                    if (empty($imageUrl)) {
                        $imageUrl = '/gallery/default.jpg';
                    }
                    ?>
                    <div class="col-lg-4 col-md-6 col-sm-12" data-aos="fade-up" data-aos-delay="<?php echo $delay; ?>">
                        <div class="card product-card h-100">
                            <a href="/product/<?php echo $product['id']; ?>" class="text-decoration-none">
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
                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>" 
                                             class="card-img-top product-image" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                                             style="cursor: pointer;">
                                    <?php else: ?>
                                        <div class="product-image bg-light d-flex align-items-center justify-content-center" style="cursor: pointer;">
                                            <i class="fas fa-image text-muted display-4"></i>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($product['condition_name'])): ?>
                                        <span class="badge bg-info product-badge"><?php echo htmlspecialchars($product['condition_name']); ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($product['sale_price']) && $product['sale_price']): ?>
                                        <?php $discount = round((($product['price'] - $product['sale_price']) / $product['price']) * 100); ?>
                                        <span class="badge bg-danger product-badge" style="top: <?php echo !empty($product['condition_name']) ? '50px' : '10px'; ?>;">Save <?php echo $discount; ?>%</span>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <div class="card-body d-flex flex-column">
                                <h6 class="card-title">
                                    <a href="/product/<?php echo $product['id']; ?>" class="text-decoration-none text-dark">
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
                                    <?php if (!empty($product['manufacturer'])): ?>
                                        <strong>Mfg:</strong> <?php echo htmlspecialchars($product['manufacturer']); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($product['model'])): ?>
                                        <strong>Model:</strong> <?php echo htmlspecialchars($product['model']); ?>
                                    <?php endif; ?>
                                </p>
                                <div class="mt-auto">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <?php if (isset($product['sale_price']) && $product['sale_price']): ?>
                                                <span class="product-price text-danger">$<?php echo number_format($product['sale_price'], 2); ?></span>
                                                <small class="text-muted text-decoration-line-through ms-1">$<?php echo number_format($product['price'], 2); ?></small>
                                            <?php else: ?>
                                                <span class="product-price">$<?php echo number_format($product['price'], 2); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                                    </div>
                                    <button class="btn btn-danger w-100 add-to-cart" 
                                            data-id="<?php echo $product['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                            data-price="<?php echo isset($product['sale_price']) && $product['sale_price'] ? $product['sale_price'] : $product['price']; ?>"
                                            data-image="<?php echo htmlspecialchars($imageUrl); ?>"
                                            data-sku="<?php echo htmlspecialchars($product['sku']); ?>">
                                        <i class="fas fa-cart-plus"></i> Add to Cart
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
                        // Build query parameters
                        $queryParams = [];
                        if ($ebayCat1) $queryParams[] = 'cat1=' . urlencode($ebayCat1);
                        if ($ebayCat2) $queryParams[] = 'cat2=' . urlencode($ebayCat2);
                        if ($ebayCat3) $queryParams[] = 'cat3=' . urlencode($ebayCat3);
                        if ($manufacturer) $queryParams[] = 'manufacturer=' . urlencode($manufacturer);
                        if ($search) $queryParams[] = 'search=' . urlencode($search);
                        $queryString = !empty($queryParams) ? '&' . implode('&', $queryParams) : '';
                        
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
                            <a class="page-link" href="/products?page=<?php echo $page - 1; ?><?php echo $queryString; ?>" aria-label="Previous">
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
                                <a class="page-link" href="/products?page=<?php echo $i; ?><?php echo $queryString; ?>"><?php echo $i; ?></a>
                            </li>
                            
                            <?php $prevPage = $i; ?>
                        <?php endforeach; ?>
                        
                        <!-- Next Button -->
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="/products?page=<?php echo $page + 1; ?><?php echo $queryString; ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
