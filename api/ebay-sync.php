<?php
/**
 * eBay Sync API Endpoint
 * Syncs products from eBay store to local database
 */

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/integrations/EbayAPI.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/utils/SyncLogger.php';

use FAS\Config\Database;
use FAS\Integrations\EbayAPI;
use FAS\Models\Product;
use FAS\Utils\SyncLogger;

// Initialize comprehensive logging to log.txt
SyncLogger::init(__DIR__ . '/../log.txt');

// Set up PHP error handler to log all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    SyncLogger::logPhpError($errno, $errstr, $errfile, $errline);
    // Return false to continue with normal error handling
    return false;
});

// Set up exception handler
set_exception_handler(function($exception) {
    SyncLogger::logError('Uncaught exception', $exception);
});

// Log script start
SyncLogger::log("eBay Sync API endpoint called");

header('Content-Type: application/json');

// Simple authentication check
// Load API key from config
$config = require __DIR__ . '/../src/config/config.php';
$expectedKey = $config['security']['sync_api_key'] ?? 'fas_sync_key_2026';

// Warn if using default key in production
if ($expectedKey === 'fas_sync_key_2026') {
    error_log("WARNING: Default sync API key is being used. Change this in admin settings for better security.");
}

if ($authKey !== $expectedKey) {
    SyncLogger::log("Authentication failed - invalid key provided");
    http_response_code(401);
    echo json_encode([
        'error' => 'Unauthorized',
        'message' => 'Invalid sync API key provided.',
        'help' => 'The sync key is configured in Settings > Security Settings > Sync API Key. Use that key in the URL: /api/ebay-sync.php?key=YOUR_KEY'
    ]);
    SyncLogger::finalize();
    exit;
}

SyncLogger::log("Authentication successful");

// Get date parameters from query string, default to last 120 days
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-120 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    SyncLogger::log("Invalid date format provided");
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid date format',
        'message' => 'Dates must be in YYYY-MM-DD format'
    ]);
    SyncLogger::finalize();
    exit;
}

// Validate date range is less than 120 days
$start = new DateTime($startDate);
$end = new DateTime($endDate);
$interval = $start->diff($end);
$daysDiff = $interval->days;

if ($daysDiff > 120) {
    SyncLogger::log("Date range exceeds 120 days: $daysDiff days");
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid date range',
        'message' => 'Date range must be less than 120 days (eBay requirement). Your range: ' . $daysDiff . ' days'
    ]);
    SyncLogger::finalize();
    exit;
}

if ($end < $start) {
    SyncLogger::log("End date is before start date");
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid date range',
        'message' => 'End date must be after start date'
    ]);
    SyncLogger::finalize();
    exit;
}

SyncLogger::log("Date range validated: $startDate to $endDate ($daysDiff days)");

try {
    $db = Database::getInstance()->getConnection();
    SyncLogger::log("Database connection established");
    
    // Load config to check credentials
    $config = require __DIR__ . '/../src/config/config.php';
    
    // Validate eBay credentials are configured
    if ($config['ebay']['app_id'] === 'YOUR_EBAY_APP_ID' || 
        strpos($config['ebay']['app_id'], 'YOUR_') === 0) {
        SyncLogger::log("eBay API credentials not configured");
        http_response_code(400);
        echo json_encode([
            'error' => 'eBay API credentials not configured',
            'message' => 'Please configure your eBay API credentials in the Settings page before syncing.',
            'help' => 'Visit /admin/ebay-token-guide.php for instructions on obtaining eBay credentials.'
        ]);
        SyncLogger::finalize();
        exit;
    }
    
    SyncLogger::log("eBay API credentials validated");
    
    $ebayAPI = new EbayAPI($config);
    $productModel = new Product($db);
    
    // Start sync log
    $stmt = $db->prepare("INSERT INTO ebay_sync_log (sync_type, status) VALUES ('full_sync', 'running')");
    $stmt->execute();
    $syncLogId = $db->lastInsertId();
    
    SyncLogger::log("Sync log created with ID: $syncLogId");
    
    $page = 1;
    $totalProcessed = 0;
    $totalAdded = 0;
    $totalUpdated = 0;
    $totalFailed = 0;
    
    // Fetch items from eBay
    do {
        SyncLogger::log("Fetching page $page from eBay store");
        $result = $ebayAPI->getStoreItems('moto800', $page, 100, $startDate, $endDate);
        
        if (!$result || empty($result['items'])) {
            // Log if no results on first page
            if ($page === 1) {
                // Check if this was due to rate limiting
                if ($ebayAPI->wasRateLimited()) {
                    error_log('eBay Sync: Rate limit exceeded. Please wait and try again later.');
                    SyncLogger::logError('Rate limit exceeded on first page');
                    
                    $totalWaitTime = $ebayAPI->getTotalRetryWaitTime();
                    
                    // Update sync log with error
                    $stmt = $db->prepare("
                        UPDATE ebay_sync_log 
                        SET status = 'failed', 
                            error_message = 'eBay API rate limit exceeded. Please wait 5-10 minutes before trying again.',
                            completed_at = datetime('now')
                        WHERE id = ?
                    ");
                    $stmt->execute([$syncLogId]);
                    
                    http_response_code(429); // Too Many Requests
                    echo json_encode([
                        'error' => 'Rate limit exceeded',
                        'message' => "eBay API rate limit has been exceeded. The sync tried 3 times with exponential backoff ({$totalWaitTime} seconds total) but the limit persists.",
                        'help' => 'Please wait 5-10 minutes before trying to sync again. eBay limits API calls to prevent abuse.',
                        'next_action' => 'Wait a few minutes and click "Start eBay Sync" again.'
                    ]);
                    SyncLogger::finalize();
                    exit;
                }
                
                error_log('eBay Sync: No items found. Check eBay credentials and store name.');
                SyncLogger::logError('No items found on first page');
                
                // Update sync log with error
                $stmt = $db->prepare("
                    UPDATE ebay_sync_log 
                    SET status = 'failed', 
                        error_message = 'No items found. Check eBay API credentials and store name.',
                        completed_at = datetime('now')
                    WHERE id = ?
                ");
                $stmt->execute([$syncLogId]);
                
                http_response_code(400);
                echo json_encode([
                    'error' => 'No items found',
                    'message' => 'Unable to fetch items from eBay. This may be due to invalid credentials, incorrect store name, or the store having no items.',
                    'help' => 'Visit /admin/ebay-token-guide.php for help with eBay API configuration.'
                ]);
                SyncLogger::finalize();
                exit;
            }
            SyncLogger::log("No more items found, ending pagination at page $page");
            break;
        }
        
        SyncLogger::log("Retrieved " . count($result['items']) . " items from page $page");
        
        foreach ($result['items'] as $item) {
            try {
                $existing = $productModel->getByEbayId($item['id']);
                
                if ($existing) {
                    $productModel->syncFromEbay($item);
                    $totalUpdated++;
                    SyncLogger::log("Updated item: {$item['id']} - {$item['title']}");
                } else {
                    $productModel->syncFromEbay($item);
                    $totalAdded++;
                    SyncLogger::log("Added new item: {$item['id']} - {$item['title']}");
                }
                
                $totalProcessed++;
            } catch (Exception $e) {
                $totalFailed++;
                error_log('Failed to sync item ' . $item['id'] . ': ' . $e->getMessage());
                SyncLogger::logError('Failed to sync item ' . $item['id'], $e);
            }
        }
        
        $page++;
        
        // Update sync log progress
        $stmt = $db->prepare("
            UPDATE ebay_sync_log 
            SET items_processed = ?, items_added = ?, items_updated = ?, items_failed = ?
            WHERE id = ?
        ");
        $stmt->execute([$totalProcessed, $totalAdded, $totalUpdated, $totalFailed, $syncLogId]);
        
        SyncLogger::log("Progress: Processed=$totalProcessed, Added=$totalAdded, Updated=$totalUpdated, Failed=$totalFailed");
        
        // Break after processing all pages
        if ($page > $result['pages']) {
            break;
        }
        
        // Delay between pages to avoid rate limiting
        // eBay Finding API allows 5000 calls per day per app
        sleep(2);
        
    } while (true);
    
    // Complete sync log
    $stmt = $db->prepare("
        UPDATE ebay_sync_log 
        SET status = 'completed', completed_at = datetime('now')
        WHERE id = ?
    ");
    $stmt->execute([$syncLogId]);
    
    SyncLogger::log("Sync completed successfully");
    SyncLogger::log("Final stats: Processed=$totalProcessed, Added=$totalAdded, Updated=$totalUpdated, Failed=$totalFailed");
    
    echo json_encode([
        'success' => true,
        'processed' => $totalProcessed,
        'added' => $totalAdded,
        'updated' => $totalUpdated,
        'failed' => $totalFailed
    ]);
    
    SyncLogger::finalize();
    
} catch (Exception $e) {
    // Update sync log with error
    if (isset($syncLogId)) {
        $stmt = $db->prepare("
            UPDATE ebay_sync_log 
            SET status = 'failed', error_message = ?, completed_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $syncLogId]);
    }
    
    SyncLogger::logError('Sync failed with exception', $e);
    
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    
    SyncLogger::finalize();
}
