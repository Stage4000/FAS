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
    private $refreshToken;
    private $tokenExpiresAt;
    private $sandbox;
    private $siteId;
    private $rateLimitExceeded = false;
    private $storeCategoriesCache = null; // Cache for store categories
    private $configFile; // Store config file path for token updates
    
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
            $this->configFile = __DIR__ . '/../config/config.php';
            if (!file_exists($this->configFile)) {
                $this->configFile = __DIR__ . '/../config/config.example.php';
            }
            $config = require $this->configFile;
        }
        
        $ebayConfig = $config['ebay'];
        $this->appId = $ebayConfig['app_id'];
        $this->certId = $ebayConfig['cert_id'];
        $this->devId = $ebayConfig['dev_id'];
        $this->userToken = $ebayConfig['user_token'];
        $this->refreshToken = $ebayConfig['refresh_token'] ?? null;
        $this->tokenExpiresAt = $ebayConfig['token_expires_at'] ?? null;
        $this->sandbox = $ebayConfig['sandbox'];
        $this->siteId = $ebayConfig['site_id'];
        
        // DON'T auto-refresh in constructor to avoid blocking object creation
        // Token refresh will happen before first API call
    }
    
    /**
     * Check if the access token is expired or about to expire
     * @return bool True if token is expired or will expire in next 5 minutes
     */
    private function isTokenExpired()
    {
        if (!$this->tokenExpiresAt) {
            return false; // No expiry info, assume it's valid
        }
        
        // Consider token expired if it expires within 5 minutes (300 seconds buffer)
        return time() >= ($this->tokenExpiresAt - 300);
    }
    
    /**
     * Ensure we have a valid access token, refresh if needed
     * @return bool True if token is valid or was successfully refreshed
     */
    private function ensureValidToken()
    {
        if (!$this->isTokenExpired()) {
            return true; // Token is still valid
        }
        
        if (!$this->refreshToken) {
            SyncLogger::logWarning('Access token expired and no refresh token available. Please re-authorize via admin panel.');
            return false;
        }
        
        SyncLogger::log('Access token expired or expiring soon. Attempting to refresh...');
        
        try {
            return $this->refreshAccessToken();
        } catch (\Exception $e) {
            SyncLogger::logError('Token refresh failed with exception', $e);
            return false;
        }
    }
    
    /**
     * Refresh the access token using the refresh token
     * @return bool True if refresh was successful
     */
    private function refreshAccessToken()
    {
        SyncLogger::log('[TOKEN_REFRESH] Starting token refresh process...');
        
        $tokenUrl = $this->sandbox 
            ? 'https://api.sandbox.ebay.com/identity/v1/oauth2/token'
            : 'https://api.ebay.com/identity/v1/oauth2/token';
        
        SyncLogger::log('[TOKEN_REFRESH] Token URL: ' . $tokenUrl);
        SyncLogger::log('[TOKEN_REFRESH] Sandbox mode: ' . ($this->sandbox ? 'true' : 'false'));
        
        $credentials = base64_encode($this->appId . ':' . $this->certId);
        SyncLogger::log('[TOKEN_REFRESH] Credentials encoded (length: ' . strlen($credentials) . ')');
        
        $postData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'scope' => 'https://api.ebay.com/oauth/api_scope'
        ];
        
        SyncLogger::log('[TOKEN_REFRESH] Refresh token length: ' . strlen($this->refreshToken ?? ''));
        SyncLogger::log('[TOKEN_REFRESH] Making cURL request...');
        
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_VERBOSE => false,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        SyncLogger::log('[TOKEN_REFRESH] cURL completed. HTTP Code: ' . $httpCode);
        SyncLogger::log('[TOKEN_REFRESH] Response length: ' . strlen($response));
        SyncLogger::log('[TOKEN_REFRESH] Response (first 1000 chars): ' . substr($response, 0, 1000));
        
        if ($error) {
            SyncLogger::logError('Token refresh cURL error', new \Exception('cURL Error: ' . $error));
            return false;
        }
        
        if ($httpCode !== 200) {
            SyncLogger::logError('Token refresh HTTP error', new \Exception("HTTP $httpCode. Response: $response"));
            return false;
        }
        
        // Check if response is empty
        if (empty($response)) {
            SyncLogger::logError('Token refresh empty response', new \Exception('Empty response from eBay OAuth endpoint'));
            return false;
        }
        
        SyncLogger::log('[TOKEN_REFRESH] Attempting to decode JSON...');
        
        // Check for JSON decode errors
        $tokenData = json_decode($response, true);
        $jsonError = json_last_error();
        
        SyncLogger::log('[TOKEN_REFRESH] JSON decode error code: ' . $jsonError);
        SyncLogger::log('[TOKEN_REFRESH] JSON decode error message: ' . json_last_error_msg());
        
        if ($jsonError !== JSON_ERROR_NONE) {
            $errorMsg = 'JSON decode error: ' . json_last_error_msg() . '. Response: ' . $response;
            SyncLogger::logError('Token refresh JSON parse error', new \Exception($errorMsg));
            return false;
        }
        
        if (!$tokenData || !isset($tokenData['access_token'])) {
            SyncLogger::logError('Invalid token refresh response', new \Exception('No access_token in response. Decoded data: ' . print_r($tokenData, true)));
            return false;
        }
        
        SyncLogger::log('[TOKEN_REFRESH] Token refresh successful! New access token received.');
        
        // Update tokens
        $this->userToken = $tokenData['access_token'];
        $this->tokenExpiresAt = time() + ($tokenData['expires_in'] ?? 7200);
        
        // Update refresh token if a new one was provided
        if (isset($tokenData['refresh_token'])) {
            $this->refreshToken = $tokenData['refresh_token'];
            SyncLogger::log('[TOKEN_REFRESH] New refresh token also received.');
        }
        
        SyncLogger::log('[TOKEN_REFRESH] Token refresh successful! New token expires at: ' . date('Y-m-d H:i:s', $this->tokenExpiresAt));
        
        // Save updated tokens to config file - completely optional, won't fail the refresh
        try {
            SyncLogger::log('[TOKEN_REFRESH] Now attempting to save tokens to config file...');
            $this->saveTokensToConfig();
            SyncLogger::log('[TOKEN_REFRESH] Config file save completed');
        } catch (\Throwable $e) {
            // Use Throwable to catch ALL errors including fatal errors
            SyncLogger::log('[TOKEN_REFRESH] CAUGHT EXCEPTION in config save: ' . get_class($e));
            SyncLogger::log('[TOKEN_REFRESH] Exception message: ' . $e->getMessage());
            SyncLogger::logError('Failed to save tokens to config file', $e);
            // Continue anyway - token is updated in memory and that's what matters
        }
        
        SyncLogger::log('Access token refreshed successfully and ready to use.');
        return true;
    }
    
    /**
     * Save updated tokens to the configuration file
     */
    private function saveTokensToConfig()
    {
        SyncLogger::log('[TOKEN_REFRESH] Attempting to save tokens to config file...');
        
        if (!$this->configFile || !file_exists($this->configFile)) {
            SyncLogger::logWarning('[TOKEN_REFRESH] Config file not found: ' . ($this->configFile ?? 'null'));
            return;
        }
        
        SyncLogger::log('[TOKEN_REFRESH] Config file path: ' . $this->configFile);
        
        try {
            $config = require $this->configFile;
            SyncLogger::log('[TOKEN_REFRESH] Config file loaded successfully');
            
            $config['ebay']['user_token'] = $this->userToken;
            $config['ebay']['token_expires_at'] = $this->tokenExpiresAt;
            
            if ($this->refreshToken) {
                $config['ebay']['refresh_token'] = $this->refreshToken;
            }
            
            SyncLogger::log('[TOKEN_REFRESH] Generating config content with var_export...');
            
            $configContent = "<?php\n/**\n * Configuration File\n * Auto-updated by eBay token refresh\n */\n\nreturn " . var_export($config, true) . ";\n";
            
            SyncLogger::log('[TOKEN_REFRESH] Config content generated (length: ' . strlen($configContent) . ')');
            SyncLogger::log('[TOKEN_REFRESH] Writing to file...');
            
            $bytesWritten = file_put_contents($this->configFile, $configContent);
            
            if ($bytesWritten !== false) {
                SyncLogger::log('[TOKEN_REFRESH] Updated tokens saved to config file (' . $bytesWritten . ' bytes written)');
            } else {
                SyncLogger::logWarning('[TOKEN_REFRESH] file_put_contents returned false - check file permissions');
            }
        } catch (\Throwable $e) {
            SyncLogger::logError('[TOKEN_REFRESH] Exception in saveTokensToConfig', $e);
            SyncLogger::log('[TOKEN_REFRESH] Exception type: ' . get_class($e));
            SyncLogger::log('[TOKEN_REFRESH] Exception message: ' . $e->getMessage());
            SyncLogger::log('[TOKEN_REFRESH] Exception trace: ' . $e->getTraceAsString());
            // Don't re-throw - let the outer catch handle it gracefully
        }
    }
    
    /**
     * Get items from eBay store using Trading API GetSellerList
     */
    public function getStoreItems($storeName = 'moto800', $pageNumber = 1, $entriesPerPage = 100, $startDate = null, $endDate = null)
    {
        // Ensure we have a valid token before making API call
        if (!$this->ensureValidToken()) {
            SyncLogger::logError('Cannot make API call - token refresh failed', new \Exception('Invalid or expired token'));
            return null;
        }
        
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
     * Get seller events (changes) using Trading API GetSellerEvents
     * This is optimized for CRON jobs to track incremental changes
     * 
     * @param DateTime|string|null $modTimeFrom Last sync time (default: 48 hours ago)
     * @param DateTime|string|null $modTimeTo End of time range (default: now - 2 minutes)
     * @param int $pageNumber Page number for pagination
     * @param int $entriesPerPage Items per page (max 200)
     * @return array|null Response with items and pagination info
     */
    public function getSellerEvents($modTimeFrom = null, $modTimeTo = null, $pageNumber = 1, $entriesPerPage = 200)
    {
        // Ensure we have a valid token before making API call
        if (!$this->ensureValidToken()) {
            SyncLogger::logError('Cannot make API call - token refresh failed', new \Exception('Invalid or expired token'));
            return null;
        }
        
        $this->rateLimitExceeded = false; // Reset flag
        
        // Default to last 48 hours if no time provided (eBay recommendation)
        if ($modTimeFrom === null) {
            $modTimeFrom = new \DateTime('-48 hours');
        } elseif (is_string($modTimeFrom)) {
            $modTimeFrom = new \DateTime($modTimeFrom);
        }
        
        // Default to now - 2 minutes (eBay recommendation to avoid missing recent changes)
        if ($modTimeTo === null) {
            $modTimeTo = new \DateTime('-2 minutes');
        } elseif (is_string($modTimeTo)) {
            $modTimeTo = new \DateTime($modTimeTo);
        }
        
        // Validate time range (eBay recommends < 48 hours)
        $interval = $modTimeFrom->diff($modTimeTo);
        $hoursDiff = ($interval->days * 24) + $interval->h;
        if ($hoursDiff > 48) {
            SyncLogger::logWarning("GetSellerEvents time range > 48 hours ({$hoursDiff}h). eBay recommends smaller windows.");
        }
        
        // Use Trading API GetSellerEvents
        $url = $this->sandbox ? 'https://api.sandbox.ebay.com/ws/api.dll' : $this->tradingApiUrl;
        
        // Build GetSellerEvents XML request
        $xmlRequest = $this->buildGetSellerEventsRequest($modTimeFrom, $modTimeTo, $pageNumber, $entriesPerPage);
        
        $response = $this->makeTradingApiRequest($url, $xmlRequest, 0, self::RATE_LIMIT_MAX_RETRIES, 'GetSellerEvents');
        
        if ($response && isset($response['ItemArray']['Item'])) {
            return $this->parseTradingApiResponse($response, $pageNumber, $entriesPerPage);
        }
        
        // Handle empty response (no changes in time range)
        if ($response && isset($response['Ack']) && $response['Ack'] === 'Success') {
            return [
                'items' => [],
                'total' => 0,
                'pages' => 0,
                'has_more' => false
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
        $xml .= '<OutputSelector>Description</OutputSelector>';
        $xml .= '<OutputSelector>SKU</OutputSelector>';
        $xml .= '<OutputSelector>PictureDetails</OutputSelector>';
        $xml .= '<OutputSelector>SellingStatus</OutputSelector>';
        $xml .= '<OutputSelector>Quantity</OutputSelector>';
        $xml .= '<OutputSelector>ConditionDisplayName</OutputSelector>';
        $xml .= '<OutputSelector>ListingType</OutputSelector>';
        $xml .= '<OutputSelector>ViewItemURL</OutputSelector>';
        $xml .= '<OutputSelector>PaginationResult</OutputSelector>';
        $xml .= '<OutputSelector>PrimaryCategory</OutputSelector>';
        $xml .= '<OutputSelector>Storefront</OutputSelector>';
        $xml .= '<OutputSelector>ItemSpecifics</OutputSelector>';
        $xml .= '<OutputSelector>ProductListingDetails</OutputSelector>';
        $xml .= '<OutputSelector>Product</OutputSelector>';
        
        $xml .= '</GetSellerListRequest>';
        
        return $xml;
    }
    
    /**
     * Build GetSellerEvents XML request
     */
    private function buildGetSellerEventsRequest(\DateTime $modTimeFrom, \DateTime $modTimeTo, $pageNumber, $entriesPerPage)
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetSellerEventsRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
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
        
        // Time range using ModTimeFrom and ModTimeTo (modification time)
        // Format: ISO 8601 (YYYY-MM-DDTHH:MM:SS.SSSZ)
        $xml .= '<ModTimeFrom>' . $modTimeFrom->format('c') . '</ModTimeFrom>';
        $xml .= '<ModTimeTo>' . $modTimeTo->format('c') . '</ModTimeTo>';
        
        // Include watch count to track listing changes
        $xml .= '<IncludeWatchCount>true</IncludeWatchCount>';
        
        // Output selectors for specific fields
        $xml .= '<OutputSelector>ItemID</OutputSelector>';
        $xml .= '<OutputSelector>Title</OutputSelector>';
        $xml .= '<OutputSelector>Description</OutputSelector>';
        $xml .= '<OutputSelector>SKU</OutputSelector>';
        $xml .= '<OutputSelector>PictureDetails</OutputSelector>';
        $xml .= '<OutputSelector>SellingStatus</OutputSelector>';
        $xml .= '<OutputSelector>Quantity</OutputSelector>';
        $xml .= '<OutputSelector>ConditionDisplayName</OutputSelector>';
        $xml .= '<OutputSelector>ListingType</OutputSelector>';
        $xml .= '<OutputSelector>ViewItemURL</OutputSelector>';
        $xml .= '<OutputSelector>TimeLeft</OutputSelector>';
        $xml .= '<OutputSelector>PaginationResult</OutputSelector>';
        $xml .= '<OutputSelector>PrimaryCategory</OutputSelector>';
        $xml .= '<OutputSelector>Storefront</OutputSelector>';
        $xml .= '<OutputSelector>ItemSpecifics</OutputSelector>';
        $xml .= '<OutputSelector>ProductListingDetails</OutputSelector>';
        $xml .= '<OutputSelector>Product</OutputSelector>';
        
        $xml .= '</GetSellerEventsRequest>';
        
        return $xml;
    }
    
    /**
     * Make Trading API request
     */
    private function makeTradingApiRequest($url, $xmlRequest, $retryCount = 0, $maxRetries = self::RATE_LIMIT_MAX_RETRIES, $callName = 'GetSellerList')
    {
        // Log the request
        SyncLogger::logRequest($url . ' (Trading API ' . $callName . ')');
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
            'X-EBAY-API-CALL-NAME: ' . $callName,
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
                        return $this->makeTradingApiRequest($url, $xmlRequest, $retryCount + 1, $maxRetries, $callName);
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
            // Extract all images from eBay
            $allImages = [];
            if (isset($item['PictureDetails']['PictureURL'])) {
                $pictureUrls = $item['PictureDetails']['PictureURL'];
                // If it's a single URL (string), wrap it in array
                if (is_string($pictureUrls)) {
                    $allImages = [$pictureUrls];
                } else if (is_array($pictureUrls)) {
                    $allImages = $pictureUrls;
                }
            }
            
            // Extract Brand and Model/MPN - try multiple sources
            $brandName = null;
            $modelNumber = null;
            
            // Priority 1: Try ItemSpecifics (most common location)
            if (isset($item['ItemSpecifics']['NameValueList'])) {
                $specifics = $item['ItemSpecifics']['NameValueList'];
                // Handle single specific (not in array)
                if (isset($specifics['Name'])) {
                    $specifics = [$specifics];
                }
                
                foreach ($specifics as $specific) {
                    $name = strtolower($specific['Name'] ?? '');
                    $value = $specific['Value'] ?? '';
                    
                    // Only check for 'brand' field for manufacturer
                    if ($name === 'brand') {
                        $brandName = is_array($value) ? $value[0] : $value;
                    } 
                    // For model: First try 'model', then fall back to 'mpn' or 'manufacturer part number'
                    elseif (!$modelNumber && $name === 'model') {
                        $modelNumber = is_array($value) ? $value[0] : $value;
                    } elseif (!$modelNumber && ($name === 'mpn' || $name === 'manufacturer part number')) {
                        $modelNumber = is_array($value) ? $value[0] : $value;
                    }
                }
            }
            
            // Priority 2: Try ProductListingDetails (eBay Catalog products)
            if (!$brandName && isset($item['ProductListingDetails']['BrandMPN']['Brand'])) {
                $brandName = $item['ProductListingDetails']['BrandMPN']['Brand'];
            }
            if (!$modelNumber && isset($item['ProductListingDetails']['BrandMPN']['MPN'])) {
                $modelNumber = $item['ProductListingDetails']['BrandMPN']['MPN'];
            }
            
            // Priority 3: Try deprecated Product field (older listings)
            if (!$brandName && isset($item['Product']['BrandMPN']['Brand'])) {
                $brandName = $item['Product']['BrandMPN']['Brand'];
            }
            if (!$modelNumber && isset($item['Product']['BrandMPN']['MPN'])) {
                $modelNumber = $item['Product']['BrandMPN']['MPN'];
            }
            
            // Strip ALL HTML, CSS, and JS from description
            $description = $item['Description'] ?? '';
            if ($description) {
                // Strip all HTML tags completely to prevent CSS/JS injection
                $description = strip_tags($description);
                // Decode HTML entities
                $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Remove excessive whitespace and normalize line breaks
                $description = preg_replace('/\s+/', ' ', $description);
                $description = trim($description);
            }
            
            // Log brand/mpn extraction for debugging
            $itemId = $item['ItemID'] ?? 'unknown';
            $itemTitle = $item['Title'] ?? 'Untitled';
            if ($brandName || $modelNumber) {
                error_log("[eBay Sync Debug] Item: $itemId - Title: $itemTitle");
                error_log("[eBay Sync Debug]   Brand: " . ($brandName ? $brandName : 'NULL'));
                error_log("[eBay Sync Debug]   MPN: " . ($modelNumber ? $modelNumber : 'NULL'));
            }
            
            $items[] = [
                'id' => $itemId,
                'title' => $itemTitle,
                'description' => $description,
                'sku' => $item['SKU'] ?? '',
                'price' => $item['SellingStatus']['CurrentPrice'] ?? '0',
                'currency' => 'USD',
                'image' => !empty($allImages) ? $allImages[0] : null,
                'images' => $allImages,
                'url' => $item['ViewItemURL'] ?? '',
                'condition' => $item['ConditionDisplayName'] ?? 'Used',
                'location' => '',
                'shipping_cost' => 0,
                'ebay_category_id' => $item['PrimaryCategory']['CategoryID'] ?? null,
                'ebay_category_name' => $item['PrimaryCategory']['CategoryName'] ?? null,
                'store_category_id' => $item['Storefront']['StoreCategoryID'] ?? null,
                'store_category2_id' => $item['Storefront']['StoreCategory2ID'] ?? null,
                'brand' => $brandName,
                'mpn' => $modelNumber,
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
    
    /**
     * Get store categories from eBay using GetStore API
     * Returns a hierarchical structure of store categories
     */
    public function getStoreCategories()
    {
        // Return cached categories if available
        if ($this->storeCategoriesCache !== null) {
            return $this->storeCategoriesCache;
        }
        
        // Ensure we have a valid token before making API call
        if (!$this->ensureValidToken()) {
            SyncLogger::logError('Cannot make API call - token refresh failed', new \Exception('Invalid or expired token'));
            return null;
        }
        
        SyncLogger::log("Fetching store categories from eBay");
        
        $url = $this->sandbox ? 'https://api.sandbox.ebay.com/ws/api.dll' : $this->tradingApiUrl;
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetStoreRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml .= '<RequesterCredentials>';
        $xml .= '<eBayAuthToken>' . htmlspecialchars($this->userToken) . '</eBayAuthToken>';
        $xml .= '</RequesterCredentials>';
        $xml .= '<Version>967</Version>';
        $xml .= '<ErrorLanguage>en_US</ErrorLanguage>';
        $xml .= '<WarningLevel>High</WarningLevel>';
        $xml .= '<CategoryStructureOnly>true</CategoryStructureOnly>';
        $xml .= '</GetStoreRequest>';
        
        $response = $this->makeTradingApiRequest($url, $xml, 0, 1, 'GetStore');
        
        if ($response && isset($response['Store']['CustomCategories']['CustomCategory'])) {
            $categories = $this->parseStoreCategories($response['Store']['CustomCategories']['CustomCategory']);
            $this->storeCategoriesCache = $categories;
            SyncLogger::log("Retrieved " . count($categories) . " store categories");
            return $categories;
        }
        
        SyncLogger::log("No store categories found");
        $this->storeCategoriesCache = [];
        return [];
    }
    
    /**
     * Parse store categories into a flat map for easy lookup
     * Returns array: CategoryID => ['name' => name, 'level' => level, 'parent' => parentName]
     */
    private function parseStoreCategories($categoriesData)
    {
        $categoryMap = [];
        
        // Handle single category (not in array)
        if (isset($categoriesData['CategoryID'])) {
            $categoriesData = [$categoriesData];
        }
        
        // Parse top-level categories (level 1 - website category mapping)
        foreach ($categoriesData as $topLevel) {
            $topCategoryId = $topLevel['CategoryID'] ?? null;
            $topCategoryName = $topLevel['Name'] ?? '';
            
            if ($topCategoryId) {
                $categoryMap[$topCategoryId] = [
                    'name' => $topCategoryName,
                    'level' => 1,
                    'parent' => null,
                    'topLevel' => $topCategoryName
                ];
                
                // Parse level 2 categories (manufacturer)
                if (isset($topLevel['ChildCategory'])) {
                    $level2Categories = $topLevel['ChildCategory'];
                    if (isset($level2Categories['CategoryID'])) {
                        $level2Categories = [$level2Categories];
                    }
                    
                    foreach ($level2Categories as $level2) {
                        $level2Id = $level2['CategoryID'] ?? null;
                        $level2Name = $level2['Name'] ?? '';
                        
                        if ($level2Id) {
                            $categoryMap[$level2Id] = [
                                'name' => $level2Name,
                                'level' => 2,
                                'parent' => $topCategoryName,
                                'topLevel' => $topCategoryName
                            ];
                            
                            // Parse level 3 categories (model)
                            if (isset($level2['ChildCategory'])) {
                                $level3Categories = $level2['ChildCategory'];
                                if (isset($level3Categories['CategoryID'])) {
                                    $level3Categories = [$level3Categories];
                                }
                                
                                foreach ($level3Categories as $level3) {
                                    $level3Id = $level3['CategoryID'] ?? null;
                                    $level3Name = $level3['Name'] ?? '';
                                    
                                    if ($level3Id) {
                                        $categoryMap[$level3Id] = [
                                            'name' => $level3Name,
                                            'level' => 3,
                                            'parent' => $level2Name,
                                            'topLevel' => $topCategoryName
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $categoryMap;
    }
    
    /**
     * Map eBay store top-level category to website category
     */
    private function mapStoreCategoryToWebsite($storeCategoryName)
    {
        $normalized = strtoupper(trim($storeCategoryName));
        
        // Direct mapping from eBay store categories to website categories
        $mappings = [
            'MOTORCYCLE' => 'motorcycle',
            'ATV 4 WHEELER / ATC 3 WHEELER' => 'atv',
            'DIRT BIKE / MOTOCROSS' => 'motorcycle',
            'MARINE / BOAT PARTS' => 'boat',
            'PWC - PERSONAL WATER CRAFT' => 'boat',
            'WATCHES / BIKER GIFTS' => 'gifts',
            'CAR & TRUCK PARTS CLEARANCE' => 'automotive',
            'SNOWMOBILE' => 'other',
            'OTHER' => 'other'
        ];
        
        // Check for exact match first
        if (isset($mappings[$normalized])) {
            return $mappings[$normalized];
        }
        
        // Fallback: check partial matches
        foreach ($mappings as $storeCategory => $websiteCategory) {
            if (strpos($normalized, $storeCategory) !== false || strpos($storeCategory, $normalized) !== false) {
                return $websiteCategory;
            }
        }
        
        // Default to 'other' if no match found
        return 'other';
    }
    
    /**
     * Extract website category, manufacturer and model from store category hierarchy
     * Mapping: Level 1 (website category) -> Level 2 (manufacturer) -> Level 3 (model)
     */
    public function extractCategoryMfgModelFromStoreCategory($storeCategoryId)
    {
        if (!$storeCategoryId) {
            return ['category' => null, 'manufacturer' => null, 'model' => null];
        }
        
        $categories = $this->getStoreCategories();
        
        if (!isset($categories[$storeCategoryId])) {
            return ['category' => null, 'manufacturer' => null, 'model' => null];
        }
        
        $category = $categories[$storeCategoryId];
        $topLevelName = $category['topLevel'] ?? null;
        $websiteCategory = $topLevelName ? $this->mapStoreCategoryToWebsite($topLevelName) : null;
        
        // Level 1 = website category only
        if ($category['level'] == 1) {
            return [
                'category' => $websiteCategory,
                'manufacturer' => null,
                'model' => null
            ];
        }
        // Level 2 = website category + manufacturer
        elseif ($category['level'] == 2) {
            return [
                'category' => $websiteCategory,
                'manufacturer' => $category['name'],
                'model' => null
            ];
        }
        // Level 3 = website category + manufacturer (parent) + model
        elseif ($category['level'] == 3) {
            return [
                'category' => $websiteCategory,
                'manufacturer' => $category['parent'], // Level 2 parent is manufacturer
                'model' => $category['name']
            ];
        }
        
        // Fallback
        return ['category' => $websiteCategory, 'manufacturer' => null, 'model' => null];
    }
    
    /**
     * Extract manufacturer and model from store category hierarchy (backward compatibility)
     * @deprecated Use extractCategoryMfgModelFromStoreCategory() instead
     */
    public function extractMfgModelFromStoreCategory($storeCategoryId)
    {
        $result = $this->extractCategoryMfgModelFromStoreCategory($storeCategoryId);
        return [
            'manufacturer' => $result['manufacturer'],
            'model' => $result['model']
        ];
    }
    
    /**
     * Get detailed item information using GetItem API
     * This reliably returns ItemSpecifics including Brand and MPN
     */
    public function getItemDetails($itemId)
    {
        SyncLogger::log("[GetItem Debug] Fetching detailed item info for ItemID: {$itemId}");
        
        // Build GetItem XML request
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml .= '<RequesterCredentials>';
        $xml .= '<eBayAuthToken>' . htmlspecialchars($this->userToken) . '</eBayAuthToken>';
        $xml .= '</RequesterCredentials>';
        $xml .= '<ItemID>' . htmlspecialchars($itemId) . '</ItemID>';
        $xml .= '<DetailLevel>ItemReturnDescription</DetailLevel>';
        $xml .= '<IncludeItemSpecifics>true</IncludeItemSpecifics>';
        $xml .= '</GetItemRequest>';
        
        $url = $this->sandbox ? 'https://api.sandbox.ebay.com/ws/api.dll' : 'https://api.ebay.com/ws/api.dll';
        
        $response = $this->makeTradingApiRequest($url, $xml, 0, self::RATE_LIMIT_MAX_RETRIES, 'GetItem');
        
        if (!$response || !isset($response['Item'])) {
            SyncLogger::log("[GetItem Debug] No item data returned for ItemID: {$itemId}");
            return null;
        }
        
        $item = $response['Item'];
        
        // Extract Brand and MPN from ItemSpecifics
        $brand = null;
        $mpn = null;
        
        if (isset($item['ItemSpecifics']['NameValueList'])) {
            $specifics = $item['ItemSpecifics']['NameValueList'];
            
            // Handle single specific vs array of specifics
            if (isset($specifics['Name'])) {
                $specifics = [$specifics];
            }
            
            foreach ($specifics as $specific) {
                $name = strtolower($specific['Name'] ?? '');
                $value = $specific['Value'] ?? null;
                
                if ($name === 'brand' && !$brand) {
                    $brand = $value;
                } elseif (in_array($name, ['mpn', 'model', 'manufacturer part number']) && !$mpn) {
                    $mpn = $value;
                }
            }
        }
        
        // Also check ProductListingDetails as fallback
        if (!$brand && isset($item['ProductListingDetails']['BrandMPN']['Brand'])) {
            $brand = $item['ProductListingDetails']['BrandMPN']['Brand'];
        }
        if (!$mpn && isset($item['ProductListingDetails']['BrandMPN']['MPN'])) {
            $mpn = $item['ProductListingDetails']['BrandMPN']['MPN'];
        }
        
        // Also check deprecated Product field as fallback
        if (!$brand && isset($item['Product']['BrandMPN']['Brand'])) {
            $brand = $item['Product']['BrandMPN']['Brand'];
        }
        if (!$mpn && isset($item['Product']['BrandMPN']['MPN'])) {
            $mpn = $item['Product']['BrandMPN']['MPN'];
        }
        
        // Extract dimensions and weight from ShipPackageDetails
        $weight = null;
        $length = null;
        $width = null;
        $height = null;
        
        if (isset($item['ShippingPackageDetails'])) {
            $packageDetails = $item['ShippingPackageDetails'];
            
            // Weight (convert to pounds if needed)
            if (isset($packageDetails['WeightMajor']) || isset($packageDetails['WeightMinor'])) {
                $weightMajor = floatval($packageDetails['WeightMajor']['__value__'] ?? $packageDetails['WeightMajor'] ?? 0);
                $weightMinor = floatval($packageDetails['WeightMinor']['__value__'] ?? $packageDetails['WeightMinor'] ?? 0);
                $measurementUnit = $packageDetails['WeightMajor']['@unit'] ?? $packageDetails['MeasurementUnit'] ?? 'lbs';
                
                // Convert to total weight (major + minor/16 for lbs/oz)
                if ($measurementUnit === 'lbs' || $measurementUnit === 'oz') {
                    $weight = $weightMajor + ($weightMinor / 16); // Convert ounces to pounds
                } else {
                    $weight = $weightMajor; // For kg or other units, use as-is
                }
            }
            
            // Dimensions (inches)
            if (isset($packageDetails['PackageDepth'])) {
                $length = floatval($packageDetails['PackageDepth']['__value__'] ?? $packageDetails['PackageDepth'] ?? 0);
            }
            if (isset($packageDetails['PackageWidth'])) {
                $width = floatval($packageDetails['PackageWidth']['__value__'] ?? $packageDetails['PackageWidth'] ?? 0);
            }
            if (isset($packageDetails['PackageLength'])) {
                $height = floatval($packageDetails['PackageLength']['__value__'] ?? $packageDetails['PackageLength'] ?? 0);
            }
        }
        
        SyncLogger::log("[GetItem Debug] Extracted - Brand: " . ($brand ?? 'NULL') . ", MPN: " . ($mpn ?? 'NULL'));
        SyncLogger::log("[GetItem Debug] Dimensions - Weight: " . ($weight ?? 'NULL') . " lbs, L: " . ($length ?? 'NULL') . ", W: " . ($width ?? 'NULL') . ", H: " . ($height ?? 'NULL') . " inches");
        
        // Extract description and strip ALL HTML, CSS, and JS
        $description = $item['Description'] ?? '';
        if ($description) {
            // Strip all HTML tags completely to prevent CSS/JS injection
            $description = strip_tags($description);
            // Decode HTML entities
            $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Remove excessive whitespace and normalize line breaks
            $description = preg_replace('/\s+/', ' ', $description);
            $description = trim($description);
        }
        
        // Extract SKU
        $sku = $item['SKU'] ?? '';
        
        // Extract all images from eBay
        $allImages = [];
        if (isset($item['PictureDetails']['PictureURL'])) {
            $pictureUrls = $item['PictureDetails']['PictureURL'];
            // If it's a single URL (string), wrap it in array
            if (is_string($pictureUrls)) {
                $allImages = [$pictureUrls];
            } elseif (is_array($pictureUrls)) {
                $allImages = $pictureUrls;
            }
        }
        
        return [
            'brand' => $brand,
            'mpn' => $mpn,
            'weight' => $weight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'description' => $description,
            'sku' => $sku,
            'images' => $allImages,
            'image' => !empty($allImages) ? $allImages[0] : null,
            'item' => $item // Return full item data for other uses if needed
        ];
    }
}
