<?php
/**
 * SARMAD Attendance - KIOSK screen (stage 4).
 *
 * One page, several screens, driven by assets/js/device.js:
 *   1) activation  - one-time code, typed on the on-screen keypad (nobody types
 *                    a username/password/PIN on this device, ever)
 *   2) home        - branding + branch + clock + the two huge buttons
 *   3) camera      - preview / face frame / guidance / loading / recognition
 *   4) success     - employee, operation, time, status, auto return
 *   5) error       - no_face / multiple_faces / unknown_face / camera / offline
 *   6) blocked     - device inactive or revoked (owner action needed)
 *   7) drawer      - NON-sensitive device preferences + camera test + status
 *
 * Server side this view renders NO customer data at all: everything the kiosk
 * shows (logo, business name, branch, settings) is fetched from
 * /api/v1/device/config with the device token that lives in the browser. That
 * is what makes it impossible for a cached page to leak customer A to a device
 * of customer B.
 *
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 */
?>
<div class="k-shell" id="kiosk-home">
    <!-- ------------------------------------------------------------------
         Top bar: logo + business name + branch + device identity
         ------------------------------------------------------------------ -->
    <header class="k-top">
        <div class="k-brand">
            <img class="k-logo" id="k-logo" alt="" hidden>
            <div class="k-logo" id="k-logo-fallback" title="تنظیمات دستگاه (نگه داشتن)">SA</div>
            <div class="k-brand-text">
                <h1 class="k-business" id="k-business">SARMAD Attendance</h1>
                <p class="k-branch" id="k-branch-wrap" hidden>
                    <span id="k-branch"></span>
                    <span class="k-dot-sep"></span>
                    <span>دستگاه ثبت حضور</span>
                </p>
            </div>
        </div>

        <div class="k-top-meta">
            <span class="k-pill">
                <span class="k-status-dot" id="k-status-dot"></span>
                <strong id="k-status-text">در حال اتصال</strong>
            </span>
            <span class="k-pill" id="k-device-pill">
                <strong id="k-device-name">—</strong>
            </span>
            <button type="button" class="k-btn k-btn-ghost" id="k-settings-open" aria-label="تنظیمات دستگاه">تنظیمات</button>
            <button type="button" class="k-btn k-btn-primary" id="k-install" hidden>نصب برنامه</button>
        </div>
    </header>

    <!-- Offline / connection banner -->
    <div class="k-banner k-banner-offline" role="status">
        <span>ارتباط دستگاه با سرور قطع است؛ صفحه آفلاین نمایش داده می‌شود و پس از برقراری ارتباط، وضعیت به‌روزرسانی می‌شود.</span>
        <span class="k-banner-queue" id="k-queue-count"></span>
    </div>

    <!-- ------------------------------------------------------------------
         Clock
         ------------------------------------------------------------------ -->
    <section class="k-clock" id="k-clock" aria-label="ساعت و تاریخ">
        <div class="k-clock-time">
            <span id="k-clock-time">--:--</span>
            <span class="k-clock-seconds" id="k-clock-seconds">:--</span>
        </div>
        <div class="k-clock-date">
            <span id="k-clock-weekday"></span>
            <span id="k-clock-jalali"></span>
            <span class="k-greg" id="k-clock-greg"></span>
        </div>
    </section>

    <!-- ------------------------------------------------------------------
         The two big buttons
         ------------------------------------------------------------------ -->
    <section class="k-actions">
        <button type="button" class="k-action k-action-entry" id="k-action-entry" data-action="entry">
            <svg class="k-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                <path d="M10 17l5-5-5-5"></path>
                <path d="M15 12H3"></path>
            </svg>
            <span class="k-action-label">ورود</span>
            <span class="k-action-hint">انگشت بزنید تا چهره شما بررسی شود</span>
        </button>

        <button type="button" class="k-action k-action-exit" id="k-action-exit" data-action="exit">
            <svg class="k-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <path d="M14 7l5 5-5 5"></path>
                <path d="M19 12H9"></path>
            </svg>
            <span class="k-action-label">خروج</span>
            <span class="k-action-hint">ثبت پایان شیفت با همان یک نگاه</span>
        </button>
    </section>

    <footer class="k-footer">
        <span>آخرین ارتباط با سرور: <strong id="k-last-seen">—</strong></span>
        <span class="k-mono" id="k-footer-device">SARMAD Attendance</span>
    </footer>
</div>

<!-- ======================================================================
     Activation screen
     ====================================================================== -->
<section class="k-overlay k-activate" id="kiosk-activation" aria-label="فعال‌سازی دستگاه">
    <div class="k-activate-card">
        <div class="k-activate-logo">SA</div>
        <h1>فعال‌سازی دستگاه حضور و غیاب</h1>
        <p>
            این دستگاه هنوز به هیچ مجموعه‌ای متصل نیست. کد فعال‌سازی یک‌بارمصرف را از مالک یا مدیر سامانه بگیرید و
            اینجا وارد کنید. پس از فعال‌سازی، پرسنل فقط با یک نگاه ورود و خروج را ثبت می‌کنند — هیچ رمز یا نام کاربری
            روی دستگاه وارد نمی‌شود.
        </p>

        <label class="k-field">
            <span>کد فعال‌سازی</span>
            <input class="k-input" id="activation-code" inputmode="numeric" autocomplete="off" spellcheck="false"
                   placeholder="123456-7890" aria-label="کد فعال‌سازی">
        </label>

        <div class="k-keypad">
            <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit): ?>
                <button type="button" class="k-key" data-key="<?= $e($digit) ?>"><?= $e($digit) ?></button>
            <?php endforeach; ?>
            <button type="button" class="k-key" data-key="back">⌫</button>
            <button type="button" class="k-key" data-key="0">0</button>
            <button type="button" class="k-key" data-key="clear">پاک</button>
        </div>

        <p class="k-banner" id="activation-error" role="alert"></p>

        <button type="button" class="k-btn k-btn-primary k-btn-lg" id="activation-submit">فعال‌سازی دستگاه</button>
        <p class="k-info-row" style="border:none;background:transparent;padding:0">
            <span>کد فعال‌سازی یک‌بارمصرف است و بعد از استفاده فوراً باطل می‌شود.</span>
        </p>
    </div>
</section>

<!-- ======================================================================
     Camera screen (all camera states live here)
     ====================================================================== -->
<section class="k-overlay" id="kiosk-camera" aria-label="دوربین">
    <div class="k-overlay-head">
        <div>
            <h2 class="k-overlay-title" id="k-camera-title">ثبت ورود</h2>
            <p class="k-overlay-sub">برای ثبت، چهره شما باید داخل کادر و بدون کلاه یا ماسک باشد.</p>
        </div>
        <button type="button" class="k-btn" id="k-camera-cancel">انصراف</button>
    </div>

    <div class="k-camera-wrap" id="k-camera-wrap">
        <video class="k-camera-video" id="k-camera-video" playsinline muted autoplay></video>
        <div class="k-face-frame" id="k-face-frame">
            <?php /* the live emphasis box that follows the detected face */ ?>
            <span class="k-face-box" aria-hidden="true"></span>
            <span class="k-face-label" id="k-face-label">چهره خود را داخل محدوده قرار دهید</span>
        </div>
        <div class="k-scanline"></div>

        <div class="k-camera-top">
            <span class="k-state-chip" id="k-chip-preview">
                <span class="k-spinner"></span>
                آماده‌سازی دوربین
            </span>
            <span class="k-state-chip" id="k-chip-loading">
                <span class="k-spinner"></span>
                بارگذاری
            </span>
            <span class="k-state-chip" id="k-chip-recognizing">
                <span class="k-spinner"></span>
                در حال تشخیص چهره
            </span>
        </div>

        <p class="k-camera-hint" id="k-camera-hint">دوربین در حال آماده‌سازی است…</p>
    </div>

    <div class="k-overlay-head">
        <button type="button" class="k-btn k-btn-lg" id="k-camera-retry" hidden>تلاش دوباره</button>
    </div>
</section>

<!-- ======================================================================
     Success screen
     ====================================================================== -->
<section class="k-overlay" id="kiosk-success" aria-label="نتیجه ثبت">
    <div class="k-result is-success">
        <div class="k-result-icon" aria-hidden="true">
            <svg width="58%" height="58%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6 9 17l-5-5"></path>
            </svg>
        </div>
        <h2 class="k-result-title" id="k-success-title">ورود با موفقیت ثبت شد</h2>
        <div id="k-success-body" style="width:100%"></div>
        <p class="k-result-message" id="k-success-message"></p>
    </div>
</section>

<!-- ======================================================================
     Error screen (no_face / multiple_faces / unknown_face / camera / offline)
     ====================================================================== -->
<section class="k-overlay" id="kiosk-error" aria-label="خطا">
    <div class="k-result is-error">
        <div class="k-result-icon" aria-hidden="true">
            <svg width="58%" height="58%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 9v4"></path>
                <path d="M12 17h.01"></path>
                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"></path>
            </svg>
        </div>
        <h2 class="k-result-title" id="k-error-title">خطا</h2>
        <p class="k-result-message" id="k-error-message"></p>
        <div class="k-result-facts">
            <div class="k-fact"><span>کد وضعیت</span><strong class="k-ltr" id="k-error-kind">—</strong></div>
        </div>
        <button type="button" class="k-btn k-btn-primary k-btn-lg" id="k-error-close">بازگشت</button>
    </div>
</section>

<!-- ======================================================================
     Blocked device screen (owner action required)
     ====================================================================== -->
<section class="k-overlay" id="kiosk-blocked" aria-label="دستگاه غیرفعال">
    <div class="k-result is-warning">
        <div class="k-result-icon" aria-hidden="true">
            <svg width="58%" height="58%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="10" width="16" height="10" rx="2"></rect>
                <path d="M8 10V7a4 4 0 1 1 8 0v3"></path>
            </svg>
        </div>
        <h2 class="k-result-title" id="k-blocked-title">این دستگاه فعال نیست</h2>
        <p class="k-result-message" id="k-blocked-message"></p>
        <button type="button" class="k-btn k-btn-lg" id="k-blocked-retry">بررسی دوباره</button>
    </div>
</section>

<!-- ======================================================================
     Settings drawer - NON sensitive device preferences only
     ====================================================================== -->
<div class="k-scrim" id="k-scrim"></div>
<aside class="k-drawer" id="k-drawer" aria-label="تنظیمات دستگاه">
    <div class="k-drawer-head">
        <h2 class="k-overlay-title">تنظیمات دستگاه</h2>
        <p class="k-overlay-sub">این تنظیمات فقط روی همین دستگاه اثر دارد و حساسیتی ندارند.</p>
        <button type="button" class="k-btn" id="k-drawer-close" style="margin-top:12px">بستن</button>
    </div>

    <div class="k-drawer-body">
        <div class="k-switch-row">
            <div class="k-switch-text">
                <strong>صدای موفقیت</strong>
                <small>یک صدای کوتاه پس از ثبت موفق پخش شود.</small>
            </div>
            <label class="k-switch">
                <input type="checkbox" id="set-success-sound">
                <span class="k-switch-track"></span>
            </label>
        </div>

        <label class="k-field">
            <span>مدت نمایش پیام موفقیت (ثانیه)</span>
            <input class="k-input" type="number" min="2" max="20" id="set-success-seconds" style="letter-spacing:normal">
        </label>

        <div class="k-switch-row">
            <div class="k-switch-text">
                <strong>نمایش ساعت و تاریخ</strong>
                <small>ساعت و تاریخ در صفحه اصلی دستگاه دیده شود.</small>
            </div>
            <label class="k-switch">
                <input type="checkbox" id="set-show-clock">
                <span class="k-switch-track"></span>
            </label>
        </div>

        <div class="k-switch-row">
            <div class="k-switch-text">
                <strong>نمایش هشدار قطع ارتباط</strong>
                <small>وقتی اینترنت قطع است، نوار هشدار نمایش داده شود.</small>
            </div>
            <label class="k-switch">
                <input type="checkbox" id="set-offline-banner">
                <span class="k-switch-track"></span>
            </label>
        </div>

        <label class="k-field">
            <span>دوربین دستگاه</span>
            <select class="k-input" id="set-camera-facing" style="letter-spacing:normal">
                <option value="user">دوربین جلو (selfie)</option>
                <option value="environment">دوربین پشت</option>
            </select>
        </label>

        <section class="k-switch-row" style="flex-direction:column;align-items:stretch">
            <div class="k-switch-text">
                <strong>آزمایش دوربین</strong>
                <small>تصویر زنده دوربین همین دستگاه را بررسی کنید.</small>
            </div>
            <button type="button" class="k-btn" id="k-camera-test">روشن کردن دوربین آزمایشی</button>
            <video id="k-camera-test-video" playsinline muted autoplay
                   style="width:100%;border-radius:16px;background:#05070d;max-height:180px"></video>
            <small id="k-camera-test-result"></small>
        </section>

        <div class="k-info-grid">
            <div class="k-info-row"><span>دستگاه</span><strong id="k-info-device">—</strong></div>
            <div class="k-info-row"><span>مجموعه</span><strong id="k-info-customer">—</strong></div>
            <div class="k-info-row"><span>شعبه</span><strong id="k-info-branch">—</strong></div>
            <div class="k-info-row"><span>وضعیت</span><strong id="k-info-status">—</strong></div>
            <div class="k-info-row"><span>آخرین ارتباط</span><strong id="k-info-seen">—</strong></div>
            <div class="k-info-row"><span>نسخه</span><strong class="k-ltr" id="k-info-version">—</strong></div>
            <div class="k-info-row k-info-row-wide"><span>تشخیص چهره</span><strong id="k-info-face">—</strong></div>
        </div>

        <p class="k-lock-note">
            <span aria-hidden="true">🔒</span>
            <span>
                توکن دستگاه، شعبه، فعال/غیرفعال بودن دستگاه و تغییر مشتری فقط از پنل مالک قابل انجام است و روی این
                دستگاه نمایش داده نمی‌شود. خروج از حالت کیوسک یا حذف اعتبار محلی، دستگاه را به حالت «فعال‌سازی» برمی‌گرداند
                و برای استفاده دوباره به کد فعال‌سازی جدید از مالک نیاز است.
            </span>
        </p>

        <button type="button" class="k-btn k-btn-danger k-btn-lg" id="k-forget">حذف اعتبار محلی این دستگاه</button>
    </div>
</aside>
