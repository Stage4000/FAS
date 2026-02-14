<?php
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';

use FAS\Config\Database;
use FAS\Models\Product;

// Get product ID
$productId = $_GET['id'] ?? null;

if (!$productId) {
    header('Location: /products');
    exit;
}

// Initialize database and product model
$db = Database::getInstance()->getConnection();
$productModel = new Product($db);

// Get product from database
$product = $productModel->getById($productId);

if (!$product) {
    header('Location: /products');
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

// Normalize all image paths
$images = array_map('normalizeImagePath', $images);

// Use main image or first from images array
$mainImage = normalizeImagePath($product['image_url'] ?? null);
if (empty($mainImage) && !empty($images)) {
    $mainImage = $images[0];
}

// If we have a main image but it's not in the images array, add it
if ($mainImage && !in_array($mainImage, $images)) {
    array_unshift($images, $mainImage);
}

// Fallback to default if no images
if (empty($mainImage)) {
    $mainImage = '/gallery/default.jpg';
}

// Set meta tags for social media sharing
$pageTitle = htmlspecialchars($product['name']);

// Create a description from product details
$descriptionParts = [];
if (!empty($product['description'])) {
    // Truncate description to ~150 characters for meta description
    $desc = strip_tags($product['description']);
    $originalLength = strlen($desc);
    $desc = substr($desc, 0, 150);
    if ($originalLength > 150) {
        $desc .= '...';
    }
    $descriptionParts[] = $desc;
} else {
    $descriptionParts[] = $product['name'];
}

// Add price
$descriptionParts[] = 'Price: $' . number_format($product['price'], 2);

// Add condition if available
if (!empty($product['condition_name'])) {
    $descriptionParts[] = 'Condition: ' . $product['condition_name'];
}

$pageDescription = htmlspecialchars(implode(' | ', $descriptionParts));

// Set Open Graph image - convert to absolute URL if it's a local path
$ogImage = $mainImage;
if (strpos($ogImage, 'http://') !== 0 && strpos($ogImage, 'https://') !== 0) {
    // Use SERVER_NAME instead of HTTP_HOST to prevent host header injection
    $host = $_SERVER['SERVER_NAME'] ?? 'flipandstrip.com';
    $ogImage = 'https://' . $host . $ogImage;
}

// Set OG type to product
$ogType = 'product';

// Include header with the meta tags
require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5">
    <nav aria-label="breadcrumb" data-aos="fade-down">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/">Home</a></li>
            <li class="breadcrumb-item"><a href="/products">Products</a></li>
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
                    $isExternal = strpos($mainImage, 'http://') === 0 || strpos($mainImage, 'https://') === 0;
                    $hasMainImage = !empty($mainImage) && (
                        $isExternal || 
                        file_exists(__DIR__ . $mainImage)
                    );
                    ?>
                    <?php if ($hasMainImage): ?>
                        <img src="<?php echo htmlspecialchars($mainImage); ?>" 
                             class="img-fluid product-detail-img w-100" 
                             id="main-product-image"
                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                             style="max-width: 100%; height: auto;">
                    <?php else: ?>
                        <div class="bg-light p-5 text-center">
                            <i class="bi bi-image display-1 text-muted"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Thumbnail Gallery -->
            <?php if (!empty($images) && count($images) > 1): ?>
                <div class="product-thumbnails mt-3 d-flex gap-2 flex-wrap" style="overflow-x: auto;">
                    <?php foreach ($images as $index => $image): ?>
                        <?php 
                        // Check if image is external or local
                        $isExternal = strpos($image, 'http://') === 0 || strpos($image, 'https://') === 0;
                        $hasImage = !empty($image) && (
                            $isExternal || 
                            file_exists(__DIR__ . $image)
                        );
                        ?>
                        <?php if ($hasImage): ?>
                            <img src="<?php echo htmlspecialchars($image); ?>" 
                                 class="img-thumbnail thumbnail-image <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 data-full="<?php echo htmlspecialchars($image); ?>"
                                 alt="View <?php echo $index + 1; ?>"
                                 style="width: 80px; height: 80px; object-fit: cover; cursor: pointer; flex-shrink: 0;">
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
            
            <div class="card border-0 mb-4" data-theme-card>
                <div class="card-body">
                    <h6 class="mb-3">Product Details</h6>
                    <table class="table table-sm table-borderless mb-0" data-theme-table>
                        <?php 
                        // Display eBay store category hierarchy if available
                        $ebayCategory = $productModel->getEbayStoreCategoryPath($product);
                        if (!empty($ebayCategory)): 
                        ?>
                        <tr>
                            <td class="text-muted" style="white-space: nowrap;">Category:</td>
                            <td style="word-break: break-word;"><?php echo htmlspecialchars($ebayCategory); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($product['manufacturer'])): ?>
                        <tr>
                            <td class="text-muted" style="white-space: nowrap;">Manufacturer:</td>
                            <td style="word-break: break-word;"><?php echo htmlspecialchars($product['manufacturer']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($product['model'])): ?>
                        <tr>
                            <td class="text-muted" style="white-space: nowrap;">Model:</td>
                            <td style="word-break: break-word;"><?php echo htmlspecialchars($product['model']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($product['condition_name'])): ?>
                        <tr>
                            <td class="text-muted" style="white-space: nowrap;">Condition:</td>
                            <td style="word-break: break-word;"><?php echo htmlspecialchars($product['condition_name']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($product['weight'])): ?>
                        <tr>
                            <td class="text-muted" style="white-space: nowrap;">Weight:</td>
                            <td style="word-break: break-word;"><?php echo number_format($product['weight'], 2); ?> lbs</td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label fw-bold">Quantity:</label>
                <div class="input-group quantity-selector">
                    <button class="btn btn-outline-danger quantity-btn" type="button" id="decrease-qty">-</button>
                    <input type="number" class="form-control text-center quantity-input" value="1" min="1" max="<?php echo (isset($product['quantity']) && intval($product['quantity']) > 0) ? intval($product['quantity']) : 999; ?>" id="quantity-input">
                    <button class="btn btn-outline-danger quantity-btn" type="button" id="increase-qty">+</button>
                </div>
            </div>
            
            <div class="d-grid gap-2 mb-4">
                <button class="btn btn-danger btn-lg add-to-cart"
                        data-id="<?php echo $product['id']; ?>"
                        data-name="<?php echo htmlspecialchars($product['name']); ?>"
                        data-price="<?php echo $product['price']; ?>"
                        data-image="<?php echo htmlspecialchars($mainImage); ?>"
                        data-sku="<?php echo htmlspecialchars($product['sku']); ?>"
                        data-weight="<?php echo !empty($product['weight']) ? floatval($product['weight']) : 1.0; ?>"
                        data-length="<?php echo !empty($product['length']) ? floatval($product['length']) : 10.0; ?>"
                        data-width="<?php echo !empty($product['width']) ? floatval($product['width']) : 10.0; ?>"
                        data-height="<?php echo !empty($product['height']) ? floatval($product['height']) : 10.0; ?>"
                        data-stock="<?php echo isset($product['quantity']) ? intval($product['quantity']) : 999; ?>">
                    <i class="bi bi-cart-plus"></i> Add to Cart
                </button>
                <a href="/cart" class="btn btn-dark btn-lg">
                    <i class="bi bi-cart3"></i> View Cart
                </a>
                <button class="btn btn-secondary btn-lg" id="share-button" aria-label="Share product link">
                    <i class="fas fa-share-alt"></i> Share
                </button>
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
                    <?php 
                    // Description should already be sanitized on import (HTML stripped, br tags converted to newlines)
                    // Display as plain text with proper escaping and preserve line breaks
                    $description = $product['description'] ?? '';
                    if (!empty($description)) {
                        echo '<p>' . nl2br(htmlspecialchars($description)) . '</p>';
                    } else {
                        echo '<p class="text-muted">No description available.</p>';
                    }
                    ?>
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
    const currentValue = parseInt(input.value) || 1;
    const maxValue = parseInt(input.getAttribute('max')) || 999;
    if (currentValue < maxValue) {
        input.value = currentValue + 1;
        // Remove tooltip if it exists
        const tooltip = bootstrap.Tooltip.getInstance(this);
        if (tooltip) {
            tooltip.dispose();
        }
    } else {
        // Show tooltip when max is reached
        if (!this.hasAttribute('data-bs-toggle')) {
            this.setAttribute('data-bs-toggle', 'tooltip');
            this.setAttribute('data-bs-placement', 'top');
            this.setAttribute('title', 'Maximum available quantity reached');
            const tooltip = new bootstrap.Tooltip(this);
            tooltip.show();
            
            // Hide and remove tooltip after 3 seconds
            setTimeout(() => {
                tooltip.hide();
                setTimeout(() => {
                    tooltip.dispose();
                    this.removeAttribute('data-bs-toggle');
                    this.removeAttribute('data-bs-placement');
                    this.removeAttribute('title');
                }, 300);
            }, 3000);
        }
    }
});

// Update add to cart to use quantity
document.querySelector('.add-to-cart').addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation(); // Prevent global handler from also firing
    
    const quantity = parseInt(document.getElementById('quantity-input').value);
    const productData = {
        id: this.dataset.id,
        name: this.dataset.name,
        price: parseFloat(this.dataset.price),
        image: this.dataset.image,
        sku: this.dataset.sku,
        weight: parseFloat(this.dataset.weight) || 1.0,
        length: parseFloat(this.dataset.length) || 10.0,
        width: parseFloat(this.dataset.width) || 10.0,
        height: parseFloat(this.dataset.height) || 10.0,
        stock: parseInt(this.dataset.stock) || 999
    };
    
    for (let i = 0; i < quantity; i++) {
        window.cart.addItem(productData);
    }
    
    // Trigger animation manually since we stopped propagation
    if (window.animateAddToCart) {
        window.animateAddToCart(this);
    }
});

/**
 * Display a notification message to the user
 * @param {string} message - The message to display
 * @param {string} type - Bootstrap alert type: 'success', 'danger', 'warning', or 'info'
 * Uses window.showToast() if available, otherwise creates a Bootstrap alert
 */
function showNotification(message, type) {
    // Ensure message is a string
    const safeMessage = String(message || '');
    
    // Validate type parameter against allowlist
    const validTypes = ['success', 'danger', 'warning', 'info'];
    const safeType = validTypes.includes(type) ? type : 'info';
    
    if (window.showToast) {
        window.showToast(safeMessage, safeType);
    } else {
        // Fallback notification if showToast doesn't exist
        const notification = document.createElement('div');
        notification.className = `alert alert-${safeType} position-fixed top-0 start-50 translate-middle-x mt-3`;
        notification.style.zIndex = '9999';
        notification.textContent = safeMessage;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
}

// Share button functionality
const shareButton = document.getElementById('share-button');
if (shareButton) {
    shareButton.addEventListener('click', function handleShareClick() {
        const currentUrl = window.location.href;
        
        // Use modern Clipboard API if available
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(currentUrl).then(function() {
                showNotification('Product link copied to clipboard!', 'success');
            }).catch(function(err) {
                console.error('Failed to copy URL:', err);
                showNotification('Failed to copy link. Please try again.', 'danger');
            });
        } else {
            // Fallback for browsers without Clipboard API support (primarily older mobile browsers)
            const textarea = document.createElement('textarea');
            textarea.value = currentUrl;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.setAttribute('aria-hidden', 'true');
            textarea.setAttribute('tabindex', '-1');
            document.body.appendChild(textarea);
            textarea.select();
            // Mobile browser compatibility: some mobile browsers don't fully support select()
            textarea.setSelectionRange(0, textarea.value.length);
            
            try {
                // Note: document.execCommand is deprecated but required for older browsers without Clipboard API
                document.execCommand('copy');
                showNotification('Product link copied to clipboard!', 'success');
            } catch (err) {
                console.error('Failed to copy URL:', err);
                showNotification('Failed to copy link. Please try again.', 'danger');
            } finally {
                // Clean up temporary textarea
                textarea.remove();
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
