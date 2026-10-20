# نقشه راه پس از مرحله ۳ — اتصال موتورهای واقعی

مرحله ۳ (پنل کامل مدیر/کارفرما) تحویل داده شده و تست شده است. این سند نشان می‌دهد
هر قابلیتی که **عمداً** فقط «ساختار» آن ساخته شده، از کجا و بدون بازنویسی به موتور
واقعی وصل می‌شود. هیچ‌کدام از موارد این سند در مرحله ۳ پیاده‌سازی نشده و در رابط
کاربری هم به‌عنوان قابلیت آماده معرفی نشده است.

## ۱) موتور ثبت ورود/خروج (دستگاه / دوربین / PWA)

| موضوع | وضعیت فعلی (ساختار آماده) |
|---|---|
| قرارداد «مجاز بودن ثبت» | `Employee::canAttend(array $employee): bool` — فعال بودن + حذف نشده بودن |
| جدول رکوردها | `attendance_records` با `UNIQUE (employee_id, work_date)` و ستون‌های ورود، خروج، دقیقه کار، تأخیر، تعطیلی زودتر، اضافه‌کار، وضعیت |
| نگاشت شیفت | `attendance_employee_shifts` با ماسک `weekday` (پیش‌فرض ۱۲۷) و `is_active` |
| محاسبه ساعت شیفت | `Shift::timeMinutes()`, `Shift::workMinutes()`, پشتیبانی از شیفت شبانه (`crosses_midnight`) |
| دامنه‌های مجاز ورود | `attendance_devices` + `attendance_api_tokens` + `POST /api/v1/device/login` |

برای اتصال موتور واقعی: مسیر `/device` (PWA) باید فقط از `canAttend()` رد شود و رکورد را
با `AttendanceRecord::create()` بنویسد؛ هیچ مسیر دیگری نباید مستقیم در جدول بنویسد.

## ۲) موتور تشخیص چهره

| موضوع | وضعیت فعلی |
|---|---|
| سه نمونه هر کارمند | `attendance_employee_faces` با `sample_index` ۱..۳ و `UNIQUE (employee_id, sample_index)` |
| وضعیت ثبت چهره | `attendance_employees.face_status` = `not_enrolled` / `partial` / `complete` (از `EmployeeFace::syncEmployeeFlags()`) |
| تصاویر | فقط تصویر در `uploads/customers/{uuid}/employees/{uuid}/face/` — بدون هیچ پردازش |
| تشخیص زنده بودن (liveness) | **استفاده نمی‌شود** و در مرحله ۳ هیچ کدی برای آن نوشته نشده است |

برای اتصال موتور: ستون embedding (مثلاً `BLOB` یا `VECTOR` بسته به موتور) به
`attendance_employee_faces` اضافه شود و مقایسه در سرویس مستقل انجام شود؛
آستانه اطمینان در `attendance_customer_settings`/`attendance_settings` نگه داشته شود.

## ۳) خروجی Excel

- صفحه `feature:excel_reports` و گزارش‌های ۹گانه (`ReportController::TYPES`) آماده‌اند.
- دکمه خروجی در `views/manager/reports/index.php` فقط وقتی نمایش داده می‌شود که
  `feature_enabled($customerId, 'excel_reports')` روشن باشد؛ الان پیام صریح
  «تولید فایل اکسل در مرحله بعد» نشان داده می‌شود (بدون ماکت).
- اتصال بعدی: تولید XLSX بدون وابستگی خارجی و ذخیره در
  `uploads/customers/{uuid}/reports/` با همان ساختار `ReportController::TYPES`.

## ۴) ربات تلگرام

- صفحه `/manager/telegram` (feature `telegram`) شامل chat id، نوع اطلاع‌رسانی و
  کلیدهای روشن/خاموش است و مقادیر در `attendance_customer_settings` ذخیره می‌شود.
- هیچ پیامی ارسال نمی‌شود و هیچ توکنی ذخیره نمی‌شود.
- اتصال بعدی: جدول صف `attendance_notifications` (فقط با پیشوند `attendance_`)،
  توکن رمزنگاری‌شده با `Security::encrypt()` و کلید `is_encrypted = 1`.

## ۵) موتور حقوق و دستمزد

- `attendance_payroll_profiles` شامل نوع پرداخت، مبلغ پایه، نرخ ساعتی، اضافه‌کار،
  کسرکار، جریمه و مزایا است؛ صفحه فقط ذخیره/ویرایش می‌کند و **هیچ محاسبه‌ای انجام نمی‌دهد**.
- اتصال بعدی: محاسبه بر پایه دقیقه‌های `attendance_records` و قواعد
  `attendance_payroll_profiles`؛ خروجی در یک سرویس مستقل.

## ۶) شیفت‌بندی چندگانه / هفتگی

- امروز: هر کارمند می‌تواند یک شیفت داشته باشد (`attendance_employee_shifts`).
- برای چند شیفت یا الگوی هفتگی: از همین جدول با چند ردیف و ماسک `weekday` استفاده
  کنید؛ هیچ تغییری در ساختار لازم نیست، فقط UI انتخاب چندشیفتی اضافه می‌شود.

## ۷) قواعدی که در همه مراحل بعدی باید حفظ شوند

1. همه جداول فقط `attendance_*` — هیچ تماسی با `barber_*`, `cafe_*`, `clinic_*`,
   `zibaei_*`, `sarmadpf_*` مجاز نیست (`Database::table()` + `SqlGuard`).
2. مجوزها در بک‌اند: `WHERE id = ? AND customer_id = ?` و `customer_id` همیشه از
   نشست/مجوز، هرگز از ورودی کاربر.
3. فیچر فلگ‌ها هم روی مسیر و هم روی API: middleware `feature:<key>` و
   `feature_enabled($customerId, '<key>')`.
4. کارهای مالک‌محور (بازنشانی دستگاه، تعیین اشتراک، تغییر قابلیت‌ها، ورود اضطراری)
   هرگز به پنل مدیر اضافه نشوند.
5. هر رخداد جدید باید در واژگان `AuditService` یک کلید داشته باشد (نه رشته آزاد).
