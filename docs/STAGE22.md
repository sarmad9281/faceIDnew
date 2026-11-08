# تسک ۲۲ — هدرهای امنیتی A+، سئوی ۱۰۰/۱۰۰ و دسترسی‌پذیری ۱۰۰/۱۰۰

**هدف:** ارتقای نمرهٔ امنیت (A+) و نمرهٔ کیفیت/سئو/دسترس‌پذیری گوگل (۱۰۰) برای تنها صفحهٔ
عمومی سامانه، بدون شکستن کیوسک، پنل‌ها یا مسیر آفلاین.

سه محور کار:

| محور | فایل اصلی | نتیجه |
|---|---|---|
| ۱. هدرهای امنیتی | `.htaccess` (+ `app/Core/Security.php`) | `Content-Security-Policy` در حالت enforce و مجموعهٔ کامل هدرهای نمره‌دار |
| ۲. سئو | `robots.txt` + `views/landing.php` | لندینگ برای خزنده باز (`Allow: /$`)، پنل/کیوسک بسته؛ canonical مطلق + متاتگ‌ها + JSON-LD |
| ۳. دسترسی‌پذیری | `views/landing.php` (+ `assets/css/landing.css`) | رفع خطاهای ARIA روی `div`/`ul`/`aside`؛ تپ‌تارگت ۴۸px؛ کنتراست AA |

---

## ۱. هدرهای امنیتی در `.htaccess` (بند ۵)

### ۱.۱ سیاست Content-Security-Policy

یک خط فیزیکی، در حالت **enforce** (نه Report-Only)، داخل `<IfModule mod_headers.c>`:

```
default-src 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:;
media-src 'self' data: blob: mediastream:;
font-src 'self' data:;
connect-src 'self' data: blob:;
worker-src 'self' blob:;
manifest-src 'self';
object-src 'none';
base-uri 'self';
form-action 'self';
frame-ancestors 'self'
```

چرا این سیاست برای همین سامانه «استاندارد و سازگار» است (همه با grep روی کد تأیید شد):

| نیاز سامانه | چگونه پوشش داده شد |
|---|---|
| هیچ CDN، فونت گوگل، iframe یا fetch خارجی در کل کد وجود ندارد | `'self'` برای script/style/font/connect کافی است |
| `<script>` اینلاین در `views/manager/attendance/index.php` و `onclick=` در `views/devices/index.php` و `views/manager/reports/index.php` | `'unsafe-inline'` در `script-src` |
| `style="…"` اینلاین (سقف‌های لوگوی تسک ۲۱) | `'unsafe-inline'` در `style-src` |
| دوربین کیوسک (`srcObject`) و صدای `assets/audio/*.mp3` | `media-src 'self' data: blob: mediastream:` |
| بارکد/کارت پرسنلی با `canvas.toDataURL()` و مدل‌های `assets/models` با fetch | `img-src … data: blob:` و `connect-src 'self'` |
| `sw.js` و کش‌کردن shell آفلاین | `worker-src 'self' blob:` + `script-src 'self'` |
| `manifest.webmanifest` | `manifest-src 'self'` |

- **`'unsafe-eval'` عمداً اضافه نشد**: در `tfjs-1.7.4.min.js`، `face-api-0.22.2.min.js`،
  `jsQR.min.js` و `qrcode.min.js` هیچ `eval()` یا `new Function()` وجود ندارد.
- **`upgrade-insecure-requests` عمداً اضافه نشد**: کیوسک‌هایی که روی شبکهٔ محلی با `http`
  کار می‌کنند با این دستور به `https` پرتاب و در صورت نبود گواهی محلی قفل می‌شدند.
- `object-src 'none'`، `base-uri 'self'`، `form-action 'self'` و `frame-ancestors 'self'`
  مسیر پلاگین، ربودن `<base>`، ربودن فرم و کلیک‌جکینگ را می‌بندند.

### ۱.۲ کیوسک سیاست سخت‌گیرانهٔ خودش را نگه می‌دارد

`Header set` هدر هم‌نامِ سمت PHP را **جایگزین** می‌کند؛ پس اگر سیاست سایت بی‌قیدوشرط
ست می‌شد، CSP سخت‌گیرانهٔ `DeviceKioskPageController` (که `script-src 'self'` بدون
`'unsafe-inline'` است و توکن بلندمدت دستگاه را محافظت می‌کند) از بین می‌رفت.

راه‌حل سازگار با Apache 2.2+ (بدون `<If>` که فقط در ۲.۴ هست):

```apache
<IfModule mod_setenvif.c>
    SetEnvIfNoCase Request_URI "^(/[^/?]*)*/device(/|\?|$)" SARMAD_KIOSK_PAGE
</IfModule>
…
Header always set Content-Security-Policy "…" env=!SARMAD_KIOSK_PAGE
```

| آدرس | نشانگر | سیاست نهایی |
|---|---|---|
| `/device` و `/device/offline` | ست می‌شود | فقط CSP سخت‌گیرانهٔ خود کیوسک |
| `/attendance/device` (نصب در زیرپوشه) | ست می‌شود | فقط CSP سخت‌گیرانهٔ خود کیوسک |
| `/api/v1/device/config` | ست می‌شود | بدون CSP سایت (پاسخ JSON است؛ تاثیری ندارد) |
| `/manager/devices`، `/assets/js/device.js` | ست **نمی‌شود** | CSP سایت |
| `/`، `/login`، `/dashboard`، `/owner/...` | ست نمی‌شود | CSP سایت |

اگر روزی `mod_setenvif` در دسترس نبود، کیوسک به سیاست عمومی سایت برمی‌گردد که هنوز هم
همهٔ نیازهایش را پوشش می‌دهد (فقط `'unsafe-inline'` اضافی است، نه کم‌کاری).

### ۱.۳ بقیهٔ هدرهای نمره‌دار

| هدر | مقدار | نکته |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | بدون تغییر |
| `X-Frame-Options` | `SAMEORIGIN` | بدون تغییر (لایهٔ قدیمیِ `frame-ancestors`) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | بدون تغییر |
| `X-Permitted-Cross-Domain-Policies` | `none` | بدون تغییر |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(self), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=(), browsing-topics=()` | دوربین کیوسک باز است؛ `fullscreen` و `clipboard-write` ذکر نشده‌اند تا روی پیش‌فرض مرورگر (همان origin) بمانند |
| `X-XSS-Protection` | `0` | توصیهٔ OWASP/MDN: این فیلتر قدیمی خاموش و CSP جایگزین آن است |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | فقط با `env=HTTPS`؛ روی HTTP هرگز ارسال نمی‌شود (RFC 6797) |

### ۱.۴ کپی PHP به‌عنوان لایهٔ دوم

`App\Core\Security::contentSecurityPolicy()` و `permissionsPolicy()` دقیقاً همان رشته‌ها را
برمی‌گردانند و `Bootstrap::boot()` حالا `Security::sendCsp()` را صدا می‌زند. نتیجه:

- روی Apache: هدر PHP با `Header always set` **جایگزین** می‌شود ⇒ همیشه **یک** هدر CSP.
- بدون `mod_headers` (nginx، `php -S` در توسعه، `AllowOverride` بسته): همان سیاست از PHP می‌رسد.
- روی `/device`: پاسخ کیوسک هدر Bootstrap را جایگزین می‌کند ⇒ فقط سیاست سخت‌گیرانهٔ کیوسک.

تست `tests/stage22_security_seo_test.js` رشتهٔ `.htaccess` و رشتهٔ PHP را directive به
directive مقایسه می‌کند، پس هیچ‌وقت بی‌سروصدا از هم دور نمی‌افتند.

### ۱.۵ باگ `Url::full()` که همین‌جا پیدا شد

`Url::full()` مسیر نصب را دوبار اضافه می‌کرد، چون `base()` خودش پوشهٔ نصب را دارد و
`to()` هم همان پوشه را اولِ مسیر می‌گذارد:

```
قبل:  Url::full('/login')  →  https://host/attendance/attendance/login   ✗
بعد:  Url::full('/login')  →  https://host/attendance/login              ✓
```

چون `canonical` و `og:image` با همین متد ساخته می‌شوند، در نصب زیرپوشه‌ای یک آدرس
دوباره‌پوشه‌ای به گوگل اعلام می‌شد. خروجی واقعی (php-wasm، PHP 8.1):

| سناریو | `Url::full('/')` | `Url::full('/login')` |
|---|---|---|
| نصب در ریشه | `https://sarmadfallahzadeh.ir/` | `https://sarmadfallahzadeh.ir/login` |
| نصب در `/attendance` | `https://sarmadfallahzadeh.ir/attendance/` | `https://sarmadfallahzadeh.ir/attendance/login` |
| `app.url` با پوشهٔ اشتباه (`/barber`) | `https://sarmadfallahzadeh.ir/attendance/` | `https://sarmadfallahzadeh.ir/attendance/login` |

---

## ۲. `robots.txt` — لندینگ باز، سامانه بسته

قاعدهٔ خزنده‌ها «بلندترین الگوی منطبق» است، بنابراین `Allow: /$` (۲ نویسه) بر
`Disallow: /` (۱ نویسه) برای خودِ آدرس ریشه غلبه می‌کند.

**باز:**

```
Allow: /$
Allow: /assets/
Allow: /manifest.webmanifest
Allow: /robots.txt
```

`/assets/` عمداً باز است: گوگل‌بات باید `landing.css` و `landing.js` را بگیرد تا صفحه را
رندر و قضاوت کند (وگرنه خطای «blocked resource» و نمرهٔ موبایل می‌سوزد).

**بسته:** کیوسک (`/device`، `/device/offline`)، ورود و پنل‌ها (`/login`، `/logout`،
`/dashboard`، `/account/`، `/manager/`، `/owner`، `/devices`، `/emergency/`)، رابط‌های
ماشینی (`/api/`، `/health`، `/install`)، و داخلِ بسته (`/app/`، `/config/`، `/docs/`،
`/routes/`، `/sql/`، `/storage/`، `/tests/`، `/tools/`، `/uploads/`، `/vendor/`،
`/views/`، `/build.sh`، `/composer.json`، `/composer.lock`، `/*.md$`) و در پایان
`Disallow: /` به‌عنوان تور ایمنی برای هر مسیر ناشناختهٔ آینده.

تست، یک **حل‌کنندهٔ واقعی robots** (با پشتیبانی `*` و `$` و قاعدهٔ بلندترین انطباق)
دارد و ۶ آدرس مجاز و ۳۷ آدرس ممنوع را بررسی می‌کند — نه فقط grep.

**نصب در زیرپوشه:** `robots.txt` همیشه از ریشهٔ دامنه سرو می‌شود. اگر بسته را در
`public_html/attendance/` گذاشته‌اید، دو خط `Allow` را عوض کنید:

```
Allow: /attendance/$
Allow: /attendance/assets/
```

هیچ `Sitemap:` در فایل نیست؛ چون فایل `sitemap.xml` در بسته وجود ندارد و ارجاع به فایل
نبود‌دار در Search Console خطا می‌دهد.

---

## ۳. سئوی `views/landing.php`

| گروه | چه اضافه شد |
|---|---|
| Canonical | `<link rel="canonical">` با آدرس **مطلق** از `Url::full('/')` (قابل override توسط کنترلر) |
| ایندکس | `<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">` |
| توضیحات | یک متغیر `$seoDescription` که هم‌زمان به `meta description`، `og:description`، `twitter:description` و JSON-LD می‌رود (۱۸۳ نویسه) |
| زبان | `hreflang="fa"` و `hreflang="x-default"` روی همان canonical |
| Open Graph | `og:type`، `og:locale`، `og:site_name`، `og:title`، `og:description`، `og:url`، `og:image` (**مطلق**)، `og:image:width/height/alt` |
| توییتر | `twitter:card=summary_large_image`، `twitter:title`، `twitter:description`، `twitter:image`، `twitter:image:alt` |
| دادهٔ ساخت‌یافته | `<script type="application/ld+json">` با سه گره: `Organization` (+`sameAs` فقط از کانال‌های واقعیِ پرشده)، `WebSite` و `SoftwareApplication` (+`featureList` از قابلیت‌های واقعی صفحه) |
| کلیدواژه | `<meta name="keywords">` (گوگل نادیده می‌گیرد؛ ممیزهای محلی می‌خوانند) |

نکتهٔ امنیتی JSON-LD: با `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`
ساخته می‌شود تا هیچ مقداری نتواند `</script>` را ببندد، و اگر encode شکست خورد بلوک
اصلاً رندر نمی‌شود (`<?php if ($jsonLd !== ''): ?>`). هیچ قیمت، امتیاز یا نظرِ ساختگی در
گراف نیست (دادهٔ ساخت‌یافتهٔ جعلی از دید گوگل spam است).

---

## ۴. دسترسی‌پذیری (`views/landing.php` و `assets/css/landing.css`)

### ۴.۱ خطاهای ARIA که رفع شد

| عنصر | خطا (axe / Lighthouse) | اصلاح |
|---|---|---|
| `<div class="hero-visual" aria-label="…">` | `aria-prohibited-attr` — نقش `generic` اجازهٔ نام‌گذاری ندارد | `role="img"` (صحنهٔ هولوگرام یک تصویرِ نام‌دار است؛ محتوای داخلی `aria-hidden` است) |
| `<div class="contact-grid" aria-label="…">` | همان خطا (needs review) | `role="group"` — گروهی از لینک‌ها، با حفظ دسترسی‌پذیری خود لینک‌ها |
| `<ul class="hero-proof" aria-label="…">` | `aria-label` روی لیست | `aria-label` حذف و `role="list"` اضافه شد (چون CSS `list-style: none` دارد، Safari/VoiceOver نقش لیست را دور می‌انداخت) |
| `<ul class="benefit-list" aria-label="…">` | همان | `role="list"` |
| `<ul class="condition-list">` | — | `role="list"` برای یکدستی |
| `<aside class="conditions-card">` داخل `<section aria-labelledby>` | `landmark-complementary-is-top-level` — landmark تو در تو | `role="note"` (از مجموعهٔ landmark خارج می‌شود، `aria-labelledby` معتبر می‌ماند) |
| `<svg class="face-svg" role="img" aria-label>` داخل درختِ `aria-hidden` | نامِ مردهٔ دسترس‌ناپذیر | `aria-hidden="true" focusable="false"` |
| دو لینک برند با `aria-label` متفاوت از متن مرئی | `label-content-name-mismatch` | `aria-label` حذف شد؛ همان متن مرئی («سرمد / سامانه حضور و غیاب هوشمند») نام دسترس‌پذیر است |

### ۴.۲ تأیید ماشینی

صفحهٔ **واقعی** با php-wasm (PHP 8.1) رندر شد (همراه با کانال‌های تماسِ نمونه)، سپس
`assets/css/landing.css` داخل آن inline و با **axe-core 4.14.0** روی jsdom بررسی شد:

```
passes=36   violations=0   incomplete=3
```

سه مورد `incomplete` محدودیت jsdom است نه خطای صفحه (`color-contrast` بدون موتور چیدمان
محاسبه نمی‌شود، و `landmark-one-main`/`page-has-heading-one` با وجود `<main id="main">` و
یک `<h1>` در jsdom «needs review» می‌شوند — با یک سند نمونهٔ کوچک هم همین نتیجه را داد).

### ۴.۳ تپ‌تارگت، اندازهٔ فونت و کنتراست (بخش SEO و a11y)

| مورد | قبل | بعد |
|---|---|---|
| `.nav-actions .btn` در `≤760px` | `min-height: 42px` | `min-height: 48px` (در پوستهٔ ۶۴px با ۸px پدینگ دقیقاً جا می‌شود) |
| فونت همان دکمه‌ها در `≤430px` | `0.74rem` = ۱۱.۸px | `0.8rem` = ۱۲.۸px (آستانهٔ «فونت خوانا» ۱۲px است) |
| `.footer-links a` | بدون پدینگ، ~۲۰px | `display: inline-flex; min-height: 48px` |
| `.main-nav` در موبایل | `display: none` در `≤1080px` | بدون تغییر (تست، آن را قفل می‌کند) |

کنتراست رنگ‌های واقعی متن روی زمینهٔ تیره (محاسبهٔ WCAG در تست):

| رنگ متن | بدترین نسبت (روی زمینهٔ صفحه، پنل و فوتر) |
|---|---|
| `--landing-text` `#f2f7ff` | ۱۷.۲۴:۱ |
| `--landing-muted` `#a9bad3` | ۹.۴۰:۱ |
| `--landing-dim` `#7286a5` (کپی‌رایت) | ۵.۰۰:۱ |
| `#cbd8ed` (لیست مزیت‌ها) | ۱۲.۸۸:۱ |
| `#dbe7f8` (لیست نمایندگی) | ۱۴.۸۳:۱ |

همه بالای ۴.۵:۱ (AA) هستند.

---

## فایل‌های تغییریافته

| فایل | تغییر |
|---|---|
| `.htaccess` | بند ۵ بازنویسی شد: CSP + Permissions-Policy + X-XSS-Protection + HSTS و استثنای کیوسک |
| `robots.txt` | بازنویسی کامل: `Allow: /$`، لیست صریح Disallow، تور ایمنی `Disallow: /` |
| `views/landing.php` | بلوک سئو (canonical، robots، OG، Twitter، hreflang، JSON-LD) + اصلاحات ARIA |
| `app/Core/Security.php` | `contentSecurityPolicy()` و `permissionsPolicy()` هم‌تراز با `.htaccess` + `X-XSS-Protection: 0` |
| `app/Bootstrap.php` | فراخوانی `Security::sendCsp()` به‌عنوان لایهٔ دوم |
| `app/Support/Url.php` | رفع باگ دوبل‌شدن پوشهٔ نصب در `Url::full()` |
| `assets/css/landing.css` | تپ‌تارگت ۴۸px و فونت ≥۱۲px |
| `tests/stage22_security_seo_test.js` | **تازه** — ۲۲۶ بررسی |
| `docs/STAGE22.md` | **تازه** — همین سند |
| `README.md` | فرمان تست تسک ۲۲ |
| `docs/SECURITY.md` | سطر CSP/هدرها در چک‌لیست |

---

## اجرای تست‌ها

```bash
node tests/stage22_security_seo_test.js    # 226 بررسی: هدرها، robots، سئو، ARIA، CSS
node tests/stage21_assets_test.js          # 85 بررسی تسک ۲۱ (رگرسیون)
node tests/kiosk_offline_test.js
node tests/service_worker_offline_test.js
node tests/kiosk_mobile_css_test.js
node tests/live_presence_test.js
node tests/queue_clear_test.js
node tests/device_qr_test.js
```

---

## مهاجرت و استقرار

این تسک **هیچ تغییری در دیتابیس ندارد**.

1. فایل‌ها را در `public_html` اکسترکت کنید (Overwrite).
2. هدرها را بررسی کنید:
   ```bash
   curl -sI https://YOUR-DOMAIN/ | grep -iE 'content-security|x-frame|x-content|referrer|permissions|strict-transport|x-xss'
   curl -sI https://YOUR-DOMAIN/device | grep -i content-security   # باید فقط سیاست سخت‌گیرانهٔ کیوسک باشد
   ```
3. اگر `Content-Security-Policy` در خروجی `curl` نبود، یعنی `mod_headers` روی هاست فعال
   نیست؛ در دایرکت‌ادمین از بخش Apache Handlers/custom config فعالش کنید. حتی در آن حالت
   هم کپی PHP (بند ۱.۴) هدر را می‌فرستد.
4. `robots.txt` را در Search Console با ابزار Robots.txt Tester بررسی کنید و آدرس ریشه را
   برای ایندکس مجدد Submit کنید.
5. یک بار `Ctrl+Shift+R` روی پنل و کیوسک بزنید (تغییر CSS لندینگ).

> نکتهٔ عیب‌یابی: اگر در کنسول مرورگر خطای `Refused to …` دیدید، منبعی است که از دامنهٔ
> دیگر بارگذاری می‌شود؛ یا آن را به همان origin برگردانید یا آن origin را به همان
> directive در `.htaccess` **و** در `Security::contentSecurityPolicy()` اضافه کنید (تست،
> هم‌ترازی این دو را کنترل می‌کند).

---

## بستهٔ الحاقی برای هاست (اکسترکت مستقیم)

```
docs/patches/patch-stage22.zip
```

- محتوای بسته **همهٔ ۱۱ فایل کامل** این تسک است (۲ فایل تازه: `docs/STAGE22.md` و
  `tests/stage22_security_seo_test.js` + ۹ فایل بازنویسی‌شده) با مسیرهای **نسبت به ریشهٔ
  پروژه**؛ هیچ پوشهٔ اضافه‌ای داخل زیپ نیست:

  ```
  .htaccess                              README.md
  app/Bootstrap.php                      app/Core/Security.php
  app/Support/Url.php                    assets/css/landing.css
  docs/SECURITY.md                       docs/STAGE22.md
  robots.txt                             tests/stage22_security_seo_test.js
  views/landing.php
  ```

- مشخصات بسته برای راستی‌آزمایی پس از دانلود: **۶۲٬۹۰۲ بایت**،
  `sha256 = b82acb627254dcb77fc4bbabcb9836704cecdc740c216802ade805b85404c64d`
  (با `sha256sum docs/patches/patch-stage22.zip` مقایسه کنید).
- در دایرکت‌ادمین در همان پوشهٔ سایت (`domains/example.com/public_html/`) اکسترکت کنید و
  «Overwrite» را تأیید کنید. هیچ فایل موجودی حذف نمی‌شود.
- `config/config.php`، پوشهٔ `uploads/` و `vendor/` در بسته نیستند.
- پس از اکسترکت: `Ctrl+Shift+R` روی لندینگ و بررسی هدرها با `curl -sI`.
- **اگر `.htaccess` روی هاست دستی ویرایش شده است**، قبل از Overwrite یک نسخهٔ پشتیبان
  بگیرید؛ این بسته کل فایل را با نسخهٔ مخزن جایگزین می‌کند.
