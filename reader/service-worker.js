'use strict';

const PRECACHE_VERSION = 'v2';
const PRECACHE_NAME = `batch-reader-precache-${PRECACHE_VERSION}`;
const RUNTIME_CACHE_NAME = 'batch-reader-runtime';

const PRECACHE_ASSETS = [
  './',
  './index.php',
  './manifest.php',
  './assets/icon.svg',
  './assets/icon-192.png',
  './assets/icon-512.png'
];

const CDN_ASSETS = [
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2',
  'https://fonts.googleapis.com/css2?family=Tajawal:wght@400;600;700&display=swap'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(PRECACHE_NAME).then((cache) =>
      Promise.allSettled(PRECACHE_ASSETS.map((asset) => cache.add(asset)))
    )
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== PRECACHE_NAME && key !== RUNTIME_CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  if (url.pathname.endsWith('/api.php')) {
    event.respondWith(
      fetch(request).catch(() =>
        new Response(
          JSON.stringify({ success: false, message: 'لا يوجد اتصال بالشبكة.' }),
          {
            status: 503,
            headers: { 'Content-Type': 'application/json; charset=utf-8' }
          }
        )
      )
    );
    return;
  }

  if (
    PRECACHE_ASSETS.includes(url.href) ||
    PRECACHE_ASSETS.includes(url.pathname.startsWith('/') ? `.${url.pathname}` : url.pathname)
  ) {
    event.respondWith(
      caches.match(request).then((cached) => cached || fetch(request))
    );
    return;
  }

  if (CDN_ASSETS.includes(url.href)) {
    event.respondWith(
      caches.open(PRECACHE_NAME).then((cache) =>
        cache.match(request).then((cached) => {
          const networkFetch = fetch(request)
            .then((response) => {
              if (response.status === 200) {
                cache.put(request, response.clone());
              }
              return response;
            })
            .catch(() => cached);
          return cached || networkFetch;
        })
      )
    );
    return;
  }

  if (request.destination === 'script' || request.destination === 'style' || request.destination === 'font') {
    event.respondWith(
      caches.open(RUNTIME_CACHE_NAME).then((cache) =>
        cache.match(request).then((cached) => {
          const networkFetch = fetch(request).then((response) => {
            if (response.status === 200) {
              cache.put(request, response.clone());
            }
            return response;
          });
          return cached || networkFetch;
        })
      )
    );
    return;
  }

  if (request.destination === 'image') {
    event.respondWith(
      caches.open(RUNTIME_CACHE_NAME).then((cache) =>
        cache.match(request).then((cached) => {
          if (cached) {
            return cached;
          }
          return fetch(request)
            .then((response) => {
              if (response.status === 200) {
                cache.put(request, response.clone());
              }
              return response;
            })
            .catch(() => caches.match('./assets/icon-192.png'));
        })
      )
    );
    return;
  }

  const acceptsHTML = request.headers.get('accept')?.includes('text/html');

  if (acceptsHTML) {
    event.respondWith(
      fetch(request)
        .then((networkResponse) => {
          if (networkResponse.status === 200) {
            const responseClone = networkResponse.clone();
            caches.open(RUNTIME_CACHE_NAME).then((cache) => cache.put(request, responseClone));
          }
          return networkResponse;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('./index.php')))
    );
    return;
  }

  event.respondWith(
    fetch(request)
      .then((networkResponse) => {
        if (networkResponse.status === 200) {
          const responseClone = networkResponse.clone();
          caches.open(RUNTIME_CACHE_NAME).then((cache) => cache.put(request, responseClone));
        }
        return networkResponse;
      })
      .catch(() => caches.match(request))
  );
});
