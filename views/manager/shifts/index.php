<?php
/**
 * Manager - shifts: definition + employee assignment.
 *
 * @var array<int,array<string,mixed>> $shifts
 * @var array<int,array<string,mixed>> $employees
 * @var array<int,array<string,mixed>> $shiftMap
 * @var array<int,array<string,mixed>> $unassigned
 * @var int $assignedCount
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Models\Employee;
use App\Models\Shift;
?>
<div class="page-head">
    <div>
        <h1>شیفت‌ها</h1>
        <p class="muted small">
            <?= $e(count($shifts)) ?> شیفت تعریف شده — <?= $e($assignedCount) ?> پرسنل شیفت دارند.
            ساختار برای شیفت ثابت، شیفت هر روز هفته و چند شیفت برای یک نفر آماده است.
        </p>
    </div>
</div>

<section class="card">
    <div class="card-head"><h2>افزودن شیفت</h2></div>
    <form method="post" action="<?= $e($url('/manager/shifts')) ?>">
        <?= $csrf() ?>
        <div class="grid-3">
            <label class="field"><span>نام شیفت *</span><input type="text" name="name" maxlength="120" required placeholder="مثلاً شیفت صبح"></label>
            <label class="field"><span>کد شیفت</span><input type="text" name="code" maxlength="40" class="ltr" placeholder="MORNING"></label>
            <label class="field"><span>ساعت شروع *</span><input type="time" name="start_time" required value="08:00" class="ltr"></label>
            <label class="field"><span>ساعت پایان *</span><input type="time" name="end_time" required value="16:00" class="ltr"></label>
            <label class="field"><span>مرخصی مجاز تأخیر (دقیقه)</span><input type="number" name="late_grace_minutes" min="0" max="600" value="10"></label>
            <label class="field"><span>مرخصی مجاز تعجیل خروج (دقیقه)</span><input type="number" name="early_leave_grace_minutes" min="0" max="600" value="10"></label>
            <label class="field"><span>استراحت (دقیقه، اختیاری)</span><input type="number" name="break_minutes" min="0" max="600" value="0"></label>
            <label class="field"><span>وضعیت</span>
                <select name="is_active">
                    <option value="1">فعال</option>
                    <option value="0">غیرفعال</option>
                </select>
            </label>
        </div>
        <label class="field"><span>یادداشت</span><input type="text" name="notes" maxlength="255"></label>
        <button type="submit" class="btn btn-primary">ایجاد شیفت</button>
        <span class="muted small">اگر ساعت پایان کوچک‌تر از شروع باشد، شیفت شبانه (عبور از نیمه‌شب) در نظر گرفته می‌شود.</span>
    </form>
</section>

<section class="card">
    <div class="card-head"><h2>شیفت‌های تعریف‌شده</h2></div>
    <?php if ($shifts === []): ?>
        <div class="empty"><strong>شیفتی تعریف نشده است</strong>با فرم بالا اولین شیفت را بسازید (نمونه: صبح ۰۸:۰۰ تا ۱۶:۰۰، شب ۱۶:۰۰ تا ۰۰:۰۰).</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>نام</th><th>ساعت</th><th>مدت کارکرد</th><th>مرخصی تأخیر</th><th>مرخصی تعجیل</th><th>استراحت</th><th>پرسنل</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($shifts as $shift): ?>
                    <tr>
                        <td>
                            <?= $e($shift['name']) ?>
                            <small class="ltr"><?= $e($shift['code'] ?? '') ?></small>
                        </td>
                        <td class="ltr"><?= $e(Shift::timeLabel($shift['start_time'])) ?> - <?= $e(Shift::timeLabel($shift['end_time'])) ?>
                            <?php if (!empty($shift['crosses_midnight'])): ?><small>شبانه</small><?php endif; ?>
                        </td>
                        <td><?= $e(Shift::hoursLabel(Shift::workMinutes($shift))) ?></td>
                        <td><?= $e($shift['late_grace_minutes']) ?> دقیقه</td>
                        <td><?= $e($shift['early_leave_grace_minutes']) ?> دقیقه</td>
                        <td><?= $e($shift['break_minutes']) ?> دقیقه</td>
                        <td class="num"><?= $e($shift['employee_count'] ?? 0) ?></td>
                        <td><span class="badge <?= !empty($shift['is_active']) ? 'badge-ok' : 'badge-muted' ?>"><?= !empty($shift['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                        <td class="actions">
                            <div class="row-actions">
                                <form method="post" action="<?= $e($url('/manager/shifts/update')) ?>" class="inline-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="shift_id" value="<?= $e($shift['id']) ?>">
                                    <input type="text" name="name" value="<?= $e($shift['name']) ?>" maxlength="120" style="width:130px" class="input">
                                    <input type="time" name="start_time" value="<?= $e(Shift::timeLabel($shift['start_time'])) ?>" class="input ltr" style="width:105px">
                                    <input type="time" name="end_time" value="<?= $e(Shift::timeLabel($shift['end_time'])) ?>" class="input ltr" style="width:105px">
                                    <input type="number" name="late_grace_minutes" value="<?= $e($shift['late_grace_minutes']) ?>" min="0" class="input" style="width:80px" title="مرخصی تأخیر">
                                    <input type="number" name="early_leave_grace_minutes" value="<?= $e($shift['early_leave_grace_minutes']) ?>" min="0" class="input" style="width:80px" title="مرخصی تعجیل">
                                    <input type="number" name="break_minutes" value="<?= $e($shift['break_minutes']) ?>" min="0" class="input" style="width:80px" title="استراحت">
                                    <input type="hidden" name="is_active" value="<?= !empty($shift['is_active']) ? '1' : '0' ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">ذخیره</button>
                                </form>
                                <form method="post" action="<?= $e($url('/manager/shifts/toggle')) ?>" class="inline-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="shift_id" value="<?= $e($shift['id']) ?>">
                                    <input type="hidden" name="active" value="<?= !empty($shift['is_active']) ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm"><?= !empty($shift['is_active']) ? 'غیرفعال' : 'فعال' ?></button>
                                </form>
                                <form method="post" action="<?= $e($url('/manager/shifts/delete')) ?>" class="inline-form" data-confirm="این شیفت حذف شود؟">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="shift_id" value="<?= $e($shift['id']) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2>اختصاص شیفت به پرسنل</h2>
        <span class="muted small"><?= $e(count($unassigned)) ?> پرسنل بدون شیفت</span>
    </div>

    <?php if ($employees === [] || $shifts === []): ?>
        <div class="empty"><strong>امکان اختصاص فراهم نیست</strong>برای اختصاص شیفت باید حداقل یک پرسنل و یک شیفت وجود داشته باشد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>پرسنل</th><th>شیفت فعلی</th><th>اختصاص شیفت جدید</th></tr></thead>
                <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php $current = $shiftMap[(int) $employee['id']] ?? null; ?>
                    <tr>
                        <td><?= $e(Employee::fullName($employee)) ?> <small class="ltr"><?= $e($employee['employee_code'] ?? '') ?></small></td>
                        <td>
                            <?php if ($current === null): ?>
                                <span class="badge badge-muted">بدون شیفت</span>
                            <?php else: ?>
                                <span class="badge badge-info"><?= $e($current['shift_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?= $e($url('/manager/shifts/assign')) ?>" class="inline-form">
                                <?= $csrf() ?>
                                <input type="hidden" name="employee_id" value="<?= $e($employee['id']) ?>">
                                <select name="shift_id" class="input" style="min-width:180px">
                                    <option value="0">— بدون شیفت —</option>
                                    <?php foreach ($shifts as $shift): ?>
                                        <?php if (empty($shift['is_active'])) { continue; } ?>
                                        <option value="<?= $e($shift['id']) ?>" <?= (int) ($current['shift_id'] ?? 0) === (int) $shift['id'] ? 'selected' : '' ?>>
                                            <?= $e($shift['name']) ?> (<?= $e(Shift::timeLabel($shift['start_time'])) ?>-<?= $e(Shift::timeLabel($shift['end_time'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm">ثبت</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
