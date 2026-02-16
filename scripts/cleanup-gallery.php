<?php
/**
 * Gallery Cleanup Script
 * 
 * This script identifies and optionally removes unused images from the gallery directory.
 * Images are considered unused if they are NOT:
 * 1. Referenced in the products or categories database tables
 * 2. Hardcoded in PHP, CSS, JavaScript, or HTML files
 * 3. System files (favicons, logo, hero images, default.jpg)
 * 
 * Usage:
 * php scripts/cleanup-gallery.php --scan          # Scan only (safe, no deletions)
 * php scripts/cleanup-gallery.php --remove        # Remove unused images
 * php scripts/cleanup-gallery.php --backup DIR    # Backup before removal
 */

// Change to project root directory
chdir(dirname(__DIR__));

// Parse command line arguments
$options = getopt('', ['scan', 'remove', 'backup:', 'help']);

if (isset($options['help']) || (empty($options))) {
    echo "Usage:\n";
    echo "  php scripts/cleanup-gallery.php --scan          # Scan only (safe, no deletions)\n";
    echo "  php scripts/cleanup-gallery.php --remove        # Remove unused images\n";
    echo "  php scripts/cleanup-gallery.php --backup DIR    # Backup before removal\n";
    exit(0);
}

$scanOnly = isset($options['scan']);
$remove = isset($options['remove']);
$backupDir = $options['backup'] ?? null;

if ($remove && $backupDir) {
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
}

echo "=== Gallery Cleanup Script ===\n";
echo "Mode: " . ($scanOnly ? "SCAN ONLY" : ($remove ? "REMOVE UNUSED" : "SCAN ONLY")) . "\n";
if ($backupDir) {
    echo "Backup directory: $backupDir\n";
}
echo "\n";

// Step 1: Get all files in gallery directory
echo "Step 1: Scanning gallery directory...\n";
$galleryPath = __DIR__ . '/../gallery';
$allFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($galleryPath, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $relativePath = 'gallery/' . str_replace($galleryPath . '/', '', $file->getPathname());
        $allFiles[] = $relativePath;
    }
}

echo "Found " . count($allFiles) . " files in gallery directory.\n\n";

// Step 2: Build list of protected (hardcoded) images
echo "Step 2: Identifying protected images...\n";
$protectedImages = [
    // Favicons
    'gallery/favicons/favicon.png',
    'gallery/favicons/favicon-60x60.png',
    'gallery/favicons/favicon-76x76.png',
    'gallery/favicons/favicon-120x120.png',
    'gallery/favicons/favicon-152x152.png',
    'gallery/favicons/favicon-180x180.png',
    'gallery/favicons/favicon-192x192.png',
    'gallery/favicons/favicon-196x196.png',
    'gallery/favicons/favicon-256x256.png',
    
    // Logo and branding
    'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg',
    'gallery/logo-crop.png',
    
    // Hero and background images
    'gallery/hero-image.png',
    'gallery/aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg',
    
    // Default fallback image
    'gallery/default.jpg',
    
    // Category icons (if they exist)
    'gallery/motorbike.png',
    'gallery/atv.svg',
    'gallery/boat.jpg',
    'gallery/yacht.png',
    'gallery/tuk-tuk.png',
    'gallery/Atv-595b40b75ba036ed117d54ab.svg',
    'gallery/asset 12-ts1553585532.svg',
];

echo "Protected " . count($protectedImages) . " hardcoded images.\n\n";

// Step 3: Scan database for referenced images
echo "Step 3: Scanning database for referenced images...\n";
$referencedImages = [];

// Try to connect to database
$dbPath = __DIR__ . '/../database/flipandstrip.db';
if (file_exists($dbPath)) {
    try {
        $db = new PDO("sqlite:$dbPath");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get images from products table
        $stmt = $db->query("SELECT image_url, images FROM products WHERE image_url IS NOT NULL OR images IS NOT NULL");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Add image_url
            if (!empty($row['image_url'])) {
                $imagePath = ltrim($row['image_url'], '/');
                $referencedImages[] = $imagePath;
            }
            
            // Parse JSON images array
            if (!empty($row['images'])) {
                $images = json_decode($row['images'], true);
                if (is_array($images)) {
                    foreach ($images as $img) {
                        $imagePath = ltrim($img, '/');
                        $referencedImages[] = $imagePath;
                    }
                }
            }
        }
        
        // Get images from categories table
        $stmt = $db->query("SELECT image_url FROM categories WHERE image_url IS NOT NULL");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['image_url'])) {
                $imagePath = ltrim($row['image_url'], '/');
                $referencedImages[] = $imagePath;
            }
        }
        
        $referencedImages = array_unique($referencedImages);
        echo "Found " . count($referencedImages) . " images referenced in database.\n\n";
        
    } catch (Exception $e) {
        echo "Warning: Could not connect to database: " . $e->getMessage() . "\n";
        echo "Continuing with file system scan only...\n\n";
    }
} else {
    echo "Database file not found at $dbPath\n";
    echo "Continuing with file system scan only...\n\n";
}

// Step 4: Scan codebase for image references
echo "Step 4: Scanning codebase for image references...\n";
$codeReferences = [];

// Search in PHP files
$phpFiles = array_merge(
    glob(__DIR__ . '/../*.php'),
    glob(__DIR__ . '/../includes/*.php'),
    glob(__DIR__ . '/../admin/*.php'),
    glob(__DIR__ . '/../api/*.php')
);

// Recursively find PHP files in src directory
$srcIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($srcIterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}

foreach ($phpFiles as $file) {
    $content = file_get_contents($file);
    // Match gallery/ paths
    if (preg_match_all('/[\'"]([^\'"]*)gallery\/([^\'"\s]+)[\'"]/', $content, $matches)) {
        foreach ($matches[0] as $match) {
            $path = trim($match, '\'"');
            $path = ltrim($path, '/');
            if (strpos($path, 'gallery/') === 0) {
                $codeReferences[] = $path;
            }
        }
    }
}

// Search in CSS files
$cssFiles = glob(__DIR__ . '/../public/css/*.css');
foreach ($cssFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match_all('/url\([\'"]?([^\'"\\)]*gallery\/[^\'"\\)]+)[\'"]?\)/', $content, $matches)) {
        foreach ($matches[1] as $path) {
            $path = ltrim($path, '/');
            $codeReferences[] = $path;
        }
    }
}

// Search in JavaScript files
$jsFiles = glob(__DIR__ . '/../public/js/*.js');
foreach ($jsFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match_all('/[\'"]([^\'"]*)gallery\/([^\'"\s]+)[\'"]/', $content, $matches)) {
        foreach ($matches[0] as $match) {
            $path = trim($match, '\'"');
            $path = ltrim($path, '/');
            if (strpos($path, 'gallery/') === 0) {
                $codeReferences[] = $path;
            }
        }
    }
}

$codeReferences = array_unique($codeReferences);
echo "Found " . count($codeReferences) . " images referenced in code.\n\n";

// Step 5: Combine all referenced images
$usedImages = array_unique(array_merge($protectedImages, $referencedImages, $codeReferences));
echo "Total used images: " . count($usedImages) . "\n\n";

// Step 6: Identify unused images
echo "Step 6: Identifying unused images...\n";
$unusedImages = [];

foreach ($allFiles as $file) {
    // Normalize path for comparison
    $normalizedFile = ltrim($file, '/');
    
    $isUsed = false;
    foreach ($usedImages as $usedImage) {
        $normalizedUsed = ltrim($usedImage, '/');
        if ($normalizedFile === $normalizedUsed) {
            $isUsed = true;
            break;
        }
    }
    
    if (!$isUsed) {
        // Additional check: skip directories like favicons (preserve these)
        // We'll handle thumbs separately - they should be deleted along with their main images
        if (!preg_match('#gallery/favicons/#', $file)) {
            // Skip uploads directory - these are user-uploaded and should be preserved unless we're sure
            if (!preg_match('#gallery/uploads/#', $file)) {
                $unusedImages[] = $file;
            }
        }
    }
}

echo "Found " . count($unusedImages) . " unused images.\n\n";

// Step 7: Display results
echo "=== RESULTS ===\n";
echo "Total files: " . count($allFiles) . "\n";
echo "Used images: " . count($usedImages) . "\n";
echo "Unused images: " . count($unusedImages) . "\n";
echo "\n";

// Show first 20 unused images as examples
if (count($unusedImages) > 0) {
    echo "Examples of unused images (first 20):\n";
    foreach (array_slice($unusedImages, 0, 20) as $img) {
        echo "  - $img\n";
    }
    if (count($unusedImages) > 20) {
        echo "  ... and " . (count($unusedImages) - 20) . " more\n";
    }
    echo "\n";
}

// Step 8: Remove unused images if requested
if ($remove && count($unusedImages) > 0) {
    echo "Step 8: Removing unused images...\n";
    
    $removedCount = 0;
    $failedCount = 0;
    
    foreach ($unusedImages as $img) {
        $filePath = __DIR__ . '/../' . $img;
        
        if (file_exists($filePath)) {
            // Backup if requested
            if ($backupDir) {
                // Preserve directory structure in backup
                $relativePath = str_replace(__DIR__ . '/../', '', $filePath);
                $backupPath = $backupDir . '/' . $relativePath;
                $backupPathDir = dirname($backupPath);
                if (!is_dir($backupPathDir)) {
                    mkdir($backupPathDir, 0755, true);
                }
                copy($filePath, $backupPath);
            }
            
            // Remove the file
            if (unlink($filePath)) {
                $removedCount++;
            } else {
                $failedCount++;
                echo "Failed to remove: $img\n";
            }
        }
    }
    
    echo "Removed $removedCount files.\n";
    if ($failedCount > 0) {
        echo "Failed to remove $failedCount files.\n";
    }
    echo "\n";
}

echo "=== COMPLETE ===\n";

if ($scanOnly) {
    echo "Scan complete. No files were removed.\n";
    echo "To remove unused images, run: php scripts/cleanup-gallery.php --remove\n";
    echo "To backup before removal, run: php scripts/cleanup-gallery.php --remove --backup=/path/to/backup\n";
}
