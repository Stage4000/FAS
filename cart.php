<?php
$pageTitle = 'Shopping Cart';
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
                    <p class="text-muted">Start shopping to add items to your cart</p>
                    <a href="products.php" class="btn btn-danger btn-ripple">Browse Products</a>
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
                        <span class="text-muted">Calculated at checkout</span>
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
});

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
        html += `
            <div class="card border-0 shadow-sm mb-3 cart-item-card card-entrance" style="animation-delay: ${index * 0.1}s;">
                <div class="card-body">
                    <div class="row align-items-center cart-item-mobile">
                        <!-- Image - hidden on mobile -->
                        <div class="col-md-2 cart-item-image">
                            ${item.image ? `<img src="${item.image}" class="img-fluid rounded" alt="${item.name}" loading="lazy">` : '<div class="bg-light p-3 rounded text-center"><i class="fas fa-image"></i></div>'}
                        </div>
                        
                        <!-- Product Details -->
                        <div class="col-md-4 cart-item-details">
                            <h6 class="mb-1 fw-bold">${item.name}</h6>
                            <small class="text-muted d-block">SKU: ${item.sku || 'N/A'}</small>
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
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
