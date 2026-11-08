<?php
/**
 * SARMAD Attendance - offline shell (stage 4).
 *
 * Cached by sw.js and shown when a navigation request can not reach the server.
 * It renders NO customer data (there is nothing to leak on a shared tablet) and
 * keeps the kiosk look so the device never falls back to the browser error page.
 *
 * @var callable $e
 */
?>
<div class="k-activate" id="kiosk-offline">
    <div class="k-activate-card">
        <div class="k-activate-logo">SA</div>
        <h1>ارتباط دستگاه با سرور قطع است</h1>
        <p>
            این صفحه نسخه آفلاین دستگاه حضور و غیاب است. به‌محض برقراری ارتباط، دستگاه به‌صورت خودکار به صفحه اصلی
            برمی‌گردد و ثبت ورود و خروج ادامه پیدا می‌کند. هیچ اطلاعات پرسنلی روی این دستگاه ذخیره نشده است.
        </p>

        <p class="k-banner k-banner-offline" style="display:flex">
            <span>وضعیت اتصال:</span>
            <strong id="k-offline-status">آفلاین</strong>
        </p>

        <button type="button" class="k-btn k-btn-primary k-btn-lg" id="k-offline-retry">تلاش دوباره</button>
        <p>اگر این پیام ادامه داشت، اتصال اینترنت یا کابل شبکه دستگاه را بررسی کنید.</p>
    </div>
</div>
