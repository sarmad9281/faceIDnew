<?php
/**
 * Manager - leaves: create, edit, approve / reject, delete.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,counts:array<string,int>} $listing
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $employees
 * @var array<string,string> $types
 * @var array<string,string> $statuses
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Models\Employee;
use App\Models\Leave;
use App\Support\Jalali;

$statusBadge = static function (string $status): string {
    switch ($status) {
        case 'approved': return 'badge-ok';
        case 'pending': return 'badge-warn';
        case 'rejected': return 'badge-error';
        default: return 'badge-muted';
    }
};
?>
<div class="page-head">
    <div>
        <h1>مرخصی‌ها</h1>
        <p class="muted small">
            <?= $e($listing['counts']['total'] ?? 0) ?> مرخصی ثبت شده —
            <?= $e($listing['counts']['pending'] ?? 0) ?> در انتظار تصمیم،
            <?= $e($listing['counts']['approved'] ?? 0) ?> تأیید شده
        </p>
    </div>
</div>

<?php if ($employees === []): ?>
    <div class="card">
        <div class="empty"><strong>پرسنلی ثبت نشده است</strong>برای ثبت مرخصی ابتدا پرسنل اضافه کنید.</div>
    </div>
<?php else: ?>
<section class="card">
    <div class="card-head"><h2>ثبت مرخصی</h2></div>
    <form method="post" action="<?= $e($url('/manager/leaves')) ?>">
        <?= $csrf() ?>
        <div class="grid-2">
            <label class="field">
                <span>پرسنل *</span>
                <select name="employee_id" required>
                    <option value="">انتخاب کنید…</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= $e($employee['id']) ?>"><?= $e(Employee::optionLabel($employee)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>نوع مرخصی *</span>
                <select name="leave_type" required>
                    <?php foreach ($types as $key => $label): ?>
                        <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>از تاریخ *</span><input type="date" name="start_date" class="ltr" required value="<?= $e(date('Y-m-d')) ?>"></label>
            <label class="field"><span>تا تاریخ *</span><input type="date" name="end_date" class="ltr" required value="<?= $e(date('Y-m-d')) ?>"></label>
            <label class="field">
                <span>وضعیت</span>
                <select name="status">
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= $key === 'pending' ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label class="field"><span>توضیح</span><textarea name="description" maxlength="500"></textarea></label>
        <button type="submit" class="btn btn-primary">ثبت مرخصی</button>
        <span class="muted small">تاریخ‌ها میلادی ثبت می‌شوند و در فهرست به‌صورت شمسی نمایش داده می‌شوند.</span>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-head"><h2>فهرست مرخصی‌ها</h2></div>

    <form method="get" action="<?= $e($url('/manager/leaves')) ?>" style="margin-bottom:14px">
        <div class="filters">
            <label class="field"><span>جستجو</span><input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="نام یا کد پرسنلی"></label>
            <label class="field">
                <span>نوع</span>
                <select name="leave_type" data-autosubmit>
                    <option value="">همه</option>
                    <?php foreach ($types as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= $filters['leave_type'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>وضعیت</span>
                <select name="status" data-autosubmit>
                    <option value="">همه</option>
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($filters['date_from']) ?>"></label>
            <label class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($filters['date_to']) ?>"></label>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">اعمال فیلتر</button></div>
        </div>
    </form>

    <?php if ($listing['rows'] === []): ?>
        <div class="empty"><strong>مرخصی‌ای یافت نشد</strong>با فرم بالا مرخصی جدید ثبت کنید.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>پرسنل</th><th>نوع</th><th>بازه</th><th>روز</th><th>توضیح</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($listing['rows'] as $leave): ?>
                    <?php $id = (int) $leave['id']; ?>
                    <tr>
                        <td><?= $e(trim(((string) ($leave['first_name'] ?? '')) . ' ' . ((string) ($leave['last_name'] ?? '')))) ?>
                            <small class="ltr"><?= $e($leave['employee_code'] ?? '') ?></small>
                        </td>
                        <td><?= $e(Leave::typeLabel((string) $leave['leave_type'])) ?></td>
                        <td><?= $e(Jalali::date($leave['start_date'])) ?> → <?= $e(Jalali::date($leave['end_date'])) ?></td>
                        <td class="num"><?= $e((float) $leave['days']) ?></td>
                        <td class="small"><?= $e(mb_strimwidth((string) ($leave['description'] ?? ''), 0, 60, '…')) ?></td>
                        <td><span class="badge <?= $statusBadge((string) $leave['status']) ?>"><?= $e(Leave::statusLabel((string) $leave['status'])) ?></span></td>
                        <td class="actions">
                            <div class="row-actions">
                                <form method="post" action="<?= $e($url('/manager/leaves/decide')) ?>" class="inline-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="leave_id" value="<?= $e($id) ?>">
                                    <select name="status" class="input" style="width:120px">
                                        <?php foreach ($statuses as $key => $label): ?>
                                            <option value="<?= $e($key) ?>" <?= (string) $leave['status'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-ghost btn-sm">ثبت وضعیت</button>
                                </form>
                                <form method="post" action="<?= $e($url('/manager/leaves/update')) ?>" class="inline-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="leave_id" value="<?= $e($id) ?>">
                                    <input type="hidden" name="employee_id" value="<?= $e($leave['employee_id']) ?>">
                                    <input type="hidden" name="leave_type" value="<?= $e($leave['leave_type']) ?>">
                                    <input type="hidden" name="status" value="<?= $e($leave['status']) ?>">
                                    <input type="date" name="start_date" class="input ltr" style="width:135px" value="<?= $e($leave['start_date']) ?>">
                                    <input type="date" name="end_date" class="input ltr" style="width:135px" value="<?= $e($leave['end_date']) ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">ویرایش بازه</button>
                                </form>
                                <form method="post" action="<?= $e($url('/manager/leaves/delete')) ?>" class="inline-form" data-confirm="این مرخصی حذف شود؟">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="leave_id" value="<?= $e($id) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $query = array_merge($filters, ['_path' => '/manager/leaves']);
        require __DIR__ . '/../../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
