# نقشه راه مراحل بعدی

این سند نشان می‌دهد هسته فعلی چگونه بدون بازنویسی، توسعه می‌یابد.

## مرحله ۲ — پنل کامل مدیر مشتری

| قابلیت | نقطه اتصال آماده در هسته |
|---|---|
| مدیریت کارکنان (CRUD کامل) | `attendance_employees`, `Employee`, middleware `tenant:single` |
| سه نمونه چهره هر کارمند | `UploadService::path($uuid,'employees',$uuid,'face')` |
| مدیریت شعبه‌ها + Geofence | `attendance_branches` (`geo_lat`, `geo_lng`, `geofence_radius`) |
| مدیریت کاربران مشتری | `Role::assignableBy()`, `PermissionService` |
| تنظیمات هر مشتری | `Setting::put(..., Setting::SCOPE_CUSTOMER, $customerId)` |
| گزارش‌های پایه | `attendance_audit_logs` + `AuditLog::recent($customerId)` |

## مرحله ۳ — دستگاه Kiosk / PWA

| قابلیت | نقطه اتصال |
|---|---|
| PWA/Kiosk | مسیر `/device` و `views/dashboard/device.php` |
| ثبت حضور | جدول `attendance_records` (مرحله ۳ ساخته شد؛ `UNIQUE (employee_id, work_date)`) |
| احراز هویت دستگاه | `POST /api/v1/device/login` + `device.token` |
| همگام‌سازی آفلاین | `last_sync_at`, `POST /api/v1/device/heartbeat` |
| دوربین و Liveness | `Device.capabilities` (JSON) برای اعلام پشتیبانی هر دستگاه |

## مرحله ۴ — تشخیص چهره

- Embedding ها در جدول `attendance_employee_faces` (مرحله ۳ با `sample_index` ۱..۳ ساخته شد؛ ستون embedding بعداً اضافه می‌شود).
- مقایسه در سرویس مستقل `FaceMatchService`؛ هیچ وابستگی به پروژه‌های دیگر.
- آستانه اطمینان از `attendance_settings` خوانده می‌شود (`face.match_threshold`).

## مرحله ۵ — Telegram، گزارش Excel، حقوق و شیفت

- توکن ربات در `attendance_settings` با `is_encrypted = 1` و رمزنگاری AES-256-GCM
  (`Security::encrypt`) ذخیره می‌شود.
- صف ارسال پیام: جدول `attendance_notifications` (وضعیت pending/sent/failed).
- گزارش Excel: تولید XLSX بدون وابستگی خارجی + مسیر
  `uploads/customers/{uuid}/reports/`.
- شیفت و حقوق: جداول `attendance_shifts`, `attendance_employee_shifts`,
  `attendance_payroll_profiles` — همه با پیشوند `attendance_` فقط (مرحله ۳ ساخته شدند).

## قواعد ثابت در همه مراحل

1. هیچ جدول یا داده‌ای خارج از `attendance_*` خوانده یا نوشته نمی‌شود.
2. هیچ قابلیت ناقص/نمایشی اضافه نمی‌شود؛ هر قابلیت با تست و Migration خودش می‌آید.
3. هر Migration جدید روی جداول `attendance_*` و پس از عبور از `SqlGuard` اجرا می‌شود.
4. هر مسیر جدید باید Middleware `auth`/`role`/`tenant` و (در صورت نوشتن) `csrf` داشته باشد.
