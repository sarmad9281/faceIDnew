# 🛠️ راهنمای سریع و گام‌به‌گام اعمال تغییرات دوربین روی DirectAdmin

این راهنما برای به‌روزرسانی سریع هاست پس از ادغام **PR #4** است؛ بدون نیاز به نصب مجدد و بدون هیچ تغییری در دیتابیس مشترک `sarmadfa_clinic`.

---

## 🎯 مسیر سایت شما در دایرکت‌ادمین:
`/domains/sarmadfallahzadeh.ir/public_html/finger`

---

## روش سریع (فقط ویرایش یا آپلود ۳ فایل):

### ۱. حل خطای ۴۰۳ مدل‌های دوربین (فایل `assets/.htaccess`)
1. در File Manager دایرکت‌ادمین، به پوشه زیر بروید:
   `public_html/finger/assets/`
2. یک فایل متنی جدید به نام `.htaccess` بسازید (دقت کنید با نقطه شروع شود).
3. محتوای زیر را در آن قرار داده و ذخیره کنید:

```apache
<FilesMatch "(?i)\.(json|js|css|png|jpg|jpeg|webp|svg|ico|woff|woff2)$">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Allow from all
    </IfModule>
</FilesMatch>

<FilesMatch "(?i)\.(php[0-9]?|phtml|phar|cgi|pl|py|sh|shtml)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

Options -Indexes
```

---

### ۲. اضافه شدن دکمه‌های وب‌کم در پرونده پرسنل
فایل زیر را در هاست با نسخه جدید جایگزین (یا محتوای آن را ویرایش) فرمایید:
- مسیر در هاست: `public_html/finger/views/manager/employees/profile.php`
- (دکمه‌های آپلود دستی با دکمه بزرگ **«🎥 باز کردن دوربین زنده و ثبت چهره پرسنل»** جایگزین شده‌اند).

---

### ۳. دکمه سریع ثبت چهره در لیست پرسنل
فایل زیر را در هاست با نسخه جدید جایگزین (یا ویرایش) فرمایید:
- مسیر در هاست: `public_html/finger/views/manager/employees/index.php`

---

### ۴. استایل دکمه‌ها (اختیاری ولی توصیه می‌شود)
فایل‌های زیر را به‌روزرسانی کنید یا خط زیر را به انتهای آن‌ها بیفزایید:
- `public_html/finger/assets/css/manager.css`
- `public_html/finger/assets/css/app.css`
کد اضافه شده:
```css
.btn-lg { padding: 13px 26px; font-size: 1rem; border-radius: 14px; }
```

---

## 🔒 امنیت دیتابیس
این تغییرات هیچ نیازی به اجرای دستورات SQL ندارد و دیتابیس و جداول `attendance_*` کاملاً محفوظ می‌مانند.
