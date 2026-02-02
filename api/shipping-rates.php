<?php
/**
 * Shipping API Endpoint
 * Get shipping rates from EasyShip with production-ready error handling
 */

require_once __DIR__ . '/../src/integrations/EasyShipAPI.php';

use FAS\Integrations\EasyShipAPI;

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

if (!isset($input['items']) || !isset($input['address'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: items and address']);
    exit;
}

// Validate items array
if (!is_array($input['items']) || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Items must be a non-empty array']);
    exit;
}

// Validate address fields
$requiredAddressFields = ['address1', 'city', 'state', 'zip', 'country'];
foreach ($requiredAddressFields as $field) {
    if (empty($input['address'][$field])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required address field: ' . $field]);
        exit;
    }
}

try {
    $easyship = new EasyShipAPI();
    
    $rates = $easyship->getShippingRates($input['items'], $input['address']);
    
    if ($rates === null) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch shipping rates. Please check your address and try again.'
        ]);
        exit;
    }
    
    if (empty($rates)) {
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'error' => 'No shipping rates available for this destination. Please contact support.'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'rates' => $rates
    ]);
    
} catch (Exception $e) {
    error_log('Shipping rates API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while calculating shipping rates. Please try again.'
    ]);
}
