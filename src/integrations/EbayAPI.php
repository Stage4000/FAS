<?php
/**
 * eBay API Integration
 * Handles communication with eBay Finding and Shopping APIs
 */

namespace FAS\Integrations;

class EbayAPI
{
    private $appId;
    private $certId;
    private $devId;
    private $userToken;
    private $sandbox;
    private $siteId;
    private $rateLimitExceeded = false;
    
    // API Endpoints
    private $findingApiUrl = 'https://svcs.ebay.com/services/search/FindingService/v1';
    private $shoppingApiUrl = 'https://open.api.ebay.com/shopping';
    
    // Rate limiting constants
    private const RATE_LIMIT_MAX_RETRIES = 3;
    private const RATE_LIMIT_BASE_WAIT = 5; // Base wait time in seconds
    private const RATE_LIMIT_MULTIPLIER = 3; // Exponential multiplier
    
    public function __construct($config = null)
    {
        if ($config === null) {
            $configFile = __DIR__ . '/../config/config.php';
            if (!file_exists($configFile)) {
                $configFile = __DIR__ . '/../config/config.example.php';
            }
            $config = require $configFile;
        }
        
        $ebayConfig = $config['ebay'];
        $this->appId = $ebayConfig['app_id'];
        $this->certId = $ebayConfig['cert_id'];
        $this->devId = $ebayConfig['dev_id'];
        $this->userToken = $ebayConfig['user_token'];
        $this->sandbox = $ebayConfig['sandbox'];
        $this->siteId = $ebayConfig['site_id'];
    }
    
    /**
     * Get items from eBay store
     */
    public function getStoreItems($storeName = 'moto800', $pageNumber = 1, $entriesPerPage = 100)
    {
        $this->rateLimitExceeded = false; // Reset flag
        
        $url = $this->findingApiUrl;
        
        $params = [
            'OPERATION-NAME' => 'findItemsIneBayStores',
            'SERVICE-VERSION' => '1.13.0',
            'SECURITY-APPNAME' => $this->appId,
            'RESPONSE-DATA-FORMAT' => 'JSON',
            'REST-PAYLOAD' => '',
            'storeName' => $storeName,
            'paginationInput.entriesPerPage' => $entriesPerPage,
            'paginationInput.pageNumber' => $pageNumber,
        ];
        
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;
        
        $response = $this->makeRequest($fullUrl);
        
        if ($response && isset($response['findItemsIneBayStoresResponse'])) {
            return $this->parseItemsResponse($response['findItemsIneBayStoresResponse']);
        }
        
        return null;
    }
    
    /**
     * Check if the last request failed due to rate limiting
     */
    public function wasRateLimited()
    {
        return $this->rateLimitExceeded;
    }
    
    /**
     * Get total wait time for all retries (for error messages)
     */
    public function getTotalRetryWaitTime()
    {
        $total = 0;
        for ($i = 0; $i < self::RATE_LIMIT_MAX_RETRIES; $i++) {
            $total += self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $i);
        }
        return $total;
    }
    
    /**
     * Get single item details
     */
    public function getItemDetails($itemId)
    {
        $url = $this->shoppingApiUrl;
        
        $params = [
            'callname' => 'GetSingleItem',
            'responseencoding' => 'JSON',
            'appid' => $this->appId,
            'siteid' => $this->siteId,
            'version' => '967',
            'ItemID' => $itemId,
            'IncludeSelector' => 'Details,Description,ItemSpecifics'
        ];
        
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;
        
        $response = $this->makeRequest($fullUrl);
        
        if ($response && isset($response['Item'])) {
            return $this->parseItemDetails($response['Item']);
        }
        
        return null;
    }
    
    /**
     * Search items by keywords
     */
    public function searchItems($keywords, $categoryId = null, $pageNumber = 1, $entriesPerPage = 100)
    {
        $url = $this->findingApiUrl;
        
        $params = [
            'OPERATION-NAME' => 'findItemsAdvanced',
            'SERVICE-VERSION' => '1.13.0',
            'SECURITY-APPNAME' => $this->appId,
            'RESPONSE-DATA-FORMAT' => 'JSON',
            'REST-PAYLOAD' => '',
            'keywords' => $keywords,
            'paginationInput.entriesPerPage' => $entriesPerPage,
            'paginationInput.pageNumber' => $pageNumber,
        ];
        
        if ($categoryId) {
            $params['categoryId'] = $categoryId;
        }
        
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;
        
        $response = $this->makeRequest($fullUrl);
        
        if ($response && isset($response['findItemsAdvancedResponse'])) {
            return $this->parseItemsResponse($response['findItemsAdvancedResponse']);
        }
        
        return null;
    }
    
    /**
     * Make HTTP request to eBay API with retry logic for rate limiting
     */
    private function makeRequest($url, $retryCount = 0, $maxRetries = self::RATE_LIMIT_MAX_RETRIES)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            error_log('eBay API cURL Error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            
            // Check for eBay API errors in response
            if (isset($data['errorMessage'])) {
                // Check if this is a rate limiting error
                if ($this->isRateLimitError($data['errorMessage'])) {
                    if ($retryCount < $maxRetries) {
                        // Use exponential backoff for rate limiting
                        $waitTime = self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $retryCount);
                        error_log("eBay API Rate Limit: Retry {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                        sleep($waitTime);
                        return $this->makeRequest($url, $retryCount + 1, $maxRetries);
                    } else {
                        $totalWaitTime = $this->getTotalRetryWaitTime();
                        error_log("eBay API Rate Limit: Max retries exceeded after {$totalWaitTime} seconds total wait time.");
                        // Set a flag to indicate rate limiting
                        $this->rateLimitExceeded = true;
                        return null;
                    }
                }
                error_log('eBay API Error Response: ' . json_encode($data['errorMessage']));
                return null;
            }
            
            return $data;
        }
        
        // Handle HTTP 500 errors which may indicate rate limiting or server issues
        if ($httpCode === 500) {
            $data = json_decode($response, true);
            
            // Check if response contains rate limit error
            if ($data && isset($data['errorMessage']) && $this->isRateLimitError($data['errorMessage'])) {
                if ($retryCount < $maxRetries) {
                    // Use exponential backoff
                    $waitTime = self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $retryCount);
                    error_log("eBay API Rate Limit (HTTP 500): Retry {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                    sleep($waitTime);
                    return $this->makeRequest($url, $retryCount + 1, $maxRetries);
                } else {
                    $totalWaitTime = $this->getTotalRetryWaitTime();
                    error_log("eBay API Rate Limit: Max retries exceeded after {$totalWaitTime} seconds total wait time.");
                    // Set a flag to indicate rate limiting
                    $this->rateLimitExceeded = true;
                    return null;
                }
            }
            
            // HTTP 500 without clear rate limit message - may still be rate limiting
            error_log('eBay API HTTP 500 Error - Possible rate limiting or server error. Response: ' . substr($response, 0, 500));
            
            if ($retryCount < $maxRetries) {
                // Treat as potential rate limit and retry with backoff
                $waitTime = self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $retryCount);
                error_log("eBay API HTTP 500: Retry {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                sleep($waitTime);
                return $this->makeRequest($url, $retryCount + 1, $maxRetries);
            } else {
                error_log('eBay API: Max retries exceeded for HTTP 500 errors.');
                $this->rateLimitExceeded = true;
                return null;
            }
        }
        
        // Log detailed error information
        error_log('eBay API HTTP Error: ' . $httpCode . ' | Response: ' . substr($response, 0, 500));
        
        // Check if credentials are placeholders
        if ($this->appId === 'YOUR_EBAY_APP_ID' || strpos($this->appId, 'YOUR_') === 0) {
            error_log('eBay API Error: Invalid credentials. Please configure eBay API credentials in Settings.');
        }
        
        return null;
    }
    
    /**
     * Check if error is a rate limiting error
     */
    private function isRateLimitError($errorMessage)
    {
        // eBay rate limit errors have errorId 10001 and domain Security/RateLimiter
        if (is_array($errorMessage)) {
            foreach ($errorMessage as $errorContainer) {
                if (isset($errorContainer['error'])) {
                    foreach ($errorContainer['error'] as $error) {
                        $errorId = isset($error['errorId'][0]) ? $error['errorId'][0] : null;
                        $domain = isset($error['domain'][0]) ? $error['domain'][0] : null;
                        $subdomain = isset($error['subdomain'][0]) ? $error['subdomain'][0] : null;
                        
                        // Check for rate limit error (errorId 10001, domain Security, subdomain RateLimiter)
                        if ($errorId === '10001' || $errorId === 10001) {
                            return true;
                        }
                        if ($domain === 'Security' && $subdomain === 'RateLimiter') {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }
    
    /**
     * Parse items response from Finding API
     */
    private function parseItemsResponse($response)
    {
        if (!isset($response[0]['searchResult'][0]['item'])) {
            return [
                'items' => [],
                'total' => 0,
                'pages' => 0
            ];
        }
        
        $items = $response[0]['searchResult'][0]['item'];
        $paginationOutput = $response[0]['paginationOutput'][0];
        
        $parsedItems = [];
        foreach ($items as $item) {
            $parsedItems[] = [
                'id' => $item['itemId'][0],
                'title' => $item['title'][0],
                'price' => $item['sellingStatus'][0]['currentPrice'][0]['__value__'],
                'currency' => $item['sellingStatus'][0]['currentPrice'][0]['@currencyId'],
                'image' => $item['galleryURL'][0] ?? null,
                'url' => $item['viewItemURL'][0],
                'condition' => $item['condition'][0]['conditionDisplayName'][0] ?? 'Used',
                'location' => $item['location'][0] ?? '',
                'shipping_cost' => $item['shippingInfo'][0]['shippingServiceCost'][0]['__value__'] ?? 0,
            ];
        }
        
        return [
            'items' => $parsedItems,
            'total' => (int)$paginationOutput['totalEntries'][0],
            'pages' => (int)$paginationOutput['totalPages'][0]
        ];
    }
    
    /**
     * Parse single item details
     */
    private function parseItemDetails($item)
    {
        return [
            'id' => $item['ItemID'],
            'title' => $item['Title'],
            'description' => $item['Description'] ?? '',
            'price' => $item['CurrentPrice']['Value'],
            'currency' => $item['CurrentPrice']['CurrencyID'],
            'quantity' => $item['Quantity'] ?? 1,
            'images' => $item['PictureURL'] ?? [],
            'condition' => $item['ConditionDisplayName'] ?? 'Used',
            'location' => $item['Location'] ?? '',
            'shipping' => $item['ShippingCostSummary'] ?? [],
            'specifics' => $item['ItemSpecifics'] ?? []
        ];
    }
}
