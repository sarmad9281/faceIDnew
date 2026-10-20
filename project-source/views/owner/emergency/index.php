<?php
/**
 * Owner - emergency access overview + full history.
 *
 * @var array<int,array<string,mixed>> $open
 * @var array<int,array<string,mixed>> $history
 * @var bool $enabled
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
?>
<div class="o-page-head">
    <div>
        <h1>دسترسی اضطراری</h1>
        <p>
            اگر مشتری در استفاده از پنل دچار مشکل شد، می‌توانید با ثبت دلیل وارد پنل او شوید.
            این کار کاملاً ثبت می‌شود: شناسه مالک، مشتری، مدیر، زمان ورود و خروج، مدت، تعداد اقدامات و IP.
        </p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/customers')) ?>">انتخاب مشتری</a>
        <a class="o-btn" href="<?= $e($url('/owner/logs?action=EMERGENCY_ACCESS')) ?>">رویدادهای دسترسی اضطراری</a>
    </div>
</div>

<?php if (!$enabled): ?>
    <div class="o-alert o-alert-warn">دسترسی اضطراری در تنظیمات سامانه غیرفعال است؛ تا فعال‌سازی، امکان ورود به پنل مشتری وجود ندارد.</div>
<?php endif; ?>

<div class="o-alert o-alert-info">
    <strong>رمز عبور مدیر هرگز نمایش داده نمی‌شود.</strong>
    رمزها فقط به صورت هش ذخیره می‌شوند و هیچ صفحه‌ای آن‌ها را بازنمی‌گرداند؛ حتی مالک سامانه. اگر رمزی لازم است، از
    «بازنشانی رمز عبور» در صفحه مدیر استفاده کنید.
</div>

<section class="o-card">
    <div class="o-card-head"><h2>نشست‌های باز<span class="o-sub">ورودهای اضطراری که هنوز بسته نشده‌اند</span></h2></div>

    <?php if ($open === []): ?>
        <div class="o-empty"><strong>نشست بازی وجود ندارد</strong></div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table" style="min-width:620px">
                <thead><tr><th>مشتری</th><th>مالک</th><th>دلیل</th><th>شروع</th><th>اقدامات</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($open as $row): ?>
                    <tr>
                        <td><?= $e($row['customer_name'] ?? '—') ?></td>
                        <td><?= $e($row['owner_name'] ?? '—') ?></td>
                        <td><?= $e($row['reason']) ?></td>
                        <td class="o-small"><?= $e($row['started_at']) ?></td>
                        <td><?= $e($row['actions_count']) ?></td>
                        <td class="o-ltr o-small"><?= $e($row['ip'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="o-card">
    <div class="o-card-head"><h2>سوابق کامل<span class="o-sub">۵۰ رکورد آخر</span></h2></div>

    <?php if ($history === []): ?>
        <div class="o-empty"><strong>هنوز از دسترسی اضطراری استفاده نشده است</strong></div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>مشتری</th>
                    <th>مالک</th>
                    <th>دلیل</th>
                    <th>شروع</th>
                    <th>پایان</th>
                    <th>مدت (ثانیه)</th>
                    <th>اقدامات</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $row): ?>
                    <?php
                    $duration = null;

                    if (!empty($row['ended_at'])) {
                        $duration = max(0, (int) strtotime((string) $row['ended_at']) - (int) strtotime((string) $row['started_at']));
                    }
                    ?>
                    <tr>
                        <td><?= $e($row['customer_name'] ?? '—') ?></td>
                        <td><?= $e($row['owner_name'] ?? '—') ?></td>
                        <td style="max-width:300px"><?= $e($row['reason']) ?></td>
                        <td class="o-small"><?= $e($row['started_at']) ?></td>
                        <td class="o-small">
                            <?= $e($row['ended_at'] ?? '—') ?>
                            <?php if (empty($row['ended_at'])): ?><span class="o-badge o-badge-warn">در جریان</span><?php endif; ?>
                        </td>
                        <td><?= $e($duration === null ? '—' : $duration) ?></td>
                        <td><?= $e($row['actions_count']) ?></td>
                        <td class="o-ltr o-small"><?= $e($row['ip'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
