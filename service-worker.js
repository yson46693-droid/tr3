const CACHE_VERSION = '1.0.0';
const CACHE_NAME = 'company-management-v' + CACHE_VERSION;
const BASE = '/v1/';

const CORE_CACHE = [
  BASE,
  BASE + 'index.php',
  BASE + 'offline.html',

  BASE + 'assets/css/responsive.css?v=1.0.0',
  BASE + 'assets/css/dark-mode.css?v=1.0.0',

  BASE + 'assets/js/main.js?v=1.0.0',
  BASE + 'assets/js/pwa-install.js?v=1.0.0',

  BASE + 'assets/icons/icon-192x192.png',
  BASE + 'assets/icons/icon-96x96.png'
];

const MAX_DYNAMIC_CACHE_ITEMS = 50;

// Install event - Cache essential files
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(CORE_CACHE))
      .catch(() => {}) // تجاهل الأخطاء
      .finally(() => self.skipWaiting())
  );
});

// Activate event - Clean old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.map(key => {
          if (key !== CACHE_NAME) return caches.delete(key);
        })
      )
    ).then(() => self.clients.claim())
  );
});

// Helper to limit cache size
async function limitCacheSize(cacheName, maxItems) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length > maxItems) {
    await cache.delete(keys[0]);
    await limitCacheSize(cacheName, maxItems);
  }
}

// Fetch event - smart caching with error and CSP handling
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // Block requests to infinityfree errors domain
  if (url.hostname.includes('infinityfree') || url.hostname.includes('errors.infinityfree.net')) {
    event.respondWith(new Response('', { status: 200 }));
    return;
  }

  // Skip service worker interception for external domains (CDN, APIs) to avoid CSP issues
  // Allow external requests to pass through without service worker handling
  const externalDomains = [
    'code.jquery.com',
    'api.qrserver.com',
    'cdn.jsdelivr.net',
    'fonts.googleapis.com',
    'fonts.gstatic.com'
  ];
  
  const isExternalDomain = externalDomains.some(domain => url.hostname.includes(domain));
  
  if (isExternalDomain) {
    // For external domains, don't intercept - let browser handle directly
    // This prevents CSP violations from service worker fetch attempts
    return;
  }

  event.respondWith(
    caches.match(event.request).then(cachedResponse => {
      if (cachedResponse) return cachedResponse;

      return fetch(event.request)
        .then(async response => {
          if (!response || response.status !== 200 || response.type !== 'basic') return response;

          // Cache static assets only from same origin
          if (/\.(css|js|png|jpg|jpeg|svg|gif|woff2?)$/i.test(url.pathname)) {
            const responseClone = response.clone();
            const cache = await caches.open(CACHE_NAME);
            await cache.put(event.request, responseClone);
            await limitCacheSize(CACHE_NAME, MAX_DYNAMIC_CACHE_ITEMS);
          }

          return response;
        })
        .catch(() => caches.match(BASE + 'offline.html'));
    })
  );
});

// Background sync
self.addEventListener('sync', event => {
  if (event.tag === 'sync-data') {
    event.waitUntil(Promise.resolve());
  }
});

// Push notifications
self.addEventListener('push', event => {
  const options = {
    body: event.data ? event.data.text() : 'إشعار جديد',
    icon: BASE + 'assets/icons/icon-192x192.png',
    badge: BASE + 'assets/icons/icon-96x96.png',
    vibrate: [200, 100, 200],
    tag: 'notification',
    requireInteraction: true
  };

  event.waitUntil(
    self.registration.showNotification('نظام الإدارة الخاص بشركة البركة', options)
  );
});

// Notification click
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(clients.openWindow(BASE));
});
