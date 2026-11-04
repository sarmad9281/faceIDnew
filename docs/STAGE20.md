# تسک ۲۰ — برند اختصاصی (فقط مالک) و کارت بارکد پرسنلی (QR) بدون هیچ بایت تصویر

این سند تغییرهای تسک ۲۰ را توضیح می‌دهد: هر مشتری یک **نام برند** و **لوگوی اختصاصی** دارد
که فقط مالک سامانه می‌تواند تغییر دهد، لوگو در هیچ‌جا بریده یا کشیده نمی‌شود، و پرسنل
می‌توانند تردد خود را با **کارت بارکد چاپی** ثبت کنند — در حالی که **صفر بایت** تصویر روی
سرور ذخیره می‌شود و بارکد آفلاین هم کار می‌کند.

---

## بخش اول — برند و لوگو، فقط در اختیار مالک

### ۱.۱ داده

| ستون | معنا | چه کسی می‌نویسد |
| --- | --- | --- |
| `customers.brand_name` | نام برندی که روی دستگاه، هدر پنل و سربرگ گزارش چاپ می‌شود | فقط مسیر `owner` |
| `customers.logo_path` | مسیر فایل لوگو داخل `uploads/customers/{uuid}/branding/` | فقط مسیر `owner` |

- ستون `brand_name` با `MigrationService::COLUMN_ADDITIONS` ساخته می‌شود
  (`VARCHAR(120) DEFAULT NULL`، بعد از `company_name`) و در `sql/attendance_install.sql`
  هم برای نصب تازه اضافه شده است.
- حالت نمایش: `Customer::brandName($customer)` → `brand_name` ← `company_name` ← `name`.
  پس مشتری‌ای که برند ندارد هم چیز معناداری می‌بیند.

### ۱.۲ قفل بودن برای مدیر

- `Customer::updateProfile()` (تنها مسیری که پنل مدیر استفاده می‌کند) دیگر `logo_path` و
  `brand_name` را در فهرست مجاز ندارد: حتی اگر در POST بیایند، بی‌صدا حذف می‌شوند.
- نوشتن برند فقط از `Customer::updateBranding()` انجام می‌شود که **دقیقاً دو ستون** را
  می‌پذیرد؛ `status`، `max_devices`، اشتراک و هر چیز دیگری در همان‌جا دور ریخته می‌شود.
- `Manager\SettingsController::update()` فایل `logo` و فیلدهای `brand_name`/`logo_path` را
  تشخیص می‌دهد، کل درخواست را رد می‌کند و با `customer.brand.denied` در `audit_logs`
  ثبت می‌کند. فرم تنظیمات مدیر هم دیگر ورودی فایل ندارد؛ فقط یک کارت «فقط خواندنی» با
  همین برند و لوگو و جملهٔ «بخش ویژه، فقط مالک» نشان می‌دهد.
- مسیرهای مالک (`routes/web.php`): `POST /owner/customers/{uuid}/logo` و مسیر تازه
  `POST /owner/customers/{uuid}/logo/delete` (هر دو با میدل‌ور `owner` + `csrf`).
  حذف لوگو فایل را از دیسک هم پاک می‌کند.

### ۱.۳ لوگو هرگز بریده یا کشیده نمی‌شود

قاعدهٔ مشترک، یک‌جا و فقط یک‌جا در `assets/css/app.css` تعریف شده:

```css
.brand-logo {
    width: auto;
    height: auto;
    max-height: 72px;
    object-fit: contain;   /* نه cover: هیچ بخشی از لوگو قطع نمی‌شود */
    background: transparent; /* شفافیت PNG/WebP حفظ می‌شود */
}
```

جای‌هایی که همین قاعده اعمال شده است:

| محل | فایل | یادداشت |
| --- | --- | --- |
| بالای کیوسک `/device` | `assets/css/device.css` → `img.k-logo` | کاشی مربعی قبلی فقط برای حالت «SA» است؛ لوگوی واقعی `max-height: 88px` و `width:auto` دارد |
| هدر پنل مدیر | `views/layouts/app.php` + `assets/css/manager.css` → `.m-brand-mark img` | کاشی همرنگ برند با لوگو جای خود را به لوگوی شفاف می‌دهد و عرض کاشی تا ۱۳۲px باز می‌شود |
| پنل مالک (فرم/فهرست/صفحهٔ مشتری) | `assets/css/owner.css` | `.brand-logo` و `.o-logo` با `contain` |
| کارت بارکد و سربرگ چاپ | `assets/css/manager.css` + `app.css` | `@media print` |

نام برند هم هم‌زمان در سه جا نمایش داده می‌شود: `GET /api/v1/device/config` (کلید
`customer.brand_name` و `customer.has_branding` که کیوسک مصرف می‌کند)، هدر پنل مدیر و
سربرگ چاپ.

### ۱.۴ سربرگ چاپ گزارش‌ها

`views/layouts/app.php` یک بلوک `div.m-print-brand` دارد (لوگو + نام برند + «گزارش حضور و
غیاب») که روی صفحه `display: none` است و فقط در `@media print` اول صفحه ظاهر می‌شود؛ پس
هر صفحهٔ چاپی پنل (از جمله `views/manager/reports/index.php`) سربرگ برند دارد.

خروجی اکسل هم در بلوک سرصفحه (`A2`) نام برند را می‌نویسد:
`مجموعه: <brand> (<tenant>)`. تصویر لوگو داخل فایل اکسل عمداً جاسازی نشده است: برای
جاسازی باید ابعاد تصویر خوانده شود و بدون GD/تصویرخوان ابعاد لوگو در فایل اکسل می‌توانست
کشیده چاپ شود؛ در حالی که قانون این تسک «هیچ distortion» است. سربرگ چاپی وب (که محل
واقعی «پرینت گزارش» است) لوگو و نام را با نسبت طبیعی خود نشان می‌دهد.

---

## بخش دوم — کارت بارکد پرسنلی (QR)

### ۲.۱ خودِ بارکد

```
SARMADQ1.<customer base36>.<employee base36>.<20 hex HMAC>
```

- امضا = `HMAC-SHA256(app.key, "sarmad.qr.v1|<customer>|<employee>")` و مقایسه با
  `hash_equals` (`app/Support/EmployeeQr.php`).
- بخش‌ها با base36 **بزرگ** کد می‌شوند تا کل رشته در حالت «alphanumeric» کد QR بماند و
  نماد کوچک‌تر و خواناتر شود (طول واقعی ≈ ۲۷ کاراکتر).
- نتیجه: بارکد قابل حدس نیست، با تغییر یک رقم باطل می‌شود، بین نصب‌های مختلف قابل
  جابه‌جایی نیست و بین مشتری‌ها هم قابل استفاده نیست (همهٔ این‌ها در تست‌ها اثبات شده‌اند).
- بارکد **ذخیره نمی‌شود**: هیچ ستون، هیچ فایل و هیچ ردیفی برای آن وجود ندارد؛ تابع
  `payload()` یک تابع خالص از (کلید نصب، شناسهٔ مشتری، شناسهٔ پرسنل) است.

### ۲.۲ کارت چاپی در پروندهٔ پرسنل

`/manager/employees/{id}` یک کارت با `data-qr-badge` دارد:

- نماد QR در **مرورگر** و به صورت **SVG درون‌خطی** ساخته می‌شود
  (`assets/vendor/qrcode.min.js` + `assets/js/qr-badge.js`)، پس هیچ فایل تصویری روی هاست
  تولید نمی‌شود و چاپ در هر اندازه تیز است،
- کارت شامل لوگو و نام برند (بالا)، QR (وسط)، نام/کد پرسنلی و جملهٔ راهنما (پایین) است،
- دکمهٔ «چاپ کارت» با `@media print` فقط همان کارت را به چاپگر می‌فرستد،
- اگر کلید نصب موجود نباشد، کارت مخفی می‌شود (بارکد بدون امضا هرگز چاپ نمی‌شود).

### ۲.۳ اسکن در کیوسک، بدون یک بایت تصویر

مسیر کیوسک:

1. همان لوپ دوربین چهره، هر `qr.scan_interval_ms` (پیش‌فرض ۳۸۰ms) یک فریم را روی یک
   `<canvas>` **در حافظه** می‌کشد، `getImageData` می‌گیرد، با
   `jsQR` (نسخهٔ ۱.۴.۰، درون‌سایتی زیر `assets/vendor/`) دیکود می‌کند و بلافاصله
   `clearRect` می‌زند؛ فریم دور ریخته می‌شود.
2. اگر رشته با `SARMADQ1.` شروع شود، `SHA-256` آن با فهرست digestهای دانلودشده مقایسه
   می‌شود (`assets/js/device-qr.js`). هیچ تصویری از بارکد آپلود نمی‌شود؛ تنها چیزی که
   از مرورگر خارج می‌شود همان رشتهٔ ~۲۷ کاراکتری است.
3. `POST /api/v1/device/attendance/qr` با `{ request_id, action, qr }`. سرور امضا را
   بررسی می‌کند و پرسنل را **خودش** استخراج می‌کند (یک `employee_id` تنها در بدنهٔ درخواست
   بی‌ارزش است، دقیقاً مثل مسیر چهره).
4. موفقیت با همان صفحهٔ موفقیت و **همان صدای فارسی** مسیر چهره اعلام می‌شود:
   `showSuccess()` که خودش `playVoice(true, state.action)` را صدا می‌زند
   («صبح بخیر، ورود شما ثبت شد» / «خسته نباشید، خروج شما ثبت شد»).

قاعدهٔ «صفر بایت» در سه جا تضمین می‌شود:

- کد کلاینت: هیچ `toDataURL`/`toBlob`/`FormData`/`FileReader` در مسیر بارکد نیست
  (تست `tests/device_qr_test.js` این را روی خود سورس بررسی می‌کند)،
- API: `attendanceQr()` هیچ `move_uploaded_file`/`UploadService` ندارد و بدنهٔ درخواست
  فقط رشته است،
- موتور: مسیر `recordQr()` هیچ فایل یا پیوستی نمی‌نویسد (تست `tests/stage20_tests.php`
  اندازه و تعداد فایل‌های `uploads/` را قبل و بعد از چند تردد بارکد مقایسه می‌کند).

### ۲.۴ موتور تردد: یک مسیر، دو اعتبارنامه

`AttendanceEngine` پارامتر `credential` گرفت (`face` پیش‌فرض، `qr`) که فقط سه چیز را
تغییر می‌دهد:

| موضوع | مسیر چهره | مسیر بارکد |
| --- | --- | --- |
| اعتبارنامه | `recognition_proof` یک‌بارمصرف | امضای HMAC خود بارکد (بدون proof) |
| پیش‌نیاز چهره | ۳/۳ نمونه لازم است | لازم نیست (دلیل وجود بارکد همین است) |
| `source` رکورد | `device` / `device_offline` | `device_qr` / `device_qr_offline` |

همهٔ قواعد دیگر یکسان می‌ماند: مالکیت مشتری، شعبه، فعال بودن پرسنل، جلوگیری از ورود
تکراری، سقف نرخ، محاسبهٔ شیفت و تاریخ شمسی/ساعت. مقدارهای تازهٔ `source` به
`ENUM` ستون `records.source` اضافه شده‌اند (مرحلهٔ DDL: `records.source-enum-qr`)، به
`AttendanceRecord::SOURCES` اضافه شده‌اند و فیلترهای «منبع» در فهرست حضور و غیاب و
گزارش‌ها یک گزینهٔ «بارکد پرسنلی (QR)» گرفته‌اند.

### ۲.۵ آفلاین

- `GET /api/v1/device/face-data` علاوه بر گالری چهره، بلوک `qr` را برمی‌گرداند:
  `{ enabled, format, employees: [{ employee_id, full_name, employee_code, qr_digest }] }`.
  فقط **digest** می‌رود، نه بارکد و نه تصویر؛ پس حتی اگر دستگاه دزدیده شود، کسی نمی‌تواند
  از روی آن کارت چاپ کند.
- این بلوک داخل همان `IndexedDB` گالری (`face-store.js`) ذخیره می‌شود، بنابراین بارکد
  آفلاین هم مثل چهره کار می‌کند.
- تردد آفلاین با `credential: 'qr'` و `qr_digest` به همان صف
  (`assets/js/device-queue.js`) می‌رود و با همان `POST /attendance/sync` فرستاده می‌شود.
  سرور digest را از روی ردیف پرسنل **دوباره** می‌سازد؛ پس یک دستگاه نمی‌تواند شناسهٔ
  پرسنلی‌ای را بفرستد که بارکدش را ندیده است.
- توجه: پرسنلی که **هیچ** نمونهٔ چهره ندارد هم در فهرست بارکدها هست (هدف اصلی همین است)،
  در حالی که گالری چهره فقط افراد ۳/۳ را دارد.

---

## فایل‌های تازه و تغییریافته

| فایل | تغییر |
| --- | --- |
| `app/Support/EmployeeQr.php` | **تازه** — ساخت/بررسی/دیجست بارکد |
| `app/Services/AttendanceEngine.php` | `recordQr()`، پارامتر `credential`، `sourceFor()`، رد شدن ۳/۳ و proof در مسیر بارکد |
| `app/Api/Controllers/DeviceKioskController.php` | `attendanceQr()` (بدون تصویر) |
| `routes/api.php` | `POST /api/v1/device/attendance/qr` |
| `app/Services/FaceDatasetService.php` | `qrForDevice()` + کلید `qr` در دیتاست دستگاه |
| `app/Services/DeviceService.php` | `customer.brand_name` / `has_branding` و بلوک `qr` در config |
| `app/Models/Customer.php` | `brandName()`، `hasBranding()`، `updateBranding()`؛ حذف برند و لوگو از `updateProfile()` |
| `app/Models/AttendanceRecord.php` | دو منبع تازه + فیلتر `origin=qr` |
| `app/Services/MigrationService.php` | `customers.brand_name` و گسترش `records.source` |
| `app/Controllers/Owner/CustomerController.php` | ذخیرهٔ برند، `logoDelete()`، نوشتن با `updateBranding()` |
| `app/Controllers/Manager/SettingsController.php` | قفل کامل برند/لوگو + audit `customer.brand.denied` |
| `app/Controllers/Manager/EmployeeController.php` | `qrPayloadFor()` + برند برای کارت |
| `app/Services/ReportService.php`, `app/Controllers/Manager/AttendanceController.php`, `app/Api/Controllers/AttendanceApiController.php` | فیلتر/برچسب منبع بارکد |
| `app/Services/ExcelExporter.php` | نام برند در سرصفحهٔ برگه |
| `views/device/kiosk.php`, `views/layouts/device.php`, `views/layouts/app.php` | دکمه/راهنمای بارکد، لوگوی contained، سربرگ چاپ، اسکریپت‌ها |
| `views/manager/employees/profile.php` | کارت چاپی بارکد |
| `views/manager/settings/index.php`, `views/owner/customers/form.php`, `views/owner/customers/show.php` | فرم برند مالک، کارت فقط‌خواندنی مدیر، پیش‌نمایش بدون برش |
| `assets/css/{app,device,manager,owner}.css` | `.brand-logo` و قواعد `contain`، کارت بارکد، `@media print` |
| `assets/js/device-qr.js`, `assets/js/qr-badge.js` | **تازه** — خواندن/تطبیق بارکد و ساخت کارت |
| `assets/js/device.js`, `assets/js/device-queue.js`, `assets/js/face/face-store.js` | اسکن بارکد، صف با `credential`, ذخیرهٔ `qr` در گالری |
| `assets/vendor/jsQR.min.js`, `assets/vendor/qrcode.min.js`, `assets/vendor/LICENSES.md` | **تازه** — کتابخانه‌های درون‌سایتی |
| `sw.js` | `sarmad-kiosk-v11` + precache ماژول بارکد |
| `sql/attendance_install.sql` | `brand_name` |
| `tests/stage20_tests.php`, `tests/device_qr_test.js` | **تازه** — ۶۵+ بررسی PHP و ۵۱ بررسی Node |
| `tests/service_worker_offline_test.js` | نسخهٔ دارایی‌های کیوسک و نام کش |

## اجرای تست‌ها

```bash
php tests/stage20_tests.php          # بارکد + برند (نیازمند دیتابیس نصب‌شده)
node tests/device_qr_test.js         # ماژول بارکد کیوسک
node tests/service_worker_offline_test.js
node tests/kiosk_offline_test.js
```

## مهاجرت

پس از استقرار، صفحهٔ «ساختار دیتابیس» پنل مالک (یا `php tools/migrate.php migrate`) را
یک بار اجرا کنید:

- `ALTER TABLE attendance_customers ADD COLUMN brand_name VARCHAR(120) NULL AFTER company_name`
- `ALTER TABLE attendance_records MODIFY source ENUM(...,'device_qr','device_qr_offline',...)`

هر دو مرحله ایدمپوتنت‌اند؛ اجرای دوباره هیچ کاری نمی‌کند. کیوسک‌ها با نسخهٔ کش
`sarmad-kiosk-v11` به‌روزرسانی می‌شوند و در اولین باز شدن دوربین، فهرست بارکدها را
دریافت می‌کنند.
