<?php
$pageTitle = 'Shopping Cart';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5">
    <h1 class="mb-4 fw-bold">Shopping Cart</h1>
    
    <div class="row">
        <div class="col-lg-8">
            <div id="cart-items-container">
                <!-- Cart items will be dynamically loaded here -->
            </div>
            
            <div id="empty-cart-message" class="card border-0 shadow-sm" style="display: none;">
                <div class="card-body text-center py-5">
                    <i class="bi bi-cart-x display-1 text-muted mb-3"></i>
                    <h3>Your cart is empty</h3>
                    <p class="text-muted">Start shopping to add items to your cart</p>
                    <a href="products.php" class="btn btn-danger">Browse Products</a>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top: 100px;">
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
                    
                    <button id="checkout-btn" class="btn btn-danger btn-lg w-100 mb-2" disabled>
                        <i class="bi bi-credit-card"></i> Proceed to Checkout
                    </button>
                    <a href="products.php" class="btn btn-outline-dark w-100">Continue Shopping</a>
                    
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="mb-3">We Accept</h6>
                        <div class="d-flex align-items-center">
                            <i class="bi bi-credit-card display-6 text-muted me-3"></i>
                            <div>
                                <small class="text-muted">Secure PayPal Checkout</small>
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
    document.getElementById('checkout-btn').addEventListener('click', function() {
        if (window.cart.cart.length > 0) {
            alert('Proceeding to PayPal checkout...\n\nThis will be integrated with PayPal payment gateway.');
            // TODO: Integrate with PayPal
        }
    });
});

function displayCartItems() {
    const cartItemsContainer = document.getElementById('cart-items-container');
    const emptyCartMessage = document.getElementById('empty-cart-message');
    const checkoutBtn = document.getElementById('checkout-btn');
    
    if (window.cart.cart.length === 0) {
        cartItemsContainer.style.display = 'none';
        emptyCartMessage.style.display = 'block';
        checkoutBtn.disabled = true;
        updateCartSummary();
        return;
    }
    
    emptyCartMessage.style.display = 'none';
    cartItemsContainer.style.display = 'block';
    checkoutBtn.disabled = false;
    
    let html = '';
    window.cart.cart.forEach(item => {
        html += `
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-2">
                            ${item.image ? `<img src="${item.image}" class="img-fluid rounded" alt="${item.name}">` : '<div class="bg-light p-3 rounded text-center"><i class="bi bi-image"></i></div>'}
                        </div>
                        <div class="col-md-4">
                            <h6 class="mb-1">${item.name}</h6>
                            <small class="text-muted">SKU: ${item.sku || 'N/A'}</small>
                        </div>
                        <div class="col-md-2 text-center">
                            <div class="input-group input-group-sm">
                                <button class="btn btn-outline-secondary" onclick="updateItemQuantity('${item.id}', ${item.quantity - 1})">-</button>
                                <input type="number" class="form-control text-center" value="${item.quantity}" min="1" 
                                       onchange="updateItemQuantity('${item.id}', this.value)" style="max-width: 60px;">
                                <button class="btn btn-outline-secondary" onclick="updateItemQuantity('${item.id}', ${item.quantity + 1})">+</button>
                            </div>
                        </div>
                        <div class="col-md-2 text-center">
                            <strong class="text-danger">$${(item.price * item.quantity).toFixed(2)}</strong>
                        </div>
                        <div class="col-md-2 text-end">
                            <button class="btn btn-sm btn-outline-danger" onclick="removeCartItem('${item.id}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    cartItemsContainer.innerHTML = html;
    updateCartSummary();
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
