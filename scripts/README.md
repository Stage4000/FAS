# Gallery Cleanup Documentation

## Overview
This directory contains scripts for managing unused images in the gallery directory.

## Cleanup Status: ✅ COMPLETE

All unused images have been removed from the gallery. Only system images remain.

## What Was Done

### Initial State
- **Total Files**: 7,329
  - Product images (root): ~2,400 files (~316MB)
  - Thumbnails (thumbs/): 4,912 files (~60MB)
  - Favicons: 7 files
  - System images: ~12 files

### Actions Taken
1. ✅ **Removed thumbnail cache** (4,912 files, ~60MB)
2. ✅ **Removed unused product images** (2,398 files, ~386MB)
3. ✅ **Preserved system images** (19 files, 1.9MB)
4. ✅ **Created backup** at `/tmp/gallery-backup-20260216/` (386MB)

### Final State
- **Total Files**: 19 (99.7% reduction)
- **Total Size**: 1.9MB (99.5% reduction)
- **Files Preserved**:
  - Favicons (7 files)
  - Logo images (2 files)
  - Hero/background images (2 files)
  - Category icons (7 files)
  - Uploads directory (.gitkeep)
## The Cleanup Scripts

### 1. `cleanup-gallery.php` - Main Cleanup Tool

Comprehensive image cleanup tool that:
- Scans database for image references (if DB exists)
- Scans code for hardcoded image paths (PHP/CSS/JS)
- Protects system images automatically
- Supports backup before deletion
- Uses prepared statements for security

**Already executed**: Removed 2,398 unused product images

**Usage for future maintenance**:
```bash
# Scan for unused images
php scripts/cleanup-gallery.php --scan

# Remove with backup
php scripts/cleanup-gallery.php --remove --backup=/path/to/backup
```

### 2. `cleanup-thumbs.php` - Thumbnail Cache Cleanup

Safe removal of thumbnail cache directory.

**Already executed**: Removed 4,912 cached thumbnails (~60MB)

**Usage** (if thumbnails regenerate):
```bash
# Scan
php scripts/cleanup-thumbs.php --scan

# Remove
php scripts/cleanup-thumbs.php --remove
```

## Protected Images

The cleanup script automatically protects these system images:

1. **Favicons** (7 files)
   - favicon.png, favicon-60x60.png, favicon-76x76.png
   - favicon-120x120.png, favicon-152x152.png
   - favicon-180x180.png, favicon-192x192.png

2. **Logo & Branding** (2 files)
   - FLIPANDSTRIP.COM_d00a_018a.jpg
   - logo-crop.png

3. **Hero/Background Images** (2 files)
   - hero-image.png
   - aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg

4. **Category Icons** (7 files)
   - motorbike.png, atv.svg, boat.jpg
   - yacht.png, tuk-tuk.png
   - Atv-595b40b75ba036ed117d54ab.svg
   - asset 12-ts1553585532.svg

5. **Uploads Directory**
   - .gitkeep (preserves directory structure)

## Future Image Management

### Adding New Product Images

New product images should be added to:
- `gallery/uploads/` - This directory is preserved and in .gitignore
- Database references (products.image_url, products.images)

### Periodic Cleanup

Run cleanup periodically to remove orphaned images:
```bash
# Check for unused images
php scripts/cleanup-gallery.php --scan

# Remove if needed (with backup)
php scripts/cleanup-gallery.php --remove --backup=/backup/$(date +%Y%m%d)
```

## Backup Information

All removed images were backed up to:
- **Location**: `/tmp/gallery-backup-20260216/`
- **Size**: 386MB (2,398 product images)
- **Note**: Thumbnails were not backed up (can be regenerated)

## Notes

- The script preserves favicons, logo, hero images automatically
- Database queries check both `image_url` and JSON `images` fields
- Code scanning checks PHP, CSS, and JavaScript files
- Always backup before bulk deletion
