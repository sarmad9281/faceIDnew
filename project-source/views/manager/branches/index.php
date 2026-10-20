<?php
/**
 * Manager - branches of the active customer (feature gated).
 *
 * @var array<int,array<string,mixed>> $branches
 * @var array<string,mixed>|null $customer
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
?>
<div class="page-head">
    <div>
        <h1>شعب و واحدها</h1>
        <p class="muted small">
            شعب همین کسب‌وکار: <?= $e($customer['name'] ?? '') ?> — دستگاه‌ها و پرسنل به شعبه متصل می‌شوند.
        </p>
    </div>
</div>

<section class="card">
    <div class="card-head"><h2>افزودن شعبه</h2></div>
    <form method="post" action="<?= $e($url('/manager/branches')) ?>">
        <?= $csrf() ?>
        <div class="grid-2">
            <label class="field"><span>نام شعبه *</span><input type="text" name="name" maxlength="150" required></label>
            <label class="field"><span>کد شعبه *</span><input type="text" name="code" maxlength="40" class="ltr" required placeholder="BR-01"></label>
            <label class="field"><span>تلفن</span><input type="tel" name="phone" maxlength="25" class="ltr"></label>
            <label class="field"><span>شهر</span><input type="text" name="city" maxlength="80"></label>
            <label class="field"><span>شعاع مجاز (متر)</span><input type="number" name="geofence_radius" value="150" min="10" max="100000"></label>
        </div>
        <label class="field"><span>آدرس</span><input type="text" name="address" maxlength="255"></label>
        <label class="field"><span>توضیحات</span><textarea name="description" maxlength="255"></textarea></label>
        <button type="submit" class="btn btn-primary">ایجاد شعبه</button>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>فهرست شعب</h2>
        <span class="muted small"><?= $e(count($branches)) ?> شعبه</span>
    </div>

    <?php if ($branches === []): ?>
        <div class="empty"><strong>شعبه‌ای ثبت نشده است</strong>با فرم بالا اولین شعبه را ایجاد کنید.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>نام</th><th>کد</th><th>تلفن</th><th>شهر</th><th>پرسنل</th><th>دستگاه</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($branches as $branch): ?>
                    <tr>
                        <td>
                            <?= $e($branch['name']) ?>
                            <small><?= $e($branch['address'] ?? '') ?></small>
                        </td>
                        <td class="ltr"><?= $e($branch['code']) ?></td>
                        <td class="ltr"><?= $e($branch['phone'] ?? '—') ?></td>
                        <td><?= $e($branch['city'] ?? '—') ?></td>
                        <td class="num"><?= $e($branch['employee_count'] ?? 0) ?></td>
                        <td class="num"><?= $e($branch['device_count'] ?? 0) ?></td>
                        <td><span class="badge <?= !empty($branch['is_active']) ? 'badge-ok' : 'badge-muted' ?>"><?= !empty($branch['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                        <td class="actions">
                            <div class="row-actions">
                                <form method="post" action="<?= $e($url('/manager/branches/update')) ?>" class="inline-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="branch_id" value="<?= $e($branch['id']) ?>">
                                    <input type="text" name="name" value="<?= $e($branch['name']) ?>" maxlength="150" class="input" style="width:140px">
                                    <input type="text" name="phone" value="<?= $e($branch['phone'] ?? '') ?>" maxlength="25" class="input ltr" style="width:110px">
                                    <input type="text" name="city" value="<?= $e($branch['city'] ?? '') ?>" maxlength="80" class="input" style="width:95px">
                                    <input type="hidden" name="address" value="<?= $e($branch['address'] ?? '') ?>">
                                    <input type="hidden" name="geofence_radius" value="<?= $e($branch['geofence_radius'] ?? 150) ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">ذخیره</button>
                                </form>
                                <form method="post" action="<?= $e($url('/manager/branches/toggle')) ?>" class="inline-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="branch_id" value="<?= $e($branch['id']) ?>">
                                    <input type="hidden" name="active" value="<?= !empty($branch['is_active']) ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm"><?= !empty($branch['is_active']) ? 'غیرفعال' : 'فعال' ?></button>
                                </form>
                                <form method="post" action="<?= $e($url('/manager/branches/delete')) ?>" class="inline-form" data-confirm="این شعبه حذف شود؟">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="branch_id" value="<?= $e($branch['id']) ?>">
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
