<?php
/**
 * Manager - advanced analysis (feature advanced_reports).
 *
 * @var array{date_from:string,date_to:string} $filters
 * @var array<int,array<string,mixed>> $trend
 * @var array<int,array<string,mixed>> $employees
 * @var array<int,array<string,mixed>> $branches
 * @var bool $branchesEnabled
 * @var array<string,mixed> $summary
 * @var bool $excelEnabled
 * @var callable $e
 * @var callable $url
 */

use App\Models\AttendanceRecord;
use App\Support\Jalali;

$max = static function (array $rows, string $key): int {
    $values = array_map(static fn (array $row): int => (int) ($row[$key] ?? 0), $rows);

    return $values === [] ? 0 : max(1, max($values));
};

$bar = static function (int $value, int $max) use ($e): string {
    $width = $max <= 0 ? 0 : max(2, (int) round($value / $max * 100));

    return '<span class="num small">' . $e(number_format($value)) . '</span>'
        . '<div style="height:6px;border-radius:4px;background:rgba(148,163,184,.25);margin-top:4px">'
        . '<div style="height:6px;border-radius:4px;width:' . $width . '%;background:linear-gradient(90deg,#38bdf8,#6366f1)"></div></div>';
};
?>
<div class="page-head">
    <div>
        <h1>گزارش‌های تحلیلی</h1>
        <p class="muted small">
            روند ماهانه، مقایسه پرسنل و مقایسه شعب روی همان رکوردهای واقعی بازه انتخاب‌شده.
            این صفحه فقط با فعال بودن قابلیت «گزارش‌های پیشرفته» برای مجموعه در دسترس است.
        </p>
    </div>
    <div class="row-actions">
        <a class="btn btn-ghost" href="<?= $e($url('/manager/reports')) ?>">گزارش‌های پایه</a>
        <?php if ($excelEnabled): ?>
            <a class="btn btn-primary" href="<?= $e($url('/manager/reports/export?type=monthly&date_from=' . urlencode($filters['date_from']) . '&date_to=' . urlencode($filters['date_to']))) ?>">دانلود اکسل خلاصه پرسنل</a>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <form method="get" action="<?= $e($url('/manager/reports/advanced')) ?>">
        <div class="filters">
            <label class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($filters['date_from']) ?>"></label>
            <label class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($filters['date_to']) ?>"></label>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">تحلیل کن</button></div>
        </div>
    </form>
</section>

<section class="kpi-grid">
    <article class="kpi"><div class="kpi-top"><span class="kpi-label">پرسنل در بازه</span></div><div class="kpi-value num"><?= $e(number_format((int) $summary['employees'])) ?></div></article>
    <article class="kpi"><div class="kpi-top"><span class="kpi-label">مجموع کارکرد</span></div><div class="kpi-value num"><?= $e(AttendanceRecord::minutesLabel((int) $summary['work_minutes'])) ?></div></article>
    <article class="kpi"><div class="kpi-top"><span class="kpi-label">مجموع تأخیر</span></div><div class="kpi-value num"><?= $e(AttendanceRecord::minutesLabel((int) $summary['late_minutes'])) ?></div></article>
    <article class="kpi"><div class="kpi-top"><span class="kpi-label">مجموع اضافه‌کاری</span></div><div class="kpi-value num"><?= $e(AttendanceRecord::minutesLabel((int) $summary['overtime_minutes'])) ?></div></article>
</section>

<section class="grid-2">
    <article class="card">
        <div class="card-head"><h2>روند ماهانه حضور</h2></div>
        <?php if ($trend === []): ?>
            <div class="empty"><div class="empty-art">📈</div><strong>داده‌ای در این بازه نیست</strong></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>ماه</th><th>کارکرد</th><th>تأخیر</th><th>اضافه‌کاری</th></tr></thead>
                    <tbody>
                    <?php foreach ($trend as $row): ?>
                        <tr>
                            <td class="ltr num"><?= $e((string) $row['month']) ?></td>
                            <td><?= $bar((int) $row['work_minutes'], $max($trend, 'work_minutes')) ?></td>
                            <td><?= $bar((int) $row['late_minutes'], $max($trend, 'late_minutes')) ?></td>
                            <td><?= $bar((int) $row['overtime_minutes'], $max($trend, 'overtime_minutes')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted small">میانگین کارکرد روزانه هر ماه = مجموع کارکرد ÷ تعداد رکوردهای دارای کارکرد.</p>
        <?php endif; ?>
    </article>

    <article class="card">
        <div class="card-head"><h2>بیشترین اضافه‌کاری و تأخیر</h2></div>
        <?php if ($employees === []): ?>
            <div class="empty"><div class="empty-art">🏅</div><strong>داده‌ای در این بازه نیست</strong></div>
        <?php else: ?>
            <ol class="list">
                <?php foreach (array_slice($employees, 0, 5) as $row): ?>
                    <li class="list-row">
                        <span>
                            <?= $e(trim((string) $row['first_name'] . ' ' . (string) $row['last_name'])) ?>
                            <span class="muted small">— اضافه‌کاری <?= $e(AttendanceRecord::minutesLabel((int) $row['overtime_minutes'])) ?>، تأخیر <?= $e(AttendanceRecord::minutesLabel((int) $row['late_minutes'])) ?></span>
                        </span>
                        <span class="badge"><?= $e(number_format((int) $row['work_minutes'])) ?> دقیقه کارکرد</span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </article>
</section>

<?php if ($branchesEnabled): ?>
    <section class="card">
        <div class="card-head"><h2>مقایسه شعب</h2></div>
        <?php if ($branches === []): ?>
            <div class="empty"><div class="empty-art">🏢</div><strong>برای این بازه رکورد شعبه‌ای ثبت نشده</strong></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>شعبه</th><th>پرسنل</th><th>تردد</th><th>میانگین کارکرد</th><th>میانگین تأخیر</th><th>اضافه‌کاری</th><th>غیبت</th></tr></thead>
                    <tbody>
                    <?php foreach ($branches as $row): ?>
                        <tr>
                            <td><?= $e((string) $row['branch_name']) ?></td>
                            <td class="num"><?= $e((string) (int) $row['employees']) ?></td>
                            <td class="num"><?= $e(number_format((int) $row['records'])) ?></td>
                            <td class="num"><?= $e(AttendanceRecord::minutesLabel((int) $row['avg_work_minutes'])) ?></td>
                            <td class="num"><?= $e(AttendanceRecord::minutesLabel((int) $row['avg_late_minutes'])) ?></td>
                            <td class="num"><?= $e(AttendanceRecord::minutesLabel((int) $row['overtime_minutes'])) ?></td>
                            <td class="num"><?= $e((string) (int) $row['absence_days']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="card">
        <div class="card-head"><h2>مقایسه شعب</h2></div>
        <p class="muted small">قابلیت «شعب» برای این مجموعه فعال نیست؛ مقایسه شعب نمایش داده نمی‌شود و در بک‌اند هم محاسبه نمی‌شود.</p>
    </section>
<?php endif; ?>
