# TASK FOR CODER - STAGE 17
## Clean Telegram UI (Remove SOCKS/HTTP Proxy Field) & Standardize Cloudflare Gateway

**Priority:** Medium  
**Context:** The site owner wants a clean, simple Telegram experience without manual proxies. DirectAdmin host will use a single global Cloudflare Worker configured in `config/config.php` (`telegram.api_base_url`).

### Requirements:
1. **Remove Proxy Inputs from Manager View (`views/manager/telegram/index.php`):**
   - Remove the `proxy_url` input field and hints completely from the manager UI. Managers do not need to deal with SOCKS5 or HTTP proxies.
   - Keep the manager UI simple and focused on:
     - وضعیت ربات (فعال / غیرفعال)
     - توکن ربات (Bot Token)
     - شناسه چت (Chat ID)
     - انواع اعلان‌ها (ورود، خروج، تأخیر، اضافه‌کاری، گزارش روزانه)
     - ساعت ارسال گزارش روزانه
   - If `api_base_url` is configured in `config/config.php`, display a small reassuring badge/notice in the head of the page:
     `"درگاه ارتباطی تلگرام: فعال (سرور خارجی / بدون فیلتر)"`
2. **Controller and Service Cleanliness (`app/Controllers/Manager/TelegramController.php`, `app/Services/TelegramService.php`):**
   - Ensure `TelegramController::save()` operates cleanly without expecting `proxy_url`.
   - Maintain the backend capability in `TelegramService` to use `Config::get('telegram.api_base_url')` so the single global Cloudflare Worker endpoint routes all messages for all managers automatically.
