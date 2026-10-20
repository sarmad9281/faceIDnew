<?php
/**
 * Manager - read only view of the feature flags of his own customer.
 *
 * @var array<string,array<string,mixed>> $features
 * @var array<string,mixed>|null $customer
 * @var callable $e
 * @var callable $url
 */
?>
<div class="page-head">
    <div>
        <h1>امکانات فعال</h1>
        <p class="muted small">
            وضعیت واقعی امکانات کسب‌وکار «<?= $e($customer['name'] ?? '') ?>». این صفحه فقط خواندنی است؛
            فعال یا غیرفعال کردن هر امکان در اختیار مالک سامانه است و در رویدادها ثبت می‌شود.
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= $e($url('/manager/settings')) ?>">بازگشت به تنظیمات</a>
</div>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>امکان</th><th>کلید</th><th>وضعیت</th><th>اثر روی پنل</th></tr></thead>
            <tbody>
            <?php foreach ($features as $key => $feature): ?>
                <tr>
                    <td><?= $e($feature['title'] ?? $key) ?></td>
                    <td class="ltr small"><?= $e($key) ?></td>
                    <td>
                        <span class="badge <?= !empty($feature['enabled']) ? 'badge-ok' : 'badge-muted' ?>">
                            <?= !empty($feature['enabled']) ? 'فعال' : 'غیرفعال' ?>
                        </span>
                    </td>
                    <td class="small">
                        <?= !empty($feature['enabled'])
                            ? 'منو و مسیرهای این بخش در دسترس است.'
                            : 'منو نمایش داده نمی‌شود و مسیرها در سرور با خطای ۴۰۳ رد می‌شوند.' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
