<?php
/**
 * Manager - Excel export centre (feature excel_reports).
 *
 * @var array<string,string> $types
 * @var array{date_from:string,date_to:string} $filters
 * @var array<int,array<string,mixed>> $employees
 * @var array<int,array<string,mixed>> $branches
 * @var bool $branchesEnabled
 * @var array{name:string,version:string,path:string,format:string} $library
 * @var callable $e
 * @var callable $url
 */

use App\Models\Employee;

$link = static function (string $type, bool $withEmployee = false) use ($filters, $e, $url): string {
    return $e($url('/manager/reports/export?' . http_build_query(array_filter([
        'type'        => $type,
        'date_from'   => $filters['date_from'],
        'date_to'     => $filters['date_to'],
        'employee_id' => $withEmployee ? 0 : 0,
    ], static fn ($value): bool => $value !== '' && $value !== null))));
};
?>
<div class="page-head">
    <div>
        <h1>خروجی اکسل</h1>
        <p class="muted small">
            فایل‌ها واقعی <span class="ltr">.xlsx</span> هستند و با کتابخانه
            <span class="ltr"><?= $e($library['name']) ?> <?= $e($library['version']) ?></span>
            ساخته می‌شوند: عنوان گزارش، نام مجموعه، بازه تاریخ، فیلترها، جدول با فیلتر خودکار و بخش خلاصه.
        </p>
    </div>
    <div class="row-actions">
        <a class="btn btn-ghost" href="<?= $e($url('/manager/reports')) ?>">بازگشت به گزارش‌ها</a>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <h2>انتخاب بازه و فیلترها</h2>
        <span class="badge">حداکثر <?= $e(number_format(20000)) ?> ردیف در هر فایل</span>
    </div>
    <form method="get" action="<?= $e($url('/manager/reports/excel')) ?>">
        <div class="filters">
            <label class="field"><span>از تاریخ</span><input type="date" name="date_from" class="ltr" value="<?= $e($filters['date_from']) ?>"></label>
            <label class="field"><span>تا تاریخ</span><input type="date" name="date_to" class="ltr" value="<?= $e($filters['date_to']) ?>"></label>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">اعمال بازه</button></div>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head"><h2>گزارش‌های قابل دانلود</h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>گزارش</th>
                <th>ستون‌های اصلی</th>
                <th>دانلود</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $columns = [
                'daily'        => 'پرسنل، تاریخ، شیفت، ورود، خروج، کارکرد، تأخیر، تعجیل، اضافه‌کاری، وضعیت، منبع',
                'monthly'      => 'پرسنل، روزهای کارکرد/حضور/غیبت/مرخصی، مجموع کارکرد، تأخیر، اضافه‌کاری',
                'per_employee' => 'خلاصه هر پرسنل با میانگین کارکرد روزانه',
                'employee'     => 'کارنامه یک پرسنل در بازه انتخاب‌شده (فیلتر پرسنل را در صفحه گزارش‌ها بگذارید)',
                'late'         => 'پرسنل، تاریخ، شیفت، شروع شیفت، ورود واقعی، دقیقه تأخیر',
                'early_leave'  => 'پرسنل، تاریخ، پایان شیفت، خروج واقعی، دقیقه تعجیل',
                'overtime'     => 'پرسنل، تاریخ، پایان شیفت، خروج واقعی، دقیقه اضافه‌کاری',
                'absence'      => 'پرسنل، تاریخ، شیفت، وضعیت (تعطیل و مرخصی غیبت شمرده نمی‌شود)',
                'leave'        => 'پرسنل، نوع مرخصی، از/تا تاریخ، تعداد روز، وضعیت، توضیحات',
                'branch'       => 'شعبه، پرسنل، تردد، کارکرد، تأخیر، اضافه‌کاری، غیبت',
                'inside'       => 'افراد حاضر در مجموعه همین حالا',
                'payroll'      => 'پیش‌نمایش حقوق: روز کارکرد، کارکرد، اضافه‌کاری، تأخیر، غیبت، حق پایه، فوق‌العاده، کسورات، خالص',
            ];
            ?>
            <?php foreach ($types as $key => $label): ?>
                <tr>
                    <td>
                        <strong><?= $e($label) ?></strong>
                        <div class="muted small ltr"><?= $e($key) ?></div>
                    </td>
                    <td class="small muted"><?= $e($columns[$key] ?? '—') ?></td>
                    <td>
                        <a class="btn btn-sm btn-primary" href="<?= $link($key) ?>">دانلود <?= $e('.xlsx') ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="card-head"><h2>محتوای هر فایل</h2></div>
    <ul class="list">
        <li class="list-row"><span>عنوان گزارش و نام مجموعه</span><span class="badge">ردیف ۱ و ۲</span></li>
        <li class="list-row"><span>بازه تاریخ (شمسی و میلادی) و تاریخ تولید</span><span class="badge">ردیف ۳ و ۴</span></li>
        <li class="list-row"><span>فیلترهای اعمال‌شده</span><span class="badge">ردیف ۵</span></li>
        <li class="list-row"><span>جدول داده با فیلتر خودکار و قفل سرصفحه</span><span class="badge">Freeze + AutoFilter</span></li>
        <li class="list-row"><span>خلاصه گزارش زیر جدول</span><span class="badge">Summary</span></li>
    </ul>
    <p class="muted small">
        کتابخانه: <span class="ltr"><?= $e($library['path']) ?></span> — نسخه <span class="ltr"><?= $e($library['version']) ?></span>
        (به همراه پروژه نصب می‌شود؛ روی هاست اشتراکی نیازی به Composer نیست).
    </p>
</section>
