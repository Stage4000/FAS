<?php
/**
 * Banner Diagnostic Test Script
 * Run this to diagnose banner display issues
 */

echo "=== BANNER SYSTEM DIAGNOSTIC TEST ===\n\n";

// Check 1: Config file existence
echo "[1] Configuration File Check\n";
$configPath = __DIR__ . '/src/config/config.php';
if (file_exists($configPath)) {
    echo "    ✓ config.php EXISTS\n";
} else {
    echo "    ✗ config.php MISSING\n";
    echo "    FIX: Copy src/config/config.example.php to src/config/config.php\n";
}
echo "\n";

// Check 2: Database connectivity
echo "[2] Database Connection Check\n";
try {
    require_once __DIR__ . '/src/config/Database.php';
    $db = \FAS\Config\Database::getInstance()->getConnection();
    echo "    ✓ Database connected successfully\n";
} catch (Exception $e) {
    echo "    ✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

// Check 3: Banners table existence
echo "[3] Banners Table Check\n";
try {
    $result = $db->query("SELECT COUNT(*) as cnt FROM banners");
    $count = $result->fetch()['cnt'];
    echo "    ✓ Banners table EXISTS with " . $count . " total records\n";
} catch (Exception $e) {
    echo "    ✗ Banners table missing or inaccessible\n";
    echo "    Running migration...\n";
    try {
        require_once __DIR__ . '/database/migrate-add-banners.php';
        echo "    ✓ Migration completed\n";
    } catch (Exception $migErr) {
        echo "    ✗ Migration failed: " . $migErr->getMessage() . "\n";
    }
}
echo "\n";

// Check 4: Active banners query
echo "[4] Active Banners Query\n";
try {
    require_once __DIR__ . '/src/models/Banner.php';
    $bannerModel = new \FAS\Models\Banner($db);
    $activeBanners = $bannerModel->getActive();
    echo "    ✓ Query executed successfully\n";
    echo "    Found " . count($activeBanners) . " active banners\n";
    
    if (count($activeBanners) > 0) {
        echo "\n    Active Banners:\n";
        foreach ($activeBanners as $idx => $banner) {
            echo "    [" . ($idx + 1) . "] ID: " . $banner['id'] 
                . " | Message: " . substr($banner['message'], 0, 40) . "...\n"
                . "        Active: " . ($banner['is_active'] ? 'YES' : 'NO')
                . " | BgColor: " . $banner['bg_color']
                . " | TextColor: " . $banner['text_color'] . "\n";
            if ($banner['starts_at']) {
                echo "        Starts: " . $banner['starts_at'] . "\n";
            }
            if ($banner['ends_at']) {
                echo "        Ends: " . $banner['ends_at'] . "\n";
            }
        }
    } else {
        echo "    ⚠ No active banners found\n";
        echo "    ACTION: Create a banner via /admin/banners.php\n";
    }
} catch (Exception $e) {
    echo "    ✗ Query failed: " . $e->getMessage() . "\n";
}
echo "\n";

// Check 5: CSS file
echo "[5] CSS Styling Check\n";
$cssPath = __DIR__ . '/public/css/style.css';
if (file_exists($cssPath)) {
    $cssContent = file_get_contents($cssPath);
    if (strpos($cssContent, '.alert-banner') !== false) {
        echo "    ✓ .alert-banner CSS class found\n";
        if (strpos($cssContent, 'display: none') === false || strpos($cssContent, '.alert-banner') < strpos($cssContent, 'display: none')) {
            echo "    ✓ .alert-banner is not hidden\n";
        }
    } else {
        echo "    ✗ .alert-banner CSS class not found\n";
    }
} else {
    echo "    ✗ style.css not found\n";
}
echo "\n";

// Check 6: PHP version and extensions
echo "[6] PHP Environment\n";
echo "    PHP Version: " . PHP_VERSION . "\n";
echo "    PDO Available: " . (extension_loaded('pdo') ? 'YES' : 'NO') . "\n";
echo "    SQLite3: " . (extension_loaded('sqlite3') ? 'YES' : 'NO') . "\n";
echo "\n";

echo "=== DIAGNOSTIC COMPLETE ===\n";
echo "\nIf banners still don't display:\n";
echo "1. Check browser DevTools console for JavaScript errors\n";
echo "2. Verify banner HTML is rendered in page source\n";
echo "3. Check is_active = 1 for all banners\n";
echo "4. Verify starts_at/ends_at date ranges (if set)\n";
