<?php
/**
 * Owner - managers list (search / customer / status filters + pagination).
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $listing
 * @var array<int,array<string,mixed>> $customers
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$rows = $listing['rows'];
?>
<div class="o-page-head">
    <div>
        <h1>مدیران مشتریان</h1>
        <p>ایجاد، ویرایش، فعال/غیرفعال‌سازی مدیران و بازنشانی رمز عبور آن‌ها. هر مدیر فقط به داده‌های مشتری خودش دسترسی دارد.</p>
    </div>
    <div class="o-actions">
        <form method="get" action="<?= $e($url('/owner/managers/create')) ?>" class="o-inline-form" style="gap:8px">
            <select name="customer_id" class="o-field" style="padding:9px 12px;border-radius:10px;background:var(--o-panel-2);border:1px solid var(--o-line);color:var(--o-text)" required>
                <option value="">انتخاب مشتری…</option>
                <?php foreach ($customers as $option): ?>
                    <option value="<?= $e($option['id']) ?>"><?= $e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="o-btn o-btn-primary">افزودن مدیر</button>
        </form>
    </div>
</div>

<section class="o-card">
    <form method="get" action="<?= $e($url('/owner/managers')) ?>" class="o-filters">
        <label class="o-field">
            <span>جستجو</span>
            <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="نام، ایمیل، موبایل، نام کاربری، مشتری">
        </label>

        <label class="o-field">
            <span>مشتری</span>
            <select name="customer_id" data-autosubmit>
                <option value="0">همه مشتریان</option>
                <?php foreach ($customers as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= (int) $filters['customer_id'] === (int) $option['id'] ? 'selected' : '' ?>><?= $e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="o-field">
            <span>وضعیت</span>
            <select name="status" data-autosubmit>
                <option value="">همه</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>غیرفعال</option>
            </select>
        </label>

        <div class="o-field">
            <span>&nbsp;</span>
            <button type="submit" class="o-btn o-btn-primary">اعمال فیلتر</button>
        </div>
    </form>
</section>

<section class="o-card">
    <div class="o-card-head">
        <h2>فهرست مدیران<span class="o-sub"><?= $e($listing['total']) ?> مدیر</span></h2>
        <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/managers')) ?>">حذف فیلترها</a>
    </div>

    <?php if ($rows === []): ?>
        <div class="o-empty"><strong>مدیری با این فیلترها یافت نشد</strong>برای هر مشتری می‌توانید یک یا چند مدیر بسازید.</div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>نام</th>
                    <th>مشتری</th>
                    <th>نام کاربری</th>
                    <th>تماس</th>
                    <th>وضعیت</th>
                    <th>آخرین ورود</th>
                    <th>عملیات</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $manager): ?>
                    <tr>
                        <td>
                            <a href="<?= $e($url('/owner/managers/' . $manager['id'] . '/edit')) ?>"><?= $e($manager['name']) ?></a>
                            <small><?= !empty($manager['is_primary']) ? 'مدیر اصلی' : 'مدیر' ?></small>
                        </td>
                        <td>
                            <?php if (!empty($manager['customer_uuid'])): ?>
                                <a href="<?= $e($url('/owner/customers/' . $manager['customer_uuid'])) ?>"><?= $e($manager['customer_name'] ?? '—') ?></a>
                            <?php else: ?>
                                <?= $e($manager['customer_name'] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <td class="o-ltr"><?= $e($manager['username'] ?? '—') ?></td>
                        <td>
                            <?= $e($manager['mobile'] ?? '—') ?>
                            <small><?= $e($manager['email'] ?? '') ?></small>
                        </td>
                        <td>
                            <span class="o-badge <?= !empty($manager['is_active']) && !empty($manager['user_active']) ? 'o-badge-ok' : 'o-badge-bad' ?>">
                                <?= !empty($manager['is_active']) && !empty($manager['user_active']) ? 'فعال' : 'غیرفعال' ?>
                            </span>
                            <?php if (!empty($manager['must_change_password'])): ?>
                                <small class="o-badge o-badge-warn">تغییر رمز در اولین ورود</small>
                            <?php endif; ?>
                        </td>
                        <td class="o-small">
                            <?= $e($manager['last_login_at'] ?? '—') ?>
                            <?php if (!empty($manager['last_login_at'])): ?>
                                <small class="o-ltr"><?= $e($manager['last_login_ip'] ?? '') ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="o-actions">
                                <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/managers/' . $manager['id'] . '/edit')) ?>">ویرایش</a>

                                <form method="post" action="<?= $e($url('/owner/managers/' . $manager['id'] . '/toggle')) ?>" class="o-inline-form"
                                      data-confirm="<?= !empty($manager['is_active']) ? 'این مدیر غیرفعال شود؟ دسترسی او و توکن‌های API‌اش قطع می‌شود.' : 'این مدیر فعال شود؟' ?>">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="active" value="<?= !empty($manager['is_active']) ? '0' : '1' ?>">
                                    <button type="submit" class="o-btn o-btn-sm <?= !empty($manager['is_active']) ? 'o-btn-danger' : 'o-btn-ok' ?>">
                                        <?= !empty($manager['is_active']) ? 'غیرفعال' : 'فعال' ?>
                                    </button>
                                </form>

                                <form method="post" action="<?= $e($url('/owner/managers/' . $manager['id'] . '/reset-password')) ?>" class="o-inline-form"
                                      data-confirm="رمز عبور این مدیر بازنشانی شود؟ رمز جدید فقط یک بار نمایش داده می‌شود.">
                                    <?= $csrf() ?>
                                    <button type="submit" class="o-btn o-btn-sm o-btn-warn">بازنشانی رمز</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::render('partials/pagination', [
            'listing' => $listing,
            'query'   => [
                '_path'       => '/owner/managers',
                'q'           => $filters['q'],
                'status'      => $filters['status'],
                'customer_id' => (int) $filters['customer_id'],
            ],
        ]) ?>
    <?php endif; ?>
</section>
