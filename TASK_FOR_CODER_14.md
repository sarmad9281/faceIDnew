# TASK FOR CODER - Fix True Offline Kiosk Face Punch & Cache Shell (Task #14)

## 🎯 گزارش و مشکل ارسالی کاربر (همراه اسکرین‌شات):
> کاربر اینترنت گوشی را قطع کرده و آدرس `finger.sarmadfallahzadeh.ir/device` را باز کرده است.
> نتیجه: صفحه خطا نمایش داده شده است:
> **«ارتباط دستگاه با سرور قطع است. این صفحه نسخه آفلاین دستگاه حضور و غیاب است... هیچ اطلاعات پرسنلی روی این دستگاه ذخیره نشده است. تلاش دوباره»**
> کاربر می‌گوید:
> «دو تا مشکل حل شدن ولی مشکل آفلاین نه؛ وقتی نت قطعه نمی‌شه تردد زد و فقط این صفحه میاد!»

---

## 🔍 ریشه‌یابی دقیق باگ آفلاین کیوسک:

تمام معماری ثبت تردد آفلاین از قبل در پروژه کدنویسی شده است:
- `assets/js/face/face-store.js`: ذخیره بردار چهره پرسنل در `IndexedDB`
- `assets/js/device-queue.js`: ثبت تردد در صف محلی `IndexedDB / localStorage`
- `assets/js/device.js`: متد `enqueueOffline()` و `finishRecognition(..., 'offline')`

اما **۲ مانع اصلی** مانع از اجرای آفلاین کیوسک در زمان قطع اینترنت می‌شدند:

### مانع اول: در `sw.js` به جای پوسته واقعی کیوسک، صفحه مرده `offline.php` کش شده بود!
در `sw.js`، در لیست `SHELL`، به جای صفحه اصلی کیوسک (`./device`)، آدرس `./device/offline` قرار داده شده بود!
و هنگام باز کردن صفحه در حالت آفلاین (`request.mode === 'navigate'`):
سرویس‌ورکر به جای باز کردن صفحه کیوسک، صفحه خطای `views/device/offline.php` را لود می‌کرد.
در فایل `assets/js/device.js` خط ۲۳۶۱:
```javascript
if (root.getAttribute('data-offline-shell') === '1') {
    bootOfflineShell();
    return; // کل کارکرد کیوسک متوقف می‌شد!
}
```
به این دلیل، صفحه اصلی ساعت و دکمه‌های ورود و خروج اصلاً بالا نمی‌آمد و یک کارت خطای استاتیک با دکمه «تلاش دوباره» نمایش داده می‌شد!

### مانع دوم: در `device.js`، کانفیگ دستگاه کش محلی نمی‌شد!
وقتی `device.js` اجرا می‌شود، متد `loadConfig()` فراخوانی می‌شود که به `/api/v1/device/config` درخواست می‌زند. در حالت آفلاین این درخواست با خطای `NETWORK_ERROR` روبرو می‌شد. چون کانفیگ قبلی در `localStorage` ذخیره نشده بود، سیستم به جای نمایش صفحه اصلی (`showHome()`)، به صفحه فعال‌سازی منتقل می‌شد.

---

## 🛠️ اقدامات لازم برای حل ریشه‌ای:

### ۱. اصلاح `sw.js`:
- افزودن `'./device'` به آرایه `SHELL`.
- در رویداد `fetch` برای ناوبری کیوسک (`request.mode === 'navigate'`):
  ابتدا شبکه بررسی شود؛ در صورت قطعی اینترنت، ابتدا `caches.match('./device')` لود شود تا صفحه واقعی کیوسک بالا بیاید. تنها در صورتی که کیوسک هرگز لود نشده باشد، به عنوان fallback صفحه `offline` لود شود:
  ```javascript
  event.respondWith(
      fetch(request).catch(() =>
          caches.match('./device').then((cachedKiosk) =>
              cachedKiosk || caches.match('./device/offline').then((cachedOffline) =>
                  cachedOffline || new Response('آفلاین', {
                      status: 503,
                      headers: { 'Content-Type': 'text/html; charset=utf-8' }
                  })
              )
          )
      )
  );
  ```
- ارتقای نسخه کش به `sarmad-kiosk-v7`.

### ۲. اصلاح `assets/js/device.js`:
- در `applyConfig(config)`:
  ذخیره کانفیگ در حافظه محلی برای استفاده آفلاین:
  ```javascript
  try {
      window.localStorage.setItem(SETUP.storageKey + '_config', JSON.stringify(config));
  } catch (e) {}
  ```
- در `loadConfig()`:
  در صورتی که درخواست `/config` با خطای شبکه (`NETWORK_ERROR` یا عدم دسترسی) مواجه شد:
  کانفیگ ذخیره‌شده محلی بررسی شود:
  ```javascript
  if (code === 'NETWORK_ERROR' || !navigator.onLine) {
      var cachedRaw = null;
      try {
          cachedRaw = window.localStorage.getItem(SETUP.storageKey + '_config');
      } catch (e) {}
      if (cachedRaw) {
          try {
              var cachedConfig = JSON.parse(cachedRaw);
              if (cachedConfig) {
                  applyConfig(cachedConfig);
                  setOnline(false);
                  showHome();
                  ensureEngine();
                  return true;
              }
          } catch (e) {}
      }
  }
  ```
- اطمینان از اینکه در حالت آفلاین:
  - ساعت و تاریخ کار می‌کنند.
  - دکمه‌های ورود و خروج فعال هستند (`setActionEnabled(true)`).
  - هوش مصنوعی چهره را با گالری محلی `IndexedDB` تطبیق می‌دهد.
  - تردد در صف محلی ثبت شده و بوق/پیام موفقیت‌آمیز ثبت تردد نمایش می‌یابد.
  - شمارنده «در صف ارسال: ۱» نمایش داده می‌شود.
  - به محض اتصال اینترنت، صف خودکار هم‌گام می‌شود.

### ۳. اصلاح متن `views/device/offline.php`:
متن قدیمی که می‌گفت «هیچ اطلاعات پرسنلی روی این دستگاه ذخیره نشده است» حذف شود؛ زیرا مربوط به نسخه‌های قدیمی قبل از مرحله ۵ بود و گمراه‌کننده است. متن به این شکل اصلاح شود:
> «دستگاه در انتظار اتصال اولیه به اینترنت جهت فعال‌سازی و دریافت اطلاعات چهره است. پس از یک‌بار فعال‌سازی آنلاین، کیوسک در زمان قطعی اینترنت نیز به ثبت تردد ادامه خواهد داد.»

---

لطفاً این تغییرات را اعمال و در قالب Pull Request ارسال فرمایید.
