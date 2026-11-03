# TASK FOR CODER - Guard session_regenerate_id & Cache-Bust Kiosk Assets

## 🎯 دو مشکل شناسایی‌شده در محیط زنده:

### ۱. کرش غیرمنتظره در `app/Core/Session.php:184`:
```text
security.WARNING: Session fingerprint mismatch (user agent) {"actor":"device#2"}
app.ERROR: Uncaught exception {"exception":"ErrorException","message":"session_regenerate_id(): Session ID cannot be regenerated when there is no active session","file":".../app/Core/Session.php:184"}
```
در PHP 8، هشدار `session_regenerate_id()` توسط ارور هندلر به `ErrorException` تبدیل شده و بلافاصله کل درخواست را کرش می‌کند.

#### اقدام در `app/Core/Session.php`:
یک متد کمکی امن اضافه شود:
```php
public static function safeRegenerateId(bool $deleteOld = true): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return @session_regenerate_id($deleteOld);
    }
    return false;
}
```
و تمام ۴ فراخوانی `session_regenerate_id(true)` در خطوط ۱۱۴، ۱۸۴، ۱۹۵، ۳۸۱ با `self::safeRegenerateId(true)` جایگزین شوند تا هرگز خطای کشنده ندهد.

---

### ۲. کش ماندگار استایل‌ها در مرورگرهای موبایل و سرویس‌ورکر:
در گوشی‌های اندروید، کروم فایل `device.css?v=1.0.0` را در حافظهٔ آفلاین نگه داشته و با رفرش معمولی آن را به‌روز نمی‌کند؛ به همین دلیل کاربر هنوز ظاهر قبلی را می‌بیند.

#### اقدام در `views/layouts/device.php`:
تگ‌های استایل و اسکریپت کیوسک دارای کش‌باستر مبتنی بر تغییر فایل یا نسخه بالاتر شوند:
```php
<link rel="stylesheet" href="<?= $e($asset('/css/device.css')) ?>&v=1.0.2">
```
و برای اسکریپت:
```php
<script src="<?= $e($asset('/js/device.js')) ?>&v=1.0.2" defer></script>
```
همچنین در `app/Support/Url.php`:
نسخه پیش‌فرض در صورت نبود یا `1.0.0` بودن کانفیگ به `'1.0.2'` تنظیم شود:
```php
$version = (string) Config::get('app.version', '1.0.2');
if ($version === '1.0.0') {
    $version = '1.0.2';
}
```

لطفاً این موارد را در PR ارسال فرمایید.
