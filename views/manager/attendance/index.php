<?php
/**
 * Manager - attendance list (stage 6, real data from the attendance engine).
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,totals:array<string,int>} $listing
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $employees
 * @var array<int,array<string,mixed>> $branches
 * @var array<int,array<string,mixed>> $shifts
 * @var array<string,string> $statuses
 * @var array<string,string> $origins
 * @var array<string,string> $flags
 * @var array<int,array<string,mixed>> $inside
 * @var array<string,int|float> $summary
 * @var bool $canManage
 * @var string $serverTime
 * @var string $jToday
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\Jalali;

$badgeFor = static function (string $status): string {
    switch ($status) {
        case 'present': return 'badge-ok';
        case 'late':
        case 'early_leave':
        case 'incomplete': return 'badge-warn';
        case 'absent': return 'badge-error';
        case 'leave':
        case 'on_leave': return 'badge-info';
        case 'holiday': return 'badge-muted';
        case 'overtime': return 'badge-ok';
        case 'manual': return 'badge-info';
        default: return 'badge-muted';
    }
};

$sourceLabel = static function (string $source) use ($origins): string {
    return $origins[$source] ?? $source;
};

$flagList = static function ($flagsRaw) use ($flags): array {
    $out = [];

    foreach (array_filter(explode(',', (string) $flagsRaw)) as $flag) {
        $out[] = $flags[$flag] ?? $flag;
    }

    return $out;
};
?>
<div class="page-head">
    <div>
        <h1>حضور و غیاب</h1>
        <p class="muted small">
            داده‌های واقعی موتور حضور و غیاب. امروز: <?= $e($jToday) ?>
            — ساعت سرور: <span class="ltr num"><?= $e($serverTime) ?></span>
        </p>
    </div>
    <span class="m-chip" id="inside-chip">الان در مجموعه: <?= $e($summary['currently_inside'] ?? 0) ?> نفر</span>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= $e($error) ?></div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= $e($success) ?></div>
<?php endif; ?>

<section class="kpi-grid">
    <article class="kpi kpi-ok">
        <div class="kpi-top"><span class="kpi-label">حاضر امروز</span></div>
        <div class="kpi-value num"><?= $e($summary['employees'] ?? 0) ?></div>
        <div class="kpi-foot">نفر-روز ثبت‌شده: <?= $e($summary['records'] ?? 0) ?></div>
    </article>
    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">الان در مجموعه</span></div>
        <div class="kpi-value num" id="kpi-inside"><?= $e($summary['currently_inside'] ?? 0) ?></div>
        <div class="kpi-foot">ورود بدون خروج</div>
    </article>
    <article class="kpi kpi-ok">
        <div class="kpi-top"><span class="kpi-label">تکمیل‌شده</span></div>
        <div class="kpi-value num"><?= $e($summary['completed'] ?? 0) ?></div>
        <div class="kpi-foot">کارکرد امروز: <?= $e(AttendanceRecord::minutesLabel($summary['work_minutes'] ?? 0)) ?></div>
    </article>
    <article class="kpi kpi-warn">
        <div class="kpi-top"><span class="kpi-label">تأخیر</span></div>
        <div class="kpi-value num"><?= $e($summary['late'] ?? 0) ?></div>
        <div class="kpi-foot">تعجیل در خروج: <?= $e($summary['early_leave'] ?? 0) ?></div>
    </article>
    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">اضافه‌کاری</span></div>
        <div class="kpi-value num"><?= $e($summary['overtime'] ?? 0) ?></div>
        <div class="kpi-foot">مجموع: <?= $e(AttendanceRecord::minutesLabel($summary['overtime_minutes'] ?? 0)) ?></div>
    </article>
    <article class="kpi kpi-error">
        <div class="kpi-top"><span class="kpi-label">غیبت</span></div>
        <div class="kpi-value num"><?= $e($summary['absent'] ?? 0) ?></div>
        <div class="kpi-foot">مرخصی امروز: <?= $e($summary['on_leave'] ?? 0) ?></div>
    </article>
</section>

<section class="card" id="inside-card">
    <div class="card-head">
        <h2>الان در مجموعه</h2>
        <span class="muted small">به‌روزرسانی خودکار هر ۳۰ ثانیه — بر پایه رکوردهای بدون خروج</span>
    </div>

    <div class="list" id="inside-list">
        <?php if ($inside === []): ?>
            <div class="empty" id="inside-empty">
                <div class="empty-art">🕒</div>
                <strong>در این لحظه کسی داخل مجموعه نیست</strong>
                به‌محض ثبت ورود، نام و مدت حضور در همین بخش نمایش داده می‌شود.
            </div>
        <?php else: ?>
            <?php foreach ($inside as $row): ?>
                <div class="list-row">
                    <div>
                        <strong><?= $e(trim(((string) $row['first_name']) . ' ' . ((string) $row['last_name']))) ?></strong>
                        <small class="ltr"><?= $e($row['employee_code'] ?? '') ?></small>
                    </div>
                    <div class="muted small">
                        ورود: <span class="ltr num"><?= $e(substr((string) $row['entry_at'], 11, 5)) ?></span>
                        — مدت حضور: <span class="num"><?= $e(AttendanceRecord::minutesLabel((int) $row['inside_minutes'], false)) ?></span>
                    </div>
                    <div class="row-actions">
                        <?php if ((string) $row['source'] === 'device_offline'): ?>
                            <span class="badge badge-warn">آفلاین</span>
                        <?php endif; ?>
                        <?php if ((int) $row['late_minutes'] > 0): ?>
                            <span class="badge badge-warn">تأخیر <?= $e($row['late_minutes']) ?>′</span>
                        <?php endif; ?>
                        <span class="badge <?= $badgeFor((string) $row['status']) ?>"><?= $e(AttendanceRecord::statusLabel((string) $row['status'])) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <form method="get" action="<?= $e($url('/manager/attendance')) ?>">
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
                <span>شیفت</span>
                <select name="shift_id">
                    <option value="0">همه شیفت‌ها</option>
                    <?php foreach ($shifts as $shift): ?>
                        <option value="<?= $e($shift['id']) ?>" <?= (int) $filters['shift_id'] === (int) $shift['id'] ? 'selected' : '' ?>><?= $e($shift['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>وضعیت</span>
                <select name="status">
                    <option value="">همه</option>
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>منبع ثبت</span>
                <select name="origin">
                    <option value="">همه</option>
                    <option value="online" <?= $filters['origin'] === 'online' ? 'selected' : '' ?>>دستگاه — آنلاین</option>
                    <option value="offline" <?= $filters['origin'] === 'offline' ? 'selected' : '' ?>>دستگاه — آفلاین (هم‌گام‌شده)</option>
                    <option value="manual" <?= $filters['origin'] === 'manual' ? 'selected' : '' ?>>اصلاح دستی / ورود گروهی</option>
                </select>
            </label>
            <label class="field">
                <span>برچسب</span>
                <select name="flag">
                    <option value="">همه</option>
                    <?php foreach ($flags as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= $filters['flag'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>جست‌وجوی نام / کد</span>
                <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="مثلاً احمدی">
            </label>
            <div class="field">
                <span>فیلترهای سریع</span>
                <div class="checkline">
                    <label class="check"><input type="checkbox" name="late" value="1" <?= !empty($filters['late']) ? 'checked' : '' ?>> فقط تأخیر</label>
                    <label class="check"><input type="checkbox" name="early" value="1" <?= !empty($filters['early']) ? 'checked' : '' ?>> فقط تعجیل</label>
                    <label class="check"><input type="checkbox" name="overtime" value="1" <?= !empty($filters['overtime']) ? 'checked' : '' ?>> فقط اضافه‌کاری</label>
                </div>
            </div>
            <div class="field">
                <button type="submit" class="btn btn-primary btn-block">اعمال فیلتر</button>
            </div>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>ترددها</h2>
        <span class="muted small">
            <?= $e($listing['total']) ?> ردیف — مجموع کارکرد <?= $e(AttendanceRecord::minutesLabel($listing['totals']['work_minutes'] ?? 0)) ?>
            — تأخیر <?= $e($listing['totals']['late'] ?? 0) ?> — غیبت <?= $e($listing['totals']['absent'] ?? 0) ?>
        </span>
    </div>

    <?php if ($listing['rows'] === []): ?>
        <div class="empty">
            <div class="empty-art">🗓️</div>
            <strong>ترددی برای این فیلتر ثبت نشده است</strong>
            جدول رکوردها خالی است؛ ورود و خروج از دستگاه ثبت می‌شود و همین‌جا نمایش داده می‌شود.
            هیچ داده نمونه‌ای ساخته نمی‌شود.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>پرسنل</th><th>تاریخ</th><th>ورود</th><th>خروج</th><th>کارکرد</th>
                    <th>تأخیر</th><th>تعجیل خروج</th><th>اضافه‌کاری</th><th>وضعیت</th><th>منبع</th><th>شیفت</th>
                    <?php if ($canManage): ?><th>عملیات</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($listing['rows'] as $row): ?>
                    <?php $rowFlags = $flagList($row['flags'] ?? ''); ?>
                    <tr>
                        <td>
                            <?= $e(trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')))) ?>
                            <small class="ltr"><?= $e($row['employee_code'] ?? '') ?></small>
                        </td>
                        <td><?= $e(Jalali::date($row['attendance_date'] ?? '')) ?></td>
                        <td class="ltr num"><?= $e(substr((string) ($row['entry_at'] ?? ''), 11, 5) ?: '—') ?></td>
                        <td class="ltr num"><?= $e(substr((string) ($row['exit_at'] ?? ''), 11, 5) ?: '—') ?></td>
                        <td class="num"><?= $e(AttendanceRecord::minutesLabel($row['work_minutes'] ?? 0)) ?></td>
                        <td class="num"><?= $e(AttendanceRecord::minutesLabel($row['late_minutes'] ?? 0)) ?></td>
                        <td class="num"><?= $e(AttendanceRecord::minutesLabel($row['early_leave_minutes'] ?? 0)) ?></td>
                        <td class="num"><?= $e(AttendanceRecord::minutesLabel($row['overtime_minutes'] ?? 0)) ?></td>
                        <td>
                            <span class="badge <?= $badgeFor((string) ($row['status'] ?? '')) ?>"><?= $e(AttendanceRecord::statusLabel((string) ($row['status'] ?? ''))) ?></span>
                            <?php if (!empty($row['open_key'])): ?>
                                <small>در مجموعه</small>
                            <?php endif; ?>
                            <?php if ((int) ($row['session_index'] ?? 1) > 1): ?>
                                <small>نوبت <?= $e($row['session_index']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $e($sourceLabel((string) ($row['source'] ?? ''))) ?>
                            <?php if ($rowFlags !== []): ?>
                                <small><?= $e(implode('، ', $rowFlags)) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($row['shift_name'] ?? '—') ?></td>
                        <?php if ($canManage): ?>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" data-edit-record
                                            data-record-id="<?= $e($row['id']) ?>"
                                            data-employee-id="<?= $e($row['employee_id']) ?>"
                                            data-date="<?= $e($row['attendance_date']) ?>"
                                            data-entry="<?= $e(substr((string) ($row['entry_at'] ?? ''), 11, 5)) ?>"
                                            data-exit="<?= $e(substr((string) ($row['exit_at'] ?? ''), 11, 5)) ?>">اصلاح</button>
                                    <details>
                                        <summary class="btn btn-danger btn-sm">ابطال</summary>
                                        <form method="post" action="<?= $e($url('/manager/attendance/void')) ?>" style="margin-top:8px">
                                            <?= $csrf() ?>
                                            <input type="hidden" name="record_id" value="<?= $e($row['id']) ?>">
                                            <label class="field"><span>دلیل ابطال (الزامی)</span>
                                                <input type="text" name="reason" required maxlength="200">
                                            </label>
                                            <button type="submit" class="btn btn-danger btn-sm btn-block">ثبت ابطال</button>
                                        </form>
                                    </details>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $query = array_merge($filters, ['_path' => '/manager/attendance']);
        require __DIR__ . '/../../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
    <div class="grid-2">
        <section class="card" id="manual-card">
            <div class="card-head"><h2>افزودن / اصلاح دستی</h2></div>
            <p class="muted small">
                هر تغییر با نام کاربر، زمان و دلیل ثبت و در گزارش تغییرات باقی می‌ماند.
                برای اصلاح یک رکورد موجود، «اصلاح» را در سطر آن رکورد بزنید (یا شناسه رکورد را وارد کنید).
            </p>
            <form method="post" action="<?= $e($url('/manager/attendance/manual')) ?>">
                <?= $csrf() ?>
                <input type="hidden" name="record_id" id="manual-record-id" value="0">
                <div class="field">
                    <span>پرسنل</span>
                    <select name="employee_id" id="manual-employee" required>
                        <option value="">— انتخاب پرسنل —</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= $e($employee['id']) ?>"><?= $e(Employee::optionLabel($employee)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><span>تاریخ</span><input type="date" name="attendance_date" id="manual-date" class="ltr" value="<?= $e(date('Y-m-d')) ?>" required></div>
                <div class="field"><span>ساعت ورود</span><input type="time" name="entry_at" id="manual-entry" class="ltr" required></div>
                <div class="field"><span>ساعت خروج (خالی = ورود باز)</span><input type="time" name="exit_at" id="manual-exit" class="ltr"></div>
                <div class="field"><span>دلیل (الزامی)</span><input type="text" name="reason" required maxlength="200" placeholder="مثلاً: خرابی دستگاه در نوبت صبح"></div>
                <button type="submit" class="btn btn-primary btn-block">ثبت</button>
            </form>
        </section>

        <section class="card">
            <div class="card-head"><h2>بازمحاسبه و بستن روز</h2></div>
            <p class="muted small">
                بازمحاسبه، قواعد شیفت را دوباره روی بازه انتخابی اجرا می‌کند (مثلاً پس از تغییر شیفت).
                بستن روز، غیبت و مرخصی روزهای تمام‌شده را محاسبه و ثبت می‌کند.
            </p>
            <form method="post" action="<?= $e($url('/manager/attendance/recalculate')) ?>">
                <?= $csrf() ?>
                <div class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($filters['date_from']) ?>" required></div>
                <div class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($filters['date_to']) ?>" required></div>
                <label class="check"><input type="checkbox" name="use_current_shift" value="1"> با شیفت فعلی پرسنل (نه شیفت زمان ثبت)</label>
                <button type="submit" class="btn btn-block" style="margin-top:10px">بازمحاسبه بازه</button>
            </form>
            <hr>
            <form method="post" action="<?= $e($url('/manager/attendance/close-day')) ?>">
                <?= $csrf() ?>
                <div class="field"><span>بستن روز (محاسبه غیبت)</span><input type="date" name="date" class="ltr" value="<?= $e(date('Y-m-d', strtotime('-1 day'))) ?>" required></div>
                <button type="submit" class="btn btn-block">بستن روز</button>
            </form>
        </section>
    </div>
<?php endif; ?>

<script>
    (function () {
        'use strict';

        // ---- «الان در مجموعه» auto refresh (same tenant, session guarded) ----
        var list = document.getElementById('inside-list');
        var chip = document.getElementById('inside-chip');
        var kpi = document.getElementById('kpi-inside');
        var branch = <?= (int) $filters['branch_id'] ?>;

        function label(minutes) {
            minutes = parseInt(minutes, 10) || 0;
            var h = Math.floor(minutes / 60);
            var m = minutes % 60;
            return h + ':' + (m < 10 ? '0' + m : m);
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function refresh() {
            if (!list || typeof window.fetch !== 'function') {
                return;
            }

            fetch('<?= $e($url('/manager/attendance/live')) ?>?branch_id=' + branch, {
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (payload) {
                if (!payload || !payload.success) {
                    return;
                }

                var inside = payload.data.inside || [];
                var count = payload.meta.count || 0;

                if (chip) { chip.textContent = 'الان در مجموعه: ' + count + ' نفر'; }
                if (kpi) { kpi.textContent = count; }

                if (!inside.length) {
                    list.innerHTML = '<div class="empty"><div class="empty-art">🕒</div>'
                        + '<strong>در این لحظه کسی داخل مجموعه نیست</strong>'
                        + 'به‌محض ثبت ورود، نام و مدت حضور در همین بخش نمایش داده می‌شود.</div>';
                    return;
                }

                list.innerHTML = inside.map(function (row) {
                    return '<div class="list-row"><div><strong>' + escapeHtml(row.name) + '</strong>'
                        + '<small class="ltr"></small></div>'
                        + '<div class="muted small">ورود: <span class="ltr num">' + escapeHtml(row.entry_time) + '</span>'
                        + ' — مدت حضور: <span class="num">' + escapeHtml(label(row.inside_minutes)) + '</span></div>'
                        + '<div class="row-actions">'
                        + (row.is_offline ? '<span class="badge badge-warn">آفلاین</span>' : '')
                        + '</div></div>';
                }).join('');
            }).catch(function () { /* offline: keep the server rendered list */ });
        }

        if (list) {
            window.setInterval(refresh, 30000);
        }

        // ---- «اصلاح» fills the manual form with the row it belongs to ----
        var recordId = document.getElementById('manual-record-id');
        var employee = document.getElementById('manual-employee');
        var date = document.getElementById('manual-date');
        var entry = document.getElementById('manual-entry');
        var exit = document.getElementById('manual-exit');
        var card = document.getElementById('manual-card');

        Array.prototype.forEach.call(document.querySelectorAll('[data-edit-record]'), function (button) {
            button.addEventListener('click', function () {
                if (!card) { return; }

                recordId.value = button.getAttribute('data-record-id') || '0';
                employee.value = button.getAttribute('data-employee-id') || '';
                date.value = button.getAttribute('data-date') || '';
                entry.value = button.getAttribute('data-entry') || '';
                exit.value = button.getAttribute('data-exit') || '';

                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                entry.focus();
            });
        });
    }());
</script>
