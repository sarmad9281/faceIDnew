<?php
/**
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
?>
<section class="card">
    <div class="card-head">
        <h1>تغییر رمز عبور</h1>
        <p class="muted">رمز عبور جدید باید حداقل <?= (int) \App\Core\Config::get('security.min_password_length', 10) ?> کاراکتر و شامل حرف و عدد باشد.</p>
    </div>

    <form method="post" action="<?= $e($url('/account/password')) ?>" autocomplete="off" novalidate>
        <?= $csrf() ?>

        <label class="field">
            <span>رمز عبور فعلی</span>
            <input type="password" name="current_password" required maxlength="200" dir="ltr">
        </label>

        <label class="field">
            <span>رمز عبور جدید</span>
            <input type="password" name="new_password" required minlength="10" maxlength="200" dir="ltr">
        </label>

        <label class="field">
            <span>تکرار رمز عبور جدید</span>
            <input type="password" name="new_password_confirmation" required minlength="10" maxlength="200" dir="ltr">
        </label>

        <button type="submit" class="btn btn-primary">ذخیره رمز عبور</button>
    </form>
</section>
