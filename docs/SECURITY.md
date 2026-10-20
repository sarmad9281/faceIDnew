# امنیت SARMAD Attendance

## چک‌لیست پیاده‌سازی‌شده

| الزام | پیاده‌سازی |
|---|---|
| هش رمز عبور | `password_hash` با bcrypt (نام الگوریتم قابل‌حمل؛ پشتیبانی argon2id) |
| عدم ذخیره Plain Text | هیچ‌جا رمز خام ذخیره نمی‌شود؛ `Security::verifyPassword` هش غیرواقعی را رد می‌کند |
| Session امن | `HttpOnly`، `SameSite=Lax`، `Secure` در HTTPS، `use_strict_mode` |
| Session Regeneration | بعد از Login و به‌صورت دوره‌ای (`session.rotate_every`) |
| Logout | پاک‌سازی کامل + حذف کوکی + چرخش توکن CSRF |
| Session Fixation | چرخش ID در Login + اتصال به fingerprint مرورگر |
| کنترل Role | Middleware `role:...` + `PermissionService` |
| جلوگیری از دسترسی مستقیم | Guard `auth` + Redirect امن + بازگشت به صفحه درخواستی (`next`) |
| CSRF | توکن در همه فرم‌ها + بررسی `Origin` + عمر محدود توکن |
| Validation سمت سرور | `App\Core\Validator` (email, min/max, phone, timezone, whitelist, ...) |
| SQL Injection | PDO Prepared Statements با `ATTR_EMULATE_PREPARES=false` |
| XSS | خروجی همه‌جا از `e()` + CSP بدون منبع خارجی |
| Brute-force | `attendance_login_attempts` + قفل حساب و محدودیت IP |
| Audit Trail | `attendance_audit_logs` برای رویدادهای مهم |
| حفاظت فایل‌ها | `.htaccess` روی `config`, `app`, `sql`, `views`, `routes`, `storage`, `docs`, `tests` |
| امنیت Upload | `uploads/.htaccess` اجرای PHP را کامل غیرفعال می‌کند؛ اعتبارسنجی تصویر در `UploadService` |

## جزئیات مهم

### ۱) محافظت از دیتابیس مشترک

سه لایه محافظت (توضیح کامل در `README.md`): `Database::table()`، `SqlGuard`، `assertPrefix`.
`SqlGuard` هر دستور ساختاری را قبل از اجرا بررسی می‌کند و در صورت وجود
`DROP`/`TRUNCATE`/`RENAME`/`GRANT`/`ALTER DATABASE`/`INTO OUTFILE`/`LOAD DATA`
یا هر ارجاع به جداول دیگر پروژه‌ها، **کل نصب را متوقف** می‌کند (به‌جای رد کردن آرام).

### ۲) دستگاه‌ها (Device)

- دستگاه ابتدا با وضعیت `pending` ثبت می‌شود و تا فعال‌سازی هیچ دسترسی ندارد.
- توکن با `att_` و ۳۲ بایت تصادفی صادر می‌شود؛ فقط هش HMAC-SHA256 (با کلید برنامه) ذخیره می‌شود.
- توکن فقط یک بار نمایش داده می‌شود؛ در UI با هشدار «فقط یک بار» نمایش داده می‌شود.
- لغو دستگاه: توکن صفر می‌شود و همه نشست‌ها (`attendance_device_sessions`) باطل می‌شوند.
- `last_seen_at`, `last_seen_ip`, `clock_offset_seconds`, `app_version` ثبت می‌شوند.

**مرحله ۴ (تبلت/Kiosk):**

- فعال‌سازی با **کد یک‌بارمصرف و زمان‌دار** (`NNNNNN-NNNN`, پیش‌فرض ۶۰ دقیقه، حداکثر ۱۰ تلاش):
  در DB فقط `code_hash` + پیشوند ۴ کاراکتری؛ کد هرگز در Audit، URL یا لاگ ذخیره نمی‌شود.
- توکن دستگاه **فقط در هدر `Authorization`** پذیرفته می‌شود؛ توکن در Query String رد می‌شود
  (رویداد `token_not_in_header`).
- بازنشانی توکن توسط مالک ⇒ توکن قدیم **و** همه نشست‌های دستگاه بلافاصله باطل؛ لغو ⇒
  `DEVICE_REVOKED`. هیچ‌کدام در دسترس مدیر نیست (`403`).
- بدون توکن معتبر هیچ داده کارمند/حضوری برنمی‌گردد؛ `customer_id`/`branch_id` همیشه از
  رکورد دستگاه استخراج می‌شود، هرگز از بدنه درخواست (مغایرت ⇒ `BRANCH_MISMATCH`/`TENANT_MISMATCH`).
- تنظیمات نوشتنی دستگاه محدود به Allow-list غیرحساس است؛ سوئیچ‌های امنیتی سمت مالک می‌مانند.
- **Kiosk/PWA:** صفحه دستگاه هیچ توکن/هش/کلیدی در HTML یا JS ندارد؛ CSP
  (`script-src 'self'`, `connect-src 'self'`, `media-src 'self' blob: mediastream:`)،
  `Referrer-Policy: no-referrer`، `Cache-Control: no-store`، `X-Robots-Tag`,
  `Permissions-Policy: camera=(self)`. Service Worker مسیر `/api/` را هرگز کش نمی‌کند و
  توکن را نه ذخیره می‌کند و نه ارسال؛ فقط پوسته (CSS/JS/آیکون/صفحه آفلاین) کش می‌شود.
- **Rate limit دستگاه:** فعال‌سازی `10,5`، heartbeat/کانفیگ/وضعیت `240,1`، نوشتن تنظیمات `60,5`،
  خطا `60,1`، فراموش‌کردن `10,5`.
- **Liveness تشخیص زنده‌بودن:** پیاده‌سازی نمی‌شود (نه کد، نه ستون).

### ۳) API

- همه پاسخ‌ها JSON استاندارد؛ HTML هرگز به کلاینت API برنمی‌گردد.
- `Content-Type` برای نوشتن باید JSON یا فرم باشد (`415` در غیر این‌صورت).
- توکن کاربر + بررسی Ability (`devices.manage`) + محدوده مشتری.
- Rate limit روی همه مسیرها؛ ورود API جداگانه محدود می‌شود.
- Cross-Origin Write پذیرفته نمی‌شود.

### ۴) لاگ‌ها

فایل: `storage/logs/YYYY-MM-DD.log` (خارج از دسترس وب، نگهداری ۶۰ روز).
دیتابیس: `attendance_audit_logs` با ستون‌های زمان، Actor، نقش، مشتری، IP، User-Agent،
مسیر، نتیجه و Meta.

`Logger::redact()` مقادیر حساس (password, token, key, ...) را قبل از نوشتن حذف می‌کند.

### ۵) تنظیمات پیشنهادی روی پروداکشن

```php
'app' => [
    'env'         => 'production',
    'debug'       => false,
    'force_https' => true,
],
'security' => [
    'password_cost'      => 12,
    'max_login_attempts' => 5,
    'lockout_minutes'    => 15,
    'trust_proxy'        => false,   // فقط اگر پشت Cloudflare/Proxy هستید true کنید
],
```

### ۶) اگر رمز دیتابیس یا کلید برنامه لو رفت

1. رمز کاربر دیتابیس را در DirectAdmin تغییر دهید و `config/config.php` را به‌روز کنید.
2. کلید برنامه (`app.key`) را عوض کنید → تمام توکن‌های دستگاه/API باطل می‌شوند
   (چون هش آن‌ها با کلید محاسبه شده است). سپس دستگاه‌ها را دوباره فعال کنید.
3. رمز همه کاربران را از پنل مالک بازنشانی کنید.
