<?php
/**
 * Safe Gallery Cleanup Script
 * 
 * This script safely removes thumbnail cache files from the gallery/thumbs directory.
 * These thumbnails are automatically generated and can be regenerated as needed.
 * 
 * This is safe because:
 * 1. gallery/thumbs/ is in .gitignore (line 138)
 * 2. No PHP code references gallery/thumbs
 * 3. Thumbnails can be regenerated from original images
 * 
 * This saves approximately 60MB of disk space.
 * 
 * Usage:
 * php scripts/cleanup-thumbs.php --scan          # Scan only (safe, no deletions)
 * php scripts/cleanup-thumbs.php --remove        # Remove thumbnail cache
 */

// Change to project root directory
chdir(dirname(__DIR__));

// Parse command line arguments
$options = getopt('', ['scan', 'remove', 'help']);

if (isset($options['help']) || empty($options)) {
    echo "Safe Gallery Cleanup - Remove Thumbnail Cache\n";
    echo "\n";
    echo "Usage:\n";
    echo "  php scripts/cleanup-thumbs.php --scan     # Scan only (safe, no deletions)\n";
    echo "  php scripts/cleanup-thumbs.php --remove   # Remove thumbnail cache\n";
    echo "\n";
    echo "This removes the gallery/thumbs/ directory which contains ~60MB of cached thumbnails.\n";
    echo "These thumbnails are not referenced in code and are in .gitignore.\n";
    exit(0);
}

$scanOnly = isset($options['scan']);
$remove = isset($options['remove']);

echo "=== Safe Gallery Cleanup ===\n";
echo "Target: gallery/thumbs/ directory\n";
echo "Mode: " . ($scanOnly ? "SCAN ONLY" : ($remove ? "REMOVE CACHE" : "SCAN ONLY")) . "\n";
echo "\n";

// Check if thumbs directory exists
$thumbsPath = __DIR__ . '/../gallery/thumbs';

if (!is_dir($thumbsPath)) {
    echo "✓ gallery/thumbs directory does not exist or already removed.\n";
    echo "No action needed.\n";
    exit(0);
}

// Count files in thumbs directory
echo "Analyzing gallery/thumbs directory...\n";
$count = 0;
$totalSize = 0;
$filePaths = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($thumbsPath, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $count++;
        $totalSize += $file->getSize();
        $filePaths[] = $file->getPathname(); // Store paths for later deletion
    }
}

$sizeMB = round($totalSize / 1024 / 1024, 2);

echo "Found $count cached thumbnail files\n";
echo "Total size: $sizeMB MB\n";
echo "\n";

if ($scanOnly) {
    echo "=== SCAN RESULTS ===\n";
    echo "Thumbnails can be safely removed:\n";
    echo "  - Files: $count\n";
    echo "  - Size: $sizeMB MB\n";
    echo "  - Location: gallery/thumbs/\n";
    echo "\n";
    echo "These are cached thumbnails that:\n";
    echo "  ✓ Are in .gitignore (automatically generated)\n";
    echo "  ✓ Not referenced in any PHP, CSS, or JS code\n";
    echo "  ✓ Can be regenerated if needed\n";
    echo "\n";
    echo "To remove them, run:\n";
    echo "  php scripts/cleanup-thumbs.php --remove\n";
    exit(0);
}

if ($remove) {
    echo "Removing thumbnail cache...\n";
    
    // Remove all files in thumbs directory
    $removed = 0;
    $failed = 0;
    
    foreach ($filePaths as $filePath) {
        if (file_exists($filePath)) {
            if (unlink($filePath)) {
                $removed++;
            } else {
                $failed++;
            }
        }
    }
    
    // Try to remove the thumbs directory itself
    if ($removed > 0) {
        @rmdir($thumbsPath);
    }
    
    echo "\n";
    echo "=== CLEANUP COMPLETE ===\n";
    echo "Removed: $removed files ($sizeMB MB)\n";
    
    if ($failed > 0) {
        echo "Failed to remove: $failed files\n";
    }
    
    echo "\n";
    echo "✓ Thumbnail cache cleared successfully!\n";
    echo "Thumbnails will be regenerated as needed.\n";
}
