<?php
/**
 * EasyShip API Integration
 * Handles shipping rate calculation and label generation with production-ready error handling
 */

namespace FAS\Integrations;

class EasyShipAPI
{
    private $apiKey;
    private $platformName;
    private $apiUrl = 'https://api.easyship.com/2023-01';
    private $maxRetries = 3;
    private $timeout = 30;
    
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
        
        // Validate configuration
        if (empty($this->apiKey) || strpos($this->apiKey, 'YOUR_') === 0) {
            throw new \Exception('EasyShip API key not configured');
        }
    }
    
    /**
     * Get shipping rates for cart items with validation
     */
    public function getShippingRates($items, $destinationAddress)
    {
        // Validate inputs
        if (empty($items) || !is_array($items)) {
            error_log('EasyShip: Invalid items array');
            return null;
        }
        
        $requiredFields = ['address1', 'city', 'state', 'zip', 'country'];
        foreach ($requiredFields as $field) {
            if (empty($destinationAddress[$field])) {
                error_log('EasyShip: Missing required address field: ' . $field);
                return null;
            }
        }
        
        try {
            $shipmentData = [
                'destination_address' => [
                    'line_1' => substr($destinationAddress['address1'], 0, 255),
                    'line_2' => isset($destinationAddress['address2']) ? substr($destinationAddress['address2'], 0, 255) : '',
                    'city' => substr($destinationAddress['city'], 0, 100),
                    'state' => substr($destinationAddress['state'], 0, 100),
                    'postal_code' => substr($destinationAddress['zip'], 0, 20),
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
            
            error_log('EasyShip: No rates found in response');
            return null;
            
        } catch (\Exception $e) {
            error_log('EasyShip getShippingRates error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create shipment and get label with validation
     */
    public function createShipment($orderId, $items, $shippingAddress, $courierID)
    {
        // Validate inputs
        if (empty($orderId) || empty($items) || empty($shippingAddress) || empty($courierID)) {
            return ['success' => false, 'error' => 'Missing required parameters'];
        }
        
        $requiredAddressFields = ['address1', 'city', 'state', 'zip', 'country', 'name', 'email'];
        foreach ($requiredAddressFields as $field) {
            if (empty($shippingAddress[$field])) {
                return ['success' => false, 'error' => 'Missing required address field: ' . $field];
            }
        }
        
        try {
            $shipmentData = [
                'platform_name' => $this->platformName,
                'platform_order_number' => (string)$orderId,
                'destination_address' => [
                    'line_1' => substr($shippingAddress['address1'], 0, 255),
                    'line_2' => isset($shippingAddress['address2']) ? substr($shippingAddress['address2'], 0, 255) : '',
                    'city' => substr($shippingAddress['city'], 0, 100),
                    'state' => substr($shippingAddress['state'], 0, 100),
                    'postal_code' => substr($shippingAddress['zip'], 0, 20),
                    'country_alpha2' => $this->getCountryCode($shippingAddress['country']),
                    'contact_name' => substr($shippingAddress['name'], 0, 100),
                    'contact_phone' => isset($shippingAddress['phone']) ? substr($shippingAddress['phone'], 0, 50) : '',
                    'contact_email' => substr($shippingAddress['email'], 0, 255)
                ],
                'parcels' => $this->buildParcels($items),
                'selected_courier_id' => (string)$courierID
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
            
        } catch (\Exception $e) {
            error_log('EasyShip createShipment error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Build parcels array from cart items with validation
     */
    private function buildParcels($items)
    {
        $parcels = [];
        
        // Default weight in lbs - should be configured or come from product data
        // This is a fallback value when product weight is not specified
        $defaultWeight = 1.0;
        
        foreach ($items as $item) {
            // Validate item structure
            if (!isset($item['name']) || !isset($item['price']) || !isset($item['quantity'])) {
                error_log('EasyShip: Invalid item structure, skipping item');
                continue;
            }
            
            // Use product weight if available, otherwise use default
            // Note: In production, all products should have weight specified
            $weight = isset($item['weight']) && $item['weight'] > 0 ? $item['weight'] : $defaultWeight;
            if (!isset($item['weight']) || $item['weight'] <= 0) {
                error_log("EasyShip: Using default weight ({$defaultWeight} lbs) for item: {$item['name']}");
            }
            
            $sku = isset($item['sku']) ? substr($item['sku'], 0, 100) : '';
            $description = substr($item['name'], 0, 255);
            
            $parcels[] = [
                'total_actual_weight' => (float)$weight,
                'items' => [
                    [
                        'description' => $description,
                        'sku' => $sku,
                        'actual_weight' => (float)$weight,
                        'declared_currency' => 'USD',
                        'declared_customs_value' => (float)$item['price'],
                        'quantity' => (int)$item['quantity']
                    ]
                ]
            ];
        }
        
        if (empty($parcels)) {
            throw new \Exception('No valid items to ship');
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
     * Make HTTP request to EasyShip API with retry logic and improved error handling
     */
    private function makeRequest($method, $endpoint, $data = null)
    {
        $url = $this->apiUrl . $endpoint;
        $retries = 0;
        $lastError = null;
        
        while ($retries < $this->maxRetries) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json'
                ]);
                
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    if ($data !== null) {
                        $jsonData = json_encode($data);
                        if ($jsonData === false) {
                            throw new \Exception('Failed to encode request data as JSON');
                        }
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                    }
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    throw new \Exception('cURL error: ' . $curlError);
                }
                
                $responseData = json_decode($response, true);
                
                if ($httpCode >= 200 && $httpCode < 300) {
                    return $responseData;
                }
                
                // Handle error responses
                $errorMessage = 'HTTP ' . $httpCode;
                if ($responseData && isset($responseData['message'])) {
                    $errorMessage .= ': ' . $responseData['message'];
                }
                if ($responseData && isset($responseData['errors'])) {
                    $errorMessage .= ' - ' . json_encode($responseData['errors']);
                }
                
                // Don't retry on client errors (4xx)
                if ($httpCode >= 400 && $httpCode < 500) {
                    error_log('EasyShip API client error: ' . $errorMessage);
                    return $responseData ?: ['error' => $errorMessage];
                }
                
                throw new \Exception($errorMessage);
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $retries++;
                
                if ($retries < $this->maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s
                    sleep(pow(2, $retries - 1));
                    error_log('EasyShip API request retry ' . $retries . '/' . $this->maxRetries . ': ' . $lastError);
                } else {
                    error_log('EasyShip API request failed after ' . $this->maxRetries . ' retries: ' . $lastError);
                }
            }
        }
        
        return null;
    }
}
