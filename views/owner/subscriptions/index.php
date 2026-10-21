<?php
/**
 * Owner - subscriptions list.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $listing
 * @var array<string,int> $counts
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$rows = $listing['rows'];
?>
<div class="o-page-head">
    <div>
        <h1>مدیریت اشتراک‌ها</h1>
        <p>وضعیت اشتراک هر مشتری، پلن، تاریخ شروع و پایان و روزهای باقی‌مانده. هر تغییر در تاریخچه اشتراک و گزارش رویدادها ثبت می‌شود.</p>
    </div>
    <div class="o-actions">
        <form method="post" action="<?= $e($url('/owner/subscriptions/expire-overdue')) ?>" class="o-inline-form"
              data-confirm="اشتراک‌های گذشته از تاریخ پایان به وضعیت «منقضی» تغییر کنند؟">
            <?= $csrf() ?>
            <button type="submit" class="o-btn o-btn-warn">به‌روزرسانی اشتراک‌های منقضی</button>
        </form>
    </div>
</div>

<div class="o-kpis">
    <div class="o-kpi"><span class="o-kpi-label">کل مشتریان</span><span class="o-kpi-value"><?= $e($counts['total']) ?></span></div>
    <div class="o-kpi is-ok"><span class="o-kpi-label">اشتراک فعال</span><span class="o-kpi-value"><?= $e($counts['active']) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">آزمایشی</span><span class="o-kpi-value"><?= $e($counts['trial']) ?></span></div>
    <div class="o-kpi is-warn"><span class="o-kpi-label">در آستانه انقضا (۷ روز)</span><span class="o-kpi-value"><?= $e($counts['expiring_7']) ?></span></div>
    <div class="o-kpi is-warn"><span class="o-kpi-label">منقضی</span><span class="o-kpi-value"><?= $e($counts['expired']) ?></span></div>
    <div class="o-kpi is-warn"><span class="o-kpi-label">معلق</span><span class="o-kpi-value"><?= $e($counts['suspended']) ?></span></div>
</div>

<section class="o-card">
    <form method="get" action="<?= $e($url('/owner/subscriptions')) ?>" class="o-filters">
        <label class="o-field">
            <span>جستجو</span>
            <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="نام مشتری، شرکت، ایمیل">
        </label>

        <label class="o-field">
            <span>وضعیت اشتراک</span>
            <select name="subscription" data-autosubmit>
                <option value="">همه</option>
                <?php foreach ($statuses as $key => $title): ?>
                    <option value="<?= $e($key) ?>" <?= $filters['subscription'] === $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                <?php endforeach; ?>
                <option value="expiring" <?= $filters['subscription'] === 'expiring' ? 'selected' : '' ?>>در آستانه انقضا</option>
            </select>
        </label>

        <label class="o-field">
            <span>پلن</span>
            <select name="plan" data-autosubmit>
                <option value="">همه</option>
                <?php foreach ($plans as $key => $title): ?>
                    <option value="<?= $e($key) ?>" <?= $filters['plan'] === $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="o-field">
            <span>&nbsp;</span>
            <button type="submit" class="o-btn o-btn-primary">اعمال فیلتر</button>
        </div>
    </form>
</section>

<section class="o-card">
    <div class="o-card-head"><h2>فهرست اشتراک‌ها<span class="o-sub"><?= $e($listing['total']) ?> مشتری</span></h2></div>

    <?php if ($rows === []): ?>
        <div class="o-empty"><strong>مشتری‌ای با این فیلترها یافت نشد</strong></div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>مشتری</th>
                    <th>پلن</th>
                    <th>وضعیت</th>
                    <th>شروع</th>
                    <th>پایان</th>
                    <th>روز باقی‌مانده</th>
                    <th>سقف پرسنل / دستگاه</th>
                    <th>عملیات</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $status = (string) $row['effective_subscription_status'];
                    $badge  = in_array($status, ['active'], true) ? 'o-badge-ok'
                        : (in_array($status, ['trial'], true) ? 'o-badge-info'
                        : (in_array($status, ['expired'], true) ? 'o-badge-bad' : 'o-badge-warn'));
                    ?>
                    <tr>
                        <td><a href="<?= $e($url('/owner/subscriptions/' . $row['uuid'])) ?>"><?= $e($row['name']) ?></a>
                            <small><?= $e($row['contact_email'] ?? '') ?></small>
                        </td>
                        <td><?= $e($plans[$row['plan_name'] ?? 'trial'] ?? (string) ($row['plan_name'] ?? '—')) ?></td>
                        <td><span class="o-badge <?= $badge ?>"><?= $e(\App\Models\Subscription::statusTitles()[$status] ?? $status) ?></span></td>
                        <td class="o-ltr o-small"><?= $e($row['subscription_start_date'] ?? '—') ?></td>
                        <td class="o-ltr o-small"><?= $e($row['subscription_expire_date'] ?? '—') ?></td>
                        <td>
                            <?php if ($row['days_remaining'] === null): ?>
                                <span class="o-badge">بدون پایان</span>
                            <?php elseif ($row['days_remaining'] < 0): ?>
                                <span class="o-badge o-badge-bad"><?= $e(abs((int) $row['days_remaining'])) ?> روز گذشته</span>
                            <?php else: ?>
                                <span class="o-badge <?= $row['days_remaining'] <= 7 ? 'o-badge-warn' : 'o-badge-ok' ?>"><?= $e($row['days_remaining']) ?> روز</span>
                            <?php endif; ?>
                        </td>
                        <td class="o-small"><?= $e($row['employee_count']) ?> / <?= $e($row['max_employees']) ?> — <?= $e($row['device_count']) ?> / <?= $e($row['max_devices']) ?></td>
                        <td>
                            <div class="o-actions">
                                <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/subscriptions/' . $row['uuid'])) ?>">تغییر اشتراک</a>
                                <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers/' . $row['uuid'])) ?>">جزئیات مشتری</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::render('partials/pagination', [
            'listing' => $listing,
            'query'   => [
                '_path'        => '/owner/subscriptions',
                'q'            => $filters['q'],
                'subscription' => $filters['subscription'],
                'plan'         => $filters['plan'],
                'status'       => $filters['status'],
            ],
        ]) ?>
    <?php endif; ?>
</section>
