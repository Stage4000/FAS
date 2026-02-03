<?php
/**
 * Product Validation API
 * Checks if a product exists and is active
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';

use FAS\Config\Database;
use FAS\Models\Product;

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get product ID from query string
$productId = $_GET['id'] ?? null;

if (!$productId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing product ID']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $productModel = new Product($db);
    
    // Check if product exists (including inactive products)
    $product = $productModel->getById($productId, true);
    
    if (!$product) {
        echo json_encode([
            'exists' => false,
            'active' => false
        ]);
        exit;
    }
    
    echo json_encode([
        'exists' => true,
        'active' => (bool)$product['is_active']
    ]);
    
} catch (Exception $e) {
    error_log('Product check API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
