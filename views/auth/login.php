<?php
/**
 * @var callable    $e
 * @var callable    $url
 * @var callable    $csrf
 * @var string|null $error
 * @var string      $intended
 */
?>
<section class="card card-auth">
    <div class="card-head">
        <span class="brand-mark">SA</span>
        <h1>ورود به SARMAD Attendance</h1>
        <p class="muted">برای ورود، نام کاربری یا ایمیل حساب خود را وارد کنید.</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e($url('/login')) ?>" autocomplete="off" novalidate>
        <?= $csrf() ?>
        <input type="hidden" name="next" value="<?= $e($intended) ?>">

        <label class="field">
            <span>نام کاربری یا ایمیل</span>
            <input type="text" name="identifier" required maxlength="190" dir="ltr" autofocus>
        </label>

        <label class="field">
            <span>رمز عبور</span>
            <input type="password" name="password" required maxlength="200" dir="ltr">
        </label>

        <button type="submit" class="btn btn-primary btn-block">ورود</button>
    </form>

    <p class="muted small">IP شما و نتیجه تلاش‌های ورود به منظور امنیت ثبت می‌شود.</p>
</section>
