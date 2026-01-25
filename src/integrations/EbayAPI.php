<?php
/**
 * eBay API Integration
 * Handles communication with eBay Trading, Inventory, Finding and Shopping APIs
 * Primary: Trading API GetSellerList (OAuth 2.0) - for fetching seller listings
 * Alternative: Inventory API (OAuth 2.0) - 2M requests/day for inventory management
 * Legacy: Finding and Shopping APIs - 5K requests/day
 */

namespace FAS\Integrations;

use FAS\Utils\SyncLogger;

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
    private $tradingApiUrl = 'https://api.ebay.com/ws/api.dll';
    private $inventoryApiUrl = 'https://api.ebay.com/sell/inventory/v1';
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
     * Get items from eBay store using Trading API GetSellerList
     */
    public function getStoreItems($storeName = 'moto800', $pageNumber = 1, $entriesPerPage = 100, $startDate = null, $endDate = null)
    {
        $this->rateLimitExceeded = false; // Reset flag
        
        // Default to last 120 days if no dates provided
        if ($startDate === null) {
            $startDate = date('Y-m-d', strtotime('-120 days'));
        }
        if ($endDate === null) {
            $endDate = date('Y-m-d');
        }
        
        // Use Trading API GetSellerList to get seller's active listings
        $url = $this->sandbox ? 'https://api.sandbox.ebay.com/ws/api.dll' : $this->tradingApiUrl;
        
        // Build GetSellerList XML request
        $xmlRequest = $this->buildGetSellerListRequest($pageNumber, $entriesPerPage, $startDate, $endDate);
        
        $response = $this->makeTradingApiRequest($url, $xmlRequest);
        
        if ($response && isset($response['ItemArray']['Item'])) {
            return $this->parseTradingApiResponse($response, $pageNumber, $entriesPerPage);
        }
        
        // Handle empty response
        if ($response && isset($response['Ack']) && $response['Ack'] === 'Success') {
            return [
                'items' => [],
                'total' => 0,
                'pages' => 0
            ];
        }
        
        return null;
    }
    
    /**
     * Build GetSellerList XML request
     */
    private function buildGetSellerListRequest($pageNumber, $entriesPerPage, $startDate, $endDate)
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetSellerListRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml .= '<RequesterCredentials>';
        $xml .= '<eBayAuthToken>' . htmlspecialchars($this->userToken) . '</eBayAuthToken>';
        $xml .= '</RequesterCredentials>';
        $xml .= '<Version>967</Version>';
        $xml .= '<ErrorLanguage>en_US</ErrorLanguage>';
        $xml .= '<WarningLevel>High</WarningLevel>';
        
        // Pagination
        $xml .= '<Pagination>';
        $xml .= '<EntriesPerPage>' . $entriesPerPage . '</EntriesPerPage>';
        $xml .= '<PageNumber>' . $pageNumber . '</PageNumber>';
        $xml .= '</Pagination>';
        
        // Detail level
        $xml .= '<DetailLevel>ReturnAll</DetailLevel>';
        
        // Time range - convert dates to ISO 8601 format
        $startDateTime = new \DateTime($startDate);
        $endDateTime = new \DateTime($endDate);
        $endDateTime->setTime(23, 59, 59); // Set to end of day
        
        $xml .= '<StartTimeFrom>' . $startDateTime->format('c') . '</StartTimeFrom>';
        $xml .= '<StartTimeTo>' . $endDateTime->format('c') . '</StartTimeTo>';
        
        // Output selector for specific fields
        $xml .= '<OutputSelector>ItemID</OutputSelector>';
        $xml .= '<OutputSelector>Title</OutputSelector>';
        $xml .= '<OutputSelector>PictureDetails</OutputSelector>';
        $xml .= '<OutputSelector>SellingStatus</OutputSelector>';
        $xml .= '<OutputSelector>Quantity</OutputSelector>';
        $xml .= '<OutputSelector>ConditionDisplayName</OutputSelector>';
        $xml .= '<OutputSelector>ListingType</OutputSelector>';
        $xml .= '<OutputSelector>ViewItemURL</OutputSelector>';
        $xml .= '<OutputSelector>PaginationResult</OutputSelector>';
        
        $xml .= '</GetSellerListRequest>';
        
        return $xml;
    }
    
    /**
     * Make Trading API request
     */
    private function makeTradingApiRequest($url, $xmlRequest, $retryCount = 0, $maxRetries = self::RATE_LIMIT_MAX_RETRIES)
    {
        // Log the request
        SyncLogger::logRequest($url . ' (Trading API GetSellerList)');
        SyncLogger::log('Request body (XML): ' . substr($xmlRequest, 0, 500) . '...');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
        
        $headers = [
            'X-EBAY-API-COMPATIBILITY-LEVEL: 967',
            'X-EBAY-API-CALL-NAME: GetSellerList',
            'X-EBAY-API-SITEID: ' . $this->siteId,
            'Content-Type: text/xml;charset=utf-8'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Log the response
        SyncLogger::logResponse($response, $httpCode);
        
        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
            error_log('eBay Trading API cURL Error: ' . $curlError);
            SyncLogger::logError('cURL Error: ' . $curlError);
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);
        
        if ($httpCode === 200) {
            // Parse XML response to array
            $data = $this->parseXmlResponse($response);
            
            if (!$data) {
                error_log('eBay Trading API: Failed to parse XML response');
                SyncLogger::logError('Failed to parse XML response');
                return null;
            }
            
            // Check for API errors
            if (isset($data['Ack']) && ($data['Ack'] === 'Failure' || $data['Ack'] === 'PartialFailure')) {
                $errorMsg = $data['Errors']['LongMessage'] ?? $data['Errors']['ShortMessage'] ?? 'Unknown error';
                error_log('eBay Trading API Error: ' . $errorMsg);
                SyncLogger::logError('Trading API returned error: ' . $errorMsg);
                
                // Check for rate limit
                if (isset($data['Errors']['ErrorCode']) && $data['Errors']['ErrorCode'] == '21919300') {
                    if ($retryCount < $maxRetries) {
                        $waitTime = self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $retryCount);
                        SyncLogger::log("Rate limit hit. Retrying {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                        sleep($waitTime);
                        return $this->makeTradingApiRequest($url, $xmlRequest, $retryCount + 1, $maxRetries);
                    } else {
                        $this->rateLimitExceeded = true;
                    }
                }
                
                return null;
            }
            
            return $data;
        }
        
        error_log('eBay Trading API HTTP Error: ' . $httpCode);
        SyncLogger::logError('eBay Trading API HTTP Error: ' . $httpCode);
        return null;
    }
    
    /**
     * Parse XML response to array
     */
    private function parseXmlResponse($xmlString)
    {
        try {
            $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml === false) {
                return null;
            }
            $json = json_encode($xml);
            return json_decode($json, true);
        } catch (\Exception $e) {
            error_log('XML parsing error: ' . $e->getMessage());
            SyncLogger::logError('XML parsing error', $e);
            return null;
        }
    }
    
    /**
     * Parse Trading API response
     */
    private function parseTradingApiResponse($response, $pageNumber, $entriesPerPage)
    {
        $items = [];
        
        // Handle single item (not in array)
        $itemsData = $response['ItemArray']['Item'] ?? [];
        if (isset($itemsData['ItemID'])) {
            // Single item, wrap in array
            $itemsData = [$itemsData];
        }
        
        foreach ($itemsData as $item) {
            $items[] = [
                'id' => $item['ItemID'] ?? '',
                'title' => $item['Title'] ?? 'Untitled',
                'price' => $item['SellingStatus']['CurrentPrice'] ?? '0',
                'currency' => 'USD',
                'image' => $item['PictureDetails']['PictureURL'][0] ?? $item['PictureDetails']['PictureURL'] ?? null,
                'url' => $item['ViewItemURL'] ?? '',
                'condition' => $item['ConditionDisplayName'] ?? 'Used',
                'location' => '',
                'shipping_cost' => 0,
            ];
        }
        
        // Get pagination info
        $totalEntries = (int)($response['PaginationResult']['TotalNumberOfEntries'] ?? count($items));
        $totalPages = (int)($response['PaginationResult']['TotalNumberOfPages'] ?? 1);
        
        return [
            'items' => $items,
            'total' => $totalEntries,
            'pages' => $totalPages
        ];
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
    private function makeRequest($url, $retryCount = 0, $maxRetries = self::RATE_LIMIT_MAX_RETRIES, $useOAuth = false)
    {
        // Log the request
        SyncLogger::logRequest($url);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Add OAuth 2.0 authentication header if required
        if ($useOAuth && $this->userToken) {
            $headers = [
                'Authorization: Bearer ' . $this->userToken,
                'Content-Type: application/json',
                'Accept: application/json'
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            SyncLogger::log("Using OAuth 2.0 authentication with user token");
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Log the response
        SyncLogger::logResponse($response, $httpCode);
        
        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
            error_log('eBay API cURL Error: ' . $curlError);
            SyncLogger::logError('cURL Error: ' . $curlError);
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            
            // Validate JSON parsing was successful
            if (json_last_error() !== JSON_ERROR_NONE || $data === null) {
                $jsonError = json_last_error_msg();
                error_log('eBay API: Invalid JSON response. Error: ' . $jsonError . ' | Full response: ' . $response);
                SyncLogger::logError('Invalid JSON response. Error: ' . $jsonError);
                return null;
            }
            
            // Check for eBay API errors in response
            if (isset($data['errorMessage'])) {
                // Check if this is a rate limiting error
                if ($this->isRateLimitError($data['errorMessage'])) {
                    if ($retryCount < $maxRetries) {
                        // Use exponential backoff for rate limiting
                        $waitTime = self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $retryCount);
                        error_log("eBay API Rate Limit: Retry {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                        SyncLogger::log("Rate limit hit. Retrying {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                        sleep($waitTime);
                        return $this->makeRequest($url, $retryCount + 1, $maxRetries, $useOAuth);
                    } else {
                        $totalWaitTime = $this->getTotalRetryWaitTime();
                        error_log("eBay API Rate Limit: Max retries exceeded after {$totalWaitTime} seconds total wait time.");
                        SyncLogger::logError("Rate limit: Max retries exceeded after {$totalWaitTime} seconds");
                        // Set a flag to indicate rate limiting
                        $this->rateLimitExceeded = true;
                        return null;
                    }
                }
                error_log('eBay API Error Response: ' . json_encode($data['errorMessage']));
                SyncLogger::logError('eBay API returned error: ' . json_encode($data['errorMessage']));
                return null;
            }
            
            return $data;
        }
        
        // Handle HTTP 500 errors which may indicate rate limiting or server issues
        if ($httpCode === 500) {
            $data = json_decode($response, true);
            
            // Validate JSON parsing was successful
            if (json_last_error() !== JSON_ERROR_NONE || $data === null) {
                $jsonError = json_last_error_msg();
                error_log('eBay API HTTP 500: Invalid JSON response. Error: ' . $jsonError . ' | Full response: ' . $response);
                SyncLogger::logError('HTTP 500 with invalid JSON. Error: ' . $jsonError);
                
                // HTTP 500 with invalid response - may still be rate limiting, retry with backoff
                if ($retryCount < $maxRetries) {
                    $waitTime = self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $retryCount);
                    error_log("eBay API HTTP 500 (invalid JSON): Retry {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                    SyncLogger::log("HTTP 500 with invalid JSON. Retrying {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                    sleep($waitTime);
                    return $this->makeRequest($url, $retryCount + 1, $maxRetries, $useOAuth);
                } else {
                    error_log('eBay API: Max retries exceeded for HTTP 500 errors with invalid JSON.');
                    SyncLogger::logError('Max retries exceeded for HTTP 500 errors with invalid JSON');
                    $this->rateLimitExceeded = true;
                    return null;
                }
            }
            
            // Check if response contains rate limit error
            if ($data && isset($data['errorMessage']) && $this->isRateLimitError($data['errorMessage'])) {
                if ($retryCount < $maxRetries) {
                    // Use exponential backoff
                    $waitTime = self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $retryCount);
                    error_log("eBay API Rate Limit (HTTP 500): Retry {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                    SyncLogger::log("Rate limit (HTTP 500). Retrying {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                    sleep($waitTime);
                    return $this->makeRequest($url, $retryCount + 1, $maxRetries, $useOAuth);
                } else {
                    $totalWaitTime = $this->getTotalRetryWaitTime();
                    error_log("eBay API Rate Limit: Max retries exceeded after {$totalWaitTime} seconds total wait time.");
                    SyncLogger::logError("Rate limit: Max retries exceeded after {$totalWaitTime} seconds");
                    // Set a flag to indicate rate limiting
                    $this->rateLimitExceeded = true;
                    return null;
                }
            }
            
            // HTTP 500 without clear rate limit message - may still be rate limiting
            error_log('eBay API HTTP 500 Error - Possible rate limiting or server error. Response: ' . substr($response, 0, 500));
            SyncLogger::logError('HTTP 500 Error - Possible rate limiting or server error');
            
            if ($retryCount < $maxRetries) {
                // Treat as potential rate limit and retry with backoff
                $waitTime = self::RATE_LIMIT_BASE_WAIT * pow(self::RATE_LIMIT_MULTIPLIER, $retryCount);
                error_log("eBay API HTTP 500: Retry {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                SyncLogger::log("HTTP 500. Retrying {$retryCount}/{$maxRetries} after {$waitTime} seconds");
                sleep($waitTime);
                return $this->makeRequest($url, $retryCount + 1, $maxRetries, $useOAuth);
            } else {
                error_log('eBay API: Max retries exceeded for HTTP 500 errors.');
                SyncLogger::logError('Max retries exceeded for HTTP 500 errors');
                $this->rateLimitExceeded = true;
                return null;
            }
        }
        
        // Log detailed error information
        error_log('eBay API HTTP Error: ' . $httpCode . ' | Response: ' . substr($response, 0, 500));
        SyncLogger::logError('eBay API HTTP Error: ' . $httpCode);
        
        // Check if credentials are placeholders
        if ($this->appId === 'YOUR_EBAY_APP_ID' || strpos($this->appId, 'YOUR_') === 0) {
            error_log('eBay API Error: Invalid credentials. Please configure eBay API credentials in Settings.');
            SyncLogger::logError('Invalid credentials. Please configure eBay API credentials in Settings.');
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
     * Parse Inventory API response
     */
    private function parseInventoryResponse($response, $pageNumber, $entriesPerPage)
    {
        $items = $response['inventoryItems'] ?? [];
        $total = $response['total'] ?? count($items);
        
        $parsedItems = [];
        foreach ($items as $item) {
            // Get the offer for pricing information
            $offer = null;
            if (isset($item['sku'])) {
                // We'll use availability and product info from the inventory item
                $parsedItems[] = [
                    'id' => $item['sku'] ?? '',
                    'title' => $item['product']['title'] ?? 'Untitled',
                    'price' => $item['product']['aspects']['Price'][0] ?? '0',
                    'currency' => 'USD', // Default, actual currency may be in offers
                    'image' => $item['product']['imageUrls'][0] ?? null,
                    'url' => '', // Inventory API doesn't provide listing URL directly
                    'condition' => $item['condition'] ?? 'USED',
                    'location' => $item['availability']['shipToLocationAvailability']['quantity'] ?? '',
                    'shipping_cost' => 0, // Would need to fetch from offers
                ];
            }
        }
        
        // Calculate total pages
        $pages = ($total > 0) ? ceil($total / $entriesPerPage) : 0;
        
        return [
            'items' => $parsedItems,
            'total' => $total,
            'pages' => $pages
        ];
    }
    
    /**
     * Parse items response from Finding API (legacy, kept for compatibility)
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
