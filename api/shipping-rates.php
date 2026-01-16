<?php
/**
 * Shipping API Endpoint
 * Get shipping rates from EasyShip
 */

require_once __DIR__ . '/../src/integrations/EasyShipAPI.php';

use FAS\Integrations\EasyShipAPI;

header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['items']) || !isset($input['address'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: items and address']);
    exit;
}

try {
    $easyship = new EasyShipAPI();
    
    $rates = $easyship->getShippingRates($input['items'], $input['address']);
    
    if ($rates === null) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch shipping rates']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'rates' => $rates
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
