<?php
/**
 * Owner - employees overview across all customers (read-only aggregate).
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $listing
 * @var array<string,mixed> $overview
 * @var array<int,array<string,mixed>> $customers
 * @var callable $e
 * @var callable $url
 */
$rows = $listing['rows'];
$max  = 1;

foreach ($overview['customers'] as $row) {
    $max = max($max, (int) $row['employee_count']);
}
?>
<div class="o-page-head">
    <div>
        <h1>نمای کلی پرسنل</h1>
        <p>تعداد پرسنل هر مشتری و فهرست پرسنل ثبت‌شده در کل سامانه. مدیریت جزئیات پرسنل در پنل مدیر مشتری انجام می‌شود.</p>
    </div>
</div>

<div class="o-kpis">
    <div class="o-kpi"><span class="o-kpi-label">کل پرسنل</span><span class="o-kpi-value"><?= $e($overview['status']['total']) ?></span></div>
    <div class="o-kpi is-ok"><span class="o-kpi-label">فعال</span><span class="o-kpi-value"><?= $e($overview['status']['active']) ?></span></div>
    <div class="o-kpi is-warn"><span class="o-kpi-label">معلق</span><span class="o-kpi-value"><?= $e($overview['status']['suspended']) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">خاتمه‌یافته</span><span class="o-kpi-value"><?= $e($overview['status']['terminated']) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">ثبت چهره‌شده</span><span class="o-kpi-value"><?= $e($overview['status']['face_enrolled']) ?></span>
        <span class="o-kpi-extra"><span>ثبت چهره در مرحله ۳ ساخته می‌شود</span></span>
    </div>
</div>

<section class="o-card">
    <div class="o-card-head"><h2>توزیع پرسنل بین مشتریان</h2></div>

    <?php if ($overview['customers'] === []): ?>
        <div class="o-empty"><strong>مشتری‌ای ثبت نشده است</strong></div>
    <?php else: ?>
        <div class="o-bars">
            <?php foreach (array_slice($overview['customers'], 0, 12) as $row): ?>
                <div class="o-bar-row">
                    <span class="o-bar-label"><?= $e(mb_substr((string) $row['name'], 0, 18, 'UTF-8')) ?></span>
                    <span class="o-bar-track">
                        <span class="o-bar-fill" style="width: <?= $e((int) round(((int) $row['employee_count'] / $max) * 100)) ?>%"></span>
                    </span>
                    <span class="o-bar-value"><?= $e($row['employee_count']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="o-card">
    <form method="get" action="<?= $e($url('/owner/employees')) ?>" class="o-filters">
        <label class="o-field">
            <span>جستجو</span>
            <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="نام، نام خانوادگی، کد پرسنلی، موبایل، کد ملی">
        </label>

        <label class="o-field">
            <span>مشتری</span>
            <select name="customer_id" data-autosubmit>
                <option value="0">همه مشتریان</option>
                <?php foreach ($customers as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= (int) $filters['customer_id'] === (int) $option['id'] ? 'selected' : '' ?>><?= $e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="o-field">
            <span>وضعیت</span>
            <select name="status" data-autosubmit>
                <option value="">همه</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="suspended" <?= $filters['status'] === 'suspended' ? 'selected' : '' ?>>معلق</option>
                <option value="terminated" <?= $filters['status'] === 'terminated' ? 'selected' : '' ?>>خاتمه‌یافته</option>
            </select>
        </label>

        <div class="o-field">
            <span>&nbsp;</span>
            <button type="submit" class="o-btn o-btn-primary">اعمال فیلتر</button>
        </div>
    </form>
</section>

<section class="o-card">
    <div class="o-card-head"><h2>فهرست پرسنل<span class="o-sub"><?= $e($listing['total']) ?> نفر</span></h2></div>

    <?php if ($rows === []): ?>
        <div class="o-empty"><strong>پرسنلی با این فیلترها یافت نشد</strong>پرسنل توسط مدیر مشتری ثبت می‌شوند.</div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>نام و نام خانوادگی</th>
                    <th>کد پرسنلی</th>
                    <th>مشتری</th>
                    <th>شعبه</th>
                    <th>سمت</th>
                    <th>وضعیت</th>
                    <th>ثبت چهره</th>
                    <th>تاریخ ثبت</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $employee): ?>
                    <tr>
                        <td><?= $e(\App\Models\Employee::fullName($employee)) ?><small><?= $e($employee['mobile'] ?? '') ?></small></td>
                        <td class="o-ltr o-small"><?= $e($employee['employee_code'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($employee['customer_uuid'])): ?>
                                <a href="<?= $e($url('/owner/customers/' . $employee['customer_uuid'])) ?>"><?= $e($employee['customer_name'] ?? '—') ?></a>
                            <?php else: ?>
                                <?= $e($employee['customer_name'] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($employee['branch_name'] ?? '—') ?></td>
                        <td><?= $e($employee['position'] ?? '—') ?></td>
                        <td><span class="o-badge <?= $employee['status'] === 'active' ? 'o-badge-ok' : 'o-badge-warn' ?>"><?= $e($employee['status']) ?></span></td>
                        <td>
                            <span class="o-badge <?= !empty($employee['face_enrolled']) ? 'o-badge-ok' : 'o-badge-muted' ?>">
                                <?= !empty($employee['face_enrolled']) ? 'ثبت شده' : 'ثبت نشده' ?>
                            </span>
                        </td>
                        <td class="o-small"><?= $e($employee['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::render('partials/pagination', [
            'listing' => $listing,
            'query'   => [
                '_path'       => '/owner/employees',
                'q'           => $filters['q'],
                'customer_id' => (int) $filters['customer_id'],
                'status'      => $filters['status'],
            ],
        ]) ?>
    <?php endif; ?>
</section>
