const CACHE = 'omnigo-offline-v3';
importScripts('/push-worker.js?v=0.9.318');
// Cache only the offline page and its two public assets.
const offlineAssets=['/install.css','/context-menu.js'];
self.addEventListener('install', event => {
 event.waitUntil(Promise.all([caches.open(CACHE).then(cache => cache.addAll(['/offline.html',...offlineAssets])),self.skipWaiting()]));
});
self.addEventListener('activate', event => {
 event.waitUntil(Promise.all([self.clients.claim(),caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('omnigo-offline-') && key !== CACHE).map(key => caches.delete(key))))]));
});
self.addEventListener('fetch', event => {
 const url = new URL(event.request.url);
 if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;
 if (event.request.mode === 'navigate') {
  event.respondWith(fetch(event.request).catch(() => caches.match('/offline.html')));
 } else if (offlineAssets.includes(url.pathname)) {
  event.respondWith(fetch(event.request).catch(() => caches.match(url.pathname)));
 }
});
