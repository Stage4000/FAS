<?php
$pageTitle = 'Checkout';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5">
    <h1 class="mb-4 fw-bold">Checkout</h1>
    
    <div class="row">
        <!-- Checkout Form -->
        <div class="col-lg-8">
            <form id="checkout-form">
                <!-- Customer Information -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h4 class="mb-4"><i class="bi bi-person me-2"></i>Customer Information</h4>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Shipping Address -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h4 class="mb-4"><i class="bi bi-truck me-2"></i>Shipping Address</h4>
                        <div class="mb-3">
                            <label class="form-label">Address Line 1 *</label>
                            <input type="text" class="form-control" name="address1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" class="form-control" name="address2">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City *</label>
                                <input type="text" class="form-control" name="city" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">State *</label>
                                <input type="text" class="form-control" name="state" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">ZIP Code *</label>
                                <input type="text" class="form-control" name="zip" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Country *</label>
                            <select class="form-select" name="country" required>
                                <option value="US">United States</option>
                                <option value="CA">Canada</option>
                                <option value="MX">Mexico</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Order Notes -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h4 class="mb-4"><i class="bi bi-chat-left-text me-2"></i>Order Notes (Optional)</h4>
                        <textarea class="form-control" name="notes" rows="3" placeholder="Special instructions or notes about your order"></textarea>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Order Summary -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top: 100px;">
                <div class="card-body p-4">
                    <h4 class="mb-4">Order Summary</h4>
                    
                    <!-- Cart Items -->
                    <div id="checkout-items" class="mb-4">
                        <!-- Items will be loaded here -->
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <span id="checkout-subtotal">$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Shipping:</span>
                        <span id="checkout-shipping" class="text-muted">Calculated</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Tax:</span>
                        <span id="checkout-tax">$0.00</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-4">
                        <strong class="fs-5">Total:</strong>
                        <strong id="checkout-total" class="text-danger fs-4">$0.00</strong>
                    </div>
                    
                    <!-- PayPal Button -->
                    <div id="paypal-button-container" class="mb-3"></div>
                    
                    <div class="text-center mt-3">
                        <small class="text-muted">
                            <i class="bi bi-shield-check me-1"></i>
                            Secure payment via PayPal
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    displayCheckoutItems();
    
    // Note: PayPal SDK would be loaded here
    // <script src="https://www.paypal.com/sdk/js?client-id=YOUR_CLIENT_ID"></script>
    
    // For now, show a placeholder
    document.getElementById('paypal-button-container').innerHTML = `
        <button type="button" class="btn btn-primary btn-lg w-100" onclick="alert('PayPal integration will be configured with API credentials')">
            <i class="bi bi-paypal"></i> Pay with PayPal
        </button>
    `;
});

function displayCheckoutItems() {
    const container = document.getElementById('checkout-items');
    const cart = window.cart.cart;
    
    if (cart.length === 0) {
        window.location.href = 'cart.php';
        return;
    }
    
    let html = '';
    cart.forEach(item => {
        html += `
            <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                <div class="d-flex align-items-center">
                    ${item.image ? `<img src="${item.image}" class="me-2" style="width: 50px; height: 50px; object-fit: cover;" alt="${item.name}">` : ''}
                    <div>
                        <div class="small fw-bold">${item.name}</div>
                        <div class="text-muted small">Qty: ${item.quantity}</div>
                    </div>
                </div>
                <div class="fw-bold">$${(item.price * item.quantity).toFixed(2)}</div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    updateCheckoutSummary();
}

function updateCheckoutSummary() {
    const subtotal = window.cart.getTotal();
    const tax = subtotal * 0.08; // 8% tax (should be calculated based on location)
    const shipping = 10.00; // Flat shipping (should be calculated via EasyShip)
    const total = subtotal + tax + shipping;
    
    document.getElementById('checkout-subtotal').textContent = `$${subtotal.toFixed(2)}`;
    document.getElementById('checkout-tax').textContent = `$${tax.toFixed(2)}`;
    document.getElementById('checkout-shipping').textContent = `$${shipping.toFixed(2)}`;
    document.getElementById('checkout-total').textContent = `$${total.toFixed(2)}`;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
