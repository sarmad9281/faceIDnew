# 📋 سند جامع وظایف فنی برای اکانت کدنویس (Technical Specification & Tasks)

**پروژه:** سامانه حضور و غیاب سرمد (SARMAD Attendance)  
**مخزن:** `sarmad9281/faceIDnew`  
**نقش ناظر و مدیر پروژه:** PM & Supervisor Agent  
**نقش مجری:** اکانت کدنویس (Coder Agent)  
**نسخه هدف:** 1.0.0 (آماده استقرار مستقیم روی DirectAdmin)  

---

## ۱. الزامات و محدودیت‌های قطعی پروژه (Non-Negotiable Constraints)

طبق مستندات کارفرما، سیستم دارای الزامات و محدودیت‌های زیر است که باید دقیقاً رعایت شوند:

1. **محیط اجرا:** پشته PHP 8.1.34 + MySQL/MariaDB + وب‌سرور Apache (با پشتیبانی کامل از `.htaccess` و `mod_rewrite`) روی پنل DirectAdmin.
2. **دیتابیس مشترک سازمانی:** نام دیتابیس `sarmadfa_clinic` است که با چندین سامانه دیگر (کلینیک، باربر، کافه و...) مشترک است.
3. **مشخصات اتصال:**
   - **Host:** `localhost` (در صورت نیاز به fallback: `127.0.0.1`)
   - **Port:** `3306`
   - **DB User:** `sarmadfa_youruser_lumiere`
   - **DB Password:** `1#0pd6TvTm6P-N`
   - **Charset:** `utf8mb4`
4. **ایزولاسیون کامل جداول (Table Isolation):**
   - پیشوند اختصاصی این پروژه **`attendance_`** است.
   - **اکیداً ممنوع:** هیچ جدول یا داده‌ای از پروژه‌های دیگر (پیشوندهای `clinic_*`، `barber_*`، `cafe_*`، `zibaei_*` و ...) نباید لمس، خوانده، ویرایش یا حذف شود. مکانیزم `SqlGuard` باید فعال بماند.
5. **کانفیگ آماده:** فایل `config/config.php` باید با اطلاعات فوق آماده‌سازی شود تا نیازی به ورود دستی مکرر توسط کاربر نباشد.
6. **فایل SQL کامل:** فایل SQL کامل ساخت ساختار جداول با پیشوند `attendance_` باید درون بسته باشد (در مسیر `sql/attendance_install.sql`).
7. **ساختار خروجی ZIP:** خروجی نهایی باید فایلی باشد که محتویات آن مستقیماً در مسیر `/domains/sarmadfallahzadeh.ir/public_html/` بدون ایجاد پوشه اضافه اکسترکت شود.

---

## ۲. ریشه‌یابی باگ فعلی (Bug & Error Analysis)

کاربر هنگام ورود به صفحه نصب با خطای زیر مواجه می‌شود:
```text
اتصال به دیتابیس برقرار نشد: SQLSTATE[HY000] [1045] Access denied for user 'sarmadfa_youruser_lumiere'@'localhost' (using password: YES)
```

### تحلیل فنی:
چون سایر پروژه‌ها با همین نام کاربری و پسورد کار می‌کنند، پس نام کاربری و رمز در MySQL معتبر هستند. خطای ۱۰۴۵ زمانی رخ می‌دهد که:
1. درایور PDO تلاش می‌کند مستقیماً با پارامتر `;dbname=sarmadfa_clinic` به دیتابیس متصل شود، اما کاربر `sarmadfa_youruser_lumiere` هنوز در سطح MySQL به این دیتابیس خاص مجوز (Grant / ALL PRIVILEGES) داده نشده است.
2. در کلاس `InstallerService.php`، تفکیک بین خطای «رمز اشتباه» و خطای «عدم دسترسی کاربر به دیتابیس مشخص» به اندازه کافی واضح نیست.

---

## ۳. وظایف اجرایی برای اکانت کدنویس (Actionable Tasks)

کدنویس باید تسک‌های زیر را به ترتیب انجام دهد:

### تسک ۱: اصلاح ساختار مخزن گیت (Repository Restructuring)
1. فایل باینری حجیم `project-source.zip` (۱۵ مگابایت) را از مخزن حذف کنید.
2. تمامی محتویات درون پوشه `project-source/` (شامل `app/`, `assets/`, `config/`, `docs/`, `install/`, `sql/`, `storage/`, `tests/`, `tools/`, `uploads/`, `vendor/`, `views/`, `index.php`, `.htaccess`, `.gitignore`, `build.sh` و...) را به **ریشه مخزن (Root)** انتقال دهید.
3. پوشه خالی‌شدهٔ `project-source/` و فایل تک‌خطی اولیه `README.md` ریشه را حذف کنید و فایل `README.md` کامل سامانه را در ریشه قرار دهید.
4. اطمینان حاصل کنید که فایل `.gitignore` مانع از نشت لاگ‌ها، سشن‌ها و فایل‌های موقت می‌شود.

### تسک ۲: آماده‌سازی `config/config.php` و روان‌سازی نصب‌کننده
1. فایل `config/config.php` را بر اساس `config/config.sample.php` بسازید و اطلاعات اتصال را با مقادیر اعلام‌شده مقداردهی کنید:
   ```php
   'db' => [
       'host'       => 'localhost',
       'port'       => 3306,
       'database'   => 'sarmadfa_clinic',
       'username'   => 'sarmadfa_youruser_lumiere',
       'password'   => '1#0pd6TvTm6P-N',
       'charset'    => 'utf8mb4',
       'prefix'     => 'attendance_',
       'persistent' => false,
   ],
   ```
   یک کلید تصادفی امن ۳۲ بایتی Base64 نیز برای `app.key` تولید و قرار دهید.
2. در `install/index.php` کاری کنید که اگر `config/config.php` وجود داشت، فرم نصب مقادیر را خودکار از آن بخواند و کاربر بدون نیاز به بازتایپ پسورد، با فشردن دکمه تأیید بتواند ویزارد را جلو ببرد.

### تسک ۳: بهبود عیب‌یابی اتصال در `InstallerService.php`
1. در متد اعتبارسنجی دیتابیس (`attemptDatabase`)، قبل از اتصال با `dbname`، یک بار با `Database::connectServer()` (بدون نام دیتابیس) وضعیت کاربر را بسنجید.
2. اگر اتصال اولیه بدون دیتابیس موفق بود اما اتصال به `sarmadfa_clinic` خطای ۱۰۴۵ داد، پیغام خطای بسیار واضح به کاربر نشان دهید:
   > «رمز عبور و نام کاربری صحیح است، اما این کاربر در DirectAdmin هنوز به دیتابیس sarmadfa_clinic متصل نشده است. لطفاً در پنل دایرکت‌ادمین از بخش Add User to Database، کاربر را با دسترسی ALL PRIVILEGES به دیتابیس اضافه کنید.»

### تسک ۴: تولید اسکریپت بیلد و پکیج نهایی (DirectAdmin Release Packaging)
1. اسکریپت `build.sh` را بررسی و اجرا کنید تا یک فایل ZIP به نام `sarmad-attendance-v1.0.0.zip` تولید کند.
2. ساختار فایل زیپ را بررسی کنید که محتویات آن مستقیماً در ریشه آرشیو باشد (یعنی بلافاصله بعد از Extract، فایل `index.php` در ریشه قرار گیرد و پوشه اضافه ایجاد نشود).

---

## ۴. استانداردهای گیت و فرآیند بازبینی (Review Protocol)

- **شاخه کاری:** اکانت کدنویس نباید روی `main` پوش مستقیم انجام دهد. یک شاخه کاری بسازد:
  ```bash
  git checkout -b fix/directadmin-deploy-and-repo-clean
  ```
- **ثبت کامیت‌ها:** کامیت‌ها با پیام‌های واضح و استاندارد ثبت شوند.
- **ارسال Pull Request:** پس از پوش شاخه، یک PR به سمت `main` باز شود.
- **تأییدیه:** این Pull Request توسط من (ناظر پروژه) بررسی دقیق کد (Code Review) خواهد شد و پس از تست و تأیید نهایی، با تأیید کارفرما مرج می‌شود.
