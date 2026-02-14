#!/usr/bin/env php
<?php
/**
 * Cron Job Script for Automated eBay Synchronization
 * 
 * Uses GetSellerEvents to track incremental changes efficiently
 * Only syncs items that have changed since the last successful sync
 * 
 * Schedule this to run regularly (e.g., every hour or every 6 hours)
 * 
 * Crontab examples:
 * Run every hour
 * 0 * * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
 * 
 * Run every 6 hours
 * 0 0,6,12,18 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
 * 
 * Run daily at 2 AM
 * 0 2 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
 */

// Change to script directory
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/SyncLogger.php';
require_once __DIR__ . '/../src/integrations/EbayAPI.php';
require_once __DIR__ . '/../src/models/Product.php';

use FAS\Config\Database;
use FAS\Utils\SyncLogger;
use FAS\Integrations\EbayAPI;
use FAS\Models\Product;

// Log start
echo "[" . date('Y-m-d H:i:s') . "] Starting eBay synchronization (GetSellerEvents)...\n";

// Initialize SyncLogger
SyncLogger::init();

try {
    $db = Database::getInstance()->getConnection();
    
    // Load config for store name and to pass to EbayAPI
    $configFile = __DIR__ . '/../src/config/config.php';
    if (!file_exists($configFile) || !is_readable($configFile)) {
        throw new Exception("Config file not found or not readable: $configFile");
    }
    $config = require $configFile;
    $storeName = $config['ebay']['store_name'] ?? 'moto800';
    
    // Initialize EbayAPI with config and config file path
    $ebayAPI = new EbayAPI($config, $configFile);
    $productModel = new Product($db);
    
    // Get last successful sync timestamp
    $stmt = $db->prepare("
        SELECT last_sync_timestamp 
        FROM ebay_sync_log 
        WHERE status = 'completed' AND last_sync_timestamp IS NOT NULL
        ORDER BY completed_at DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $lastSync = $stmt->fetchColumn();
    
    // Determine time range for GetSellerEvents
    // Always define modTimeTo for timestamp consistency
    // Use 2-minute buffer as recommended by eBay to avoid missing recent changes during processing
    $modTimeTo = new DateTime('-2 minutes');
    
    if ($lastSync) {
        // Incremental sync - use GetSellerEvents to track changes since last sync
        $modTimeFrom = new DateTime($lastSync);
        $modTimeFrom->modify('-2 minutes');
        echo "[" . date('Y-m-d H:i:s') . "] Incremental sync: changes since " . $modTimeFrom->format('Y-m-d H:i:s') . "\n";
    } else {
        // First sync or after purge - use GetSellerEvents with 120-day window
        // eBay listings are renewed every 30 days, so 120 days captures all active listings
        $modTimeFrom = new DateTime('-120 days');
        echo "[" . date('Y-m-d H:i:s') . "] Full sync: fetching all events from last 120 days\n";
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Sync time range: " . $modTimeFrom->format('Y-m-d H:i:s') . " to " . $modTimeTo->format('Y-m-d H:i:s') . "\n";
    
    // Start sync log
    $syncType = !$lastSync ? 'scheduled_full_sync' : 'scheduled_events_sync';
    $stmt = $db->prepare("INSERT INTO ebay_sync_log (sync_type, status) VALUES (?, 'running')");
    $stmt->execute([$syncType]);
    $syncLogId = $db->lastInsertId();
    
    $page = 1;
    $totalProcessed = 0;
    $totalAdded = 0;
    $totalUpdated = 0;
    $totalFailed = 0;
    $totalHidden = 0;
    
    // Fetch items from eBay using GetSellerEvents
    // This works for both full and incremental sync based on the time range
    do {
        $result = $ebayAPI->getSellerEvents($modTimeFrom, $modTimeTo, $page, 200);
        
        if (!$result || empty($result['items'])) {
            if ($page == 1) {
                if (!$lastSync) {
                    echo "[" . date('Y-m-d H:i:s') . "] No events found in 120-day range\n";
                } else {
                    echo "[" . date('Y-m-d H:i:s') . "] No changes detected in time range\n";
                }
            }
            break;
        }
        
        $itemLabel = !$lastSync ? "items" : "changed items";
        echo "[" . date('Y-m-d H:i:s') . "] Processing page {$page} with " . count($result['items']) . " {$itemLabel}...\n";
        
        // Handle inactive items - returned by GetSellerEvents
        if (!empty($result['inactive_item_ids'])) {
            echo "[" . date('Y-m-d H:i:s') . "] Found " . count($result['inactive_item_ids']) . " inactive items to hide...\n";
            foreach ($result['inactive_item_ids'] as $inactiveItemId) {
                try {
                    if ($productModel->hideByEbayId($inactiveItemId)) {
                        $totalHidden++;
                        echo "[" . date('Y-m-d H:i:s') . "] Hidden inactive item {$inactiveItemId} from website\n";
                    }
                } catch (Exception $e) {
                    echo "[ERROR] Failed to hide inactive item {$inactiveItemId}: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // Pre-fetch existing products in batch to reduce database queries
        $itemIds = array_column($result['items'], 'id');
        $existingProducts = [];
        if (!empty($itemIds)) {
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            $stmt = $db->prepare("SELECT id, ebay_item_id FROM products WHERE ebay_item_id IN ($placeholders)");
            $stmt->execute($itemIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existingProducts[$row['ebay_item_id']] = $row;
            }
        }
        
        foreach ($result['items'] as $item) {
            try {
                $existing = $existingProducts[$item['id']] ?? null;
                
                if ($existing) {
                    $productModel->syncFromEbay($item, $ebayAPI);
                    $totalUpdated++;
                } else {
                    $productModel->syncFromEbay($item, $ebayAPI);
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
        
        // Brief delay between pages only if processing many items
        // (to avoid rate limiting if GetItem calls were made)
        if (count($result['items']) > 50) {
            usleep(500000); // 0.5 seconds for smaller batches
        }
        
    } while (true);
    
    // Complete sync log with timestamp
    // Use modTimeTo for both sync types to ensure consistency
    $stmt = $db->prepare("
        UPDATE ebay_sync_log 
        SET status = 'completed', completed_at = datetime('now'), last_sync_timestamp = ?
        WHERE id = ?
    ");
    $stmt->execute([$modTimeTo->format('Y-m-d H:i:s'), $syncLogId]);
    
    echo "[" . date('Y-m-d H:i:s') . "] Synchronization completed successfully!\n";
    echo "  Processed: {$totalProcessed}\n";
    echo "  Added: {$totalAdded}\n";
    echo "  Updated: {$totalUpdated}\n";
    echo "  Failed: {$totalFailed}\n";
    echo "  Hidden: {$totalHidden}\n";
    
    SyncLogger::finalize();
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
    SyncLogger::finalize();
    exit(1);
}
