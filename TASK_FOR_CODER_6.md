# TASK FOR CODER - Fix Kiosk Mobile UI, Keypad RTL, Banner Display & Device Activation

## 🎯 هدف
رفع ۳ مشکل گزارش‌شده توسط کاربر در صفحه کیوسک دستگاه (`/device`):
1. **نمایش نامناسب در موبایل (سرریز و بریده شدن دکمه ثبت):** کارت فعال‌سازی روی گوشی‌های موبایل اسکرول نمی‌خورد و دکمه «فعال‌سازی دستگاه» زیر صفحه مخفی می‌ماند.
2. **برعکس بودن ارقام صفحه‌کلید لمسی:** به دلیل RTL بودن، ارقام به صورت `3 2 1` چیده شده‌اند به جای `1 2 3` استاندارد.
3. **عدم واکنش هنگام زدن دکمه ثبت:**
   - بنر خطا (`#activation-error` با کلاس `.k-banner`) به دلیل نبود استایل `.k-banner.is-open` همیشه `display: none` است و هیچ خطایی نمایش داده نمی‌شود.
   - کد جفت‌سازی ایجادشده توسط مدیر در `/manager/devices` در جدول `devices.pairing_code` ذخیره می‌شد اما اندپوینت `/api/v1/device/activate` فقط جدول `device_activation_codes` را بررسی می‌کرد و کد را نامعتبر تشخیص می‌داد.

---

## 🛠️ تغییرات لازم

### ۱. اصلاح CSS در `assets/css/device.css`

#### الف) جهت LTR برای کی‌پد لمسی:
در کلاس `.k-keypad` حتماً `direction: ltr;` اضافه شود:
```css
.k-keypad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    width: min(430px, 100%);
    margin-inline: auto;
    direction: ltr; /* ارقام باید همیشه چپ به راست 1 2 3 باشند */
}
```

#### ب) نمایش بنر خطا و پیام‌ها:
در بخش `.k-banner`:
```css
.k-banner.is-open {
    display: flex;
}
```

#### ج) اسکرول‌پذیری و ریسپانسیو موبایل برای صفحه فعال‌سازی:
در کلاس‌های `.k-overlay` و `.k-activate`:
```css
.k-overlay {
    position: fixed;
    inset: 0;
    z-index: 40;
    display: none;
    flex-direction: column;
    overflow-y: auto; /* امکان اسکرول محتوا در صفحات کوچک */
    -webkit-overflow-scrolling: touch;
}

.k-activate {
    min-height: 100dvh;
    display: flex; /* یا grid */
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 20px 14px;
    box-sizing: border-box;
}
```

و در مدیاکوئری موبایل (`@media (max-width: 600px)`):
```css
@media (max-width: 600px) {
    .k-activate {
        padding: 14px 10px;
        justify-content: flex-start;
    }
    .k-activate-card {
        padding: 20px 14px;
        gap: 12px;
        border-radius: 20px;
    }
    .k-activate-logo {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        font-size: 22px;
    }
    .k-activate-card p {
        font-size: 13px;
        line-height: 1.6;
    }
    .k-key {
        min-height: 50px;
        border-radius: 14px;
        font-size: 20px;
    }
    .k-keypad {
        gap: 8px;
    }
}
```

---

### ۲. اتصال کد ثبت‌شده در پنل مدیر به فعال‌سازی کیوسک

#### الف) در `app/Services/DeviceService.php`:
هنگامی که متد `register()` یک دستگاه جدید ثبت می‌کند، باید بلافاصله برای آن یک `DeviceActivationCode` رسمی هم صادر کند تا کدی که به مدیر نشان داده می‌شود مستقیماً در کیوسک کار کند:

```php
// در متد register:
$issuedCode = $this->issueActivationCode($result['device_id'], 60 * 24); // معتبر تا ۲۴ ساعت
$pairingCode = $issuedCode['ok'] ? $issuedCode['activation']['code'] : $result['pairing_code'];

return [
    'ok'           => true,
    'message'      => 'دستگاه ثبت شد و در انتظار فعال‌سازی است.',
    'device'       => $device,
    'pairing_code' => $pairingCode,
];
```

#### ب) پشتیبانی دوطرفه در `activateWithCode`:
در صورت امکان، در `DeviceService::activateWithCode($plainCode)` اگر کد در `DeviceActivationCode::verify` پیدا نشد، به عنوان fallback از `Device::findByPairingCode($plainCode)` نیز پشتیبانی شود تا دستگاه‌های ثبت‌شده قبلی هم بدون مشکل فعال شوند:
اگر دستگاه با `findByPairingCode` پیدا شد و وضعیت آن `pending` بود:
توکن صادر شود (`Device::issueToken`), دستگاه `active` شود و دیتای نشست ثبت گردد.

#### ج) افزودن دکمه «کد فعال‌سازی» در فهرست دستگاه‌های مدیر (`views/manager/devices/index.php`):
مدیر بتواند برای هر دستگاه در انتظار فعال‌سازی، دکمه دریافت کد فعال‌سازی جدید را بزند.

---

## 🧪 تست و اعتبارسنجی
1. تست در نمای موبایل (عرض ۳۶۰ تا ۴۰۰ پیکسل): تمام کی‌پد و دکمه «فعال‌سازی دستگاه» قابل مشاهده و اسکرول باشند.
2. بررسی ترتیب ارقام کی‌پد لمسی: ردیف اول `1 2 3`، ردیف دوم `4 5 6`، ردیف سوم `7 8 9`.
3. تست ورود کد اشتباه: بنر خطا به رنگ زرد/قرمز با متن «کد فعال‌سازی نامعتبر است» ظاهر شود.
4. تست ورود کد صحیح: دستگاه با موفقیت فعال شده و به صفحه اصلی کیوسک هدایت شود.
