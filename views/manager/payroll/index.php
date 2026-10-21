<?php
/**
 * Manager - payroll (stage 7).
 *
 * @var array<int,array<string,mixed>> $rows            profile listing (stage 3 shape)
 * @var array<string,string> $types
 * @var array<string,int> $typeCounts
 * @var int $employeeCount
 * @var int $profiles
 * @var array<string,mixed> $settings
 * @var array<string,mixed> $engine
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Models\AttendanceRecord;
use App\Support\Jalali;

$money = static fn ($value): string => number_format((float) $value, 0);

$period   = $engine['period'];
$items    = $engine['items'];
$preview  = $engine['preview'];
$totals   = $engine['totals'];
$rates    = $engine['rates'];
$isFinal  = is_array($period) && (string) $period['status'] === 'finalized';

$previewOrFrozen = $isFinal ? $items : $preview;
?>
<div class="page-head">
    <div>
        <h1>حقوق و دستمزد</h1>
        <p class="muted small">
            نرخ‌ها در پروفایل هر پرسنل نگه‌داری می‌شود و محاسبه فقط در یک نقطه انجام می‌شود
            (<span class="ltr">PayrollCalculator <?= $e($engine['calc_version']) ?></span>).
            تا وقتی دوره «قطعی» نشده، اعداد پیش‌نمایش زنده هستند؛ بعد از قطعی‌سازی، هر تغییر با ثبت Revision انجام می‌شود.
        </p>
    </div>
    <div class="row-actions">
        <span class="badge">پروفایل: <?= $e((string) $profiles) ?> از <?= $e((string) $employeeCount) ?> پرسنل</span>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <h2>پیش‌نمایش دوره</h2>
        <span class="badge <?= $isFinal ? 'badge-warn' : '' ?>"><?= $isFinal ? 'قطعی‌شده' : 'پیش‌نمایش' ?></span>
    </div>
    <form method="get" action="<?= $e($url('/manager/payroll')) ?>">
        <div class="filters">
            <label class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($engine['from']) ?>"></label>
            <label class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($engine['to']) ?>"></label>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">محاسبه پیش‌نمایش</button></div>
        </div>
    </form>

    <div class="kpi-grid" style="margin-top:10px">
        <article class="kpi"><div class="kpi-top"><span class="kpi-label">مجموع ناخالص</span></div><div class="kpi-value num"><?= $e($money($totals['gross'])) ?></div></article>
        <article class="kpi"><div class="kpi-top"><span class="kpi-label">مجموع کسورات</span></div><div class="kpi-value num"><?= $e($money($totals['deductions'])) ?></div></article>
        <article class="kpi"><div class="kpi-top"><span class="kpi-label">مجموع خالص پرداختی</span></div><div class="kpi-value num"><?= $e($money($totals['net'])) ?></div></article>
        <article class="kpi"><div class="kpi-top"><span class="kpi-label">اضافه‌کاری دوره</span></div><div class="kpi-value num"><?= $e(AttendanceRecord::minutesLabel((int) $totals['overtime'])) ?></div></article>
    </div>

    <?php if ($previewOrFrozen === []): ?>
        <div class="empty">
            <div class="empty-art">💳</div>
            <strong>برای این بازه پرسنل دارای پروفایل حقوقی وجود ندارد</strong>
            ابتدا برای هر پرسنل نرخ‌ها را در فرم پایین ثبت کنید.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>پرسنل</th><th>نوع</th><th>روز کارکرد</th><th>کارکرد</th><th>اضافه‌کاری</th>
                    <th>تأخیر</th><th>تعجیل خروج</th><th>غیبت</th><th>حق پایه</th>
                    <th>فوق‌العاده</th><th>مزایا</th><th>کسورات</th><th>خالص</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($previewOrFrozen as $item): ?>
                    <?php
                    $deductions = $item['total_deductions'] ?? ((float) $item['late_deduction'] + (float) $item['early_leave_deduction'] + (float) $item['absence_deduction'] + (float) $item['fixed_deductions'] + (float) $item['other_deductions']);
                    $name = $item['employee_name'] ?? trim((string) ($item['first_name'] ?? '') . ' ' . (string) ($item['last_name'] ?? ''));
                    $payType = $item['pay_type_label'] ?? ($types[(string) $item['pay_type']] ?? (string) $item['pay_type']);
                    ?>
                    <tr>
                        <td>
                            <strong><?= $e($name) ?></strong>
                            <div class="muted small"><?= $e((string) ($item['employee_code'] ?? '')) ?></div>
                        </td>
                        <td><?= $e((string) $payType) ?></td>
                        <td class="num"><?= $e((string) (int) $item['work_days']) ?></td>
                        <td class="num"><?= $e(AttendanceRecord::minutesLabel((int) $item['work_minutes'])) ?></td>
                        <td class="num"><?= $e(AttendanceRecord::minutesLabel((int) $item['overtime_minutes'])) ?></td>
                        <td class="num"><?= $e(AttendanceRecord::minutesLabel((int) $item['late_minutes'])) ?></td>
                        <td class="num"><?= $e(AttendanceRecord::minutesLabel((int) $item['early_leave_minutes'])) ?></td>
                        <td class="num"><?= $e((string) (int) $item['absence_days']) ?></td>
                        <td class="num"><?= $e($money($item['base_amount'])) ?></td>
                        <td class="num"><?= $e($money($item['overtime_amount'])) ?></td>
                        <td class="num"><?= $e($money($item['benefits'])) ?></td>
                        <td class="num"><?= $e($money($deductions)) ?></td>
                        <td class="num"><strong><?= $e($money($item['net_amount'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">
            فرمول‌ها: اضافه‌کاری = دقیقه ÷ ۶۰ × نرخ اضافه‌کاری | تأخیر = دقیقه × نرخ دقیقه‌ای | غیبت بر اساس روش انتخابی پروفایل.
            خالص = حق پایه + مزایا + فوق‌العاده − کسورات.
        </p>
    <?php endif; ?>
</section>

<section class="grid-2">
    <article class="card">
        <div class="card-head"><h2>دوره‌های حقوق</h2></div>
        <form method="post" action="<?= $e($url('/manager/payroll/period')) ?>">
            <input type="hidden" name="_token" value="<?= $csrf() ?>">
            <div class="filters">
                <label class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($engine['from']) ?>"></label>
                <label class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($engine['to']) ?>"></label>
                <label class="field"><span>عنوان (اختیاری)</span><input type="text" name="title" maxlength="120"></label>
                <div class="field"><button type="submit" class="btn btn-ghost btn-block">ایجاد / انتخاب دوره</button></div>
            </div>
        </form>

        <?php if ($engine['periods'] === []): ?>
            <p class="muted small">هنوز دوره‌ای ساخته نشده است.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>دوره</th><th>وضعیت</th><th>پرسنل</th><th>خالص</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($engine['periods'] as $row): ?>
                        <tr>
                            <td>
                                <?= $e(Jalali::date((string) $row['start_date'])) ?> تا <?= $e(Jalali::date((string) $row['end_date'])) ?>
                                <?php if (($row['finalized_at'] ?? null) !== null): ?>
                                    <div class="muted small">قطعی‌شده در <?= $e(Jalali::date((string) $row['finalized_at'], true)) ?> — <?= $e((string) ($row['finalizer_name'] ?? '')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= (string) $row['status'] === 'finalized' ? 'badge-warn' : '' ?>"><?= $e((string) $row['status'] === 'finalized' ? 'قطعی' : 'باز') ?></span></td>
                            <td class="num"><?= $e((string) (int) $row['employee_count']) ?></td>
                            <td class="num"><?= $e($money($row['total_net'])) ?></td>
                            <td><a class="btn btn-sm btn-ghost" href="<?= $e($url('/manager/payroll?period_id=' . (int) $row['id'])) ?>">مشاهده</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="card">
        <div class="card-head"><h2>قطعی‌سازی و بازمحاسبه</h2></div>
        <?php if (!is_array($period)): ?>
            <p class="muted small">برای قطعی‌سازی، ابتدا یک دوره را انتخاب کنید.</p>
        <?php else: ?>
            <p class="small">
                دوره انتخابی:
                <span class="ltr num"><?= $e((string) $period['start_date']) ?></span>
                تا
                <span class="ltr num"><?= $e((string) $period['end_date']) ?></span>
                —
                <span class="badge <?= $isFinal ? 'badge-warn' : '' ?>"><?= $e($isFinal ? 'قطعی' : 'باز') ?></span>
            </p>
            <?php if (!$isFinal): ?>
                <form method="post" action="<?= $e($url('/manager/payroll/finalize')) ?>">
                    <input type="hidden" name="_token" value="<?= $csrf() ?>">
                    <input type="hidden" name="period_id" value="<?= $e((string) $period['id']) ?>">
                    <button type="submit" class="btn btn-primary">قطعی‌سازی دوره</button>
                </form>
                <p class="muted small" style="margin-top:8px">
                    با قطعی‌سازی، اعداد همین لحظه برای هر پرسنل ذخیره می‌شود و مبنای پرداخت قرار می‌گیرد.
                </p>
            <?php else: ?>
                <form method="post" action="<?= $e($url('/manager/payroll/revise')) ?>">
                    <input type="hidden" name="_token" value="<?= $csrf() ?>">
                    <input type="hidden" name="period_id" value="<?= $e((string) $period['id']) ?>">
                    <label class="field"><span>دلیل بازمحاسبه (الزامی برای Revision)</span><input type="text" name="reason" maxlength="500" placeholder="مثلاً: اصلاح ورود ۱۲ مهر"></label>
                    <button type="submit" class="btn btn-ghost">بازمحاسبه و ثبت Revision</button>
                </form>
            <?php endif; ?>

            <?php if ($engine['revisions'] !== []): ?>
                <h3 class="small">Revision‌های ثبت‌شده</h3>
                <ul class="list">
                    <?php foreach (array_slice($engine['revisions'], 0, 8) as $revision): ?>
                        <?php
                        $old = json_decode((string) $revision['old_values'], true);
                        $new = json_decode((string) $revision['new_values'], true);
                        ?>
                        <li class="list-row">
                            <span>
                                <?= $e(Jalali::date((string) $revision['created_at'], true)) ?> —
                                <?= $e((string) ($revision['actor_label'] ?? '')) ?>
                                <span class="muted small"><?= $e((string) ($revision['reason'] ?? '')) ?></span>
                            </span>
                            <span class="num small">
                                <?= $e($money($old['net_amount'] ?? 0)) ?> →
                                <strong><?= $e($money($new['net_amount'] ?? 0)) ?></strong>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </article>
</section>

<section class="card">
    <div class="card-head">
        <h2>پروفایل حقوقی پرسنل</h2>
        <span class="badge">نرخ‌های دقیقه‌ای/ضریب‌ها اختیاری‌اند</span>
    </div>

    <form method="post" action="<?= $e($url('/manager/payroll/profile')) ?>">
        <input type="hidden" name="_token" value="<?= $csrf() ?>">
        <div class="filters">
            <label class="field">
                <span>پرسنل</span>
                <select name="employee_id" required>
                    <option value="0">— انتخاب پرسنل —</option>
                    <?php foreach ($rows as $row): ?>
                        <option value="<?= $e($row['employee_id']) ?>">
                            <?= $e(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''))) ?>
                            <?= isset($row['pay_type']) ? ' — ' . $e((string) ($types[(string) $row['pay_type']] ?? $row['pay_type'])) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>نوع پرداخت</span>
                <select name="pay_type">
                    <?php foreach ($engine['pay_types'] as $key => $label): ?>
                        <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>حق پایه / دستمزد</span><input type="text" name="base_amount" class="ltr num" value="0"></label>
            <label class="field"><span>نرخ ساعتی</span><input type="text" name="hourly_rate" class="ltr num" value="0"></label>
            <label class="field"><span>نرخ ساعتی اضافه‌کاری</span><input type="text" name="overtime_rate" class="ltr num" value="0"></label>
            <?php foreach ($rates as $field => $meta): ?>
                <?php if ($field === 'absence_mode'): ?>
                    <label class="field">
                        <span><?= $e($meta['label']) ?></span>
                        <select name="absence_mode">
                            <?php foreach ($engine['absence_modes'] as $key => $label): ?>
                                <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-hint"><?= $e($meta['hint']) ?></span>
                    </label>
                <?php else: ?>
                    <label class="field">
                        <span><?= $e($meta['label']) ?></span>
                        <input type="text" name="<?= $e($field) ?>" class="ltr num" value="">
                        <span class="field-hint"><?= $e($meta['hint']) ?></span>
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
            <label class="field"><span>مزایا</span><input type="text" name="benefits_amount" class="ltr num" value="0"></label>
            <label class="field">
                <span>واحد پول</span>
                <select name="currency">
                    <option value="IRT">IRT</option>
                    <option value="IRR">IRR</option>
                </select>
            </label>
            <label class="field"><span>توضیحات</span><input type="text" name="notes" maxlength="255"></label>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">ذخیره پروفایل</button></div>
        </div>
        <p class="muted small">
            اگر نرخ دقیقه‌ای تأخیر یا تعجیل خروج وارد نشود، از نرخ ساعتی ÷ ۶۰ استفاده می‌شود؛
            اگر نرخ اضافه‌کاری وارد نشود، نرخ ساعتی × ضریب (پیش‌فرض ۱.۵) ملاک است. همه این‌ها در یک نقطه محاسبه می‌شوند.
        </p>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>قواعد کارکرد مجموعه</h2>
        <span class="badge">همین مقادیر در محاسبه تأخیر مؤثرند</span>
    </div>
    <form method="post" action="<?= $e($url('/manager/payroll/rules')) ?>">
        <input type="hidden" name="_token" value="<?= $csrf() ?>">
        <div class="filters">
            <label class="field">
                <span>واحد پول</span>
                <select name="currency">
                    <option value="IRT" <?= ($settings['currency'] ?? '') === 'IRT' ? 'selected' : '' ?>>IRT</option>
                    <option value="IRR" <?= ($settings['currency'] ?? '') === 'IRR' ? 'selected' : '' ?>>IRR</option>
                </select>
            </label>
            <label class="field"><span>مهلت تأخیر (دقیقه)</span><input type="number" name="late_grace_minutes" min="0" max="600" value="<?= $e((string) ($settings['late_grace_minutes'] ?? 0)) ?>"></label>
            <label class="field"><span>مهلت تعجیل خروج (دقیقه)</span><input type="number" name="early_grace_minutes" min="0" max="600" value="<?= $e((string) ($settings['early_grace_minutes'] ?? 0)) ?>"></label>
            <div class="field"><button type="submit" class="btn btn-ghost btn-block">ذخیره قواعد</button></div>
        </div>
    </form>
</section>
