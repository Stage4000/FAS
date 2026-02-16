# Gallery Cleanup Documentation

## Overview
This directory contains scripts for managing unused images in the gallery directory.

## The Problem
The gallery directory contains ~7,300 files. Without a populated database, it's impossible to determine which product images will be used once the eBay sync is configured.

## Current Status
- **Total Files**: 7,329
  - Product images (root): ~2,400 files
  - Thumbnails (thumbs/): ~4,912 files
  - Favicons: 7 files
  - Uploads: 1 file
  - System images: ~9 files (logo, hero, etc.)

## What Can Be Safely Deleted?

### ❌ DO NOT DELETE (Until Database is Populated):
1. **Product images in gallery root** - These are legitimate product photos that may be referenced when eBay products are synced
2. **Favicons** - Used by the website and PWA manifest
3. **System images** - Logo, hero images, default.jpg
4. **uploads/** - User-uploaded images

### ✅ CAN BE DELETED (With Caution):
1. **Thumbnails (thumbs/ directory)** - These can be regenerated if needed, BUT:
   - Only if you have a thumbnail generation system
   - They save bandwidth for image loading
   - Total size: ~60MB vs ~387MB for originals

## Recommendation

**WAIT** until:
1. The database is set up and populated
2. eBay sync has run and products are imported
3. You can see which images are actually referenced

**Then run**: 
```bash
php scripts/cleanup-gallery.php --scan
```

This will identify truly unused images based on actual database references.

## Safe Cleanup Options Now

If you need to free up space immediately:

### Option 1: Remove Thumbnails (Reversible if you can regenerate)
- Saves: ~60MB
- Risk: Low if you can regenerate thumbnails
- Command: `rm -rf gallery/thumbs/`

### Option 2: Remove Obvious Non-Product Files
Look for:
- Temporary files (.tmp, .bak)
- macOS files (.DS_Store)
- Hidden files that aren't needed

## The Cleanup Script

The script `cleanup-gallery.php` performs:

1. **Scan Mode** (`--scan`): Analyzes without deleting
   - Lists all gallery files
   - Identifies protected/hardcoded images
   - Checks database references (if DB exists)
   - Scans code for image references
   - Reports unused images

2. **Remove Mode** (`--remove`): Deletes unused images
   - ⚠️ ONLY USE AFTER DATABASE IS POPULATED
   - Removes files not found in any reference
   
3. **Backup Mode** (`--backup DIR`): Backs up before deleting
   - Creates backup of removed files
   - Recommended before any deletion

## Usage Examples

```bash
# Safe: Scan only (no deletions)
php scripts/cleanup-gallery.php --scan

# Cautious: Remove with backup
php scripts/cleanup-gallery.php --remove --backup=/tmp/gallery-backup

# Aggressive: Remove without backup (NOT RECOMMENDED)
php scripts/cleanup-gallery.php --remove
```

## After eBay Sync

Once products are imported from eBay:

1. Run scan to see what's actually unused:
   ```bash
   php scripts/cleanup-gallery.php --scan
   ```

2. Review the list of unused images

3. Back up and remove if satisfied:
   ```bash
   php scripts/cleanup-gallery.php --remove --backup=/backup/gallery
   ```

## Notes

- The script preserves favicons, logo, hero images automatically
- Database queries check both `image_url` and JSON `images` fields
- Code scanning checks PHP, CSS, and JavaScript files
- Always backup before bulk deletion
