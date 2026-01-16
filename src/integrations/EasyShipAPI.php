<?php
/**
 * EasyShip API Integration
 * Handles shipping rate calculation and label generation
 */

namespace FAS\Integrations;

class EasyShipAPI
{
    private $apiKey;
    private $platformName;
    private $apiUrl = 'https://api.easyship.com/2023-01';
    
    public function __construct($config = null)
    {
        if ($config === null) {
            $configFile = __DIR__ . '/../config/config.php';
            if (!file_exists($configFile)) {
                $configFile = __DIR__ . '/../config/config.example.php';
            }
            $config = require $configFile;
        }
        
        $easyshipConfig = $config['easyship'];
        $this->apiKey = $easyshipConfig['api_key'];
        $this->platformName = $easyshipConfig['platform_name'];
    }
    
    /**
     * Get shipping rates for cart items
     */
    public function getShippingRates($items, $destinationAddress)
    {
        $shipmentData = [
            'destination_address' => [
                'line_1' => $destinationAddress['address1'],
                'line_2' => $destinationAddress['address2'] ?? '',
                'city' => $destinationAddress['city'],
                'state' => $destinationAddress['state'],
                'postal_code' => $destinationAddress['zip'],
                'country_alpha2' => $this->getCountryCode($destinationAddress['country'])
            ],
            'origin_address' => [
                'country_alpha2' => 'US',
                'postal_code' => '33510' // Florida ZIP - should be in config
            ],
            'incoterms' => 'DDU',
            'insurance' => [
                'is_insured' => false
            ],
            'courier_selection' => [
                'apply_shipping_rules' => true
            ],
            'parcels' => $this->buildParcels($items)
        ];
        
        $response = $this->makeRequest('POST', '/rates', $shipmentData);
        
        if ($response && isset($response['rates'])) {
            return $this->parseRates($response['rates']);
        }
        
        return null;
    }
    
    /**
     * Create shipment and get label
     */
    public function createShipment($orderId, $items, $shippingAddress, $courierID)
    {
        $shipmentData = [
            'platform_name' => $this->platformName,
            'platform_order_number' => $orderId,
            'destination_address' => [
                'line_1' => $shippingAddress['address1'],
                'line_2' => $shippingAddress['address2'] ?? '',
                'city' => $shippingAddress['city'],
                'state' => $shippingAddress['state'],
                'postal_code' => $shippingAddress['zip'],
                'country_alpha2' => $this->getCountryCode($shippingAddress['country']),
                'contact_name' => $shippingAddress['name'],
                'contact_phone' => $shippingAddress['phone'] ?? '',
                'contact_email' => $shippingAddress['email']
            ],
            'parcels' => $this->buildParcels($items),
            'selected_courier_id' => $courierID
        ];
        
        $response = $this->makeRequest('POST', '/shipments', $shipmentData);
        
        if ($response && isset($response['shipment'])) {
            return [
                'success' => true,
                'shipment_id' => $response['shipment']['easyship_shipment_id'],
                'tracking_number' => $response['shipment']['tracking_number'] ?? null,
                'label_url' => $response['shipment']['label_url'] ?? null
            ];
        }
        
        return ['success' => false, 'error' => $response['message'] ?? 'Failed to create shipment'];
    }
    
    /**
     * Build parcels array from cart items
     */
    private function buildParcels($items)
    {
        $parcels = [];
        
        foreach ($items as $item) {
            $parcels[] = [
                'total_actual_weight' => $item['weight'] ?? 1.0,
                'items' => [
                    [
                        'description' => $item['name'],
                        'sku' => $item['sku'] ?? '',
                        'actual_weight' => $item['weight'] ?? 1.0,
                        'declared_currency' => 'USD',
                        'declared_customs_value' => $item['price'],
                        'quantity' => $item['quantity']
                    ]
                ]
            ];
        }
        
        return $parcels;
    }
    
    /**
     * Parse rates response
     */
    private function parseRates($rates)
    {
        $parsed = [];
        
        foreach ($rates as $rate) {
            if (!isset($rate['available_handover_options'])) {
                continue;
            }
            
            foreach ($rate['available_handover_options'] as $option) {
                $parsed[] = [
                    'courier_id' => $rate['courier_id'],
                    'courier_name' => $rate['courier_name'],
                    'service_name' => $option['service_level_name'] ?? 'Standard',
                    'total_charge' => $option['total_charge'],
                    'currency' => $option['currency'],
                    'min_delivery_time' => $option['min_delivery_time'] ?? null,
                    'max_delivery_time' => $option['max_delivery_time'] ?? null,
                    'delivery_time_text' => $this->formatDeliveryTime($option)
                ];
            }
        }
        
        // Sort by price
        usort($parsed, function($a, $b) {
            return $a['total_charge'] <=> $b['total_charge'];
        });
        
        return $parsed;
    }
    
    /**
     * Format delivery time for display
     */
    private function formatDeliveryTime($option)
    {
        $min = $option['min_delivery_time'] ?? null;
        $max = $option['max_delivery_time'] ?? null;
        
        if ($min && $max) {
            return "{$min}-{$max} business days";
        } elseif ($min) {
            return "{$min}+ business days";
        }
        
        return "Standard delivery";
    }
    
    /**
     * Get country code from country name
     */
    private function getCountryCode($country)
    {
        $codes = [
            'US' => 'US',
            'United States' => 'US',
            'CA' => 'CA',
            'Canada' => 'CA',
            'MX' => 'MX',
            'Mexico' => 'MX'
        ];
        
        return $codes[$country] ?? $country;
    }
    
    /**
     * Make HTTP request to EasyShip API
     */
    private function makeRequest($method, $endpoint, $data = null)
    {
        $url = $this->apiUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            error_log('EasyShip API Error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }
        
        error_log('EasyShip API HTTP Error: ' . $httpCode . ' - ' . $response);
        return json_decode($response, true);
    }
}
