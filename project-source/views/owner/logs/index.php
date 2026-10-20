<?php
/**
 * Owner - audit log browser with real filters + pagination.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $listing
 * @var array<string,mixed> $filters
 * @var array<string,string> $actions
 * @var array<string,int> $counts
 * @var array<int,string> $channels
 * @var array<int,array<string,mixed>> $customers
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$rows = $listing['rows'];

$resultBadge = static function (string $result): string {
    switch ($result) {
        case 'success':
            return 'o-badge-ok';
        case 'denied':
            return 'o-badge-warn';
        case 'failure':
        case 'error':
            return 'o-badge-bad';
        default:
            return '';
    }
};
?>
<div class="o-page-head">
    <div>
        <h1>گزارش رویدادهای سامانه</h1>
        <p>ورود و خروج، ساخت و ویرایش مشتری و مدیر، تغییر امکانات و اشتراک، ثبت و ریست دستگاه، رد دسترسی و دسترسی اضطراری — همه با زمان، کاربر، IP و نتیجه.</p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/logs/export?' . http_build_query(array_filter([
            'action'      => $filters['action'],
            'actor'       => $filters['actor'],
            'result'      => $filters['result'],
            'customer_id' => (int) $filters['customer_id'],
            'from'        => $filters['from'],
            'to'          => $filters['to'],
            'q'           => $filters['q'],
        ])))) ?>">خروجی CSV (حداکثر ۲۰۰ رکورد)</a>
    </div>
</div>

<section class="o-card">
    <form method="get" action="<?= $e($url('/owner/logs')) ?>" class="o-filters">
        <label class="o-field">
            <span>نوع رویداد</span>
            <select name="action" data-autosubmit>
                <option value="">همه رویدادها</option>
                <?php foreach ($actions as $key => $title): ?>
                    <option value="<?= $e($key) ?>" <?= $filters['action'] === $key ? 'selected' : '' ?>>
                        <?= $e($title) ?><?= isset($counts[$key]) ? ' (' . $e($counts[$key]) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="o-field">
            <span>کاربر / توضیح</span>
            <input type="text" name="actor" value="<?= $e($filters['actor']) ?>" placeholder="نام کاربر یا بخشی از توضیح">
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
            <span>نتیجه</span>
            <select name="result" data-autosubmit>
                <option value="">همه</option>
                <option value="success" <?= $filters['result'] === 'success' ? 'selected' : '' ?>>موفق</option>
                <option value="denied" <?= $filters['result'] === 'denied' ? 'selected' : '' ?>>رد شده</option>
                <option value="failure" <?= $filters['result'] === 'failure' ? 'selected' : '' ?>>ناموفق</option>
                <option value="error" <?= $filters['result'] === 'error' ? 'selected' : '' ?>>خطا</option>
            </select>
        </label>

        <label class="o-field">
            <span>از تاریخ (میلادی)</span>
            <input type="date" name="from" value="<?= $e($filters['from']) ?>" class="o-ltr">
        </label>

        <label class="o-field">
            <span>تا تاریخ (میلادی)</span>
            <input type="date" name="to" value="<?= $e($filters['to']) ?>" class="o-ltr">
        </label>

        <label class="o-field">
            <span>مسیر / IP / موجودیت</span>
            <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="/owner/customers یا 1.2.3.4">
        </label>

        <div class="o-field">
            <span>&nbsp;</span>
            <button type="submit" class="o-btn o-btn-primary">اعمال فیلتر</button>
        </div>
    </form>
</section>

<section class="o-card">
    <div class="o-card-head">
        <h2>رویدادها<span class="o-sub"><?= $e($listing['total']) ?> رکورد با این فیلتر</span></h2>
        <div class="o-chips">
            <?php foreach (array_slice($actions, 0, 8, true) as $key => $title): ?>
                <a class="o-chip <?= $filters['action'] === $key ? 'is-active' : '' ?>"
                   href="<?= $e($url('/owner/logs?action=' . urlencode($key))) ?>"><?= $e($title) ?></a>
            <?php endforeach; ?>
            <a class="o-chip" href="<?= $e($url('/owner/logs')) ?>">حذف فیلتر</a>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="o-empty"><strong>رویدادی با این فیلتر یافت نشد</strong>بازه تاریخ یا نوع رویداد را تغییر دهید.</div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>زمان</th>
                    <th>رویداد</th>
                    <th>کاربر</th>
                    <th>مشتری</th>
                    <th>نتیجه</th>
                    <th>شرح</th>
                    <th>IP</th>
                    <th>مسیر</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $action = (string) ($row['action'] ?? \App\Services\AuditService::actionFor((string) $row['event'])); ?>
                    <tr>
                        <td class="o-small o-nowrap"><?= $e($row['created_at']) ?></td>
                        <td>
                            <span class="o-badge o-badge-info"><?= $e(\App\Services\AuditService::titleFor($action)) ?></span>
                            <small class="o-ltr"><?= $e($row['event']) ?></small>
                        </td>
                        <td>
                            <?= $e($row['actor_name'] ?? $row['actor_label'] ?? '—') ?>
                            <small><?= $e($row['actor_type'] ?? '') ?><?= !empty($row['role']) ? ' / ' . $e($row['role']) : '' ?></small>
                        </td>
                        <td><?= $e($row['customer_name'] ?? '—') ?></td>
                        <td><span class="o-badge <?= $resultBadge((string) $row['result']) ?>"><?= $e($row['result']) ?></span></td>
                        <td style="max-width:340px"><?= $e($row['description'] ?? '') ?></td>
                        <td class="o-ltr o-small"><?= $e($row['ip'] ?? '') ?></td>
                        <td class="o-ltr o-small"><?= $e(($row['request_method'] ?? '') . ' ' . ($row['request_path'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::render('partials/pagination', [
            'listing' => $listing,
            'query'   => [
                '_path'       => '/owner/logs',
                'action'      => $filters['action'],
                'actor'       => $filters['actor'],
                'result'      => $filters['result'],
                'channel'     => $filters['channel'],
                'customer_id' => (int) $filters['customer_id'],
                'from'        => $filters['from'],
                'to'          => $filters['to'],
                'q'           => $filters['q'],
            ],
        ]) ?>
    <?php endif; ?>
</section>
