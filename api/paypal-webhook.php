<?php
/**
 * PayPal Webhook/IPN Handler
 * Processes PayPal payment notifications and updates orders
 */

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Order.php';
require_once __DIR__ . '/../src/models/Product.php';

use FAS\Config\Database;
use FAS\Models\Order;
use FAS\Models\Product;

// Get webhook data
$rawInput = file_get_contents('php://input');
$webhookData = json_decode($rawInput, true);

// Log webhook for debugging
error_log('PayPal Webhook received: ' . $rawInput);

// Verify it's a valid PayPal webhook (in production, verify signature)
if (!$webhookData || !isset($webhookData['event_type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook data']);
    exit;
}

$eventType = $webhookData['event_type'];

// Handle different event types
switch ($eventType) {
    case 'CHECKOUT.ORDER.APPROVED':
    case 'PAYMENT.CAPTURE.COMPLETED':
        handlePaymentCompleted($webhookData);
        break;
    
    case 'PAYMENT.CAPTURE.DENIED':
    case 'PAYMENT.CAPTURE.REFUNDED':
        handlePaymentFailed($webhookData);
        break;
    
    default:
        error_log('Unhandled PayPal webhook event: ' . $eventType);
}

http_response_code(200);
echo json_encode(['success' => true]);

/**
 * Handle completed payment
 */
function handlePaymentCompleted($webhookData)
{
    $db = Database::getInstance()->getConnection();
    $orderModel = new Order($db);
    $productModel = new Product($db);
    
    $resource = $webhookData['resource'];
    $paypalOrderId = $resource['id'] ?? ($resource['supplementary_data']['related_ids']['order_id'] ?? null);
    
    if (!$paypalOrderId) {
        error_log('PayPal webhook: No order ID found in webhook data');
        return;
    }
    
    // Check if order already exists
    $existingOrder = $orderModel->getByPayPalOrderId($paypalOrderId);
    
    if ($existingOrder) {
        // Update existing order status
        $orderModel->update($existingOrder['id'], [
            'payment_status' => 'completed',
            'order_status' => 'processing',
            'paypal_transaction_id' => $resource['id'] ?? null
        ]);
        
        error_log('PayPal webhook: Updated existing order #' . $existingOrder['order_number']);
    } else {
        error_log('PayPal webhook: Order not found for PayPal order ID: ' . $paypalOrderId);
    }
}

/**
 * Handle failed/refunded payment
 */
function handlePaymentFailed($webhookData)
{
    $db = Database::getInstance()->getConnection();
    $orderModel = new Order($db);
    $productModel = new Product($db);
    
    $resource = $webhookData['resource'];
    $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
    
    if (!$paypalOrderId) {
        return;
    }
    
    $existingOrder = $orderModel->getByPayPalOrderId($paypalOrderId);
    
    if ($existingOrder) {
        // Update order status
        $status = $webhookData['event_type'] === 'PAYMENT.CAPTURE.REFUNDED' ? 'refunded' : 'failed';
        $orderModel->update($existingOrder['id'], [
            'payment_status' => $status,
            'order_status' => 'cancelled'
        ]);
        
        // Restore product quantities
        $items = $orderModel->getItems($existingOrder['id']);
        foreach ($items as $item) {
            $product = $productModel->getById($item['product_id'], true);
            if ($product) {
                $newQuantity = $product['quantity'] + $item['quantity'];
                $productModel->update($item['product_id'], ['quantity' => $newQuantity]);
            }
        }
        
        error_log('PayPal webhook: Order ' . $existingOrder['order_number'] . ' marked as ' . $status);
    }
}
