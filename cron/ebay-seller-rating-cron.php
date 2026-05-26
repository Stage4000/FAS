#!/usr/bin/env php
<?php
/**
 * Cron job for refreshing cached eBay seller rating data.
 */

chdir(__DIR__ . '/..');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/SyncLogger.php';
require_once __DIR__ . '/../src/integrations/EbayAPI.php';
require_once __DIR__ . '/../src/models/EbaySellerRating.php';

use FAS\Config\Database;
use FAS\Utils\SyncLogger;
use FAS\Integrations\EbayAPI;
use FAS\Models\EbaySellerRating;

echo "[" . date('Y-m-d H:i:s') . "] Refreshing cached eBay seller rating...\n";

SyncLogger::init();

$storeName = null;
$storeUrl = null;
$sellerRatingModel = null;

try {
    $db = Database::getInstance()->getConnection();
    $sellerRatingModel = new EbaySellerRating($db);

    $configFile = __DIR__ . '/../src/config/config.php';
    if (!file_exists($configFile) || !is_readable($configFile)) {
        throw new Exception("Config file not found or not readable: $configFile");
    }

    $config = require $configFile;
    $storeName = trim((string) ($config['ebay']['store_name'] ?? ''));
    $storeUrl = $storeName !== '' ? 'https://www.ebay.com/str/' . rawurlencode($storeName) : null;

    if ($storeName === '') {
        throw new Exception('eBay store_name is not configured.');
    }

    $ebayAPI = new EbayAPI($config, $configFile);
    $sellerProfile = $ebayAPI->getSellerProfile($storeName);

    if (!$sellerProfile) {
        throw new Exception('eBay seller profile request returned no data.');
    }

    if (!isset($sellerProfile['feedback_score'], $sellerProfile['positive_feedback_percent'])) {
        throw new Exception('eBay seller profile response is missing feedback metrics.');
    }

    $sellerProfile['store_name'] = $storeName;
    $sellerProfile['store_url'] = $sellerProfile['store_url'] ?? $storeUrl;
    $sellerProfile['last_error'] = null;

    $sellerRatingModel->saveCache($sellerProfile);

    echo "[" . date('Y-m-d H:i:s') . "] Seller rating cache refreshed successfully.\n";
    echo "  Seller: " . ($sellerProfile['seller_name'] ?? $storeName) . "\n";
    echo "  Score: " . $sellerProfile['feedback_score'] . "\n";
    echo "  Positive feedback: " . $sellerProfile['positive_feedback_percent'] . "%\n";

    SyncLogger::finalize();
    exit(0);
} catch (Exception $e) {
    if ($sellerRatingModel && $storeName) {
        $sellerRatingModel->recordRefreshFailure($storeName, $e->getMessage(), $storeUrl);
    }

    echo "[ERROR] Seller rating refresh failed: " . $e->getMessage() . "\n";
    SyncLogger::logError('Seller rating refresh failed', $e);
    SyncLogger::finalize();
    exit(1);
}
