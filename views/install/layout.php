<?php
/**
 * Installer layout.
 *
 * @var string $step
 * @var array  $errors
 * @var array  $form
 * @var array|null $dbReport
 * @var App\Services\InstallerService $installer
 * @var array  $requirements
 * @var bool   $requirementsPassed
 * @var array  $scope
 * @var string $prefix
 * @var string $csrfToken
 * @var string $suggestedPassword
 * @var bool   $sqlGuardOk
 * @var array|null $preconfigured  masked summary of the shipped config (no secrets)
 */
$steps = [
    '1' => 'بازبینی پیش‌نیازها و اتصال دیتابیس',
    '2' => 'حساب مالک و تنظیمات اولیه',
];
$current = array_key_exists((string) $step, $steps) ? (string) $step : '1';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب SARMAD Attendance</title>
    <link rel="stylesheet" href="assets/install.css">
</head>
<body>
<header class="inst-top">
    <div class="inst-brand">
        <span class="brand-mark">SA</span>
        <div>
            <strong>SARMAD Attendance</strong>
            <small>نصب‌کننده نسخه ۱.۰.۰ — مرحله اول (هسته سامانه)</small>
        </div>
    </div>
    <ol class="inst-steps">
        <?php foreach ($steps as $number => $label): ?>
            <li class="<?= $number === $current ? 'is-active' : ($number < $current ? 'is-done' : '') ?>">
                <span><?= h($number) ?></span><?= h($label) ?>
            </li>
        <?php endforeach; ?>
    </ol>
</header>

<main class="inst-main">
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if (!$sqlGuardOk): ?>
        <div class="alert alert-error">هشدار: فایل SQL از بازبینی امنیتی عبور نکرد. نصب ادامه داده نمی‌شود.</div>
    <?php endif; ?>

    <?php if (is_array($dbReport) && ($dbReport['ok'] ?? false)): ?>
        <section class="inst-card">
            <h1>نتیجه بررسی دیتابیس</h1>
            <ul class="report">
                <li>نسخه سرور: <code class="ltr"><?= h($dbReport['server'] ?? '') ?></code></li>
                <li>دیتابیس: <code class="ltr"><?= h($dbReport['database'] ?? '') ?></code></li>
                <li>جداول پروژه (<?= h($prefix) ?>*): <strong><?= count((array) ($dbReport['project_tables'] ?? [])) ?></strong> جدول</li>
                <li>جداول در انتظار ایجاد: <strong><?= count((array) ($dbReport['missing'] ?? [])) ?></strong> جدول</li>
                <li>
                    جداول سایر پروژه‌ها (فقط شناسایی شد، دست نخواهد خورد):
                    <strong><?= count((array) ($dbReport['foreign_tables'] ?? [])) ?></strong>
                </li>
            </ul>

            <?php if (!empty($dbReport['foreign_tables'])): ?>
                <details>
                    <summary>مشاهده فهرست جداول سایر پروژه‌ها (read-only)</summary>
                    <div class="chips">
                        <?php foreach ((array) $dbReport['foreign_tables'] as $table): ?>
                            <span class="chip ltr"><?= h($table) ?></span>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($current === '1'): ?>
        <section class="inst-card">
            <h1>۱. پیش‌نیازهای سرور</h1>
            <table class="inst-table">
                <thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead>
                <tbody>
                <?php foreach ($requirements as $check): ?>
                    <tr>
                        <td><?= h($check['label']) ?></td>
                        <td>
                            <span class="pill <?= $check['ok'] ? 'pill-ok' : ($check['fatal'] ? 'pill-bad' : 'pill-warn') ?>">
                                <?= $check['ok'] ? 'OK' : ($check['fatal'] ? 'خطا' : 'هشدار') ?>
                            </span>
                        </td>
                        <td class="ltr small"><?= h($check['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <?php if (!empty($preconfigured) && is_array($preconfigured)): ?>
        <section class="inst-card">
            <h1>پیکربندی آماده شناسایی شد</h1>
            <p class="muted">
                فایل <code class="ltr">config/config.php</code> همراه همین بسته ارسال شده و کامل است؛
                نیازی به وارد کردن دوبارهٔ رمز عبور دیتابیس نیست. با یک کلیک، اتصال با همان اطلاعات
                ذخیره‌شده بررسی می‌شود و به مرحلهٔ ساخت حساب مالک می‌روید. ساخت جداول
                <code class="ltr"><?= h($prefix) ?>*</code> و قفل نصب (<code class="ltr">storage/installed.lock</code>)
                در انتهای همان مرحله انجام می‌شود.
            </p>
            <ul class="report">
                <li>هاست دیتابیس: <code class="ltr"><?= h($preconfigured['host']) ?>:<?= h($preconfigured['port']) ?></code></li>
                <li>دیتابیس: <code class="ltr"><?= h($preconfigured['database']) ?></code></li>
                <li>کاربر دیتابیس: <code class="ltr"><?= h($preconfigured['username']) ?></code></li>
                <li>پیشوند جداول: <code class="ltr"><?= h($preconfigured['prefix']) ?></code></li>
                <li>رمز عبور دیتابیس: ذخیره‌شده در سرور (نمایش داده نمی‌شود)</li>
                <li>کلید برنامه (app.key): <?= !empty($preconfigured['key_ok']) ? 'معتبر و آماده (۳۲ بایت)' : 'هنگام نصب ساخته می‌شود' ?></li>
            </ul>

            <form method="post" action="<?= h(installer_url()) ?>" autocomplete="off">
                <input type="hidden" name="_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="use_preconfigured">
                <button type="submit" class="btn btn-primary" <?= $requirementsPassed ? '' : 'disabled' ?>>
                    تأیید و ادامه نصب با همین پیکربندی
                </button>
            </form>

            <p class="muted small">اگر این اطلاعات درست نیست، از فرم دستی بخش بعد استفاده کنید؛ مقدار تایپ‌شده جایگزین پیکربندی آماده می‌شود.</p>
        </section>
        <?php endif; ?>

        <section class="inst-card">
            <h1>۲. اطلاعات اتصال دیتابیس</h1>
            <p class="muted">
                سامانه روی دیتابیس مشترک سازمان اجرا می‌شود. تمام جداول این پروژه فقط با پیشوند
                <code class="ltr"><?= h($prefix) ?></code> ساخته می‌شوند و هیچ جدول دیگری خوانده یا تغییر داده نمی‌شود.
            </p>

            <div class="scope">
                <div class="scope-box scope-ok">
                    <h3>مجاز است</h3>
                    <ul>
                        <?php foreach ($scope['allowed'] as $item): ?>
                            <li><?= h($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="scope-box scope-no">
                    <h3>ممنوع است</h3>
                    <ul>
                        <?php foreach ($scope['forbidden'] as $item): ?>
                            <li><?= h($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <form method="post" action="<?= h(installer_url()) ?>" autocomplete="off">
                <input type="hidden" name="_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="test_connection">

                <div class="grid">
                    <label class="field">
                        <span>Host دیتابیس</span>
                        <input type="text" name="db_host" value="<?= h($form['db_host']) ?>" required dir="ltr">
                    </label>

                    <label class="field">
                        <span>Port</span>
                        <input type="number" name="db_port" value="<?= h($form['db_port']) ?>" required min="1" max="65535" dir="ltr">
                    </label>

                    <label class="field">
                        <span>نام دیتابیس</span>
                        <input type="text" name="db_database" value="<?= h($form['db_database']) ?>" required dir="ltr">
                    </label>

                    <label class="field">
                        <span>کاربر دیتابیس</span>
                        <input type="text" name="db_username" value="<?= h($form['db_username']) ?>" required dir="ltr">
                    </label>

                    <label class="field">
                        <span>رمز عبور دیتابیس</span>
                        <input type="password" name="db_password" value="" dir="ltr" autocomplete="new-password" placeholder="اگر خالی بماند، رمز ذخیره‌شده استفاده می‌شود">
                    </label>

                    <?php if (!empty($hasStoredPassword)): ?>
                        <p class="muted small">یک رمز معتبر از قبل در <code>config/config.php</code> ذخیره شده است. اگر این کادر را خالی بگذارید، همان رمز استفاده می‌شود؛ و اگر رمزی تایپ کنید، مقدار تایپ‌شده ارسال خواهد شد.</p>
                    <?php else: ?>
                        <p class="muted small">رمزی در <code>config/config.php</code> ذخیره نشده است؛ لطفاً رمز را در کادر بالا وارد کنید.</p>
                    <?php endif; ?>

                    <label class="field">
                        <span>پیشوند جداول (تغییر ندهید)</span>
                        <input type="text" name="db_prefix" value="<?= h($form['db_prefix']) ?>" readonly dir="ltr">
                    </label>
                </div>

                <button type="submit" class="btn btn-primary" <?= $requirementsPassed ? '' : 'disabled' ?>>
                    بررسی اتصال و دیتابیس
                </button>

                <?php if (!$requirementsPassed): ?>
                    <p class="muted small">تا رفع خطاهای پیش‌نیاز، ادامه نصب امکان‌پذیر نیست.</p>
                <?php endif; ?>
            </form>
        </section>

    <?php else: ?>
        <section class="inst-card">
            <h1>۳. حساب مالک سامانه</h1>
            <p class="muted">این حساب بالاترین سطح دسترسی را دارد. رمز عبور با bcrypt ذخیره می‌شود و هرگز به صورت متن ساده نگهداری نمی‌شود.</p>

            <form method="post" action="<?= h(installer_url()) ?>" autocomplete="off">
                <input type="hidden" name="_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="install">

                <?php // The database password is deliberately NOT carried through the HTML:
                      // it stays in the server side session (installer_remember_db()).
                ?>
                <?php foreach (['db_host', 'db_port', 'db_database', 'db_username', 'db_prefix'] as $key): ?>
                    <input type="hidden" name="<?= h($key) ?>" value="<?= h($form[$key]) ?>">
                <?php endforeach; ?>
                <p class="muted small">رمز عبور دیتابیس فقط در نشست سرور نگه داشته می‌شود و در HTML نمایش داده نمی‌شود.</p>

                <div class="grid">
                    <label class="field">
                        <span>نام و نام خانوادگی مالک *</span>
                        <input type="text" name="owner_name" required maxlength="150">
                    </label>

                    <label class="field">
                        <span>نام کاربری مالک *</span>
                        <input type="text" name="owner_username" required maxlength="60" dir="ltr" placeholder="owner">
                    </label>

                    <label class="field">
                        <span>ایمیل مالک</span>
                        <input type="email" name="owner_email" maxlength="190" dir="ltr">
                    </label>

                    <label class="field">
                        <span>موبایل مالک</span>
                        <input type="text" name="owner_mobile" maxlength="20" dir="ltr">
                    </label>

                    <label class="field">
                        <span>رمز عبور مالک *</span>
                        <input type="text" name="owner_password" required minlength="10" maxlength="200"
                               dir="ltr" value="<?= h($suggestedPassword) ?>">
                        <small class="muted">پیشنهاد امن تولید شده است؛ می‌توانید آن را تغییر دهید.</small>
                    </label>

                    <label class="field">
                        <span>تکرار رمز عبور مالک *</span>
                        <input type="text" name="owner_password_confirmation" required minlength="10" maxlength="200"
                               dir="ltr" value="<?= h($suggestedPassword) ?>">
                    </label>

                    <label class="field">
                        <span>منطقه زمانی</span>
                        <input type="text" name="timezone" value="<?= h($form['timezone']) ?>" dir="ltr" maxlength="64">
                    </label>

                    <label class="field">
                        <span>تقویم پیش‌فرض</span>
                        <select name="date_system">
                            <option value="jalali" <?= $form['date_system'] === 'jalali' ? 'selected' : '' ?>>شمسی</option>
                            <option value="gregorian" <?= $form['date_system'] === 'gregorian' ? 'selected' : '' ?>>میلادی</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>نام سامانه</span>
                        <input type="text" name="app_name" value="<?= h($form['app_name']) ?>" maxlength="120">
                    </label>

                    <label class="field">
                        <span>آدرس سامانه (خودکار)</span>
                        <input type="text" name="app_url" value="<?= h($form['app_url']) ?>" dir="ltr" maxlength="190" placeholder="خالی = تشخیص خودکار">
                    </label>
                </div>

                <h2>۴. مشتری اول (اختیاری)</h2>
                <p class="muted small">
                    اگر می‌خواهید همین حالا یک محدوده مشتری و حساب مدیر آن ساخته شود، این بخش را تکمیل کنید.
                    در غیر این صورت از پنل مالک، مشتریان را اضافه کنید.
                </p>

                <div class="grid">
                    <label class="field">
                        <span>نام مشتری</span>
                        <input type="text" name="customer_name" maxlength="150">
                    </label>

                    <label class="field">
                        <span>نام شرکت</span>
                        <input type="text" name="customer_company" maxlength="180">
                    </label>

                    <label class="field">
                        <span>نام مدیر مشتری</span>
                        <input type="text" name="manager_name" maxlength="150">
                    </label>

                    <label class="field">
                        <span>ایمیل مدیر مشتری</span>
                        <input type="email" name="manager_email" maxlength="190" dir="ltr">
                    </label>

                    <label class="field">
                        <span>رمز عبور مدیر مشتری</span>
                        <input type="text" name="manager_password" minlength="10" maxlength="200" dir="ltr"
                               value="<?= h($suggestedPassword) ?>">
                    </label>
                </div>

                <div class="warn-box">
                    با زدن دکمه زیر:
                    <ul>
                        <li>فایل <code class="ltr">config/config.php</code> ساخته می‌شود.</li>
                        <li>جداول <code class="ltr"><?= h($prefix) ?>*</code> در دیتابیس ایجاد می‌شوند.</li>
                        <li>حساب مالک و تنظیمات اولیه ثبت می‌شود.</li>
                        <li>نصب‌کننده قفل می‌شود و دیگر قابل اجرا نخواهد بود.</li>
                    </ul>
                </div>

                <button type="submit" class="btn btn-primary">شروع نصب</button>
            </form>
        </section>
    <?php endif; ?>
</main>

<footer class="inst-foot">
    <span>PHP <?= h(PHP_VERSION) ?></span>
    <span>Server: <?= h($serverSoftware) ?></span>
    <span>Prefix: <code class="ltr"><?= h($prefix) ?></code></span>
    <span>Build: <code class="ltr"><?= h(defined('SARMAD_INSTALLER_BUILD') ? SARMAD_INSTALLER_BUILD : \App\Services\InstallerService::BUILD) ?></code></span>
</footer>
</body>
</html>
