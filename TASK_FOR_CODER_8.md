# TASK FOR CODER - Guard session_regenerate_id when session not active

## 🎯 باگ مشاهده‌شده در لاگ زنده سرور:
```
security.WARNING: Session fingerprint mismatch (user agent) {"actor":"device#2"}
app.ERROR: Uncaught exception {"exception":"ErrorException","message":"session_regenerate_id(): Session ID cannot be regenerated when there is no active session","file":".../app/Core/Session.php:184"}
```

## 🛠️ اصلاح در `app/Core/Session.php`:
در تمام جاهایی که `session_regenerate_id(true)` صدا زده می‌شود (خطوط ۱۱۴، ۱۸۴، ۱۹۵، ۳۸۱):
باید بررسی شود که نشست فعال باشد تا هیچ‌وقت خطای `ErrorException` تولید نشود:

```php
public static function regenerateIdSafe(bool $deleteOld = true): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return @session_regenerate_id($deleteOld);
    }
    return false;
}
```
و به جای فراخوانی مستقیم `session_regenerate_id(true)`، از یک هلپر امن `self::safeRegenerateId(true)` استفاده شود که وضعیت `session_status() === PHP_SESSION_ACTIVE` را بررسی کند.

لطفاً این اصلاح را روی یک شاخه جدید پوش کرده و PR را ارسال فرمایید.
