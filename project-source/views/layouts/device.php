<?php
/**
 * SARMAD Attendance - KIOSK shell layout (stage 4).
 *
 * This layout is used ONLY by the attendance device pages. It is intentionally
 * independent from the owner/manager layouts:
 *   - no navigation to any panel (a device user can never reach /owner or /manager)
 *   - no session user data, no CSRF meta for privileged actions, no token in HTML
 *   - installable: manifest + service worker + standalone display + theming
 *   - offline capable: the shell is cached by sw.js, data is never cached
 *
 * @var string $content
 * @var callable $e
 * @var callable $asset
 * @var callable $url
 * @var string $pageTitle
 * @var string $base        application base path ('' or '/subdir')
 * @var string $manifestUrl
 * @var string $swUrl
 * @var string $apiBase
 * @var int    $heartbeat
 * @var string $appVersion
 * @var string $deviceState  unactivated | home | blocked (server rendered default)
 */
$pageTitle = $pageTitle ?? 'SARMAD Attendance';
$deviceState = $deviceState ?? 'unactivated';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title><?= $e($pageTitle) ?></title>
    <meta name="description" content="دستگاه ثبت حضور و غیاب SARMAD">
    <meta name="theme-color" content="#070b16">
    <meta name="color-scheme" content="dark">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SARMAD Attendance">
    <meta name="application-name" content="SARMAD Attendance">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <link rel="manifest" href="<?= $e($manifestUrl) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $e($asset('/icons/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= $e($asset('/icons/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= $e($asset('/css/device.css')) ?>">
</head>
<body class="kiosk">
<div id="kiosk"
     data-api="<?= $e($apiBase) ?>"
     data-base="<?= $e($base) ?>"
     data-sw="<?= $e($swUrl) ?>"
     data-manifest="<?= $e($manifestUrl) ?>"
     data-heartbeat="<?= (int) $heartbeat ?>"
     data-version="<?= $e($appVersion) ?>"
     data-state="<?= $e($deviceState) ?>"
     data-offline-shell="<?= $deviceState === 'offline' ? '1' : '0' ?>">

    <?php /* The offline shell is already a screen of its own: it needs no boot overlay. */ ?>
    <?php if ($deviceState !== 'offline'): ?>
        <div class="k-boot" id="kiosk-boot">
            <div class="k-boot-inner">
                <div class="k-activate-logo">SA</div>
                <div class="k-spinner"></div>
                <p>SARMAD Attendance</p>
            </div>
        </div>
    <?php endif; ?>

    <?= $content ?>
</div>

<?php /* Stage 5 engine: all local, all same-origin, no CDN. */ ?>
<script src="<?= $e($asset('/vendor/tfjs-1.7.4.min.js')) ?>" defer></script>
<script src="<?= $e($asset('/vendor/face-api-0.22.2.min.js')) ?>" defer></script>
<script src="<?= $e($asset('/js/face/face-engine.js')) ?>" defer></script>
<script src="<?= $e($asset('/js/face/face-store.js')) ?>" defer></script>
<script src="<?= $e($asset('/js/device-queue.js')) ?>" defer></script>
<script src="<?= $e($asset('/js/device.js')) ?>" defer></script>
</body>
</html>
