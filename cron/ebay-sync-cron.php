#!/usr/bin/env php
<?php
/**
 * Cron Job Script for Automated eBay Synchronization
 * 
 * Schedule this to run regularly (e.g., every hour or daily)
 * 
 * Crontab examples:
 * # Run every hour
 * 0 * * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
 * 
 * # Run every 6 hours
 * 0 */6 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
 * 
 * # Run daily at 2 AM
 * 0 2 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
 */

// Change to script directory
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/integrations/EbayAPI.php';
require_once __DIR__ . '/../src/models/Product.php';

use FAS\Config\Database;
use FAS\Integrations\EbayAPI;
use FAS\Models\Product;

// Log start
echo "[" . date('Y-m-d H:i:s') . "] Starting eBay synchronization...\n";

try {
    $db = Database::getInstance()->getConnection();
    $ebayAPI = new EbayAPI();
    $productModel = new Product($db);
    
    // Start sync log
    $stmt = $db->prepare("INSERT INTO ebay_sync_log (sync_type, status) VALUES ('scheduled_sync', 'running')");
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
            break;
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] Processing page {$page} with " . count($result['items']) . " items...\n";
        
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
                echo "[ERROR] Failed to sync item " . $item['id'] . ": " . $e->getMessage() . "\n";
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
        
        // Small delay to avoid rate limiting
        sleep(1);
        
    } while (true);
    
    // Complete sync log
    $stmt = $db->prepare("
        UPDATE ebay_sync_log 
        SET status = 'completed', completed_at = datetime('now')
        WHERE id = ?
    ");
    $stmt->execute([$syncLogId]);
    
    echo "[" . date('Y-m-d H:i:s') . "] Synchronization completed successfully!\n";
    echo "  Processed: {$totalProcessed}\n";
    echo "  Added: {$totalAdded}\n";
    echo "  Updated: {$totalUpdated}\n";
    echo "  Failed: {$totalFailed}\n";
    
    exit(0);
    
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
    
    echo "[ERROR] Synchronization failed: " . $e->getMessage() . "\n";
    exit(1);
}
