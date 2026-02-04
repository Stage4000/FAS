<?php
/**
 * PayPal Payment Integration
 * Handles PayPal checkout and payment processing with production-ready error handling
 */

namespace FAS\Integrations;

class PayPalAPI
{
    private $clientId;
    private $clientSecret;
    private $mode; // sandbox or live
    private $currency;
    private $apiUrl;
    private $accessToken;
    private $tokenExpiry;
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
        
        $paypalConfig = $config['paypal'];
        $this->clientId = $paypalConfig['client_id'];
        $this->clientSecret = $paypalConfig['client_secret'];
        $this->mode = $paypalConfig['mode'];
        $this->currency = $paypalConfig['currency'];
        
        // Validate configuration
        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \Exception('PayPal API credentials not configured');
        }
        
        if (!in_array($this->mode, ['sandbox', 'live'])) {
            throw new \Exception('Invalid PayPal mode. Must be "sandbox" or "live"');
        }
        
        // Set API URL based on mode
        $this->apiUrl = $this->mode === 'sandbox' 
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }
    
    /**
     * Get OAuth access token with caching and retry logic
     */
    private function getAccessToken()
    {
        // Return cached token if still valid
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }
        
        $retries = 0;
        $lastError = null;
        
        while ($retries < $this->maxRetries) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/v1/oauth2/token');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
                curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: application/json',
                    'Accept-Language: en_US'
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    throw new \Exception('cURL error: ' . $curlError);
                }
                
                if ($httpCode !== 200) {
                    throw new \Exception('HTTP error: ' . $httpCode . ' - ' . $response);
                }
                
                $data = json_decode($response, true);
                
                if (!isset($data['access_token'])) {
                    throw new \Exception('No access token in response');
                }
                
                // Cache the token (expires in ~9 hours, we'll use 8 hours to be safe)
                $this->accessToken = $data['access_token'];
                $this->tokenExpiry = time() + ($data['expires_in'] ?? 28800) - 600; // Subtract 10 minutes buffer
                
                return $this->accessToken;
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $retries++;
                
                if ($retries < $this->maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s
                    sleep(pow(2, $retries - 1));
                } else {
                    error_log('PayPal getAccessToken failed after ' . $this->maxRetries . ' retries: ' . $lastError);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Create PayPal order with improved error handling and validation
     */
    public function createOrder($items, $shippingAmount = 0, $taxAmount = 0)
    {
        // Validate inputs
        if (empty($items) || !is_array($items)) {
            return ['error' => 'Invalid items array'];
        }
        
        if ($shippingAmount < 0 || $taxAmount < 0) {
            return ['error' => 'Invalid amounts'];
        }
        
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return ['error' => 'Failed to authenticate with PayPal'];
        }
        
        // Calculate total with validation
        $subtotal = 0;
        $orderItems = [];
        
        foreach ($items as $item) {
            // Validate item structure
            if (!isset($item['name']) || !isset($item['price']) || !isset($item['quantity'])) {
                return ['error' => 'Invalid item structure'];
            }
            
            if ($item['price'] < 0 || $item['quantity'] < 1) {
                return ['error' => 'Invalid item price or quantity'];
            }
            
            $itemTotal = $item['price'] * $item['quantity'];
            $subtotal += $itemTotal;
            
            $orderItems[] = [
                'name' => substr($item['name'], 0, 127), // PayPal limit
                'description' => isset($item['description']) ? substr($item['description'], 0, 127) : '',
                'sku' => isset($item['sku']) ? substr($item['sku'], 0, 127) : '',
                'unit_amount' => [
                    'currency_code' => $this->currency,
                    'value' => number_format($item['price'], 2, '.', '')
                ],
                'quantity' => (string)$item['quantity'],
                'category' => 'PHYSICAL_GOODS'
            ];
        }
        
        $totalAmount = $subtotal + $shippingAmount + $taxAmount;
        
        // Ensure amounts match PayPal's precision requirements
        $subtotalStr = number_format($subtotal, 2, '.', '');
        $shippingStr = number_format($shippingAmount, 2, '.', '');
        $taxStr = number_format($taxAmount, 2, '.', '');
        $totalStr = number_format($totalAmount, 2, '.', '');
        
        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $this->currency,
                    'value' => $totalStr,
                    'breakdown' => [
                        'item_total' => [
                            'currency_code' => $this->currency,
                            'value' => $subtotalStr
                        ],
                        'shipping' => [
                            'currency_code' => $this->currency,
                            'value' => $shippingStr
                        ],
                        'tax_total' => [
                            'currency_code' => $this->currency,
                            'value' => $taxStr
                        ]
                    ]
                ],
                'items' => $orderItems
            ]],
            'application_context' => [
                'brand_name' => 'Flip and Strip',
                'landing_page' => 'BILLING',
                'user_action' => 'PAY_NOW',
                'return_url' => 'https://flipandstrip.com/checkout-success.php',
                'cancel_url' => 'https://flipandstrip.com/checkout-cancel.php'
            ]
        ];
        
        return $this->makeRequest('POST', '/v2/checkout/orders', $orderData);
    }
    
    /**
     * Capture payment for order with validation
     */
    public function captureOrder($orderId)
    {
        if (empty($orderId)) {
            return ['error' => 'Order ID is required'];
        }
        
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return ['error' => 'Failed to authenticate with PayPal'];
        }
        
        $result = $this->makeRequest('POST', '/v2/checkout/orders/' . $orderId . '/capture', []);
        
        if (isset($result['success']) && $result['success'] && isset($result['data'])) {
            $data = $result['data'];
            if ($data['status'] === 'COMPLETED') {
                $transactionId = null;
                if (isset($data['purchase_units'][0]['payments']['captures'][0]['id'])) {
                    $transactionId = $data['purchase_units'][0]['payments']['captures'][0]['id'];
                }
                
                return [
                    'success' => true,
                    'transaction_id' => $transactionId,
                    'status' => $data['status']
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Verify webhook signature for security
     * Note: This is a placeholder. For production use, implement proper verification.
     */
    public function verifyWebhookSignature($headers, $body)
    {
        // IMPORTANT: This method requires proper implementation for production use
        // To implement webhook verification:
        // 1. Get your webhook ID from PayPal Developer Dashboard
        // 2. Store it in your config
        // 3. Use PayPal's verification API endpoint
        
        $transmissionId = $headers['Paypal-Transmission-Id'] ?? $headers['paypal-transmission-id'] ?? null;
        $transmissionTime = $headers['Paypal-Transmission-Time'] ?? $headers['paypal-transmission-time'] ?? null;
        $transmissionSig = $headers['Paypal-Transmission-Sig'] ?? $headers['paypal-transmission-sig'] ?? null;
        $certUrl = $headers['Paypal-Cert-Url'] ?? $headers['paypal-cert-url'] ?? null;
        $authAlgo = $headers['Paypal-Auth-Algo'] ?? $headers['paypal-auth-algo'] ?? null;
        
        if (!$transmissionId || !$transmissionSig || !$certUrl) {
            error_log('PayPal webhook: Missing required headers for signature verification');
            return false;
        }
        
        // WARNING: PayPal webhook signature verification is NOT IMPLEMENTED
        // This is a critical security issue for production use
        // Without proper verification, attackers can send fake webhooks to mark orders as paid
        // 
        // REQUIRED FOR PRODUCTION:
        // 1. Get webhook ID from PayPal Developer Dashboard
        // 2. Store it in config.php under $config['paypal']['webhook_id']
        // 3. Uncomment and complete the verification code below
        // 4. For now, REJECT all webhook requests to prevent fraud
        
        error_log('SECURITY WARNING: PayPal webhook signature verification is not implemented');
        error_log('Rejecting webhook to prevent potential fraud. Configure webhook_id to enable.');
        
        // For production safety, reject webhooks until proper verification is implemented
        return false;
        
        /* UNCOMMENT THIS BLOCK AFTER CONFIGURING WEBHOOK_ID IN config.php:
        
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return false;
        }
        
        $webhookId = $this->config['paypal']['webhook_id'] ?? null;
        if (!$webhookId) {
            error_log('PayPal webhook ID not configured');
            return false;
        }
        
        $verificationData = [
            'transmission_id' => $transmissionId,
            'transmission_time' => $transmissionTime,
            'cert_url' => $certUrl,
            'auth_algo' => $authAlgo,
            'transmission_sig' => $transmissionSig,
            'webhook_id' => $webhookId,
            'webhook_event' => json_decode($body, true)
        ];
        
        $result = $this->makeRequest('POST', '/v1/notifications/verify-webhook-signature', $verificationData);
        return isset($result['verification_status']) && $result['verification_status'] === 'SUCCESS';
        
        */
    }
    
    /**
     * Get order details
     */
    public function getOrderDetails($orderId)
    {
        if (empty($orderId)) {
            return ['error' => 'Order ID is required'];
        }
        
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return ['error' => 'Failed to authenticate with PayPal'];
        }
        
        return $this->makeRequest('GET', '/v2/checkout/orders/' . $orderId);
    }
    
    /**
     * Make HTTP request to PayPal API with retry logic
     */
    private function makeRequest($method, $endpoint, $data = null)
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return ['error' => 'Failed to authenticate with PayPal'];
        }
        
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
                
                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken
                ];
                
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    if ($data !== null) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                } elseif ($method === 'GET') {
                    curl_setopt($ch, CURLOPT_HTTPGET, true);
                }
                
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    throw new \Exception('cURL error: ' . $curlError);
                }
                
                $responseData = json_decode($response, true);
                
                // Handle successful responses
                if ($httpCode >= 200 && $httpCode < 300) {
                    if ($method === 'POST' && $endpoint === '/v2/checkout/orders') {
                        // Create order response
                        if (isset($responseData['id'])) {
                            return [
                                'success' => true,
                                'order_id' => $responseData['id'],
                                'approve_url' => $this->getApproveUrl($responseData['links'] ?? []),
                                'data' => $responseData
                            ];
                        }
                    } elseif (strpos($endpoint, '/capture') !== false) {
                        // Capture order response
                        return [
                            'success' => true,
                            'data' => $responseData
                        ];
                    } else {
                        // Generic success response
                        return [
                            'success' => true,
                            'data' => $responseData
                        ];
                    }
                }
                
                // Handle error responses
                $errorMessage = 'HTTP ' . $httpCode;
                if (isset($responseData['message'])) {
                    $errorMessage .= ': ' . $responseData['message'];
                }
                if (isset($responseData['details'])) {
                    $errorMessage .= ' - ' . json_encode($responseData['details']);
                }
                
                // Don't retry on client errors (4xx)
                if ($httpCode >= 400 && $httpCode < 500) {
                    error_log('PayPal API client error: ' . $errorMessage);
                    return ['error' => $errorMessage, 'http_code' => $httpCode];
                }
                
                throw new \Exception($errorMessage);
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $retries++;
                
                if ($retries < $this->maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s
                    sleep(pow(2, $retries - 1));
                    error_log('PayPal API request retry ' . $retries . '/' . $this->maxRetries . ': ' . $lastError);
                } else {
                    error_log('PayPal API request failed after ' . $this->maxRetries . ' retries: ' . $lastError);
                }
            }
        }
        
        return ['error' => 'Request failed: ' . $lastError];
    }
    
    /**
     * Get approval URL from links
     */
    private function getApproveUrl($links)
    {
        foreach ($links as $link) {
            if ($link['rel'] === 'approve') {
                return $link['href'];
            }
        }
        return null;
    }
}
