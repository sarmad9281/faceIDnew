<?php
/**
 * Manager - employee profile: identity, shift, branch, face enrolment and the
 * real attendance history of this person.
 *
 * @var array<string,mixed> $employee
 * @var array<string,mixed>|null $shift
 * @var array<int,array<string,mixed>|null> $faceSlots
 * @var int $faceCount
 * @var array<int,array<string,mixed>> $records
 * @var array<int,array<string,mixed>> $leaves
 * @var array<string,string> $statuses
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeFace;
use App\Models\Leave;
use App\Support\Jalali;

$id        = (int) $employee['id'];
$fullName  = Employee::fullName($employee);
$photo     = trim((string) ($employee['photo_path'] ?? ''));
$statusKey = (string) $employee['status'];
$enrolled  = $faceCount > 0;
$employment = [
    'full_time'  => 'تمام‌وقت',
    'part_time'  => 'پاره‌وقت',
    'contractor' => 'پیمانکاری',
    'intern'     => 'کارآموز',
];
?>
<div class="page-head">
    <div class="person">
        <span class="avatar avatar-lg">
            <?php if ($photo !== ''): ?>
                <img src="<?= $e(\App\Support\Url::upload($photo)) ?>" alt="<?= $e($fullName) ?>">
            <?php else: ?>
                <?= $e(mb_substr($fullName, 0, 1, 'UTF-8')) ?>
            <?php endif; ?>
        </span>
        <div>
            <h1><?= $e($fullName) ?></h1>
            <p class="muted small">
                <span class="badge <?= $statusKey === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= $e($statuses[$statusKey] ?? $statusKey) ?></span>
                <span class="badge badge-muted ltr"><?= $e($employee['employee_code'] ?? '—') ?></span>
                <?= $e($employee['position'] ?? '') !== '' ? ' — ' . $e($employee['position']) : '' ?>
            </p>
        </div>
    </div>
    <div class="row-actions">
        <a class="btn btn-ghost" href="<?= $e($url('/manager/employees')) ?>">فهرست پرسنل</a>
        <a class="btn btn-primary" href="<?= $e($url('/manager/employees/' . $id . '/edit')) ?>">ویرایش اطلاعات</a>
    </div>
</div>

<div class="split">
    <div>
        <section class="card">
            <div class="card-head"><h2>ثبت نمونه‌های چهره</h2></div>

            <p class="muted small">
                برای هر پرسنل دقیقاً سه نمونه چهره در نظر گرفته شده است. در این مرحله فقط تصاویر ذخیره می‌شوند؛
                <strong>موتور تشخیص چهره و تشخیص زنده‌بودن در این سامانه استفاده نمی‌شود</strong> و به مرحله بعد موکول است.
            </p>

            <div class="list-row" style="margin-bottom:14px">
                <span>وضعیت ثبت چهره</span>
                <?php if (!$enrolled): ?>
                    <span class="badge badge-warn">ثبت چهره انجام نشده</span>
                <?php elseif ($faceCount < 3): ?>
                    <span class="badge badge-info">ناقص — <?= $e($faceCount) ?> از ۳ نمونه</span>
                <?php else: ?>
                    <span class="badge badge-ok">کامل — ۳ نمونه ثبت شده</span>
                <?php endif; ?>
            </div>

            <div class="face-grid">
                <?php foreach ([1, 2, 3] as $index): ?>
                    <?php $slot = $faceSlots[$index] ?? null; ?>
                    <div class="face-slot <?= $slot !== null ? 'is-filled' : '' ?>">
                        <?php if ($slot !== null): ?>
                            <img class="face-thumb" src="<?= $e(\App\Support\Url::upload((string) $slot['file_path'])) ?>" alt="<?= $e(EmployeeFace::slotLabel($index)) ?>">
                            <span class="badge badge-ok"><?= $e(EmployeeFace::slotLabel($index)) ?></span>
                            <div class="face-meta">
                                <span><?= $e(Jalali::date($slot['captured_at'] ?? '', true)) ?></span>
                                <span class="ltr"><?= $e(number_format(((int) ($slot['file_size'] ?? 0)) / 1024, 1)) ?> KB</span>
                            </div>
                            <div class="row-actions">
                                <form method="post" action="<?= $e($url('/manager/employees/face/delete')) ?>" data-confirm="این نمونه چهره حذف شود؟">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="employee_id" value="<?= $e($id) ?>">
                                    <input type="hidden" name="sample_index" value="<?= $e($index) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                                </form>
                                <form method="post" action="<?= $e($url('/manager/employees/face')) ?>" enctype="multipart/form-data" class="inline-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="employee_id" value="<?= $e($id) ?>">
                                    <input type="hidden" name="sample_index" value="<?= $e($index) ?>">
                                    <input type="file" name="sample" accept="image/png,image/jpeg,image/webp" required style="max-width:150px">
                                    <button type="submit" class="btn btn-ghost btn-sm">جایگزینی</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="face-empty">ثبت نشده</div>
                            <strong><?= $e(EmployeeFace::slotLabel($index)) ?></strong>
                            <form method="post" action="<?= $e($url('/manager/employees/face')) ?>" enctype="multipart/form-data">
                                <?= $csrf() ?>
                                <input type="hidden" name="employee_id" value="<?= $e($id) ?>">
                                <input type="hidden" name="sample_index" value="<?= $e($index) ?>">
                                <input type="file" name="sample" accept="image/png,image/jpeg,image/webp" required>
                                <button type="submit" class="btn btn-primary btn-sm btn-block" style="margin-top:8px">ثبت نمونه <?= $e($index) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h2>سابقه تردد</h2>
                <a class="btn btn-ghost btn-sm" href="<?= $e($url('/manager/attendance?employee_id=' . $id)) ?>">فیلتر در حضور و غیاب</a>
            </div>

            <?php if ($records === []): ?>
                <div class="empty">
                    <strong>ترددی ثبت نشده است</strong>
                    موتور ثبت ورود و خروج (دستگاه و دوربین) در مرحله بعد فعال می‌شود؛ تا آن زمان این فهرست خالی است و داده ساختگی نمایش داده نمی‌شود.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>تاریخ</th><th>ورود</th><th>خروج</th><th>کارکرد</th><th>تأخیر</th><th>تعجیل</th><th>اضافه‌کاری</th><th>وضعیت</th></tr></thead>
                        <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= $e(Jalali::date($record['attendance_date'] ?? '')) ?></td>
                                <td class="ltr"><?= $e(substr((string) ($record['entry_at'] ?? ''), 11, 5) ?: '—') ?></td>
                                <td class="ltr"><?= $e(substr((string) ($record['exit_at'] ?? ''), 11, 5) ?: '—') ?></td>
                                <td><?= $e(AttendanceRecord::minutesLabel($record['work_minutes'] ?? 0)) ?></td>
                                <td><?= $e(AttendanceRecord::minutesLabel($record['late_minutes'] ?? 0)) ?></td>
                                <td><?= $e(AttendanceRecord::minutesLabel($record['early_leave_minutes'] ?? 0)) ?></td>
                                <td><?= $e(AttendanceRecord::minutesLabel($record['overtime_minutes'] ?? 0)) ?></td>
                                <td><span class="badge badge-muted"><?= $e(AttendanceRecord::statusLabel((string) ($record['status'] ?? ''))) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card-head"><h2>مشخصات</h2></div>
            <table class="mini-table">
                <tr><td>نام و نام خانوادگی</td><td><?= $e($fullName) ?></td></tr>
                <tr><td>کد پرسنلی</td><td class="ltr"><?= $e($employee['employee_code'] ?? '—') ?></td></tr>
                <tr><td>موبایل</td><td class="ltr"><?= $e($employee['mobile'] ?? '—') ?></td></tr>
                <tr><td>کد ملی</td><td class="ltr"><?= $e($employee['national_id'] ?? '—') ?></td></tr>
                <tr><td>ایمیل</td><td class="ltr"><?= $e($employee['email'] ?? '—') ?></td></tr>
                <tr><td>سمت</td><td><?= $e($employee['position'] ?? '—') ?></td></tr>
                <tr><td>واحد</td><td><?= $e($employee['department'] ?? '—') ?></td></tr>
                <tr><td>نوع همکاری</td><td><?= $e($employment[(string) ($employee['employment_type'] ?? '')] ?? '—') ?></td></tr>
                <tr><td>تاریخ استخدام</td><td><?= $e(Jalali::date($employee['hire_date'] ?? '')) ?></td></tr>
                <?php if ($branchesEnabled): ?>
                    <tr><td>شعبه</td><td><?= $e($employee['branch_name'] ?? '—') ?></td></tr>
                <?php endif; ?>
                <tr><td>یادداشت</td><td><?= $e($employee['notes'] ?? '—') ?></td></tr>
            </table>
        </section>

        <?php if ($shiftsEnabled): ?>
            <section class="card">
                <div class="card-head"><h2>شیفت</h2></div>
                <?php if ($shift === null): ?>
                    <p class="muted small">شیفتی به این پرسنل اختصاص نیافته است. <a href="<?= $e($url('/manager/shifts')) ?>">مدیریت شیفت‌ها</a></p>
                <?php else: ?>
                    <table class="mini-table">
                        <tr><td>نام شیفت</td><td><?= $e($shift['shift_name']) ?></td></tr>
                        <tr><td>ساعت کاری</td><td class="ltr"><?= $e(\App\Models\Shift::timeLabel($shift['start_time'])) ?> - <?= $e(\App\Models\Shift::timeLabel($shift['end_time'])) ?></td></tr>
                        <tr><td>مرخصی مجاز تأخیر</td><td><?= $e($shift['late_grace_minutes']) ?> دقیقه</td></tr>
                        <tr><td>مرخصی مجاز تعجیل</td><td><?= $e($shift['early_leave_grace_minutes']) ?> دقیقه</td></tr>
                        <tr><td>استراحت</td><td><?= $e($shift['break_minutes']) ?> دقیقه</td></tr>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-head"><h2>مرخصی‌های اخیر</h2></div>
            <?php if ($leaves === []): ?>
                <p class="muted small">مرخصی ثبت نشده است. <a href="<?= $e($url('/manager/leaves')) ?>">ثبت مرخصی</a></p>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($leaves as $leave): ?>
                        <div class="list-row">
                            <span><?= $e(Leave::typeLabel((string) $leave['leave_type'])) ?></span>
                            <span class="muted small"><?= $e(Jalali::date($leave['start_date'])) ?> تا <?= $e(Jalali::date($leave['end_date'])) ?></span>
                            <span class="badge <?= (string) $leave['status'] === 'approved' ? 'badge-ok' : 'badge-muted' ?>"><?= $e(Leave::statusLabel((string) $leave['status'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card <?= $statusKey === 'active' ? 'danger-zone' : '' ?>">
            <div class="card-head"><h2><?= $statusKey === 'active' ? 'غیرفعال‌سازی پرسنل' : 'فعال‌سازی پرسنل' ?></h2></div>
            <p class="muted small">
                پرسنل غیرفعال نمی‌تواند تردد جدید ثبت کند، اما همه سوابق و گزارش‌های قبلی او دست‌نخورده باقی می‌ماند.
            </p>
            <form method="post" action="<?= $e($url('/manager/employees/toggle')) ?>"
                  data-confirm="<?= $statusKey === 'active' ? 'این پرسنل غیرفعال شود؟' : 'این پرسنل فعال شود؟' ?>">
                <?= $csrf() ?>
                <input type="hidden" name="employee_id" value="<?= $e($id) ?>">
                <input type="hidden" name="active" value="<?= $statusKey === 'active' ? '0' : '1' ?>">
                <button type="submit" class="btn <?= $statusKey === 'active' ? 'btn-danger' : 'btn-primary' ?>">
                    <?= $statusKey === 'active' ? 'غیرفعال کردن' : 'فعال کردن' ?>
                </button>
            </form>
        </section>
    </div>
</div>
