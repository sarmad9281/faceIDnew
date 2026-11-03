# TASK FOR CODER - Fix Kiosk Face Recognition Freeze & Timeout Safety

## 🎯 ریشه‌یابی دقیق مشکل معلق ماندن ۳ دقیقه‌ای روی «چهره در حال شناسایی»:

کاربر گزارش داد کیوسک با موفقیت فعال شد و دوربین باز شد، اما به محض قرار گرفتن چهره، سیستم روی «چهره در حال شناسایی» قفل می‌کند و تا ۳ دقیقه نه تایید می‌کند و نه رد.

### ریشه خطا در `assets/js/device.js:1516`:
```javascript
function runRecognition(action) {
    ...
    state.faceBusy = true;
    state.action = action;
    setCameraState('recognizing', HINTS.recognizing);

    var requestId = requestId(); // ⚠️ خطای مهلک Hoisting در جاوااسکریپت!
    state.lastRequestId = requestId;
```
در جاوااسکریپت، اعلان `var requestId` به بالای تابع `runRecognition` منتقل (Hoist) می‌شود و مقدار آن در زمان فراخوانی `undefined` است! در نتیجه اجرای `requestId()` بلافاصله خطای همگام پرتاب می‌کند:
`Uncaught TypeError: requestId is not a function`
چون این خطا دقیقاً بعد از `setCameraState('recognizing')` و قبل از `engine.recognize()` رخ می‌دهد:
۱. متن «چهره در حال شناسایی» برای همیشه روی صفحه می‌ماند.
۲. پرچم `state.faceBusy = true` هرگز ریست نمی‌شود و تمام فریم‌های بعدی نادیده گرفته می‌شوند.
۳. کیوسک به حالت کاملاً فریز درمی‌آید.

---

## 🛠️ اقدامات مورد نیاز:

### ۱. اصلاح `assets/js/device.js`:
- تغییر نام متغیر محلی در `runRecognition` از `requestId` به `reqId`:
```javascript
var reqId = requestId();
state.lastRequestId = reqId;
```
و پاس دادن `reqId` به `reportOutcome(action, reqId, ...)` در خطوط بعدی.
- افزودن تایم‌اوت محافظ (Watchdog Timeout) ۱۰ ثانیه‌ای در حین شناسایی، تا اگر به هر دلیل پردازش طول کشید یا معلق ماند، وضعیت ریست شده و کاربر راهنمایی شود:
```javascript
var recognitionTimer = window.setTimeout(function () {
    if (state.faceBusy && root.getAttribute('data-camera-state') === 'recognizing') {
        state.faceBusy = false;
        state.faceStable = 0;
        setCameraState('guidance', 'شناسایی زمان‌بر شد؛ لطفاً چهره را دوباره تنظیم کنید.');
    }
}, 8000);
```
و حتماً در `.then()` و `.catch()` دستور `window.clearTimeout(recognitionTimer);` قرار گیرد.

### ۲. ارتقای نسخه کش‌باستر به `1.0.3`:
- در `views/layouts/device.php`:
```php
<link rel="stylesheet" href="<?= $e($asset('/css/device.css')) ?>&v=1.0.3">
<script src="<?= $e($asset('/js/device.js')) ?>&v=1.0.3" defer></script>
```
- در `app/Support/Url.php`:
نسخه پیش‌فرض به `1.0.3` ارتقا یابد و نسخه‌های `1.0.0` تا `1.0.2` به `1.0.3` مپ شوند.

لطفاً این موارد را تست و در قالب یک PR ارسال فرمایید.
