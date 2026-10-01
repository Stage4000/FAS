<?php
/**
 * Shipping API Endpoint
 * Get shipping rates with first-party free-shipping rules.
 */

require_once __DIR__ . '/../src/shipping/ShippingRateService.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/models/Warehouse.php';
require_once __DIR__ . '/../src/utils/ShippingRules.php';
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';
require_once __DIR__ . '/../src/payments/ApplePayContext.php';

use FAS\Config\Database;
use FAS\Shipping\ShippingRateService;
use FAS\Models\Product;
use FAS\Models\Warehouse;
use FAS\Utils\ShippingRules;
use FAS\Utils\ErrorMonitor;

require_once __DIR__ . '/../includes/security.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    fas_security_guard('shipping', false, false);
    fas_security_body(65536);
}

header('Content-Type: application/json');
header('Cache-Control: private, no-store');

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
        'has_complete_shipping_data' => !empty($product['weight']) && !empty($product['length'])
            && !empty($product['width']) && !empty($product['height']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fasShippingRatesError(405, 'Method not allowed');
}

$input = json_decode(fas_security_body(65536), true);

if (!is_array($input)) {
    fasShippingRatesError(400, 'Invalid JSON data');
}

if (!isset($input['items']) || !isset($input['address'])) {
    fasShippingRatesError(400, 'Missing required fields: items and address');
}

if (!is_array($input['items']) || empty($input['items']) || count($input['items'])>100 || !is_array($input['address'])) {
    fasShippingRatesError(400, 'Enter a valid address and between 1 and 100 cart items');
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
    $destinationIsContinentalUs = ShippingRules::isContinentalUsAddress($input['address']);

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
        if (!is_array($item) || filter_var($item['quantity'] ?? 1, FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>999]])===false) {
            fasShippingRatesError(400, 'Invalid shipping quantity');
        }
        $productId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        if ($productId <= 0) {
            fasShippingRatesError(400, 'Invalid product ID in cart item');
        }

        $product = $productModel->getById($productId);
        if (!$product || empty($product['is_active']) || empty($product['show_on_website'])) {
            fasShippingRatesError(400, 'Product not found for shipping: ' . $productId);
        }

        $catalogItem = fasShippingCatalogItem($item, $product);
        $reason = $destinationIsContinentalUs
            ? ShippingRules::getFreeShippingReason($product, $settings, $input['address'])
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
    }

    $freeShippingSummary = [
        'enabled' => !empty($settings['enabled']),
        'destination_eligible' => $destinationIsContinentalUs,
        'item_count' => $freeQuantity,
        'line_count' => count($freeItems),
        'rated_item_count' => $ratedQuantity,
        'free_subtotal' => round($freeSubtotal, 2),
        'reasons' => $reasonCounts,
    ];

    if (empty($ratedItems)) {
        $freeRate = ShippingRules::freeShippingRate($freeShippingSummary);
        $quoteId = \FAS\Payments\ApplePayContext::rememberShipping($input, [$freeRate]);
        if ($quoteId === null) fasShippingRatesError(503, 'Shipping checkout is temporarily unavailable. Please try again.');
        echo json_encode([
            'success' => true,
            'free_shipping' => $freeShippingSummary,
            'shipping_quote' => $quoteId,
            'applepay_shipping_quote' => $quoteId,
            'rates' => [$freeRate],
        ]);
        exit;
    }

    $warehouseModel = new Warehouse($db);
    $warehouse = $warehouseModel->getForCartItems($ratedItems);
    $shipping = ShippingRateService::forDatabase($db);
    $rates = $shipping->getShippingRates($ratedItems, $input['address'], $warehouse ?: null);

    if ($rates === null) {
        $monitor = new ErrorMonitor($db);
        $monitor->record(ErrorMonitor::AREA_SHIPPING, 'Shipping providers could not return rates.', [
            'source' => 'api/shipping-rates.php',
            'severity' => 'error',
            'metadata' => [
                'items_count' => count($ratedItems),
                'providers' => $shipping->diagnostics(),
                'warehouse_id' => $warehouse['id'] ?? null,
            ],
        ]);
        fasShippingRatesError(500, 'Failed to fetch shipping rates. Please check your address and try again.');
    }

    if (empty($rates)) {
        fasShippingRatesError(200, 'No shipping rates available for this destination. Please contact support.');
    }

    $quoteId = \FAS\Payments\ApplePayContext::rememberShipping($input, $rates, $shipping->shipmentSnapshot());
    if ($quoteId === null) fasShippingRatesError(503, 'Shipping checkout is temporarily unavailable. Please try again.');
    echo json_encode([
        'success' => true,
        'free_shipping' => $freeShippingSummary,
        'shipping_quote' => $quoteId,
        'applepay_shipping_quote' => $quoteId,
        'rates' => $rates,
    ]);
} catch (Throwable $e) {
    error_log('Shipping rates API error: ' . $e->getMessage());
    try {
        if (!isset($db)) {
            $db = Database::getInstance()->getConnection();
        }
        $monitor = new ErrorMonitor($db);
        $monitor->recordThrowable(ErrorMonitor::AREA_SHIPPING, $e, [
            'source' => 'api/shipping-rates.php',
            'severity' => 'error',
            'metadata' => [
                'items_count' => count($ratedItems ?? []),
            ],
        ]);
    } catch (Throwable $monitorError) {
        error_log('Shipping rates error monitor write failed: ' . $monitorError->getMessage());
    }
    fasShippingRatesError(500, 'An error occurred while calculating shipping rates. Please try again.');
}
