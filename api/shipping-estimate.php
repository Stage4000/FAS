<?php
/**
 * Pre-checkout shipping estimator.
 *
 * Estimates shipping from city/state/ZIP and reports first-party free-shipping
 * eligibility before the shopper reaches checkout.
 */

require_once __DIR__ . '/../src/integrations/EasyShipAPI.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/models/Warehouse.php';
require_once __DIR__ . '/../src/utils/ShippingRules.php';
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';

use FAS\Config\Database;
use FAS\Integrations\EasyShipAPI;
use FAS\Models\Product;
use FAS\Models\Warehouse;
use FAS\Utils\ShippingRules;
use FAS\Utils\ErrorMonitor;

header('Content-Type: application/json');

function fasShippingEstimateError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $message,
    ]);
    exit;
}

function fasShippingEstimatePrice(array $product): float
{
    $price = (float)($product['price'] ?? 0);
    $salePrice = !empty($product['sale_price']) ? (float)$product['sale_price'] : null;

    if ($salePrice !== null && $salePrice > 0 && $salePrice < $price) {
        return $salePrice;
    }

    return $price;
}

function fasShippingEstimateCatalogItem(array $item, array $product): array
{
    $shippingFieldsComplete = !empty($product['weight'])
        && !empty($product['length'])
        && !empty($product['width'])
        && !empty($product['height']);

    return [
        'id' => (int)$product['id'],
        'product_id' => (int)$product['id'],
        'name' => (string)$product['name'],
        'sku' => (string)($product['sku'] ?? ''),
        'price' => fasShippingEstimatePrice($product),
        'quantity' => max(1, (int)($item['quantity'] ?? 1)),
        'weight' => !empty($product['weight']) ? (float)$product['weight'] : 1.0,
        'length' => !empty($product['length']) ? (float)$product['length'] : 10.0,
        'width' => !empty($product['width']) ? (float)$product['width'] : 10.0,
        'height' => !empty($product['height']) ? (float)$product['height'] : 10.0,
        'has_complete_shipping_data' => $shippingFieldsComplete,
    ];
}

function fasShippingEstimateAddress(array $address): array
{
    return [
        'address1' => trim((string)($address['address1'] ?? 'Shipping estimate')),
        'address2' => '',
        'city' => trim((string)($address['city'] ?? 'Estimate')),
        'state' => strtoupper(trim((string)($address['state'] ?? ''))),
        'zip' => trim((string)($address['zip'] ?? '')),
        'country' => strtoupper(trim((string)($address['country'] ?? 'US'))),
    ];
}

function fasShippingEstimateLowestRate(array $rates): ?array
{
    if (empty($rates)) {
        return null;
    }

    usort($rates, function ($a, $b) {
        return (float)($a['total_charge'] ?? 0) <=> (float)($b['total_charge'] ?? 0);
    });

    return $rates[0];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fasShippingEstimateError(405, 'Method not allowed');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    fasShippingEstimateError(400, 'Invalid JSON data');
}

if (empty($input['items']) || !is_array($input['items'])) {
    fasShippingEstimateError(400, 'Add at least one item before estimating shipping');
}

$address = fasShippingEstimateAddress(is_array($input['address'] ?? null) ? $input['address'] : []);
if ($address['city'] === '' || $address['zip'] === '' || $address['state'] === '') {
    fasShippingEstimateError(400, 'Enter destination city, state, and ZIP code');
}

try {
    $db = Database::getInstance()->getConnection();
    $productModel = new Product($db);
    $settings = ShippingRules::getFreeShippingSettings();
    $destinationIsContinentalUs = ShippingRules::isContinentalUsAddress($address);

    $freeItems = [];
    $ratedItems = [];
    $freeQuantity = 0;
    $ratedQuantity = 0;
    $freeSubtotal = 0.0;
    $ratedSubtotal = 0.0;
    $missingShippingData = [];
    $reasonCounts = [
        'manual' => 0,
        'size_weight' => 0,
    ];

    foreach ($input['items'] as $item) {
        $productId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        if ($productId <= 0) {
            fasShippingEstimateError(400, 'Invalid product ID in estimate request');
        }

        $product = $productModel->getById($productId);
        if (!$product || empty($product['show_on_website'])) {
            fasShippingEstimateError(400, 'Product is unavailable for shipping estimate: ' . $productId);
        }

        $catalogItem = fasShippingEstimateCatalogItem($item, $product);
        if (empty($catalogItem['has_complete_shipping_data'])) {
            $missingShippingData[] = [
                'product_id' => $catalogItem['product_id'],
                'name' => $catalogItem['name'],
            ];
        }

        $reason = $destinationIsContinentalUs
            ? ShippingRules::getFreeShippingReason($product, $settings, $address)
            : '';

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
        $ratedSubtotal += $catalogItem['price'] * $catalogItem['quantity'];
    }

    $freeShippingSummary = [
        'enabled' => !empty($settings['enabled']),
        'destination_eligible' => $destinationIsContinentalUs,
        'free_items_count' => $freeQuantity,
        'rated_items_count' => $ratedQuantity,
        'free_subtotal' => round($freeSubtotal, 2),
        'rated_subtotal' => round($ratedSubtotal, 2),
        'reason_counts' => $reasonCounts,
        'missing_shipping_data' => $missingShippingData,
    ];

    if (empty($ratedItems)) {
        $freeRate = [
            'courier_id' => 'free_shipping',
            'courier_name' => 'Flip and Strip',
            'service_name' => 'Free Shipping',
            'total_charge' => 0,
            'currency' => 'USD',
            'delivery_time_text' => 'Eligible continental US address',
        ];

        echo json_encode([
            'success' => true,
            'estimate' => true,
            'address' => [
                'city' => $address['city'],
                'state' => $address['state'],
                'zip' => $address['zip'],
                'country' => $address['country'],
            ],
            'free_shipping' => $freeShippingSummary,
            'rates' => [$freeRate],
            'lowest_rate' => $freeRate,
            'message' => $destinationIsContinentalUs
                ? 'All selected items qualify for free shipping to this continental US destination.'
                : 'Free shipping is limited to continental US addresses.',
        ]);
        exit;
    }

    $warehouseModel = new Warehouse($db);
    $warehouse = $warehouseModel->getForCartItems($ratedItems);
    $easyship = new EasyShipAPI();
$rates = $easyship->getShippingRates($ratedItems, $address, $warehouse);

if ($rates === null || empty($rates)) {
    $monitor = new ErrorMonitor($db);
    $monitor->record(ErrorMonitor::AREA_SHIPPING, 'Shipping estimate unavailable for destination.', [
        'source' => 'api/shipping-estimate.php',
        'severity' => 'warning',
        'metadata' => [
            'items_count' => count($ratedItems),
            'destination_state' => $address['state'] ?? null,
            'destination_zip' => $address['zip'] ?? null,
            'warehouse_id' => $warehouse['id'] ?? null,
            'rates_null' => $rates === null,
        ],
    ]);
    echo json_encode([
        'success' => false,
            'estimate' => true,
            'address' => [
                'city' => $address['city'],
                'state' => $address['state'],
                'zip' => $address['zip'],
                'country' => $address['country'],
            ],
            'free_shipping' => $freeShippingSummary,
            'rates' => [],
            'error' => 'Live shipping estimate is unavailable for this destination. Final shipping can still be calculated at checkout.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'estimate' => true,
        'address' => [
            'city' => $address['city'],
            'state' => $address['state'],
            'zip' => $address['zip'],
            'country' => $address['country'],
        ],
        'free_shipping' => $freeShippingSummary,
        'rates' => $rates,
        'lowest_rate' => fasShippingEstimateLowestRate($rates),
        'message' => $freeQuantity > 0
            ? 'Some selected items qualify for free shipping; rates shown apply to the remaining items.'
            : 'Estimated shipping rate returned.',
    ]);
} catch (Throwable $e) {
    error_log('Shipping estimate API error: ' . $e->getMessage());
    try {
        if (!isset($db)) {
            $db = Database::getInstance()->getConnection();
        }
        $monitor = new ErrorMonitor($db);
        $monitor->recordThrowable(ErrorMonitor::AREA_SHIPPING, $e, [
            'source' => 'api/shipping-estimate.php',
            'severity' => 'error',
            'metadata' => [
                'address' => $address ?? null,
                'input_preview' => isset($input) ? array_intersect_key((array)$input, array_flip(['destination', 'items', 'product_id'])) : null,
            ],
        ]);
    } catch (Throwable $monitorError) {
        error_log('Shipping estimate error monitor write failed: ' . $monitorError->getMessage());
    }
    fasShippingEstimateError(500, 'Shipping estimate is unavailable right now. Final shipping can still be calculated at checkout.');
}
