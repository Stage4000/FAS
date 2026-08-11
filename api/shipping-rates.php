<?php
/**
 * Shipping API Endpoint
 * Get shipping rates from EasyShip with first-party free-shipping rules.
 */

require_once __DIR__ . '/../src/integrations/EasyShipAPI.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/models/Warehouse.php';
require_once __DIR__ . '/../src/utils/ShippingRules.php';

use FAS\Config\Database;
use FAS\Integrations\EasyShipAPI;
use FAS\Models\Product;
use FAS\Models\Warehouse;
use FAS\Utils\ShippingRules;

header('Content-Type: application/json');

function fasShippingRatesError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $message,
    ]);
    exit;
}

function fasShippingProductPrice(array $product): float
{
    $price = (float)($product['price'] ?? 0);
    $salePrice = !empty($product['sale_price']) ? (float)$product['sale_price'] : null;

    if ($salePrice !== null && $salePrice > 0 && $salePrice < $price) {
        return $salePrice;
    }

    return $price;
}

function fasShippingCatalogItem(array $item, array $product): array
{
    return [
        'id' => (int)$product['id'],
        'product_id' => (int)$product['id'],
        'name' => (string)$product['name'],
        'sku' => (string)($product['sku'] ?? ''),
        'price' => fasShippingProductPrice($product),
        'quantity' => max(1, (int)($item['quantity'] ?? 1)),
        'weight' => !empty($product['weight']) ? (float)$product['weight'] : 1.0,
        'length' => !empty($product['length']) ? (float)$product['length'] : 10.0,
        'width' => !empty($product['width']) ? (float)$product['width'] : 10.0,
        'height' => !empty($product['height']) ? (float)$product['height'] : 10.0,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fasShippingRatesError(405, 'Method not allowed');
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    fasShippingRatesError(400, 'Invalid JSON data');
}

if (!isset($input['items']) || !isset($input['address'])) {
    fasShippingRatesError(400, 'Missing required fields: items and address');
}

if (!is_array($input['items']) || empty($input['items'])) {
    fasShippingRatesError(400, 'Items must be a non-empty array');
}

$requiredAddressFields = ['address1', 'city', 'state', 'zip', 'country'];
foreach ($requiredAddressFields as $field) {
    if (empty($input['address'][$field])) {
        fasShippingRatesError(400, 'Missing required address field: ' . $field);
    }
}

try {
    $db = Database::getInstance()->getConnection();
    $productModel = new Product($db);
    $settings = ShippingRules::getFreeShippingSettings();

    $freeItems = [];
    $ratedItems = [];
    $freeQuantity = 0;
    $ratedQuantity = 0;
    $freeSubtotal = 0.0;
    $reasonCounts = [
        'manual' => 0,
        'size_weight' => 0,
    ];

    foreach ($input['items'] as $item) {
        $productId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        if ($productId <= 0) {
            fasShippingRatesError(400, 'Invalid product ID in cart item');
        }

        $product = $productModel->getById($productId);
        if (!$product) {
            fasShippingRatesError(400, 'Product not found for shipping: ' . $productId);
        }

        $catalogItem = fasShippingCatalogItem($item, $product);
        $reason = ShippingRules::getFreeShippingReason($product, $settings);

        if ($reason !== '') {
            $freeItems[] = $catalogItem + ['free_shipping_reason' => $reason];
            $freeQuantity += $catalogItem['quantity'];
            $freeSubtotal += $catalogItem['price'] * $catalogItem['quantity'];
            if (isset($reasonCounts[$reason])) {
                $reasonCounts[$reason]++;
            }
            continue;
        }

        $ratedItems[] = $catalogItem;
        $ratedQuantity += $catalogItem['quantity'];
    }

    $freeShippingSummary = [
        'enabled' => !empty($settings['enabled']),
        'item_count' => $freeQuantity,
        'line_count' => count($freeItems),
        'rated_item_count' => $ratedQuantity,
        'free_subtotal' => round($freeSubtotal, 2),
        'reasons' => $reasonCounts,
    ];

    if (empty($ratedItems)) {
        echo json_encode([
            'success' => true,
            'free_shipping' => $freeShippingSummary,
            'rates' => [
                ShippingRules::freeShippingRate($freeShippingSummary),
            ],
        ]);
        exit;
    }

    $warehouseModel = new Warehouse($db);
    $warehouse = $warehouseModel->getForCartItems($ratedItems);
    $easyship = new EasyShipAPI();
    $rates = $easyship->getShippingRates($ratedItems, $input['address'], $warehouse);

    if ($rates === null) {
        fasShippingRatesError(500, 'Failed to fetch shipping rates. Please check your address and try again.');
    }

    if (empty($rates)) {
        fasShippingRatesError(200, 'No shipping rates available for this destination. Please contact support.');
    }

    echo json_encode([
        'success' => true,
        'free_shipping' => $freeShippingSummary,
        'rates' => $rates,
    ]);
} catch (Throwable $e) {
    error_log('Shipping rates API error: ' . $e->getMessage());
    fasShippingRatesError(500, 'An error occurred while calculating shipping rates. Please try again.');
}
