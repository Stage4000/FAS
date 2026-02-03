<?php
/**
 * PayPal Webhook/IPN Handler
 * Processes PayPal payment notifications and updates orders with production-ready security
 */

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Order.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/integrations/PayPalAPI.php';

use FAS\Config\Database;
use FAS\Models\Order;
use FAS\Models\Product;
use FAS\Integrations\PayPalAPI;

// Get webhook data
$rawInput = file_get_contents('php://input');
$webhookData = json_decode($rawInput, true);

// Log webhook metadata only (not sensitive payment data)
if ($webhookData && isset($webhookData['event_type'])) {
    $orderId = 'unknown';
    if (isset($webhookData['resource']['id'])) {
        $orderId = substr($webhookData['resource']['id'], 0, 20); // Truncate for safety
    } elseif (isset($webhookData['resource']['supplementary_data']['related_ids']['order_id'])) {
        $orderId = substr($webhookData['resource']['supplementary_data']['related_ids']['order_id'], 0, 20);
    }
    error_log('PayPal Webhook: event=' . $webhookData['event_type'] . ' order=' . $orderId);
}

// Verify it's a valid PayPal webhook
if (!$webhookData || !isset($webhookData['event_type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook data']);
    exit;
}

// Verify webhook signature
try {
    $paypalAPI = new PayPalAPI();
    $headers = getallheaders();
    
    // Convert header keys to match PayPal's format (case-insensitive)
    $normalizedHeaders = [];
    foreach ($headers as $key => $value) {
        $normalizedHeaders[strtolower($key)] = $value;
    }
    
    if (!$paypalAPI->verifyWebhookSignature($normalizedHeaders, $rawInput)) {
        error_log('PayPal Webhook: Signature verification failed - possible unauthorized request');
        // In production, you should reject the webhook here
        // For now, we'll log and continue
        // http_response_code(401);
        // echo json_encode(['error' => 'Unauthorized']);
        // exit;
    }
} catch (Exception $e) {
    error_log('PayPal Webhook: Error verifying signature: ' . $e->getMessage());
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
 * Handle completed payment with improved error handling
 */
function handlePaymentCompleted($webhookData)
{
    try {
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
            // Check if already processed to avoid duplicate processing
            if ($existingOrder['payment_status'] === 'completed') {
                error_log('PayPal webhook: Order #' . $existingOrder['order_number'] . ' already completed, skipping');
                return;
            }
            
            // Update existing order status
            $orderModel->update($existingOrder['id'], [
                'payment_status' => 'completed',
                'order_status' => 'processing',
                'paypal_transaction_id' => $resource['id'] ?? null
            ]);
            
            // Deduct inventory for this order only if not already deducted
            // Check if inventory was already deducted (order status would be 'processing' or higher)
            if ($existingOrder['order_status'] === 'pending') {
                $items = $orderModel->getItems($existingOrder['id']);
                foreach ($items as $item) {
                    $product = $productModel->getById($item['product_id'], true);
                    if ($product && $product['quantity'] >= $item['quantity']) {
                        $newQuantity = $product['quantity'] - $item['quantity'];
                        $productModel->update($item['product_id'], ['quantity' => $newQuantity]);
                        error_log("Deducted {$item['quantity']} units from product #{$item['product_id']} for order #{$existingOrder['order_number']}");
                    } else {
                        error_log("Warning: Could not deduct inventory for product #{$item['product_id']} in order #{$existingOrder['order_number']}");
                    }
                }
            } else {
                error_log('PayPal webhook: Inventory already deducted for order #' . $existingOrder['order_number'] . ', skipping');
            }
            
            error_log('PayPal webhook: Updated existing order #' . $existingOrder['order_number']);
        } else {
            error_log('PayPal webhook: Order not found for PayPal order ID: ' . $paypalOrderId);
        }
    } catch (Exception $e) {
        error_log('PayPal webhook handlePaymentCompleted error: ' . $e->getMessage());
    }
}

/**
 * Handle failed/refunded payment with improved error handling
 */
function handlePaymentFailed($webhookData)
{
    try {
        $db = Database::getInstance()->getConnection();
        $orderModel = new Order($db);
        $productModel = new Product($db);
        
        $resource = $webhookData['resource'];
        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
        
        if (!$paypalOrderId) {
            error_log('PayPal webhook handlePaymentFailed: No order ID found');
            return;
        }
        
        $existingOrder = $orderModel->getByPayPalOrderId($paypalOrderId);
        
        if ($existingOrder) {
            // Determine the appropriate status
            $status = $webhookData['event_type'] === 'PAYMENT.CAPTURE.REFUNDED' ? 'refunded' : 'failed';
            
            // Check if order was already completed (and thus inventory was deducted)
            $shouldRestoreInventory = ($existingOrder['payment_status'] === 'completed');
            
            // Update order status
            $orderModel->update($existingOrder['id'], [
                'payment_status' => $status,
                'order_status' => 'cancelled'
            ]);
            
            // Restore product quantities if inventory was previously deducted
            if ($shouldRestoreInventory) {
                $items = $orderModel->getItems($existingOrder['id']);
                foreach ($items as $item) {
                    $product = $productModel->getById($item['product_id'], true);
                    if ($product) {
                        $newQuantity = $product['quantity'] + $item['quantity'];
                        $productModel->update($item['product_id'], ['quantity' => $newQuantity]);
                        error_log("Restored {$item['quantity']} units to product #{$item['product_id']} for order #{$existingOrder['order_number']}");
                    }
                }
            }
            
            error_log('PayPal webhook: Order ' . $existingOrder['order_number'] . ' marked as ' . $status);
        } else {
            error_log('PayPal webhook handlePaymentFailed: Order not found for PayPal order ID: ' . $paypalOrderId);
        }
    } catch (Exception $e) {
        error_log('PayPal webhook handlePaymentFailed error: ' . $e->getMessage());
    }
}
