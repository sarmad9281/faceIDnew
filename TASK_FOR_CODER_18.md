# TASK FOR CODER - STAGE 18
## Bump Kiosk Asset Version & Service Worker Cache to Force-Refresh Audio Files

**Priority:** High  
**Context:** The audio files in `assets/audio/` were updated with natural Persian AI voice clips («صبح بخیر، ورود شما ثبت شد»، «خسته نباشید، خروج شما ثبت شد»، «چهره شناسایی نشد، لطفاً دوباره تلاش کنید»). Because `assets/.htaccess` sets long-term immutable caching and Service Worker v9 cached `?v=1.0.9`, existing kiosk installations continue serving the old audio from cache.

### Requirements:
1. **Bump Asset Version from `1.0.9` to `1.1.0`:**
   - In `assets/js/device.js`:
     Update `audioPath(name)`:
     ```javascript
     return SETUP.base.replace(/\/?$/, '/') + 'assets/audio/' + name + '.mp3?v=1.1.0';
     ```
   - In `views/layouts/device.php`:
     Bump `&v=1.1.0` on all kiosk scripts and stylesheets (`device.css`, `device-queue.js`, `device.js`, `face-engine.js`, `face-store.js`).
2. **Update Service Worker (`sw.js`):**
   - Bump cache name:
     ```javascript
     const CACHE = 'sarmad-kiosk-v10';
     const PREVIOUS_MODEL_CACHES = ['sarmad-kiosk-v9', 'sarmad-kiosk-v8', 'sarmad-kiosk-v7'];
     ```
     (Ensures face recognition model weights are preserved and not re-downloaded).
   - In `CORE_SHELL`:
     Update all versioned URLs from `?v=1.0.9` to `?v=1.1.0`:
     - `./assets/js/device-queue.js?v=1.1.0`
     - `./assets/js/device.js?v=1.1.0`
     - `./assets/audio/entry.mp3?v=1.1.0`
     - `./assets/audio/exit.mp3?v=1.1.0`
     - `./assets/audio/error.mp3?v=1.1.0`
3. **Verify tests:**
   - Run existing offline and service worker tests.
