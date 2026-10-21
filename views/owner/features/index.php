<?php
/**
 * Owner - feature matrix for every customer.
 *
 * @var array<int,array<string,mixed>> $features
 * @var array<int,array{customer:array<string,mixed>,features:array<string,array<string,mixed>>}> $matrix
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
?>
<div class="o-page-head">
    <div>
        <h1>ماتریس امکانات مشتریان</h1>
        <p>نمای یک‌جا از امکانات فعال همه مشتریان. برای تغییر، روی «مدیریت» هر مشتری بزنید یا از دکمه‌های هر خانه استفاده کنید.</p>
    </div>
</div>

<div class="o-alert o-alert-info">
    قانون سامانه: فعال/غیرفعال بودن هر امکان در <strong>بک‌اند</strong> بررسی می‌شود. اگر امکانی برای مشتری غیرفعال باشد،
    دسترسی به صفحه یا API آن — حتی با آدرس‌دهی مستقیم — رد می‌شود و در گزارش رویدادها با نوع <span class="o-ltr">ACCESS_DENIED</span> ثبت می‌گردد.
</div>

<section class="o-card">
    <div class="o-card-head"><h2>فهرست امکانات سامانه</h2></div>
    <div class="o-chips">
        <?php foreach ($features as $feature): ?>
            <span class="o-chip">
                <?= $e($feature['title_fa']) ?>
                <b><?= $feature['category'] === 'basic' ? 'پایه' : 'اختیاری' ?></b>
                <b><?= !empty($feature['module_ready']) ? 'آماده' : 'مرحله بعد' ?></b>
            </span>
        <?php endforeach; ?>
    </div>
</section>

<section class="o-card">
    <div class="o-card-head"><h2>ماتریس مشتری × امکان<span class="o-sub"><?= $e(count($matrix)) ?> مشتری</span></h2></div>

    <?php if ($matrix === []): ?>
        <div class="o-empty"><strong>مشتری‌ای ثبت نشده است</strong></div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>مشتری</th>
                    <?php foreach ($features as $feature): ?>
                        <th><?= $e($feature['title_fa']) ?></th>
                    <?php endforeach; ?>
                    <th>عملیات</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matrix as $row): ?>
                    <tr>
                        <td>
                            <a href="<?= $e($url('/owner/customers/' . $row['customer']['uuid'])) ?>"><?= $e($row['customer']['name']) ?></a>
                            <small><?= $e($row['customer']['status'] === 'active' ? 'فعال' : 'غیرفعال') ?></small>
                        </td>
                        <?php foreach ($features as $feature): ?>
                            <?php $state = $row['features'][(string) $feature['feature_key']] ?? null; ?>
                            <td>
                                <?php if ($state === null): ?>
                                    <span class="o-badge">—</span>
                                <?php else: ?>
                                    <form method="post" action="<?= $e($url('/owner/features/' . $row['customer']['uuid'] . '/toggle')) ?>" class="o-inline-form">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="feature" value="<?= $e($feature['feature_key']) ?>">
                                        <input type="hidden" name="enabled" value="<?= $state['enabled'] ? '0' : '1' ?>">
                                        <button type="submit" class="o-badge <?= $state['enabled'] ? 'o-badge-ok' : 'o-badge-muted' ?>" style="cursor:pointer">
                                            <?= $state['enabled'] ? 'فعال' : 'غیرفعال' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td>
                            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/features/' . $row['customer']['uuid'])) ?>">مدیریت</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
