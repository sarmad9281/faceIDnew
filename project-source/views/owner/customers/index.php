<?php
/**
 * Owner - customers list (search + status + subscription filters + pagination).
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $listing
 * @var array<string,mixed> $filters
 * @var callable $e
 * @var callable $url
 */
$rows = $listing['rows'];
?>
<div class="o-page-head">
    <div>
        <h1>مدیریت مشتریان</h1>
        <p>ایجاد، ویرایش، فعال/غیرفعال‌سازی مشتریان و مشاهده وضعیت اشتراک و امکانات هرکدام.</p>
    </div>
    <div class="o-actions">
        <a class="o-btn o-btn-primary" href="<?= $e($url('/owner/customers/create')) ?>">افزودن مشتری</a>
    </div>
</div>

<section class="o-card">
    <form method="get" action="<?= $e($url('/owner/customers')) ?>" class="o-filters">
        <label class="o-field">
            <span>جستجو</span>
            <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="نام، شرکت، ایمیل، تلفن">
        </label>

        <label class="o-field">
            <span>وضعیت مشتری</span>
            <select name="status" data-autosubmit>
                <option value="">همه</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="suspended" <?= $filters['status'] === 'suspended' ? 'selected' : '' ?>>غیرفعال</option>
                <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>آرشیو</option>
            </select>
        </label>

        <label class="o-field">
            <span>وضعیت اشتراک</span>
            <select name="subscription" data-autosubmit>
                <option value="">همه</option>
                <?php foreach ($statuses as $key => $title): ?>
                    <option value="<?= $e($key) ?>" <?= $filters['subscription'] === $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                <?php endforeach; ?>
                <option value="expiring" <?= $filters['subscription'] === 'expiring' ? 'selected' : '' ?>>در آستانه انقضا (۷ روز)</option>
            </select>
        </label>

        <label class="o-field">
            <span>پلن</span>
            <select name="plan" data-autosubmit>
                <option value="">همه</option>
                <?php foreach ($plans as $key => $title): ?>
                    <option value="<?= $e($key) ?>" <?= $filters['plan'] === $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="o-field">
            <span>ترتیب</span>
            <select name="sort" data-autosubmit>
                <option value="id DESC" <?= $filters['sort'] === 'id DESC' ? 'selected' : '' ?>>جدیدترین</option>
                <option value="name ASC" <?= $filters['sort'] === 'name ASC' ? 'selected' : '' ?>>نام (الفبا)</option>
                <option value="subscription_expire_date ASC" <?= $filters['sort'] === 'subscription_expire_date ASC' ? 'selected' : '' ?>>نزدیک‌ترین انقضا</option>
                <option value="status ASC" <?= $filters['sort'] === 'status ASC' ? 'selected' : '' ?>>وضعیت</option>
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
        <h2>فهرست مشتریان<span class="o-sub"><?= $e($listing['total']) ?> مشتری</span></h2>
        <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers')) ?>">حذف فیلترها</a>
    </div>

    <?php if ($rows === []): ?>
        <div class="o-empty"><strong>مشتری‌ای با این فیلترها یافت نشد</strong>فیلترها را تغییر دهید یا مشتری جدید بسازید.</div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>مشتری</th>
                    <th>مدیر</th>
                    <th>تماس</th>
                    <th>وضعیت</th>
                    <th>اشتراک</th>
                    <th>امکانات</th>
                    <th>پرسنل / دستگاه</th>
                    <th>تاریخ ایجاد</th>
                    <th>عملیات</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $customer): ?>
                    <?php
                    $logoUrl = \App\Support\Url::customerLogo($customer);
                    $subKey  = (string) $customer['effective_subscription_status'];
                    $subBadge = in_array($subKey, ['active'], true) ? 'o-badge-ok'
                        : (in_array($subKey, ['trial'], true) ? 'o-badge-info'
                        : (in_array($subKey, ['expired'], true) ? 'o-badge-bad' : 'o-badge-warn'));
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <?php if ($logoUrl !== null): ?>
                                    <img class="o-logo" src="<?= $e($logoUrl) ?>" alt="لوگو">
                                <?php else: ?>
                                    <span class="o-logo-fallback"><?= $e(mb_substr((string) $customer['name'], 0, 2, 'UTF-8')) ?></span>
                                <?php endif; ?>
                                <span>
                                    <a href="<?= $e($url('/owner/customers/' . $customer['uuid'])) ?>"><?= $e($customer['name']) ?></a>
                                    <small class="o-ltr"><?= $e($customer['slug']) ?></small>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php if ((int) $customer['manager_count'] > 0): ?>
                                <?= $e($customer['manager_count']) ?> مدیر
                            <?php else: ?>
                                <span class="o-badge o-badge-warn">بدون مدیر</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $e($customer['contact_phone'] ?? '—') ?>
                            <small><?= $e($customer['contact_email'] ?? '') ?></small>
                        </td>
                        <td>
                            <span class="o-badge <?= $customer['status'] === 'active' ? 'o-badge-ok' : 'o-badge-bad' ?>">
                                <?= $customer['status'] === 'active' ? 'فعال' : ($customer['status'] === 'suspended' ? 'غیرفعال' : 'آرشیو') ?>
                            </span>
                        </td>
                        <td>
                            <span class="o-badge <?= $subBadge ?>"><?= $e(\App\Models\Subscription::statusTitles()[$subKey] ?? $subKey) ?></span>
                            <small><?= $e($customer['subscription_expire_date'] ?? '—') ?></small>
                        </td>
                        <td><span class="o-badge o-badge-violet"><?= $e($customer['feature_count']) ?> فعال</span></td>
                        <td>
                            <?= $e($customer['employee_count']) ?> / <?= $e($customer['device_count']) ?>
                            <small>آنلاین: <?= $e($customer['online_count']) ?></small>
                        </td>
                        <td><span class="o-small"><?= $e($customer['created_at']) ?></span></td>
                        <td>
                            <div class="o-actions">
                                <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers/' . $customer['uuid'])) ?>">جزئیات</a>
                                <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers/' . $customer['uuid'] . '/edit')) ?>">ویرایش</a>
                                <form method="post" action="<?= $e($url('/owner/customers/' . $customer['uuid'] . '/status')) ?>" class="o-inline-form"
                                      data-confirm="<?= $customer['status'] === 'active' ? 'این مشتری غیرفعال شود؟ کاربران و دستگاه‌های آن قطع می‌شوند.' : 'این مشتری فعال شود؟' ?>">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="status" value="<?= $customer['status'] === 'active' ? 'suspended' : 'active' ?>">
                                    <button type="submit" class="o-btn o-btn-sm <?= $customer['status'] === 'active' ? 'o-btn-danger' : 'o-btn-ok' ?>">
                                        <?= $customer['status'] === 'active' ? 'غیرفعال' : 'فعال' ?>
                                    </button>
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
                '_path'        => '/owner/customers',
                'q'            => $filters['q'],
                'status'       => $filters['status'],
                'subscription' => $filters['subscription'],
                'plan'         => $filters['plan'],
                'sort'         => $filters['sort'],
            ],
        ]) ?>
    <?php endif; ?>
</section>
