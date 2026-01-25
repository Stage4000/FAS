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
 * Create order in database (called when customer initiates checkout)
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
    
    // Validate inventory availability
    foreach ($input['items'] as $item) {
        $product = $productModel->getById($item['product_id']);
        if (!$product) {
            http_response_code(400);
            echo json_encode(['error' => 'Product not found: ' . $item['product_id']]);
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
 * Complete order and deduct inventory (called after payment is confirmed)
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
    
    // Check if already completed
    if ($order['payment_status'] === 'completed') {
        echo json_encode([
            'success' => true,
            'message' => 'Order already completed',
            'order_number' => $order['order_number']
        ]);
        exit;
    }
    
    // Update order status
    $orderModel->update($order['id'], [
        'payment_status' => 'completed',
        'order_status' => 'processing',
        'paypal_transaction_id' => $paypalTransactionId
    ]);
    
    // Deduct product quantities
    $items = $orderModel->getItems($order['id']);
    foreach ($items as $item) {
        $product = $productModel->getById($item['product_id'], true);
        if ($product && $product['quantity'] >= $item['quantity']) {
            $newQuantity = $product['quantity'] - $item['quantity'];
            $productModel->update($item['product_id'], ['quantity' => $newQuantity]);
            error_log("Deducted {$item['quantity']} units from product #{$item['product_id']}. New quantity: {$newQuantity}");
        } else {
            error_log("Warning: Could not deduct inventory for product #{$item['product_id']}");
        }
    }
    
    echo json_encode([
        'success' => true,
        'order_number' => $order['order_number'],
        'message' => 'Order completed and inventory updated'
    ]);
}
