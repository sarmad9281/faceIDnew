# TASK FOR CODER - STAGE 16
## Persian Voice Feedback, Offline Queue Deduplication, Asset Caching, Live Presence Board & Telegram Bot Fix

**Priority:** High  
**Context:** Production deployment on DirectAdmin shared hosting (`finger.sarmadfallahzadeh.ir`). Kiosk face punch is working fast and stable. We now require 5 targeted enhancements:
1. Persian voice feedback on Kiosk punch (entry / exit / not recognized).
2. Automatic deduplication & cleanup for offline punch queue.
3. Long-term immutable caching headers in `assets/.htaccess` for face AI models.
4. Live Presence Board (تابلوی زنده حاضرین) in the Manager panel with auto-refresh.
5. Fix Manager Telegram bot failures on Iranian shared hosting (support reverse-proxy / API gateway / proxy & diagnostics).

---

### 1. Kiosk Persian Voice Feedback (`assets/js/device.js`, `sw.js`, `assets/audio/`)
- **Objective:** When an employee clocks in or out, provide clear, pleasant spoken Persian audio feedback instead of (or in addition to) the WebAudio synth beep.
- **Audio Prompts:**
  - Entry success: "ورود ثبت شد" (short, clean, ~1 second)
  - Exit success: "خروج ثبت شد" (short, clean, ~1 second)
  - Face not recognized / Error: "چهره شناسایی نشد" (or retry chime)
- **Audio Quality Note:**
  - Standard multilingual AI TTS engines often mispronounce Persian vowels and sound unnatural. Keep phrases as short and natural as possible.
  - Structure the audio system so that files under `assets/audio/` (`entry.mp3`, `exit.mp3`, `error.mp3`) can be easily replaced by the site owner with their own high-quality studio/native Persian voice recordings or custom chimes at any time.
  - Maintain the existing pleasant WebAudio synth chime as a reliable instant fallback.
- **Implementation Requirements:**
  - Place lightweight, pre-compressed audio files (`entry.mp3`, `exit.mp3`, `error.mp3`) under `assets/audio/`.
  - In `assets/js/device.js`:
    - In `playTone(success, actionType)` or dedicated `playVoiceFeedback(actionType)`:
      - Respect `state.settings.success_sound` toggle from the device settings drawer.
      - Play the appropriate audio clip via standard HTML5 Audio with fallback to WebAudio oscillator beep if audio files fail or are blocked by browser autoplay policy.
    - Handle both online and offline playback smoothly.
  - In `sw.js`:
    - Add `assets/audio/entry.mp3`, `assets/audio/exit.mp3`, `assets/audio/error.mp3` to `CORE_ASSETS` precache so they work completely offline without internet.

---

### 2. Offline Queue Deduplication & Cleanup (`app/Services/AttendanceEngine.php`, `assets/js/device-queue.js`, `assets/js/device.js`)
- **Current Issue:** When a device comes back online, punches that conflict with existing attendance records (e.g. employee already has an open entry session `CODE_ENTRY_OPEN` or duplicate clock `CODE_DUPLICATE`) are currently marked as `REJECTED` and remain stuck in the IndexedDB offline queue, triggering persistent warnings.
- **Requirements:**
  - In `app/Services/AttendanceEngine.php` (`syncBatch` method):
    - When an offline punch is evaluated, if the error is `CODE_ENTRY_OPEN` or `CODE_DUPLICATE`, return status `'DUPLICATE'` rather than `'REJECTED'`.
    - This allows `device-queue.js` (`applyResults`) to treat it as duplicate and drop it from IndexedDB (`dropped.push(row)`), cleanly resolving the queue count.
  - In `assets/js/device-queue.js`:
    - Add a `clearQueue()` helper to purge stale/irrecoverable offline items if needed.
  - In `assets/js/device.js`:
    - In the settings drawer, under "صف آفلاین", if there are stuck items, provide a button: "پاک‌سازی صف آفلاین" with confirmation dialog to allow supervisor to clear invalid/stuck records.

---

### 3. Long-term Immutable Caching Headers (`assets/.htaccess`)
- **Objective:** DirectAdmin Apache server must serve face recognition model shards, weight manifests, and static JS/CSS with long-term caching and `immutable` flag so browsers cache them aggressively.
- **Requirements:**
  - Update `assets/.htaccess`:
    - Add `mod_expires` rules:
      - `application/json`: access plus 1 year
      - `application/javascript`: access plus 1 year
      - `text/css`: access plus 1 year
      - `image/*`: access plus 1 year
      - `audio/*`: access plus 1 year
    - Add `mod_headers` rules:
      - For `\.(json|min\.js|shard\d*|mp3|woff2)$`, set:
        `Header set Cache-Control "public, max-age=31536000, immutable"`

---

### 4. Live Presence Board (تابلوی زنده حاضرین) in Manager Panel (`views/manager/attendance/index.php`, `app/Controllers/Manager/AttendanceController.php`, `assets/js/manager-attendance.js`)
- **Objective:** Give managers a real-time board showing employees currently inside the workplace, their entry time, duration of stay, branch, and status.
- **Requirements:**
  - Create or enhance endpoint: `GET /manager/attendance/live-presence` (returns JSON list of currently present employees, open sessions, total counts, server time).
  - In `views/manager/attendance/index.php`:
    - Enhance `#inside-card`:
      - Add a manual "به‌روزرسانی" (Refresh) button.
      - Add auto-polling every 30 seconds via JavaScript fetch to update the list smoothly without full page reload.
      - Show employee full name, employee code, branch name, entry clock, duration inside (e.g. `۲ ساعت و ۱۵ دقیقه`), and badges for late/offline.
      - If empty, display a clean state: "در این لحظه کسی داخل مجموعه نیست".

---

### 5. Fix Manager Telegram Bot & Support Iranian Hosting Proxy (`app/Services/TelegramService.php`, `views/manager/telegram/index.php`, `app/Controllers/Manager/TelegramController.php`, `database/`)
- **Current Issue on Iranian Hosting:**
  - DirectAdmin server at `sarmadfallahzadeh.ir` resides in an Iranian data center. `api.telegram.org` is blocked by national filtering. Direct cURL calls to `https://api.telegram.org` time out or fail.
  - In the manager UI, the "ارسال پیام آزمایشی" button is in a separate form from the bot token input, so users testing before saving get an error.
  - `TelegramService` currently hardcodes `https://api.telegram.org` with no configurable custom API Base URL or cURL proxy.
- **Requirements:**
  - **Configurable API Gateway / Reverse Proxy & SOCKS/HTTP Proxy:**
    - In `app/Services/TelegramService.php`:
      - Allow configuring an alternative Telegram API Base URL (e.g. custom Cloudflare Worker or reverse proxy like `https://my-telegram-proxy.example.com`).
      - Support reading `telegram.api_base_url` and `telegram.proxy` from `config/config.php` as default fallbacks.
      - Support per-customer `api_base_url` and `proxy_url` stored in `attendance_telegram_settings` (add columns if not present, safely with `SHOW COLUMNS`).
      - In cURL call (`curlPost`):
        - If proxy is set, configure `CURLOPT_PROXY` and `CURLOPT_PROXYTYPE`.
        - Use the configured base URL instead of hardcoded `https://api.telegram.org`.
  - **Clear Error Diagnostics:**
    - In `TelegramController::test()` and `TelegramService::testSend()`:
      - If cURL fails (timeout or network error), return a clear, Persian diagnostic message:
        `"خطا در برقراری ارتباط با سرور تلگرام: [پیام cURL]. در صورتی که هاست در ایران است، نیاز به تنظیم آدرس پروکسی معکوس (Reverse Proxy) یا پروکسی سرور دارید."`
  - **UI Improvements (`views/manager/telegram/index.php`):**
    - Add fields in the Telegram settings form for:
      - آدرس درگاه اختصاصی / پروکسی معکوس (اختیاری - برای سرورهای ایران)
      - آدرس پروکسی HTTP/SOCKS5 (اختیاری)
    - If bot token is not saved yet, show a prominent notice next to the test button: "لطفاً ابتدا توکن ربات و شناسه چت را ذخیره کنید، سپس پیام آزمایشی ارسال نمایید."

---

### Non-Negotiable Constraints:
- Do NOT touch or alter other site tables (`cafe_*`, `barber_*`, `zibaei_*`, `clinic_*`, etc.).
- Maintain strict backward compatibility.
- Ensure audio files are small (< 50 KB each) and don't stall kiosk initialization.
