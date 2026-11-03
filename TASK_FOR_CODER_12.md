# TASK FOR CODER - Fix Owner Panel Feature Routes 404 and Manager Feature Access

## 🎯 گزارش مشکل کاربر:
> «اره الان ورود و خروج کار کرد ولی دسترسی همه به این مدیر قطع شده
> و وقتی وارد پنل مالک میشم و به این مدیر میخوام دسترسی بدم بهم اینو میگ:
> آدرس درخواستی در این سامانه وجود ندارد.»

---

## 🔍 ریشه‌یابی دقیق فنی:

### ۱. عدم تعریف مسیر `/owner/customers/{uuid}/features` در `routes/web.php`:
در `routes/web.php` (خطوط ۱۱۶ تا ۱۲۴) مسیرهای بخش امکانات به این شکل تعریف شده‌اند:
```php
// Features
$router->get('/owner/features', [FeatureController::class, 'index'],
    ['auth', 'owner'], 'owner.features');
$router->get('/owner/features/{uuid}', [FeatureController::class, 'show'],
    ['auth', 'owner'], 'owner.features.show');
$router->post('/owner/features/{uuid}', [FeatureController::class, 'update'],
    ['auth', 'owner', 'csrf'], 'owner.features.update');
$router->post('/owner/features/{uuid}/toggle', [FeatureController::class, 'toggle'],
    ['auth', 'owner', 'csrf'], 'owner.features.toggle');
```

اما در Viewهای پنل مالک:
- در `views/owner/customers/show.php` (خطوط ۴۳ و ۹۱):
  `<a class="o-btn" href="<?= $e($url('/owner/customers/' . $uuid . '/features')) ?>">امکانات</a>`
  `<a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers/' . $uuid . '/features')) ?>">مدیریت امکانات</a>`
- در `views/owner/managers/form.php` (خط ۱۸۷ در بخش «امکانات این مشتری»):
  `<a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers/' . $customer['uuid'] . '/features')) ?>">مدیریت امکانات</a>`
- و از همه جالب‌تر، در خود کنترلر `app/Controllers/Owner/FeatureController.php` (خطوط ۱۱۲ و ۱۳۲):
  `return Redirect::response('/owner/customers/' . (string) $customer['uuid'] . '/features');`

به دلیل اینکه مسیر `/owner/customers/{uuid}/features` در روت‌ها ثبت نشده، هر زمان که مالک دکمه «امکانات» یا «مدیریت امکانات» را می‌زند، یا فرم ذخیره امکانات را سابمیت می‌کند، با خطای ۴۰۴ مواجه می‌شود:
**«آدرس درخواستی در این سامانه وجود ندارد.»** (از فایل `views/errors/404.php`).

---

## 🛠️ اقدامات لازم:

### ۱. اصلاح `routes/web.php`:
مسیرهای کامل برای هر دو الگوی آدرس (`/owner/features/{uuid}` و `/owner/customers/{uuid}/features`) ثبت شوند:
```php
    // Features (هر دو الگوی آدرس معتبر و پشتیبانی شوند)
    $router->get('/owner/features', [FeatureController::class, 'index'],
        ['auth', 'owner'], 'owner.features');
    $router->get('/owner/features/{uuid}', [FeatureController::class, 'show'],
        ['auth', 'owner'], 'owner.features.show');
    $router->get('/owner/customers/{uuid}/features', [FeatureController::class, 'show'],
        ['auth', 'owner'], 'owner.customers.features');
    $router->post('/owner/features/{uuid}', [FeatureController::class, 'update'],
        ['auth', 'owner', 'csrf'], 'owner.features.update');
    $router->post('/owner/customers/{uuid}/features', [FeatureController::class, 'update'],
        ['auth', 'owner', 'csrf'], 'owner.customers.features.update');
    $router->post('/owner/features/{uuid}/toggle', [FeatureController::class, 'toggle'],
        ['auth', 'owner', 'csrf'], 'owner.features.toggle');
    $router->post('/owner/customers/{uuid}/features/toggle', [FeatureController::class, 'toggle'],
        ['auth', 'owner', 'csrf'], 'owner.customers.features.toggle');
```

### ۲. بررسی `app/Controllers/Owner/FeatureController.php`:
اطمینان حاصل شود که متدهای `show`، `update` و `toggle` با هر دو الگوی URL به درستی پارامتر `{uuid}` را دریافت کرده و در صورت نیاز ریدایرکت‌ها بدون ایجاد خطای ۴۰۴ انجام شوند.

### ۳. بررسی `views/owner/features/show.php`:
فرم‌های صفحه امکانات به مسیر متناظر پست شوند و دکمه‌های بازگشت بدون ارور کار کنند.

---

لطفاً این تغییرات را در شاخه کاری جدید اعمال و Pull Request ایجاد نمایید.
