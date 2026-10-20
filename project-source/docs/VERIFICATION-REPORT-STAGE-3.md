# گزارش تحویل و تست مرحله ۳ — پنل مدیر / کارفرما

این سند نتیجه اجرای **واقعی** تست‌ها روی بسته تحویلی است (نه نمونه، نه ماکت).
هر عددی که در این سند آمده از اجرای همین کد روی همین دیتابیس به دست آمده است.

---

## ۱) خلاصه اجرایی

| مورد | نتیجه |
|---|---|
| نصب تازه از ZIP روی دیتابیس خالی | **موفق** (۲۷ جدول، Installer خودقفل‌شو) |
| تست مرحله ۱ (هسته) | **۷۰ از ۷۰ — همه PASS** |
| تست مرحله ۲ (پنل مالک) | **۲۱۸ از ۲۱۸ — همه PASS** |
| تست مرحله ۳ (پنل مدیر) | **۲۷۱ از ۲۷۱ — همه PASS** |
| تست واحد امنیت/سینتکس | **۶۹ از ۶۹ — همه PASS** |
| بررسی سینتکس و سازگاری | `LINT: OK` — ۱۷۱ فایل، ۰ خطا، PHPCompatibility (8.1) بدون ایراد |
| جداول خارج از پروژه | هیچ تغییری نکرد (۵ جدول decoy پروژه‌های دیگر دست‌نخورده) |
| تست رد‌شده | **۰** |

نکته شفافیت: اجرای **اول** تست مرحله ۳ روی همین کد `۱۹۷ PASS / ۷۴ FAIL` بود. هر ۷۴ مورد
ریشه‌یابی شد؛ بخشی ایراد واقعی محصول بود (بخش ۹) و بخشی ایراد خود تست (بخش ۱۰). پس از
رفع، دور نهایی بدون هیچ تست رد‌شده تمام شد.

---

## ۲) محیط تست

| مورد | مقدار |
|---|---|
| نسخه هدف | PHP **8.1.34** (کد برای ۸.۱ نوشته شده) |
| نسخه اجرا | PHP **8.4.26** — سازگاری با PHPCompatibility `testVersion 8.1` تأیید شد |
| دیتابیس | MariaDB **11.8.6** (`sarmadfa_clinic`) |
| پیشوند جداول | `attendance_` (تنها پیشوند استفاده‌شده) |
| وب‌سرور تست | PHP built-in server + `tests/router.php` (شبیه‌ساز `.htaccess`) |
| مسیر آزمون نهایی | استخراج ZIP در یک مسیر تازه + دیتابیس خالی + نصب کامل از پنل Installer |

---

## ۳) آزمون نصب تازه (ZIP → دیتابیس خالی)

```
هاست تازه: /tmp/verify4 (استخراج مستقیم ZIP، بدون هیچ فایل دستی)
دیتابیس: تمام جداول attendance_* حذف شد (۰ جدول)

SARMAD_TEST_DB_PASS='…' bash tests/install_run.sh http://127.0.0.1:8083
  → INSTALL: SUCCESS

پس از نصب:
  جداول پروژه: 27      (۱۵ جدول مرحله ۱ + ۴ جدول مرحله ۲ + ۸ جدول مرحله ۳)
  schema_migrations: initial_schema + owner_panel + upgrade_2.0.0 + upgrade_3.0.0
  storage/installed.lock: ساخته شد
  تلاش دوم برای نصب: رد شد («قبلاً نصب شده است»)
  GET / قبل از نصب: 302 → /install/
```

سپس هر سه مجموعه تست روی همین نصب تازه اجرا شد: ۷۰ + ۲۱۸ + ۲۷۱ — همه PASS.

---

## ۴) جداول و ستون‌های ساخته‌شده در مرحله ۳

| جدول | نقش |
|---|---|
| `attendance_shifts` | شیفت: نام، شروع، پایان، مهلت تأخیر/تعطیلی زودتر، استراحت، فعال/غیرفعال، `crosses_midnight` |
| `attendance_employee_shifts` | نگاشت کارمند↔شیفت با ماسک `weekday` (پیش‌فرض ۱۲۷) و `is_active` — آماده برای چند شیفت/الگوی هفتگی |
| `attendance_employee_faces` | سه نمونه چهره (`sample_index` ۱..۳، `UNIQUE (employee_id, sample_index)`) — فقط تصویر، بدون موتور تشخیص |
| `attendance_records` | رکورد حضور روزانه: ورود، خروج، دقیقه کار/تأخیر/تعطیلی/اضافه‌کار، وضعیت، `UNIQUE (employee_id, work_date)` |
| `attendance_leaves` | مرخصی: کارمند، نوع، بازه، روز (`DECIMAL(5,2)`)، وضعیت، تأییدکننده |
| `attendance_holidays` | تعطیلات اختصاصی مشتری + شعبه اختیاری |
| `attendance_payroll_profiles` | پروفایل حقوق: نوع پرداخت، مبلغ پایه، نرخ ساعتی، اضافه‌کار، کسرکار، جریمه، مزایا (بدون محاسبه) |
| `attendance_customer_settings` | تنظیمات سطح مشتری: تلگرام، مهلت‌ها، حالت گزارش، ۲۲ کلید مدیریت‌شده |

ستون‌های جدید روی جداول موجود: `branches.phone`, `branches.notes`, `employees.notes`,
`employees.face_status`.

همه با `attendance_` ساخته شده‌اند؛ هیچ جدول/کلید خارجی لمس نشد
(بررسی `information_schema` و جداول decoy پروژه‌های دیگر در تست‌ها انجام می‌شود).

---

## ۵) فایل‌های جدید مرحله ۳

**مدل‌ها (۸ فایل، ~۱٬۶۸۰ خط):** `Shift`, `EmployeeShift`, `EmployeeFace`, `AttendanceRecord`,
`Leave`, `Holiday`, `PayrollProfile`, `CustomerSetting` — همه در `app/Models/`.

**کنترلرهای پنل مدیر (۱۳ فایل، ~۲٬۹۷۶ خط):** `app/Controllers/Manager/` →
`EmployeeController`, `ShiftController`, `BranchController`, `AttendanceController`,
`LeaveController`, `HolidayController`, `ReportController`, `PayrollController`,
`TelegramController`, `DeviceController`, `SettingsController`, `ActivityController`,
`ModuleController`.

**نمای‌ها (۱۶ فایل، ~۲٬۱۶۹ خط):** `views/layouts/app.php`، `views/dashboard/manager.php`،
`views/manager/{employees/{index,form,profile},shifts,attendance,leaves,holidays,branches,
reports,payroll,telegram,devices,activity,settings/{index,features},module}.php`.

**دارایی‌ها:** `assets/css/manager.css` (۵۸۴ خط، تم تیره/RTL/Responsive)،
`assets/js/manager.js` (۹۹ خط).

**پشتیبانی و امنیت:** `app/Support/Capability.php` (توانایی‌های نقش + `Capability::check`)،
`feature_enabled()` / `tenant_feature_enabled()` در `app/Support/helpers.php`.

**ساختار و تست:** `sql/attendance_upgrade_3.0.0.sql` (۲۷۴ خط)، `tests/e2e_stage3.sh` (۹۱۵ خط،
۲۷۱ بررسی)، `tools/audit_api.php` (ابزار توسعه‌ای: بازرسی فراخوانی‌های ناموجود در کد).

---

## ۶) فایل‌های تغییر یافته در مرحله ۳

| فایل | تغییر |
|---|---|
| `routes/web.php` | ~۷۰ مسیر پنل مدیر با میان‌افزارهای `auth`, `role:…`, `tenant:single`, `feature:<key>`, `csrf` |
| `app/Models/Employee.php` | `notes`, `face_status`, جست‌وجوی پیشرفته‌تر و قرارداد `canAttend()` |
| `app/Models/Branch.php` | فیلدهای `phone`, `notes` |
| `app/Models/AuditLog.php` | واژگان مرحله ۳ + انتقال `customer_id` در رویدادهای ورود/خروج مدیر |
| `app/Models/Setting.php` | تحمل‌پذیری `loadOverrides()` وقتی جدول تنظیمات هنوز ساخته نشده |
| `app/Services/AuditService.php` | واژگان کانونیک مرحله ۳ (کارمند، چهره، شیفت، مرخصی، تعطیلات، حقوق، تلگرام) |
| `app/Services/FeatureService.php` | `stateFor()`, `navFlags()` (وضعیت فیچر برای منو و صفحات) |
| `app/Services/AuthService.php` | `customer_id` در Audit ورود/خروج |
| `app/Services/InstallerService.php` | اصلاح ایراد جدی نصب تازه (بخش ۹) + پیام‌های دقیق‌تر |
| `app/Core/Security.php` | اصلاح `suggestPassword()` (بخش ۹) |
| `app/Bootstrap.php` | پیام روشن وقتی ساختار دیتابیس ناقص است (به‌جای خطای مبهم) |
| `views/layouts/app.php`, `views/dashboard/manager.php` | پوسته و داشبورد پنل مدیر |
| `views/partials/pagination.php` | صفحه‌بندی مشترک (`.o-pagination`) |
| `tests/e2e.sh` | هم‌سویی با قرارداد مرحله ۲ برای ساخت مشتری/مدیر (بخش ۱۰) |
| `tests/security_tests.php` | ۴ بررسی جدید برای سیاست رمز عبور |
| `docs/*`, `README.md` | گزارش‌ها و مستندات مرحله ۳ |

---

## ۷) قابلیت‌های پیاده‌شده (و شاهد تست هرکدام)

| خواسته | وضعیت | شاهد |
|---|---|---|
| داشبورد مدیر، فقط داده‌های همان مشتری | انجام شد | شمارش کارکنان/شعبه/دستگاه و ورود امروز از DB؛ تست: «manager lands on the customer dashboard»، «dashboard shows the own customer» |
| کارکنان: ایجاد/ویرایش/فعال‌غیرفعال/جست‌وجو/فیلتر | انجام شد | `manager/employees*`؛ تست‌های بخش ۴ و «search by name works» |
| پروفایل کارمند + وضعیت چهره + حضور اخیر | انجام شد | «profile shows the required empty state» (`ثبت چهره انجام نشده`)، «profile shows the shift» |
| کارمند غیرفعال: بدون ثبت جدید، سوابق محفوظ | انجام شد | `Employee::canAttend()` + تست CLI: `active=allowed suspended=blocked deleted=blocked` |
| ساختار دقیقاً سه نمونه چهره (DB + UI، بدون موتور/بدون liveness) | انجام شد | «exactly three face rows exist»، «slots 1..3 are the ones stored»، «no recognition / liveness column exists» |
| آپلود امن نمونه چهره | انجام شد | «a php payload can not be stored as a face sample»، «sample files are on disk inside the tenant folder» |
| شیفت‌ها + انتساب آماده برای چند شیفت/هفتگی | انجام شد | «shift 08:00→16:00 stored»، «nested shift crosses midnight»، «assignment 1:127»، «unassign keeps the employee» |
| شعبه‌ها فقط با فیچر `branches` | انجام شد | خاموش: ۴۰۳ (صفحه + API + منو)؛ روشن: CRUD؛ «branch assignment accepted» |
| دستگاه‌ها: فقط مشاهده + ثبت محدود؛ عملیات حساس مالک‌محور | انجام شد | «device registration pending»، «activate → active + hash 64»، отказа‌های مالک‌محور ۴۰۳ |
| حضور و غیاب: ساختار + فیلترها + ستون‌ها | انجام شد | «attendance page renders» + ۸ فیلتر (`date_from/date_to/employee_id/branch_id/status/shift_id/late/early/overtime`) |
| مرخصی‌ها + تعطیلات | انجام شد | انواع پایه، تصمیم تأیید/رد، بازه معکوس رد می‌شود، تعطیلی تکراری رد می‌شود |
| حقوق: صفحه پایه، نرخ‌ها، بدون محاسبه | انجام شد | خاموش ۴۰۳ / روشن ۲۰۰؛ ذخیره `hourly:250000000.00:850000.00` |
| تلگرام: تنظیمات، بدون ارسال واقعی | انجام شد | خاموش ۴۰۳ / روشن ۲۰۰؛ `telegram_chat_id=-1001234567890` |
| گزارش‌ها: ۹ نوع | انجام شد | `type=daily|monthly|per_employee|late|early_leave|overtime|absence|leave|branch` همگی ۲۰۰؛ خروجی Excel صریحاً «مرحله بعد» |
| تنظیمات کسب‌وکار (نام، لوگو، موبایل، ایمیل، آدرس، توضیح) | انجام شد | ویرایش ذخیره شد؛ سقف پرسنل/اشتراک از این صفحه تغییر نکرد |
| تغییر رمز کارمند/مدیر با `password_hash()` | انجام شد | «password is stored as a bcrypt hash ($2y$)»، رمز قدیمی بی‌اعتبار، مالک هرگز رمز را نمی‌بیند |
| رویدادها فقط برای همان مشتری | انجام شد | صفحه ۲۰۰ + فیلترهای `q/actor/action/result/from/to` |
| فیچر Middleware در مسیر و API | انجام شد | `feature:<key>` روی هر مسیر + تست خاموش/روشن برای هر ۶ فیچر |
| جداسازی مشتری‌ها (وب) | انجام شد | ~۲۰ حمله مستقیم با شناسه خام (کارمند، شعبه، چهره، مرخصی، دستگاه، حضور) → ۴۰۳/۳۰۲ و بی‌اثر |
| مدیر نمی‌تواند پنل مالک/اشتراک/ریست دستگاه/نقش خود را تغییر دهد | انجام شد | بخش ۱۳ تست: همه ۴۰۳/۳۰۲ و بدون اثر در DB |
| Audit کامل قابلیت‌های مدیر | انجام شد | ۱۸ نوع رویداد کانونیک + `actor_user_id`/`actor_role` (۶۴ ردیف) |
| UI مدرن تیره/RTL/ریسپانسیو بدون شبیه‌ساز قالب خام | انجام شد | `assets/css/manager.css` + پوسته `views/layouts/app.php` (بدون CDN) |

---

## ۸) جداسازی، امنیت و پاکت داده (شواهد)

- هر کوئری نوشتنی/خواندنی مشتری با `customer_id` می‌بندد و `customer_id` همیشه از
  نشست/مجوز می‌آید؛ `customer_id` ارسالی از URL نادیده گرفته می‌شود
  (تست: «manager A ignores a foreign tenant filter»).
- فایل‌های نمونه چهره در `uploads/customers/{uuid}/employees/{uuid}/face/` ذخیره می‌شوند و
  مسیر از شناسه‌های همان مشتری ساخته می‌شود (تست روی دیسک + تست رد شدن `.php`).
- CSRF: هر POST نیازمند `_token` و هم‌مبدأ بودن است (تست‌های مثبت و منفی).
- SQL Injection: همه کوئری‌ها Prepared؛ `SqlGuard` + پیشوند اجباری `Database::table()`.
- XSS: نام کارمند/شعبه/تعطیلی با `<script>` ذخیره و در HTML اسکیپ‌شده نمایش داده می‌شود.
- رمزها: فقط bcrypt؛ هیچ رمزی در پنل مالک یا لاگ‌ها نمایش داده نمی‌شود.

---

## ۹) ایرادهای واقعی که در جریان تست پیدا و رفع شد

۱. **نام جدول دو-پیشوندی `attendance_attendance_records`** — مدل و مسیرهای کد
   `attendance_records` را انتظار داشتند. اصلاح در SQL مهاجرت، مدل و دو فراخوانی،
   همراه rename جدول زنده. (شاهد: «stage 3 tables exist with the attendance_ prefix (8)»)

۲. **`SubscriptionService::currentFor()` وجود نداشت** → صفحه تنظیمات مدیر ۵۰۰ می‌داد.
   اصلاح: استفاده از `Subscription::currentFor()` و `Subscription::effectiveStatus()`.

۳. **`Security::suggestPassword()` رمزی می‌ساخت که سیاست خودش رد می‌کرد** (حدود ۴۰٪ مواقع
   بدون رقم) → بازنشانی رمز مدیر توسط مالک بی‌صدا شکست می‌خورد.
   اصلاح: تولید تضمین‌شده (حروف کوچک/بزرگ + رقم + نماد) و ۴ تست واحد جدید.

۴. **نصب تازه هرگز کامل نمی‌شد.** `InstallerService::runSchema()` فهرست کامل ۲۷ جدول را با
   اجرای تنها فایل مرحله ۱ می‌سنجید، پس نصب با پیام «جداول ایجاد نشد» متوقف می‌شد و جداول
   مرحله ۲/۳ ساخته نمی‌شدند. اصلاح: سنجش نهایی یک‌بار پس از اجرای فایل‌های ارتقا انجام می‌شود.
   (شاهد: `INSTALL: SUCCESS` + ۲۷ جدول روی دیتابیس خالی این نسخه)

۵. **خطای مبهم در حالت نصب نیمه‌کاره.** `Setting::loadOverrides()` روی دیتابیس بدون جدول
   تنظیمات، صفحه «خطای غیرمنتظره» می‌داد. حالا پیام روشن («ساختار دیتابیس کامل نیست…»)
   نمایش داده می‌شود و `php tools/migrate.php migrate` مسیر بازیابی است.

۶. **Audit ورود/خروج مدیر بدون `customer_id`** بود و در گزارش مشتری دیده نمی‌شد؛ حالا
   رویدادهای `LOGIN`/`LOGOUT` با مشتری مرتبط ثبت می‌شوند.

۷. **کادر جست‌وجوی صفحه رویدادها بیشتر از کوئری وعده می‌داد.** راهنمای فیلد اصلاح شد و
   دو فیلتر واقعی «کاربر یا شرح رویداد» و «کد رویداد» به صفحه اضافه شد.

---

## ۱۰) اصلاح‌های خود تست (نه محصول)

- `tests/e2e_stage3.sh` روی خط فرمان سرور PHP، مقدارهای غیر-ASCII را باید percent-encode
  کند (وگرنه درخواست بی‌پاسخ می‌ماند) → تابع `urlenc()` اضافه شد.
- بررسی قرارداد موتور ثبت حضور به یک اسکریپت CLI واقعی با `SARMAD_ROOT` تبدیل شد
  (سه حالت: فعال/غیرفعال/حذف‌شده).
- بررسی صفحه رویدادها با فیلتر `action=` انجام می‌شود (فهرست پیش‌فرض ۳۰ ردیف آخر است).
- برای بررسی شعبه مشتری دوم، فیچر `branches` توسط مالک برای همان مشتری روشن می‌شود.
- `tests/e2e.sh` (مرحله ۱): ساخت مشتری/مدیر با قرارداد مرحله ۲
  (`manager_username` و هم‌خانواده‌ها) هم‌سو شد تا روی نصب تازه واقعاً اجرا شود.
- ریشه‌های باقی‌مانده در `storage/cache/throttle_*.json` حذف شد.

---

## ۱۱) آنچه عمداً ساخته نشده (شفاف و بدون ادعای نادرست)

- موتور تشخیص چهره، دوربین، PWA و **تشخیص زنده‌بودن (liveness)**: هیچ کدی نوشته نشده؛
  تنها ساختار سه نمونه + وضعیت ثبت چهره.
- ثبت واقعی ورود/خروج از دستگاه/دوربین: جدول و قرارداد `canAttend()` آماده است ولی
  جدول `attendance_records` در این مرحله **خالی** می‌ماند (صفحه حضور فقط ساختار و فیلتر است).
- ارسال واقعی تلگرام: فقط ذخیره تنظیمات؛ پیامی ارسال نمی‌شود.
- محاسبه حقوق: فقط ذخیره نرخ‌ها؛ هیچ محاسبه‌ای انجام نمی‌شود.
- خروجی Excel: ۹ گزارش آماده است، تولید فایل XLSX در مرحله بعد (دکمه فعلی صریحاً
  «مرحله بعد» را اعلام می‌کند و ماکت نیست).

## ۱۲) نکات باقی‌مانده برای مرحله بعد

- `owner.impersonation_enabled` مقدار پیش‌فرض `1` دارد (دسترسی اضطراری مالک، کامل Audit
  می‌شود: ورود و خروج). اگر می‌خواهید پیش‌فرض بسته باشد، فقط همان کلید در
  `/owner/settings` تغییر می‌کند.
- PHPCompatibility روی محیط توسعه با Composer اجرا می‌شود؛ روی هاست بدون Composer
  تنها `php -l` اجرا می‌شود (که در همان بسته هم بدون خطاست).
- `tools/audit_api.php` یک ابزار توسعه‌ای است (CLI-only) و بخشی از مسیر اجرای برنامه نیست.

---

## ۱۳) بازتولید نتایج

```bash
# ۰) دیتابیس خالی + استخراج ZIP در یک مسیر تازه
SARMAD_TEST_DB_PASS='…' bash tests/install_run.sh http://127.0.0.1:8083     # INSTALL: SUCCESS
bash tests/lint.sh                                                         # LINT: OK
php tests/security_tests.php                                               # 69 passed, 0 failed
APP_PATH=/path/to/app bash tests/e2e.sh         http://127.0.0.1:8083 …     # 70 passed, 0 failed
APP_PATH=/path/to/app bash tests/e2e_stage2.sh  http://127.0.0.1:8083 …     # 218 passed, 0 failed
APP_PATH=/path/to/app bash tests/e2e_stage3.sh  http://127.0.0.1:8083 …     # 271 passed, 0 failed
```
