<?php
/**
 * Order Processing API
 * Creates order and deducts inventory when payment is completed
 */

require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../src/utils/Timezone.php';
\FAS\Utils\Timezone::apply();

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Order.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/integrations/PayPalAPI.php';
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';
require_once __DIR__ . '/../src/shipping/ShippingOrder.php';
require_once __DIR__ . '/../src/payments/CheckoutPricing.php';

use FAS\Config\Database;
use FAS\Models\Order;
use FAS\Models\Product;
use FAS\Integrations\PayPalAPI;
use FAS\Utils\ErrorMonitor;
use FAS\Shipping\ShippingOrder;
use FAS\Payments\CheckoutProblem;
use FAS\Payments\CheckoutPricing;

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get request data
$input = json_decode(fas_security_body(), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

$action = $input['action'] ?? '';
if ($action === 'create_order') fas_security_guard('payment_create');
elseif ($action === 'complete_order') fas_security_guard('payment_recovery', true);

try {
    $db = Database::getInstance()->getConnection();
    $orderModel = new Order($db);
    $productModel = new Product($db);
    
    switch ($action) {
        case 'create_order':
            createOrder($input, $orderModel, $productModel);
            break;
        
        case 'complete_order':
            completeOrder($input, $orderModel, $productModel);
            break;
        
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (CheckoutProblem $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Order API Error: ' . $e->getMessage());
    try {
        if (!isset($db)) {
            $db = Database::getInstance()->getConnection();
        }
        $monitor = new ErrorMonitor($db);
        $monitor->recordThrowable(ErrorMonitor::AREA_CHECKOUT, $e, [
            'source' => 'api/process-order.php',
            'severity' => 'error',
            'metadata' => [
                'action' => $action ?? null,
                'customer_email' => $input['customer_email'] ?? null,
                'items_count' => isset($input['items']) && is_array($input['items']) ? count($input['items']) : null,
            ],
        ]);
    } catch (Throwable $monitorError) {
        error_log('Order API error monitor write failed: ' . $monitorError->getMessage());
    }
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}

/**
 * Create order in database (called when customer initiates checkout) with improved validation
 */
function createOrder($input, $orderModel, $productModel)
{
    [$shippingKey, $shippingQuote, $selectedRate] = ShippingOrder::selected($input);
    // Validate required fields
    $required = ['customer_email', 'items', 'subtotal', 'total_amount'];
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: {$field}"]);
            exit;
        }
    }
    
    // Validate email format
    if (!filter_var($input['customer_email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address']);
        exit;
    }
    
    $priced=CheckoutPricing::calculate($input,$shippingQuote['cart'],$productModel,
        \FAS\Payments\ApplePayContext::catalogCents($selectedRate['total_charge']));
    
    // Generate order number
    $orderNumber = $orderModel->generateOrderNumber();
    
    // Create order
    $orderData = [
        'order_number' => $orderNumber,
        'customer_email' => $input['customer_email'],
        'customer_name' => $input['customer_name'] ?? null,
        'customer_phone' => $input['customer_phone'] ?? null,
        'billing_address' => $input['billing_address'] ?? null,
        'shipping_address' => $input['shipping_address'] ?? null,
        'subtotal' => $priced['subtotal'],
        'shipping_cost' => $priced['shipping_cost'],
        'tax_amount' => $priced['tax_amount'],
        'discount_code' => $priced['discount_code'],
        'discount_amount' => $priced['discount_amount'],
        'total_amount' => $priced['total_amount'],
        'payment_method' => 'paypal',
        'payment_status' => 'pending',
        'paypal_order_id' => null,
        'order_status' => 'pending',
        'notes' => $input['notes'] ?? null
    ];
    
    $orderItems = $priced['items'];
    
    $db = $productModel->getDb();
    $db->beginTransaction();
    try {
        $orderId = $orderModel->create($orderData);
        if (!$orderId || !$orderModel->addItems($orderId, $orderItems)) {
            throw new RuntimeException('Failed to create order.');
        }
        ShippingOrder::record($db, (int)$orderId, $shippingKey, $shippingQuote, $selectedRate);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    
    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'order_number' => $orderNumber
    ]);
}

/**
 * Complete order and deduct inventory (called after payment is confirmed) with improved validation
 */
function completeOrder($input, $orderModel, $productModel)
{
    $paypalOrderId = $input['paypal_order_id'] ?? null;
    $paypalTransactionId = $input['paypal_transaction_id'] ?? null;
    $orderId = $input['order_id'] ?? null;
    
    if (!$paypalOrderId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing PayPal order ID']);
        exit;
    }
    
    // Find order: prefer lookup by our DB order ID (reliable), fall back to PayPal order ID
    $order = null;
    if ($orderId) {
        $order = $orderModel->getById($orderId);
    }
    if (!$order) {
        $order = $orderModel->getByPayPalOrderId($paypalOrderId);
    }
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }
    
    // Wallet orders must only be completed by the server-verified Apple Pay path.
    if (($order['payment_method'] ?? '') === 'applepay') {
        http_response_code(409);
        echo json_encode(['error' => 'Apple Pay orders use the dedicated payment endpoint.']);
        exit;
    }

    // Check if already completed to prevent duplicate inventory deduction
    if ($order['payment_status'] === 'completed') {
        echo json_encode([
            'success' => true,
            'message' => 'Order already completed',
            'order_number' => $order['order_number']
        ]);
        exit;
    }
    
    // Use transaction to prevent race conditions during inventory check and deduction
    // This ensures atomicity: either all inventory is deducted or none is
    $db = $productModel->getDb();
    
    try {
        $db->beginTransaction();

        // Acquire SQLite's writer lock before rechecking completion. Another worker
        // may have completed this order since the initial lookup above.
        $claim = $db->prepare('UPDATE orders SET id=id WHERE id=?');
        $claim->execute([$order['id']]);
        $currentOrder = $orderModel->getById($order['id']);
        if (!$currentOrder) throw new RuntimeException('Order is no longer available.');
        if ($currentOrder['payment_status'] === 'completed') {
            $db->commit();
            echo json_encode(['success'=>true,'message'=>'Order already completed','order_number'=>$currentOrder['order_number']]);
            return;
        }
        $order = $currentOrder;
        
        // Verify inventory is still available and lock the rows
        $items = $orderModel->getItems($order['id']);
        $inventoryIssues = [];
        $productsToUpdate = [];
        
        foreach ($items as $item) {
            // Use SELECT ... FOR UPDATE to lock rows and prevent concurrent modifications
            // Note: SQLite doesn't support FOR UPDATE, but transaction provides serialization
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$item['product_id']]);
            $product = $stmt->fetch();
            
            if (!$product) {
                $inventoryIssues[] = "Product #{$item['product_id']} not found";
            } elseif ($product['quantity'] < $item['quantity']) {
                $inventoryIssues[] = "{$product['name']}: only {$product['quantity']} available, need {$item['quantity']}";
            } else {
                // Store product for deduction
                $productsToUpdate[] = [
                    'id' => $item['product_id'],
                    'name' => $product['name'],
                    'quantity' => $item['quantity'],
                    'new_quantity' => $product['quantity'] - $item['quantity']
                ];
            }
        }
        
    if (!empty($inventoryIssues)) {
        $db->rollBack();
        error_log('Order completion inventory issue for order #' . $order['order_number'] . ': ' . implode('; ', $inventoryIssues));
        try {
            $monitor = new ErrorMonitor($db);
            $monitor->record(ErrorMonitor::AREA_CHECKOUT, 'Order completion blocked by inventory availability.', [
                'source' => 'api/process-order.php',
                'severity' => 'warning',
                'order_id' => $order['id'] ?? null,
                'paypal_order_id' => $paypalOrderId ?? null,
                'metadata' => [
                    'order_number' => $order['order_number'] ?? null,
                    'inventory_issues' => $inventoryIssues,
                ],
            ]);
        } catch (Throwable $monitorError) {
            error_log('Inventory conflict error monitor write failed: ' . $monitorError->getMessage());
        }
        http_response_code(409);
            echo json_encode([
                'error' => 'Inventory no longer available',
                'details' => $inventoryIssues
            ]);
            exit;
        }
        
        // Update order status and set the real PayPal order ID
        $orderModel->update($order['id'], [
            'payment_status' => 'completed',
            'order_status' => 'processing',
            'paypal_order_id' => $paypalOrderId,
            'paypal_transaction_id' => $paypalTransactionId
        ]);
        
        // Deduct product quantities atomically
        foreach ($productsToUpdate as $product) {
            $productModel->update($product['id'], ['quantity' => $product['new_quantity']]);
            error_log("Deducted {$product['quantity']} units from product #{$product['id']} ({$product['name']}). New quantity: {$product['new_quantity']}");
        }
        
        // Increment coupon usage if a coupon was applied
        if (!empty($order['discount_code'])) {
            require_once __DIR__ . '/../src/models/Coupon.php';
            $couponModel = new \FAS\Models\Coupon($db);
            $couponModel->incrementUsage($order['discount_code']);
            error_log("Incremented usage for coupon: {$order['discount_code']}");
        }
        
        // Commit transaction
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'order_number' => $order['order_number'],
            'message' => 'Order completed and inventory updated'
        ]);
        
    } catch (Exception $e) {
        // Rollback on any error
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Order completion failed for order #' . $order['order_number'] . ': ' . $e->getMessage());
        try {
            $monitor = new ErrorMonitor($db);
            $monitor->recordThrowable(ErrorMonitor::AREA_CHECKOUT, $e, [
                'source' => 'api/process-order.php',
                'severity' => 'critical',
                'order_id' => $order['id'] ?? null,
                'paypal_order_id' => $paypalOrderId ?? null,
                'metadata' => [
                    'order_number' => $order['order_number'] ?? null,
                    'action' => 'complete_order',
                ],
            ]);
        } catch (Throwable $monitorError) {
            error_log('Order completion error monitor write failed: ' . $monitorError->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to complete order',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
