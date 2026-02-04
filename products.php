<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';

use FAS\Config\Database;
use FAS\Models\Product;

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
            <div class="input-group">
                <input type="text" class="form-control" placeholder="Search products..." id="product-search">
                <button class="btn btn-danger" type="button">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-4">
        <div class="col-12 mb-3">
            <label class="form-label fw-bold">Category</label>
            <div class="btn-group-responsive" role="group">
                <a href="products.php<?php echo $manufacturer ? '?manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo !$category ? 'btn-danger' : 'btn-outline-danger'; ?>">All</a>
                <a href="products.php?category=motorcycle<?php echo $manufacturer ? '&manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'motorcycle' ? 'btn-danger' : 'btn-outline-danger'; ?>">Motorcycle</a>
                <a href="products.php?category=atv<?php echo $manufacturer ? '&manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'atv' ? 'btn-danger' : 'btn-outline-danger'; ?>">ATV/UTV</a>
                <a href="products.php?category=boat<?php echo $manufacturer ? '&manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'boat' ? 'btn-danger' : 'btn-outline-danger'; ?>">Boat</a>
                <a href="products.php?category=automotive<?php echo $manufacturer ? '&manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'automotive' ? 'btn-danger' : 'btn-outline-danger'; ?>">Automotive</a>
                <a href="products.php?category=gifts<?php echo $manufacturer ? '&manufacturer=' . urlencode($manufacturer) : ''; ?>" 
                   class="btn btn-sm <?php echo $category === 'gifts' ? 'btn-danger' : 'btn-outline-danger'; ?>">Gifts</a>
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
        let url = 'products.php';
        const params = [];
        if (category) params.push('category=' + category);
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
            ?>
            <div class="col-lg-3 col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="<?php echo $delay; ?>">
                <div class="card product-card h-100">
                    <div class="position-relative">
                        <?php 
                        // Check if image URL exists and is either external (http/https) or a local file
                        $hasImage = !empty($product['image_url']) && (
                            strpos($product['image_url'], 'http://') === 0 || 
                            strpos($product['image_url'], 'https://') === 0 || 
                            file_exists($product['image_url'])
                        );
                        ?>
                        <?php if ($hasImage): ?>
                            <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                 class="card-img-top product-image" 
                                 alt="<?php echo htmlspecialchars($product['name']); ?>">
                        <?php else: ?>
                            <div class="product-image bg-light d-flex align-items-center justify-content-center">
                                <i class="fas fa-image text-muted display-4"></i>
                            </div>
                        <?php endif; ?>
                        <span class="badge bg-success product-badge">In Stock</span>
                        <?php if (isset($product['sale_price']) && $product['sale_price']): ?>
                            <?php $discount = round((($product['price'] - $product['sale_price']) / $product['price']) * 100); ?>
                            <span class="badge bg-danger product-badge" style="top: 50px;">Save <?php echo $discount; ?>%</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <h6 class="card-title">
                            <a href="product.php?id=<?php echo $product['id']; ?>" class="text-decoration-none text-dark">
                                <?php echo htmlspecialchars($product['name']); ?>
                            </a>
                        </h6>
                        <p class="card-text text-muted small flex-grow-1">
                            <?php echo htmlspecialchars(substr($product['description'], 0, 80)) . '...'; ?>
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
                                    data-image="<?php echo htmlspecialchars($product['image_url'] ?? ''); ?>"
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
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $category ? '&category=' . $category : ''; ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo $category ? '&category=' . $category : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $category ? '&category=' . $category : ''; ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
