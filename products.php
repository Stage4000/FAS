<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';

use FAS\Config\Database;
use FAS\Models\Product;

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
$category = $_GET['category'] ?? null;
$manufacturer = $_GET['manufacturer'] ?? null;
$search = $_GET['search'] ?? null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 24;

// Initialize database and product model
$db = Database::getInstance()->getConnection();
$productModel = new Product($db);

// Get products from database
$products = $productModel->getAll($page, $perPage, $category, $search, $manufacturer);
$totalProducts = $productModel->getCount($category, $search, $manufacturer);

// Get unique manufacturers for filter (from all products)
$allManufacturers = $productModel->getManufacturers();

$totalPages = ceil($totalProducts / $perPage);
?>

<div class="container my-5">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="fw-bold">
                <?php if ($category): ?>
                    <?php echo ucfirst($category); ?> Parts
                <?php elseif ($search): ?>
                    Search Results for "<?php echo htmlspecialchars($search); ?>"
                <?php else: ?>
                    All Products
                <?php endif; ?>
            </h1>
            <p class="text-muted"><?php echo $totalProducts; ?> products found</p>
        </div>
        <div class="col-md-6">
            <!-- Search Box -->
            <form method="get" action="/products<?php echo $category ? '/' . $category : ''; ?>" id="search-form">
                <div class="input-group">
                    <input type="text" class="form-control" placeholder="Search products..." id="product-search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>">
                    <?php if ($manufacturer): ?>
                        <input type="hidden" name="manufacturer" value="<?php echo htmlspecialchars($manufacturer); ?>">
                    <?php endif; ?>
                    <button class="btn btn-danger" type="submit">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-4">
        <div class="col-12 mb-3">
            <label class="form-label fw-bold">Category</label>
            <div class="btn-group-responsive" role="group">
                <a href="/products<?php echo $manufacturer ? '?manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo !$category ? 'btn-danger' : 'btn-outline-danger'; ?>">All
                <a href="/products/motorcycle<?php echo $manufacturer ? '?manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'motorcycle' ? 'btn-danger' : 'btn-outline-danger'; ?>">Motorcycle</a>
                <a href="/products/atv<?php echo $manufacturer ? '?manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'atv' ? 'btn-danger' : 'btn-outline-danger'; ?>">ATV/UTV</a>
                <a href="/products/boat<?php echo $manufacturer ? '?manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'boat' ? 'btn-danger' : 'btn-outline-danger'; ?>">Boat</a>
                <a href="/products/automotive<?php echo $manufacturer ? '?manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'automotive' ? 'btn-danger' : 'btn-outline-danger'; ?>">Automotive</a>
                <a href="/products/gifts<?php echo $manufacturer ? '?manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'gifts' ? 'btn-danger' : 'btn-outline-danger'; ?>">Gifts</a>
                <a href="/products/other<?php echo $manufacturer ? '?manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'other' ? 'btn-danger' : 'btn-outline-danger'; ?>">Other</a>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-bold">Manufacturer</label>
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
        const category = '<?php echo $category ?? ''; ?>';
        let url = '/products';
        if (category) url += '/' + category;
        const params = [];
        if (mfg) params.push('manufacturer=' + encodeURIComponent(mfg));
        if (params.length > 0) url += '?' + params.join('&');
        window.location.href = url;
    });
    </script>

    <!-- Products Grid -->
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
            <div class="col-lg-3 col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="<?php echo $delay; ?>">
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

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Product pagination" class="mt-5">
            <ul class="pagination justify-content-center flex-wrap">
                <?php
                // Build base URL for pagination
                $baseUrl = '/products';
                if ($category) $baseUrl .= '/' . $category;
                $queryParams = [];
                if ($manufacturer) $queryParams[] = 'manufacturer=' . urlencode($manufacturer);
                if ($search) $queryParams[] = 'search=' . urlencode($search);
                $queryString = !empty($queryParams) ? '&' . implode('&', $queryParams) : '';
                
                // Smart pagination: show first, last, current and nearby pages with ellipsis
                $paginationRange = 2; // Number of pages to show on each side of current page
                $maxPagesToShowAll = 7; // Show all pages if total is less than or equal to this
                $showPages = [];
                
                // For small page counts, show all pages
                if ($totalPages <= $maxPagesToShowAll) {
                    for ($i = 1; $i <= $totalPages; $i++) {
                        $showPages[] = $i;
                    }
                } else {
                    // Always show first page
                    $showPages[] = 1;
                    
                    // Show pages around current page
                    for ($i = max(2, $page - $paginationRange); $i <= min($totalPages - 1, $page + $paginationRange); $i++) {
                        $showPages[] = $i;
                    }
                    
                    // Always show last page
                    if ($totalPages > 1) {
                        $showPages[] = $totalPages;
                    }
                    
                    // Remove duplicates and sort
                    $showPages = array_unique($showPages);
                    sort($showPages);
                }
                ?>
                
                <!-- Previous Button -->
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseUrl; ?>?page=<?php echo $page - 1; ?><?php echo $queryString; ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                
                <?php 
                $prevPage = 0;
                foreach ($showPages as $i): 
                    // Add ellipsis if there's a gap
                    if ($i - $prevPage > 1): ?>
                        <li class="page-item disabled d-none d-sm-block">
                            <span class="page-link">...</span>
                        </li>
                    <?php endif; ?>
                    
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo $baseUrl; ?>?page=<?php echo $i; ?><?php echo $queryString; ?>"><?php echo $i; ?></a>
                    </li>
                    
                    <?php $prevPage = $i; ?>
                <?php endforeach; ?>
                
                <!-- Next Button -->
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseUrl; ?>?page=<?php echo $page + 1; ?><?php echo $queryString; ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
