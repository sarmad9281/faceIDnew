<?php
/**
 * Manager - employee list with search, filters and real face/shift status.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $listing
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $branches
 * @var array<int,array<string,mixed>> $shifts
 * @var array<int,array<string,mixed>> $shiftMap
 * @var array<int,int> $faceCounts
 * @var array<string,int> $counts
 * @var array<string,string> $statuses
 * @var callable $e
 * @var callable $url
 */

use App\Models\Employee;

$statusBadge = static fn (string $status): string => $status === 'active' ? 'badge-ok' : ($status === 'suspended' ? 'badge-warn' : 'badge-muted');
?>
<div class="page-head">
    <div>
        <h1>پرسنل</h1>
        <p class="muted small">
            <?= $e($counts['total'] ?? 0) ?> نفر — <?= $e($counts['active'] ?? 0) ?> فعال،
            <?= $e($counts['suspended'] ?? 0) ?> غیرفعال، <?= $e($counts['face_enrolled'] ?? 0) ?> دارای نمونه چهره
        </p>
    </div>
    <div class="row-actions">
        <a class="btn btn-ghost" href="<?= $e($url('/manager/reports?type=per_employee')) ?>">گزارش پرسنل</a>
        <a class="btn btn-primary" href="<?= $e($url('/manager/employees/create')) ?>">افزودن پرسنل</a>
    </div>
</div>

<section class="card">
    <form method="get" action="<?= $e($url('/manager/employees')) ?>">
        <div class="filters">
            <label class="field">
                <span>جستجو</span>
                <input type="text" name="q" value="<?= $e($filters['q'] ?? '') ?>" placeholder="نام، کد پرسنلی یا موبایل">
            </label>

            <label class="field">
                <span>وضعیت</span>
                <select name="status" data-autosubmit>
                    <option value="">همه</option>
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php if ($branchesEnabled): ?>
                <label class="field">
                    <span>شعبه</span>
                    <select name="branch_id" data-autosubmit>
                        <option value="">همه شعب</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $e($branch['id']) ?>" <?= (int) ($filters['branch_id'] ?? 0) === (int) $branch['id'] ? 'selected' : '' ?>><?= $e($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <?php if ($shiftsEnabled): ?>
                <label class="field">
                    <span>شیفت</span>
                    <select name="shift_id" data-autosubmit>
                        <option value="">همه شیفت‌ها</option>
                        <?php foreach ($shifts as $shift): ?>
                            <option value="<?= $e($shift['id']) ?>" <?= (int) ($filters['shift_id'] ?? 0) === (int) $shift['id'] ? 'selected' : '' ?>><?= $e($shift['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label class="field">
                <span>وضعیت چهره</span>
                <select name="face" data-autosubmit>
                    <option value="">همه</option>
                    <option value="none" <?= ($filters['face'] ?? '') === 'none' ? 'selected' : '' ?>>بدون نمونه</option>
                    <option value="partial" <?= ($filters['face'] ?? '') === 'partial' ? 'selected' : '' ?>>ناقص</option>
                    <option value="complete" <?= ($filters['face'] ?? '') === 'complete' ? 'selected' : '' ?>>کامل (۳ نمونه)</option>
                </select>
            </label>

            <div class="field">
                <button type="submit" class="btn btn-primary btn-block">اعمال فیلتر</button>
            </div>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>فهرست پرسنل</h2>
        <input type="text" class="input" style="max-width:220px" placeholder="فیلتر سریع در همین صفحه" data-table-filter="#employees-table">
    </div>

    <?php if ($listing['rows'] === []): ?>
        <div class="empty">
            <div class="empty-art">👥</div>
            <strong>پرسنلی یافت نشد</strong>
            <?= ($filters['q'] ?? '') !== '' ? 'فیلترها را تغییر دهید یا پرسنل جدید اضافه کنید.' : 'هنوز پرسنلی ثبت نشده است. با دکمه «افزودن پرسنل» شروع کنید.' ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="employees-table">
                <thead>
                <tr>
                    <th>پرسنل</th>
                    <th>کد</th>
                    <th>سمت</th>
                    <?php if ($branchesEnabled): ?><th>شعبه</th><?php endif; ?>
                    <?php if ($shiftsEnabled): ?><th>شیفت</th><?php endif; ?>
                    <th>چهره</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($listing['rows'] as $employee): ?>
                    <?php
                    $id        = (int) $employee['id'];
                    $faces     = (int) ($faceCounts[$id] ?? 0);
                    $shiftRow  = $shiftMap[$id] ?? null;
                    $photo     = trim((string) ($employee['photo_path'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <div class="person">
                                <span class="avatar">
                                    <?php if ($photo !== ''): ?>
                                        <img src="<?= $e(\App\Support\Url::upload($photo)) ?>" alt="">
                                    <?php else: ?>
                                        <?= $e(mb_substr(Employee::fullName($employee), 0, 1, 'UTF-8')) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="person-meta">
                                    <strong><?= $e(Employee::fullName($employee)) ?></strong>
                                    <small class="ltr"><?= $e($employee['mobile'] ?? '—') ?></small>
                                </span>
                            </div>
                        </td>
                        <td class="ltr small"><?= $e($employee['employee_code'] ?? '—') ?></td>
                        <td><?= $e($employee['position'] ?? '—') ?></td>
                        <?php if ($branchesEnabled): ?><td><?= $e($employee['branch_name'] ?? '—') ?></td><?php endif; ?>
                        <?php if ($shiftsEnabled): ?>
                            <td>
                                <?php if ($shiftRow === null): ?>
                                    <span class="badge badge-muted">بدون شیفت</span>
                                <?php else: ?>
                                    <span class="badge badge-info"><?= $e($shiftRow['shift_name']) ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?= $faces >= 3 ? 'badge-ok' : ($faces > 0 ? 'badge-warn' : 'badge-muted') ?>">
                                <?= $e($faces) ?> / ۳
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $statusBadge((string) $employee['status']) ?>">
                                <?= $e($statuses[$employee['status']] ?? $employee['status']) ?>
                            </span>
                        </td>
                        <td class="actions">
                            <div class="row-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= $e($url('/manager/employees/' . $id)) ?>">پرونده</a>
                                <a class="btn btn-ghost btn-sm" href="<?= $e($url('/manager/employees/' . $id . '/edit')) ?>">ویرایش</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $query = array_merge($filters, ['_path' => '/manager/employees']);
        unset($query['customer_id']);
        require __DIR__ . '/../../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
