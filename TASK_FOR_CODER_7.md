# TASK FOR CODER - Fix Kiosk Activation Hiding, Service Worker Cache & Auto-Migration

## 🎯 ریشه‌یابی دقیق باگ‌های گزارش‌شده:
1. **عدم پنهان شدن صفحه فعال‌سازی پس از ثبت کد («هیچ اتفاقی نمیوفته ولی تو پنل مالک میزنه آنلاین»):**
   - در `assets/css/device.css`، کلاس `.k-activate` دارای `display: grid;` در خط ۱۰۵۵ است که به دلیل ترتیب در CSS، بر `display: none` کلاس `.k-overlay` در خط ۴۱۷ غلبه می‌کند.
   - در نتیجه، وقتی جاوااسکریپت پس از فعال‌سازی موفق، کلاس `is-open` را حذف می‌کند (`only(null)` و `showHome()`)، صفحه فعال‌سازی پنهان نمی‌شود و روی صفحه کیوسک باقی می‌ماند!
2. **خطای غیرمنتظره سرور پس از رفرش («پیام میاد خطای غیرمنتظره رخ داد»):**
   - پس از رفرش، توکن ذخیره شده و دستگاه اندپوینت `GET /api/v1/device/config` را صدا می‌زند.
   - این اندپوینت از `FaceDatasetService::versionInfoForDevice` و ستون `attendance_customers.face_data_version` و همچنین جداول `attendance_device_settings` و `attendance_device_logs` استفاده می‌کند.
   - در هاست، دیتابیس با نسخه پایه `attendance_install.sql` ساخته شده و مایگریشن‌های مراحل ۲ تا ۷ اجرا نشده‌اند؛ در نتیجه خطای دیتابیس رخ داده و `Bootstrap::renderFatal` پیام «خطای غیرمنتظره سرور رخ داد» را نشان می‌دهد.
3. **اعمال نشدن تغییرات ظاهری در گوشی («تغییرات اعمال نشدن»):**
   - فایل `sw.js` با نام کش `sarmad-kiosk-v3` استایل `device.css` را کش کرده است.

---

## 🛠️ اقدامات لازم:

### ۱. اصلاح CSS در `assets/css/device.css`:
در کلاس `.k-activate`، مقدار پیش‌فرض را `display: none` بگذارید و فقط در صورت داشتن `.is-open` نمایش داده شود:
```css
.k-activate {
    min-height: 100dvh;
    display: none; /* به صورت پیش‌فرض مخفی است */
    place-items: center;
    padding: 26px 18px;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

.k-activate.is-open {
    display: grid; /* فقط با کلاس is-open نمایش داده شود */
}

@media (max-width: 600px) {
    .k-activate.is-open {
        display: flex;
        padding: 14px 10px;
    }
}
```

### ۲. ارتقای نسخه کش سرویس ورکر در `sw.js`:
نام کش را ارتقا دهید:
```javascript
const CACHE = 'sarmad-kiosk-v4';
```
و در `config/app.php` نسخه برنامه را به `'version' => '1.0.1'` افزایش دهید تا کش مرورگر باطل شود.

### ۳. اجرای خودکار مایگریشن‌های ناقص در صورت نیاز:
در `app/Api/Controllers/DeviceKioskController.php` یا در `DeviceService::configFor`، اگر خطای نبود ستون رخ داد یا قبل از فراخوانی، در صورتی که دیتابیس نیاز به آپدیت داشته باشد، از `(new MigrationService())->run()` استفاده شود تا جداول و ستون‌های مرحله ۴ خودبه‌خود ایجاد شوند و هیچ‌وقت خطای ۵۰۰ ندهد.

لطفاً این اصلاحات را در یک PR جدید ارسال فرمایید.
