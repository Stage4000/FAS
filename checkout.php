<?php
// Disable error display in production to prevent HTML corruption
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Ensure proper content type (must be first)
header('Content-Type: text/html; charset=UTF-8');

// Load configuration for PayPal
$configFile = __DIR__ . '/src/config/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/src/config/config.example.php';
}
$config = require $configFile;
$paypalClientId = $config['paypal']['client_id'] ?? '';
$paypalMode = $config['paypal']['mode'] ?? 'sandbox';

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
                            <input type="text" class="form-control" name="country" id="country-select" value="US" readonly required>
                            <small class="text-muted">Currently shipping to US addresses only</small>
                        </div>
                        <button type="button" class="btn btn-danger col-12" id="calculate-shipping-btn">
                            <i class="bi bi-calculator"></i> Calculate Shipping
                        </button>
                    </div>
                </div>
                
                <!-- Shipping Options -->
                <div class="card border-0 shadow-sm mb-4" id="shipping-options-card" style="display: none;">
                    <div class="card-body p-4">
                        <h4 class="mb-4"><i class="bi bi-box-seam me-2"></i>Select Shipping Method</h4>
                        <div id="shipping-options-container">
                            <!-- Shipping options will be loaded here -->
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
            <div class="card border-0 shadow-sm sticky-top" style="top: 100px; z-index: 100;">
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
                    
                    <!-- PayPal Button Container -->
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

<!-- Load PayPal SDK -->
<?php if (!empty($paypalClientId) && strpos($paypalClientId, 'YOUR_') !== 0): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?php echo htmlspecialchars($paypalClientId); ?>&currency=USD"></script>
<?php endif; ?>

<script type="text/javascript">
let selectedShippingRate = null;

// Function to check if form is ready for payment
function isFormReadyForPayment() {
    const form = document.getElementById('checkout-form');
    if (!form) return false;
    
    // Check required fields
    const requiredFields = form.querySelectorAll('[required]');
    for (let field of requiredFields) {
        if (!field.value || (field.type === 'select-one' && !field.value)) {
            return false;
        }
    }
    
    // Check if shipping is selected
    if (!selectedShippingRate) {
        return false;
    }
    
    return true;
}

// Function to update payment button state
function updatePaymentButtonState() {
    const container = document.getElementById('paypal-button-container');
    const isReady = isFormReadyForPayment();
    
    if (container) {
        container.style.opacity = isReady ? '1' : '0.5';
        container.style.pointerEvents = isReady ? 'auto' : 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    displayCheckoutItems();
    
    // Calculate shipping button
    document.getElementById('calculate-shipping-btn').addEventListener('click', calculateShipping);
    
    // Setup PayPal button
    setupPayPalButton();
    
    // Monitor form fields for changes to enable/disable payment button
    const form = document.getElementById('checkout-form');
    if (form) {
        form.addEventListener('input', updatePaymentButtonState);
        form.addEventListener('change', updatePaymentButtonState);
    }
    
    // Initial state
    updatePaymentButtonState();
});

/**
 * Setup PayPal Button with full integration
 */
function setupPayPalButton() {
    const container = document.getElementById('paypal-button-container');
    
    // Check if PayPal SDK is loaded
    if (typeof paypal === 'undefined') {
        // Fallback to demo mode if PayPal SDK not configured
        container.innerHTML = `
            <button type="button" class="btn btn-primary btn-lg w-100" onclick="handleDemoCheckout()">
                <i class="bi bi-paypal"></i> Complete Order (Demo Mode)
            </button>
            <small class="text-muted d-block mt-2">Configure PayPal credentials for live payments</small>
        `;
        return;
    }
    
    // Render actual PayPal button
    paypal.Buttons({
        style: {
            layout: 'vertical',
            color: 'blue',
            shape: 'rect',
            label: 'pay'
        },
        
        // Create order on PayPal
        createOrder: async function(data, actions) {
            // Validate form
            const form = document.getElementById('checkout-form');
            if (!form.checkValidity()) {
                form.reportValidity();
                throw new Error('Please fill in all required fields');
            }
            
            // Validate shipping
            if (!selectedShippingRate) {
                alert('Please calculate and select a shipping method first');
                throw new Error('Shipping not selected');
            }
            
            // Create order in our database first
            const orderResult = await createOrder();
            if (!orderResult) {
                throw new Error('Failed to create order');
            }
            
            // Calculate amounts
            const cart = window.cart.cart;
            const subtotal = window.cart.getTotal();
            const tax = 0; // Tax removed - will be implemented based on customer location in future
            const shipping = selectedShippingRate.cost;
            const total = subtotal + tax + shipping;
            
            // Create PayPal order
            return actions.order.create({
                purchase_units: [{
                    amount: {
                        currency_code: 'USD',
                        value: total.toFixed(2),
                        breakdown: {
                            item_total: {
                                currency_code: 'USD',
                                value: subtotal.toFixed(2)
                            },
                            shipping: {
                                currency_code: 'USD',
                                value: shipping.toFixed(2)
                            },
                            tax_total: {
                                currency_code: 'USD',
                                value: tax.toFixed(2)
                            }
                        }
                    },
                    items: cart.map(item => ({
                        name: item.name,
                        description: item.sku || '',
                        unit_amount: {
                            currency_code: 'USD',
                            value: item.price.toFixed(2)
                        },
                        quantity: item.quantity.toString()
                    })),
                    shipping: {
                        name: {
                            full_name: form.first_name.value + ' ' + form.last_name.value
                        },
                        address: {
                            address_line_1: form.address1.value,
                            address_line_2: form.address2.value || '',
                            admin_area_2: form.city.value,
                            admin_area_1: form.state.value,
                            postal_code: form.zip.value,
                            country_code: form.country.value
                        }
                    }
                }],
                application_context: {
                    shipping_preference: 'SET_PROVIDED_ADDRESS'
                }
            });
        },
        
        // Handle payment approval
        onApprove: async function(data, actions) {
            try {
                // Capture the payment
                const orderData = await actions.order.capture();
                
                // Complete order in our system
                await completeOrder(data.orderID, orderData.purchase_units[0].payments.captures[0].id);
                
                // Clear cart
                window.cart.clearCart();
                
                // Show success message and redirect
                alert('Payment successful! Order #' + orderData.id + ' completed.');
                window.location.href = 'index.php?order_success=1';
                
            } catch (error) {
                console.error('Payment capture error:', error);
                alert('Payment was approved but there was an error completing your order. Please contact support.');
            }
        },
        
        // Handle errors
        onError: function(err) {
            console.error('PayPal error:', err);
            alert('An error occurred with PayPal. Please try again or contact support.');
        },
        
        // Handle cancellation
        onCancel: function(data) {
            console.log('Payment cancelled:', data);
            alert('Payment was cancelled. Your cart items are still saved.');
        }
    }).render('#paypal-button-container');
}

/**
 * Demo checkout handler (fallback when PayPal not configured)
 */
async function handleDemoCheckout() {
    const form = document.getElementById('checkout-form');
    
    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Validate shipping is selected
    if (!selectedShippingRate) {
        alert('Please calculate and select a shipping method first');
        return;
    }
    
    // Create order
    const orderResult = await createOrder();
    if (!orderResult) {
        return;
    }
    
    // Simulate payment completion
    if (confirm('Simulate payment completion for order #' + orderResult.order_number + '?')) {
        await completeOrder('DEMO-PAYPAL-ORDER-' + orderResult.order_id, 'DEMO-TRANSACTION-' + Date.now());
        
        // Clear cart
        window.cart.clearCart();
        
        // Redirect
        alert('Demo order completed successfully! Order #' + orderResult.order_number);
        window.location.href = 'index.php';
    }
}

/**
 * Handle checkout process (kept for legacy compatibility)
 */
async function handleCheckout() {
    const form = document.getElementById('checkout-form');
    
    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Validate shipping is selected
    if (!selectedShippingRate) {
        alert('Please calculate and select a shipping method first');
        return;
    }
    
    // Create order
    const orderResult = await createOrder();
    if (!orderResult) {
        return;
    }
    
    // In a real implementation, this would integrate with PayPal SDK
    // For demo purposes, we'll simulate payment completion
    if (confirm('Simulate payment completion for order #' + orderResult.order_number + '?')) {
        await completeOrder('DEMO-PAYPAL-ORDER-' + orderResult.order_id, 'DEMO-TRANSACTION-' + Date.now());
    }
}

async function calculateShipping() {
    const form = document.getElementById('checkout-form');
    const address = {
        address1: form.address1.value,
        address2: form.address2.value,
        city: form.city.value,
        state: form.state.value,
        zip: form.zip.value,
        country: form.country.value
    };
    
    // Validate address fields
    if (!address.address1 || !address.city || !address.state || !address.zip) {
        alert('Please fill in all required shipping address fields');
        return;
    }
    
    const cart = window.cart.cart;
    const items = cart.map(item => ({
        name: item.name,
        sku: item.sku,
        price: item.price,
        quantity: item.quantity,
        weight: 1.5 // Default weight, should come from product data
    }));
    
    const btn = document.getElementById('calculate-shipping-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Calculating...';
    
    try {
        const response = await fetch('/api/shipping-rates.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ items, address })
        });
        
        const data = await response.json();
        
        if (data.success && data.rates) {
            displayShippingOptions(data.rates);
        } else {
            alert('Error: ' + (data.error || 'Failed to calculate shipping'));
        }
    } catch (error) {
        console.error('Shipping calculation error:', error);
        alert('Failed to calculate shipping rates. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-calculator"></i> Calculate Shipping';
    }
}

function displayShippingOptions(rates) {
    const container = document.getElementById('shipping-options-container');
    const card = document.getElementById('shipping-options-card');
    
    let html = '';
    rates.forEach((rate, index) => {
        html += `
            <div class="form-check mb-3 p-3 border rounded">
                <input class="form-check-input" type="radio" name="shipping_method" 
                       id="shipping_${index}" value="${index}" 
                       data-cost="${rate.total_charge}" 
                       data-courier="${rate.courier_id}"
                       onchange="selectShippingMethod(${index}, ${rate.total_charge})">
                <label class="form-check-label w-100" for="shipping_${index}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong>${rate.courier_name}</strong> - ${rate.service_name}<br>
                            <small class="text-muted">${rate.delivery_time_text}</small>
                        </div>
                        <strong class="text-danger">$${rate.total_charge.toFixed(2)}</strong>
                    </div>
                </label>
            </div>
        `;
    });
    
    container.innerHTML = html;
    card.style.display = 'block';
    
    // Auto-select cheapest option
    if (rates.length > 0) {
        document.getElementById('shipping_0').checked = true;
        selectShippingMethod(0, rates[0].total_charge);
    }
}

function selectShippingMethod(index, cost) {
    selectedShippingRate = { index, cost };
    updateCheckoutSummary();
    updatePaymentButtonState(); // Enable payment button when shipping is selected
}

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
    const tax = 0; // Tax removed - will be implemented based on customer location in future
    const shipping = selectedShippingRate ? selectedShippingRate.cost : 0;
    const total = subtotal + tax + shipping;
    
    document.getElementById('checkout-subtotal').textContent = `$${subtotal.toFixed(2)}`;
    document.getElementById('checkout-tax').textContent = `$${tax.toFixed(2)}`;
    
    if (selectedShippingRate) {
        document.getElementById('checkout-shipping').textContent = `$${shipping.toFixed(2)}`;
        document.getElementById('checkout-shipping').classList.remove('text-muted');
    } else {
        document.getElementById('checkout-shipping').textContent = 'Calculate shipping';
        document.getElementById('checkout-shipping').classList.add('text-muted');
    }
    
    document.getElementById('checkout-total').textContent = `$${total.toFixed(2)}`;
}

/**
 * Process order creation
 */
async function createOrder() {
    const form = document.getElementById('checkout-form');
    const cart = window.cart.cart;
    
    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return null;
    }
    
    // Prepare order data
    const subtotal = window.cart.getTotal();
    const tax = 0; // Tax removed - will be implemented based on customer location in future
    const shipping = selectedShippingRate ? selectedShippingRate.cost : 0;
    const total = subtotal + tax + shipping;
    
    const orderData = {
        action: 'create_order',
        customer_email: form.email.value,
        customer_name: form.first_name.value + ' ' + form.last_name.value,
        customer_phone: form.phone.value,
        shipping_address: {
            address1: form.address1.value,
            address2: form.address2.value,
            city: form.city.value,
            state: form.state.value,
            zip: form.zip.value,
            country: form.country.value
        },
        items: cart.map(item => ({
            product_id: item.id,
            product_name: item.name,
            product_sku: item.sku,
            quantity: item.quantity,
            unit_price: item.price
        })),
        subtotal: subtotal,
        shipping_cost: shipping,
        tax_amount: tax,
        total_amount: total,
        notes: form.notes.value,
        paypal_order_id: 'PENDING-' + Date.now() // Will be updated with actual PayPal order ID
    };
    
    try {
        const response = await fetch('/api/process-order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(orderData)
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'Failed to create order');
        }
        
        return data;
    } catch (error) {
        console.error('Order creation error:', error);
        alert('Failed to create order: ' + error.message);
        return null;
    }
}

/**
 * Complete order after payment
 */
async function completeOrder(paypalOrderId, paypalTransactionId) {
    try {
        const response = await fetch('/api/process-order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'complete_order',
                paypal_order_id: paypalOrderId,
                paypal_transaction_id: paypalTransactionId
            })
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'Failed to complete order');
        }
        
        // Clear cart
        window.cart.clearCart();
        
        // Redirect to success page
        alert('Order completed successfully! Order #' + data.order_number);
        window.location.href = 'index.php';
        
    } catch (error) {
        console.error('Order completion error:', error);
        alert('Payment was successful but there was an issue completing your order. Please contact support.');
    }
}

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
