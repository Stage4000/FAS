<?php
$pageTitle = 'Product Details';
require_once __DIR__ . '/includes/header.php';

// Get product ID
$productId = $_GET['id'] ?? null;

if (!$productId) {
    header('Location: products.php');
    exit;
}

// Sample product data (will be replaced with database query)
$product = [
    'id' => $productId,
    'name' => 'Yamaha YZF600R Thundercat Foot Peg Bracket Left Rear',
    'description' => '<p>YAMAHA YZF600R THUNDERCAT FOOT PEG AND BRACKET REAR LEFT.</p><p>LOW MILES (9300 MI) - USED CONDITION.</p><p><i>PLEASE ALL SEE PICTURES!!!</i></p><p>(JA1 1325FAS YZF23)</p><p>4JH-2741L-03-00 BRACKET</p>',
    'price' => 23.45,
    'sku' => '1325 FAS',
    'condition' => 'Used - Excellent',
    'quantity' => 1,
    'weight' => 1.4,
    'category' => 'Motorcycle Parts',
    'manufacturer' => 'Yamaha',
    'model' => 'YZF600R Thundercat',
    'images' => [
        'gallery/yamaha-foot-peg-bracket-left-rear-yzf600r-parts 1.webp',
        'gallery/yamaha-foot-peg-bracket-left-rear-yzf600r-parts 2.webp',
        'gallery/yamaha-foot-peg-bracket-left-rear-yzf600r-parts 3.webp',
        'gallery/yamaha-foot-peg-bracket-left-rear-yzf600r-parts 4.webp',
        'gallery/yamaha-foot-peg-bracket-left-rear-yzf600r-parts 5.webp',
    ]
];

$mainImage = $product['images'][0] ?? 'gallery/default.jpg';
?>

<div class="container my-5">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
            <li class="breadcrumb-item"><a href="products.php">Products</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($product['name']); ?></li>
        </ol>
    </nav>

    <div class="row">
        <!-- Product Images -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <?php if (file_exists($mainImage)): ?>
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
            <?php if (!empty($product['images'])): ?>
                <div class="product-thumbnails mt-3 d-flex gap-2 flex-wrap">
                    <?php foreach ($product['images'] as $index => $image): ?>
                        <?php if (file_exists($image)): ?>
                            <img src="<?php echo htmlspecialchars($image); ?>" 
                                 class="img-thumbnail <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 data-full="<?php echo htmlspecialchars($image); ?>"
                                 alt="View <?php echo $index + 1; ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Product Information -->
        <div class="col-lg-6">
            <h1 class="mb-3"><?php echo htmlspecialchars($product['name']); ?></h1>
            
            <div class="mb-4">
                <span class="badge bg-success me-2">In Stock</span>
                <span class="badge bg-secondary"><?php echo htmlspecialchars($product['condition']); ?></span>
            </div>
            
            <div class="product-price display-4 fw-bold text-danger mb-4">
                $<?php echo number_format($product['price'], 2); ?>
            </div>
            
            <div class="mb-4">
                <strong>SKU:</strong> <?php echo htmlspecialchars($product['sku']); ?>
            </div>
            
            <div class="card border-0 bg-light mb-4">
                <div class="card-body">
                    <h6 class="mb-3">Product Details</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted">Category:</td>
                            <td><?php echo htmlspecialchars($product['category']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Manufacturer:</td>
                            <td><?php echo htmlspecialchars($product['manufacturer']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Model:</td>
                            <td><?php echo htmlspecialchars($product['model']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Condition:</td>
                            <td><?php echo htmlspecialchars($product['condition']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Weight:</td>
                            <td><?php echo $product['weight']; ?> lbs</td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label fw-bold">Quantity:</label>
                <div class="input-group" style="max-width: 150px;">
                    <button class="btn btn-outline-secondary" type="button" id="decrease-qty">-</button>
                    <input type="number" class="form-control text-center" value="1" min="1" max="<?php echo $product['quantity']; ?>" id="quantity-input">
                    <button class="btn btn-outline-secondary" type="button" id="increase-qty">+</button>
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
                <small>Ships via EasyShip with tracking</small>
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
                    <?php echo $product['description']; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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
