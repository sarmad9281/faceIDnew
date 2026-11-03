# Task 15: Fix Critical Kiosk Flaws — True Offline Boot, Permanent Device Authentication & Stable Mobile Responsive Viewport

## 1. Problem Overview & Field Feedback
Real-world field testing of the kiosk on mobile phones and tablets revealed three critical issues:

1. **Offline Still Does Not Work ("هنوز افلاین کار نکرد")**:
   - In `sw.js`, `cache.addAll(SHELL)` during the `install` event attempts to download 13+ MB of TensorFlow and Face-API model weights (`ssd_mobilenetv1` shards, `face_recognition` shards, etc.) simultaneously.
   - In the browser Cache API, `cache.addAll()` is an **atomic** operation. If even a single shard request times out or is cancelled (extremely common on mobile internet or VPNs in Iran), `cache.addAll()` rejects completely. As a result, the Service Worker installation fails entirely and is never activated. When the user tests offline, the browser displays the default "No Internet Connection" dinosaur page.
   - **Resolution**: Separate the lightweight core shell (~1.5 MB total: HTML shell, CSS, JS runtime, icons, manifest) from heavy model shards. Precache the core shell during `install` so installation always succeeds in < 2 seconds. Cache model files in the background without blocking the worker, or rely on the runtime cache. Ensure navigation requests fallback reliably to `./device`.

2. **Registered Device Unexpectedly Kicks Out to Activation Screen ("یهو گوشی که ثبت شده میپره بیرون و رمز میخواد")**:
   - In `assets/js/device.js`, `loadConfig()`:
     When network is offline or fails with any non-successful response (`NETWORK_ERROR`, `INVALID_RESPONSE`, timeout, HTTP 500/502/504), if `readCachedConfig()` fails or if `code !== 'NETWORK_ERROR'`, it calls:
     ```javascript
     showActivation(payload.message || 'ارتباط با سرور برقرار نشد.', code);
     ```
     This displays the activation keypad overlay and sets `state.status = 'unactivated'`, kicking out an already-registered device!
     Furthermore, in `readCachedConfig()`: it was overly strict—checking `credentialDeviceId !== configDeviceId` and wiping the cached config if `device_id` was missing or zero from an earlier registration.
   - **Rule**: An already-registered device (where `credentials.load()` has a valid token) **MUST NEVER** be kicked out or shown the activation screen on any network failure, offline state, or temporary server glitch.
   - On boot: if `credentials.load()` has a token, immediately apply cached config (or fallback config), set `state.status = 'active'`, and call `showHome()`. The device must stay on the Home screen in offline mode.
   - Background `api('/config')` and `api('/heartbeat')` must **ONLY** clear credentials and call `showActivation()` if the server explicitly returns HTTP 401 with `code === 'DEVICE_UNAUTHORIZED'`, or `DEVICE_REVOKED`, or `DEVICE_NOT_FOUND` (i.e. the device was explicitly revoked or deleted by the manager/owner in the admin panel).

3. **Mobile Layout Keeps Breaking & Fixing Itself ("دوباره هی سایز برا گوشی درست میشه خراب میشه")**:
   - In `assets/css/device.css`:
     `body.kiosk` has `overflow: hidden; min-height: 100dvh;`.
     In `@media (max-width: 760px)`, `.k-top` was set to `flex-direction: column !important;`, stacking logo, title, and metadata pills vertically, expanding the header to over 120px tall.
     Combined with `.k-clock` and `.k-actions`, the content height exceeded the viewport when the mobile browser's address bar was visible, pushing the punch buttons off the bottom of the screen and cutting them off.
     When the address bar collapsed/expanded on scroll or touch, the viewport height fluctuated, causing the layout to repeatedly jump, break, and fix itself.
   - **Resolution**:
     - On mobile screens (`max-width: 760px`):
       Keep `.k-top` as a compact single flex row (`flex-direction: row; align-items: center; justify-content: space-between;`): logo 38-42px, compact title (15-16px), compact status pill, height ~50-54px.
       Scale `.k-clock` cleanly (`flex: 1 1 auto; min-height: 0;`): clock time `clamp(38px, 8.5vh, 60px)`, date `clamp(12px, 1.8vh, 16px)`.
       Scale `.k-actions` so punch buttons (`.k-action`) are `min-height: clamp(80px, 14vh, 110px)`, padding 8-12px, label `clamp(20px, 2.8vh, 26px)`.
       Set `.k-shell` to `height: 100dvh; min-height: 100dvh; max-height: 100dvh; box-sizing: border-box; overflow: hidden;` with safe-area padding (`env(safe-area-inset-top)` / `bottom`).
       On very short screens (`max-height: 600px`), allow `.k-shell` to scroll smoothly rather than clipping off-screen (`overflow-y: auto`).

---

## 2. Required Code Changes

### File 1: `assets/js/device.js`
1. **Boot Sequence (`boot()`)**:
   - When `credentials.load()` returns stored credentials:
     - Set `state.token = stored.token`.
     - Set `state.status = 'active'`.
     - Immediately read cached config with `readCachedConfig()`. If present, call `applyConfig(cachedConfig)`.
     - Immediately call `showHome()` and `setActionEnabled(true)`. Do not leave the device on activation or boot spinner.
     - Call `loadConfig()` in the background to update config from server if online.
   - Only call `showActivation('', 'none')` if `credentials.load()` is strictly `null` (first-time activation).

2. **Server Config Loading (`loadConfig()`)**:
   - If `api('/config')` succeeds: apply config, update `_config` in `localStorage`, set online = true, `showHome()`, `startHeartbeat()`, sync queue.
   - If `api('/config')` fails:
     - If `payload.code === 'DEVICE_UNAUTHORIZED' || payload.code === 'DEVICE_REVOKED' || payload.code === 'DEVICE_NOT_FOUND'`:
       Only here (server explicitly revoked or deleted the device):
       ```javascript
       stopHeartbeat();
       credentials.clear();
       wipeFaceData();
       showActivation('اعتبار این دستگاه باطل شده است. کد فعال‌سازی جدید را وارد کنید.', payload.code);
       return false;
       ```
     - For **ANY OTHER FAILURE** (`NETWORK_ERROR`, `INVALID_RESPONSE`, timeout, HTTP 500/502/503/504, or offline):
       - **NEVER** call `showActivation()`!
       - **NEVER** clear credentials!
       - Set `setOnline(false)`.
       - Keep `state.status = 'active'` and `state.features.attendance = true`.
       - Ensure punch buttons remain enabled (`setActionEnabled(true)`).
       - Ensure cached gallery is loaded (`loadCachedGallery()`) and engine initialized (`ensureEngine()`).
       - Call `showHome()`.
       - Start heartbeat timer so it can automatically reconnect when network returns.
       - Return `true`.

3. **Cached Config Resiliency (`readCachedConfig()`)**:
   - Parse `localStorage.getItem(SETUP.storageKey + '_config')`.
   - If a valid object exists with `customer` or `settings` or `device`:
     Return the cached config.
   - Do not wipe `_config` just because `device_id` was missing or 0 from an older format. Only discard if JSON is completely corrupted or invalid.

4. **Credential Saving (`credentials.save(token, meta)`)**:
   - Do not blindly wipe `_config` on save unless the device ID is different.

5. **Heartbeat (`pushHeartbeat()`)**:
   - On response failure: only clear credentials if `payload.code === 'DEVICE_UNAUTHORIZED'`.
   - On network error or timeout: simply toggle connection dot (`setOnline(false)`). Never kick out or show activation.

---

### File 2: `sw.js`
1. **Cache Version**:
   - Bump cache name to `sarmad-kiosk-v8`.

2. **Core Precache List (`CORE_SHELL`)**:
   - Define `CORE_SHELL` containing strictly the lightweight essential assets (~1.5 MB):
     ```javascript
     const CORE_SHELL = [
         './device',
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
     ```
   - In the `install` event:
     ```javascript
     self.addEventListener('install', (event) => {
         event.waitUntil(
             caches.open(CACHE)
                 .then((cache) => cache.addAll(CORE_SHELL))
                 .then(() => self.skipWaiting())
         );
     });
     ```
   - Heavy model shards (`/assets/models/face-api-1.0.0/*`) must NOT be in the blocking `cache.addAll(CORE_SHELL)` list! They are either cached in the background after activation or runtime-cached when requested by `face-engine.js`.

3. **Navigation Fallback in `fetch`**:
   - For `request.mode === 'navigate'` and `isDeviceNavigation(url)`:
     ```javascript
     event.respondWith(
         fetch(request).catch(() =>
             caches.open(CACHE).then((cache) =>
                 cache.match(request, { ignoreSearch: true })
                     .then((cached) => cached || cache.match('./device', { ignoreSearch: true }))
                     .then((cachedDevice) => cachedDevice || cache.match('./device/offline', { ignoreSearch: true }))
                     .then((finalResponse) =>
                         finalResponse || new Response('آفلاین', {
                             status: 503,
                             headers: { 'Content-Type': 'text/html; charset=utf-8' }
                         })
                     )
             )
         )
     );
     ```

---

### File 3: `assets/css/device.css`
1. **Viewport & Shell Layout**:
   - Ensure `body.kiosk` and `.k-shell` strictly fill the dynamic viewport height without triggering address bar jumps:
     ```css
     body.kiosk {
         min-height: 100dvh;
         height: 100dvh;
         overflow: hidden;
     }

     .k-shell {
         display: flex;
         flex-direction: column;
         height: 100dvh;
         min-height: 100dvh;
         max-height: 100dvh;
         box-sizing: border-box;
         overflow: hidden;
     }
     ```

2. **Mobile Screen Rules (`@media (max-width: 760px)`)**:
   - Keep `.k-top` as a compact single flex row (NEVER `flex-direction: column`):
     ```css
     @media (max-width: 760px) {
         .k-shell {
             padding: clamp(8px, 1.5vh, 14px) clamp(10px, 3vw, 18px);
             gap: clamp(8px, 1.2vh, 12px);
         }

         .k-top {
             flex-direction: row !important;
             align-items: center !important;
             justify-content: space-between !important;
             padding: 8px 12px !important;
             gap: 10px !important;
         }

         .k-brand {
             gap: 10px;
         }

         .k-logo {
             width: 40px !important;
             height: 40px !important;
             border-radius: 12px !important;
             font-size: 17px !important;
         }

         .k-business {
             font-size: clamp(14px, 2.2vh, 18px) !important;
         }

         .k-branch {
             display: none !important;
         }

         .k-top-meta {
             margin-inline-start: auto;
             gap: 6px;
         }

         .k-pill {
             padding: 4px 8px !important;
             font-size: 11px !important;
         }

         .k-pill#k-device-pill {
             display: none;
         }

         .k-clock {
             flex: 1 1 auto;
             min-height: 0;
             justify-content: center;
             padding: 4px 0 !important;
             gap: 4px;
         }

         .k-clock-time {
             font-size: clamp(38px, 8.5vh, 60px) !important;
         }

         .k-clock-date {
             font-size: clamp(12px, 1.8vh, 16px) !important;
             gap: 8px;
         }

         .k-actions {
             grid-template-columns: 1fr 1fr !important;
             gap: 10px !important;
             padding-bottom: env(safe-area-inset-bottom, 6px);
         }

         .k-action {
             min-height: clamp(80px, 14vh, 110px) !important;
             padding: 8px 10px !important;
             border-radius: 16px !important;
             gap: 6px !important;
         }

         .k-action .k-action-icon {
             width: clamp(28px, 4.5vh, 36px) !important;
             height: clamp(28px, 4.5vh, 36px) !important;
         }

         .k-action .k-action-label {
             font-size: clamp(20px, 3vh, 26px) !important;
         }

         .k-action .k-action-hint {
             display: none !important;
         }
     }
     ```

3. **Short Viewport / Landscape Handling (`@media (max-height: 580px)`)**:
   - In tight landscape orientations, allow smooth vertical scrolling so buttons and headers are never clipped:
     ```css
     @media (max-height: 580px) {
         .k-shell {
             overflow-y: auto !important;
         }

         .k-clock {
             display: none !important;
         }
     }
     ```

---

### File 4: `views/layouts/device.php`
- Update asset version cache-busting query strings to `&v=1.0.8`:
  - `device.css&v=1.0.8`
  - `device-queue.js&v=1.0.8`
  - `device.js&v=1.0.8`
  - `face-engine.js&v=1.0.8`
  - `face-store.js&v=1.0.8`

---

## 3. Verification & Acceptance Criteria
1. **Offline Navigation & Boot**:
   - Open `/device` online once so SW installs. Turn off Wi-Fi/data. Reload `/device`.
   - The page must load offline from cache without any browser dinosaur error.
   - An already-registered device must immediately show the Home screen (clock, branding, and active "ورود" and "خروج" buttons) in offline mode ("بدون اتصال"). It must **NOT** show the activation keypad.
2. **Permanent Authentication**:
   - An activated device must remain permanently active across page reloads, browser restarts, network disconnections, and server restarts.
   - It must never ask for an activation code again unless explicitly revoked by the owner/manager in the admin panel or reset via "فراموشی دستگاه" in the settings drawer.
3. **Mobile Layout Stability**:
   - On a phone screen (portrait, 360px - 412px wide):
     - Header, clock, and both punch buttons must comfortably fit inside the viewport without vertical scrolling or overflow.
     - Address bar collapsing/expanding must not clip buttons or cause layout jumping.
     - Both "ورود" and "خروج" buttons must be side-by-side, fully visible, and easily tappable.
