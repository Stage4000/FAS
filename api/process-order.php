<?php
/**
 * Order Processing API
 * Creates order and deducts inventory when payment is completed
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Order.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/integrations/PayPalAPI.php';

use FAS\Config\Database;
use FAS\Models\Order;
use FAS\Models\Product;
use FAS\Integrations\PayPalAPI;

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

$action = $input['action'] ?? '';

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
    
} catch (Exception $e) {
    error_log('Order API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}

/**
 * Create order in database (called when customer initiates checkout) with improved validation
 */
function createOrder($input, $orderModel, $productModel)
{
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
    
    // Validate items array
    if (!is_array($input['items']) || empty($input['items'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Items must be a non-empty array']);
        exit;
    }
    
    // Validate amounts
    if ($input['subtotal'] < 0 || $input['total_amount'] < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid amounts']);
        exit;
    }
    
    // Validate inventory availability
    foreach ($input['items'] as $item) {
        if (!isset($item['product_id']) || !isset($item['quantity'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid item structure']);
            exit;
        }
        
        $product = $productModel->getById($item['product_id'], true); // Include inactive to check existence
        if (!$product) {
            http_response_code(400);
            echo json_encode(['error' => 'Product not found: ' . $item['product_id']]);
            exit;
        }
        
        // Check if product is active and available
        if (!$product['is_active']) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Product is no longer available',
                'product' => $product['name']
            ]);
            exit;
        }
        
        if ($product['quantity'] < $item['quantity']) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Insufficient stock',
                'product' => $product['name'],
                'available' => $product['quantity'],
                'requested' => $item['quantity']
            ]);
            exit;
        }
    }
    
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
        'subtotal' => $input['subtotal'],
        'shipping_cost' => $input['shipping_cost'] ?? 0,
        'tax_amount' => $input['tax_amount'] ?? 0,
        'total_amount' => $input['total_amount'],
        'payment_method' => 'paypal',
        'payment_status' => 'pending',
        'paypal_order_id' => $input['paypal_order_id'] ?? null,
        'order_status' => 'pending',
        'notes' => $input['notes'] ?? null
    ];
    
    $orderId = $orderModel->create($orderData);
    
    if (!$orderId) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create order']);
        exit;
    }
    
    // Add order items
    $orderItems = [];
    foreach ($input['items'] as $item) {
        $orderItems[] = [
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'product_sku' => $item['product_sku'] ?? null,
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'total_price' => $item['unit_price'] * $item['quantity']
        ];
    }
    
    $orderModel->addItems($orderId, $orderItems);
    
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
    
    if (!$paypalOrderId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing PayPal order ID']);
        exit;
    }
    
    // Find order by PayPal order ID
    $order = $orderModel->getByPayPalOrderId($paypalOrderId);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
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
            http_response_code(409);
            echo json_encode([
                'error' => 'Inventory no longer available',
                'details' => $inventoryIssues
            ]);
            exit;
        }
        
        // Update order status
        $orderModel->update($order['id'], [
            'payment_status' => 'completed',
            'order_status' => 'processing',
            'paypal_transaction_id' => $paypalTransactionId
        ]);
        
        // Deduct product quantities atomically
        foreach ($productsToUpdate as $product) {
            $productModel->update($product['id'], ['quantity' => $product['new_quantity']]);
            error_log("Deducted {$product['quantity']} units from product #{$product['id']} ({$product['name']}). New quantity: {$product['new_quantity']}");
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
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to complete order',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
