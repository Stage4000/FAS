<?php
/**
 * eBay Sync API Endpoint
 * Syncs products from eBay store to local database
 */

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/integrations/EbayAPI.php';
require_once __DIR__ . '/../src/models/Product.php';

use FAS\Config\Database;
use FAS\Integrations\EbayAPI;
use FAS\Models\Product;

header('Content-Type: application/json');

// Simple authentication check
$authKey = $_GET['key'] ?? '';
$expectedKey = 'fas_sync_key_2026'; // Should be in config

if ($authKey !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Load config to check credentials
    $config = require __DIR__ . '/../src/config/config.php';
    
    // Validate eBay credentials are configured
    if ($config['ebay']['app_id'] === 'YOUR_EBAY_APP_ID' || 
        strpos($config['ebay']['app_id'], 'YOUR_') === 0) {
        http_response_code(400);
        echo json_encode([
            'error' => 'eBay API credentials not configured',
            'message' => 'Please configure your eBay API credentials in the Settings page before syncing.',
            'help' => 'Visit /admin/ebay-token-guide.php for instructions on obtaining eBay credentials.'
        ]);
        exit;
    }
    
    $ebayAPI = new EbayAPI($config);
    $productModel = new Product($db);
    
    // Start sync log
    $stmt = $db->prepare("INSERT INTO ebay_sync_log (sync_type, status) VALUES ('full_sync', 'running')");
    $stmt->execute();
    $syncLogId = $db->lastInsertId();
    
    $page = 1;
    $totalProcessed = 0;
    $totalAdded = 0;
    $totalUpdated = 0;
    $totalFailed = 0;
    
    // Fetch items from eBay
    do {
        $result = $ebayAPI->getStoreItems('moto800', $page, 100);
        
        if (!$result || empty($result['items'])) {
            // Log if no results on first page
            if ($page === 1) {
                error_log('eBay Sync: No items found. Check eBay credentials and store name.');
                
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
                    'message' => 'Unable to fetch items from eBay. This may be due to invalid credentials, incorrect store name, or API rate limits.',
                    'help' => 'Visit /admin/ebay-token-guide.php for help with eBay API configuration.'
                ]);
                exit;
            }
            break;
        }
        
        foreach ($result['items'] as $item) {
            try {
                $existing = $productModel->getByEbayId($item['id']);
                
                if ($existing) {
                    $productModel->syncFromEbay($item);
                    $totalUpdated++;
                } else {
                    $productModel->syncFromEbay($item);
                    $totalAdded++;
                }
                
                $totalProcessed++;
            } catch (Exception $e) {
                $totalFailed++;
                error_log('Failed to sync item ' . $item['id'] . ': ' . $e->getMessage());
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
        
        // Break after processing all pages
        if ($page > $result['pages']) {
            break;
        }
        
    } while (true);
    
    // Complete sync log
    $stmt = $db->prepare("
        UPDATE ebay_sync_log 
        SET status = 'completed', completed_at = datetime('now')
        WHERE id = ?
    ");
    $stmt->execute([$syncLogId]);
    
    echo json_encode([
        'success' => true,
        'processed' => $totalProcessed,
        'added' => $totalAdded,
        'updated' => $totalUpdated,
        'failed' => $totalFailed
    ]);
    
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
    
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
