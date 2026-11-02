# 📋 سند نیازمندی‌ها و باگ‌ریپورت شماره ۲: رفع خطای ۴۰۳ ورود مالک (Task Specification #2)

**پروژه:** سامانه حضور و غیاب سرمد (SARMAD Attendance)  
**مخزن:** `sarmad9281/faceIDnew`  
**ناظر و مدیر پروژه:** PM & Supervisor Agent  
**مجری:** اکانت کدنویس (Coder Agent)  

---

## ۱. شرح باگ و رفتار مشاهده‌شده (Bug Description)

کاربر فرآیند نصب سامانه را با موفقیت پشت سر گذاشته و حساب کاربری مالک (Owner) ایجاد شده است. اما پس از ورود با نام کاربری و رمز مالک در صفحه `/login`، با خطای ۴۰۳ مواجه می‌شود:

```text
403 دسترسی غیرمجاز
حساب کاربری شما به هیچ مشتری‌ای متصل نیست.
[بازگشت به داشبورد] (لینک به /dashboard)
```

### علت ریشه‌ای باگ (Root Cause):
1. **تفاوت مسیر داشبورد مالک و مدیر:**  
   در معماری سیستم، مسیر `/dashboard` مخصوص پنل مدیران (Manager) است که تحت میان‌افزار `tenant:single` قرار دارد و انتظار دارد `customer_id > 0` باشد. اما کاربر مالک (Owner) در سطح پلتفرم تعریف شده و `customer_id` آن `null` است. مسیر اختصاصی پنل مالک **`/owner`** است.
2. **هدایت اشتباه در روت ریشه (`/`):**  
   در `routes/web.php` خط ۶۷، مسیر ریشه به `DashboardController::home` هدایت می‌شود:
   ```php
   $router->get('/', [DashboardController::class, 'home'], ['auth'], 'home');
   ```
   و در `DashboardController::home` (خط ۱۸۲)، همه کاربران به صورت پیش‌فرض به `/dashboard` هدایت می‌شوند:
   ```php
   public function home(Request $request): Response
   {
       if (Session::isDevice()) {
           return Response::redirect(Url::to('/device'));
       }

       return Response::redirect(Url::to('/dashboard')); // <--- این برای مالک اشتباه است!
   }
   ```
   اگر کاربر مالک وارد شود یا به آدرس اصلی سایت (`/`) برود، به جای `/owner` به `/dashboard` هدایت شده و چون مالک متصل به مشتری نیست، `TenantMiddleware` خطای ۴۰۳ «حساب کاربری شما به هیچ مشتری‌ای متصل نیست» را صادر می‌کند!
3. **دکمه بازگشت در قالب ۴۰۳:**  
   در `views/errors/403.php`، لینک دکمه بازگشت هاردکد شده به `/dashboard` است که برای مالک لوپ بی‌پایان خطای ۴۰۳ ایجاد می‌کند.

---

## ۲. وظایف اصلاحی برای اکانت کدنویس (Actionable Tasks)

### تسک ۱: اصلاح متد `home` در `app/Controllers/DashboardController.php`
متد `home` باید بر اساس نقش کاربر هدایت را انجام دهد:
```php
public function home(Request $request): Response
{
    if (Session::isDevice()) {
        return Response::redirect(Url::to('/device'));
    }

    if (Session::role() === \App\Models\Role::OWNER) {
        return Response::redirect(Url::to('/owner'));
    }

    return Response::redirect(Url::to('/dashboard'));
}
```

### تسک ۲: اصلاح انعطاف‌پذیری `TenantMiddleware` برای مالک در `/dashboard`
در `app/Middleware/TenantMiddleware.php`، اگر کاربر نقش `owner` داشت اما وارد مسیر `/dashboard` شد، به جای نمایش خطای ۴۰۳ خشن، کاربر مالک را به صورت خودکار به `/owner` هدایت کنید (Redirect)، یا اگر مشتری فعالی در سیستم وجود داشت، اولین مشتری فعال را برای پیش‌نمایش انتخاب کند.

### تسک ۳: هوشمندسازی دکمه بازگشت در `views/errors/403.php`
لینک دکمه بازگشت باید با توجه به نقش کاربر تعیین شود:
- اگر کاربر `owner` است: لینک به `\App\Support\Url::to('/owner')`
- در غیر این صورت: لینک به `\App\Support\Url::to('/dashboard')` (یا اگر لاگین نیست به `/login`).

---

## ۳. فرآیند گیت و تحویل:
1. یک برنچ جدید بسازید (یا روی برنچ جلسه خود کامیت کنید).
2. تغییرات را تست کرده و در قالب Pull Request ارسال کنید.
3. در پیام کامیت و PR بنویسید: `fix(auth): redirect owner to /owner and handle owner tenant scope gracefully`.
