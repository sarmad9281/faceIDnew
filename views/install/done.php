<?php
/**
 * Installation result page.
 *
 * @var string $owner_username
 * @var string $customer_message
 * @var string $manager_username
 * @var array  $created_tables
 * @var array  $foreign_tables
 * @var int    $executed
 * @var string $login_url
 * @var string $base_url
 * @var string $lock_path
 * @var array  $errors
 */
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب موفق - SARMAD Attendance</title>
    <link rel="stylesheet" href="assets/install.css">
</head>
<body>
<main class="inst-main">
    <section class="inst-card">
        <h1>✅ نصب با موفقیت انجام شد</h1>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endforeach; ?>

        <ul class="report">
            <li>دستورات اجراشده SQL: <strong><?= (int) $executed ?></strong></li>
            <li>جداول ایجادشده: <strong><?= count((array) $created_tables) ?></strong></li>
            <li>حساب مالک: <code class="ltr"><?= h($owner_username) ?></code></li>
            <?php if ($manager_username !== ''): ?>
                <li>حساب مدیر مشتری: <code class="ltr"><?= h($manager_username) ?></code></li>
            <?php endif; ?>
            <?php if ($customer_message !== ''): ?>
                <li><?= h($customer_message) ?></li>
            <?php endif; ?>
            <li>فایل قفل نصب: <code class="ltr"><?= h($lock_path) ?></code></li>
        </ul>

        <?php if (!empty($created_tables)): ?>
            <details open>
                <summary>جداول ایجادشده در این نصب</summary>
                <div class="chips">
                    <?php foreach ((array) $created_tables as $table): ?>
                        <span class="chip ltr"><?= h($table) ?></span>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php if (!empty($foreign_tables)): ?>
            <details>
                <summary>جداول سایر پروژه‌ها که شناسایی شد و دست‌نخورده باقی ماند (<?= count((array) $foreign_tables) ?>)</summary>
                <div class="chips">
                    <?php foreach ((array) $foreign_tables as $table): ?>
                        <span class="chip ltr"><?= h($table) ?></span>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <div class="warn-box">
            <strong>کارهای امنیتی باقی‌مانده (توصیه اکید):</strong>
            <ul>
                <li>پوشه <code class="ltr">/install</code> را از هاست حذف یا غیرفعال کنید.</li>
                <li>اطمینان حاصل کنید فایل <code class="ltr">config/config.php</code> قابل دانلود از مرورگر نیست.</li>
                <li>با حساب مالک وارد شوید و رمز عبور را در صورت نیاز تغییر دهید.</li>
            </ul>
        </div>

        <p>
            <a class="btn btn-primary" href="<?= h($login_url) ?>">ورود به سامانه</a>
        </p>
        <p class="muted small ltr"><?= h($base_url) ?></p>
    </section>
</main>
</body>
</html>
