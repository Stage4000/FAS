/**
 * Service Worker for Admin PWA
 * Handles caching and offline functionality for the admin panel
 */

const CACHE_NAME = 'fas-admin-v1';
// Only cache static resources that won't redirect
const urlsToCache = [
  '/admin/css/admin-style.css',
  '/admin/manifest.json',
  '/gallery/favicons/favicon.png',
  '/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg'
];

// Install event - cache essential resources
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Admin SW: Opened cache');
        // Cache static resources that won't redirect
        return cache.addAll(urlsToCache).catch(err => {
          console.log('Admin SW: Some resources failed to cache', err);
          // Continue even if some resources fail
        });
      })
  );
  self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('Admin SW: Removing old cache', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  return self.clients.claim();
});

// Fetch event - serve from cache when possible, fallback to network
self.addEventListener('fetch', event => {
  // Skip non-GET requests
  if (event.request.method !== 'GET') {
    return;
  }

  // Skip external requests
  if (!event.request.url.startsWith(self.location.origin)) {
    return;
  }

  event.respondWith(
    caches.match(event.request)
      .then(response => {
        // Cache hit - return response
        if (response) {
          return response;
        }

        // Network-first strategy for admin pages
        return fetch(event.request, { redirect: 'follow' })
          .then(response => {
            // Check if valid response
            if (!response || response.status !== 200) {
              return response;
            }
            
            // Only cache same-origin responses (basic and cors types)
            // Opaque responses (from redirects) won't be cached
            if (response.type !== 'basic' && response.type !== 'cors') {
              return response;
            }

            // Clone the response
            const responseToCache = response.clone();

            // Cache the response asynchronously
            caches.open(CACHE_NAME)
              .then(cache => {
                cache.put(event.request, responseToCache);
              })
              .catch(err => {
                console.log('Admin SW: Cache put failed', err);
              });

            return response;
          })
          .catch(error => {
            console.log('Admin SW: Fetch failed', error);
            // Try to return cached login page or a basic offline page
            return caches.match('/admin/login.php').then(cachedResponse => {
              return cachedResponse || new Response('Offline', {
                status: 503,
                statusText: 'Service Unavailable',
                headers: new Headers({
                  'Content-Type': 'text/plain'
                })
              });
            });
          });
      })
  );
});
