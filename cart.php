<?php
$pageTitle = 'Shopping Cart';
$metaTitle = 'Shopping Cart | Flip and Strip';
$metaDescription = 'Review selected Flip and Strip parts before checkout.';
$canonicalUrl = 'https://flipandstrip.com/cart';
$robotsMeta = 'noindex, follow';
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';
require_once __DIR__ . '/includes/product-merchandising.php';

$cartTrendingProducts = [];
try {
    $cartDb = \FAS\Config\Database::getInstance()->getConnection();
    $cartProductModel = new \FAS\Models\Product($cartDb);
    $cartTrendingProducts = fasAnalyticsRankedProducts($cartDb, $cartProductModel, 4);
} catch (Throwable $e) {
    $cartTrendingProducts = [];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5 animate-fade-in">
    <h1 class="mb-4 fw-bold">Shopping Cart</h1>
    
    <div class="row">
        <div class="col-lg-8">
            <div id="cart-items-container" class="scroll-reveal">
                <!-- Cart items will be dynamically loaded here -->
            </div>
            
            <div id="empty-cart-message" class="card border-0 shadow-sm animate-scale" style="display: none;">
<div class="card-body text-center py-5">
<i class="fas fa-shopping-cart display-1 text-muted mb-3"></i>
<h3>Your cart is empty</h3>
<p class="text-muted mb-4">Start with a popular category or high-demand part, then come back here to review totals before checkout.</p>
<div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
<a href="/products/motorcycle" class="btn btn-outline-danger btn-sm">Motorcycle Parts</a>
<a href="/products/atv" class="btn btn-outline-danger btn-sm">ATV / UTV Parts</a>
<a href="/products/boat" class="btn btn-outline-danger btn-sm">Boat Parts</a>
<a href="/products/automotive" class="btn btn-outline-danger btn-sm">Automotive Parts</a>
<a href="/products" class="btn btn-danger btn-sm btn-ripple">Browse All Products</a>
</div>
<?php if (!empty($cartTrendingProducts)): ?>
<div class="text-start mt-4">
<h4 class="h5 fw-bold text-center mb-3">Popular Parts Shoppers Are Viewing</h4>
<div class="row g-3">
<?php foreach ($cartTrendingProducts as $index => $cartProduct): ?>
<?php echo fasProductCard($cartProduct, 'col-lg-6 col-md-6 col-sm-12', min($index * 50, 250)); ?>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
        
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top order-summary-mobile">
                <div class="card-body">
                    <h4 class="mb-4">Order Summary</h4>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <span id="cart-subtotal">$0.00</span>
                    </div>
<div class="d-flex justify-content-between mb-2">
<span>Shipping:</span>
<span id="cart-shipping-estimate" class="text-muted">Estimate below</span>
</div>
<div class="d-flex justify-content-between mb-2 shipping-estimate-total-row d-none">
<span>Estimated total:</span>
<span id="cart-estimated-total" class="fw-semibold">$0.00</span>
</div>
<div class="alert alert-light border small mb-3">
<strong>Before You Pay</strong>
<ul class="mb-0 ps-3">
<li>Estimate shipping here, then confirm final rates at checkout.</li>
<li>Any coupon code is applied on the checkout page.</li>
<li>The final total is shown before secure PayPal payment approval.</li>
</ul>
</div>
<div class="card border-0 bg-light mb-3 shipping-estimator-card">
<div class="card-body p-3">
<h5 class="h6 fw-bold mb-2">
<i class="fas fa-truck-fast text-danger me-1"></i>Shipping Estimate
</h5>
<form class="row g-2" data-shipping-estimator data-estimate-mode="cart" data-result-target="#cart-shipping-estimate-result" data-shipping-target="#cart-shipping-estimate" data-total-target="#cart-estimated-total">
<div class="col-12">
<label class="form-label small fw-semibold" for="cart-estimate-city">City</label>
<input type="text" class="form-control form-control-sm" id="cart-estimate-city" name="city" placeholder="Portland" autocomplete="address-level2">
</div>
<div class="col-5">
<label class="form-label small fw-semibold" for="cart-estimate-state">State</label>
<input type="text" class="form-control form-control-sm text-uppercase" id="cart-estimate-state" name="state" maxlength="2" placeholder="OR" autocomplete="address-level1">
</div>
<div class="col-7">
<label class="form-label small fw-semibold" for="cart-estimate-zip">ZIP Code</label>
<input type="text" class="form-control form-control-sm" id="cart-estimate-zip" name="zip" inputmode="numeric" placeholder="97035" autocomplete="postal-code">
</div>
<div class="col-12">
<button type="submit" class="btn btn-outline-danger btn-sm w-100">
<i class="fas fa-calculator me-1"></i>Estimate Shipping
</button>
</div>
</form>
<div id="cart-shipping-estimate-result" class="mt-3"></div>
</div>
</div>
<hr>
                    <div class="d-flex justify-content-between mb-4">
                        <strong>Total:</strong>
                        <strong id="cart-total" class="text-danger fs-4">$0.00</strong>
                    </div>
                    
                    <a href="checkout.php" id="checkout-btn" class="btn btn-danger btn-lg w-100 mb-2 btn-ripple" style="display: none;">
                        <i class="fas fa-credit-card"></i> Proceed to Checkout
                    </a>
                    <a href="products.php" class="btn btn-outline-danger w-100">Continue Shopping</a>
                    
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="mb-3">We Accept</h6>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-credit-card display-6 text-muted me-3"></i>
                            <div>
                                <small class="text-muted">Secure PayPal, Debit &amp; Credit Checkout</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Cart page specific JavaScript
document.addEventListener('DOMContentLoaded', function() {
    displayCartItems();
    
    // Checkout button
    document.getElementById('checkout-btn').addEventListener('click', function(e) {
        if (window.cart.cart.length === 0) {
            e.preventDefault();
            alert('Your cart is empty');
    }
    // Allow navigation to checkout.php
});

document.addEventListener('click', function(e) {
    if (e.target.closest('#empty-cart-message .add-to-cart')) {
        setTimeout(displayCartItems, 150);
    }
});
});

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value || '';
    return div.innerHTML;
}

function displayCartItems() {
    const cartItemsContainer = document.getElementById('cart-items-container');
    const emptyCartMessage = document.getElementById('empty-cart-message');
    const checkoutBtn = document.getElementById('checkout-btn');
    
    if (window.cart.cart.length === 0) {
        cartItemsContainer.style.display = 'none';
        emptyCartMessage.style.display = 'block';
        checkoutBtn.style.display = 'none';
        updateCartSummary();
        return;
    }
    
    emptyCartMessage.style.display = 'none';
    cartItemsContainer.style.display = 'block';
    checkoutBtn.style.display = 'block';
    
    let html = '';
    window.cart.cart.forEach((item, index) => {
        const imageSrc = escapeHtml(item.image || '');
        const imageAlt = escapeHtml(item.image_alt || item.name || 'Product image');
        const itemName = escapeHtml(item.name || '');
        const sku = escapeHtml(item.sku || 'N/A');
        const freeShippingBadge = item.free_shipping ? '<small class="text-success fw-semibold d-block mt-1"><i class="fas fa-truck-fast me-1"></i>Free shipping eligible for continental US addresses</small>' : '';

        html += `
            <div class="card border-0 shadow-sm mb-3 cart-item-card card-entrance" style="animation-delay: ${index * 0.1}s;">
                <div class="card-body">
                    <div class="row align-items-center cart-item-mobile">
                        <!-- Image - hidden on mobile -->
                        <div class="col-md-2 cart-item-image">
                            ${imageSrc ? `<img src="${imageSrc}" class="img-fluid rounded" alt="${imageAlt}" loading="lazy">` : '<div class="bg-light p-3 rounded text-center"><i class="fas fa-image"></i></div>'}
                        </div>
                        
                        <!-- Product Details -->
            <div class="col-md-4 cart-item-details">
            <h6 class="mb-1 fw-bold">${itemName}</h6>
            <small class="text-muted d-block">SKU: ${sku}</small>
            ${freeShippingBadge}
            <div class="d-md-none cart-item-price mt-2">
                                $${(item.price * item.quantity).toFixed(2)}
                            </div>
                        </div>
                        
                        <!-- Quantity Controls -->
                        <div class="col-md-2 cart-item-quantity">
                            <div class="input-group input-group-sm">
                                <button class="btn btn-outline-secondary mobile-touch-target" onclick="updateItemQuantity('${item.id}', ${item.quantity - 1})" aria-label="Decrease quantity">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <input type="number" class="form-control text-center" value="${item.quantity}" min="1" max="${item.stock || 999}"
                                       onchange="updateItemQuantity('${item.id}', this.value)" aria-label="Quantity">
                                <button class="btn btn-outline-secondary mobile-touch-target" onclick="updateItemQuantity('${item.id}', ${item.quantity + 1})" aria-label="Increase quantity">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Price - desktop only -->
                        <div class="col-md-2 text-center d-none d-md-block">
                            <strong class="text-danger fs-5">$${(item.price * item.quantity).toFixed(2)}</strong>
                        </div>
                        
                        <!-- Remove Button -->
                        <div class="col-md-2 text-end cart-item-total">
                            <button class="btn btn-sm btn-outline-danger mobile-touch-target" onclick="removeCartItem('${item.id}')" aria-label="Remove item">
                                <i class="fas fa-trash"></i> <span class="d-none d-md-inline">Remove</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    cartItemsContainer.innerHTML = html;
    updateCartSummary();
    
    // Trigger scroll reveal animations
    setTimeout(() => {
        document.querySelectorAll('.scroll-reveal').forEach(el => {
            el.classList.add('revealed');
        });
    }, 100);
}

function updateItemQuantity(productId, quantity) {
    window.cart.updateQuantity(productId, quantity);
    displayCartItems();
}

function removeCartItem(productId) {
    if (confirm('Remove this item from cart?')) {
        window.cart.removeItem(productId);
        displayCartItems();
    }
}

function updateCartSummary() {
    const subtotal = window.cart.getTotal();
    document.getElementById('cart-subtotal').textContent = `$${subtotal.toFixed(2)}`;
    document.getElementById('cart-total').textContent = `$${subtotal.toFixed(2)}`;
    const shippingEstimate = document.getElementById('cart-shipping-estimate');
    const estimatedTotalRow = document.querySelector('.shipping-estimate-total-row');
    const estimateResult = document.getElementById('cart-shipping-estimate-result');
    if (shippingEstimate) {
        shippingEstimate.textContent = window.cart.cart.length > 0 ? 'Estimate below' : 'No items';
        shippingEstimate.classList.add('text-muted');
        shippingEstimate.classList.remove('text-success', 'text-danger');
    }
    if (estimatedTotalRow) {
        estimatedTotalRow.classList.add('d-none');
    }
    if (estimateResult) {
        estimateResult.innerHTML = '';
    }
}
</script>
<script src="/public/js/shipping-estimator.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
