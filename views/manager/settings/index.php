<?php
/**
 * Manager - own business profile. Owner level settings are NOT editable here.
 *
 * @var array<string,mixed>|null $customer
 * @var array<string,string> $settings
 * @var array<string,array<string,mixed>> $features
 * @var array<string,mixed>|null $plan
 * @var array<string,string> $money
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Services\SubscriptionService;
use App\Support\Url;

$logo = trim((string) ($customer['logo_path'] ?? ''));
?>
<div class="page-head">
    <div>
        <h1>تنظیمات کسب‌وکار</h1>
        <p class="muted small">
            نام، لوگو، اطلاعات تماس و آدرس این کسب‌وکار. تنظیمات سطح مالک (اشتراک، امکانات، ریست دستگاه) از این پنل قابل تغییر نیست.
        </p>
    </div>
</div>

<div class="split">
    <section class="card">
        <div class="card-head"><h2>اطلاعات کسب‌وکار</h2></div>

        <form method="post" action="<?= $e($url('/manager/settings/update')) ?>" enctype="multipart/form-data">
            <?= $csrf() ?>
            <div class="grid-2">
                <label class="field">
                    <span>نام کسب‌وکار *</span>
                    <input type="text" name="name" maxlength="150" required value="<?= $e($customer['name'] ?? '') ?>">
                </label>
                <label class="field">
                    <span>نام تجاری / برند</span>
                    <input type="text" name="company_name" maxlength="150" value="<?= $e($customer['company_name'] ?? '') ?>">
                </label>
                <label class="field">
                    <span>موبایل</span>
                    <input type="tel" name="contact_phone" maxlength="15" class="ltr" value="<?= $e($customer['contact_phone'] ?? '') ?>">
                </label>
                <label class="field">
                    <span>ایمیل</span>
                    <input type="email" name="contact_email" maxlength="120" class="ltr" value="<?= $e($customer['contact_email'] ?? '') ?>">
                </label>
            </div>

            <label class="field">
                <span>آدرس</span>
                <textarea name="address" maxlength="255"><?= $e($customer['address'] ?? '') ?></textarea>
            </label>

            <label class="field">
                <span>توضیحات کسب‌وکار</span>
                <textarea name="description" maxlength="500"><?= $e($settings['description'] ?? '') ?></textarea>
            </label>

            <div class="grid-3">
                <label class="field">
                    <span>ساعت شروع پیش‌فرض کار</span>
                    <input type="time" name="work_start_hint" class="ltr" value="<?= $e($settings['work_start_hint'] ?? '08:00') ?>">
                </label>
                <label class="field">
                    <span>ساعت پایان پیش‌فرض کار</span>
                    <input type="time" name="work_end_hint" class="ltr" value="<?= $e($settings['work_end_hint'] ?? '17:00') ?>">
                </label>
                <label class="field">
                    <span>لوگو</span>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                    <?php if ($logo !== ''): ?>
                        <span class="field-hint">لوگوی فعلی ثبت شده است؛ انتخاب فایل جدید آن را جایگزین می‌کند.</span>
                    <?php endif; ?>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">ذخیره اطلاعات</button>
        </form>
    </section>

    <div>
        <section class="card">
            <div class="card-head"><h2>لوگو</h2></div>
            <?php if ($logo !== ''): ?>
                <img src="<?= $e(Url::upload($logo)) ?>" alt="لوگو" style="max-width:180px;border-radius:14px;border:1px solid var(--m-line)">
            <?php else: ?>
                <p class="muted small">لوگویی ثبت نشده است.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head"><h2>امکانات فعال</h2></div>
            <div class="list">
                <?php foreach ($features as $key => $feature): ?>
                    <div class="list-row">
                        <span><?= $e($feature['title'] ?? $key) ?></span>
                        <span class="badge <?= !empty($feature['enabled']) ? 'badge-ok' : 'badge-muted' ?>"><?= !empty($feature['enabled']) ? 'فعال' : 'غیرفعال' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted small" style="margin-top:10px">
                فعال یا غیرفعال کردن امکانات فقط توسط مالک سامانه انجام می‌شود.
                <a href="<?= $e($url('/manager/settings/features')) ?>">جزئیات</a>
            </p>
        </section>

        <section class="card">
            <div class="card-head"><h2>اشتراک</h2></div>
            <?php if ($plan === null): ?>
                <p class="muted small">اشتراکی ثبت نشده است.</p>
            <?php else: ?>
                <table class="mini-table">
                    <tr><td>پلن</td><td><?= $e($plan['plan_name'] ?? '—') ?></td></tr>
                    <tr><td>وضعیت</td><td><?= $e(\App\Models\Subscription::effectiveStatus($plan) ?? '—') ?></td></tr>
                    <tr><td>پایان دوره</td><td><?= $e($plan['ends_at'] ?? '—') ?></td></tr>
                    <tr><td>سقف پرسنل</td><td><?= $e($plan['max_employees'] ?? '—') ?></td></tr>
                    <tr><td>سقف دستگاه</td><td><?= $e($plan['max_devices'] ?? '—') ?></td></tr>
                </table>
                <p class="muted small" style="margin-top:10px">تغییر اشتراک فقط توسط مالک سامانه انجام می‌شود.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head"><h2>امنیت</h2></div>
            <p class="muted small">برای تغییر رمز عبور از صفحه امنیتی استفاده کنید؛ رمز فعلی و رمز جدید پرسیده می‌شود.</p>
            <a class="btn btn-ghost" href="<?= $e($url('/account/password')) ?>">تغییر رمز عبور</a>
        </section>
    </div>
</div>
