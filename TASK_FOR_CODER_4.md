# 📋 سند جامع اصلاح ۲ باگ کلیدی: خطای ۴۰۳ مدل‌های هوش مصنوعی و لینک‌های مفقود دوربین زنده (Task Specification #4)

**پروژه:** سامانه حضور و غیاب سرمد (SARMAD Attendance)  
**مخزن:** `sarmad9281/faceIDnew`  
**ناظر و مدیر پروژه:** PM & Supervisor Agent  
**مجری:** اکانت کدنویس (Coder Agent)  

---

## ۱. شرح دو مشکل و ریشه‌یابی فنی (Root Cause Analysis)

### مشکل ۱: خطای ۴۰۳ در دانلود وزن‌ها و مانیفست مدل هوش مصنوعی
کاربر هنگام ورود به صفحه وب‌کم با خطای زیر مواجه شده است:
```text
بارگذاری مدل تشخیص چهره ناموفق بود: failed to fetch: (403) , from url: https://finger.sarmadfallahzadeh.ir/assets/models/face-api-1.0.0/tiny_face_detector_model-weights_manifest.json
```

**علت ریشه‌ای در وب‌سرور Apache:**  
در فایل `.htaccess` ریشه در بخش «Hard block» خط ۱۸:
```apache
<FilesMatch "(?i)\.(sql|md|log|lock|ini|json|yml|yaml|sh|bak|old|dist|sample|example|env)$">
    Require all denied
</FilesMatch>
```
پسوند `json` برای کل وب‌سایت مسدود شده است! در نتیجه فایل‌های مانیفست وزن‌های هوش مصنوعی درون پوشه `assets/models/...` (که پسوند `.json` دارند) توسط وب‌سرور مسدود شده و خطای ۴۰۳ می‌دهند و مرورگر نمی‌تواند شبکه عصبی را لود کند. همچنین فایل `manifest.webmanifest` نیز ممکن است تحت تأثیر قرار گیرد.

**راه‌حل فنی:**  
یک فایل `assets/.htaccess` ایجاد کنید که دسترسی به فایل‌های استاتیک مجاز (به‌ویژه `.json`, `.css`, `.js`, تصاویر و فونت‌ها) را درون پوشه `assets/` آزاد کند:
```apache
# Allow public static assets including AI model manifests
<FilesMatch "(?i)\.(json|js|css|png|jpg|jpeg|webp|svg|ico|woff|woff2|ttf|eot)$">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
    <IfModule !mod_authz_core.c>
        Allow from all
    </IfModule>
</FilesMatch>
```

---

### مشکل ۲: عدم وجود دکمه و لینک در پنل و اجبار به تایپ دستی آدرس
کاربر به درستی معترض است که چرا دکمه ثبت چهره با دوربین درون خود سایت نیست و مجبور است آدرس را دستی در مرورگر بزند!

**علت ریشه‌ای:**  
در `views/manager/employees/profile.php` هنوز کادرهای قدیمی `Choose File` نمایش داده می‌شوند و هیچ دکمه‌ای برای رفتن به ویزارد دوربین زنده وجود ندارد. همچنین در جدول فهرست پرسنل (`views/manager/employees/index.php`) دکمه میانبر دوربین نیست.

**راه‌حل فنی:**  
1. **در `views/manager/employees/profile.php`:**
   - کادرهای قدیمی آپلود فایل دستی (`<input type="file" name="sample">`) را کاملاً حذف کنید.
   - یک دکمه بزرگ و شاخص با رنگ آبی و آیکون دوربین قرار دهید:
     **«🎥 باز کردن دوربین زنده و ثبت چهره پرسنل»** با لینک:
     `href="<?= $e($url('/manager/employees/' . $id . '/faces')) ?>"`
   - در بالای صفحه در دکمه‌های عملیات (`row-actions`) نیز یک دکمه «ثبت چهره با دوربین» اضافه کنید.
2. **در `views/manager/employees/index.php` (فهرست پرسنل):**
   - در ستون عملیات کنار «پرونده» و «ویرایش»، یک دکمه سریع با آیکون دوربین/ثبت چهره اضافه کنید که مستقیماً به `/manager/employees/{id}/faces` لینک شود.

---

## ۲. چک‌لیست تحویل برای کدنویس:
- [ ] ساخت فایل `assets/.htaccess` برای رفع خطای ۴۰۳ فایل‌های `.json` مدل‌ها.
- [ ] اصلاح کامل `views/manager/employees/profile.php` و افزودن دکمه‌های دوربین.
- [ ] افزودن دکمه ثبت چهره در جدول پرسنل `views/manager/employees/index.php`.
- [ ] تست و ارسال در قالب Pull Request به `main`.
