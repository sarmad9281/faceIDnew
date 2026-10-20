<?php
/**
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$generatedPassword = \App\Core\Security::suggestPassword(12);
?>
<section class="page-head">
    <div>
        <h1>افزودن مشتری</h1>
        <p class="muted">ایجاد محدوده جدید و (اختیاری) نخستین حساب مدیر مشتری.</p>
    </div>
</section>

<section class="card">
    <form method="post" action="<?= $e($url('/owner/customers')) ?>" autocomplete="off" novalidate>
        <?= $csrf() ?>

        <h2>اطلاعات مشتری</h2>

        <div class="grid-2">
            <label class="field">
                <span>نام مشتری *</span>
                <input type="text" name="name" required maxlength="150">
            </label>

            <label class="field">
                <span>نام شرکت</span>
                <input type="text" name="company_name" maxlength="180">
            </label>

            <label class="field">
                <span>ایمیل تماس</span>
                <input type="email" name="contact_email" maxlength="190" dir="ltr">
            </label>

            <label class="field">
                <span>تلفن تماس</span>
                <input type="text" name="contact_phone" maxlength="20" dir="ltr">
            </label>

            <label class="field">
                <span>منطقه زمانی</span>
                <input type="text" name="timezone" value="Asia/Tehran" maxlength="64" dir="ltr">
            </label>

            <label class="field">
                <span>تقویم</span>
                <select name="date_system">
                    <option value="jalali">شمسی</option>
                    <option value="gregorian">میلادی</option>
                </select>
            </label>
        </div>

        <h2>حساب مدیر مشتری (اختیاری)</h2>
        <p class="muted small">در صورت تکمیل، یک کاربر با نقش «مدیر مشتری» ساخته می‌شود که فقط به داده‌های همین مشتری دسترسی دارد.</p>

        <div class="grid-2">
            <label class="field">
                <span>نام مدیر</span>
                <input type="text" name="manager_name" maxlength="150">
            </label>

            <label class="field">
                <span>ایمیل مدیر</span>
                <input type="email" name="manager_email" maxlength="190" dir="ltr">
            </label>

            <label class="field">
                <span>رمز عبور مدیر</span>
                <input type="text" name="manager_password" value="<?= $e($generatedPassword) ?>" minlength="10" maxlength="200" dir="ltr">
                <small class="muted">این رمز فقط همین یک بار نمایش داده می‌شود؛ آن را ذخیره کنید.</small>
            </label>
        </div>

        <button type="submit" class="btn btn-primary">ایجاد مشتری</button>
    </form>
</section>
