# معماری SARMAD Attendance

## چرخه یک درخواست

```
Browser ──► index.php ──► App\Bootstrap::run()
                             │
                             ├─ autoloader (app/)
                             ├─ load config/config.php   (اگر نبود → /install/)
                             ├─ Security::sendHeaders()  (X-Frame-Options, CSP, ...)
                             ├─ Database::connect()      (PDO + prefix guard)
                             ├─ Session::start()         (fingerprint + lifetime + rotation)
                             ├─ Setting::loadOverrides()
                             └─ Router::dispatch(Request)
                                     │
                                     ├─ routes/web.php   ─┐
                                     └─ routes/api.php   ─┴─► Middleware pipeline
                                                                 │
                                                     Controller / ApiController
                                                                 │
                                                        Model / Service
                                                                 │
                                                             Response
```

## لایه‌ها

| لایه | مسئولیت | مثال |
|---|---|---|
| `Core` | زیرساخت بدون وابستگی | `Database`, `Router`, `Session`, `SqlGuard` |
| `Middleware` | کنترل دسترسی و سیاست‌ها | `auth`, `role`, `tenant`, `csrf`, `throttle` |
| `Controllers` | وب | `AuthController`, `DeviceController` |
| `Api\Controllers` | API نسخه‌دار | `DeviceApiController`, `ApiAuthController` |
| `Models` | دسترسی به داده | `User`, `Device`, `DeviceActivationCode`, `DeviceLog`, `AuditLog`, `Setting` |
| `Services` | منطق دامنه | `AuthService`, `DeviceService`, `InstallerService` |
| `Support` | ابزارها | `Url` |

## Middleware

| نام | کار |
|---|---|
| `csrf` | اعتبارسنجی توکن CSRF + بررسی Origin برای درخواست‌های تغییردهنده |
| `auth` | نیازمند کاربر وارد‌شده (و غیرمسدود) |
| `guest` | فقط برای کاربران وارد‌نشده (صفحات ورود) |
| `role:owner,manager` | کنترل دسترسی بر اساس نقش |
| `tenant:single\|platform\|auto` | تعیین و قفل محدوده مشتری |
| `api` | مرز JSON (Content-Type، پاسخ همیشه JSON) |
| `api.auth:<ability>` | توکن کاربر + بررسی مجوز (`devices.manage` ...) |
| `device.token` | توکن/نشست دستگاه |
| `throttle:60,5` | محدودیت نرخ (فایل‌محور در `storage/cache`) |
| `installer` | قفل دائمی `/install` بعد از نصب |

## دستگاه و PWA (مرحله ۴)

```
تبلت/موبایل  (Kiosk - PWA)
  /device                → DeviceKioskPageController::index   (صفحه Kiosk، بدون منو، no-store)
  /device/offline        → پوسته آفلاین (Service Worker این را precache می‌کند)
  manifest.webmanifest   → نام «SARMAD Attendance»، standalone+fullscreen، start_url ./device
  sw.js                  → هرگز /api/ را کش نمی‌کند؛ ناوبری: Network-first → پوسته آفلاین

چرخه توکن:
  مالک (پنل)  POST /owner/devices/activation-code  →  کد یک‌بارمصرف NNNNNN-NNNN
                                                     (DB: فقط code_hash + code_prefix)
  دستگاه      POST /api/v1/device/activate {code}  →  توکن att_… (یک‌بار نمایش)
                                                     (DB: فقط api_token_hash)
  دستگاه      هر فراخوانی بعدی: Authorization: Bearer att_…  (هدر فقط)
  مالک        بازنشانی/لغو → توکن + همه نشست‌ها باطل ⇒ فعال‌سازی مجدد

لایه‌ها:
  Middleware  device.token (App\Api\Middleware\DeviceAuth) → TenantContext از رکورد دستگاه
  Controller  App\Api\Controllers\DeviceKioskController (config/status/settings/heartbeat/
              engine/recognition/attendance/error/forget)
  Service     DeviceService (activateWithCode, configFor, heartbeatFor, applySettings,
              engineContract, engineResponse, recognitionContract, attendanceContract)
  Model       DeviceActivationCode, DeviceSetting, DeviceLog, DeviceRequest (idempotency)
```

`engine`/`recognition`/`attendance` امروز **قرارداد** هستند: `recognition` پاسخ
`503 FACE_ENGINE_UNAVAILABLE` و `attendance` پاسخ `503 ATTENDANCE_ENGINE_NOT_READY`
می‌دهد و هیچ رکورد حضوری نوشته نمی‌شود. تشخیص Liveness در معماری **وجود ندارد**
(نه کد، نه ستون).

## طراحی دیتابیس

```
attendance_customers ──1:N── attendance_managers ──1:1── attendance_users
        │                              │
        ├──1:N── attendance_branches ──┘
        ├──1:N── attendance_employees
        └──1:N── attendance_devices ──1:N── attendance_device_sessions

attendance_roles ──1:N── attendance_users
attendance_settings   (scope, customer_id, branch_id, device_id, setting_key)
attendance_audit_logs / attendance_login_attempts / attendance_api_tokens /
attendance_api_request_logs / attendance_password_resets / attendance_schema_migrations
```

قواعد:

- هر جدول وابسته به مشتری، ستون `customer_id` دارد و در تمام Queryها فیلتر می‌شود.
- `deleted_at` برای Soft Delete در جداول دامنه (حذف فیزیکی فقط برای پاک‌سازی رکوردهای تکراری).
- `settings` تکرارپذیری را با کلید یکتای `(scope, customer_id, branch_id, device_id, setting_key)`
  تضمین می‌کند (مقادیر `0` به‌جای `NULL` تا Unique در MySQL درست کار کند).
- تمام Foreign Key ها **فقط** بین جداول `attendance_*` هستند.

## چرا `primary key` از نوع BIGINT؟

حجم punch های آینده (ثبت حضور) بالا است؛ کلید ۶۴ بیتی از سقف زودرس جلوگیری می‌کند.

## تنظیمات سلسله‌مراتبی

`Setting::resolve($key, $customerId, $branchId, $deviceId)` به ترتیب
`device → branch → customer → global` را بررسی می‌کند؛ بنابراین هر مشتری می‌تواند
بدون تغییر کد، رفتار سامانه را برای خودش تنظیم کند (مثلاً دایره Geofence یا آستانه اطمینان چهره).

## مدیریت خطا

- Exception ها در `Bootstrap::dispatch` گرفته و در `storage/logs` ثبت می‌شوند.
- در API همیشه `{"success":false,"error":{...}}` با کد وضعیت صحیح برگردانده می‌شود.
- در حالت `app.debug = true` (فقط لوکال) جزئیات نمایش داده می‌شود؛ روی پروداکشن هرگز.

## افزودن یک صفحه جدید (مثال مرحله بعد)

```php
// routes/web.php
$router->get('/employees', [EmployeeController::class, 'index'],
    ['auth', 'role:manager,supervisor,owner', 'tenant:single'], 'employees.index');
```

```php
// app/Controllers/EmployeeController.php
$customerId = TenantContext::requireCustomerId();
$employees  = Employee::forCustomer($customerId);
return Response::html(View::render('employees/index', compact('employees'), 'layouts/app'));
```
