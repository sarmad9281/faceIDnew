<?php
/**
 * Manager - create / edit one employee of the active customer.
 *
 * @var array<string,mixed>|null $employee
 * @var array<int,array<string,mixed>> $branches
 * @var array<int,array<string,mixed>> $shifts
 * @var array<string,mixed>|null $currentShift
 * @var array<string,string> $statuses
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

$isEdit = $employee !== null;
$action = $isEdit ? '/manager/employees/update' : '/manager/employees';
$row    = $employee ?? [];
$pick   = static function (string $key, string $default = '') use ($row): string {
    return htmlspecialchars((string) ($row[$key] ?? $default), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$employmentTypes = [
    'full_time'  => 'تمام‌وقت',
    'part_time'  => 'پاره‌وقت',
    'contractor' => 'پیمانکاری',
    'intern'     => 'کارآموز',
];
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'ویرایش پرسنل' : 'افزودن پرسنل' ?></h1>
        <p class="muted small">اطلاعات پایه پرسنل؛ ثبت نمونه‌های چهره پس از ذخیره در پرونده پرسنل انجام می‌شود.</p>
    </div>
    <a class="btn btn-ghost" href="<?= $e($url('/manager/employees')) ?>">بازگشت به فهرست</a>
</div>

<form method="post" action="<?= $e($url($action)) ?>" enctype="multipart/form-data" class="card">
    <?= $csrf() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="employee_id" value="<?= $e($employee['id']) ?>">
    <?php endif; ?>

    <div class="section-title">اطلاعات هویتی</div>
    <div class="grid-2">
        <label class="field">
            <span>نام *</span>
            <input type="text" name="first_name" maxlength="80" required value="<?= $pick('first_name') ?>">
        </label>
        <label class="field">
            <span>نام خانوادگی *</span>
            <input type="text" name="last_name" maxlength="80" required value="<?= $pick('last_name') ?>">
        </label>
        <label class="field">
            <span>شماره موبایل</span>
            <input type="tel" name="mobile" maxlength="15" class="ltr" value="<?= $pick('mobile') ?>" placeholder="09123456789">
        </label>
        <label class="field">
            <span>کد ملی</span>
            <input type="text" name="national_id" maxlength="20" class="ltr" value="<?= $pick('national_id') ?>">
        </label>
        <label class="field">
            <span>ایمیل</span>
            <input type="email" name="email" maxlength="120" class="ltr" value="<?= $pick('email') ?>">
        </label>
        <label class="field">
            <span>کد پرسنلی</span>
            <input type="text" name="employee_code" maxlength="40" class="ltr" value="<?= $pick('employee_code') ?>">
            <span class="field-hint">خالی بگذارید تا به‌صورت خودکار ساخته شود.</span>
        </label>
    </div>

    <div class="section-title">اطلاعات شغلی</div>
    <div class="grid-2">
        <label class="field">
            <span>سمت</span>
            <input type="text" name="position" maxlength="80" value="<?= $pick('position') ?>">
        </label>
        <label class="field">
            <span>واحد سازمانی</span>
            <input type="text" name="department" maxlength="80" value="<?= $pick('department') ?>">
        </label>
        <label class="field">
            <span>نوع همکاری</span>
            <select name="employment_type">
                <?php foreach ($employmentTypes as $key => $label): ?>
                    <option value="<?= $e($key) ?>" <?= $pick('employment_type', 'full_time') === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>تاریخ استخدام (میلادی)</span>
            <input type="date" name="hire_date" class="ltr" value="<?= $pick('hire_date', date('Y-m-d')) ?>">
            <span class="field-hint">معادل شمسی: <?= $e(\App\Support\Jalali::date($pick('hire_date', date('Y-m-d')))) ?></span>
        </label>

        <?php if ($branchesEnabled): ?>
            <label class="field">
                <span>شعبه</span>
                <select name="branch_id">
                    <option value="0">— بدون شعبه —</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $e($branch['id']) ?>" <?= (int) $pick('branch_id', '0') === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= $e($branch['name']) ?> (<?= $e($branch['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <?php if ($shiftsEnabled): ?>
            <label class="field">
                <span>شیفت</span>
                <select name="shift_id">
                    <option value="0">— بدون شیفت —</option>
                    <?php foreach ($shifts as $shift): ?>
                        <option value="<?= $e($shift['id']) ?>" <?= (int) ($currentShift['shift_id'] ?? 0) === (int) $shift['id'] ? 'selected' : '' ?>>
                            <?= $e($shift['name']) ?> — <?= $e(\App\Models\Shift::summary($shift)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <label class="field">
            <span>وضعیت</span>
            <select name="status">
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= $e($key) ?>" <?= $pick('status', 'active') === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="field-hint">پرسنل غیرفعال نمی‌تواند تردد جدید ثبت کند؛ سوابق قبلی حفظ می‌شود.</span>
        </label>

        <label class="field">
            <span>تصویر پرسنل</span>
            <input type="file" name="photo" accept="image/png,image/jpeg,image/webp">
            <?php if (!empty($employee['photo_path'])): ?>
                <span class="field-hint">تصویر فعلی ثبت شده است؛ انتخاب فایل جدید آن را جایگزین می‌کند.</span>
            <?php endif; ?>
        </label>
    </div>

    <label class="field">
        <span>یادداشت</span>
        <textarea name="notes" maxlength="500"><?= $pick('notes') ?></textarea>
    </label>

    <div class="row-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'ذخیره تغییرات' : 'ثبت پرسنل' ?></button>
        <a class="btn btn-ghost" href="<?= $e($url('/manager/employees')) ?>">انصراف</a>
    </div>
</form>

<?php if ($isEdit): ?>
<section class="card">
    <div class="card-head"><h2>نمونه‌های چهره</h2></div>
    <p class="muted small">
        ثبت نمونه‌های چهره در پرونده پرسنل انجام می‌شود (سه نمونه). در این مرحله فقط تصاویر ذخیره می‌شوند؛
        موتور تشخیص چهره و تشخیص زنده‌بودن در مراحل بعدی و به‌صورت جداگانه اضافه می‌شود.
    </p>
    <a class="btn btn-ghost" href="<?= $e($url('/manager/employees/' . $employee['id'])) ?>">رفتن به پرونده پرسنل</a>
</section>
<?php endif; ?>
