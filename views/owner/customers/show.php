<?php
/**
 * Owner - customer detail page: identity, managers, devices, features,
 * subscription, employees and recent audit, all in one screen.
 *
 * @var array<string,mixed> $customer
 * @var array<string,int> $stats
 * @var array<string,mixed> $counters
 * @var array<int,array<string,mixed>> $managersFull
 * @var array<int,array<string,mixed>> $branches
 * @var array<int,array<string,mixed>> $devices
 * @var array<int,array<string,mixed>> $features
 * @var array<int,array<string,mixed>> $audit
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$uuid     = (string) $customer['uuid'];
$subBadge = in_array($subStatus, ['active'], true) ? 'o-badge-ok'
    : (in_array($subStatus, ['trial'], true) ? 'o-badge-info'
    : (in_array($subStatus, ['expired'], true) ? 'o-badge-bad' : 'o-badge-warn'));

$enabledFeatures = array_values(array_filter($features, static fn (array $f): bool => (bool) $f['enabled']));
?>
<div class="o-page-head">
    <div style="display:flex;align-items:center;gap:14px">
        <?php if ($logoUrl !== null): ?>
            <img src="<?= $e($logoUrl) ?>" alt="لوگو" style="width:56px;height:56px;border-radius:14px;object-fit:cover;border:1px solid var(--o-line)">
        <?php else: ?>
            <span class="o-brand-mark" style="width:56px;height:56px;border-radius:16px"><?= $e(mb_substr((string) $customer['name'], 0, 2, 'UTF-8')) ?></span>
        <?php endif; ?>
        <div>
            <h1><?= $e($customer['name']) ?></h1>
            <p>
                <span class="o-ltr"><?= $e($customer['slug']) ?></span> —
                <span class="o-badge <?= $customer['status'] === 'active' ? 'o-badge-ok' : 'o-badge-bad' ?>"><?= $customer['status'] === 'active' ? 'فعال' : 'غیرفعال' ?></span>
                <span class="o-badge <?= $subBadge ?>">اشتراک: <?= $e(\App\Models\Subscription::statusTitles()[$subStatus] ?? $subStatus) ?></span>
            </p>
        </div>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/customers/' . $uuid . '/edit')) ?>">ویرایش</a>
        <a class="o-btn" href="<?= $e($url('/owner/customers/' . $uuid . '/features')) ?>">امکانات</a>
        <a class="o-btn" href="<?= $e($url('/owner/subscriptions/' . $uuid)) ?>">اشتراک</a>
        <a class="o-btn o-btn-warn" href="<?= $e($url('/owner/customers/' . $uuid . '/emergency')) ?>">ورود اضطراری</a>
        <form method="post" action="<?= $e($url('/owner/customers/' . $uuid . '/status')) ?>" class="o-inline-form"
              data-confirm="<?= $customer['status'] === 'active' ? 'مشتری غیرفعال شود؟ کاربران و دستگاه‌های آن قطع می‌شوند.' : 'مشتری فعال شود؟' ?>">
            <?= $csrf() ?>
            <input type="hidden" name="status" value="<?= $customer['status'] === 'active' ? 'suspended' : 'active' ?>">
            <button type="submit" class="o-btn <?= $customer['status'] === 'active' ? 'o-btn-danger' : 'o-btn-ok' ?>">
                <?= $customer['status'] === 'active' ? 'غیرفعال‌سازی مشتری' : 'فعال‌سازی مشتری' ?>
            </button>
        </form>
    </div>
</div>

<div class="o-kpis">
    <div class="o-kpi"><span class="o-kpi-label">پرسنل</span><span class="o-kpi-value"><?= $e($stats['employees']) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">دستگاه‌ها</span><span class="o-kpi-value"><?= $e($stats['devices']) ?></span>
        <span class="o-kpi-extra"><span>آنلاین: <b><?= $e($counters['online_count']) ?></b></span></span></div>
    <div class="o-kpi"><span class="o-kpi-label">شعب</span><span class="o-kpi-value"><?= $e($stats['branches']) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">مدیران</span><span class="o-kpi-value"><?= $e($stats['managers']) ?></span></div>
    <div class="o-kpi"><span class="o-kpi-label">کاربران</span><span class="o-kpi-value"><?= $e($stats['users']) ?></span></div>
    <div class="o-kpi <?= $daysLeft !== null && $daysLeft <= 7 ? 'is-warn' : 'is-ok' ?>">
        <span class="o-kpi-label">روزهای باقی‌مانده اشتراک</span>
        <span class="o-kpi-value"><?= $e($daysLeft === null ? '∞' : $daysLeft) ?></span>
        <span class="o-kpi-extra"><span>انقضا: <b><?= $e($customer['subscription_expire_date'] ?? '—') ?></b></span></span>
    </div>
</div>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head"><h2>اطلاعات مشتری</h2></div>
        <div class="o-kv">
            <div><span>نام شرکت</span><strong><?= $e($customer['company_name'] ?? '—') ?></strong></div>
            <div><span>موبایل</span><strong class="o-ltr"><?= $e($customer['contact_phone'] ?? '—') ?></strong></div>
            <div><span>ایمیل</span><strong class="o-ltr"><?= $e($customer['contact_email'] ?? '—') ?></strong></div>
            <div><span>آدرس</span><strong><?= $e($customer['address'] ?? '—') ?></strong></div>
            <div><span>منطقه زمانی</span><strong class="o-ltr"><?= $e($customer['timezone']) ?></strong></div>
            <div><span>تقویم</span><strong><?= $e($customer['date_system'] === 'jalali' ? 'شمسی' : 'میلادی') ?></strong></div>
            <div><span>تاریخ ایجاد</span><strong><?= $e($customer['created_at']) ?></strong></div>
            <div><span>پلن / پلن‌اشتراک</span><strong><?= $e(\App\Models\Subscription::planTitles()[$customer['plan_name'] ?? 'trial'] ?? ($customer['plan_name'] ?? '—')) ?></strong></div>
            <div><span>سقف پرسنل / دستگاه</span><strong><?= $e($customer['max_employees']) ?> / <?= $e($customer['max_devices']) ?></strong></div>
            <div><span>شناسه یکتا</span><strong class="o-ltr"><?= $e($uuid) ?></strong></div>
        </div>
    </section>

    <section class="o-card">
        <div class="o-card-head">
            <h2>امکانات فعال<span class="o-sub"><?= $e(count($enabledFeatures)) ?> از <?= $e(count($features)) ?> مورد فعال است</span></h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers/' . $uuid . '/features')) ?>">مدیریت امکانات</a>
        </div>

        <div class="o-chips">
            <?php foreach ($features as $feature): ?>
                <span class="o-chip <?= $feature['enabled'] ? 'is-active' : '' ?>">
                    <?= $e($feature['title_fa']) ?>
                    <b><?= $feature['enabled'] ? 'فعال' : 'غیرفعال' ?></b>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="o-mt o-small o-muted">
            امکاناتی که ماژول آن‌ها در این مرحله آماده نیست با برچسب «مرحله بعد» نمایش داده می‌شوند؛ وضعیت فعال/غیرفعال آن‌ها همین حالا در بک‌اند اعمال می‌شود.
        </div>
        <div class="o-chips">
            <?php foreach ($features as $feature): ?>
                <?php if (!$feature['module_ready']): ?>
                    <span class="o-chip"><?= $e($feature['title_fa']) ?> <b>مرحله بعد</b></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head">
            <h2>مدیران مشتری</h2>
            <a class="o-btn o-btn-sm o-btn-primary" href="<?= $e($url('/owner/managers/create?customer_id=' . $customer['id'])) ?>">افزودن مدیر</a>
        </div>

        <?php if ($managersFull['rows'] === []): ?>
            <div class="o-empty"><strong>مدیری ثبت نشده است</strong>تا زمانی که مدیر نسازید، این مشتری نمی‌تواند وارد پنل شود.</div>
        <?php else: ?>
            <div class="o-table-wrap">
                <table class="o-table" style="min-width:560px">
                    <thead><tr><th>نام</th><th>نام کاربری</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($managersFull['rows'] as $manager): ?>
                        <tr>
                            <td><?= $e($manager['name']) ?><small><?= $e($manager['email'] ?? '') ?></small></td>
                            <td class="o-ltr"><?= $e($manager['username'] ?? '—') ?></td>
                            <td>
                                <span class="o-badge <?= !empty($manager['user_active']) ? 'o-badge-ok' : 'o-badge-bad' ?>">
                                    <?= !empty($manager['user_active']) ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td class="o-small"><?= $e($manager['last_login_at'] ?? '—') ?></td>
                            <td><a class="o-btn o-btn-sm" href="<?= $e($url('/owner/managers/' . $manager['id'] . '/edit')) ?>">مدیریت</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="o-card">
        <div class="o-card-head">
            <h2>دستگاه‌ها<span class="o-sub">۲۰ دستگاه آخر</span></h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/devices?customer_id=' . $customer['id'])) ?>">همه دستگاه‌ها</a>
        </div>

        <?php if ($devices === []): ?>
            <div class="o-empty"><strong>دستگاهی ثبت نشده است</strong></div>
        <?php else: ?>
            <ul class="o-timeline">
                <?php foreach ($devices as $device): ?>
                    <li>
                        <span class="o-dot <?= \App\Models\Device::isOnline($device) ? 'o-dot-on' : 'o-dot-off' ?>"></span>
                        <span class="o-what">
                            <strong><?= $e($device['name']) ?></strong>
                            <small class="o-ltr"><?= $e($device['device_uid']) ?></small>
                            <small>وضعیت: <?= $e($device['status']) ?> — آخرین تماس: <?= $e($device['last_seen_at'] ?? '—') ?></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="o-card">
        <div class="o-card-head"><h2>شعب<span class="o-sub"><?= $e(count($branches)) ?> شعبه</span></h2></div>
        <?php if ($branches === []): ?>
            <div class="o-empty"><strong>شعبه‌ای ثبت نشده است</strong>اگر امکان «شعب» فعال باشد، مدیر مشتری می‌تواند شعبه بسازد.</div>
        <?php else: ?>
            <div class="o-table-wrap">
                <table class="o-table" style="min-width:460px">
                    <thead><tr><th>نام</th><th>کد</th><th>پرسنل</th><th>دستگاه</th><th>وضعیت</th></tr></thead>
                    <tbody>
                    <?php foreach ($branches as $branch): ?>
                        <tr>
                            <td><?= $e($branch['name']) ?><small><?= $e($branch['city'] ?? '') ?></small></td>
                            <td class="o-ltr"><?= $e($branch['code']) ?></td>
                            <td><?= $e($branch['employee_count'] ?? 0) ?></td>
                            <td><?= $e($branch['device_count'] ?? 0) ?></td>
                            <td><span class="o-badge <?= !empty($branch['is_active']) ? 'o-badge-ok' : 'o-badge-bad' ?>"><?= !empty($branch['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="o-card">
        <div class="o-card-head">
            <h2>اشتراک</h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/subscriptions/' . $uuid)) ?>">تغییر اشتراک</a>
        </div>
        <div class="o-kv">
            <div><span>پلن</span><strong><?= $e($subscription['plan_name'] ?? ($customer['plan_name'] ?? '—')) ?></strong></div>
            <div><span>وضعیت</span><strong><?= $e(\App\Models\Subscription::statusTitles()[$subStatus] ?? $subStatus) ?></strong></div>
            <div><span>شروع</span><strong><?= $e($subscription['start_date'] ?? ($customer['subscription_start_date'] ?? '—')) ?></strong></div>
            <div><span>پایان</span><strong><?= $e($subscription['expire_date'] ?? ($customer['subscription_expire_date'] ?? '—')) ?></strong></div>
        </div>

        <div class="o-table-wrap o-mt">
            <table class="o-table" style="min-width:520px">
                <thead><tr><th>تاریخ ثبت</th><th>پلن</th><th>وضعیت</th><th>شروع</th><th>پایان</th><th>توسط</th></tr></thead>
                <tbody>
                <?php foreach ($subHistory as $row): ?>
                    <tr>
                        <td class="o-small"><?= $e($row['created_at']) ?></td>
                        <td><?= $e($row['plan_name']) ?></td>
                        <td><span class="o-badge"><?= $e(\App\Models\Subscription::statusTitles()[(string) $row['status']] ?? $row['status']) ?></span></td>
                        <td class="o-ltr"><?= $e($row['start_date'] ?? '—') ?></td>
                        <td class="o-ltr"><?= $e($row['expire_date'] ?? '—') ?></td>
                        <td class="o-small"><?= $e($row['actor_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head">
            <h2>آخرین رویدادهای این مشتری</h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/logs?customer_id=' . $customer['id'])) ?>">همه رویدادها</a>
        </div>

        <?php if ($audit === []): ?>
            <div class="o-empty"><strong>رویدادی ثبت نشده است</strong></div>
        <?php else: ?>
            <ul class="o-timeline">
                <?php foreach ($audit as $row): ?>
                    <li>
                        <span class="o-time"><?= $e($row['created_at']) ?></span>
                        <span class="o-what">
                            <strong><?= $e(\App\Services\AuditService::titleFor((string) ($row['action'] ?? \App\Services\AuditService::actionFor((string) $row['event'])))) ?></strong>
                            <small><?= $e($row['description'] ?? '') ?> — <?= $e($row['actor_label'] ?? '') ?></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="o-card">
        <div class="o-card-head">
            <h2>دسترسی اضطراری این مشتری<span class="o-sub">ورودهای مالک به پنل این مشتری</span></h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/emergency')) ?>">همه سوابق</a>
        </div>

        <?php if ($impersonation === []): ?>
            <div class="o-empty"><strong>سابقه‌ای ثبت نشده است</strong>هر ورود اضطراری اینجا با دلیل، زمان و IP ثبت می‌شود.</div>
        <?php else: ?>
            <ul class="o-timeline">
                <?php foreach ($impersonation as $row): ?>
                    <li>
                        <span class="o-time"><?= $e($row['started_at']) ?></span>
                        <span class="o-what">
                            <strong>ورود مالک (<?= $e($row['owner_name'] ?? '—') ?>)</strong>
                            <small>دلیل: <?= $e($row['reason']) ?></small>
                            <small>پایان: <?= $e($row['ended_at'] ?? 'در جریان') ?> — اقدامات: <?= $e($row['actions_count']) ?> — IP: <span class="o-ltr"><?= $e($row['ip'] ?? '') ?></span></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
