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
 * @var array<string,mixed> $insurance          stage 23: SSO list of the selected period
 * @var array<string,mixed> $insuranceSettings  stage 23: workshop data + rates
 * @var array<int,string> $listKinds            stage 23: DSK_KIND options
 * @var array<int,array<string,mixed>> $bankColumns
 * @var array<string,array<string,mixed>> $identityColumns
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Models\AttendanceRecord;
use App\Support\Jalali;
use App\Support\JalaliHint;

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
            <label class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($engine['from']) ?>"><?= JalaliHint::render($engine['from']) ?></label>
            <label class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($engine['to']) ?>"><?= JalaliHint::render($engine['to']) ?></label>
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
            <?= $csrf() ?>
            <div class="filters">
                <label class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($engine['from']) ?>"><?= JalaliHint::render($engine['from']) ?></label>
                <label class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($engine['to']) ?>"><?= JalaliHint::render($engine['to']) ?></label>
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
                            <td>
                                <a class="btn btn-sm btn-ghost" href="<?= $e($url('/manager/payroll?period_id=' . (int) $row['id'])) ?>">مشاهده</a>
                                <?php /* Stage 23: the official files of this period. A download link, never a
                                           form - it is a GET with no side effect, and it is audited server side. */ ?>
                                <a class="btn btn-sm btn-ghost" title="دیسکت لیست حق بیمه تأمین اجتماعی (DSKKAR00 + DSKWOR00)"
                                   href="<?= $e($url('/manager/payroll/diskette?period_id=' . (int) $row['id'] . '&file=zip')) ?>">دیسکت بیمه</a>
                                <a class="btn btn-sm btn-ghost" title="فایل پرداخت خالص حقوق برای بانک"
                                   href="<?= $e($url('/manager/payroll/bank-file?period_id=' . (int) $row['id'] . '&format=xlsx')) ?>">فایل بانک</a>
                            </td>
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
                <span class="ltr num" title="<?= $e((string) $period['start_date']) ?>"><?= $e(Jalali::date((string) $period['start_date'])) ?></span>
                تا
                <span class="ltr num" title="<?= $e((string) $period['end_date']) ?>"><?= $e(Jalali::date((string) $period['end_date'])) ?></span>
                —
                <span class="badge <?= $isFinal ? 'badge-warn' : '' ?>"><?= $e($isFinal ? 'قطعی' : 'باز') ?></span>
            </p>
            <?php if (!$isFinal): ?>
                <form method="post" action="<?= $e($url('/manager/payroll/finalize')) ?>">
                    <?= $csrf() ?>
                    <input type="hidden" name="period_id" value="<?= $e((string) $period['id']) ?>">
                    <button type="submit" class="btn btn-primary">قطعی‌سازی دوره</button>
                </form>
                <p class="muted small" style="margin-top:8px">
                    با قطعی‌سازی، اعداد همین لحظه برای هر پرسنل ذخیره می‌شود و مبنای پرداخت قرار می‌گیرد.
                </p>
            <?php else: ?>
                <form method="post" action="<?= $e($url('/manager/payroll/revise')) ?>">
                    <?= $csrf() ?>
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

<?php /* =================================================================
 * STAGE 23 - دیسکت لیست حق بیمه تأمین اجتماعی + فایل پرداخت بانک
 *
 * The workshop data is a customer setting (it survives every period), the list
 * itself is built from the selected period: from the FROZEN rows when the period
 * is finalized and from the LIVE preview while it is open, so what is shown here
 * is exactly what the downloaded file contains.
 * ================================================================== */ ?>
<section class="card" id="insurance">
    <div class="card-head">
        <h2>اطلاعات بیمه و بانک</h2>
        <span class="badge">دیسکت تأمین اجتماعی · DSKKAR00 / DSKWOR00</span>
    </div>

    <form method="post" action="<?= $e($url('/manager/payroll/insurance')) ?>">
        <?= $csrf() ?>
        <input type="hidden" name="period_id" value="<?= $e((string) (is_array($period) ? (int) $period['id'] : 0)) ?>">
        <div class="filters">
            <label class="field">
                <span>شماره کارگاه (DSK_ID)</span>
                <input type="text" name="insurance_workshop_code" class="ltr num" inputmode="numeric" maxlength="10"
                       value="<?= $e((string) ($insuranceSettings['workshop_code'] ?? '')) ?>" required>
                <span class="field-hint">فقط رقم — بدون آن، دیسکت ساخته نمی‌شود</span>
            </label>
            <label class="field">
                <span>نام کارگاه (DSK_NAME)</span>
                <input type="text" name="insurance_workshop_name" maxlength="60"
                       value="<?= $e((string) ($insuranceSettings['workshop_name'] ?? '')) ?>">
                <span class="field-hint">خالی = نام شرکت در پروفایل مجموعه</span>
            </label>
            <label class="field">
                <span>نام کارفرما (DSK_FARM)</span>
                <input type="text" name="insurance_employer_name" maxlength="60"
                       value="<?= $e((string) ($insuranceSettings['employer_name'] ?? '')) ?>">
            </label>
            <label class="field">
                <span>نشانی کارگاه (DSK_ADRS)</span>
                <input type="text" name="insurance_workshop_address" maxlength="120"
                       value="<?= $e((string) ($insuranceSettings['workshop_address'] ?? '')) ?>">
            </label>
            <label class="field">
                <span>نوع لیست (DSK_KIND)</span>
                <select name="insurance_list_kind">
                    <?php foreach ($listKinds as $value => $label): ?>
                        <option value="<?= $e((string) $value) ?>" <?= (int) ($insuranceSettings['list_kind'] ?? 1) === (int) $value ? 'selected' : '' ?>>
                            <?= $e((string) $value) ?> — <?= $e((string) $label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>شماره لیست (DSK_LISTNO)</span>
                <input type="text" name="insurance_list_no" class="ltr num" maxlength="12"
                       value="<?= $e((string) ($insuranceSettings['list_no'] ?? '')) ?>">
                <span class="field-hint">خالی = سال و ماه شمسی دوره</span>
            </label>
            <label class="field">
                <span>ردیف پیمان (MON_PYM)</span>
                <input type="text" name="insurance_contract_row" class="ltr num" maxlength="3"
                       value="<?= $e((string) ($insuranceSettings['contract_row'] ?? '000')) ?>">
            </label>
            <label class="field">
                <span>شرح لیست (DSK_DISC)</span>
                <input type="text" name="insurance_description" maxlength="60"
                       value="<?= $e((string) ($insuranceSettings['description'] ?? '')) ?>">
                <span class="field-hint">خالی = «لیست حق بیمه مهر ۱۴۰۴»</span>
            </label>
            <label class="field">
                <span>سهم بیمه‌شده (٪)</span>
                <input type="number" name="insurance_employee_rate" class="ltr num" step="0.01" min="0" max="100"
                       value="<?= $e((string) ($insuranceSettings['employee_rate'] ?? 7)) ?>">
                <span class="field-hint">DSW_BIME — پیش‌فرض ۷٪</span>
            </label>
            <label class="field">
                <span>سهم کارفرما (٪)</span>
                <input type="number" name="insurance_employer_rate" class="ltr num" step="0.01" min="0" max="100"
                       value="<?= $e((string) ($insuranceSettings['employer_rate'] ?? 20)) ?>">
                <span class="field-hint">DSK_TKOSO — پیش‌فرض ۲۰٪</span>
            </label>
            <label class="field">
                <span>بیمه بیکاری (٪)</span>
                <input type="number" name="insurance_unemployment_rate" class="ltr num" step="0.01" min="0" max="100"
                       value="<?= $e((string) ($insuranceSettings['unemployment_rate'] ?? 3)) ?>">
                <span class="field-hint">DSK_BIC — پیش‌فرض ۳٪</span>
            </label>
            <label class="field">
                <span>سقف ماخذ (ریال)</span>
                <input type="text" name="insurance_max_mash" class="ltr num" inputmode="numeric"
                       value="<?= $e((string) ($insuranceSettings['max_mash'] ?? 0)) ?>">
                <span class="field-hint">۰ = بدون سقف</span>
            </label>
            <label class="field">
                <span>نرخ پورسانتاژ (DSK_PRATE)</span>
                <input type="text" name="insurance_period_rate" class="ltr num" inputmode="decimal"
                       value="<?= $e((string) ($insuranceSettings['period_rate'] ?? 0)) ?>">
            </label>
            <label class="field">
                <span>نام بانک پیش‌فرض</span>
                <input type="text" name="bank_name" maxlength="60"
                       value="<?= $e((string) ($insuranceSettings['bank_name'] ?? '')) ?>">
            </label>
            <label class="field">
                <span>شرح پرداخت بانک</span>
                <input type="text" name="bank_payment_note" maxlength="60"
                       value="<?= $e((string) ($insuranceSettings['bank_note'] ?? '')) ?>">
                <span class="field-hint">خالی = «حقوق ۱۴۰۴/۰۷»</span>
            </label>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">ذخیره اطلاعات بیمه و بانک</button></div>
        </div>
    </form>

    <?php
    $insuranceOk   = (bool) ($insurance['ok'] ?? false);
    $insuranceData = (array) ($insurance['data'] ?? []);
    $control       = (array) ($insuranceData['control'] ?? []);
    $kar           = (array) ($insuranceData['kar'] ?? []);
    $wor           = (array) ($insuranceData['wor'] ?? []);
    $selectedId    = is_array($period) ? (int) $period['id'] : 0;
    ?>

    <?php if ($selectedId <= 0): ?>
        <p class="muted small" style="margin-top:12px">
            برای دیدن پیش‌نمایش و دریافت دیسکت، از جدول «دوره‌های حقوق» یک دوره را انتخاب کنید.
        </p>
    <?php elseif (!$insuranceOk): ?>
        <p class="alert alert-error" style="margin-top:12px"><?= $e((string) ($insurance['message'] ?? 'ساخت لیست ناموفق بود.')) ?></p>
    <?php else: ?>
        <?php $head = $kar[0] ?? []; ?>
        <h3 class="small" style="margin-top:14px">
            پیش‌نمایش DSKKAR00 — <?= $e((string) ($insuranceData['jalali']['label'] ?? '')) ?>
            <span class="badge"><?= $e((string) ($insuranceData['settings']['workshop_code'] ?? '')) ?></span>
            <span class="badge">نوع لیست <?= $e((string) ($insuranceData['settings']['list_kind'] ?? 1)) ?></span>
            <span class="badge">شماره لیست <?= $e((string) ($insuranceData['list_no'] ?? '')) ?></span>
            <span class="badge <?= ($insuranceData['finalized'] ?? false) ? 'badge-warn' : '' ?>">
                <?= ($insuranceData['finalized'] ?? false) ? 'دوره قطعی' : 'پیش‌نمایش زنده' ?>
            </span>
        </h3>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>نفرات</th><th>جمع روز کارکرد</th><th>جمع دستمزد روزانه</th><th>جمع دستمزد ماهانه</th>
                        <th>جمع مزایا</th><th>جمع ماخذ</th><th>سهم بیمه‌شده</th><th>سهم کارفرما</th><th>بیمه بیکاری</th><th>نرخ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="num">
                        <td><?= $e((string) (int) ($head['DSK_NUM'] ?? 0)) ?></td>
                        <td><?= $e((string) (int) ($head['DSK_TDD'] ?? 0)) ?></td>
                        <td><?= $e($money($head['DSK_TROOZ'] ?? 0)) ?></td>
                        <td><?= $e($money($head['DSK_TMAH'] ?? 0)) ?></td>
                        <td><?= $e($money($head['DSK_TMAZ'] ?? 0)) ?></td>
                        <td><?= $e($money($head['DSK_TMASH'] ?? 0)) ?></td>
                        <td><?= $e($money($head['DSK_TBIME'] ?? 0)) ?></td>
                        <td><?= $e($money($head['DSK_TKOSO'] ?? 0)) ?></td>
                        <td><?= $e($money($head['DSK_BIC'] ?? 0)) ?></td>
                        <td><?= $e((string) (int) ($head['DSK_RATE'] ?? 0)) ?>٪</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <details style="margin-top:10px">
            <summary>ریز DSKWOR00 — <?= $e((string) count($wor)) ?> نفر</summary>
            <div class="table-wrap" style="margin-top:10px">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ردیف</th><th>نام</th><th>شماره بیمه</th><th>کد ملی</th><th>شغل</th>
                            <th>روز</th><th>دستمزد روزانه</th><th>دستمزد ماهانه</th><th>مزایا</th>
                            <th>ماخذ</th><th>سهم بیمه‌شده</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($wor as $index => $person): ?>
                        <tr>
                            <td class="num"><?= $e((string) ($index + 1)) ?></td>
                            <td><?= $e((string) ($person['_employee_name'] ?? '')) ?></td>
                            <td class="num ltr"><?= $e((string) ($person['DSW_ID1'] ?? '')) ?></td>
                            <td class="num ltr"><?= $e((string) ($person['PER_NATCOD'] ?? '')) ?></td>
                            <td><?= $e((string) ($person['DSW_OCP'] ?? '')) ?></td>
                            <td class="num"><?= $e((string) (int) ($person['DSW_DD'] ?? 0)) ?></td>
                            <td class="num"><?= $e($money($person['DSW_ROOZ'] ?? 0)) ?></td>
                            <td class="num"><?= $e($money($person['DSW_MAH'] ?? 0)) ?></td>
                            <td class="num"><?= $e($money($person['DSW_MAZ'] ?? 0)) ?></td>
                            <td class="num"><?= $e($money($person['DSW_MASH'] ?? 0)) ?></td>
                            <td class="num"><?= $e($money($person['DSW_BIME'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>

        <?php foreach (($insuranceData['warnings'] ?? []) as $warning): ?>
            <p class="alert alert-warn small"><?= $e((string) $warning) ?></p>
        <?php endforeach; ?>

        <div class="row-actions" style="margin-top:12px;flex-wrap:wrap;gap:6px">
            <a class="btn btn-primary" href="<?= $e($url('/manager/payroll/diskette?period_id=' . $selectedId . '&file=zip')) ?>">
                دریافت دیسکت بیمه (ZIP)
            </a>
            <a class="btn btn-ghost" href="<?= $e($url('/manager/payroll/diskette?period_id=' . $selectedId . '&file=kar-dbf')) ?>">DSKKAR00.dbf</a>
            <a class="btn btn-ghost" href="<?= $e($url('/manager/payroll/diskette?period_id=' . $selectedId . '&file=wor-dbf')) ?>">DSKWOR00.dbf</a>
            <a class="btn btn-ghost" href="<?= $e($url('/manager/payroll/diskette?period_id=' . $selectedId . '&file=kar')) ?>">DSKKAR00.txt</a>
            <a class="btn btn-ghost" href="<?= $e($url('/manager/payroll/diskette?period_id=' . $selectedId . '&file=wor')) ?>">DSKWOR00.txt</a>
            <a class="btn btn-ghost" href="<?= $e($url('/manager/payroll/diskette?period_id=' . $selectedId . '&file=zip&encoding=utf-8')) ?>">ZIP با کدگذاری UTF-8</a>
            <a class="btn btn-ghost" href="<?= $e($url('/manager/payroll/bank-file?period_id=' . $selectedId . '&format=xlsx')) ?>">فایل بانک (Excel)</a>
            <a class="btn btn-ghost" href="<?= $e($url('/manager/payroll/bank-file?period_id=' . $selectedId . '&format=csv')) ?>">فایل بانک (CSV)</a>
        </div>

        <p class="muted small" style="margin-top:8px">
            مبلغ‌ها به <strong>ریال</strong> و با رقم لاتین نوشته می‌شوند (پروفایل تومان ×۱۰).
            روزهای کارکرد = حاضر + مرخصی + تعطیل، حداکثر به طول ماه شمسی.
            فایل متنی و DBF با کدگذاری WINDOWS-1256 تولید می‌شود؛ نسخه UTF-8 فقط برای بازبینی است.
            مزایای غیرمشمول (عیدی، کمک مسکن در ایام بیماری، حق شیر، مأموریت و…) را در «مزایا» وارد نکنید.
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2>پروفایل حقوقی پرسنل</h2>
        <span class="badge">نرخ‌های دقیقه‌ای/ضریب‌ها اختیاری‌اند</span>
    </div>

    <form method="post" action="<?= $e($url('/manager/payroll/profile')) ?>">
        <?= $csrf() ?>
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

        <?php /* Stage 23: the identity data the SSO diskette needs (DSW_*) and the
                   bank account of the payment file. They belong to THIS profile row,
                   so they are edited here and not in the personnel form. Collapsed by
                   default - a payroll operator who does not send an insurance list
                   never has to look at them. */ ?>
        <details style="margin-top:10px">
            <summary>اطلاعات بیمه و بانک همین پرسنل (دیسکت تأمین اجتماعی + فایل پرداخت)</summary>
            <div class="filters" style="margin-top:10px">
                <?php foreach ($identityColumns as $field => $meta): ?>
                    <?php if (($meta['kind'] ?? 'text') === 'choice'): ?>
                        <label class="field">
                            <span><?= $e($meta['label']) ?></span>
                            <select name="<?= $e($field) ?>">
                                <option value="">— خالی —</option>
                                <?php foreach (($meta['options'] ?? []) as $value => $label): ?>
                                    <option value="<?= $e((string) $value) ?>"><?= $e((string) $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-hint"><?= $e($meta['hint']) ?></span>
                        </label>
                    <?php elseif (($meta['kind'] ?? '') === 'date'): ?>
                        <label class="field">
                            <span><?= $e($meta['label']) ?></span>
                            <input type="date" name="<?= $e($field) ?>" class="ltr"><?= JalaliHint::render('') ?>
                            <span class="field-hint"><?= $e($meta['hint']) ?></span>
                        </label>
                    <?php else: ?>
                        <label class="field">
                            <span><?= $e($meta['label']) ?></span>
                            <input type="text" name="<?= $e($field) ?>" class="ltr"
                                   <?= ($meta['kind'] ?? '') === 'iban' ? 'maxlength="34" placeholder="IR06…"' : '' ?>
                                   <?= ($meta['kind'] ?? '') === 'digits' ? 'inputmode="numeric"' : '' ?>>
                            <span class="field-hint"><?= $e($meta['hint']) ?></span>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="muted small">
                این فیلدها فقط در دیسکت لیست حق بیمه و فایل پرداخت بانک استفاده می‌شوند و در محاسبه حقوق اثری ندارند.
                شماره بیمه، کد ملی و شماره شناسنامه فقط رقم نگه‌داشته می‌شوند؛ شبا بدون فاصله و با حروف بزرگ ذخیره می‌شود.
            </p>
        </details>
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
        <?= $csrf() ?>
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
