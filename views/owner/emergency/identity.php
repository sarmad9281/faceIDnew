<?php
/**
 * Owner - manager identity of a customer (username only, never a secret).
 *
 * @var array<string,mixed> $customer
 * @var array<string,mixed>|null $manager
 * @var array<string,mixed>|null $user
 * @var callable $e
 * @var callable $url
 */
$uuid = (string) $customer['uuid'];
?>
<div class="o-page-head">
    <div>
        <h1>اطلاعات ورود مدیر</h1>
        <p>مشتری: <strong><?= $e($customer['name']) ?></strong> — فقط اطلاعات هویتی؛ رمز عبور قابل مشاهده نیست.</p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/customers/' . $uuid)) ?>">بازگشت به مشتری</a>
    </div>
</div>

<div class="o-alert o-alert-info">
    رمز عبور مدیر به صورت هش یک‌طرفه ذخیره می‌شود و هیچ‌کس (حتی مالک سامانه) نمی‌تواند آن را ببیند.
    برای دسترسی جدید، از «بازنشانی رمز عبور» استفاده کنید.
</div>

<section class="o-card">
    <div class="o-card-head"><h2>مشخصات مدیر</h2></div>

    <?php if ($manager === null): ?>
        <div class="o-empty"><strong>مدیری برای این مشتری ثبت نشده است</strong>برای دسترسی مدیر، ابتدا یک حساب مدیر بسازید.</div>
    <?php else: ?>
        <div class="o-kv">
            <div><span>نام مدیر</span><strong><?= $e($manager['name']) ?></strong></div>
            <div><span>موبایل</span><strong class="o-ltr"><?= $e($manager['mobile'] ?? '—') ?></strong></div>
            <div><span>ایمیل</span><strong class="o-ltr"><?= $e($manager['email'] ?? '—') ?></strong></div>
            <div><span>نام کاربری</span><strong class="o-ltr"><?= $e($user['username'] ?? '—') ?></strong></div>
            <div><span>وضعیت حساب</span><strong><?= !empty($user['is_active']) ? 'فعال' : 'غیرفعال' ?></strong></div>
            <div><span>آخرین ورود</span><strong><?= $e($user['last_login_at'] ?? '—') ?></strong></div>
        </div>

        <?php if ($manager['id'] !== null): ?>
            <div class="o-actions o-mt">
                <a class="o-btn" href="<?= $e($url('/owner/managers/' . $manager['id'] . '/edit')) ?>">مدیریت این مدیر</a>
                <a class="o-btn o-btn-warn" href="<?= $e($url('/owner/customers/' . $uuid . '/emergency')) ?>">ورود اضطراری</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
