/**
 * Minimal Service Worker for Admin PWA
 * Just enough to enable PWA installation - no caching
 */

// Install event - just activate immediately
self.addEventListener('install', event => {
  console.log('Admin SW: Installing');
  self.skipWaiting();
});

// Activate event - claim clients immediately
self.addEventListener('activate', event => {
  console.log('Admin SW: Activating');
  return self.clients.claim();
});

// Fetch event - pass through all requests to the network
self.addEventListener('fetch', event => {
  // Do nothing - just let the browser handle all fetches normally
  return;
});
