# TASK FOR CODER - Fix Owner Panel Feature Routes 404 & Feature Matrix Form Parsing (Bug #12)

## 🎯 گزارش مشکل کاربر:
> ۱. «روش دوم اصلاح ریشه‌ای می‌خوام»
> ۲. «مدیر می‌تونه وارد سایت بشه ولی هیچ دسترسی نداره»
> ۳. خطای ۴۰۴ هنگام کلیک روی دکمه «مدیریت امکانات» یا ارسال فرم امکانات.

---

## 🔍 ریشه‌یابی دقیق ۲ مشکل اساسی:

### مشکل اول: خطای ۴۰۴ به دلیل عدم تطابق آدرس‌ها
در `routes/web.php` (خطوط ۱۱۶ تا ۱۲۴) مسیرهای زیر تعریف شده‌اند:
- `GET /owner/features/{uuid}`
- `POST /owner/features/{uuid}`
- `POST /owner/features/{uuid}/toggle`

اما در قالب‌ها و کنترلر:
1. در `views/owner/customers/show.php` (خطوط ۴۳ و ۹۱) لینک‌ها به `/owner/customers/{uuid}/features` هستند.
2. در `views/owner/managers/form.php` (خط ۱۸۷) دکمه «مدیریت امکانات» به `/owner/customers/{uuid}/features` اشاره دارد.
3. در `app/Controllers/Owner/FeatureController.php` (خطوط ۱۱۲ و ۱۳۲)، پس از ذخیره فرم ریدایرکت به آدرس زیر انجام می‌شود:
   `return Redirect::response('/owner/customers/' . (string) $customer['uuid'] . '/features');`

به دلیل نبود روت‌های متناظر `/owner/customers/{uuid}/features`، سیستم بلافاصله خطای ۴۰۴ («آدرس درخواستی در این سامانه وجود ندارد») می‌دهد.

---

### مشکل دوم (بسیار حیاتی): قطع شدن تمام دسترسی‌های مدیر پس از ذخیره فرم!
**علت قطع شدن دسترسی‌های مدیر و خالی شدن پنل:**
وقتی فرم در `views/owner/features/show.php` ارسال می‌شود، چک‌باکس‌ها با نام‌های آرایه‌ای هستند:
`<input type="checkbox" name="features[attendance]" value="1">`
`<input type="checkbox" name="features[employees]" value="1">`
`<input type="checkbox" name="features[shifts]" value="1">`

در PHP به طور خودکار `$_POST` این داده‌ها را به شکل یک آرایه تو در تو باز می‌کند:
```php
$payload = [
    'features' => [
        'attendance' => '1',
        'employees' => '1',
        'shifts' => '1',
    ],
    '_csrf' => '...',
];
```

اما در `app/Services/FeatureService.php` (خطوط ۱۱۸ تا ۱۲۵):
```php
$key     = (string) $feature['feature_key'];
$field   = 'features[' . $key . ']';
$present = array_key_exists($field, $payload) || array_key_exists($key, $payload);
$raw     = $payload[$field] ?? $payload[$key] ?? null;

if (!$present) {
    $raw = '0'; // کلید در سطح اول پیدا نشد!
}
$enabled = in_array((string) $raw, ['1', 'on', 'true', 'yes'], true);
$result = FeatureFlag::set($customerId, $key, $enabled, $actorUserId);
```
**نتیجه این باگ:**
کد به جای بررسی `$payload['features'][$key]`، به دنبال کلید متنی `'features[attendance]'` در سطح اول `$payload` می‌گشت! چون این کلید وجود نداشت، `$present` همیشه `false` می‌شد و در نتیجه **تمام امکانات مشتری بدون استثنا صفر (غیرفعال) شدند!**

به محض اینکه مالک دکمه «ذخیره امکانات» را زد:
۱. تمام دسترسی‌های مشتری (حضور و غیاب، پرسنل، شیفت‌ها، مرخصی‌ها، گزارش‌ها، شعب و...) در دیتابیس `is_enabled = 0` شدند.
۲. کاربر به خطای ۴۰۴ منتقل شد.
۳. و وقتی مدیر وارد حساب کاربری خود شد، هیچ دسترسی و منویی در پنل خود ندید!

---

## 🛠️ اقدامات لازم:

### ۱. اصلاح `app/Services/FeatureService.php` (متد `applyMatrix`):
پشتیبانی از آرایه تو در توی `features` در متد `applyMatrix`:
```php
    public static function applyMatrix(int $customerId, array $payload, int $actorUserId = 0): array
    {
        $changed   = [];
        $unchanged = 0;
        $featuresMap = is_array($payload['features'] ?? null) ? $payload['features'] : [];

        foreach (Feature::catalogue() as $feature) {
            $key     = (string) $feature['feature_key'];
            $field   = 'features[' . $key . ']';
            $present = array_key_exists($key, $featuresMap)
                || array_key_exists($field, $payload)
                || array_key_exists($key, $payload);

            $raw = $featuresMap[$key] ?? $payload[$field] ?? $payload[$key] ?? null;

            if (!$present) {
                // Master switch off for optional features (explicit "no").
                $raw = '0';
            }

            $enabled = in_array((string) $raw, ['1', 'on', 'true', 'yes'], true);

            $result = FeatureFlag::set($customerId, $key, $enabled, $actorUserId);

            if ($result['changed']) {
                $changed[] = [
                    'key'     => $key,
                    'title'   => (string) $feature['title_fa'],
                    'before'  => $result['before'],
                    'enabled' => $enabled,
                ];
            } else {
                $unchanged++;
            }
        }

        return ['changed' => $changed, 'unchanged' => $unchanged];
    }
```

### ۲. اصلاح `routes/web.php`:
تعریف مسیرهای کامل برای هر دو الگوی URL:
```php
    // Features (پشتیبانی کامل از هر دو الگوی آدرس)
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

### ۳. بررسی `app/Controllers/Owner/FeatureController.php`:
اطمینان از سازگاری متدها و ریدایرکت‌ها با هر دو آدرس بدون ایجاد خطای ۴۰۴.

---

لطفاً این تغییرات را اعمال و در قالب Pull Request ارسال فرمایید.
