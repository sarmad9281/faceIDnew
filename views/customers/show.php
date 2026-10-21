<?php
/**
 * @var callable $e
 * @var callable $url
 * @var array $customer
 * @var array $stats
 * @var array $managers
 * @var array $branches
 * @var array $devices
 * @var array $audit
 */
?>
<section class="page-head">
    <div>
        <h1><?= $e($customer['name']) ?></h1>
        <p class="muted ltr"><?= $e($customer['uuid']) ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= $e($url('/owner/customers')) ?>">بازگشت</a>
</section>

<section class="stats">
    <?php
    foreach ([
        ['label' => 'کارکنان', 'value' => $stats['employees'] ?? 0],
        ['label' => 'شعبه‌ها', 'value' => $stats['branches'] ?? 0],
        ['label' => 'دستگاه‌ها', 'value' => $stats['devices'] ?? 0],
        ['label' => 'کاربران', 'value' => $stats['users'] ?? 0],
    ] as $card) {
        $label = $card['label'];
        $value = $card['value'];
        require dirname(__DIR__) . '/partials/stat.php';
    }
    ?>
</section>

<div class="grid-2">
    <section class="card">
        <h2>مدیران</h2>
        <?php if ($managers === []): ?>
            <p class="muted">مدیری ثبت نشده است.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>نام</th><th>ایمیل</th><th>وضعیت</th></tr></thead>
                <tbody>
                <?php foreach ($managers as $manager): ?>
                    <tr>
                        <td><?= $e($manager['name']) ?></td>
                        <td class="ltr"><?= $e($manager['email'] ?? '—') ?></td>
                        <td><?= (int) $manager['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>دستگاه‌ها</h2>
        <?php if ($devices === []): ?>
            <p class="muted">دستگاهی ثبت نشده است.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>نام</th><th>وضعیت</th><th>آخرین ارتباط</th></tr></thead>
                <tbody>
                <?php foreach ($devices as $device): ?>
                    <tr>
                        <td><?= $e($device['name']) ?></td>
                        <td><?= $e($device['status']) ?></td>
                        <td class="ltr"><?= $e($device['last_seen_at'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <h2>رویدادهای اخیر این مشتری</h2>
    <?php if ($audit === []): ?>
        <p class="muted">رویدادی ثبت نشده است.</p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($audit as $row): ?>
                <li>
                    <span class="badge badge-<?= $e($row['result'] === 'success' ? 'ok' : 'error') ?>"><?= $e($row['result']) ?></span>
                    <strong><?= $e($row['event']) ?></strong>
                    <small class="muted"><?= $e($row['created_at']) ?> — <?= $e($row['actor_label'] ?? '') ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
