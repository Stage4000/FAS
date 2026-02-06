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
    private $apiUrl = 'https://public-api.easyship.com/2024-09';
    private $maxRetries = 3;
    private $timeout = 30;
    private $logFile;
    
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
        
        // Set log file path
        $this->logFile = __DIR__ . '/../../log.txt';
        
        // Validate configuration
        if (empty($this->apiKey) || strpos($this->apiKey, 'YOUR_') === 0) {
            throw new \Exception('EasyShip API key not configured');
        }
    }
    
    /**
     * Get shipping rates for cart items with validation
     */
    public function getShippingRates($items, $destinationAddress, $originWarehouse = null)
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
        
        // Get origin address from warehouse or use default
        $originAddress = $this->getOriginAddress($originWarehouse);
        
        try {
            $shipmentData = [
                'origin_address' => $originAddress,
                'destination_address' => [
                    'country_alpha2' => $this->getCountryCode($destinationAddress['country']),
                    'line_1' => substr($destinationAddress['address1'], 0, 255),
                    'state' => substr($destinationAddress['state'], 0, 100),
                    'city' => substr($destinationAddress['city'], 0, 100),
                    'postal_code' => substr($destinationAddress['zip'], 0, 20)
                ],
                'incoterms' => 'DDP',
                'insurance' => [
                    'is_insured' => false
                ],
                'courier_settings' => [
                    'show_courier_logo_url' => true,
                    'apply_shipping_rules' => true
                ],
                'shipping_settings' => [
                    'units' => [
                        'weight' => 'lb',
                        'dimensions' => 'in'
                    ],
                    'output_currency' => 'USD'
                ],
                'parcels' => $this->buildParcels($items),
                'calculate_tax_and_duties' => true // Required for DDP (Delivered Duty Paid) shipments
            ];
            
            // Add line_2 if present
            if (!empty($destinationAddress['address2'])) {
                $shipmentData['destination_address']['line_2'] = substr($destinationAddress['address2'], 0, 255);
            }
            
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
     * Consolidates all items into a single parcel for accurate shipping calculation
     */
    private function buildParcels($items)
    {
        // Default weight in lbs - should be configured or come from product data
        // This is a fallback value when product weight is not specified
        $defaultWeight = 1.0;
        
        $parcelItems = [];
        $totalWeight = 0.0;
        
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
            
            // Add item to parcel items array
            $parcelItems[] = [
                'quantity' => (int)$item['quantity'],
                'contains_battery_pi966' => false,
                'contains_battery_pi967' => false,
                'contains_liquids' => false,
                'origin_country_alpha2' => 'US',
                'declared_currency' => 'USD',
                'description' => $description,
                'hs_code' => '85171400', // Default HS code for electronics
                'declared_customs_value' => (float)$item['price']
            ];
            
            // Calculate total weight: item weight × quantity
            $totalWeight += $weight * (int)$item['quantity'];
        }
        
        if (empty($parcelItems)) {
            throw new \Exception('No valid items to ship');
        }
        
        // Return a single parcel containing all items with combined weight
        return [
            [
                'box' => [
                    'length' => 10,
                    'width' => 10,
                    'height' => 10
                ],
                'items' => $parcelItems,
                'total_actual_weight' => $totalWeight
            ]
        ];
    }
    
    /**
     * Parse rates response
     * Handles both 2023-01 and 2024-09 API response formats
     */
    private function parseRates($rates)
    {
        $parsed = [];
        
        foreach ($rates as $rate) {
            // Handle 2024-09 API format with courier_service object
            if (isset($rate['courier_service']) && is_array($rate['courier_service'])) {
                $courierService = $rate['courier_service'];
                $courierId = $courierService['id'] ?? $courierService['courier_id'] ?? 'unknown';
                $courierName = $courierService['name'] ?? 'Unknown Courier';
                
                // Calculate total charge from various fee components
                $totalCharge = 0.0;
                $totalCharge += floatval($rate['shipment_charge'] ?? 0);
                $totalCharge += floatval($rate['fuel_surcharge'] ?? 0);
                $totalCharge += floatval($rate['additional_services_surcharge'] ?? 0);
                $totalCharge += floatval($rate['insurance_fee'] ?? 0);
                $totalCharge += floatval($rate['import_tax_charge'] ?? 0);
                $totalCharge += floatval($rate['import_duty_charge'] ?? 0);
                $totalCharge += floatval($rate['ddp_handling_fee'] ?? 0);
                
                // Use shipment_charge_total if available, otherwise use calculated total
                if (isset($rate['shipment_charge_total'])) {
                    $totalCharge = floatval($rate['shipment_charge_total']);
                }
                
                $parsed[] = [
                    'courier_id' => $courierId,
                    'courier_name' => $courierName,
                    'service_name' => $courierService['name'] ?? $courierName,
                    'total_charge' => $totalCharge,
                    'currency' => $rate['currency'] ?? 'USD',
                    'min_delivery_time' => $rate['min_delivery_time'] ?? null,
                    'max_delivery_time' => $rate['max_delivery_time'] ?? null,
                    'delivery_time_text' => $this->formatDeliveryTime($rate)
                ];
            } else {
                // Log unexpected format for debugging
                error_log('EasyShip: Unexpected rate format - missing courier_service: ' . json_encode($rate));
            }
        }
        
        // Sort by price (lowest first)
        usort($parsed, function($a, $b) {
            return $a['total_charge'] <=> $b['total_charge'];
        });
        
        // Return only top 5 cheapest options
        return array_slice($parsed, 0, 5);
    }
    
    /**
     * Format delivery time for display
     * Adds 1 day processing time to all estimates
     */
    private function formatDeliveryTime($option)
    {
        $min = $option['min_delivery_time'] ?? null;
        $max = $option['max_delivery_time'] ?? null;
        
        if ($min && $max) {
            // Add 1 day for processing to both min and max
            $min = intval($min) + 1;
            $max = intval($max) + 1;
            return "{$min}-{$max} business days";
        } elseif ($min) {
            $min = intval($min) + 1;
            return "{$min}+ business days";
        }
        
        return "Standard delivery";
    }
    
    /**
     * Get origin address from warehouse or config
     */
    private function getOriginAddress($warehouse = null)
    {
        // If warehouse object provided, use it
        if ($warehouse && is_array($warehouse)) {
            return [
                'line_1' => $warehouse['address_line1'],
                'state' => $warehouse['state'],
                'city' => $warehouse['city'],
                'postal_code' => $warehouse['postal_code'],
                'country_alpha2' => $warehouse['country_code'] ?? 'US'
            ];
        }
        
        // Try to get default warehouse from database
        try {
            require_once __DIR__ . '/../config/Database.php';
            require_once __DIR__ . '/../models/Warehouse.php';
            
            $db = \FAS\Config\Database::getInstance()->getConnection();
            $warehouseModel = new \FAS\Models\Warehouse($db);
            $defaultWarehouse = $warehouseModel->getDefault();
            
            if ($defaultWarehouse) {
                return [
                    'line_1' => $defaultWarehouse['address_line1'],
                    'state' => $defaultWarehouse['state'],
                    'city' => $defaultWarehouse['city'],
                    'postal_code' => $defaultWarehouse['postal_code'],
                    'country_alpha2' => $defaultWarehouse['country_code'] ?? 'US'
                ];
            }
        } catch (\Exception $e) {
            error_log('EasyShip: Could not load warehouse from database: ' . $e->getMessage());
        }
        
        // Fallback to hardcoded default (for backwards compatibility)
        return [
            'line_1' => '3430 sw westwood dr',
            'city' => 'Topeka',
            'state' => 'KS',
            'postal_code' => '66614',
            'country_alpha2' => 'US'
        ];
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
                    'Content-Type: application/json',
                    'Accept: application/json'
                ]);
                
                $jsonData = null;
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    if ($data !== null) {
                        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
                        if ($jsonData === false) {
                            throw new \Exception('Failed to encode request data as JSON');
                        }
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                    }
                }
                
                // Log request
                $this->logToFile("========== EASYSHIP API REQUEST ==========\n");
                $this->logToFile("Time: " . date('Y-m-d H:i:s') . "\n");
                $this->logToFile("Method: $method\n");
                $this->logToFile("URL: $url\n");
                if ($jsonData) {
                    $this->logToFile("Payload:\n" . $jsonData . "\n");
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                // Log response
                $this->logToFile("Response Code: $httpCode\n");
                if ($curlError) {
                    $this->logToFile("cURL Error: $curlError\n");
                }
                $this->logToFile("Response Body:\n" . $response . "\n");
                $this->logToFile("=========================================\n\n");
                
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
                    $this->logToFile("Retrying request (attempt " . ($retries + 1) . ")...\n\n");
                } else {
                    error_log('EasyShip API request failed after ' . $this->maxRetries . ' retries: ' . $lastError);
                    $this->logToFile("Request failed after " . $this->maxRetries . " retries: $lastError\n\n");
                }
            }
        }
        
        return null;
    }
    
    /**
     * Log message to log file
     */
    private function logToFile($message)
    {
        try {
            file_put_contents($this->logFile, $message, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            error_log('Failed to write to EasyShip log file: ' . $e->getMessage());
        }
    }
}
