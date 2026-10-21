<?php
/**
 * Owner - manager create / edit form.
 *
 * @var array<string,mixed>|null $manager
 * @var array<string,mixed>|null $user
 * @var array<string,mixed> $customer
 * @var string $mode
 * @var array<string,mixed> $old
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$isEdit = $mode === 'edit';
$errors = [];

if ($raw = $flash('errors')) {
    $decoded = json_decode((string) $raw, true);
    $errors  = is_array($decoded) ? $decoded : [];
}

$value = static function (string $key, $default = '') use ($manager, $user, $old) {
    if (isset($old[$key]) && $old[$key] !== '') {
        return $old[$key];
    }

    if ($user !== null && array_key_exists($key, $user) && $key !== 'password') {
        return $user[$key];
    }

    if ($manager !== null && array_key_exists($key, $manager)) {
        return $manager[$key];
    }

    return $default;
};

$error = static function (string $key) use ($errors): string {
    return isset($errors[$key][0]) ? (string) $errors[$key][0] : '';
};
?>
<div class="o-page-head">
    <div>
        <h1><?= $e($isEdit ? 'ویرایش مدیر' : 'افزودن مدیر جدید') ?></h1>
        <p>
            مشتری: <strong><?= $e($customer['name']) ?></strong>
            <?php if ($isEdit && $manager !== null): ?>
                — مدیر: <strong><?= $e($manager['name']) ?></strong>
            <?php endif; ?>
        </p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/managers?customer_id=' . $customer['id'])) ?>">بازگشت به فهرست مدیران</a>
        <a class="o-btn" href="<?= $e($url('/owner/customers/' . $customer['uuid'])) ?>">صفحه مشتری</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="o-alert o-alert-error">لطفاً خطاهای فرم را برطرف کنید.</div>
<?php endif; ?>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head"><h2>اطلاعات و دسترسی ورود</h2></div>

        <form method="post" action="<?= $e($url($isEdit ? '/owner/managers/' . $manager['id'] : '/owner/managers')) ?>">
            <?= $csrf() ?>

            <?php if (!$isEdit): ?>
                <input type="hidden" name="customer_id" value="<?= $e($customer['id']) ?>">
            <?php endif; ?>

            <div class="o-form-grid">
                <label class="o-field <?= $error('first_name') !== '' ? 'is-error' : '' ?>">
                    <span>نام *</span>
                    <input type="text" name="first_name" value="<?= $e($isEdit ? '' : $value('first_name')) ?>" maxlength="80" required>
                    <?php if ($error('first_name')): ?><small class="o-error-text"><?= $e($error('first_name')) ?></small><?php endif; ?>
                </label>

                <label class="o-field">
                    <span>نام خانوادگی *</span>
                    <input type="text" name="last_name" value="<?= $e($isEdit ? '' : $value('last_name')) ?>" maxlength="80" required>
                </label>

                <?php if ($isEdit): ?>
                    <label class="o-field">
                        <span>نام کامل مدیر *</span>
                        <input type="text" name="name" value="<?= $e($value('name')) ?>" maxlength="150" required>
                    </label>
                <?php endif; ?>

                <label class="o-field <?= $error('title') !== '' ? 'is-error' : '' ?>">
                    <span>سمت</span>
                    <input type="text" name="title" value="<?= $e($value('title')) ?>" maxlength="120">
                </label>

                <label class="o-field <?= $error('mobile') !== '' ? 'is-error' : '' ?>">
                    <span>موبایل</span>
                    <input type="text" name="mobile" value="<?= $e($value('mobile')) ?>" maxlength="25" placeholder="09xxxxxxxxx">
                    <?php if ($error('mobile')): ?><small class="o-error-text"><?= $e($error('mobile')) ?></small><?php endif; ?>
                </label>

                <label class="o-field <?= $error('email') !== '' ? 'is-error' : '' ?>">
                    <span>ایمیل *</span>
                    <input type="text" name="email" value="<?= $e($value('email')) ?>" maxlength="190" class="o-ltr" required>
                    <?php if ($error('email')): ?><small class="o-error-text"><?= $e($error('email')) ?></small><?php endif; ?>
                </label>

                <label class="o-field <?= $error('username') !== '' ? 'is-error' : '' ?>">
                    <span>نام کاربری *</span>
                    <input type="text" name="username" value="<?= $e($value('username')) ?>" maxlength="60" class="o-ltr" required>
                    <small>فقط حروف کوچک انگلیسی، رقم، نقطه، خط تیره و زیرخط.</small>
                    <?php if ($error('username')): ?><small class="o-error-text"><?= $e($error('username')) ?></small><?php endif; ?>
                </label>

                <?php if (!$isEdit): ?>
                    <label class="o-field <?= $error('is_primary') !== '' ? 'is-error' : '' ?>">
                        <span>نوع مدیر</span>
                        <select name="is_primary">
                            <option value="1" selected>مدیر اصلی</option>
                            <option value="0">مدیر عادی</option>
                        </select>
                    </label>
                <?php endif; ?>
            </div>

            <?php if (!$isEdit): ?>
                <label class="o-field <?= $error('password') !== '' ? 'is-error' : '' ?>">
                    <span>رمز عبور *</span>
                    <input type="text" name="password" value="<?= $e($value('password')) ?>" maxlength="64" class="o-ltr" required>
                    <small>حداقل ۱۰ کاراکتر شامل حرف بزرگ، کوچک، رقم و نماد. مدیر در اولین ورود باید رمز را تغییر دهد.</small>
                    <?php if ($error('password')): ?><small class="o-error-text"><?= $e($error('password')) ?></small><?php endif; ?>
                </label>
            <?php endif; ?>

            <button type="submit" class="o-btn o-btn-primary"><?= $e($isEdit ? 'ذخیره تغییرات' : 'ایجاد مدیر') ?></button>
        </form>
    </section>

    <div>
        <section class="o-card">
            <div class="o-card-head"><h2>وضعیت حساب</h2></div>

            <?php if ($isEdit && $manager !== null): ?>
                <div class="o-kv">
                    <div><span>وضعیت</span><strong><?= !empty($manager['is_active']) ? 'فعال' : 'غیرفعال' ?></strong></div>
                    <div><span>نقش</span><strong>مدیر مشتری (manager)</strong></div>
                    <div><span>مشتری</span><strong><?= $e($customer['name']) ?></strong></div>
                    <div><span>آخرین ورود</span><strong><?= $e($user['last_login_at'] ?? '—') ?></strong></div>
                </div>

                <div class="o-actions o-mt">
                    <form method="post" action="<?= $e($url('/owner/managers/' . $manager['id'] . '/toggle')) ?>" class="o-inline-form"
                          data-confirm="<?= !empty($manager['is_active']) ? 'این مدیر غیرفعال شود؟' : 'این مدیر فعال شود؟' ?>">
                        <?= $csrf() ?>
                        <input type="hidden" name="active" value="<?= !empty($manager['is_active']) ? '0' : '1' ?>">
                        <button type="submit" class="o-btn <?= !empty($manager['is_active']) ? 'o-btn-danger' : 'o-btn-ok' ?>">
                            <?= !empty($manager['is_active']) ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>
                        </button>
                    </form>

                    <form method="post" action="<?= $e($url('/owner/managers/' . $manager['id'] . '/reset-password')) ?>" class="o-inline-form"
                          data-confirm="رمز عبور بازنشانی شود؟ رمز جدید فقط یک بار نمایش داده می‌شود.">
                        <?= $csrf() ?>
                        <button type="submit" class="o-btn o-btn-warn">بازنشانی رمز عبور</button>
                    </form>
                </div>

                <p class="o-small o-muted o-mt">
                    رمز عبور به صورت هش ذخیره می‌شود و هیچ‌جا (حتی برای مالک سامانه) قابل مشاهده نیست.
                    در صورت فراموشی، تنها راه، بازنشانی رمز است.
                </p>
            <?php else: ?>
                <p class="o-small o-muted">
                    حساب مدیر با نقش «manager» و محدود به همین مشتری ساخته می‌شود. مدیر نمی‌تواند مدیر دیگری بسازد،
                    نقش خود را تغییر دهد یا داده مشتری دیگری را ببیند.
                </p>
            <?php endif; ?>
        </section>

        <section class="o-card">
            <div class="o-card-head"><h2>امکانات این مشتری</h2></div>
            <p class="o-small o-muted">
                دسترسی مدیر به بخش‌ها بر اساس امکانات فعال همین مشتری تعیین می‌شود؛ اگر «شعب» غیرفعال باشد،
                صفحه شعبه‌ها برای این مدیر وجود نخواهد داشت.
            </p>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers/' . $customer['uuid'] . '/features')) ?>">مدیریت امکانات</a>
        </section>
    </div>
</div>
