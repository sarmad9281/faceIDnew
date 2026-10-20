<?php
/**
 * Manager - activity log of the active customer only.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $listing
 * @var array<string,mixed> $filters
 * @var array<string,string> $channels
 * @var array<string,int> $summary
 * @var array<string,mixed>|null $customer
 * @var callable $e
 * @var callable $url
 */

use App\Support\Jalali;

$summaryLabels = ['success' => 'موفق (۷ روز)', 'failure' => 'ناموفق (۷ روز)', 'denied' => 'رد شده (۷ روز)', 'error' => 'خطا (۷ روز)'];
$badge = static function (string $result): string {
    switch ($result) {
        case 'success': return 'badge-ok';
        case 'denied': return 'badge-warn';
        case 'failure':
        case 'error': return 'badge-error';
        default: return 'badge-muted';
    }
};
?>
<div class="page-head">
    <div>
        <h1>رویدادهای کسب‌وکار</h1>
        <p class="muted small">
            فقط رویدادهای «<?= $e($customer['name'] ?? '') ?>» نمایش داده می‌شود؛ داده سایر مشتریان و رویدادهای سطح سامانه در این صفحه دیده نمی‌شود.
        </p>
    </div>
</div>

<section class="kpi-grid">
    <?php foreach ($summary as $key => $value): ?>
        <article class="kpi">
            <div class="kpi-top"><span class="kpi-label"><?= $e($summaryLabels[$key] ?? $key) ?></span></div>
            <div class="kpi-value num"><?= $e($value) ?></div>
        </article>
    <?php endforeach; ?>
</section>

<section class="card">
    <form method="get" action="<?= $e($url('/manager/activity')) ?>">
        <div class="filters">
            <label class="field"><span>جستجو</span><input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="مسیر، IP یا نوع رکورد"></label>
            <label class="field"><span>کاربر یا شرح رویداد</span><input type="text" name="actor" value="<?= $e($filters['actor']) ?>" placeholder="نام کاربر یا بخشی از شرح"></label>
            <label class="field"><span>کد رویداد</span><input type="text" name="action" class="ltr" value="<?= $e($filters['action']) ?>" placeholder="CREATE_EMPLOYEE"></label>
            <label class="field">
                <span>کانال</span>
                <select name="channel" data-autosubmit>
                    <option value="">همه</option>
                    <?php foreach ($channels as $channel): ?>
                        <option value="<?= $e($channel) ?>" <?= $filters['channel'] === $channel ? 'selected' : '' ?>><?= $e($channel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>نتیجه</span>
                <select name="result" data-autosubmit>
                    <option value="">همه</option>
                    <option value="success" <?= $filters['result'] === 'success' ? 'selected' : '' ?>>موفق</option>
                    <option value="failure" <?= $filters['result'] === 'failure' ? 'selected' : '' ?>>ناموفق</option>
                    <option value="denied" <?= $filters['result'] === 'denied' ? 'selected' : '' ?>>رد شده</option>
                    <option value="error" <?= $filters['result'] === 'error' ? 'selected' : '' ?>>خطا</option>
                </select>
            </label>
            <label class="field"><span>از تاریخ</span><input type="date" name="from" class="ltr" value="<?= $e($filters['from']) ?>"></label>
            <label class="field"><span>تا تاریخ</span><input type="date" name="to" class="ltr" value="<?= $e($filters['to']) ?>"></label>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">اعمال فیلتر</button></div>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>رویدادها</h2>
        <span class="muted small"><?= $e($listing['total']) ?> رویداد</span>
    </div>

    <?php if ($listing['rows'] === []): ?>
        <div class="empty"><strong>رویدادی یافت نشد</strong>با انجام عملیات در پنل، رویدادها اینجا ثبت می‌شوند.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>زمان</th><th>اقدام</th><th>شرح</th><th>کاربر</th><th>نتیجه</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($listing['rows'] as $row): ?>
                    <tr>
                        <td class="small num"><?= $e(Jalali::date($row['created_at'] ?? '', true)) ?></td>
                        <td class="ltr small"><?= $e($row['action'] ?? $row['event'] ?? '') ?></td>
                        <td><?= $e($row['description'] ?? '') ?></td>
                        <td class="small"><?= $e($row['actor_label'] ?? '—') ?></td>
                        <td><span class="badge <?= $badge((string) ($row['result'] ?? '')) ?>"><?= $e($row['result'] ?? '') ?></span></td>
                        <td class="ltr small"><?= $e($row['ip'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $query = array_merge($filters, ['_path' => '/manager/activity']);
        unset($query['customer_id']);
        require __DIR__ . '/../../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
