<?php
/**
 * SARMAD Attendance - bare layout (login / error pages).
 *
 * @var string      $content
 * @var string|null $title
 * @var callable    $e
 * @var callable    $asset
 */
$title = $title ?? 'SARMAD Attendance';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= $csrfMeta() ?>
    <title><?= $e($title) ?> | SARMAD Attendance</title>
    <link rel="stylesheet" href="<?= $e($asset('css/app.css')) ?>">
</head>
<body class="body-blank">
    <main class="blank-wrap">
        <?= $content ?>
    </main>
</body>
</html>
