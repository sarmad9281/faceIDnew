<?php
/**
 * SARMAD Attendance - offline shell (stage 4).
 *
 * Cached by sw.js as the LAST-RESORT screen: it is shown only when the real
 * cached kiosk shell is unavailable (normally before first activation). An
 * already activated device reopens ./device itself while offline. It renders
 * NO customer data and keeps the kiosk look instead of a browser error page.
 *
 * @var callable $e
 */
?>
<div class="k-activate" id="kiosk-offline">
    <div class="k-activate-card">
        <div class="k-activate-logo">SA</div>
        <h1>ارتباط دستگاه با سرور قطع است</h1>
        <p>
            دستگاه در انتظار اتصال اولیه به اینترنت جهت فعال‌سازی و دریافت اطلاعات چهره است. پس از یک‌بار فعال‌سازی آنلاین، کیوسک در زمان قطعی اینترنت نیز به ثبت تردد ادامه خواهد داد.
        </p>

        <p class="k-banner k-banner-offline" style="display:flex">
            <span>وضعیت اتصال:</span>
            <strong id="k-offline-status">آفلاین</strong>
        </p>

        <button type="button" class="k-btn k-btn-primary k-btn-lg" id="k-offline-retry">تلاش دوباره</button>
        <p>اگر این پیام ادامه داشت، اتصال اینترنت یا کابل شبکه دستگاه را بررسی کنید.</p>
    </div>
</div>
