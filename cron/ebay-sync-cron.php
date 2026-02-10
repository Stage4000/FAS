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
    $ebayAPI = new EbayAPI();
    $productModel = new Product($db);
    
    // Load config to get store name
    $configFile = __DIR__ . '/../src/config/config.php';
    if (!file_exists($configFile) || !is_readable($configFile)) {
        throw new Exception("Config file not found or not readable: $configFile");
    }
    $config = require $configFile;
    $storeName = $config['ebay']['store_name'] ?? 'moto800';
    
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
    
    // Determine sync strategy based on whether we have a last sync timestamp
    $useFullSync = !$lastSync; // Use full sync if no previous sync exists
    
    // Always define modTimeTo for timestamp consistency
    $modTimeTo = new DateTime('-2 minutes');
    
    if ($lastSync) {
        // Incremental sync - use GetSellerEvents to track changes
        $modTimeFrom = new DateTime($lastSync);
        $modTimeFrom->modify('-2 minutes');
        echo "[" . date('Y-m-d H:i:s') . "] Incremental sync: changes since " . $modTimeFrom->format('Y-m-d H:i:s') . "\n";
    } else {
        // First sync or after purge - use GetSellerList to import ALL active listings
        $startDate = (new DateTime('-120 days'))->format('Y-m-d');
        $endDate = (new DateTime())->format('Y-m-d');
        echo "[" . date('Y-m-d H:i:s') . "] Full sync: fetching all active listings (last 120 days)\n";
    }
    
    // Start sync log
    $syncType = $useFullSync ? 'scheduled_full_sync' : 'scheduled_events_sync';
    $stmt = $db->prepare("INSERT INTO ebay_sync_log (sync_type, status) VALUES (?, 'running')");
    $stmt->execute([$syncType]);
    $syncLogId = $db->lastInsertId();
    
    $page = 1;
    $totalProcessed = 0;
    $totalAdded = 0;
    $totalUpdated = 0;
    $totalFailed = 0;
    
    // Fetch items from eBay using appropriate method
    do {
        if ($useFullSync) {
            // Use GetSellerList to get ALL active listings
            $result = $ebayAPI->getStoreItems($storeName, $page, 100, $startDate, $endDate);
        } else {
            // Use GetSellerEvents to get only changed items
            $result = $ebayAPI->getSellerEvents($modTimeFrom, $modTimeTo, $page, 200);
        }
        
        if (!$result || empty($result['items'])) {
            if ($page == 1) {
                if ($useFullSync) {
                    echo "[" . date('Y-m-d H:i:s') . "] No active listings found in store\n";
                } else {
                    echo "[" . date('Y-m-d H:i:s') . "] No changes detected in time range\n";
                }
            }
            break;
        }
        
        $itemLabel = $useFullSync ? "items" : "changed items";
        echo "[" . date('Y-m-d H:i:s') . "] Processing page {$page} with " . count($result['items']) . " {$itemLabel}...\n";
        
        // Handle inactive items - only returned by GetSellerEvents
        if (!$useFullSync && !empty($result['inactive_item_ids'])) {
            echo "[" . date('Y-m-d H:i:s') . "] Found " . count($result['inactive_item_ids']) . " inactive items to hide...\n";
            foreach ($result['inactive_item_ids'] as $inactiveItemId) {
                try {
                    // Check if item exists in database
                    $stmt = $db->prepare("SELECT id FROM products WHERE ebay_item_id = ?");
                    $stmt->execute([$inactiveItemId]);
                    $existingItem = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingItem) {
                        // Hide from website since item is no longer active on eBay
                        $stmt = $db->prepare("UPDATE products SET show_on_website = 0 WHERE id = ?");
                        $stmt->execute([$existingItem['id']]);
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
