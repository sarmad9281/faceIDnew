<?php
/**
 * SARMAD Attendance - owner panel layout (dark, responsive, RTL).
 *
 * @var string   $content
 * @var string   $title
 * @var string   $active  active sidebar key
 * @var callable $e
 * @var callable $asset
 * @var callable $url
 * @var callable $csrf
 * @var callable $csrfMeta
 * @var callable $session
 * @var callable $flash
 */
$user         = $session()->user();
$active       = $active ?? '';
$platformName = (string) \App\Models\Setting::get('platform.name', 'SARMAD Attendance');

$nav = [
    ['key' => 'dashboard',     'title' => 'داشبورد',        'href' => '/owner',               'icon' => 'grid'],
    ['key' => 'customers',     'title' => 'مشتریان',        'href' => '/owner/customers',     'icon' => 'users'],
    ['key' => 'managers',      'title' => 'مدیران',         'href' => '/owner/managers',      'icon' => 'user-tie'],
    ['key' => 'employees',     'title' => 'پرسنل',          'href' => '/owner/employees',     'icon' => 'id-card'],
    ['key' => 'devices',       'title' => 'دستگاه‌ها',      'href' => '/owner/devices',       'icon' => 'tablet'],
    ['key' => 'features',      'title' => 'امکانات',        'href' => '/owner/features',      'icon' => 'sliders'],
    ['key' => 'subscriptions', 'title' => 'اشتراک‌ها',      'href' => '/owner/subscriptions', 'icon' => 'card'],
    ['key' => 'logs',          'title' => 'رویدادها',       'href' => '/owner/logs',          'icon' => 'list'],
    ['key' => 'emergency',     'title' => 'دسترسی اضطراری', 'href' => '/owner/emergency',     'icon' => 'shield'],
    ['key' => 'settings',      'title' => 'تنظیمات',        'href' => '/owner/settings',      'icon' => 'cog'],
];

$initials = 'M';

if (is_array($user) && !empty($user['name'])) {
    $parts    = preg_split('/\s+/u', trim((string) $user['name'])) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_substr($part, 0, 1, 'UTF-8');
    }
    $initials = $initials !== '' ? $initials : 'M';
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#070b14">
    <?= $csrfMeta() ?>
    <title><?= $e($title ?? 'پنل مالک') ?> | <?= $e($platformName) ?></title>
    <link rel="stylesheet" href="<?= $e($asset('css/owner.css')) ?>">
</head>
<body class="o-body">

<!-- Inline SVG sprite (no external icon font, works offline) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <defs>
        <symbol id="i-grid" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/></symbol>
        <symbol id="i-users" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M16 18v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM21 18v-1a4 4 0 0 0-3-3.87M16.5 4.2a3 3 0 0 1 0 5.6"/></symbol>
        <symbol id="i-user-tie" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM6 21v-2a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v2M10 13l2 3 2-3"/></symbol>
        <symbol id="i-id-card" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M3 6h18v12H3zM8 12a2 2 0 1 0 0-3.5A2 2 0 0 0 8 12zM5.5 16c.4-1.3 1.4-2 2.5-2s2.1.7 2.5 2M14 10h4M14 13h4M14 16h2"/></symbol>
        <symbol id="i-tablet" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M6 3h12v18H6zM11 18h2"/></symbol>
        <symbol id="i-sliders" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M4 7h10M18 7h2M4 17h4M12 17h8M14 4v6M8 14v6"/></symbol>
        <symbol id="i-card" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M3 6h18v12H3zM3 10h18M6 14h4"/></symbol>
        <symbol id="i-list" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></symbol>
        <symbol id="i-shield" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6zM9 12l2 2 4-4"/></symbol>
        <symbol id="i-cog" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19 12c0-.6-.1-1.1-.2-1.6l2-1.5-2-3.4-2.3 1a7 7 0 0 0-2.7-1.5L13.5 2h-3l-.3 2.5A7 7 0 0 0 7.5 6l-2.3-1-2 3.4 2 1.5a7 7 0 0 0 0 3.2l-2 1.5 2 3.4 2.3-1a7 7 0 0 0 2.7 1.5L10.5 22h3l.3-2.5a7 7 0 0 0 2.7-1.5l2.3 1 2-3.4-2-1.5c.1-.5.2-1 .2-1.6z"/></symbol>
        <symbol id="i-logout" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M15 12H4M8 8l-4 4 4 4M13 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4"/></symbol>
    </defs>
</svg>

<div class="o-shell">

    <aside class="o-sidebar" id="o-sidebar">
        <div class="o-brand">
            <span class="o-brand-mark">SA</span>
            <span class="o-brand-text">
                <strong><?= $e($platformName) ?></strong>
                <small>پنل مالک سامانه</small>
            </span>
        </div>

        <nav class="o-nav">
            <span class="o-nav-title">مدیریت سامانه</span>
            <?php foreach ($nav as $item): ?>
                <?php if ($item['key'] === 'emergency'): ?>
                    <span class="o-nav-title">امنیت</span>
                <?php endif; ?>
                <a href="<?= $e($url($item['href'])) ?>" class="<?= $active === $item['key'] ? 'is-active' : '' ?>">
                    <svg class="ico"><use href="#i-<?= $e($item['icon']) ?>"></use></svg>
                    <span><?= $e($item['title']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="o-backdrop" id="o-backdrop"></div>

    <div class="o-main">
        <header class="o-header">
            <button type="button" class="o-burger" id="o-burger" aria-label="منو">☰</button>
            <div>
                <h1><?= $e($title ?? 'پنل مالک') ?></h1>
                <span class="o-sub">مالک سامانه — دسترسی کامل به مشتریان، امکانات و دستگاه‌ها</span>
            </div>

            <div class="o-userbox o-right">
                <span class="o-avatar"><?= $e($initials) ?></span>
                <span class="o-userbox-meta">
                    <strong><?= $e($user['name'] ?? 'مالک') ?></strong>
                    <small><?= $e($user['username'] ?? '') ?></small>
                </span>
                <form method="post" action="<?= $e($url('/logout')) ?>" class="o-inline-form">
                    <?= $csrf() ?>
                    <button type="submit" class="o-btn o-btn-ghost o-btn-sm">
                        <svg class="ico" width="16" height="16"><use href="#i-logout"></use></svg>
                        خروج
                    </button>
                </form>
            </div>
        </header>

        <main class="o-content">
            <?php if ($message = $flash('success')): ?>
                <div class="o-alert o-alert-success"><?= $e($message) ?></div>
            <?php endif; ?>
            <?php if ($message = $flash('error')): ?>
                <div class="o-alert o-alert-error"><?= $e($message) ?></div>
            <?php endif; ?>

            <?= $content ?>
        </main>

        <footer class="o-footer">
            <span>SARMAD Attendance v<?= $e(\App\Core\Config::get('app.version', '1.0.0')) ?> — مرحله ۲ (پنل مالک)</span>
            <span>پیشوند جداول: <code><?= $e(\App\Core\Database::prefix()) ?></code></span>
            <span>PHP <?= $e(PHP_VERSION) ?></span>
        </footer>
    </div>
</div>

<script src="<?= $e($asset('js/owner.js')) ?>" defer></script>
</body>
</html>
