<?php
/**
 * Owner - customer create / edit form (shared).
 *
 * @var array<string,mixed>|null $customer
 * @var string $mode 'create'|'edit'
 * @var array<int,array<string,mixed>> $managers
 * @var array<string,int|string> $plans
 * @var array<string,string> $statuses
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

$value = static function (string $key, $default = '') use ($customer, $old) {
    if (isset($old[$key]) && $old[$key] !== '') {
        return $old[$key];
    }

    if ($customer !== null && array_key_exists($key, $customer)) {
        return $customer[$key];
    }

    return $default;
};

$error = static function (string $key) use ($errors): string {
    return isset($errors[$key][0]) ? (string) $errors[$key][0] : '';
};
?>
<div class="o-page-head">
    <div>
        <h1><?= $e($isEdit ? 'ویرایش مشتری' : 'افزودن مشتری جدید') ?></h1>
        <p><?= $e($isEdit
            ? 'اطلاعات کسب‌وکار، لوگو و محدودیت‌های مشتری را ویرایش کنید.'
            : 'اطلاعات کسب‌وکار، اشتراک اولیه و در صورت نیاز حساب مدیر را وارد کنید.') ?></p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/customers')) ?>">بازگشت به فهرست</a>
        <?php if ($isEdit): ?>
            <a class="o-btn" href="<?= $e($url('/owner/customers/' . $customer['uuid'])) ?>">صفحه مشتری</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="o-alert o-alert-error">لطفاً خطاهای فرم را برطرف کنید.</div>
<?php endif; ?>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head"><h2>اطلاعات کسب‌وکار</h2></div>

        <form method="post" id="customer-create-form"
              action="<?= $e($url($isEdit ? '/owner/customers/' . $customer['uuid'] : '/owner/customers')) ?>"
              enctype="multipart/form-data">
            <?= $csrf() ?>

            <div class="o-form-grid">
                <label class="o-field <?= $error('name') !== '' ? 'is-error' : '' ?>">
                    <span>نام کسب‌وکار / مشتری *</span>
                    <input type="text" name="name" value="<?= $e($value('name')) ?>" required maxlength="150">
                    <?php if ($error('name')): ?><small class="o-error-text"><?= $e($error('name')) ?></small><?php endif; ?>
                </label>

                <label class="o-field <?= $error('company_name') !== '' ? 'is-error' : '' ?>">
                    <span>نام شرکت</span>
                    <input type="text" name="company_name" value="<?= $e($value('company_name')) ?>" maxlength="180">
                    <?php if ($error('company_name')): ?><small class="o-error-text"><?= $e($error('company_name')) ?></small><?php endif; ?>
                </label>

                <label class="o-field <?= $error('contact_phone') !== '' ? 'is-error' : '' ?>">
                    <span>موبایل</span>
                    <input type="text" name="contact_phone" value="<?= $e($value('contact_phone')) ?>" maxlength="25" placeholder="09xxxxxxxxx">
                    <?php if ($error('contact_phone')): ?><small class="o-error-text"><?= $e($error('contact_phone')) ?></small><?php endif; ?>
                </label>

                <label class="o-field <?= $error('contact_email') !== '' ? 'is-error' : '' ?>">
                    <span>ایمیل</span>
                    <input type="text" name="contact_email" value="<?= $e($value('contact_email')) ?>" maxlength="190" class="o-ltr">
                    <?php if ($error('contact_email')): ?><small class="o-error-text"><?= $e($error('contact_email')) ?></small><?php endif; ?>
                </label>
            </div>

            <label class="o-field">
                <span>آدرس</span>
                <input type="text" name="address" value="<?= $e($value('address')) ?>" maxlength="255">
            </label>

            <label class="o-field">
                <span>توضیحات داخلی</span>
                <textarea name="notes" maxlength="500" placeholder="یادداشت مالک درباره این مشتری (در تنظیمات مشتری ذخیره می‌شود)"><?= $e($value('notes')) ?></textarea>
            </label>

            <div class="o-form-grid o-form-grid-3">
                <label class="o-field">
                    <span>منطقه زمانی</span>
                    <input type="text" name="timezone" value="<?= $e($value('timezone', 'Asia/Tehran')) ?>" maxlength="64" class="o-ltr">
                </label>

                <label class="o-field">
                    <span>تقویم</span>
                    <select name="date_system">
                        <option value="jalali" <?= $value('date_system', 'jalali') === 'jalali' ? 'selected' : '' ?>>شمسی</option>
                        <option value="gregorian" <?= $value('date_system') === 'gregorian' ? 'selected' : '' ?>>میلادی</option>
                    </select>
                </label>

                <?php if ($isEdit): ?>
                    <label class="o-field">
                        <span>وضعیت مشتری</span>
                        <select name="status">
                            <?php foreach (['active' => 'فعال', 'suspended' => 'غیرفعال', 'archived' => 'آرشیو'] as $key => $title): ?>
                                <option value="<?= $e($key) ?>" <?= $value('status') === $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else: ?>
                    <label class="o-field">
                        <span>پلن اشتراک</span>
                        <select name="plan_name">
                            <?php foreach ($plans as $key => $title): ?>
                                <option value="<?= $e($key) ?>" <?= (string) $defaultPlan === (string) $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
            </div>

            <?php if ($isEdit): ?>
                <div class="o-form-grid o-form-grid-3">
                    <label class="o-field">
                        <span>سقف پرسنل</span>
                        <input type="number" name="max_employees" min="1" max="100000" value="<?= $e($value('max_employees', 50)) ?>">
                    </label>
                    <label class="o-field">
                        <span>سقف دستگاه</span>
                        <input type="number" name="max_devices" min="1" max="10000" value="<?= $e($value('max_devices', 5)) ?>">
                    </label>
                </div>
            <?php else: ?>
                <div class="o-form-grid o-form-grid-3">
                    <label class="o-field">
                        <span>وضعیت اشتراک</span>
                        <select name="subscription_status">
                            <?php foreach ($statuses as $key => $title): ?>
                                <option value="<?= $e($key) ?>" <?= $key === 'trial' ? 'selected' : '' ?>><?= $e($title) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="o-field">
                        <span>تاریخ شروع</span>
                        <input type="date" name="start_date" value="<?= $e(date('Y-m-d')) ?>" class="o-ltr">
                    </label>
                    <label class="o-field">
                        <span>تاریخ پایان</span>
                        <input type="date" name="expire_date" value="<?= $e(date('Y-m-d', time() + ((int) $trialDays * 86400))) ?>" class="o-ltr">
                        <small>پیش‌فرض: <?= $e($trialDays) ?> روز آزمایشی</small>
                    </label>
                </div>
            <?php endif; ?>

            <button type="submit" class="o-btn o-btn-primary o-mt">
                <?= $e($isEdit ? 'ذخیره تغییرات' : 'ایجاد مشتری') ?>
            </button>
        </form>
    </section>

    <div>
        <?php if ($isEdit): ?>
            <section class="o-card">
                <div class="o-card-head"><h2>لوگوی مشتری<span class="o-sub">JPG / PNG / WEBP — حداکثر ۵ مگابایت</span></h2></div>

                <?php if ($logoUrl !== null): ?>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                        <img src="<?= $e($logoUrl) ?>" alt="لوگو" style="max-width:120px;max-height:80px;border-radius:10px;border:1px solid var(--o-line)">
                        <span class="o-small o-muted"><?= $e((string) $customer['logo_path']) ?></span>
                    </div>
                <?php else: ?>
                    <div class="o-empty"><strong>لوگویی ثبت نشده است</strong>فرمت‌های مجاز: JPG، PNG، WEBP</div>
                <?php endif; ?>

                <form method="post" action="<?= $e($url('/owner/customers/' . $customer['uuid'] . '/logo')) ?>" enctype="multipart/form-data">
                    <?= $csrf() ?>
                    <label class="o-field">
                        <span>انتخاب فایل لوگو</span>
                        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required>
                        <small>پسوند و نوع واقعی فایل بررسی می‌شود؛ فایل‌های اجرایی (PHP/JS/SVG) رد می‌شوند.</small>
                    </label>
                    <button type="submit" class="o-btn">بارگذاری لوگو</button>
                </form>
            </section>

            <section class="o-card">
                <div class="o-card-head">
                    <h2>مدیران این مشتری</h2>
                    <a class="o-btn o-btn-sm o-btn-primary" href="<?= $e($url('/owner/managers/create?customer_id=' . $customer['id'])) ?>">افزودن مدیر</a>
                </div>

                <?php if ($managers === []): ?>
                    <div class="o-empty"><strong>مدیری ثبت نشده است</strong>یک حساب مدیر بسازید تا مشتری بتواند وارد شود.</div>
                <?php else: ?>
                    <ul class="o-timeline">
                        <?php foreach ($managers as $manager): ?>
                            <li>
                                <span class="o-what">
                                    <strong><?= $e($manager['name']) ?></strong>
                                    <small><?= $e($manager['email'] ?? '—') ?> — <?= $e($manager['mobile'] ?? '') ?></small>
                                </span>
                                <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/managers/' . $manager['id'] . '/edit')) ?>">ویرایش</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="o-card">
                <div class="o-card-head"><h2>حساب مدیر اولیه<span class="o-sub">اختیاری — می‌توانید بعداً هم بسازید</span></h2></div>

                <div class="o-form-grid">
                    <label class="o-field <?= $error('manager_first_name') !== '' ? 'is-error' : '' ?>">
                        <span>نام</span>
                        <input type="text" name="manager_first_name" form="customer-create-form" value="<?= $e($old['manager_first_name'] ?? '') ?>" maxlength="80">
                    </label>
                    <label class="o-field">
                        <span>نام خانوادگی</span>
                        <input type="text" name="manager_last_name" form="customer-create-form" value="<?= $e($old['manager_last_name'] ?? '') ?>" maxlength="80">
                    </label>
                    <label class="o-field <?= $error('manager_mobile') !== '' ? 'is-error' : '' ?>">
                        <span>موبایل</span>
                        <input type="text" name="manager_mobile" form="customer-create-form" value="<?= $e($old['manager_mobile'] ?? '') ?>" maxlength="25">
                    </label>
                    <label class="o-field <?= $error('manager_email') !== '' ? 'is-error' : '' ?>">
                        <span>ایمیل *</span>
                        <input type="text" name="manager_email" form="customer-create-form" value="<?= $e($old['manager_email'] ?? '') ?>" maxlength="190" class="o-ltr">
                        <?php if ($error('manager_email')): ?><small class="o-error-text"><?= $e($error('manager_email')) ?></small><?php endif; ?>
                    </label>
                    <label class="o-field <?= $error('manager_username') !== '' ? 'is-error' : '' ?>">
                        <span>نام کاربری *</span>
                        <input type="text" name="manager_username" form="customer-create-form" value="<?= $e($old['manager_username'] ?? '') ?>" maxlength="60" class="o-ltr" placeholder="manager_x">
                        <?php if ($error('manager_username')): ?><small class="o-error-text"><?= $e($error('manager_username')) ?></small><?php endif; ?>
                    </label>
                    <label class="o-field <?= $error('manager_password') !== '' ? 'is-error' : '' ?>">
                        <span>رمز عبور *</span>
                        <input type="text" name="manager_password" form="customer-create-form" value="<?= $e($old['manager_password'] ?? '') ?>" maxlength="64" class="o-ltr">
                        <small>حداقل ۱۰ کاراکتر با حرف بزرگ، کوچک، رقم و نماد. کاربر در اولین ورود باید تغییر دهد.</small>
                        <?php if ($error('manager_password')): ?><small class="o-error-text"><?= $e($error('manager_password')) ?></small><?php endif; ?>
                    </label>
                </div>
            </section>

            <section class="o-card">
                <div class="o-card-head"><h2>امکانات اولیه</h2></div>
                <p class="o-small o-muted">
                    هنگام ساخت مشتری، امکانات پیش‌فرض سامانه (پرسنل، حضور و غیاب، گزارش‌ها) فعال و بقیه غیرفعال می‌شوند.
                    پس از ساخت می‌توانید از صفحه «امکانات» هر مورد را تغییر دهید. این تنظیم در بک‌اند اعمال می‌شود، نه فقط در ظاهر.
                </p>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php
// NOTE: in create mode the second column lives outside the <form> element
// (visual two column layout), so those inputs are attached to it through the
// HTML5 form="customer-create-form" attribute. No JS required.
?>
