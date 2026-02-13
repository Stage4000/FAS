<?php
// Handle redirect BEFORE any output
// Get filter parameters
$homepageCategory = $_GET['category'] ?? null;  // Homepage category slug (motorcycle, atv, boat, etc.)

// Validate homepage category against allowlist
$allowedCategories = ['motorcycle', 'atv', 'boat', 'automotive', 'gifts', 'other'];
if ($homepageCategory !== null && !in_array($homepageCategory, $allowedCategories)) {
    $homepageCategory = null;  // Invalid category, treat as no filter
}

// If homepage category is specified, redirect to the appropriate eBay category
// This ensures the sidebar navigation works consistently
if ($homepageCategory) {
    require_once __DIR__ . '/src/config/Database.php';
    require_once __DIR__ . '/src/models/HomepageCategoryMapping.php';
    require_once __DIR__ . '/src/utils/SyncLogger.php';
    require_once __DIR__ . '/src/integrations/EbayAPI.php';
    
    $db = \FAS\Config\Database::getInstance()->getConnection();
    $mappingModel = new \FAS\Models\HomepageCategoryMapping($db);
    $ebayCategoryNames = $mappingModel->getEbayCategoriesForHomepageCategory($homepageCategory);
    
    if (!empty($ebayCategoryNames)) {
        try {
            $config = require __DIR__ . '/src/config/config.php';
            $ebayAPI = new \FAS\Integrations\EbayAPI($config);
            $flatCategories = $ebayAPI->getStoreCategories();
            
            // Find the first eBay category ID that matches
            $redirectCat1 = null;
            foreach ($ebayCategoryNames as $ebayCategoryName) {
                foreach ($flatCategories as $catId => $catInfo) {
                    if (strcasecmp($catInfo['name'], $ebayCategoryName) === 0) {
                        $redirectCat1 = $catId;
                        break 2;
                    }
                }
            }
            
            // Redirect to eBay category if found
            if ($redirectCat1) {
                $search = $_GET['search'] ?? null;
                $manufacturer = $_GET['manufacturer'] ?? null;
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                
                $redirectUrl = '/products?cat1=' . urlencode($redirectCat1);
                if ($search) $redirectUrl .= '&search=' . urlencode($search);
                if ($manufacturer) $redirectUrl .= '&manufacturer=' . urlencode($manufacturer);
                if ($page > 1) $redirectUrl .= '&page=' . (int)$page;
                
                header('Location: ' . $redirectUrl);
                exit;
            }
        } catch (Exception $e) {
            // If redirect fails, continue to normal page load
            error_log("Redirect failed: " . $e->getMessage());
        }
    }
}

// Now include header and continue with normal page rendering
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';
require_once __DIR__ . '/src/models/HomepageCategoryMapping.php';
require_once __DIR__ . '/src/utils/SyncLogger.php';
require_once __DIR__ . '/src/integrations/EbayAPI.php';

use FAS\Config\Database;
use FAS\Models\Product;
use FAS\Models\HomepageCategoryMapping;
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

// Get filter parameters (already got homepageCategory above for redirect)
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

// Use eBay category filters (or show all products if no filters set)
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
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Categories</h5>
                    <button class="btn btn-sm btn-outline-light d-md-none" type="button" id="categoryToggle">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div class="card-body p-0 collapse show" id="categoryMenu" style="max-height: 600px; overflow-y: auto;">
                    <div class="list-group list-group-flush">
                        <!-- All Products Link -->
                        <a href="#" data-category="" class="category-link list-group-item list-group-item-action <?php echo (!$ebayCat1 && !$ebayCat2 && !$ebayCat3) ? 'active' : ''; ?>">
                            <i class="fas fa-th"></i> All Products
                            <?php if (!$ebayCat1 && !$ebayCat2 && !$ebayCat3): ?>
                                <span class="badge bg-danger float-end"><?php echo $totalProducts; ?></span>
                            <?php endif; ?>
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
                                            data-sku="<?php echo htmlspecialchars($product['sku']); ?>"
                                            data-stock="<?php echo isset($product['quantity']) ? intval($product['quantity']) : 999; ?>">
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

<script>
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
});

// AJAX category filtering
function attachCategoryHandlers() {
    document.querySelectorAll('.category-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Build URL parameters
            const params = new URLSearchParams(window.location.search);
            
            // Reset category parameters
            params.delete('cat1');
            params.delete('cat2');
            params.delete('cat3');
            params.delete('page'); // Reset to first page
            
            // Add new category parameters
            const cat1 = this.dataset.cat1;
            const cat2 = this.dataset.cat2;
            const cat3 = this.dataset.cat3;
            
            if (cat1) params.set('cat1', cat1);
            if (cat2) params.set('cat2', cat2);
            if (cat3) params.set('cat3', cat3);
            
            // Update browser URL without refresh
            const newUrl = '/products' + (params.toString() ? '?' + params.toString() : '');
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
function loadProductsAndSidebar(params) {
    // Show loading state
    const content = document.getElementById('productsContent');
    content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-danger" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    
    // Fetch products with sidebar
    fetch('/api/products.php?' + params.toString() + '&include_sidebar=1')
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.statusText);
            }
            return response.text();
        })
        .then(text => {
            console.log('API Response length:', text.length);
            console.log('API Response (first 500 chars):', text.substring(0, 500));
            console.log('API Response (last 100 chars):', text.substring(Math.max(0, text.length - 100)));
            
            try {
                const data = JSON.parse(text);
                console.log('Parsed data keys:', Object.keys(data));
                console.log('HTML length:', data.html ? data.html.length : 'null');
                console.log('Sidebar length:', data.sidebar ? data.sidebar.length : 'null');
                console.log('HTML preview (first 200 chars):', data.html ? data.html.substring(0, 200) : 'null');
                console.log('HTML preview (last 200 chars):', data.html ? data.html.substring(Math.max(0, data.html.length - 200)) : 'null');
                
                if (data.error) {
                    throw new Error(data.message || 'Server error');
                }
                
                if (!data.html) {
                    throw new Error('No HTML content in response');
                }
                
                console.log('Setting content.innerHTML...');
                console.log('Content element:', content);
                console.log('Content element ID:', content ? content.id : 'null');
                content.innerHTML = data.html;
                console.log('Products HTML updated successfully');
                console.log('Content element childElementCount after update:', content.childElementCount);
                
                // Remove AOS attributes from dynamically loaded content to prevent visibility issues
                // AOS keeps elements hidden until they animate in, which doesn't work well with AJAX
                const aosElements = content.querySelectorAll('[data-aos]');
                aosElements.forEach(el => {
                    el.removeAttribute('data-aos');
                    el.removeAttribute('data-aos-delay');
                    el.removeAttribute('data-aos-duration');
                    el.removeAttribute('data-aos-offset');
                    // Remove AOS classes that hide elements
                    el.classList.remove('aos-init', 'aos-animate');
                    // Force visibility by removing any AOS inline styles
                    el.style.removeProperty('opacity');
                    el.style.removeProperty('transform');
                    el.style.removeProperty('transition-property');
                });
                console.log('Removed AOS attributes from', aosElements.length, 'dynamically loaded elements');
                
                // Update sidebar if provided
                if (data.sidebar) {
                    const sidebarContainer = document.querySelector('#categoryMenu .list-group');
                    if (sidebarContainer) {
                        sidebarContainer.innerHTML = data.sidebar;
                        console.log('Sidebar updated successfully');
                        // Reattach category click handlers
                        attachCategoryHandlers();
                    } else {
                        console.warn('Sidebar container not found');
                    }
                }
                
                // Reattach pagination click handlers
                attachPaginationHandlers();
                
                // Reattach manufacturer filter handler
                attachManufacturerFilterHandler();
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError);
                console.error('Response text:', text);
                content.innerHTML = '<div class="alert alert-danger">Error parsing server response. Check console for details.</div>';
                throw parseError;
            }
        })
        .catch(error => {
            console.error('Error loading products:', error);
            content.innerHTML = '<div class="alert alert-danger">Error loading products: ' + error.message + '. Please refresh the page.</div>';
        });
}

// Attach pagination handlers
function attachPaginationHandlers() {
    document.querySelectorAll('.pagination .page-link').forEach(link => {
        link.addEventListener('click', function(e) {
            if (this.parentElement.classList.contains('disabled')) {
                e.preventDefault();
                return;
            }
            
            e.preventDefault();
            const url = new URL(this.href);
            const params = new URLSearchParams(url.search);
            
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
    const params = new URLSearchParams(window.location.search);
    loadProductsAndSidebar(params);
});

// Manufacturer filter change handler function
function handleManufacturerChange() {
    const params = new URLSearchParams(window.location.search);
    const mfg = this.value;
    
    if (mfg) {
        params.set('manufacturer', mfg);
    } else {
        params.delete('manufacturer');
    }
    params.delete('page'); // Reset to first page
    
    // Update URL and load products
    const newUrl = '/products' + (params.toString() ? '?' + params.toString() : '');
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

// Handle manufacturer filter change
attachManufacturerFilterHandler();

// Handle search form submission
document.getElementById('search-form')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const params = new URLSearchParams(window.location.search);
    const searchTerm = document.getElementById('product-search').value;
    
    if (searchTerm) {
        params.set('search', searchTerm);
    } else {
        params.delete('search');
    }
    params.delete('page'); // Reset to first page
    
    // Update URL and load products
    const newUrl = '/products' + (params.toString() ? '?' + params.toString() : '');
    window.history.pushState({}, '', newUrl);
    loadProductsAndSidebar(params);
});

// Initial pagination handlers
attachPaginationHandlers();
</script>

<style>
/* Mobile category menu styles */
@media (max-width: 767px) {
    #categoryMenu.collapse:not(.show) {
        display: none;
    }
    
    #categoryMenu.collapse.show {
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
