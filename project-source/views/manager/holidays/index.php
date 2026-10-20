<?php
/**
 * Manager - customer holiday calendar.
 *
 * @var array<int,array<string,mixed>> $holidays
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $branches
 * @var array<int,array<string,mixed>> $upcoming
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Support\Jalali;

$years = [];
foreach ($holidays as $holiday) {
    $year = substr((string) $holiday['holiday_date'], 0, 4);
    $years[$year] = $year;
}
ksort($years);
$years[$filters['year']] = $filters['year'];
?>
<div class="page-head">
    <div>
        <h1>تعطیلات</h1>
        <p class="muted small">
            تقویم تعطیلات اختصاصی این کسب‌وکار. این تاریخ‌ها مبنای محاسبه کارکرد و حقوق در مرحله بعد خواهند بود.
        </p>
    </div>
</div>

<section class="card">
    <div class="card-head"><h2>ثبت تعطیلی</h2></div>
    <form method="post" action="<?= $e($url('/manager/holidays')) ?>">
        <?= $csrf() ?>
        <div class="grid-2">
            <label class="field"><span>عنوان *</span><input type="text" name="title" maxlength="150" required placeholder="مثلاً تعطیلی رسمی"></label>
            <label class="field">
                <span>تاریخ *</span>
                <input type="date" name="holiday_date" class="ltr" required value="<?= $e(date('Y-m-d')) ?>">
                <span class="field-hint">تاریخ میلادی؛ معادل شمسی در فهرست نمایش داده می‌شود.</span>
            </label>
            <?php if ($branchesEnabled): ?>
                <label class="field">
                    <span>شعبه (اختیاری)</span>
                    <select name="branch_id">
                        <option value="0">همه شعب</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $e($branch['id']) ?>"><?= $e($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="field">
                <span>تکرار سالانه</span>
                <select name="is_recurring_yearly">
                    <option value="0">بدون تکرار</option>
                    <option value="1">هر سال تکرار شود</option>
                </select>
            </label>
        </div>
        <label class="field"><span>توضیح</span><input type="text" name="description" maxlength="255"></label>
        <input type="hidden" name="is_active" value="1">
        <button type="submit" class="btn btn-primary">ثبت تعطیلی</button>
    </form>
</section>

<div class="split">
    <section class="card">
        <div class="card-head">
            <h2>تقویم تعطیلات</h2>
            <form method="get" action="<?= $e($url('/manager/holidays')) ?>" class="inline-form">
                <input type="text" name="q" class="input" style="width:150px" placeholder="جستجوی عنوان" value="<?= $e($filters['q']) ?>">
                <select name="status" class="input" data-autosubmit>
                    <option value="">همه</option>
                    <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>فعال</option>
                    <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>غیرفعال</option>
                </select>
                <button type="submit" class="btn btn-ghost btn-sm">فیلتر</button>
            </form>
        </div>

        <?php if ($holidays === []): ?>
            <div class="empty"><strong>تعطیلی ثبت نشده است</strong>اولین تعطیلی این کسب‌وکار را از فرم بالا ثبت کنید.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>عنوان</th><th>تاریخ شمسی</th><th>تاریخ میلادی</th><th>شعبه</th><th>تکرار</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                    <tbody>
                    <?php foreach ($holidays as $holiday): ?>
                        <tr>
                            <td><?= $e($holiday['title']) ?><small><?= $e($holiday['description'] ?? '') ?></small></td>
                            <td><span class="badge badge-info"><?= $e(Jalali::date($holiday['holiday_date'])) ?></span></td>
                            <td class="ltr small"><?= $e($holiday['holiday_date']) ?></td>
                            <td><?= $e($holiday['branch_name'] ?? 'همه شعب') ?></td>
                            <td><?= !empty($holiday['is_recurring_yearly']) ? 'سالانه' : '—' ?></td>
                            <td><span class="badge <?= !empty($holiday['is_active']) ? 'badge-ok' : 'badge-muted' ?>"><?= !empty($holiday['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                            <td class="actions">
                                <div class="row-actions">
                                    <form method="post" action="<?= $e($url('/manager/holidays/update')) ?>" class="inline-form">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="holiday_id" value="<?= $e($holiday['id']) ?>">
                                        <input type="text" name="title" value="<?= $e($holiday['title']) ?>" class="input" style="width:130px">
                                        <input type="date" name="holiday_date" value="<?= $e($holiday['holiday_date']) ?>" class="input ltr" style="width:140px">
                                        <input type="hidden" name="branch_id" value="<?= $e($holiday['branch_id'] ?? 0) ?>">
                                        <input type="hidden" name="is_recurring_yearly" value="<?= !empty($holiday['is_recurring_yearly']) ? '1' : '0' ?>">
                                        <input type="hidden" name="is_active" value="<?= !empty($holiday['is_active']) ? '1' : '0' ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm">ذخیره</button>
                                    </form>
                                    <form method="post" action="<?= $e($url('/manager/holidays/toggle')) ?>" class="inline-form">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="holiday_id" value="<?= $e($holiday['id']) ?>">
                                        <input type="hidden" name="active" value="<?= !empty($holiday['is_active']) ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm"><?= !empty($holiday['is_active']) ? 'غیرفعال' : 'فعال' ?></button>
                                    </form>
                                    <form method="post" action="<?= $e($url('/manager/holidays/delete')) ?>" class="inline-form" data-confirm="این تعطیلی حذف شود؟">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="holiday_id" value="<?= $e($holiday['id']) ?>">
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
        <div class="card-head"><h2>تعطیلات پیش‌رو</h2></div>
        <?php if ($upcoming === []): ?>
            <p class="muted small">تعطیلی آینده‌ای ثبت نشده است.</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($upcoming as $holiday): ?>
                    <div class="list-row">
                        <span><?= $e($holiday['title']) ?></span>
                        <span class="badge badge-info"><?= $e(Jalali::date($holiday['holiday_date'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
