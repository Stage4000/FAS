# PWA Setup for Admin Panel

## Overview

The admin panel is now configured as a Progressive Web App (PWA), allowing administrators to install it as a standalone app on their devices.

## What Was Fixed

### Problem
The original issue reported HTTP 428 "Precondition Required" errors when trying to fetch `manifest.json`. This error typically occurs when:
1. A PWA manifest exists without a service worker
2. The browser's security requirements for PWAs are not met
3. Missing or incorrect server headers

### Solution

#### 1. Service Worker (`admin/service-worker.js`)
Created a service worker to meet PWA requirements:
- Caches essential admin resources for offline functionality
- Handles fetch events for network resilience
- Required for any PWA installation

#### 2. PWA Installer (`admin/js/pwa-installer.js`)
Implemented installation prompt handling:
- Registers the service worker
- Captures the `beforeinstallprompt` event
- Provides an "Install App" button in the admin navigation
- Shows confirmation when app is successfully installed

#### 3. Manifest Updates
Enhanced `admin/manifest.json`:
- Added proper `scope` field (`/admin/`)
- Updated `start_url` to `index.php`
- Added 512x512 icon for better compatibility
- Set theme color to brand red (`#db0335`)

#### 4. PWA Meta Tags
Added to admin pages:
- `theme-color` for mobile browsers
- Apple-specific meta tags for iOS installation
- Apple touch icon for home screen

#### 5. Server Configuration
Updated `.htaccess`:
- Set correct Content-Type for manifest.json (`application/manifest+json`)
- Added cache headers for optimal PWA performance
- Configured service worker headers
- Added compression for manifest files

## How to Use

### For Administrators

1. **Access the Admin Panel**
   - Navigate to `/admin/` and log in
   - The PWA functionality is **only available to logged-in admins**

2. **Install the PWA**
   - After logging in, look for the "Install App" button in the top navigation bar
   - Click the button when it appears (it only shows when PWA is installable)
   - Confirm the installation prompt from your browser
   - The app will be installed on your device

3. **Using the Installed App**
   - Launch the admin app from your device's home screen or app list
   - It will open in standalone mode (without browser UI)
   - Works offline for cached pages
   - Faster loading due to caching

### For Users (Non-Admins)

The PWA installation feature is **intentionally NOT available** to regular users:
- No manifest link on public pages
- No service worker registration outside admin area
- The "Install App" button only appears in the admin panel

## Technical Details

### Browser Compatibility
- Chrome/Edge: Full support
- Firefox: Full support
- Safari: Partial support (iOS 11.3+)
- Opera: Full support

### Requirements
- HTTPS connection (or localhost for testing)
- Valid manifest.json
- Registered service worker
- At least one icon (192x192 or larger)

### Files Modified
- `/admin/service-worker.js` - New service worker
- `/admin/js/pwa-installer.js` - New PWA installer script
- `/admin/manifest.json` - Enhanced manifest
- `/admin/includes/nav.php` - Added install button
- `/admin/includes/footer.php` - Added PWA script
- `/admin/index.php` - Added PWA meta tags
- `/admin/login.php` - Added PWA meta tags
- `/.htaccess` - Added manifest and service worker headers

## Testing

To test the PWA functionality:

1. **On Desktop Chrome/Edge:**
   - Open DevTools (F12)
   - Go to Application tab > Manifest
   - Verify manifest loads without errors
   - Go to Application tab > Service Workers
   - Verify service worker is registered and active

2. **Installation Test:**
   - Log into admin panel
   - Look for install prompt or button
   - Click install and verify it works

3. **Offline Test:**
   - Install the PWA
   - Open DevTools > Network tab
   - Set to "Offline" mode
   - Navigate to cached admin pages
   - Verify they still load

## Troubleshooting

### "Install App" button doesn't appear
- Ensure you're logged in as admin
- Check that the site is served over HTTPS (or localhost)
- Verify service worker is registered (DevTools > Application > Service Workers)
- Some browsers require the app to be visited at least twice

### Manifest errors in console
- Clear browser cache and reload
- Check that all icon files exist in `/gallery/favicons/`
- Verify manifest.json is valid JSON

### Service worker not registering
- Check browser console for errors
- Verify `/admin/service-worker.js` is accessible
- Ensure no CORS issues with the service worker file

## Security Notes

- PWA installation is **admin-only** by design
- Service worker scope is limited to `/admin/` directory
- No sensitive data is cached
- Credentials still required for each session
