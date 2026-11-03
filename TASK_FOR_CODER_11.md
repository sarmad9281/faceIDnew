# TASK FOR CODER - Fix Kiosk Buttons Becoming Disabled After Idle

## 🎯 گزارش مشکل کاربر:
> «با گوشی بعد چند دقیقه یا ساعت مراجعه کردم
> گزینه های ورود و خروج هستن ولی غیرفعال هستن
> بعد حتی رفتم پنل مدیر قسمت دستگاه ها اخرین ارتباط بروز هست
> خود گوشی زده متصل به سرور ولی گزینه ها غیرفعالن»

---

## 🔍 ریشه‌یابی دقیق:

### ۱. غیرفعال شدن دکمه‌ها در `assets/js/device.js`:
تنها جایی در کل کد که دکمه‌های ورود و خروج را `disabled` و `aria-disabled="true"` (و در CSS دارای `pointer-events: none` و `filter: grayscale`) می‌کند، متد `setActionEnabled` است:
```javascript
function setActionEnabled(enabled) {
    [el.entryBtn, el.exitBtn].forEach(function (button) {
        if (!button) return;
        button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        button.disabled = !enabled;
    });
}
```
این تابع در `showHome()` به این صورت فراخوانی می‌شود:
`setActionEnabled(state.features.attendance);`
و در خط ۱۳۹ وضعیت اولیه `state.features` برابر است با:
`features: { attendance: false, branches: false }`

اگر گوشی به حالت Sleep برود، تب کروم ریست شود یا پاسخ `/api/v1/device/config` به هر علتی فیلد `attendance` را به صورت صریح `true` برنگرداند، مقدار `state.features.attendance` برابر با `false` شده و دکمه‌های ورود و خروج روی کیوسک **برای همیشه قفل و غیرفعال** می‌شوند!

### ۲. مقداردهی سرور در `app/Services/DeviceService.php`:
در متد `configFor($device)`:
`'attendance' => \feature_enabled($customerId, 'attendance')`
یک کیوسک ثبت حضور و غیاب (SARMAD Attendance Kiosk) هویت و کارکردش ثبت تردد است؛ بنابراین برای یک کیوسک فعال، نباید قابلیت attendance غیرفعال ارسال شود.

### ۳. عدم بیداری تب در مرورگر موبایل پس از Sleep:
وقتی گوشی ساعت‌ها خاموش می‌ماند و روشن می‌شود، هیچ رویداد `visibilitychange` برای بازیابی وضعیت و اطمینان از فعال بودن دکمه‌ها تعریف نشده است.

---

## 🛠️ اقدامات لازم:

### ۱. اصلاح `app/Services/DeviceService.php` (خط ۹۲۶):
در خروجی متد `configFor(array $device)`، برای دستگاه کیوسک قابلیت `attendance` همواره `true` باشد:
```php
'features' => [
    'branches'   => \feature_enabled($customerId, 'branches'),
    'attendance' => true, // کیوسک فعال همواره دارای قابلیت ثبت حضور است
    'employees'  => \feature_enabled($customerId, 'employees'),
],
```

### ۲. اصلاح `app/Models/FeatureFlag.php` (متد `isEnabled`):
قابلیت‌های اصلی سامانه (`attendance`, `employees`, `reports`) که هستهٔ پروژه سرمد هستند، در صورت نبود رکورد صریح، همواره پیش‌فرض `true` داشته باشند:
```php
if (in_array($featureKey, ['attendance', 'employees', 'reports'], true)) {
    return true;
}
```

### ۳. اصلاح `assets/js/device.js`:
- در خط ۱۳۹، پیش‌فرض `features` را با `attendance: true` مقداردهی کنید:
```javascript
features: { attendance: true, branches: true },
```
- در `showHome()`:
```javascript
setActionEnabled(state.features.attendance !== false);
```
- افزودن لیسنر `visibilitychange` به صفحه تا وقتی کاربر بعد از ساعت‌ها قفل گوشی را باز می‌کند، وضعیت دکمه‌ها بلافاصله فعال شده، ساعت همگام و هارت‌بیت زده شود:
```javascript
document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
        if (state.token && (state.status === 'active' || root.getAttribute('data-state') === 'home')) {
            setActionEnabled(true);
            renderClock();
            pushHeartbeat();
        }
    }
});
```

### ۴. ارتقای نسخه کش‌باستر به `1.0.5`:
- در `views/layouts/device.php` و `app/Support/Url.php` نسخه به `1.0.5` ارتقا یابد.
- در `sw.js` نام کش به `sarmad-kiosk-v5` تغییر کند تا حافظه کش سرویس‌ورکر گوشی پاک و نوسازی شود.

لطفاً این اصلاحات را پیاده‌سازی و در قالب PR ارسال فرمایید.
