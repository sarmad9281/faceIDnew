<?php
/**
 * Owner - subscription edit page for one customer.
 *
 * @var array<string,mixed> $customer
 * @var array<string,mixed>|null $subscription
 * @var array<int,array<string,mixed>> $history
 * @var array<string,string> $plans
 * @var array<string,string> $statuses
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$uuid = (string) $customer['uuid'];
?>
<div class="o-page-head">
    <div>
        <h1>اشتراک مشتری: <?= $e($customer['name']) ?></h1>
        <p>
            پلن، وضعیت و بازه اعتبار اشتراک. وضعیت «منقضی» خودکار محاسبه می‌شود: هر مشتری که تاریخ پایانش گذشته باشد
            حتی اگر وضعیت «فعال» ذخیره شده باشد، منقضی گزارش می‌شود.
        </p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/subscriptions')) ?>">فهرست اشتراک‌ها</a>
        <a class="o-btn" href="<?= $e($url('/owner/customers/' . $uuid)) ?>">صفحه مشتری</a>
    </div>
</div>

<div class="o-kpis">
    <div class="o-kpi"><span class="o-kpi-label">پلن فعلی</span><span class="o-kpi-value" style="font-size:20px"><?= $e($plans[$subscription['plan_name'] ?? 'trial'] ?? (string) ($subscription['plan_name'] ?? '—')) ?></span></div>
    <div class="o-kpi <?= $status === 'active' ? 'is-ok' : 'is-warn' ?>"><span class="o-kpi-label">وضعیت</span><span class="o-kpi-value" style="font-size:20px"><?= $e($statuses[$status] ?? $status) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">روز باقی‌مانده</span><span class="o-kpi-value"><?= $e($daysLeft === null ? '∞' : $daysLeft) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">پایان اعتبار</span><span class="o-kpi-value" style="font-size:18px"><?= $e($subscription['expire_date'] ?? ($customer['subscription_expire_date'] ?? '—')) ?></span></div>
</div>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head"><h2>ویرایش اشتراک</h2></div>

        <form method="post" action="<?= $e($url('/owner/subscriptions/' . $uuid)) ?>">
            <?= $csrf() ?>

            <div class="o-form-grid">
                <label class="o-field">
                    <span>پلن</span>
                    <select name="plan_name">
                        <?php foreach ($plans as $key => $title): ?>
                            <option value="<?= $e($key) ?>" <?= (string) ($subscription['plan_name'] ?? $customer['plan_name'] ?? 'trial') === (string) $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                        <?php endforeach; ?>
                        <?php if (($subscription['plan_name'] ?? '') !== '' && !isset($plans[$subscription['plan_name']])): ?>
                            <option value="<?= $e($subscription['plan_name']) ?>" selected><?= $e($subscription['plan_name']) ?> (سفارشی)</option>
                        <?php endif; ?>
                    </select>
                </label>

                <label class="o-field">
                    <span>وضعیت</span>
                    <select name="status">
                        <?php foreach ($statuses as $key => $title): ?>
                            <option value="<?= $e($key) ?>" <?= (string) ($subscription['status'] ?? 'trial') === (string) $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="o-field">
                    <span>تاریخ شروع</span>
                    <input type="date" name="start_date" class="o-ltr"
                           value="<?= $e((string) ($subscription['start_date'] ?? $customer['subscription_start_date'] ?? date('Y-m-d'))) ?>">
                </label>

                <label class="o-field">
                    <span>تاریخ پایان</span>
                    <input type="date" name="expire_date" class="o-ltr"
                           value="<?= $e((string) ($subscription['expire_date'] ?? $customer['subscription_expire_date'] ?? '')) ?>">
                </label>

                <label class="o-field">
                    <span>مبلغ (ریال — اختیاری)</span>
                    <input type="text" name="price" class="o-ltr" value="<?= $e((string) ($subscription['price'] ?? '')) ?>" placeholder="0">
                    <small>پرداخت آنلاین در این مرحله وجود ندارد؛ این فیلد فقط برای ثبت توافقات است.</small>
                </label>

                <label class="o-field">
                    <span>سقف پرسنل</span>
                    <input type="number" name="max_employees" min="1" value="<?= $e((string) ($subscription['max_employees'] ?? $customer['max_employees'] ?? 50)) ?>">
                </label>

                <label class="o-field">
                    <span>سقف دستگاه</span>
                    <input type="number" name="max_devices" min="1" value="<?= $e((string) ($subscription['max_devices'] ?? $customer['max_devices'] ?? 5)) ?>">
                </label>
            </div>

            <label class="o-field">
                <span>یادداشت</span>
                <input type="text" name="notes" maxlength="255" value="<?= $e((string) ($subscription['notes'] ?? '')) ?>">
            </label>

            <button type="submit" class="o-btn o-btn-primary">ذخیره اشتراک</button>
        </form>
    </section>

    <section class="o-card">
        <div class="o-card-head"><h2>تاریخچه تغییرات اشتراک</h2></div>

        <?php if ($history === []): ?>
            <div class="o-empty"><strong>تاریخچه‌ای ثبت نشده است</strong>اولین تغییر اشتراک اینجا ثبت می‌شود.</div>
        <?php else: ?>
            <div class="o-table-wrap">
                <table class="o-table" style="min-width:560px">
                    <thead><tr><th>زمان</th><th>پلن</th><th>وضعیت</th><th>بازه</th><th>توسط</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td class="o-small o-nowrap"><?= $e($row['created_at']) ?></td>
                            <td><?= $e($row['plan_name']) ?></td>
                            <td><span class="o-badge"><?= $e($statuses[(string) $row['status']] ?? $row['status']) ?></span></td>
                            <td class="o-ltr o-small"><?= $e($row['start_date'] ?? '—') ?> → <?= $e($row['expire_date'] ?? '—') ?></td>
                            <td class="o-small"><?= $e($row['actor_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
