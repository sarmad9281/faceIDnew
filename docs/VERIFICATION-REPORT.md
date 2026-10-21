# گزارش تست و تحویل مرحله اول

این سند نتیجه اجرای واقعی تست‌ها روی همین بسته است (نه نمونه/ماکت).

## محیط تست

| مورد | مقدار |
|---|---|
| PHP | 8.4.26 (build محلی) |
| سازگاری PHP | PHPCompatibility `testVersion 8.1` → **۰ ایراد** (همچنین 8.2، 8.3 و 8.4) |
| دیتابیس | MariaDB 11.8.6 (`sarmadfa_clinic`) |
| کاربر دیتابیس | `sarmadfa_youruser_lumiere` |
| پیشوند جداول | `attendance_` |
| وب‌سرور تست | PHP built-in server + `tests/router.php` (شبیه‌ساز قواعد `.htaccess`) |
| سناریوی دیپلوی | استخراج ZIP در ریشه + استخراج در زیرپوشه (`/attendance`) |

> نکته: کد برای **PHP 8.1.34** نوشته شده و روی نسخه جدیدتر اجرا شده است؛ نتیجه
> PHPCompatibility تأیید می‌کند هیچ قابلیت نسخه‌های بالاتر از 8.1 در کد استفاده نشده است.

## ۱) تست سینتکس و سازگاری

```
bash tests/lint.sh
  PHP syntax check (php -l) on every .php file:
    checked: 86 files, failures: 0
  PHP version: 8.4.26  (target: 8.1.34+)
  PHPCompatibility (testVersion 8.1): (بدون ایراد)
  LINT: OK
```

## ۲) تست‌های واحد امنیتی

```
php tests/security_tests.php
  UNIT RESULT: 65 passed, 0 failed
```

پوشش: SqlGuard (۱۹ سناریوی SQL خطرناک/مجاز)، اجبار پیشوند جداول، رد پیشوندهای
سایر پروژه‌ها، هش رمز، AES-256-GCM و تشخیص دست‌کاری ciphertext،
جلوگیری از Open Redirect، قواعد Validator و نرمال‌سازی ورودی فارسی.

نمونه شواهد SqlGuard:

| دستور | نتیجه |
|---|---|
| `DROP TABLE barber_appointments` | مسدود |
| `TRUNCATE TABLE clinic_patients` | مسدود |
| `RENAME TABLE zibaei_reservations TO x` | مسدود |
| `ALTER TABLE sarmadpf_posts ...` | مسدود |
| `GRANT ALL ON *.*` | مسدود |
| `SELECT 1 INTO OUTFILE ...` | مسدود |
| `LOAD DATA INFILE ...` | مسدود |
| `INSERT INTO attendance_users ...` | مجاز |
| `UPDATE attendance_users ...` | مجاز |
| `sql/attendance_install.sql` (کل فایل) | مجاز |

## ۳) نصب واقعی از صفر (روی ZIP استخراج‌شده)

```
bash tests/install_run.sh http://127.0.0.1:8081
  INSTALL: SUCCESS
```

خروجی Installer: `دستورات اجراشده SQL: 20`، `جداول ایجادشده: 15`،
`حساب مالک: owner`، `حساب مدیر مشتری: manager` و قفل شدن نصب.

جداول ایجادشده (همه با پیشوند `attendance_`):

```
attendance_api_request_logs   attendance_api_tokens    attendance_audit_logs
attendance_branches           attendance_customers     attendance_device_sessions
attendance_devices            attendance_employees     attendance_login_attempts
attendance_managers           attendance_password_resets  attendance_roles
attendance_schema_migrations  attendance_settings      attendance_users
```

جداول سایر پروژه‌ها که قبل از نصب وجود داشتند و **دست‌نخورده** ماندند:

```
barber_appointments  cafe_orders  clinic_patients  sarmadpf_posts  zibaei_reservations
```

## ۴) تست انتها-به-انتها (E2E)

```
bash tests/e2e.sh http://127.0.0.1:8081 sarmadfa_clinic sarmadfa_youruser_lumiere '...' attendance_
  RESULT: 70 passed, 0 failed, 0 skipped
```

(دو بار پشت سر هم اجرا شد؛ هر دو بار همه تست‌ها PASS — تست‌ها تکرارپذیر هستند.)

| بخش | نمونه نتایج |
|---|---|
| دیتابیس و پیشوند | ۱۵ جدول پروژه، صفر Foreign Key خارج از `attendance_`، صفر رمز Plain Text |
| قفل Installer | `/install/` و `/install/?step=2` → `404` |
| فایل‌های حساس | `config.php`, `Database.php`, `installed.lock`, SQL، قالب‌ها، routes → `403` |
| احراز هویت | ورود بدون CSRF → `419`، تلاش ناموفق ثبت شد، Payload تزریق SQL وارد نشد، ورود درست → `302`، چرخش Session ID |
| کنترل نقش | مالک `/owner` → `200`؛ مدیر → `403`؛ کاربر مهمان → `302` به `/login` |
| XSS | نام مشتری `<Script>alert(1)</Script>` به‌صورت `&lt;Script&gt;` رندر شد |
| Tenant Isolation | دستگاه هر مشتری روی `customer_id` خودش؛ فعال‌سازی متقاطع رد شد؛ `?customer=<uuid>` توسط مدیر بی‌اثر است |
| معماری دستگاه | `pending` → فعال‌سازی → توکن یک‌بارمصرف؛ ذخیره فقط هش؛ Heartbeat؛ ثبت `last_seen_at`؛ رد توکن نامعتبر (`401`)؛ رد نشست پس از Revoke |
| API | پاکت JSON استاندارد، `401`، `415`، `404`، رد توکن مالک بدون محدوده (`tenant_scope_required`)، محدوده‌دهی صریح مالک |
| CSRF/Cross-Origin | POST بدون توکن → `419` و بدون نوشتن رکورد؛ درخواست Cross-Origin → `403` |
| Logging | ثبت `auth.login.success/failed`, `auth.logout`, `device.auth.success`, `device.register`, `access.denied`, `customer.create` |
| منابع خارجی | فهرست و داده جداول سایر پروژه‌ها قبل و بعد از همه تست‌ها یکسان |

## ۵) سناریوهای دیپلوی تأییدشده

| سناریو | نتیجه |
|---|---|
| استخراج ZIP در ریشه دامنه بدون پوشه اضافه | ✅ `index.php` و `.htaccess` مستقیم در ریشه |
| نصب، ورود، پنل، دستگاه، API در ریشه | ✅ همه PASS |
| استخراج در زیرپوشه `/attendance/` | ✅ URLها `‎/attendance/...‎`، CSS/JS، فرم‌ها، API و قفل Installer درست |
| محافظت فایل حتی در زیرپوشه | ✅ `config/config.php` → `403` |
| بسته بدون فایل حساس | ✅ ZIP بدون `config.php`، بدون `installed.lock`، بدون لاگ |

## ۶) مواردی که در این مرحله ساخته **نشده‌اند** (طبق درخواست)

دوربین، Liveness Detection، تشخیص چهره، پنل کامل مدیر، پنل کامل مالک، Telegram Bot،
گزارش Excel، محاسبه حقوق و سیستم کامل شیفت.

آنچه ساخته شده صرفاً **هسته واقعی و اجراشدنی** است: نصب، احراز هویت، نقش‌ها،
معماری دیتابیس، جداسازی مشتری‌ها، معماری دستگاه، API پایه، Logging و امنیت پایه.

## ۷) دستور اجرای مجدد تست‌ها

```bash
bash tests/lint.sh                 # سینتکس + سازگاری PHP 8.1
php  tests/security_tests.php      # تست‌های واحد امنیتی
bash tests/e2e.sh <BASE_URL> <DB> <DB_USER> <DB_PASS> attendance_
```
