<?php
/**
 * Manager - reports (stage 7): twelve report types on real data + real .xlsx.
 *
 * @var array<string,string> $types
 * @var array<string,mixed> $filters
 * @var array{type:string,title:string,columns:array<int,array{key:string,label:string,format?:string}>,rows:array<int,array<string,mixed>>,summary:array<int,array{label:string,value:mixed}>,note:string} $data
 * @var array<string,mixed>|null $employeeCard
 * @var array<int,array<string,mixed>> $employees
 * @var array<int,array<string,mixed>> $branches
 * @var array<int,array<string,mixed>> $shifts
 * @var bool $branchesEnabled
 * @var bool $payrollEnabled
 * @var bool $advancedEnabled
 * @var bool $excelEnabled
 * @var callable $e
 * @var callable $url
 */

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\Jalali;

$isDate = static fn (string $key): bool => in_array($key, ['date', 'from', 'to', 'start_date', 'end_date'], true);
$isLong = static fn (string $key): bool => in_array($key, ['entry_time', 'exit_time', 'shift_start', 'shift_end', 'entry_at'], true);
$isNum  = static fn (array $column): bool => isset($column['format']);

$format = static function (array $column, $value) use ($e): string {
    $format = (string) ($column['format'] ?? '');

    if ($format === 'minutes') {
        return (string) AttendanceRecord::minutesLabel((int) $value);
    }

    if ($format === 'money') {
        return number_format((float) $value, 0);
    }

    if ($format === 'int') {
        return (string) (int) $value;
    }

    return (string) $value;
};

$query = static fn (array $overrides): string => $url('/manager/reports?' . http_build_query(array_filter(
    array_merge($filters, $overrides),
    static fn ($value): bool => $value !== '' && $value !== null && $value !== 0 && $value !== false
)));

$exportQuery = static fn (): string => $url('/manager/reports/export?' . http_build_query(array_filter(
    [
        'type'        => $filters['type'],
        'date_from'   => $filters['date_from'],
        'date_to'     => $filters['date_to'],
        'employee_id' => $filters['employee_id'],
        'branch_id'   => $filters['branch_id'],
        'shift_id'    => $filters['shift_id'],
        'status'      => $filters['status'],
        'origin'      => $filters['origin'],
    ],
    static fn ($value): bool => $value !== '' && $value !== null && $value !== 0
)));
?>
<div class="page-head">
    <div>
        <h1>گزارش‌ها</h1>
        <p class="muted small">
            همه اعداد این صفحه از رکوردهای واقعی حضور و غیاب و مرخصی‌های همین مجموعه ساخته می‌شود؛
            همان کوئری‌ها خروجی اکسل را هم می‌سازند تا عدد صفحه و فایل یکی باشد.
        </p>
    </div>
    <div class="row-actions">
        <?php if ($advancedEnabled): ?>
            <a class="btn btn-ghost" href="<?= $e($url('/manager/reports/advanced')) ?>">گزارش‌های تحلیلی</a>
        <?php endif; ?>
        <?php if ($excelEnabled): ?>
            <a class="btn btn-primary" href="<?= $e($exportQuery()) ?>">دانلود اکسل (.xlsx)</a>
        <?php else: ?>
            <span class="badge badge-muted">خروجی اکسل غیرفعال است</span>
        <?php endif; ?>
    </div>
</div>

<div class="tabs">
    <?php foreach ($types as $key => $label): ?>
        <a class="tab <?= $filters['type'] === $key ? 'is-active' : '' ?>" href="<?= $e($query(['type' => $key, 'page' => 0])) ?>"><?= $e($label) ?></a>
    <?php endforeach; ?>
</div>

<section class="card">
    <form method="get" action="<?= $e($url('/manager/reports')) ?>">
        <input type="hidden" name="type" value="<?= $e($filters['type']) ?>">
        <div class="filters">
            <label class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($filters['date_from']) ?>"></label>
            <label class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($filters['date_to']) ?>"></label>
            <label class="field">
                <span>پرسنل</span>
                <select name="employee_id">
                    <option value="0">همه پرسنل</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= $e($employee['id']) ?>" <?= (int) $filters['employee_id'] === (int) $employee['id'] ? 'selected' : '' ?>><?= $e(Employee::optionLabel($employee)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($branchesEnabled): ?>
                <label class="field">
                    <span>شعبه</span>
                    <select name="branch_id">
                        <option value="0">همه شعب</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $e($branch['id']) ?>" <?= (int) $filters['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>><?= $e($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="field">
                <span>وضعیت</span>
                <select name="status">
                    <option value="">همه</option>
                    <?php foreach (AttendanceRecord::STATUSES as $status => $label): ?>
                        <option value="<?= $e($status) ?>" <?= (string) $filters['status'] === $status ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>منبع</span>
                <select name="origin">
                    <option value="">همه</option>
                    <option value="online" <?= $filters['origin'] === 'online' ? 'selected' : '' ?>>آنلاین دستگاه</option>
                    <option value="offline" <?= $filters['origin'] === 'offline' ? 'selected' : '' ?>>آفلاین (هم‌گام‌شده)</option>
                    <option value="manual" <?= $filters['origin'] === 'manual' ? 'selected' : '' ?>>دستی</option>
                </select>
            </label>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">نمایش گزارش</button></div>
        </div>
    </form>
</section>

<section class="kpi-grid">
    <?php foreach ($data['summary'] as $card): ?>
        <article class="kpi">
            <div class="kpi-top"><span class="kpi-label"><?= $e($card['label']) ?></span></div>
            <?php
            $cardValue = $card['value'];
            $raw = is_numeric($cardValue) ? (float) $cardValue : 0.0;
            $minutesLike = in_array((string) $card['label'], array_filter(array_map(
                static fn (array $c): string => (string) $c['label'],
                $data['summary']
            ), static fn (string $l): bool => str_contains($l, 'دقیقه') || str_contains($l, 'کارکرد') || str_contains($l, 'اضافه')), true);
            ?>
            <div class="kpi-value num"><?= $e($minutesLike && is_numeric($cardValue) ? AttendanceRecord::minutesLabel((int) $raw) : (is_numeric($cardValue) ? number_format($raw) : (string) $cardValue)) ?></div>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($employeeCard !== null): ?>
    <section class="card">
        <div class="card-head">
            <h2>کارنامه <?= $e($employeeCard['name']) ?></h2>
            <span class="badge"><?= $e($employeeCard['code'] === '' ? 'بدون کد' : $employeeCard['code']) ?></span>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2><?= $e($data['title']) ?></h2>
        <div class="toolbar" style="margin:0">
            <span class="muted small"><?= $e(number_format(count($data['rows']))) ?> ردیف در این نمایش</span>
            <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">چاپ</button>
            <?php if ($excelEnabled): ?>
                <a class="btn btn-ghost btn-sm" href="<?= $e($exportQuery()) ?>">خروجی اکسل</a>
            <?php else: ?>
                <span class="badge badge-muted">خروجی اکسل غیرفعال است</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($data['note'] !== ''): ?>
        <p class="muted small"><?= $e($data['note']) ?></p>
    <?php endif; ?>

    <?php if ($data['rows'] === []): ?>
        <div class="empty">
            <div class="empty-art">📊</div>
            <strong>داده‌ای برای این گزارش وجود ندارد</strong>
            بازه تاریخ یا فیلترها را تغییر دهید؛ گزارش‌ها فقط از رکوردهای واقعی ساخته می‌شوند.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <?php foreach ($data['columns'] as $column): ?>
                        <th><?= $e($column['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($data['rows'] as $row): ?>
                    <tr>
                        <?php foreach ($data['columns'] as $column): ?>
                            <?php
                            $key   = (string) $column['key'];
                            $value = $row[$key] ?? '';
                            $text  = $format($column, $value);

                            if ($isDate($key) && (string) $value !== '') {
                                $text = Jalali::date(substr((string) $value, 0, 10));
                            } elseif ($isLong($key) && (string) $value !== '') {
                                $text = substr((string) $value, 11, 5) !== '' && strlen((string) $value) >= 16
                                    ? substr((string) $value, 0, 10) . ' ' . substr((string) $value, 11, 5)
                                    : (string) $value;
                            }
                            ?>
                            <td class="<?= $isNum($column) || $isDate($key) ? 'num' : '' ?>">
                                <?= $e($text) ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
