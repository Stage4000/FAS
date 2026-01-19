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
    
    // API Endpoints
    private $findingApiUrl = 'https://svcs.ebay.com/services/search/FindingService/v1';
    private $shoppingApiUrl = 'https://open.api.ebay.com/shopping';
    
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
     * Make HTTP request to eBay API
     */
    private function makeRequest($url)
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
                error_log('eBay API Error Response: ' . json_encode($data['errorMessage']));
                return null;
            }
            
            return $data;
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
