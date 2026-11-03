# TASK FOR CODER - Fix Owner Panel Feature Routes 404, Feature Matrix Form Parsing & Telegram CSRF 419 (Task #12)

## 🎯 گزارش مشکلات کاربر:
> ۱. خطای ۴۰۴ هنگام کلیک روی دکمه «مدیریت امکانات» یا ارسال فرم امکانات در پنل مالک.
> ۲. «مدیر می‌تونه وارد سایت بشه ولی هیچ دسترسی نداره».
> ۳. خطای ۴۱۹ (توکن امنیتی نامعتبر / CSRF) هنگام ذخیره توکن ربات تلگرام در پنل مدیر:
>    «توکن امنیتی فرم (CSRF) نامعتبر یا منقضی شده است. لطفاً به صفحه قبل برگردید، صفحه را دوباره بارگذاری کنید و فرم را مجدداً ارسال کنید.»

---

## 🔍 ریشه‌یابی دقیق ۳ مشکل:

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

### مشکل دوم (حیاتی): قطع شدن تمام دسترسی‌های مدیر پس از ذخیره فرم!
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

---

### مشکل سوم (جدید): خطای ۴۱۹ در فرم ربات تلگرام و حقوق و دستمزد
در فایل `app/Core/View.php` متغیر `$csrf` به صورت زیر تعریف شده است:
```php
'csrf' => static fn (): string => Csrf::field(),
```
و متد `Csrf::field()` یک تگ کامل HTML برمی‌گرداند:
`<input type="hidden" name="_token" value="...">`

در تمام فایل‌های پروژه (مانند `login.php`، `branches/index.php` و...) به درستی فقط نوشته شده است:
`<?= $csrf() ?>`

اما متأسفانه در فایل `views/manager/telegram/index.php` (خطوط ۶۵، ۱۰۰، ۱۰۴ و ۱۰۹) و `views/manager/payroll/index.php` (خطوط ۱۲۱، ۱۷۲، ۱۸۱، ۲۲۱ و ۲۸۹) به این شکل نوشته شده است:
```html
<input type="hidden" name="_token" value="<?= $csrf() ?>">
```
این کار باعث می‌شود در خروجی HTML تگ به صورت تو در تو رندر شود:
`<input type="hidden" name="_token" value="<input type="hidden" name="_token" value="...">">`
هنگامی که کاربر دکمه ذخیره را می‌زند، مرورگر مقدار توکن را به دلیل بسته‌شدن زودهنگام کوتیشن‌ها به صورت ناقص ارسال می‌کند (`_token: "<input type="`) و اعتبارسنجی CSRF بلافاصله با **خطای ۴۱۹ (توکن امنیتی نامعتبر)** رد می‌شود!

همچنین در متد `Csrf::validate` در فایل `app/Core/Csrf.php`، جهت استحکام در برابر چنین اشتباهاتی، اگر توکن ارسالی با الگوی تگ HTML ارسال شده باشد باید مقدار واقعی استخراج شود.

---

## 🛠️ اقدامات لازم:

### ۱. اصلاح `views/manager/telegram/index.php`:
خط ۶۵ (و خطوط ۱۰۰، ۱۰۴، ۱۰۹) از:
```html
<input type="hidden" name="_token" value="<?= $csrf() ?>">
```
به:
```html
<?= $csrf() ?>
```
تغییر یابد.

### ۲. اصلاح `views/manager/payroll/index.php`:
خطوط ۱۲۱، ۱۷۲، ۱۸۱، ۲۲۱ و ۲۸۹ به همین ترتیب به `<?= $csrf() ?>` اصلاح شوند.

### ۳. افزودن سازگاری در `app/Core/Csrf.php` (جهت اطمینان حداکثری):
در متد `Csrf::validate(Request $request)`، در صورتی که توکن دریافتی شامل تگ HTML باشد، توکن خالص hex استخراج شود:
```php
if (is_string($token) && preg_match('/value=["\']([a-f0-9]{32,128})["\']/i', $token, $matches)) {
    $token = $matches[1];
}
```

### ۴. اصلاح `app/Services/FeatureService.php` (متد `applyMatrix`):
پشتیبانی از آرایه تو در توی `features`:
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

### ۵. اصلاح `routes/web.php`:
ثبت روت‌های متناظر `/owner/customers/{uuid}/features` برای جلوگیری از خطای ۴۰۴:
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

---

لطفاً این تغییرات را اعمال و در قالب Pull Request ارسال فرمایید.
