/* =====================================================================
   SARMAD Attendance - service worker (stage 4)
   ---------------------------------------------------------------------
   Rules, on purpose and in this order:

     1. /api/ is NEVER cached. Employee data, attendance contracts and the
        device config are personal data; an offline cache would leak them to
        the next person standing at the tablet.
     2. The device SHELL (offline page, css, js, icons, manifest) is cached, so
        the tablet still shows a branded kiosk screen without internet.
     3. Navigation requests try the network first and fall back to the cached
        offline shell - so a device that lost its connection never shows the
        browser's "no internet" dinosaur.
     4. Only same-origin GET requests are handled.
     5. Stage 5: the face engine and its model weights are cached as well, so a
        device that loses internet keeps recognising faces with the LOCAL model
        and the LOCAL descriptor gallery (the gallery itself lives in
        IndexedDB, never in this cache).

   The service worker never stores, reads or forwards a device token: the token
   lives in localStorage and is only attached to requests by the page itself.
   ===================================================================== */

const CACHE = 'sarmad-kiosk-v3';

const SHELL = [
    './device/offline',
    './assets/css/device.css',
    './assets/js/device-queue.js',
    './assets/js/device.js',
    './assets/vendor/tfjs-1.7.4.min.js',
    './assets/vendor/face-api-0.22.2.min.js',
    './assets/js/face/face-engine.js',
    './assets/js/face/face-store.js',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/maskable-512.png',
    './assets/icons/apple-touch-icon.png',
    './manifest.webmanifest'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

function isApi(url) {
    return url.pathname.indexOf('/api/') !== -1;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Never touch another origin, never cache the API.
    if (url.origin !== self.location.origin || isApi(url)) {
        return;
    }

    // Page navigations: network first, offline shell as the fallback.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match('./device/offline').then((cached) =>
                    cached || new Response('آفلاین', {
                        status: 503,
                        headers: { 'Content-Type': 'text/html; charset=utf-8' }
                    })
                )
            )
        );

        return;
    }

    // Static shell assets: cache first, then network (and remember it).
    // The face model weights are `.json` manifests plus extension-less shard
    // files, so /assets/models/ is cached by path as well.
    const cacheable = /\.(css|js|json|png|jpg|jpeg|svg|webmanifest|woff2?)$/i.test(url.pathname)
        || url.pathname.indexOf('/assets/models/') !== -1;

    if (!cacheable) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(request).then((response) => {
                if (response && response.ok) {
                    const copy = response.clone();

                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            });
        })
    );
});
