<?php
/**
 * Minimal owner overview.
 *
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 * @var array $customers
 * @var array $totals
 * @var string $search
 * @var array $roleCounts
 * @var array $recentAudit
 */
?>
<section class="page-head">
    <div>
        <h1>پنل مالک سامانه</h1>
        <p class="muted">نمای کلی مشتریان و وضعیت سامانه (نسخه مرحله اول).</p>
    </div>
    <a class="btn btn-primary" href="<?= $e($url('/owner/customers/create')) ?>">افزودن مشتری</a>
</section>

<section class="stats">
    <?php
    $cards = [
        ['label' => 'مشتریان', 'value' => $totals['customers'] ?? 0],
        ['label' => 'کاربران', 'value' => $totals['users'] ?? 0],
        ['label' => 'دستگاه‌ها', 'value' => $totals['devices'] ?? 0],
        ['label' => 'کارکنان', 'value' => $totals['employees'] ?? 0],
    ];

    foreach ($cards as $card) {
        $label = $card['label'];
        $value = $card['value'];
        require dirname(__DIR__) . '/partials/stat.php';
    }
    ?>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h2>مشتریان</h2>
            <form method="get" action="<?= $e($url('/owner')) ?>" class="inline-form">
                <input type="text" name="q" value="<?= $e($search) ?>" placeholder="جستجو…" maxlength="80">
                <button class="btn btn-ghost" type="submit">جستجو</button>
            </form>
        </div>

        <?php if ($customers === []): ?>
            <p class="muted">مشتری‌ای ثبت نشده است.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr><th>نام</th><th>شناسه (UUID)</th><th>وضعیت</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td>
                            <?= $e($customer['name']) ?>
                            <small class="muted"><?= $e($customer['slug']) ?></small>
                        </td>
                        <td class="ltr small"><?= $e($customer['uuid']) ?></td>
                        <td>
                            <span class="badge badge-<?= $e($customer['status'] === 'active' ? 'ok' : 'muted') ?>">
                                <?= $e($customer['status']) ?>
                            </span>
                        </td>
                        <td>
                            <a class="btn btn-ghost" href="<?= $e($url('/owner/customers/show?customer=' . urlencode((string) $customer['uuid']))) ?>">جزئیات</a>
                            <a class="btn btn-ghost" href="<?= $e($url('/devices?customer=' . urlencode((string) $customer['uuid']))) ?>">دستگاه‌ها</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>آخرین رویدادهای سامانه</h2></div>
        <?php if ($recentAudit === []): ?>
            <p class="muted">رویدادی ثبت نشده است.</p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($recentAudit as $row): ?>
                    <li>
                        <span class="badge badge-<?= $e($row['result'] === 'success' ? 'ok' : 'error') ?>"><?= $e($row['result']) ?></span>
                        <strong><?= $e($row['event']) ?></strong>
                        <small class="muted">
                            actor: <?= $e($row['actor_label'] ?? '—') ?> — <?= $e($row['created_at']) ?>
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
