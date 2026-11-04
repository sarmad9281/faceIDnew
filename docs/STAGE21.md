# تسک ۲۱ — مهار صلب لوگو، کارت پرسنلی استاندارد و باطل‌سازی کش استایل‌ها

این سند سه تغییر تسک ۲۱ را توضیح می‌دهد:

1. **لوگو دیگر به فایل CSS وابسته نیست**: ابعاد همان‌جا روی تگ `<img>` نوشته شده است.
2. **کارت بارکد پرسنلی** روی صفحه و روی کاغذ ابعاد استاندارد دارد.
3. **همهٔ استایل‌ها** با `?v=1.2.1` سرو می‌شوند تا مرورگر نسخهٔ کهنه را نشان ندهد.

---

## ۱. مهار صلب و اینلاین لوگو

هیچ لوگویی دیگر نمی‌تواند چیدمان را به‌هم بزند، حتی اگر `manager.css` / `device.css`
هنوز در کش مرورگر باشد یا اصلاً بارگذاری نشود. قاعدهٔ ثابت روی تگ:

```html
style="max-height: 42px; max-width: 130px; width: auto; height: auto; object-fit: contain; display: block;"
```

| محل | فایل | کادر |
| --- | --- | --- |
| لوگوی سایدبار مدیریت | `views/layouts/app.php` | همین قاعدهٔ ۴۲×۱۳۰ |
| لوگوی سربرگ چاپی پنل | `views/layouts/app.php` | ۴۲×۱۳۰ |
| لوگوی کیوسک | `views/device/kiosk.php` | ۴۲×۱۳۰ |
| لوگوی کارت بارکد پرسنلی | `views/manager/employees/profile.php` | ۴۲×۱۳۰ (در چاپ ۲۸×۹۰) |
| پیش‌نمایش لوگو در پنل مالک | `views/owner/customers/form.php` | کادر ثابت ۱۶۰×۶۰ |
| صفحهٔ مشتری در پنل مالک | `views/owner/customers/show.php` | ۴۲×۱۳۰ |
| کارت فقط‌خواندنی تنظیمات مدیر | `views/manager/settings/index.php` | ۴۲×۱۳۰ |

`object-fit: contain` می‌ماند (تسک ۲۰): هر لوگویی با نسبت تصویر خودش دیده می‌شود،
هیچ‌وقت بریده یا کشیده نمی‌شود.

### ۱.۱ کادر پیش‌نمایش مالک (۱۶۰×۶۰)

```html
<div class="o-logo-frame" style="display: inline-flex; align-items: center;
     justify-content: center; height: 60px; max-height: 60px; max-width: 160px;
     overflow: hidden; …">
    <img class="brand-logo" style="max-height: 60px; max-width: 160px; …">
</div>
```

عکس ۴۰۰۰ پیکسلی هم داخل این کادر جا می‌شود؛ کارت برند شکل خودش را نگه می‌دارد و صفحه
افقی اسکرول نمی‌شود. مسیر فایل (`logo_path`) هم دیگر داخل کادر نیست تا با
`overflow: hidden` بریده نشود و زیر آن نمایش داده می‌شود.

### ۱.۲ سایدبار مدیریت: کادر ۱۳۲×۴۴ با `overflow: hidden`

```css
.m-brand-mark        { width: 44px;  height: 44px;  overflow: hidden; }  /* کاشی «SA» */
.m-brand-mark:has(img) { width: 132px; height: 44px; padding: 0; overflow: hidden; }
.m-brand-mark img    { max-width: 130px; max-height: 42px; object-fit: contain; }
```

۱۳۲ منهای ۱ پیکسل حاشیه = ۱۳۰، و ۴۴ منهای ۲ = ۴۲؛ یعنی دقیقاً همان ابعادی که روی تگ
نوشته شده، بدون یک پیکسل برش. `overflow: hidden` تضمین می‌کند حتی اگر مرورگری استایل
اینلاین را هم نادیده بگیرد، عکس روی منو یا دکمه‌های داشبورد نریزد.

---

## ۲. کارت بارکد: ۳۲۰ پیکسل روی صفحه، ۸۰ میلی‌متر روی کاغذ

### نمایش

```css
.qr-badge-card { width: 100%; max-width: 320px; margin: 14px auto; box-sizing: border-box; }
```

### چاپ (`@media print`)

| موضوع | مقدار |
| --- | --- |
| عرض کارت | `80mm` (کارت واقعی ۸۵٫۶×۵۴mm است؛ ۸۰mm حاشیهٔ چاپگر را نگه می‌دارد) |
| ارتفاع کمینه | `54mm` |
| جای‌گیری | `margin: 0 auto` — وسط برگهٔ سفید |
| قاب | `border: 0.4mm solid #111`، گوشهٔ `3mm`، پس‌زمینه سفید |
| شکستن صفحه | `page-break-inside: avoid` + `break-inside: avoid` |
| لوگو | حداکثر `28px` در `90px` با `!important` (استایل اینلاین ۴۲×۱۳۰ را در چاپ کنار می‌زند) |
| نماد QR | مربع `38mm`، وسط کارت، بدون شکستن |
| چیدمان | `.card-head`، `.page-head`، `.m-print-brand` و بقیهٔ کارت‌ها چاپ نمی‌شوند |

نکته: `!important` در بلوک چاپ تنها جایی است که بر استایل اینلاین پیروز می‌شود؛ برای همین
لوگوی کارت روی کاغذ کوچک است، در حالی که همان تگ روی صفحه سقف ۴۲×۱۳۰ دارد.

---

## ۳. باطل‌سازی کش استایل‌ها (`?v=1.2.1`)

یک منبع حقیقت، یک پارامتر:

```php
// app/Support/Url.php
public const CSS_VERSION = '1.2.1';

public static function css(string $path): string
{
    return self::asset($path, self::CSS_VERSION);
}
```

همهٔ چیدمان‌ها از همین کمک‌تابع استفاده می‌کنند:

| فایل | خروجی |
| --- | --- |
| `views/layouts/app.php` | `css/app.css?v=1.2.1`، `css/manager.css?v=1.2.1`، `css/owner.css?v=1.2.1` |
| `views/layouts/owner.php` | `css/owner.css?v=1.2.1` |
| `views/layouts/device.php` | `css/device.css?v=1.2.1` |
| `views/layouts/blank.php` (ورود/ثبت‌نام) | `css/app.css?v=1.2.1` |
| `views/landing.php` | `css/landing.css?v=1.2.1` |

برای تغییر بعدی کافی است `Url::CSS_VERSION` را بالا ببرید؛ هیچ فایلی از ویوها دست نمی‌خورد.

### ۳.۱ رفع باگ `?v=…&v=…`

تا پیش از این تسک، لینک استایل کیوسک و اسکریپت‌ها این‌گونه ساخته می‌شد:

```php
$asset('/css/device.css') . '&v=1.1.0'   // → ?v=1.0.9&v=1.1.0
```

پارامتر `v` دوبار تکرار می‌شد و مرورگر (و `parse_str`) **اولین** مقدار را می‌خواند؛ یعنی
نسخهٔ اعمال‌شده همیشه `1.0.9` بود، نه چیزی که برنامه‌نویس نوشته بود. اکنون:

```php
$asset('/js/device.js', '1.2.0')   // → ?v=1.2.0
```

`Url::asset($path, $version)` هر کوئری/هش چسبیده به مسیر را هم دور می‌ریزد
(`?x=1` → حذف) و فقط یک `?v=` می‌سازد.

### ۳.۲ سرویس‌ورکر کیوسک

- کش به `sarmad-kiosk-v12` ارتقا یافت (وگرنه استایل قدیمی برای همیشه از کش سرو می‌شد).
- `sarmad-kiosk-v11` به فهرست کش‌های مدل اضافه شد تا وزن‌های چهره بدون دانلود دوباره
  منتقل شوند.
- آدرس precache دقیقاً همان چیزی است که صفحه می‌خواهد: `./assets/css/device.css?v=1.2.1`.
- تطبیق دارایی‌های شل با `ignoreSearch` انجام می‌شود، پس تغییر نسخه هرگز کیوسک را آفلاین
  خراب نمی‌کند.

---

## فایل‌های تغییریافته

| فایل | تغییر |
| --- | --- |
| `app/Support/Url.php` | `CSS_VERSION`، `css()`، `asset($path, $version)`، حذف کوئری تکراری |
| `app/Core/View.php` | کمک‌تابع `$css()` + پارامتر نسخه در `$asset()` |
| `views/layouts/{app,owner,device,blank}.php`, `views/landing.php` | لینک استایل با `$css()`؛ حذف `&v=` دستی |
| `views/layouts/app.php`, `views/device/kiosk.php`, `views/manager/employees/profile.php`, `views/manager/settings/index.php`, `views/owner/customers/{form,show}.php` | استایل اینلاین صلب لوگو + کادر پیش‌نمایش |
| `assets/css/manager.css` | `.m-brand-mark` کادر ۱۳۲×۴۴ با `overflow: hidden`، کارت ۳۲۰px، قواعد چاپ ۸۰mm |
| `assets/css/device.css` | `img.k-logo` با همان سقف ۱۳۰×۴۲ |
| `assets/css/owner.css` | `.o-logo-frame` (۱۶۰×۶۰) |
| `sw.js` | کش `sarmad-kiosk-v12` + precache استایل نسخه‌دار |
| `tests/stage21_assets_test.js` | **تازه** — ۸۳ بررسی |
| `tests/service_worker_offline_test.js` | انتظار نسخهٔ `1.2.1` و کش `v12` |

## اجرای تست‌ها

```bash
node tests/stage21_assets_test.js          # 83 بررسی، بدون نیاز به سرور
node tests/service_worker_offline_test.js
node tests/kiosk_offline_test.js           # نیازمند npm i در tests/
node tests/device_qr_test.js
php tests/stage20_tests.php                # قراردادهای تسک ۲۰ (دیتابیس واقعی)
```

## مهاجرت

این تسک **هیچ تغییری در دیتابیس ندارد**. تنها پس از استقرار:

1. یک بار `Ctrl+Shift+R` روی پنل/کیوسک بزنید تا `?v=1.2.1` و `sw.js` تازه دیده شود.
2. اگر سایدبار قدیمی نمایش داده شد، تب را ببندید و باز کنید (سرویس‌ورکر نسخهٔ `v12` را
   در `activate` جایگزین می‌کند).

---

## بستهٔ الحاقی برای هاست (اکسترکت مستقیم)

```
docs/patches/patch-stage21.zip
```

- محتوای بسته **همهٔ ۲۱ فایل کامل** این تسک است (۲ فایل تازه + ۱۹ فایل بازنویسی‌شده) با
  مسیرهای **نسبت به ریشهٔ پروژه**؛ هیچ پوشهٔ اضافه‌ای داخل زیپ نیست.
- در دایرکت‌ادمین فایل را در همان پوشهٔ سایت (مثلاً `domains/example.com/public_html/`)
  اکسترکت کنید و «Overwrite» را تأیید کنید. هیچ فایل موجودی حذف نمی‌شود.
- این تسک **هیچ تغییر دیتابیسی ندارد**؛ نیازی به اجرای مهاجرت نیست.
- `config/config.php`، پوشهٔ `uploads/` و `vendor/` در بسته نیستند.
- پس از اکسترکت، یک بار `Ctrl+Shift+R` روی پنل و کیوسک بزنید. سرویس‌ورکر تازه
  (`sarmad-kiosk-v12`) در `activate` جایگزین کش قبلی می‌شود و استایل‌ها با `?v=1.2.1`
  دوباره دانلود می‌شوند.
- اگر سایدبار یا کارت پرسنلی باز هم اندازهٔ قدیمی را نشان داد، تب را ببندید و باز کنید
  (کش CSS مرورگر با پارامتر نسخه در همان درخواست اول باطل می‌شود).
