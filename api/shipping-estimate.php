<?php
/**
 * Pre-checkout shipping estimator.
 *
 * Estimates shipping from city/state/ZIP and reports first-party free-shipping
 * eligibility before the shopper reaches checkout.
 */

require_once __DIR__ . '/../src/shipping/ShippingRateService.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/models/Warehouse.php';
require_once __DIR__ . '/../src/utils/ShippingRules.php';
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';
require_once __DIR__ . '/../src/shipping/ShippingOrder.php';

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

function fasShippingEstimatePublicRates(array $rates): array
{
    foreach ($rates as &$rate) unset($rate['carrier_quote_cents'],$rate['parcel_services']);
    unset($rate);
    return $rates;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fasShippingEstimateError(405, 'Method not allowed');
}

$input = json_decode(fas_security_body(65536), true);
if (!is_array($input)) {
    fasShippingEstimateError(400, 'Invalid JSON data');
}

if (empty($input['items']) || !is_array($input['items']) || count($input['items'])>100) {
    fasShippingEstimateError(400, 'Add between 1 and 100 items before estimating shipping');
}

$address = fasShippingEstimateAddress(is_array($input['address'] ?? null) ? $input['address'] : []);
if ($address['city'] === '' || $address['zip'] === '' || $address['state'] === '') {
    fasShippingEstimateError(400, 'Enter destination city, state, and ZIP code');
}

try {
    $db = Database::getInstance()->getConnection();
    $effectiveConfig=\FAS\Shipping\ShippingConfig::load();
    if ($effectiveConfig['mode']!=='easyship' && !\FAS\Shipping\ShippingOrder::directReady($db)) {
        if ($effectiveConfig['mode']==='direct') {
            fasShippingEstimateError(503,'Shipping is temporarily unavailable. Please try again.');
        }
        $effectiveConfig['mode']='easyship';
    }
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
        if (!is_array($item) || filter_var($item['quantity'] ?? 1, FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>999]])===false) {
            fasShippingEstimateError(400, 'Invalid shipping quantity');
        }
        $productId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        if ($productId <= 0) {
            fasShippingEstimateError(400, 'Invalid product ID in estimate request');
        }

        $product = $productModel->getById($productId);
        if (!$product || empty($product['is_active']) || empty($product['show_on_website'])) {
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
        $directConfig=$effectiveConfig;
        $selectedMode=$directConfig['mode'];
        if ($selectedMode!=='easyship') {
            $directConfig['mode']='direct';
            $warehouseModel=new Warehouse($db);
            $warehouse=$warehouseModel->getForCartItems($freeItems);
            $shipping=ShippingRateService::forDatabase($db,$directConfig);
            $carrierRates=$shipping->getShippingRates($freeItems,$address+['_estimate'=>true],$warehouse ?: null);
            if (!$carrierRates && $selectedMode==='direct') {
                fasShippingEstimateError(503,'Shipping is temporarily unavailable for this destination.');
            }
        }
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
    $shippingConfig=$effectiveConfig;
    $deadline=$freeItems ? microtime(true)+$shippingConfig['request_budget_seconds'] : null;
    $shipping = ShippingRateService::forDatabase($db,$shippingConfig,$deadline);
$rates = $shipping->getShippingRates($ratedItems, $address + ['_estimate'=>true], $warehouse ?: null);
if ($freeItems && $rates && in_array($rates[0]['provider'] ?? '',['usps','ups'],true)) {
    $directConfig=$shippingConfig;
    $fallbackMode=$directConfig['mode'];
    $directConfig['mode']='direct';
    $allItems=array_merge($ratedItems,$freeItems);
    $allWarehouse=$warehouseModel->getForCartItems($allItems);
    $fullShipping=ShippingRateService::forDatabase($db,$directConfig,$deadline);
    $fullRates=$fullShipping->getShippingRates($allItems,$address+['_estimate'=>true],$allWarehouse ?: null,10);
    $fullIds=[];
    foreach ($fullRates ?? [] as $fullRate) $fullIds[$fullRate['courier_id']]=true;
    $rates=array_values(array_filter($rates,static fn($rate)=>isset($fullIds[$rate['courier_id']])));
    if (!$rates && $fallbackMode==='direct_with_fallback') {
        $directConfig['mode']='easyship';
        $legacy=ShippingRateService::forDatabase($db,$directConfig);
        $rates=$legacy->getShippingRates($ratedItems,$address+['_estimate'=>true],$warehouse ?: null);
    }
}

if ($rates === null || empty($rates)) {
    $monitor = new ErrorMonitor($db);
    $monitor->record(ErrorMonitor::AREA_SHIPPING, 'Shipping estimate unavailable for destination.', [
        'source' => 'api/shipping-estimate.php',
        'severity' => 'warning',
        'metadata' => [
            'items_count' => count($ratedItems),
            'providers' => $shipping->diagnostics(),
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

    $publicRates=fasShippingEstimatePublicRates($rates);
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
        'rates' => $publicRates,
        'lowest_rate' => fasShippingEstimateLowestRate($publicRates),
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
                'items_count' => count($ratedItems ?? []),
            ],
        ]);
    } catch (Throwable $monitorError) {
        error_log('Shipping estimate error monitor write failed: ' . $monitorError->getMessage());
    }
    fasShippingEstimateError(500, 'Shipping estimate is unavailable right now. Final shipping can still be calculated at checkout.');
}
