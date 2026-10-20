<?php
/**
 * Manager dashboard - the real numbers of ONE customer.
 *
 * @var array<string,mixed>|null $customer
 * @var array<string,int> $cards
 * @var array<string,int> $todayKpis
 * @var array<int,array<string,mixed>> $branchRows
 * @var array<int,array<string,mixed>> $devices
 * @var array<int,array<string,mixed>> $shifts
 * @var array<int,array<string,mixed>> $upcomingHolidays
 * @var array<int,array<string,mixed>> $missingToday
 * @var array<int,array<string,mixed>> $recentAudit
 * @var array<string,int> $faceCounts
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Shift;
use App\Support\Jalali;

$clock = static fn (string $time): string => $time === '' ? '—' : substr($time, 0, 5);
?>
<div class="page-head">
    <div>
        <h1>داشبورد مدیریت</h1>
        <p class="muted small">
            امروز <?= $e(Jalali::longDate($today)) ?> — همه اعداد این صفحه از دیتابیس همین کسب‌وکار خوانده می‌شوند.
        </p>
    </div>
    <div class="row-actions">
        <a class="btn btn-ghost" href="<?= $e($url('/manager/attendance')) ?>">حضور و غیاب</a>
        <a class="btn btn-primary" href="<?= $e($url('/manager/employees/create')) ?>">افزودن پرسنل</a>
    </div>
</div>

<section class="kpi-grid">
    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">پرسنل</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 9a7 7 0 0 0-14 0Z"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($cards['employees']) ?></div>
        <div class="kpi-foot"><?= $e($cards['employees_active']) ?> پرسنل فعال</div>
    </article>

    <article class="kpi kpi-ok">
        <div class="kpi-top"><span class="kpi-label">حاضر امروز</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-1 12.4-3.5-3.5 1.4-1.4L11 12.6l4.6-4.6L17 9.4Z"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($cards['present']) ?></div>
        <div class="kpi-foot">ورود ثبت‌شده: <?= $e($cards['entries']) ?> — خروج: <?= $e($cards['exits']) ?></div>
    </article>

    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">الان در مجموعه</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 8v4l3 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($cards['inside']) ?></div>
        <div class="kpi-foot">ورود بدون خروج</div>
    </article>

    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">تکمیل‌شده</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 12.5 9.5 17 19 7.5"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($cards['completed']) ?></div>
        <div class="kpi-foot">مجموع کارکرد امروز: <?= $e(AttendanceRecord::minutesLabel($cards['work_minutes'])) ?></div>
    </article>

    <article class="kpi kpi-warn">
        <div class="kpi-top"><span class="kpi-label">تأخیر امروز</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm.9 4.6v4.2l3.1 1.8-.9 1.6-4-2.3V7.6h1.8Z"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($cards['late']) ?></div>
        <div class="kpi-foot">تعجیل در خروج: <?= $e($cards['early_leave']) ?> — اضافه‌کاری: <?= $e($cards['overtime']) ?></div>
    </article>

    <article class="kpi kpi-error">
        <div class="kpi-top"><span class="kpi-label">غیبت ثبت‌شده امروز</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($cards['absences']) ?></div>
        <div class="kpi-foot">مرخصی تأییدشده امروز: <?= $e($cards['on_leave']) ?></div>
    </article>

    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">دستگاه‌ها</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 3h9a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3 14h3"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($cards['devices']) ?></div>
        <div class="kpi-foot">
            فعال: <?= $e($cards['devices_active']) ?> — در انتظار: <?= $e($cards['devices_pending']) ?>
        </div>
    </article>

    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">شعب</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 21V4h10v17M14 9h6v12"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($cards['branches']) ?></div>
        <div class="kpi-foot"><?= $branchesEnabled ? 'امکان شعب فعال است' : 'امکان شعب برای این کسب‌وکار فعال نیست' ?></div>
    </article>

    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">ثبت چهره</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-3.2 6.5h.01m6.38 0h.01M8.6 14.6a4.4 4.4 0 0 0 6.8 0"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($faceEnrolled) ?></div>
        <div class="kpi-foot">پرسنل دارای نمونه چهره (از ۳ نمونه)</div>
    </article>

    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">شیفت اختصاص‌یافته</span>
            <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm.9 4.6v4.2l3.1 1.8-.9 1.6-4-2.3V7.6h1.8Z"/></svg>
        </div>
        <div class="kpi-value num"><?= $e($shiftAssignment) ?></div>
        <div class="kpi-foot">
            <?php if ($shiftsEnabled): ?>
                <?= $e(count($shifts)) ?> شیفت فعال تعریف شده
            <?php else: ?>
                امکان شیفت برای این کسب‌وکار فعال نیست
            <?php endif; ?>
        </div>
    </article>
</section>

<div class="split">
    <section class="card">
        <div class="card-head">
            <h2>آخرین رویدادهای کسب‌وکار</h2>
            <a class="btn btn-ghost btn-sm" href="<?= $e($url('/manager/activity')) ?>">همه رویدادها</a>
        </div>

        <?php if ($recentAudit === []): ?>
            <div class="empty"><strong>رویدادی ثبت نشده است</strong>به‌محض انجام اولین عملیات، اینجا نمایش داده می‌شود.</div>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($recentAudit as $row): ?>
                    <div class="timeline-item">
                        <div>
                            <strong><?= $e($row['description'] ?? $row['event'] ?? '') ?></strong>
                            <small>
                                <?= $e(Jalali::date($row['created_at'] ?? '', true)) ?> —
                                <?= $e(($row['actor_label'] ?? '') !== '' ? (string) $row['actor_label'] : 'سامانه') ?> —
                                <span class="badge <?= ($row['result'] ?? '') === 'success' ? 'badge-ok' : 'badge-error' ?>"><?= $e($row['result'] ?? '') ?></span>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div>
        <section class="card">
            <div class="card-head"><h2>پرسنل بدون تردد امروز</h2></div>
            <?php if ($missingToday === []): ?>
                <p class="muted small">همه پرسنل فعال امروز تردد ثبت‌شده یا مرخصی تأییدشده دارند (یا هنوز ترددی ثبت نشده است).</p>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($missingToday as $row): ?>
                        <div class="list-row">
                            <span><?= $e(trim(((string) $row['first_name']) . ' ' . ((string) $row['last_name']))) ?></span>
                            <span class="badge badge-muted ltr"><?= $e($row['employee_code'] ?? '') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="muted small" style="margin-top:10px">این فهرست از نبود رکورد تردد امروز ساخته می‌شود؛ تا زمانی که موتور ثبت تردد فعال نشود، خالی یا کامل خواهد بود.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head"><h2>تعطیلات پیش‌رو</h2></div>
            <?php if ($upcomingHolidays === []): ?>
                <p class="muted small">تعطیلی ثبت نشده است. <a href="<?= $e($url('/manager/holidays')) ?>">افزودن تعطیلی</a></p>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($upcomingHolidays as $holiday): ?>
                        <div class="list-row">
                            <span><?= $e($holiday['title']) ?></span>
                            <span class="badge badge-info"><?= $e(Jalali::date($holiday['holiday_date'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head"><h2>دستگاه‌های اخیر</h2></div>
            <?php if ($devices === []): ?>
                <p class="muted small">دستگاهی ثبت نشده است. <a href="<?= $e($url('/manager/devices')) ?>">ثبت دستگاه</a></p>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($devices as $device): ?>
                        <div class="list-row">
                            <span><?= $e($device['name']) ?></span>
                            <span class="badge <?= (string) $device['status'] === Device::STATUS_ACTIVE ? 'badge-ok' : 'badge-muted' ?>"><?= $e($device['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($branchRows !== []): ?>
<section class="card">
    <div class="card-head">
        <h2>شعب</h2>
        <a class="btn btn-ghost btn-sm" href="<?= $e($url('/manager/branches')) ?>">مدیریت شعب</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>نام</th><th>کد</th><th>پرسنل</th><th>دستگاه</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($branchRows as $branch): ?>
                <tr>
                    <td><?= $e($branch['name']) ?></td>
                    <td class="ltr"><?= $e($branch['code']) ?></td>
                    <td class="num"><?= $e($branch['employee_count'] ?? 0) ?></td>
                    <td class="num"><?= $e($branch['device_count'] ?? 0) ?></td>
                    <td><span class="badge <?= !empty($branch['is_active']) ? 'badge-ok' : 'badge-muted' ?>"><?= !empty($branch['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
