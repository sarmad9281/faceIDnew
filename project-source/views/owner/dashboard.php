<?php
/**
 * Owner dashboard - real counters + newest activity.
 *
 * @var array<string,mixed> $data
 * @var array<int,string> $pending pending migrations
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$c      = $data['counters'];
$sub    = $data['subscription'];
$totals = $data['totals'];
$recent = $data['recent'];
$env    = $data['environment'];
?>
<?php if ($pending !== []): ?>
    <div class="o-alert o-alert-warn">
        <strong>ساختار دیتابیس به‌روز نیست.</strong>
        <?= $e(count($pending)) ?> تغییر ساختاری در انتظار اجراست (جداول/ستون‌های مرحله ۲).
        <form method="post" action="<?= $e($url('/owner/settings/migrate')) ?>" class="o-inline-form" data-confirm="به‌روزرسانی ساختار دیتابیس اجرا شود؟">
            <?= $csrf() ?>
            <button type="submit" class="o-btn o-btn-warn o-btn-sm">اجرای به‌روزرسانی ساختار</button>
        </form>
    </div>
<?php endif; ?>

<div class="o-page-head">
    <div>
        <h1>داشبورد مالک سامانه</h1>
        <p>نمای کامل سامانه: مشتریان، مدیران، پرسنل، دستگاه‌ها و اشتراک‌ها — همه اعداد از دیتابیس خوانده می‌شوند.</p>
    </div>
    <div class="o-actions">
        <a class="o-btn o-btn-primary" href="<?= $e($url('/owner/customers/create')) ?>">افزودن مشتری</a>
        <a class="o-btn" href="<?= $e($url('/owner/devices')) ?>">مدیریت دستگاه‌ها</a>
    </div>
</div>

<div class="o-kpis">
    <div class="o-kpi">
        <span class="o-kpi-label">کل مشتریان</span>
        <span class="o-kpi-value"><?= $e(number_format($c['customers_total'])) ?></span>
        <span class="o-kpi-extra">
            <span>فعال: <b><?= $e($c['customers_active']) ?></b></span>
            <span>غیرفعال: <b><?= $e($c['customers_inactive']) ?></b></span>
        </span>
    </div>

    <div class="o-kpi is-ok">
        <span class="o-kpi-label">مشتریان فعال</span>
        <span class="o-kpi-value"><?= $e($c['customers_active']) ?></span>
        <span class="o-kpi-extra">
            <span>معلق: <b><?= $e($c['customers_suspended']) ?></b></span>
            <span>آرشیو: <b><?= $e(max(0, $c['customers_total'] - $c['customers_active'] - $c['customers_suspended'])) ?></b></span>
        </span>
    </div>

    <div class="o-kpi is-warn">
        <span class="o-kpi-label">مشتریان غیرفعال</span>
        <span class="o-kpi-value"><?= $e($c['customers_inactive']) ?></span>
        <span class="o-kpi-extra">
            <span>اشتراک منقضی: <b><?= $e($sub['expired']) ?></b></span>
            <span>تا ۷ روز: <b><?= $e($sub['expiring_7']) ?></b></span>
        </span>
    </div>

    <div class="o-kpi">
        <span class="o-kpi-label">مدیران</span>
        <span class="o-kpi-value"><?= $e($c['managers_total']) ?></span>
        <span class="o-kpi-extra">
            <span>فعال: <b><?= $e($c['managers_active']) ?></b></span>
            <span>غیرفعال: <b><?= $e(max(0, $c['managers_total'] - $c['managers_active'])) ?></b></span>
        </span>
    </div>

    <div class="o-kpi">
        <span class="o-kpi-label">پرسنل</span>
        <span class="o-kpi-value"><?= $e($c['employees_total']) ?></span>
        <span class="o-kpi-extra">
            <span>فعال: <b><?= $e($c['employees_active']) ?></b></span>
            <span>شعب: <b><?= $e($c['branches_total']) ?></b></span>
        </span>
    </div>

    <div class="o-kpi">
        <span class="o-kpi-label">دستگاه‌ها</span>
        <span class="o-kpi-value"><?= $e($c['devices_total']) ?></span>
        <span class="o-kpi-extra">
            <span>آنلاین: <b><?= $e($totals['devices_online']) ?></b></span>
            <span>خاموش: <b><?= $e($totals['devices_offline']) ?></b></span>
        </span>
    </div>

    <div class="o-kpi is-ok">
        <span class="o-kpi-label">دستگاه‌های فعال</span>
        <span class="o-kpi-value"><?= $e($c['devices_active']) ?></span>
        <span class="o-kpi-extra">
            <span>در انتظار فعال‌سازی: <b><?= $e($c['devices_pending']) ?></b></span>
        </span>
    </div>

    <div class="o-kpi is-warn">
        <span class="o-kpi-label">دستگاه‌های غیرفعال</span>
        <span class="o-kpi-value"><?= $e($c['devices_disabled'] + $c['devices_revoked']) ?></span>
        <span class="o-kpi-extra">
            <span>غیرفعال: <b><?= $e($c['devices_disabled']) ?></b></span>
            <span>لغو شده: <b><?= $e($c['devices_revoked']) ?></b></span>
        </span>
    </div>
</div>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head">
            <h2>آخرین مشتریان ثبت‌شده<span class="o-sub">۵ مشتری تازه‌ترین</span></h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/customers')) ?>">همه مشتریان</a>
        </div>

        <?php if ($recent['customers'] === []): ?>
            <div class="o-empty"><strong>هنوز مشتری‌ای ثبت نشده است</strong>با دکمه «افزودن مشتری» شروع کنید.</div>
        <?php else: ?>
            <div class="o-table-wrap">
                <table class="o-table" style="min-width:520px">
                    <thead>
                    <tr>
                        <th>مشتری</th>
                        <th>وضعیت</th>
                        <th>اشتراک</th>
                        <th>پرسنل</th>
                        <th>دستگاه</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent['customers'] as $customer): ?>
                        <tr>
                            <td>
                                <a href="<?= $e($url('/owner/customers/' . $customer['uuid'])) ?>"><?= $e($customer['name']) ?></a>
                                <small><?= $e($customer['created_at']) ?></small>
                            </td>
                            <td>
                                <span class="o-badge <?= $customer['status'] === 'active' ? 'o-badge-ok' : 'o-badge-bad' ?>">
                                    <?= $customer['status'] === 'active' ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td>
                                <span class="o-badge o-badge-info"><?= $e(\App\Models\Subscription::planTitles()[$customer['plan_name'] ?? 'trial'] ?? ($customer['plan_name'] ?? 'trial')) ?></span>
                                <small><?= $e(\App\Models\Subscription::statusTitles()[$customer['effective_subscription_status']] ?? $customer['effective_subscription_status']) ?></small>
                            </td>
                            <td><?= $e($customer['employee_count']) ?></td>
                            <td><?= $e($customer['device_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="o-card">
        <div class="o-card-head">
            <h2>آخرین ورود مدیران<span class="o-sub">۶ ورود اخیر</span></h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/managers')) ?>">همه مدیران</a>
        </div>

        <?php if ($recent['manager_logins'] === []): ?>
            <div class="o-empty"><strong>ورودی ثبت نشده است</strong>پس از اولین ورود مدیران، اینجا نمایش داده می‌شود.</div>
        <?php else: ?>
            <ul class="o-timeline">
                <?php foreach ($recent['manager_logins'] as $login): ?>
                    <li>
                        <span class="o-time"><?= $e($login['last_login_at']) ?></span>
                        <span class="o-what">
                            <strong><?= $e($login['name']) ?></strong>
                            <small><?= $e($login['customer_name'] ?? '—') ?> — نام کاربری: <?= $e($login['username'] ?? '') ?> — IP: <span class="o-ltr"><?= $e($login['last_login_ip'] ?? '') ?></span></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="o-card">
        <div class="o-card-head">
            <h2>آخرین دستگاه‌های آنلاین<span class="o-sub">آستانه آفلاین: <?= $e($offlineAfter) ?> ثانیه</span></h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/devices')) ?>">همه دستگاه‌ها</a>
        </div>

        <?php if ($recent['devices_online'] === []): ?>
            <div class="o-empty"><strong>دستگاهی متصل نشده است</strong>دستگاه‌ها پس از ثبت و فعال‌سازی اینجا دیده می‌شوند.</div>
        <?php else: ?>
            <ul class="o-timeline">
                <?php foreach ($recent['devices_online'] as $device): ?>
                    <?php $online = \App\Models\Device::isOnline($device, $offlineAfter); ?>
                    <li>
                        <span class="o-dot <?= $online ? 'o-dot-on' : 'o-dot-off' ?>"></span>
                        <span class="o-what">
                            <strong><?= $e($device['name']) ?></strong>
                            <small><?= $e($device['customer_name'] ?? '—') ?> — آخرین تماس: <?= $e($device['last_seen_at'] ?? '—') ?>
                                <?= $online ? '<b style="color:#86efac">آنلاین</b>' : 'آفلاین' ?></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="o-card">
        <div class="o-card-head">
            <h2>آخرین فعالیت‌های سامانه<span class="o-sub">از گزارش رویدادها</span></h2>
            <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/logs')) ?>">همه رویدادها</a>
        </div>

        <?php if ($recent['activity'] === []): ?>
            <div class="o-empty"><strong>رویدادی ثبت نشده است</strong></div>
        <?php else: ?>
            <ul class="o-timeline">
                <?php foreach ($recent['activity'] as $row): ?>
                    <?php
                    $action = (string) ($row['action'] ?? \App\Services\AuditService::actionFor((string) $row['event']));
                    $badge  = $row['result'] === 'success' ? 'o-badge-ok' : ($row['result'] === 'denied' ? 'o-badge-warn' : 'o-badge-bad');
                    ?>
                    <li>
                        <span class="o-time"><?= $e($row['created_at']) ?></span>
                        <span class="o-what">
                            <strong><span class="o-badge <?= $badge ?>"><?= $e(\App\Services\AuditService::titleFor($action)) ?></span></strong>
                            <small><?= $e($row['description'] ?? $row['event']) ?><?= !empty($row['customer_name']) ? ' — ' . $e($row['customer_name']) : '' ?></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head"><h2>وضعیت اشتراک‌ها</h2></div>
        <?php
        $subRows = [
            'آزمایشی'    => (int) $sub['trial'],
            'فعال'       => (int) $sub['active'],
            'منقضی'      => (int) $sub['expired'],
            'معلق'       => (int) $sub['suspended'],
        ];
        $subMax = max(1, max(array_values($subRows)));
        ?>
        <div class="o-bars">
            <?php foreach ($subRows as $label => $value): ?>
                <div class="o-bar-row">
                    <span class="o-bar-label"><?= $e($label) ?></span>
                    <span class="o-bar-track"><span class="o-bar-fill" style="width: <?= $e((int) round(($value / $subMax) * 100)) ?>%"></span></span>
                    <span class="o-bar-value"><?= $e($value) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="o-mt o-small o-muted">
            قراردادهای در آستانه انقضا (۷ روز): <b><?= $e($sub['expiring_7']) ?></b> — امکانات فعال‌شده در کل سامانه: <b><?= $e($totals['features_enabled']) ?></b>
        </div>
    </section>

    <section class="o-card">
        <div class="o-card-head"><h2>محیط اجرا</h2></div>
        <div class="o-kv">
            <div><span>نسخه PHP</span><strong class="o-ltr"><?= $e($env['php']) ?></strong></div>
            <div><span>دیتابیس</span><strong class="o-ltr"><?= $e($env['database']) ?></strong></div>
            <div><span>جداول پروژه</span><strong><?= $e($env['tables']) ?> جدول با پیشوند <code><?= $e($env['prefix']) ?></code></strong></div>
            <div><span>آستانه آفلاین دستگاه</span><strong><?= $e($env['offline_after']) ?> ثانیه</strong></div>
        </div>
    </section>
</div>
