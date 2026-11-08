# راهنمای نصب روی DirectAdmin

## ۱) پیش‌نیازها (تأیید شده)

| مورد | مقدار |
|---|---|
| PHP | 8.1.34 (تست‌شده تا 8.4) |
| افزونه‌ها | `pdo_mysql`, `mbstring`, `openssl`, `json` |
| دیتابیس | MySQL/MariaDB |
| وب‌سرور | Apache + mod_rewrite (DirectAdmin) |

## ۲) استخراج فایل‌ها

دو نوع ZIP با `build.sh` ساخته می‌شود (هر دو بدون پوشهٔ اضافه — `index.php`
مستقیماً در ریشهٔ آرشیو است):

| بسته | دستور ساخت | کاربرد |
|---|---|---|
| `sarmad-attendance-v1.0.0-directadmin-private.zip` | `./build.sh --with-config` | **بستهٔ استقرار این دامنه**: `config/config.php` آماده (رمز دیتابیس + `app.key`) داخل آن است و نصب با «یک کلیک تأیید» انجام می‌شود. **محرمانه است؛ هرگز در گیت/کانال‌های مشترک نگذارید.** |
| `sarmad-attendance-v1.0.0.zip` | `./build.sh` | بستهٔ امن بدون `config/config.php` — برای آرشیو/اشتراک‌گذاری؛ نصب‌کننده اطلاعات دیتابیس را دستی می‌پرسد. |

```bash
cd /domains/sarmadfallahzadeh.ir/public_html/
unzip sarmad-attendance-v1.0.0-directadmin-private.zip
ls -la
# باید index.php و .htaccess را ببینید (پوشه اضافه ایجاد نمی‌شود)

# مهم (فقط بستهٔ private): خودِ فایل ZIP حاوی رمز دیتابیس است؛
# بلافاصله بعد از Extract آن را از public_html حذف یا به خارج از آن منتقل کنید
# تا از وب قابل دانلود نباشد:
mv sarmad-attendance-v1.0.0-directadmin-private.zip ~/   # یا: rm آن فایل
```

خروجی `build.sh` در پوشهٔ `release/` ساخته می‌شود (در `.gitignore` است و کامیت نمی‌شود)
و اسکریپت قبل از تحویل، خودش بررسی می‌کند که: `index.php` در ریشهٔ آرشیو باشد،
`storage/installed.lock` و لاگ‌ها داخل بسته نباشند و در حالت private فایل
`config/config.php` دقیقاً یک بار وجود داشته باشد.

## ۳) دسترسی‌ها

```bash
chmod 755 config storage storage/logs storage/tmp storage/cache uploads
chmod 640 config/config.php   # بعد از نصب
```

اگر DirectAdmin مالکیت فایل‌ها را به کاربر حساب (مثلاً `sarmadfa`) داده است،
همان کاربر باید مالک پوشه‌های بالا باشد.

## ۴) اجرای Installer

```
https://sarmadfallahzadeh.ir/install/
```

### حالت یک‌کلیک (بستهٔ private — پیش‌فرض این استقرار)

اگر `config/config.php` آماده همراه بسته باشد (`installed=false`، مشخصات دیتابیس و
`app.key` سی‌ودو‌بایتی از پیش ساخته شده)، بالای مرحلهٔ اول کارت
«پیکربندی آماده شناسایی شد» نمایش داده می‌شود — با خلاصهٔ ماسک‌شده (هاست، دیتابیس و
کاربر؛ **رمز و کلید هرگز نمایش داده نمی‌شوند**). کافی است دکمهٔ
«تأیید و ادامه نصب با همین پیکربندی» را بزنید:

1. نصب‌کننده اعتبارنامه را **سمت سرور** از `config/config.php` می‌خواند و اتصال را بررسی می‌کند
   (رمز دوباره پرسیده یا در فرم برگردانده نمی‌شود).
2. در مرحلهٔ دوم فقط حساب مالک (و مشتری اولِ اختیاری) را می‌سازید.
3. با «شروع نصب»: جداول `attendance_*` از `sql/attendance_install.sql` ساخته می‌شوند،
   `config/config.php` با `installed=true` بازنویسی می‌شود (`app.key` آماده حفظ می‌شود)
   و `storage/installed.lock` ایجاد و نصب‌کننده قفل می‌شود.

### حالت دستی (جایگزین)

اگر پیکربندی آماده نباشد یا اطلاعاتش درست نباشد، فرم دستی همان مرحله را تکمیل کنید.
اطلاعات مرحله اول:

| فیلد | مقدار |
|---|---|
| Host | `localhost` |
| Port | `3306` |
| Database | `sarmadfa_clinic` |
| User | `sarmadfa_youruser_lumiere` |
| Password | رمز دیتابیس شما |
| Prefix | `attendance_` (قابل تغییر نیست) |

سپس در مرحله دوم: نام و رمز مالک، منطقه زمانی، تقویم و (اختیاری) مشتری اول.

## ۵) پس از نصب

1. `install/` را حذف کنید:
   ```bash
   rm -rf /domains/sarmadfallahzadeh.ir/public_html/install
   ```
2. بررسی کنید `https://sarmadfallahzadeh.ir/config/config.php` پاسخ `403` بدهد.
3. وارد شوید: `https://sarmadfallahzadeh.ir/login`
4. در DirectAdmin → **PHP Settings** توصیه می‌شود:
   - `display_errors = Off`
   - `allow_url_fopen = On` (برای ارسال Telegram در مرحله بعد لازم است؛
     در صورت عدم نیاز `Off` بماند)
   - `session.save_path` لازم نیست تغییر کند (پروژه از `storage/tmp/sessions` استفاده می‌کند)

## ۴.۵) تست موقت در یک پوشهٔ دیگر (مثلاً `/barber`) و انتقال به مسیر نهایی

پروژه مسیر پایهٔ خود را از هر درخواست تشخیص می‌دهد و `app.url` در config پیش‌فرض
**خالی** است؛ یعنی می‌توانید همین بسته را در `public_html/barber/` تست کنید و بعداً
همان فایل‌ها را به `public_html/attendance/` منتقل کنید **بدون هیچ تغییری در config**:

```bash
# ۱) بکاپ پوشهٔ موجود (اجباری)
cp -a /domains/sarmadfallahzadeh.ir/public_html/barber /home/USER/barber-backup-$(date +%F-%H%M)

# ۲) استخراج بسته در همان پوشه (فایل‌های هم‌نام بازنویسی می‌شوند!)
cd /domains/sarmadfallahzadeh.ir/public_html/barber && unzip -o sarmad_attendance.zip

# ۳) نصب و تست روی https://sarmadfallahzadeh.ir/barber/install/

# ۴) انتقال به مسیر نهایی
mkdir -p /domains/sarmadfallahzadeh.ir/public_html/attendance
mv /domains/sarmadfallahzadeh.ir/public_html/barber/* /domains/sarmadfallahzadeh.ir/public_html/attendance/
mv /domains/sarmadfallahzadeh.ir/public_html/barber/.htaccess /domains/sarmadfallahzadeh.ir/public_html/attendance/

# ۵) بازگردانی پروژهٔ Barber از بکاپ مرحلهٔ ۱ و بازکردن
#    https://sarmadfallahzadeh.ir/attendance/  (هیچ فایلی نباید ویرایش شود)
```

نکات:
- **اگر در مرحلهٔ نصب فیلد «آدرس سایت» را پر کرده باشید** (مثلاً
  `https://sarmadfallahzadeh.ir/barber`)، نگران انتقال پوشه نباشید: برنامه پوشهٔ واقعی
  اجرا را از خود درخواست تشخیص می‌دهد و اگر با مسیر نوشته‌شده در `app.url` یکی نباشد،
  **مسیر واقعی را مبنا می‌گیرد** (هاست و پروتکل همان مقدار config باقی می‌ماند). پس
  بعد از `mv` هیچ ویرایشی لازم نیست. با این حال تمیزترین حالت این است که فیلد آدرس را
  در نصب‌کننده **خالی** بگذارید یا بعد از انتقال در `config/config.php` مقدار
  `'url' => ''` بگذارید تا همه‌چیز کاملاً خودکار تشخیص داده شود.
- جدول‌های `attendance_*` در دیتابیس **مشترک** ساخته می‌شوند و به پوشه وابسته نیستند؛
  بنابراین انتقال پوشه، نیازی به نصب مجدد ندارد.
- فایل `storage/installed.lock` هم با پوشه منتقل می‌شود و نصب‌کننده قفل می‌ماند.
- اگر می‌خواهید نصب را از صفر تکرار کنید، قبل از اجرای مجدد
  `storage/installed.lock` را حذف کنید و در پنل مالک/مدیر را دوباره بسازید؛ جدول‌ها
  idempotent ساخته می‌شوند و داده‌های موجود پاک نمی‌شوند.
- **هیچ جدول `barber_`/`cafe_`/`clinic_`/`zibaei_`/`sarmadpf_` خوانده یا تغییر داده نمی‌شود؛**
  لایهٔ `Database::assertPrefix()` و `SqlGuard` هر تلاشی برای دست‌زدن به این جدول‌ها را رد می‌کنند.

## ۵.۵) عیب‌یابی اتصال دیتابیس

### «مُهر نسخه» (Build) روی صفحهٔ نصب — اول این را چک کنید

پایین صفحهٔ نصب‌کننده یک مقدار `Build` نوشته شده است. اگر این مقدار با شمارهٔ
نسخه‌ای که تحویل گرفته‌اید یکی نیست، یعنی فایل‌های جدید روی هاست بالا نیامده‌اند
(آپلود ناقص) یا PHP از حافظهٔ کش قدیمی (opcache) استفاده می‌کند؛ در آن حالت هر
اصلاحی بی‌اثر است. راه‌حل: فایل‌ها را دوباره Extract کنید و در DirectAdmin →
PHP Settings (یا `Select PHP Version`) گزینهٔ opcache را یک بار خاموش/روشن کنید.

### تست اتصال دولایه: اول «سرور»، بعد «دیتابیس» (Build ‏2026-10-21.1)

نصب‌کننده ابتدا یک اتصال **بدون نام دیتابیس** (فقط سطح سرور MySQL/MariaDB) برقرار می‌کند
و فقط اگر آن موفق بود سراغ دیتابیس مشخص (`sarmadfa_clinic`) می‌رود؛ بنابراین پیام خطا دقیقاً
می‌گوید مشکل کجاست و شما را به بازتنظیمِ بی‌دلیلِ رمز نمی‌فرستد:

- **«اتصال به سرور MySQL/MariaDB با کاربر … موفق بود، اما دسترسی به دیتابیس `sarmadfa_clinic` رد شد»**
  → نام کاربری/رمز درست است، ولی کاربر در دایرکت‌ادمین به این دیتابیس وصل نشده است:
  DirectAdmin ← MySQL Management ← **Add User to Database** ← کاربر `sarmadfa_youruser_lumiere`
  + دیتابیس `sarmadfa_clinic` + **ALL PRIVILEGES** (برای ساخت جداول `attendance_` در نصب لازم است).
- **«… اما دیتابیسی با نام `sarmadfa_clinic` پیدا نشد»** (خطای 1049)
  → در MySQL Management وجود/املاى دقیق نام دیتابیس را بررسی (یا آن را ایجاد) کنید.
- **«اتصال به سرور دیتابیس برقرار نشد»**
  → مشکل در سطح سرور است (کاربر/رمز/هاست)؛ راهنمای 1045 در ادامهٔ همین بخش.

در همهٔ این حالت‌ها نصب‌کننده هیچ جدولی ایجاد یا تغییر نداده است و رمز در هیچ پیامی چاپ نمی‌شود.

### نصب‌کننده خودش «localhost» و «127.0.0.1» را آزمایش می‌کند (از نسخهٔ فعلی)

اگر هاستِ وارد‌شده پذیرفته نشود، نصب‌کننده **بی‌درنگ همان اطلاعات را با هاست دیگر**
(`localhost` ↔ `127.0.0.1`) آزمایش می‌کند؛ چون روی هاست اشتراکی، یک حساب MySQL ممکن است
فقط روی سوکت (localhost) یا فقط روی TCP (127.0.0.1) اجازه داشته باشد. هرکدام که واقعاً
جواب بدهد، همان در `config/config.php` ذخیره می‌شود و پیام «اتصال با هاست … برقرار نشد، اما
با … موفق بود» در همان صفحه نشان داده می‌شود. (هاست‌های غیر از این دو هرگز به‌صورت خودکار
عوض نمی‌شوند.)

### دکتر اتصال در مرورگر (بدون SSH)

`https://<domain>/<folder>/tools/db_check.php` را باز کنید. **فقط تا وقتی نصب قفل نشده**
قابل استفاده است (بعد از نصب، ۴۰۳ می‌دهد) و هیچ‌چیز نمی‌نویسد:

- نتیجهٔ تست `localhost` (سوکت) و `127.0.0.1` (TCP) را جدا نشان می‌دهد،
- «شکل» رمز (طول، شروع/پایان، داشتن `#`، فاصلهٔ اضافه) را می‌گوید — **خودِ رمز هرگز چاپ نمی‌شود**،
- می‌گوید کاربر اصلاً روی سرور وجود دارد یا نه (آزمایش با رمز خالی)،
- جدول‌های `attendance_*` و جدول‌های خارجی را فهرست می‌کند.

برای آزمایش یک رمز نامزد (مثلاً همان که تازه در پنل تنظیم کرده‌اید) بدون نوشتن آن در آدرس،
از همان صفحه یک POST با فیلد `pass` بفرستید:

```bash
curl -s -X POST --data-urlencode 'pass=THE-PASSWORD' \
     https://sarmadfallahzadeh.ir/barber/tools/db_check.php
```

اگر بعد از نصب، ابزار را لازم داشتید، از SSH: `php tools/db_check.php`.

### چه زمانی پیام 1045 می‌بینید و چه کاری باید کرد

MariaDB برای «کاربر ناموجود» و «رمز غلط» هر دو همین خطا را می‌دهد، پس ترتیب کار این است:

1. **رمز را در پنل از نو تنظیم کنید**: DirectAdmin → MySQL Management → کاربر
   `sarmadfa_youruser_lumiere` → *Change Password*؛ سپس در نصب‌کننده تیک
   «استفاده از رمز ذخیره‌شده در config/config.php» را **بردارید** و رمزِ تازه را دستی وارد کنید.
2. **کاربر به دیتابیس وصل باشد**: همان صفحه → *Add User to Database* → `sarmadfa_clinic` → ALL PRIVILEGES.
3. **نام کاربری دقیق**: نام کامل (مثل `sarmadfa_youruser_lumiere`) با همان حروف؛ MySQL به حروف بزرگ/کوچک حساس است.
4. اگر با shell کاربر ساخته شده، احتمال کلاسیک «`#` به‌عنوان کامنت shell» را جدی بگیرید:
   `mysql -e "... IDENTIFIED BY YOUR-PASSWORD"` رمز را از اولین `#` به بعد قطع می‌کند؛ رمز را در پنل بازتنظیم کنید.

پیام خطای نصب‌کننده طول، اولین و آخرین کاراکتر رمز ارسالی را نشان می‌دهد تا تایپ اشتباه را
بی‌درنگ ببینید؛ خود رمز هرگز نمایش داده نمی‌شود.


### خطای `SQLSTATE[HY000] [1045] Access denied for user '...'@'localhost' (using password: YES)`

این خطا از **MySQL** می‌آید (نه از کد) و یعنی «کاربر/رمز پذیرفته نشد». نصب‌کننده خودش
نوع خطا را تشخیص می‌دهد و متن راهنما نشان می‌دهد؛ مسیر رسیدگی:

1. **رمز عبور**: در DirectAdmin → **MySQL Management** → کاربر → *Change Password* رمز را
   از نو تنظیم کنید. سپس **همان** مقدار را در نصب‌کننده وارد کنید.
   - رمز **هرگز trim یا اصلاح نمی‌شود**؛ کاراکترهایی مثل `#`، `$`، `!` و فاصله بخشی از رمز
     هستند و عیناً به MySQL فرستاده می‌شوند. فاصلهٔ ابتدا/انتها فقط هشدار می‌گیرد.
   - رمز در صفحه‌های نصب‌کننده نمایش داده **نمی‌شود** (نه در value، نه در hidden) و در
     `config/config.php` با `var_export` به‌صورت byte-for-byte ذخیره می‌شود.
2. **اتصال کاربر به دیتابیس**: در MySQL Management → *Add User To Database* کاربر باید به
   دیتابیس `sarmadfa_clinic` وصل شده و **ALL PRIVILEGES** داشته باشد.
3. **نام کاربری**: در DirectAdmin نام کاربر MySQL همیشه با پیشوند حساب ساخته می‌شود
   (مثل `sarmadfa_youruser_lumiere`). همان نام کامل را وارد کنید.
4. اگر رمز روی سرور تغییر کرده باشد، `config/config.php` قدیمی کار نمی‌کند: رمز را در آن
   فایل هم به‌روز کنید یا نصب‌کننده را دوباره اجرا کنید (بعد از حذف `storage/installed.lock`).

### نصب در زیرپوشه `/public_html/attendance/`

پروژه مسیر پایه را خودکار تشخیص می‌دهد؛ کافی است ZIP در همان پوشه استخراج شود و
`https://دامنه/attendance/install/` باز شود. اگر آدرس کامل را دستی تنظیم می‌کنید،
مقدار `app.url` در `config/config.php` باید همان زیرپوشه را داشته باشد (مثلاً
`https://sarmadfallahzadeh.ir/attendance`).

## ۶) DirectAdmin — نکات رایج

- **mod_rewrite**: در DirectAdmin به‌صورت پیش‌فرض فعال است. اگر 404 گرفتید،
  `AllowOverride All` باید برای دامنه فعال باشد (پیش‌فرض DirectAdmin همین است).
- **HTTPS**: اگر SSL فعال است، `app.force_https` در `config/config.php` را `true` کنید
  یا `null` بگذارید (تشخیص خودکار).
- **root دامنه**: اگر پروژه در زیرپوشه نصب شود (مثل `/attendance/`)، Rewrite و
  تولید URL به‌صورت خودکار مسیر را تشخیص می‌دهند.
- **بکاپ**: فقط جداول `attendance_*` را بکاپ بگیرید:
  ```bash
  mysqldump -u sarmadfa_youruser_lumiere -p sarmadfa_clinic \
    $(mysql -u sarmadfa_youruser_lumiere -p -N -e "SELECT GROUP_CONCAT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA='sarmadfa_clinic' AND TABLE_NAME LIKE 'attendance_%'") \
    > attendance-backup.sql
  ```
- **حذف کامل پروژه**: هنگام پاک‌کردن، فقط جداول `attendance_*` را حذف کنید. هرگز از
  `DROP DATABASE` استفاده نکنید، چون دیتابیس با پروژه‌های دیگر مشترک است:
  ```sql
  -- فقط و فقط جداول همین پروژه:
  DROP TABLE IF EXISTS attendance_device_sessions, attendance_devices;
  -- ... (بقیه جداول attendance_*)
  ```
