<?php
/**
 * PayPal Payment Integration
 * Handles PayPal checkout and payment processing
 */

namespace FAS\Integrations;

class PayPalAPI
{
    private $clientId;
    private $clientSecret;
    private $mode; // sandbox or live
    private $currency;
    private $apiUrl;
    
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
        
        // Set API URL based on mode
        $this->apiUrl = $this->mode === 'sandbox' 
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }
    
    /**
     * Get OAuth access token
     */
    private function getAccessToken()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Accept-Language: en_US'
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }
    
    /**
     * Create PayPal order
     */
    public function createOrder($items, $shippingAmount = 0, $taxAmount = 0)
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return ['error' => 'Failed to get access token'];
        }
        
        // Calculate total
        $subtotal = 0;
        $orderItems = [];
        
        foreach ($items as $item) {
            $itemTotal = $item['price'] * $item['quantity'];
            $subtotal += $itemTotal;
            
            $orderItems[] = [
                'name' => $item['name'],
                'description' => $item['description'] ?? '',
                'sku' => $item['sku'] ?? '',
                'unit_amount' => [
                    'currency_code' => $this->currency,
                    'value' => number_format($item['price'], 2, '.', '')
                ],
                'quantity' => $item['quantity'],
                'category' => 'PHYSICAL_GOODS'
            ];
        }
        
        $totalAmount = $subtotal + $shippingAmount + $taxAmount;
        
        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $this->currency,
                    'value' => number_format($totalAmount, 2, '.', ''),
                    'breakdown' => [
                        'item_total' => [
                            'currency_code' => $this->currency,
                            'value' => number_format($subtotal, 2, '.', '')
                        ],
                        'shipping' => [
                            'currency_code' => $this->currency,
                            'value' => number_format($shippingAmount, 2, '.', '')
                        ],
                        'tax_total' => [
                            'currency_code' => $this->currency,
                            'value' => number_format($taxAmount, 2, '.', '')
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
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/v2/checkout/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($httpCode === 201 && isset($data['id'])) {
            return [
                'success' => true,
                'order_id' => $data['id'],
                'approve_url' => $this->getApproveUrl($data['links'])
            ];
        }
        
        return ['error' => $data['message'] ?? 'Failed to create order'];
    }
    
    /**
     * Capture payment for order
     */
    public function captureOrder($orderId)
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return ['error' => 'Failed to get access token'];
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/v2/checkout/orders/' . $orderId . '/capture');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($httpCode === 201 && $data['status'] === 'COMPLETED') {
            return [
                'success' => true,
                'transaction_id' => $data['purchase_units'][0]['payments']['captures'][0]['id']
            ];
        }
        
        return ['error' => $data['message'] ?? 'Failed to capture payment'];
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
