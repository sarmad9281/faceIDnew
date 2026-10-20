# گزارش تحویل و تست مرحله ۴ — دستگاه حضور، PWA و حالت Kiosk

**پروژه:** SARMAD Attendance (کاملاً مستقل از سایر پروژه‌ها)
**مرحله:** ۴ — «تبلت/موبایل/PC محل کسب‌وکار به یک دستگاه حضور ثابت تبدیل می‌شود»
**تاریخ:** ۱۴ مهر ۱۴۰۵ (2026-10-06)
**وضعیت:** تحویل‌شده و تست‌شده — **۹۶۶ بررسی، ۰ ناموفق**

---

## ۰) خلاصه اجرایی

در این مرحله یک دستگاه (تبلت/موبایل/کامپیوتر) در محل کسب‌وکار به «دستگاه حضور و غیاب»
تبدیل می‌شود: اپلیکیشن **PWA** با قابلیت نصب، اجرای **Standalone/تمام‌صفحه** و یک
**Kiosk** تیره، مدرن، فارسی/RTL و لمسی که کارمند روی آن هیچ نام کاربری/رمز/PIN وارد نمی‌کند؛
فقط **ورود/خروج → دوربین → شناسایی → ثبت**.

نکات صادقانه و بدون ادعای نادرست (طبق درخواست صریح):

1. **موتور تشخیص چهره در این مرحله ساخته نشده است.** `GET /api/v1/device/engine` و
   `POST /api/v1/device/recognition` فقط **قرارداد (Contract)** هستند؛ پاسخ واقعی
   `503 FACE_ENGINE_UNAVAILABLE` است و هیچ بردار/Embedding/مدلی در کد وجود ندارد.
   اتصال موتور واقعی کار مرحله ۵ است (`docs/ROADMAP-STAGE-4.md`).
2. **تشخیص Liveness (زنده‌بودن) هرگز پیاده‌سازی نشده و نمی‌شود** — نه کد، نه ستون دیتابیس،
   نه گزینه. تضمین `'liveness' => 'not_used'` در قرارداد موتور و تست شمارشی در سوئیت
   (شمارش کد/ستون liveness = ۰) این را قفل می‌کند.
3. **ثبت واقعی حضور در این مرحله نوشته نمی‌شود:** `POST /api/v1/device/attendance`
   قرارداد است و با `503 ATTENDANCE_ENGINE_NOT_READY` پاسخ می‌دهد؛ هیچ ردیفی در
   `attendance_records` نوشته نمی‌شود (این نکته در تست هم بررسی می‌شود).
4. **نصب/اجرای PWA در مرورگر واقعی قابل مشاهده نیست:** در این محیط مرورگر بدون سر
   (headless Chrome) وجود ندارد؛ بنابراین نصب، حالت Standalone و فعال‌شدن Service Worker
   به‌صورت **ایستا** (manifest، محتوای `sw.js`، فهرست precache، هندلر `beforeinstallprompt`،
   پوسته آفلاین) بررسی شده و به‌عنوان «تأییدشده در مرورگر» ادعا **نمی‌شود**.
5. **صف آفلاین فقط «ساختار» است:** UI آفلاین + وضعیت اتصال + ساختار صف محلی آماده است،
   اما ارسال/بازپخش صف کار مرحله موتور حضور است (بدون هیچ ذخیره‌سازی حساس بدون طراحی امنیتی).

---

## ۱) دامنه مرحله ۴ — چه ساخته شد و چه چیزی عمداً ساخته نشد

| موضوع | وضعیت |
|---|---|
| ثبت/فعال‌سازی دستگاه با **کد فعال‌سازی یک‌بارمصرف و زمان‌دار** | ✅ ساخته و تست شد |
| توکن دستگاه (تصادفی/بلند/فقط هش در DB/قابل ابطال و بازنشانی) | ✅ |
| احراز هویت دستگاه روی همه APIها با `Authorization: Bearer` | ✅ (هدر فقط) |
| Kiosk UI (لوگو، نام مجموعه، شعبه، ساعت/تاریخ، دو دکمه بزرگ ورود/خروج) | ✅ |
| حالت‌های دوربین (پیش‌نمایش، کادر چهره، راهنما، Loading، شناسایی، موفق، خطا) | ✅ |
| پاسخ‌های موتور (بدون چهره / چند چهره / چهره ناشناس) با متن‌های دقیق فارسی | ✅ |
| صفحه موفقیت (نام، عکس در صورت وجود، نوع عملیات، ساعت، وضعیت) + بازگشت خودکار | ✅ |
| Heartbeat هر ۶۰ ثانیه + Last Seen + وضعیت آنلاین در پنل مالک/مدیر | ✅ |
| PWA کامل (manifest، Service Worker، آیکون‌ها، Install Prompt، Standalone، پوسته آفلاین) | ✅ (با محدودیت بند ۰.۴) |
| سخت‌سازی Kiosk (عدم دسترسی به پنل‌ها، عدم نمایش توکن، مسیر امن خروج/تنظیمات) | ✅ |
| تنظیمات غیرحساس دستگاه (صدا، مدت پیام، ساعت، تست دوربین، وضعیت اتصال) | ✅ |
| لاگ دستگاه (فعال‌سازی، احراز هویت، Heartbeat، بازنشانی توکن، غیرفعال‌سازی، خطا، ثبت) | ✅ |
| **موتور واقعی چهره / ثبت ۳ نمونه / Embedding / TensorFlow.js / face-api.js** | ❌ مرحله ۵ |
| **محاسبه حضور، تأخیر، اضافه‌کار، تلگرام، اکسل، موتور حقوق** | ❌ مراحل بعد |
| **Liveness** | ❌ هرگز (طبق سیاست پروژه) |

---

## ۲) محیط تست

| مورد | مقدار |
|---|---|
| نسخه هدف | PHP **8.1.34** |
| نسخه اجرا | PHP **8.4.26** (بدون هیچ سینتکس 8.2+؛ `tests/lint.sh` تمیز) |
| دیتابیس | MariaDB **11.8.6** — `sarmadfa_clinic`، کاربر `sarmadfa_youruser_lumiere` |
| پیشوند جداول | **`attendance_`** (تنها پیشوند استفاده‌شده) |
| وب‌سرور تست | PHP built-in + `tests/router.php` (شبیه‌ساز `.htaccess`): منبع `:8080`، نصب تازه ZIP `:8090` |
| مسیر آزمون نهایی | استخراج ZIP در مسیر تازه `/tmp/verify-stage4` + دیتابیس خالی + نصب کامل |

---

## ۳) آزمون نصب تازه (ZIP → دیتابیس خالی) — شاهد

```
قبل از نصب:
  جداول attendance_* در دیتابیس: 0   (۳۱ جدول مرحله قبل حذف شد)
  جداول پروژه‌های دیگر (دست‌نخورده): barber_appointments, cafe_orders,
                                     clinic_patients, zibaei_reservations, sarmadpf_posts
  GET /  →  302  →  /install/            (ریدایرکت درست پیش از نصب)

استخراج ZIP: /tmp/verify-stage4  (بدون config/config.php و بدون storage/installed.lock — طبق build.sh)
SARMAD_TEST_DB_PASS='…' bash tests/install_run.sh http://127.0.0.1:8090
  →  INSTALL: SUCCESS

پس از نصب:
  جداول پروژه: 31
  schema_migrations: 2026_01_01_000000_initial_schema
                     2026_02_01_000000_owner_panel
                     attendance_upgrade_2.0.0
                     attendance_upgrade_3.0.0
                     attendance_upgrade_4.0.0
  storage/installed.lock  →  ساخته شد
  نصب دوم  →  رد شد: «SARMAD Attendance قبلاً نصب شده است و نصب‌کننده غیرفعال شده است.»
  php tools/migrate.php status  →  «جداول پروژه: 31 / ساختار دیتابیس به‌روز است.»
```

**تأیید روی دقیقاً همان ZIP تحویلی (پس از افزودن مستندات):** همان بسته در مسیر تازه
`/tmp/verify-final` استخراج، دیتابیس دوباره خالی و نصب از صفر اجرا شد
(`INSTALL: SUCCESS`، ۳۱ جدول) و **همان نتایج** به‌دست آمد: lint OK، امنیت ۶۹/۰،
audit ۲۳۲۶/۰ و سوئیت‌ها ۷۰/۲۱۸/۲۷۱/۳۳۸ روی `http://127.0.0.1:8091`.
همچنین در همان نصب تازه: مسیرهای `/config/config.php`, `/app/Services/DeviceService.php` و
`/views/device/kiosk.php` → **۴۰۳**، اما `manifest.webmanifest`, `sw.js`, آیکون‌ها،
`/device` و `/device/offline` → **۲۰۰**، و `/dashboard/device` → **۳۰۲ به `/device`**.

**جدول‌های سایر پروژه‌ها قبل و بعد از نصب دست‌نخورده ماندند** (کنترل سه‌لایه:
`Database::table()` + `SqlGuard` + `assertPrefix`). هیچ جدول/داده‌ای خارج از `attendance_*`
ساخته، حذف یا تغییر نکرد.

---

## ۴) جداول ساخته‌شده در مرحله ۴ (۳. جداول)

فایل `sql/attendance_upgrade_4.0.0.sql` (**فقط `CREATE TABLE`** — بدون `DROP`/`ALTER`)،
اجرا شده توسط `MigrationService`:

| جدول | کاربرد | نکته امنیتی |
|---|---|---|
| `attendance_device_activation_codes` | کد فعال‌سازی یک‌بارمصرف (۱۴ ستون) | فقط `code_hash` (HMAC-SHA256) + `code_prefix` (۴ کاراکتر برای نمایش به مالک)؛ انقضا، `used_at`، `attempts`، `max_attempts` |
| `attendance_device_settings` | تنظیمات غیرحساس هر دستگاه | Allow-list در مدل؛ سوئیچ‌های امنیتی از سمت مالک |
| `attendance_device_logs` | لاگ رویدادهای دستگاه (۱۳ نوع رویداد، سطوح info/warning/error) | `device_id` + `customer_id` برای جداسازی مستأجر |
| `attendance_device_requests` | Idempotency ثبت‌ها | `UNIQUE (device_id, request_key)` — دیتابیس مرجع جلوگیری از ثبت دوباره |

ستون‌های اضافه‌شده به `attendance_devices` از مسیر `MigrationService::COLUMN_ADDITIONS`
(نه `ALTER` داخل فایل SQL): `last_error_at`, `last_error_code`.

**مجموع جداول پروژه: ۳۱** — ۱۵ (مرحله ۱) + ۴ (مرحله ۲) + ۸ (مرحله ۳) + ۴ (مرحله ۴).

---

## ۵) فایل‌های جدید مرحله ۴ (۱. فایل‌های ساخته‌شده)

| فایل | خط | توضیح |
|---|---|---|
| `sql/attendance_upgrade_4.0.0.sql` | ۱۳۳ | ۴ جدول مرحله ۴ (فقط CREATE) |
| `app/Models/DeviceActivationCode.php` | ۳۰۷ | صدور/بررسی/مصرف کد، قفل پس از تلاش‌های ناموفق، وضعیت کدها برای پنل مالک |
| `app/Models/DeviceLog.php` | ۲۴۲ | لاگ دستگاه + `recentForDevices` + شمارش خطا |
| `app/Models/DeviceSetting.php` | ۲۲۶ | تنظیمات مجاز دستگاه (Allow-list) |
| `app/Models/DeviceRequest.php` | ۱۳۵ | Idempotency (`claim`/`finish`) |
| `app/Api/Controllers/DeviceKioskController.php` | ۴۰۰ | ۱۰ اندپوینت دستگاه (زیر) |
| `app/Controllers/DeviceKioskPageController.php` | ۱۰۲ | صفحه Kiosk + صفحه آفلاین + هدرهای امنیتی/کش |
| `views/device/kiosk.php` | ۳۳۴ | رابط Kiosk (حالت‌های دستگاه، صفحه موفق/خطا/مسدود/فعال‌سازی) |
| `views/device/offline.php` | ۲۹ | پوسته آفلاین (PWA) |
| `views/layouts/device.php` | ۷۵ | چیدمان اختصاصی دستگاه (بدون منوی پنل) |
| `assets/css/device.css` | ۱۱۱۸ | تم تیره/پریمیوم/RTL/لمسی، دکمه‌های بسیار بزرگ |
| `assets/js/device.js` | ۱۶۰۸ | دوربین، حالت‌های UI، heartbeat، آفلاین، صف، نصب PWA، تنظیمات |
| `manifest.webmanifest` | ۵۱ | نام «SARMAD Attendance»، `fa-IR`/`rtl`، standalone+fullscreen، آیکون‌ها |
| `sw.js` | ۱۱۱ | Service Worker (هرگز `/api/` را کش نمی‌کند) |
| `assets/icons/{icon-192,icon-512,maskable-512,apple-touch-icon,favicon-48}.png` | — | ۵ آیکون PWA |
| `tests/e2e_stage4.sh` | ۹۲۵ | سوئیت پذیرش مرحله ۴ (۳۳۸ بررسی) |

---

## ۶) فایل‌های تغییر یافته در مرحله ۴ (۲. فایل‌های تغییر یافته)

| فایل | تغییر |
|---|---|
| `routes/api.php` | ۱۰ مسیر دستگاه (`activate`, `config`, `status`, `settings` GET/POST, `engine`, `recognition`, `attendance`, `error`, `forget`) + بازهدایت `heartbeat` به `DeviceKioskController::heartbeat` |
| `routes/web.php` | `/device` و `/device/offline` (بدون CMS/منو) + `GET /dashboard/device` → ریدایرکت ۳۰۲ به `/device` |
| `app/Controllers/DashboardController.php` | متد `device()` از «صفحه نمایشی جعلی» به ریدایرکت واقعی به مسیر یکتای Kiosk تغییر کرد |
| `app/Services/DeviceService.php` | `issueActivationCode`, `activateWithCode`, `configFor`, `engineContract`, `applySettings`, `heartbeatFor`, `configVersion`, `recognitionContract`, `attendanceContract`, `engineResponse`, `authenticateTokenDetailed`, `attendanceEnabled` |
| `app/Api/Middleware/DeviceAuth.php` | توکن **فقط** از هدر `Authorization`؛ کدهای دقیق خطا؛ یک ردیف لاگ دستگاه در هر موفقیت؛ Audit کپی throttle‌شده (۱ در ۳۰۰ ثانیه برای هر دستگاه) |
| `app/Controllers/Owner/DeviceController.php` | اکشن `activationCode()` + بازنشانی توکن که کد فعال‌سازی واقعی صادر می‌کند؛ Audit با **هویت موجودیت** (نه فقط متن) |
| `views/owner/devices/index.php` | ستون/دکمه «کد فعال‌سازی یک‌بارمصرف» + جدول آخرین رویدادهای دستگاه + شمارش خطای ۶۰ دقیقه |
| `app/Services/MigrationService.php` | افزودن `attendance_upgrade_4.0.0.sql` + ستون‌های `last_error_*` |
| `app/Core/Security.php` | `deviceCode()` = کد فعال‌سازی `NNNNNN-NNNN` (نرمال‌سازی حروف/جداکننده‌ها) |
| `app/Api/ApiResponse.php` | `deviceSuccess()` / `deviceError()` — پاکت استاندارد دستگاه |
| `app/Models/Device.php` | `tokenExpired()`, وضعیت‌های دستگاه، پشتیبانی ستون‌های خطای آخر |
| `app/Models/DeviceSession.php` | `revokeAllForDevice()` (بازنشانی توکن ⇒ باطل‌شدن فوری نشست‌ها) |
| `app/Models/AuditLog.php`, `app/Services/AuditService.php` | واژگان مرحله ۴ (`ISSUE_ACTIVATION_CODE`, `DEVICE_ACTIVATION`, `ACTIVATION_FAILED`, `UPDATE_DEVICE_SETTINGS`, `DEVICE_ERROR`, `DELETE_DEVICE`) |
| `app/Models/Employee.php` | نگه‌داشتن قرارداد `canAttend()` برای مرحله ۵ (بدون تغییر رفتار) |
| حذف‌شده: `views/dashboard/device.php` | صفحه Placeholder قدیمی حذف شد تا هیچ‌کس آن را با Kiosk واقعی اشتباه نگیرد |
| `README.md` و `docs/*` | مستندات مرحله ۴ (این گزارش + نقشه راه مرحله ۵ + معماری/امنیت) |

---

## ۷) APIهای ساخته‌شده (۴. APIها)

**پاکت پاسخ (یکسان برای همه اندپوینت‌های دستگاه):**

```json
{ "success": true,  "code": "OK",                 "message": "…", "data": {}, "meta": {} }
{ "success": false, "code": "DEVICE_UNAUTHORIZED","message": "…", "error": {} }
```

| متد | مسیر | احراز هویت | Rate limit | کار |
|---|---|---|---|---|
| POST | `/api/v1/device/activate` | عمومی (خودِ کد، اعتبار است) | `10,5` | تبدیل کد یک‌بارمصرف به توکن دستگاه |
| POST | `/api/v1/device/login` | توکن دستگاه در Body (سازگاری مرحله ۱) | `60,5` | تبدیل توکن به نشست `att_sess_*` |
| GET | `/api/v1/device/me` | `device.token` | `120,1` | هویت دستگاه |
| POST | `/api/v1/device/heartbeat` | `device.token` | `240,1` | Last Seen + زمان سرور + نسخه کانفیگ + تنظیمات در انتظار |
| GET | `/api/v1/device/config` | `device.token` | `240,1` | برندینگ/لوگو/شعبه/زبان/تقویم/فیچرها/تنظیمات/قرارداد موتور |
| GET | `/api/v1/device/status` | `device.token` | `240,1` | وضعیت دستگاه + وضعیت سرور |
| GET | `/api/v1/device/settings` | `device.token` | `240,1` | خواندن تنظیمات مجاز |
| POST | `/api/v1/device/settings` | `device.token` | `60,5` | نوشتن تنظیمات **غیرحساس** (Allow-list) |
| GET | `/api/v1/device/engine` | `device.token` | `240,1` | **قرارداد** موتور: `face_engine: unavailable`, `stage: 5`, `liveness: not_used` |
| POST | `/api/v1/device/recognition` | `device.token` | `240,1` | `request_id` اجباری، idempotency، پاسخ `503 FACE_ENGINE_UNAVAILABLE` |
| POST | `/api/v1/device/attendance` | `device.token` | `240,1` | **قرارداد** ثبت: بررسی مستأجر/کارمند/شعبه سپس `503 ATTENDANCE_ENGINE_NOT_READY` (بدون نوشتن رکورد) |
| POST | `/api/v1/device/error` | `device.token` | `60,1` | ثبت خطای دستگاه (کد در `devices.last_error_code`) |
| POST | `/api/v1/device/forget` | `device.token` | `10,5` | پاک‌کردن اعتبار محلی دستگاه |

**کدهای پاسخ پیاده‌شده:** `OK` · `DEVICE_UNAUTHORIZED` · `DEVICE_REVOKED` · `DEVICE_INACTIVE`
· `CUSTOMER_DISABLED` · `FEATURE_DISABLED` · `ACTIVATION_CODE_REQUIRED/INVALID` ·
`ACTIVATION_CODE_USED/EXPIRED` · `ACTIVATION_CODE_LOCKED` · `INVALID_REQUEST_ID` ·
`DUPLICATE_REQUEST` · `EMPLOYEE_NOT_FOUND` · `EMPLOYEE_INACTIVE` · `BRANCH_MISMATCH` ·
`NO_FACE` · `MULTIPLE_FACES` · `UNKNOWN_FACE` · `FACE_ENGINE_UNAVAILABLE` ·
`ATTENDANCE_ENGINE_NOT_READY` · `NO_SETTINGS/TOO_MANY_SETTINGS` · `PAYLOAD_TOO_LARGE`.

**API پنل مالک (خارج از دستگاه):** `POST /owner/devices/activation-code` (مالک‌فقط، CSRF،
کد یک‌بار و فقط یک‌بار نمایش داده می‌شود) و اکشن‌های `reset` (بازنشانی توکن ⇒ صدور کد جدید)،
`rotate-token`، `revoke`، `delete` — همه مالک‌فقط و Audited. مدیر در همین مسیرها `403` می‌گیرد
(تست‌شده).

---

## ۸) وضعیت PWA (۵. وضعیت PWA)

| مورد | وضعیت | شاهد |
|---|---|---|
| `manifest.webmanifest` | ✅ | نام «SARMAD Attendance»، `fa-IR`, `dir: rtl`, `start_url: ./device`, `scope: ./`, `display: standalone` + `display_override: fullscreen`, `theme_color/background_color: #070b16`, آیکون‌های ۱۹۲/۵۱۲/maskable/apple-touch، shortcut |
| Service Worker | ✅ | `sw.js` (۳۵۳۳ بایت): `/api/` هرگز کش نمی‌شود، پوسته دستگاه precache می‌شود، ناوبری Network-first با fallback به `./device/offline`، فقط GET هم‌مبدأ |
| آیکون‌ها | ✅ | ۵ فایل PNG (۱۹۲/۵۱۲/maskable 512/apple 180/favicon 48) — همه با HTTP 200 |
| Install Prompt | ✅ (کد) | هندلر `beforeinstallprompt` در `assets/js/device.js` (خط ۱۳۲۹) |
| حالت Standalone | ✅ (کد) | تشخیص `display-mode: standalone` و مسیر اجرای تمام‌صفحه |
| پوسته آفلاین | ✅ | `/device/offline` (۲۰۰، `data-offline-shell="1"`) + بنر آفلاین + وضعیت اتصال در Kiosk |
| محدودیت | ⚠️ | نصب واقعی/Standalone/فعال‌شدن SW در این محیط مرورگر قابل مشاهده نیست (بند ۰.۴) — **ایستا** بررسی شده |
| سخت‌سازی مسیرها | ✅ | `/views/` با ۴۰۳ بسته است؛ فایل‌های PWA در ریشه پروژه هستند تا مستقیماً سرو شوند |
| هدرهای صفحه دستگاه | ✅ | CSP (`script-src 'self'`, `connect-src 'self'`, `media-src 'self' blob: mediastream:`)، `Referrer-Policy: no-referrer`، `Cache-Control: no-store`، `X-Robots-Tag`، `Permissions-Policy: camera=(self)` |

---

## ۹) وضعیت احراز هویت دستگاه (۶. وضعیت احراز هویت دستگاه)

- **ورود بدون رمز:** روی دستگاه هیچ‌کس نام کاربری/رمز/PIN وارد نمی‌کند؛ فقط
  «کد فعال‌سازی یک‌بارمصرف» یک بار توسط مالک تولید و روی دستگاه وارد می‌شود.
- **کد فعال‌سازی:** `NNNNNN-NNNN`، انقضای پیش‌فرض ۶۰ دقیقه، حداکثر ۱۰ تلاش،
  **فقط هش** در DB (`code_hash` + `code_prefix` چهارکاراکتری برای نمایش به مالک).
  مصرف یک‌بارمصرف و پاسخ‌یک‌بار: بار دوم `409 ACTIVATION_CODE_USED`.
- **توکن دستگاه:** `att_` + ۳۲ بایت تصادفی؛ در DB فقط `api_token_hash` (HMAC-SHA256، ۶۴ کاراکتر)؛
  **توکن خام هرگز در دیتابیس/لاگ/Audit نیست** (تست: `token_prefix` فقط برای شناسایی).
- **محل نگهداری در دستگاه:** `localStorage` با کلید Namespaced؛ هرگز در URL، هرگز در Service Worker،
  هرگز در لاگ سرور. خروج از دستگاه با `POST /api/v1/device/forget` پاک می‌شود.
- **فقط هدر `Authorization`:** توکن دستگاه از Query String پذیرفته **نمی‌شود**
  (`DEVICE_UNAUTHORIZED` + ثبت رویداد `token_not_in_header`).
- **بازنشانی توسط مالک:** توکن جديد صادر و **همه نشست‌ها بلافاصله باطل** می‌شوند
  (`DeviceSession::revokeAllForDevice`) ⇒ دستگاه باید دوباره فعال شود.
- **لغو دستگاه:** هش توکن صفر و همه نشست‌ها باطل؛ هر اعتبار قبلی `DEVICE_UNAUTHORIZED`
  و تلاش برای گرفتن توکن جدید `DEVICE_REVOKED` می‌گیرد.
- **بدون توکن معتبر، هیچ داده‌ای برنمی‌گردد:** همه اندپوینت‌های داده با `device.token` محافظت
  شده‌اند؛ بدون توکن `401` + `{"success":false,"code":"DEVICE_UNAUTHORIZED"}`.
- **مالک/مدیر هرگز توکن را نمی‌بینند** — در پنل فقط «وضعیت، آخرین بازدید، خطای آخر» نمایش داده می‌شود.
- **مدیر:** فقط دستگاه‌های مشتری خودش را می‌بیند؛ بازنشانی/چرخش/لغو/حذف برای او `403` است.
- **دستگاه نمی‌تواند شعبه/مشتری خود را تغییر دهد:** `customer_id` و `branch_id` هرگز از بدنه
  درخواست خوانده نمی‌شود؛ از توکن و رکورد دستگاه استخراج می‌شود و مغایرت با `BRANCH_MISMATCH` رد می‌شود.

---

## ۱۰) رابط Kiosk (UI) — شاهد تست

| مورد | شاهد در تست |
|---|---|
| لوگو + نام مجموعه + نام شعبه در بالا | `id="k-business"`, `id="k-branch"`, لوگو از سرور |
| ساعت/تاریخ در وسط | `id="k-clock-time"` + تقویم شمسی/میلادی از کانفیگ سرور |
| دو دکمه بسیار بزرگ «ورود»/«خروج» | `id="k-action-entry"` / `id="k-action-exit"` با برچسب `ورود`/`خروج` |
| حالت‌های دوربین | `preview`, کادر جای‌گیری چهره (`id="k-face-frame"`), راهنما (`id="k-camera-hint"`), `loading`, chip شناسایی (`id="k-chip-recognizing"`) |
| صفحه موفقیت | `id="kiosk-success"` (نام، عکس، نوع عملیات، ساعت، پیام «ورود با موفقیت ثبت شد») |
| صفحه خطا | `id="kiosk-error"` + متن دقیق هر کد (`NO_FACE`/`MULTIPLE_FACES`/`UNKNOWN_FACE`) |
| صفحه مسدود/فعال‌سازی | `id="kiosk-blocked"`, `id="kiosk-activation"` |
| آفلاین + وضعیت اتصال | `k-banner-offline`, `id="k-status-text"`, شمارنده صف |
| تنظیمات غیرحساس | کشوی تنظیمات (`id="k-drawer"`), تست دوربین (`id="k-camera-test"`), صدا/مدت پیام/ساعت — سوئیچ‌های امنیتی در دستگاه وجود ندارد |
| بدون منوی مدیریتی | هیچ لینک پنل مدیر/مالک در صفحه دستگاه؛ تست: توکن دستگاه در پنل‌ها همیشه ۳۰۲/۴۰۳ |

---

## ۱۱) امنیت، جداسازی مستأجر و لاگ (شواهد تست‌شده)

- **جداسازی مستأجر:** دستگاه A با توکن معتبر خودش به هیچ داده‌ای از مشتری B دسترسی ندارد
  (کارمند مشتری دیگر `404 EMPLOYEE_NOT_FOUND`، تنظیمات/کانفیگ فقط از `customer_id` توکن).
- **تلاش برای دستکاری شناسه‌ها:** ارسال `customer_id`/`branch_id`/`device_id` جعلی در بدنه
  ⇒ نادیده گرفته می‌شود یا `BRANCH_MISMATCH`/`TENANT_MISMATCH`؛ هیچ درخواست دستی نمی‌تواند
  مستأجر را عوض کند.
- **توکن دستگاه دیگر:** `401 DEVICE_UNAUTHORIZED`.
- **CSRF:** بخش‌های مرورگری پنل‌ها CSRF دارند؛ دستگاه **توکن Bearer** دارد (بدون کوکی نشست)
  و «نوشتن از مبدأ دیگر» (`Origin` متفاوت) رد می‌شود (تست: ۴۰۳ حتی با توکن).
- **XSS:** نام دستگاه/مشتری حاوی HTML به‌صورت متن ذخیره و در همه نماها Escape می‌شود
  (تست: هم در پنل مدیر و هم در پنل مالک `0` نسخه خام، `1` نسخه Escape‌شده).
- **CSRF/XSS در Kiosk:** صفحه دستگاه هیچ توکن/کلید/هش را در HTML یا JS تزریق نمی‌کند
  (تست: «kiosk page ships no token material» = ۰).
- **Rate limiting:** فعال روی همه مسیرهای دستگاه (بند ۷) + `throttle:10,5` روی فعال‌سازی.
- **تأیید ورودی‌ها:** اندازه بدنه محدود (`PAYLOAD_TOO_LARGE`)، `request_id` الگوی‌دار،
  `action` فقط `entry|exit`، و تنظیمات فقط از Allow-list.
- **Audit/لاگ:** کد فعال‌سازی هرگز در Audit ذخیره نمی‌شود (تست: ۰ مورد)؛ لاگ دستگاه فقط
  `code_prefix` چهارکاراکتری را نگه می‌دارد؛ ورود اضطراری مدیر به پنلش پرسمان‌دقیق
  (ورود و خروج) ثبت می‌شود.
- **HTTPS:** اجباری برای پروداکشن (`docs/INSTALL-DIRECTADMIN.md` + پیشنهاد `force_https`)،
  و دستگاه‌های مرورگری بدون HTTPS دوربین نمی‌گیرند.

---

## ۱۲) ایرادهای واقعی که در جریان تست پیدا و رفع شد

| # | ایراد | رفع |
|---|---|---|
| ۱ | توکن دستگاه از **Query String** هم پذیرفته می‌شد | `DeviceAuth` فقط هدر `Authorization` را می‌پذیرد + رویداد `token_not_in_header` |
| ۲ | کد فعال‌سازی **متنی** در `devices.pairing_code` آینه می‌شد و به صفحه مدیر نشت می‌کرد | آینه حذف شد (`NULL`)؛ کد فقط هش + پیشوند ۴ کاراکتری در جدول اختصاصی |
| ۳ | `AuditLog::device()` ستون‌های هویت موجودیت را نمی‌نوشت ⇒ ردیابی کد/فعال‌سازی بی‌معنا بود | `AuditLog::record(..., 'device', 'device', $deviceId, $customerId)` در صدور کد، فعال‌سازی موفق و ناموفق |
| ۴ | شمارش «تلاش ناموفق» فقط با `device_id` کار می‌کرد ⇒ استفاده مجدد از کد رد‌شده شمارنده را بالا نمی‌برد | سه‌لایه: `registerAttemptById()` (کد شناخته‌شده)، `deviceIdForPrefix()` (حدس با پیشوند درست)، و throttle IP برای حدس‌های کاملاً تصادفی |
| ۵ | `heartbeat` به کنترلر API قدیمی می‌رفت ⇒ بدون لاگ دستگاه/تنظیمات در انتظار | مسیر به `DeviceKioskController::heartbeat` منتقل شد (قرارداد ۲۰۰/۴۰۱ و محدودیت `240,1` حفظ شد؛ سازگاری با توکن `att_` مرحله ۱ حفظ شد) |
| ۶ | کلاینت `clock_offset_seconds` می‌فرستاد، سرور `clock_offset` می‌خواند | سرور هر دو را می‌پذیرد |
| ۷ | صفحه Placeholder قدیمی `views/dashboard/device.php` وجود داشت و می‌توانست با Kiosk واقعی اشتباه شود | فایل حذف شد؛ `GET /dashboard/device` به‌صورت ۳۰۲ به `/device` می‌رود |
| ۸ | خطاهای خودِ تست (نه محصول): تشخیص False-Positive «liveness» روی توضیحات بالای فایل‌ها، بدنه ناقص ساخت کارمند، متغیرهای بی‌مقدار، سناریوی غیرقططعی قفل کد | سوئیت اصلاح شد (بخش ۱۴) |

---

## ۱۳) تست‌های موفق (۷. تست‌های موفق)

اجرای نهایی روی **نصب تازه همان ZIP تحویلی** (`http://127.0.0.1:8090`، دیتابیس خالی،
نصب از صفر) و همچنین روی درخت منبع (`:8080`):

| مجموعه | نتیجه | دامنه |
|---|---|---|
| `tests/e2e.sh` | **۷۰ موفق، ۰ ناموفق** | هسته: نصب، ورود، نقش‌ها، جداسازی مستأجر، توکن دستگاه، Audit |
| `tests/e2e_stage2.sh` | **۲۱۸ موفق، ۰ ناموفق** | پنل مالک (۱۴ بخش) |
| `tests/e2e_stage3.sh` | **۲۷۱ موفق، ۰ ناموفق** | پنل مدیر/کارفرما |
| `tests/e2e_stage4.sh` | **۳۳۸ موفق، ۰ ناموفق** | دستگاه/PWA/Kiosk (۱۴ بخش) |
| **جمع سوئیت‌ها** | **۸۹۷ موفق، ۰ ناموفق** | — |
| `php tests/security_tests.php` | **۶۹ موفق، ۰ ناموفق** | واحد امنیتی (رمز، token، CSRF، پرسمان) |
| `bash tests/lint.sh` | **LINT: OK** | PHP 8.1 compatibility + سینتکس |
| `php tools/audit_api.php` | **۲۳۲۶ فراخوانی، ۰ ایراد** | بازرسی ایستا: مدل/متد/راوت ناموجود |
| `php tools/migrate.php status` | **۳۱ جدول / ساختار به‌روز** | Migration |

بخش‌های سوئیت مرحله ۴: فایل‌ها و طرح دیتابیس و قاعده «بدون Liveness» · کد فعال‌سازی
(معتبر/نامعتبر/منقضی/مصرف‌شده/قفل‌شده) · فعال‌سازی و توکن · احراز هویت دستگاه (توکن، نشست،
توکن نامعتبر، دستگاه غیرفعال/لغو‌شده، مشتری غیرفعال) · بازنشانی توکن و ابطال نشست ·
Heartbeat و Last Seen · کانفیگ و تنظیمات غیرحساس · قرارداد موتور (بدون چهره/چند چهره/ناشناس/
کارمند غیرفعال) · قرارداد ثبت حضور · Idempotency · جداسازی مستأجر · UI دستگاه (۲۲ بررسی) ·
PWA (ایستا) · امنیت (۳۵ بررسی) · لاگ دستگاه.

---

## ۱۴) تست‌های ناموفق (۸. تست‌های ناموفق)

**۰ (صفر).** هیچ بررسی ناموفقی در اجرای نهایی وجود ندارد.

تنها «NOTE» سوئیت، یک محدودیت محیط است و به‌عنوان موفقیت واقعی ثبت **نمی‌شود**:

> PWA install prompt, standalone launch and service worker activation can only be observed in a
> real browser: this sandbox has no headless Chrome, so those three are asserted statically
> (manifest, sw.js, precache list, install handler, offline shell) and NOT claimed as
> browser-verified.

همچنین ۸ ایراد واقعی که پیش‌تر پیدا شده بودند (بخش ۱۲) **پیش از اجرای نهایی** رفع شدند؛ شمار
ناموفق بالا در میانه کار، مربوط به همان ایرادها بود، نه باقی‌مانده.

---

## ۱۵) موارد باقی‌مانده (۹. موارد باقی‌مانده)

1. **موتور تشخیص چهره:** فقط قرارداد؛ اتصال واقعی در مرحله ۵. کلیدهای آماده:
   `GET /device/engine`, `POST /device/recognition`, `DeviceRequest` (idempotency), اعتبارسنجی
   `request_id`, تحلیل کدهای موتور در `DeviceService::engineResponse()`.
2. **Liveness:** پیاده‌سازی **نخواهد شد** (سیاست پروژه) — نه کد، نه ستون.
3. **ثبت واقعی حضور:** `POST /device/attendance` امروز عمداً `503` می‌دهد؛ محاسبه تأخیر/
   اضافه‌کار/شیفت شبانه کار مرحله موتور حضور است (`attendance_records` هنوز از این مسیر
   نوشته نمی‌شود).
4. **صف آفلاین:** ساختار و UI آماده است؛ بازپخش صف و تصمیم‌گیری «چه چیزی آفلاین ثبت شود»
   کار مرحله موتور حضور است (بدون ذخیره‌سازی حساس بدون طراحی امنیتی).
5. **تأیید مرورگری PWA:** نصب/Standalone/فعال‌شدن Service Worker باید روی دستگاه واقعی
   (تبلت/موبایل با HTTPS) یک بار چشمی تأیید شود؛ در این محیط قابل تست خودکار نبود.
6. **ثبت ۳ نمونه چهره، Embedding و مدل:** خارج از دامنه مرحله ۴ (طبق درخواست).
7. **تلگرام/اکسل/موتور حقوق:** مراحل بعد.

---

## ۱۶) بازتولید نتایج

```bash
# ۰) استخراج ZIP در مسیر تازه + دیتابیس خالی (فقط جداول attendance_*)
cd /home/user/sarmad-attendance && bash build.sh          # ZIP: /home/user/sarmad-attendance-v1.0.0.zip
mkdir -p /tmp/verify-stage4 && cd /tmp/verify-stage4 && unzip -q /home/user/sarmad-attendance-v1.0.0.zip
php -S 0.0.0.0:8090 tests/router.php &                    # از همین مسیر اجرا شود

SARMAD_TEST_DB_PASS='…' bash tests/install_run.sh http://127.0.0.1:8090    # INSTALL: SUCCESS (۳۱ جدول)

# ۱) تست‌های ایستا و واحد
bash tests/lint.sh                        # LINT: OK
php tests/security_tests.php              # 69 passed, 0 failed
php tools/audit_api.php                   # 2328 checked, 0 problems
php tools/migrate.php status              # 31 tables / up to date

# ۲) سوئیت‌های پذیرش (هر چهار مرحله)
APP_PATH=/tmp/verify-stage4 bash tests/e2e.sh         http://127.0.0.1:8090 sarmadfa_clinic sarmadfa_youruser_lumiere '…' attendance_   # 70 / 0
APP_PATH=/tmp/verify-stage4 bash tests/e2e_stage2.sh  http://127.0.0.1:8090 sarmadfa_clinic sarmadfa_youruser_lumiere '…' attendance_   # 218 / 0
APP_PATH=/tmp/verify-stage4 bash tests/e2e_stage3.sh  http://127.0.0.1:8090 sarmadfa_clinic sarmadfa_youruser_lumiere '…' attendance_   # 271 / 0
APP_PATH=/tmp/verify-stage4 bash tests/e2e_stage4.sh  http://127.0.0.1:8090 sarmadfa_clinic sarmadfa_youruser_lumiere '…' attendance_   # 338 / 0
```

> نکته: در سوئیت‌های نرخ‌محدود، در صورت نیاز `rm -f storage/cache/throttle_*.json` را
> قبل از اجرا انجام دهید (شمارنده‌ها فایل‌محور هستند).
