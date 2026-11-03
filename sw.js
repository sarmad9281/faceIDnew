/* =====================================================================
   SARMAD Attendance - service worker (stage 4)
   ---------------------------------------------------------------------
   Rules, on purpose and in this order:

     1. /api/ is NEVER cached. Employee data, attendance contracts and the
        device config are personal data; an offline cache would leak them to
        the next person standing at the tablet.
     2. The REAL device shell (./device, css, js, icons, manifest) is cached,
        so an already activated tablet can reopen the working kiosk without
        internet. The static offline page is only a last-resort explanation
        for a browser that has no cached kiosk shell at all.
     3. Kiosk navigation requests try the network first and then fall back to
        the cached REAL kiosk; only if that is unavailable do they use the
        static offline page. Navigations outside the kiosk are left
        untouched, so the public site and panels keep the browser's normal
        offline behaviour instead of showing kiosk copy.
     4. Only same-origin GET requests are handled.
     5. Stage 5: the face engine and its model weights are cached as well, so a
        device that loses internet keeps recognising faces with the LOCAL model
        and the LOCAL descriptor gallery (the gallery itself lives in
        IndexedDB, never in this cache).

   The service worker never stores, reads or forwards a device token: the token
   lives in localStorage and is only attached to requests by the page itself.
   ===================================================================== */

const CACHE = 'sarmad-kiosk-v7';

const SHELL = [
    './device',
    './device/offline',
    './assets/css/device.css',
    './assets/js/device-queue.js',
    './assets/js/device.js',
    './assets/vendor/tfjs-1.7.4.min.js',
    './assets/vendor/face-api-0.22.2.min.js',
    './assets/js/face/face-engine.js',
    './assets/js/face/face-store.js',
    // Both supported detectors and the shared landmark/recognition networks.
    // Installing the new worker must not discard the only cached model.
    './assets/models/face-api-1.0.0/tiny_face_detector_model-weights_manifest.json',
    './assets/models/face-api-1.0.0/tiny_face_detector_model-shard1',
    './assets/models/face-api-1.0.0/ssd_mobilenetv1_model-weights_manifest.json',
    './assets/models/face-api-1.0.0/ssd_mobilenetv1_model-shard1',
    './assets/models/face-api-1.0.0/ssd_mobilenetv1_model-shard2',
    './assets/models/face-api-1.0.0/face_landmark_68_model-weights_manifest.json',
    './assets/models/face-api-1.0.0/face_landmark_68_model-shard1',
    './assets/models/face-api-1.0.0/face_recognition_model-weights_manifest.json',
    './assets/models/face-api-1.0.0/face_recognition_model-shard1',
    './assets/models/face-api-1.0.0/face_recognition_model-shard2',
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
            .then((keys) => Promise.all(keys.filter((key) => key.indexOf('sarmad-kiosk-') === 0 && key !== CACHE).map((key) => caches.delete(key))))
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

function isDeviceNavigation(url) {
    let scopePath = new URL(self.registration.scope).pathname;

    if (scopePath.slice(-1) !== '/') {
        scopePath += '/';
    }

    let relativePath = url.pathname;

    if (relativePath.indexOf(scopePath) === 0) {
        relativePath = relativePath.slice(scopePath.length);
    } else {
        return false;
    }

    relativePath = relativePath.replace(/\/+$/, '');

    return relativePath === 'device' || relativePath.indexOf('device/') === 0;
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

    // Kiosk navigations: network first, then the cached REAL kiosk. The
    // static offline page is only for a browser with no cached kiosk shell.
    // Other pages are deliberately not intercepted here.
    if (request.mode === 'navigate') {
        if (!isDeviceNavigation(url)) {
            return;
        }

        event.respondWith(
            fetch(request).catch(() =>
                caches.open(CACHE).then((cache) => cache.match('./device'))
                    .then((cachedDevice) => cachedDevice || caches.open(CACHE).then((cache) => cache.match('./device/offline')))
                    .then((cached) =>
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

    // Ignore version parameters only for the explicit precached files; do not
    // conflate query-dependent images or other resources.
    const shellAsset = SHELL.some((item) => new URL(item, self.registration.scope).pathname === url.pathname);
    event.respondWith(
        caches.open(CACHE).then((cache) => cache.match(request, { ignoreSearch: shellAsset })).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(request).then((response) => {
                if (response && response.ok) {
                    const copy = response.clone();

                    return caches.open(CACHE).then((cache) => cache.put(request, copy))
                        .catch(() => {}).then(() => response);
                }

                return response;
            });
        })
    );
});
