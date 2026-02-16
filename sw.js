/**
 * MyVivarium Service Worker
 * Enables PWA install and basic caching for static assets.
 * Pages use network-first strategy (dynamic PHP content).
 * Static assets use cache-first strategy.
 */

const CACHE_NAME = 'vivarium-cache-v1';

// Static assets to pre-cache on install
const PRECACHE_ASSETS = [
    '/icons/favicon-32x32.png',
    '/icons/android-chrome-192x192.png',
    '/icons/android-chrome-512x512.png',
    '/icons/apple-touch-icon.png',
    '/images/logo1.jpg'
];

// Install event — pre-cache essential assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE_ASSETS))
            .then(() => self.skipWaiting())
    );
});

// Activate event — clean up old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event — network-first for pages, cache-first for static assets
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Skip non-GET requests
    if (request.method !== 'GET') return;

    // Skip cross-origin requests
    if (!request.url.startsWith(self.location.origin)) return;

    const url = new URL(request.url);

    // Static assets (images, icons, CSS, JS files) — cache-first
    if (isStaticAsset(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // PHP pages — network-first (always try server, fall back to cache)
    event.respondWith(
        fetch(request)
            .then((response) => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                }
                return response;
            })
            .catch(() => {
                return caches.match(request).then((cached) => {
                    if (cached) return cached;
                    // Return offline fallback for navigation requests
                    if (request.mode === 'navigate') {
                        return new Response(
                            '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline - MyVivarium</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa;color:#333;text-align:center;padding:20px}.offline-card{max-width:400px}.offline-card h1{font-size:1.5rem;margin-bottom:10px}.offline-card p{color:#666;margin-bottom:20px}.retry-btn{background:#0d6efd;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-size:1rem;cursor:pointer}.retry-btn:hover{background:#0b5ed7}</style></head><body><div class="offline-card"><h1>You\'re Offline</h1><p>Please check your internet connection and try again.</p><button class="retry-btn" onclick="window.location.reload()">Retry</button></div></body></html>',
                            { headers: { 'Content-Type': 'text/html' } }
                        );
                    }
                });
            })
    );
});

/**
 * Check if a URL path is a static asset
 */
function isStaticAsset(pathname) {
    return /\.(css|js|png|jpg|jpeg|gif|webp|ico|svg|woff|woff2|ttf|eot)$/i.test(pathname);
}
