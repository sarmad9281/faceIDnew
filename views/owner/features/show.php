<?php
/**
 * Owner - per customer feature control.
 *
 * @var array<string,mixed> $customer
 * @var array<int,array<string,mixed>> $features
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$uuid     = (string) $customer['uuid'];
$enabled  = array_values(array_filter($features, static fn (array $f): bool => (bool) $f['enabled']));
$basic    = array_values(array_filter($features, static fn (array $f): bool => $f['category'] === 'basic'));
$optional = array_values(array_filter($features, static fn (array $f): bool => $f['category'] === 'optional'));
?>
<div class="o-page-head">
    <div>
        <h1>امکانات مشتری: <?= $e($customer['name']) ?></h1>
        <p>
            هر تیک این صفحه در بک‌اند اعمال می‌شود: اگر امکانی غیرفعال باشد، مدیر مشتری حتی با وارد کردن دستی آدرس صفحه
            یا فراخوانی API هم به آن دسترسی ندارد.
        </p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/customers/' . $uuid)) ?>">صفحه مشتری</a>
        <a class="o-btn" href="<?= $e($url('/owner/features')) ?>">ماتریس همه مشتریان</a>
    </div>
</div>

<div class="o-kpis">
    <div class="o-kpi is-ok"><span class="o-kpi-label">امکانات فعال</span><span class="o-kpi-value"><?= $e(count($enabled)) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">کل امکانات</span><span class="o-kpi-value"><?= $e(count($features)) ?></span></div>
    <div class="o-kpi is-warn"><span class="o-kpi-label">غیرفعال</span><span class="o-kpi-value"><?= $e(count($features) - count($enabled)) ?></span></div>
</div>

<form method="post" action="<?= $e($url('/owner/features/' . $uuid)) ?>">
    <?= $csrf() ?>

    <section class="o-card">
        <div class="o-card-head">
            <h2>امکانات پایه<span class="o-sub">پیش‌فرض همه مشتریان</span></h2>
        </div>

        <div class="o-feature-grid">
            <?php foreach ($basic as $feature): ?>
                <div class="o-feature <?= $feature['enabled'] ? 'is-on' : 'is-off' ?>">
                    <div class="o-feature-head">
                        <label class="o-switch">
                            <input type="checkbox" name="features[<?= $e($feature['key']) ?>]" value="1" <?= $feature['enabled'] ? 'checked' : '' ?>>
                            <strong><?= $e($feature['title_fa']) ?></strong>
                        </label>
                        <span class="o-badge <?= $feature['enabled'] ? 'o-badge-ok' : 'o-badge-muted' ?>">
                            <?= $feature['enabled'] ? 'فعال' : 'غیرفعال' ?>
                        </span>
                    </div>
                    <div class="o-feature-desc"><?= $e($feature['description']) ?></div>
                    <div class="o-small o-muted">
                        <?php if (!$feature['module_ready']): ?>
                            <span class="o-badge o-badge-warn">ماژول: مرحله بعد</span>
                        <?php else: ?>
                            <span class="o-badge o-badge-ok">ماژول آماده</span>
                        <?php endif; ?>
                        <?php if ($feature['is_explicit']): ?>
                            <span class="o-badge o-badge-info">تنظیم اختصاصی</span>
                        <?php else: ?>
                            <span class="o-badge">مقدار پیش‌فرض</span>
                        <?php endif; ?>
                        <?php if (!empty($feature['updated_at'])): ?>
                            <small>آخرین تغییر: <?= $e($feature['updated_at']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="o-card">
        <div class="o-card-head">
            <h2>امکانات اختیاری<span class="o-sub">فقط در صورت نیاز مشتری فعال کنید</span></h2>
        </div>

        <div class="o-feature-grid">
            <?php foreach ($optional as $feature): ?>
                <div class="o-feature <?= $feature['enabled'] ? 'is-on' : 'is-off' ?>">
                    <div class="o-feature-head">
                        <label class="o-switch">
                            <input type="checkbox" name="features[<?= $e($feature['key']) ?>]" value="1" <?= $feature['enabled'] ? 'checked' : '' ?>>
                            <strong><?= $e($feature['title_fa']) ?></strong>
                        </label>
                        <span class="o-badge <?= $feature['enabled'] ? 'o-badge-ok' : 'o-badge-muted' ?>">
                            <?= $feature['enabled'] ? 'فعال' : 'غیرفعال' ?>
                        </span>
                    </div>
                    <div class="o-feature-desc"><?= $e($feature['description']) ?></div>
                    <div class="o-small o-muted">
                        <?php if (!$feature['module_ready']): ?>
                            <span class="o-badge o-badge-warn">ماژول: مرحله بعد</span>
                        <?php else: ?>
                            <span class="o-badge o-badge-ok">ماژول آماده</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="o-card">
        <button type="submit" class="o-btn o-btn-primary">ذخیره امکانات</button>
        <span class="o-small o-muted">تغییرات در گزارش رویدادها با نوع CHANGE_FEATURE ثبت می‌شود.</span>
    </div>
</form>

<section class="o-card">
    <div class="o-card-head"><h2>تغییر سریع یک امکان</h2></div>
    <div class="o-chips">
        <?php foreach ($features as $feature): ?>
            <form method="post" action="<?= $e($url('/owner/features/' . $uuid . '/toggle')) ?>" class="o-inline-form">
                <?= $csrf() ?>
                <input type="hidden" name="feature" value="<?= $e($feature['key']) ?>">
                <input type="hidden" name="enabled" value="<?= $feature['enabled'] ? '0' : '1' ?>">
                <button type="submit" class="o-chip <?= $feature['enabled'] ? 'is-active' : '' ?>" style="cursor:pointer">
                    <?= $e($feature['title_fa']) ?> — <?= $feature['enabled'] ? 'خاموش کن' : 'روشن کن' ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
</section>
