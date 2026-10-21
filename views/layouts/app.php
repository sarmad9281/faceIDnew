<?php
/**
 * SARMAD Attendance - authenticated shell (manager / supervisor / owner).
 *
 * Premium dark sidebar layout: the navigation is built from the features the
 * customer really owns, but that is only cosmetics - every module is also
 * gated in the routes and in the controllers, so a hidden menu entry is not
 * the security boundary.
 *
 * @var string   $content
 * @var string   $title
 * @var callable $e
 * @var callable $asset
 * @var callable $csrf
 * @var callable $csrfMeta
 * @var callable $url
 * @var callable $session
 */

use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Customer;
use App\Services\FeatureService;
use App\Services\ImpersonationService;

$currentUser  = $session()->user();
$role         = (string) $session()->role();
$impersonation = ImpersonationService::current();

$customerId = TenantContext::customerId();
$navFlags   = $customerId !== null ? FeatureService::navFlags() : [];
$customer   = $customerId !== null ? Customer::find($customerId) : null;
$has        = static fn (string $key): bool => !empty($navFlags[$key]);

$path    = Request::capture()->path();
$is      = static function (string $prefix) use ($path): bool {
    if ($prefix === '/dashboard') {
        return $path === '/' || $path === '/dashboard';
    }

    return strpos($path, $prefix) === 0;
};

$logoPath = trim((string) ($customer['logo_path'] ?? ''));
$logoUrl  = $logoPath !== '' ? \App\Support\Url::upload(ltrim($logoPath, '/')) : '';
$roleTitles = [
    'owner'      => 'مالک سامانه',
    'manager'    => 'مدیر کسب‌وکار',
    'supervisor' => 'سرپرست',
    'device'     => 'دستگاه',
];
$ownerMode = $role === 'owner' && $impersonation === null;

/**
 * @var array<int,array{title:string,items:array<int,array{label:string,href:string,icon:string,badge?:string}>}> $groups
 */
if ($ownerMode) {
    $groups = [
        ['title' => 'پنل مالک', 'items' => [
            ['label' => 'نمای کلی', 'href' => '/owner', 'icon' => 'grid'],
            ['label' => 'مشتریان', 'href' => '/owner/customers', 'icon' => 'building'],
            ['label' => 'مدیران', 'href' => '/owner/managers', 'icon' => 'users'],
            ['label' => 'پرسنل', 'href' => '/owner/employees', 'icon' => 'user'],
            ['label' => 'دستگاه‌ها', 'href' => '/owner/devices', 'icon' => 'device'],
        ]],
        ['title' => 'سامانه', 'items' => [
            ['label' => 'امکانات و اشتراک', 'href' => '/owner/features', 'icon' => 'sliders'],
            ['label' => 'اشتراک‌ها', 'href' => '/owner/subscriptions', 'icon' => 'card'],
            ['label' => 'رویدادها', 'href' => '/owner/logs', 'icon' => 'activity'],
            ['label' => 'تنظیمات مالک', 'href' => '/owner/settings', 'icon' => 'cog'],
        ]],
    ];
} else {
    $groups = [
        ['title' => 'مدیریت روزانه', 'items' => array_values(array_filter([
            ['label' => 'داشبورد', 'href' => '/dashboard', 'icon' => 'grid'],
            $has('employees') ? ['label' => 'پرسنل', 'href' => '/manager/employees', 'icon' => 'users'] : null,
            $has('shifts') ? ['label' => 'شیفت‌ها', 'href' => '/manager/shifts', 'icon' => 'clock'] : null,
            $has('attendance') ? ['label' => 'حضور و غیاب', 'href' => '/manager/attendance', 'icon' => 'check'] : null,
            $has('employees') ? ['label' => 'مرخصی‌ها', 'href' => '/manager/leaves', 'icon' => 'calendar'] : null,
            $has('attendance') ? ['label' => 'تعطیلات', 'href' => '/manager/holidays', 'icon' => 'sun'] : null,
        ]))],
        ['title' => 'تحلیل و پیکربندی', 'items' => array_values(array_filter([
            $has('reports') ? ['label' => 'گزارش‌ها', 'href' => '/manager/reports', 'icon' => 'chart'] : null,
            $has('payroll') ? ['label' => 'حقوق و دستمزد', 'href' => '/manager/payroll', 'icon' => 'card'] : null,
            $has('branches') ? ['label' => 'شعب و واحدها', 'href' => '/manager/branches', 'icon' => 'building'] : null,
            $has('telegram') ? ['label' => 'ربات تلگرام', 'href' => '/manager/telegram', 'icon' => 'send'] : null,
        ]))],
        ['title' => 'سیستم', 'items' => [
            ['label' => 'دستگاه‌ها', 'href' => '/manager/devices', 'icon' => 'device'],
            ['label' => 'رویدادها', 'href' => '/manager/activity', 'icon' => 'activity'],
            ['label' => 'تنظیمات کسب‌وکار', 'href' => '/manager/settings', 'icon' => 'cog'],
            ['label' => 'امکانات فعال', 'href' => '/manager/settings/features', 'icon' => 'sliders'],
            ['label' => 'تغییر رمز عبور', 'href' => '/account/password', 'icon' => 'lock'],
        ]],
    ];
}

/** Tiny inline SVG icon set - no CDN, no external font. */
$icons = [
    'grid'     => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
    'users'    => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 9a7 7 0 0 0-14 0Zm3-9a3 3 0 1 0-2-5.2M17 20a5.9 5.9 0 0 0-1.7-4.1A3.9 3.9 0 0 1 20 20Z"/>',
    'user'     => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0Z"/>',
    'clock'    => '<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm.9 4.6v4.2l3.1 1.8-.9 1.6-4-2.3V7.6h1.8Z"/>',
    'check'    => '<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-1 12.4-3.5-3.5 1.4-1.4L11 12.6l4.6-4.6L17 9.4Z"/>',
    'calendar' => '<path d="M7 2v3M17 2v3M3.5 8.5h17M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm3 8h3v3H8z"/>',
    'sun'      => '<path d="M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0-15v3m0 14v3M2 12h3m14 0h3M4.9 4.9l2.1 2.1m10 10 2.1 2.1M19.1 4.9 17 7m-10 10-2.1 2.1"/>',
    'chart'    => '<path d="M4 20V4h2v14h14v2Zm4-3V9h3v8Zm5 0V6h3v11Zm5 0v-6h3v6Z"/>',
    'card'     => '<path d="M3 6h18v12H3zM3 10h18M6.5 14.5h4"/>',
    'building' => '<path d="M4 21V4h10v17M14 9h6v12M7 8h2m-2 4h2m-2 4h2m6-5h2m-2 4h2"/>',
    'device'   => '<path d="M6 3h9a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3 14h3"/>',
    'send'     => '<path d="M3 11.5 21 3l-8.5 18-2.4-7.1L3 11.5Z"/>',
    'activity' => '<path d="M3 12h4l2.5-7 4 14L16 12h5"/>',
    'cog'      => '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm8.5-3.5a8.5 8.5 0 0 0-.2-1.8l2-1.5-2-3.4-2.3 1a8.5 8.5 0 0 0-3-1.8L14.6 2H9.4L9 4.5a8.5 8.5 0 0 0-3 1.8l-2.3-1-2 3.4 2 1.5a8.6 8.6 0 0 0 0 3.6l-2 1.5 2 3.4 2.3-1a8.5 8.5 0 0 0 3 1.8l.4 2.5h5.2l.4-2.5a8.5 8.5 0 0 0 3-1.8l2.3 1 2-3.4-2-1.5c.1-.6.2-1.2.2-1.8Z"/>',
    'sliders'  => '<path d="M4 7h10m4 0h2M4 17h4m4 0h8M15.5 4.5v5M9.5 14.5v5"/>',
    'lock'     => '<path d="M6 11h12v9H6zM9 11V8a3 3 0 0 1 6 0v3"/>',
];
$icon = static function (string $key) use ($icons): string {
    return '<svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$key] ?? '') . '</svg>';
};
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= $csrfMeta() ?>
    <title><?= $e($title ?? 'داشبورد') ?> | SARMAD Attendance</title>
    <link rel="stylesheet" href="<?= $e($asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= $e($asset('css/manager.css')) ?>">
    <?php if ($ownerMode || $impersonation !== null): ?>
        <link rel="stylesheet" href="<?= $e($asset('css/owner.css')) ?>">
    <?php endif; ?>
</head>
<body class="mng">
<div class="m-shell">
    <aside class="m-sidebar" id="m-sidebar" aria-label="منوی اصلی">
        <div class="m-brand">
            <span class="m-brand-mark">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= $e($logoUrl) ?>" alt="لوگو">
                <?php else: ?>SA<?php endif; ?>
            </span>
            <span class="m-brand-text">
                <strong><?= $e($customer['name'] ?? 'SARMAD Attendance') ?></strong>
                <small><?= $e($ownerMode ? 'پنل مالک سامانه' : 'سامانه حضور و غیاب') ?></small>
            </span>
        </div>

        <nav class="m-nav">
            <?php foreach ($groups as $group): ?>
                <?php if ($group['items'] === []) { continue; } ?>
                <div class="m-nav-group">
                    <span class="m-nav-title"><?= $e($group['title']) ?></span>
                    <?php foreach ($group['items'] as $item): ?>
                        <a class="m-nav-link<?= $is($item['href']) ? ' is-active' : '' ?>" href="<?= $e($url($item['href'])) ?>">
                            <?= $icon((string) $item['icon']) ?>
                            <span><?= $e($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!$ownerMode && !$has('branches') && !$has('payroll') && !$has('telegram')): ?>
                <p class="m-nav-hint">امکانات اختیاری (شعب، حقوق و دستمزد، تلگرام) توسط مالک سامانه فعال می‌شوند.</p>
            <?php endif; ?>
        </nav>

        <div class="m-sidebar-foot">
            <div class="m-actor">
                <span class="m-avatar"><?= $e(mb_substr((string) ($currentUser['name'] ?? 'کاربر'), 0, 1, 'UTF-8')) ?></span>
                <span class="m-actor-meta">
                    <strong><?= $e($currentUser['name'] ?? 'کاربر') ?></strong>
                    <small><?= $e($roleTitles[$role] ?? $role) ?></small>
                </span>
            </div>
            <form method="post" action="<?= $e($url('/logout')) ?>" class="m-logout">
                <?= $csrf() ?>
                <button type="submit" class="btn btn-ghost btn-block">خروج از حساب</button>
            </form>
        </div>
    </aside>

    <div class="m-backdrop" id="m-backdrop" hidden></div>

    <div class="m-main">
        <header class="m-topbar">
            <button type="button" class="m-burger" id="m-burger" aria-label="منو" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
            <div class="m-topbar-title">
                <h1><?= $e($title ?? 'داشبورد') ?></h1>
                <small><?= $e($customer['name'] ?? 'سامانه حضور و غیاب') ?></small>
            </div>
            <div class="m-topbar-actions">
                <?php if ($customerId !== null && !$ownerMode): ?>
                    <span class="m-chip">شناسه کسب‌وکار: <?= $e($customerId) ?></span>
                <?php endif; ?>
                <?php if (($title ?? '') !== 'تغییر رمز عبور'): ?>
                    <a class="btn btn-ghost" href="<?= $e($url('/account/password')) ?>">رمز عبور</a>
                <?php endif; ?>
            </div>
        </header>

        <main class="m-content">
            <?php if ($impersonation !== null): ?>
                <div class="alert alert-error">
                    <strong>شما در حالت دسترسی اضطراری هستید.</strong>
                    مشتری: <?= $e($impersonation['customer_name'] ?? '') ?> —
                    دلیل: <?= $e($impersonation['reason'] ?? '') ?>
                    <form method="post" action="<?= $e($url('/emergency/exit')) ?>" class="inline-form" style="margin-top:8px">
                        <?= $csrf() ?>
                        <button type="submit" class="btn btn-primary">خروج از دسترسی اضطراری</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($message = $flash('success')): ?>
                <div class="alert alert-success"><?= $e($message) ?></div>
            <?php endif; ?>
            <?php if ($message = $flash('error')): ?>
                <div class="alert alert-error"><?= $e($message) ?></div>
            <?php endif; ?>

            <?= $content ?>
        </main>

        <footer class="m-footer">
            <span>SARMAD Attendance v<?= $e(\App\Core\Config::get('app.version', '1.0.0')) ?></span>
            <span>پیشوند جداول: <code><?= $e(\App\Core\Database::prefix()) ?></code></span>
        </footer>
    </div>
</div>

<script src="<?= $e($asset('js/manager.js')) ?>" defer></script>
</body>
</html>
