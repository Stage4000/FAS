<?php
/**
 * Coupon Validation API
 * Validates coupon codes and calculates discounts
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Coupon.php';

use FAS\Config\Database;
use FAS\Models\Coupon;

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

$code = $input['code'] ?? '';
$subtotal = $input['subtotal'] ?? 0;

if (empty($code)) {
    http_response_code(400);
    echo json_encode(['error' => 'Coupon code is required']);
    exit;
}

if ($subtotal <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subtotal']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $couponModel = new Coupon($db);
    
    $result = $couponModel->validateCoupon($code, $subtotal);
    
    if (!$result['valid']) {
        http_response_code(400);
        echo json_encode([
            'valid' => false,
            'message' => $result['message']
        ]);
        exit;
    }
    
    echo json_encode([
        'valid' => true,
        'code' => $result['coupon']['code'],
        'discount_type' => $result['coupon']['discount_type'],
        'discount_value' => $result['coupon']['discount_value'],
        'discount_amount' => $result['discount'],
        'description' => $result['coupon']['description']
    ]);
    
} catch (Exception $e) {
    error_log('Coupon validation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
