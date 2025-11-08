'use strict';

const CACHE_NAME = 'batch-reader-cache-v1';
const OFFLINE_URLS = [
  './',
  './index.php',
  './manifest.json',
  './assets/icon.svg',
  './assets/icon-192.png',
  './assets/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(OFFLINE_URLS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
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

  const acceptsHTML = request.headers.get('accept')?.includes('text/html');

  if (acceptsHTML) {
    event.respondWith(
      fetch(request)
        .then((networkResponse) => {
          if (networkResponse.status === 200) {
            const responseClone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
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
          caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
        }
        return networkResponse;
      })
      .catch(() => caches.match(request))
  );
});
