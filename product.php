<?php
$pageTitle = 'Product Details';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';

use FAS\Config\Database;
use FAS\Models\Product;

// Get product ID
$productId = $_GET['id'] ?? null;

if (!$productId) {
    header('Location: products.php');
    exit;
}

// Initialize database and product model
$db = Database::getInstance()->getConnection();
$productModel = new Product($db);

// Get product from database
$product = $productModel->getById($productId);

if (!$product) {
    header('Location: products.php');
    exit;
}

// Parse images from JSON if available
$images = [];
if (!empty($product['images'])) {
    $images = json_decode($product['images'], true);
    if (!is_array($images)) {
        $images = [];
    }
}

// Use main image or first from images array
$mainImage = $product['image_url'] ?? null;
if (empty($mainImage) && !empty($images)) {
    $mainImage = $images[0];
}

// If we have a main image but it's not in the images array, add it
if ($mainImage && !in_array($mainImage, $images)) {
    array_unshift($images, $mainImage);
}

// Fallback to default if no images
if (empty($mainImage)) {
    $mainImage = 'gallery/default.jpg';
}
?>

<div class="container my-5">
    <nav aria-label="breadcrumb" data-aos="fade-down">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
            <li class="breadcrumb-item"><a href="products.php">Products</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($product['name']); ?></li>
        </ol>
    </nav>

    <div class="row">
        <!-- Product Images -->
        <div class="col-lg-6 mb-4" data-aos="fade-right">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <?php 
                    // Check if image is external or local
                    $hasMainImage = !empty($mainImage) && (
                        strpos($mainImage, 'http://') === 0 || 
                        strpos($mainImage, 'https://') === 0 || 
                        file_exists($mainImage)
                    );
                    ?>
                    <?php if ($hasMainImage): ?>
                        <img src="<?php echo htmlspecialchars($mainImage); ?>" 
                             class="img-fluid product-detail-img w-100" 
                             id="main-product-image"
                             alt="<?php echo htmlspecialchars($product['name']); ?>">
                    <?php else: ?>
                        <div class="bg-light p-5 text-center">
                            <i class="bi bi-image display-1 text-muted"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Thumbnail Gallery -->
            <?php if (!empty($images) && count($images) > 1): ?>
                <div class="product-thumbnails mt-3 d-flex gap-2 flex-wrap">
                    <?php foreach ($images as $index => $image): ?>
                        <?php 
                        // Check if image is external or local
                        $hasImage = !empty($image) && (
                            strpos($image, 'http://') === 0 || 
                            strpos($image, 'https://') === 0 || 
                            file_exists($image)
                        );
                        ?>
                        <?php if ($hasImage): ?>
                            <img src="<?php echo htmlspecialchars($image); ?>" 
                                 class="img-thumbnail thumbnail-image <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 data-full="<?php echo htmlspecialchars($image); ?>"
                                 alt="View <?php echo $index + 1; ?>"
                                 style="width: 80px; height: 80px; object-fit: cover; cursor: pointer;">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Product Information -->
        <div class="col-lg-6" data-aos="fade-left">
            <h1 class="mb-3"><?php echo htmlspecialchars($product['name']); ?></h1>
            
            <div class="mb-4">
                <span class="badge bg-success me-2">In Stock</span>
                <?php if (!empty($product['condition_name'])): ?>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($product['condition_name']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="product-price display-4 fw-bold text-danger mb-4">
                $<?php echo number_format($product['price'], 2); ?>
            </div>
            
            <div class="mb-4">
                <strong>SKU:</strong> <?php echo htmlspecialchars($product['sku']); ?>
            </div>
            
            <div class="card border-0 bg-light mb-4" data-theme-card>
                <div class="card-body">
                    <h6 class="mb-3">Product Details</h6>
                    <table class="table table-sm table-borderless mb-0" data-theme-table>
                        <?php if (!empty($product['category'])): ?>
                        <tr>
                            <td class="text-muted">Category:</td>
                            <td><?php echo htmlspecialchars(ucfirst($product['category']) . ($product['category'] === 'gifts' ? '' : ' Parts')); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($product['manufacturer'])): ?>
                        <tr>
                            <td class="text-muted">Manufacturer:</td>
                            <td><?php echo htmlspecialchars($product['manufacturer']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($product['model'])): ?>
                        <tr>
                            <td class="text-muted">Model:</td>
                            <td><?php echo htmlspecialchars($product['model']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($product['condition_name'])): ?>
                        <tr>
                            <td class="text-muted">Condition:</td>
                            <td><?php echo htmlspecialchars($product['condition_name']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($product['weight'])): ?>
                        <tr>
                            <td class="text-muted">Weight:</td>
                            <td><?php echo number_format($product['weight'], 2); ?> lbs</td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label fw-bold">Quantity:</label>
                <div class="input-group quantity-selector" style="max-width: 150px;">
                    <button class="btn btn-outline-danger quantity-btn" type="button" id="decrease-qty">-</button>
                    <input type="number" class="form-control text-center quantity-input" value="1" min="1" max="<?php echo intval($product['quantity']); ?>" id="quantity-input">
                    <button class="btn btn-outline-danger quantity-btn" type="button" id="increase-qty">+</button>
                </div>
            </div>
            
            <div class="d-grid gap-2 mb-4">
                <button class="btn btn-danger btn-lg add-to-cart"
                        data-id="<?php echo $product['id']; ?>"
                        data-name="<?php echo htmlspecialchars($product['name']); ?>"
                        data-price="<?php echo $product['price']; ?>"
                        data-image="<?php echo htmlspecialchars($mainImage); ?>"
                        data-sku="<?php echo htmlspecialchars($product['sku']); ?>">
                    <i class="bi bi-cart-plus"></i> Add to Cart
                </button>
                <a href="cart.php" class="btn btn-outline-dark btn-lg">
                    <i class="bi bi-cart3"></i> View Cart
                </a>
            </div>
            
            <div class="alert alert-info">
                <i class="bi bi-truck me-2"></i>
                <strong>Fast Shipping Available</strong><br>
                <small>Ships with tracking</small>
            </div>
            
            <div class="alert alert-secondary">
                <i class="bi bi-shield-check me-2"></i>
                <strong>Secure Payment</strong><br>
                <small>PayPal checkout for safe transactions</small>
            </div>
        </div>
    </div>
    
    <!-- Product Description -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h3 class="mb-4">Product Description</h3>
                    <?php echo htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Thumbnail gallery functionality
document.querySelectorAll('.thumbnail-image').forEach(thumbnail => {
    thumbnail.addEventListener('click', function() {
        const fullImageUrl = this.dataset.full;
        const mainImage = document.getElementById('main-product-image');
        
        if (mainImage && fullImageUrl) {
            mainImage.src = fullImageUrl;
        }
        
        // Update active state
        document.querySelectorAll('.thumbnail-image').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
    });
});

// Quantity controls
document.getElementById('decrease-qty').addEventListener('click', function() {
    const input = document.getElementById('quantity-input');
    const currentValue = parseInt(input.value);
    if (currentValue > 1) {
        input.value = currentValue - 1;
    }
});

document.getElementById('increase-qty').addEventListener('click', function() {
    const input = document.getElementById('quantity-input');
    const currentValue = parseInt(input.value);
    const max = parseInt(input.max);
    if (currentValue < max) {
        input.value = currentValue + 1;
    }
});

// Update add to cart to use quantity
document.querySelector('.add-to-cart').addEventListener('click', function() {
    const quantity = parseInt(document.getElementById('quantity-input').value);
    const productData = {
        id: this.dataset.id,
        name: this.dataset.name,
        price: parseFloat(this.dataset.price),
        image: this.dataset.image,
        sku: this.dataset.sku
    };
    
    for (let i = 0; i < quantity; i++) {
        window.cart.addItem(productData);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
