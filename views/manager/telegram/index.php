<?php
/**
 * Manager - telegram (stage 6 readiness -> stage 7 real sending).
 *
 * The bot token is NEVER printed here: the panel only shows the 4 character
 * hint (************1234) and a field to replace it.
 *
 * @var string $chatId
 * @var array<string,array{label:string,enabled:bool}> $notifications
 * @var bool $configured
 * @var string|null $lastSaved
 * @var array<string,mixed> $engine
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Support\Jalali;

$settings = $engine['settings'];
?>
<div class="page-head">
    <div>
        <h1>ربات تلگرام</h1>
        <p class="muted small">
            اعلان‌های حضور و غیاب و گزارش روزانه از طریق ربات اختصاصی همین مجموعه ارسال می‌شود.
            ثبت حضور هرگز منتظر تلگرام نمی‌ماند: پیام در صف قرار می‌گیرد، تلاش مجدد می‌شود و خطا در همین صفحه دیده می‌شود.
        </p>
    </div>
    <div class="row-actions">
        <span class="badge <?= $engine['usable'] ? '' : 'badge-muted' ?>">
            <?= $engine['usable'] ? 'آماده ارسال' : 'غیرفعال / تنظیم‌نشده' ?>
        </span>
    </div>
</div>

<section class="kpi-grid">
    <article class="kpi"><div class="kpi-top"><span class="kpi-label">در صف ارسال</span></div><div class="kpi-value num"><?= $e((string) $engine['queueStats']['pending']) ?></div></article>
    <article class="kpi"><div class="kpi-top"><span class="kpi-label">ارسال‌شده</span></div><div class="kpi-value num"><?= $e((string) $engine['queueStats']['sent']) ?></div></article>
    <article class="kpi"><div class="kpi-top"><span class="kpi-label">ناموفق</span></div><div class="kpi-value num"><?= $e((string) $engine['queueStats']['failed']) ?></div></article>
    <article class="kpi"><div class="kpi-top"><span class="kpi-label">رویدادهای در انتظار</span></div><div class="kpi-value num"><?= $e((string) $engine['eventPending']) ?></div></article>
</section>

<section class="grid-2">
    <article class="card">
        <div class="card-head"><h2>تنظیمات اتصال</h2></div>

        <p class="small">
            توکن ربات:
            <?php if ($engine['hasToken']): ?>
                <span class="ltr num"><?= $e($engine['tokenMasked']) ?></span>
                <span class="badge">ذخیره‌شده و رمزنگاری‌شده</span>
            <?php else: ?>
                <span class="badge badge-muted">ثبت نشده</span>
            <?php endif; ?>
        </p>
        <?php if ($engine['lastOk'] || $engine['lastError']): ?>
            <p class="muted small">
                <?php if ($engine['lastOk']): ?>آخرین ارسال موفق: <?= $e(Jalali::date((string) $engine['lastOk'], true)) ?><?php endif; ?>
                <?php if ($engine['lastError']): ?> — آخرین خطا: <?= $e((string) $engine['lastError']) ?><?php endif; ?>
            </p>
        <?php endif; ?>

        <form method="post" action="<?= $e($url('/manager/telegram')) ?>">
            <input type="hidden" name="_token" value="<?= $csrf() ?>">
            <div class="filters">
                <label class="field">
                    <span>Bot Token (برای تغییر پر کنید)</span>
                    <input type="password" name="bot_token" class="ltr" autocomplete="off" placeholder="123456789:AA...">
                    <span class="field-hint">توکن در دیتابیس رمزنگاری می‌شود، در HTML/JS/URL نمایش داده نمی‌شود و در لاگ‌ها ثبت نمی‌شود.</span>
                </label>
                <label class="field">
                    <span>Chat ID</span>
                    <input type="text" name="telegram_chat_id" class="ltr" value="<?= $e($chatId) ?>" placeholder="-1001234567890">
                </label>
                <label class="field">
                    <span>ساعت گزارش روزانه</span>
                    <input type="time" name="daily_report_time" class="ltr" value="<?= $e($engine['dailyTime']) ?>">
                </label>
                <label class="field check">
                    <input type="checkbox" name="is_active" value="1" <?= $engine['isActive'] ? 'checked' : '' ?>>
                    <span>ربات فعال باشد</span>
                </label>
            </div>

            <h3 class="small">اعلان‌ها</h3>
            <div class="filters">
                <label class="field check"><input type="checkbox" name="telegram_entry_alert" value="1" <?= ($notifications['telegram_entry_alert'] ?? ['enabled' => $engine['notify']['entry']])['enabled'] || $engine['notify']['entry'] ? 'checked' : '' ?>><span>اعلان ورود</span></label>
                <label class="field check"><input type="checkbox" name="telegram_exit_alert" value="1" <?= $engine['notify']['exit'] ? 'checked' : '' ?>><span>اعلان خروج</span></label>
                <label class="field check"><input type="checkbox" name="notify_late" value="1" <?= $engine['notify']['late'] ? 'checked' : '' ?>><span>اعلان تأخیر</span></label>
                <label class="field check"><input type="checkbox" name="notify_overtime" value="1" <?= $engine['notify']['overtime'] ? 'checked' : '' ?>><span>اعلان اضافه‌کاری</span></label>
                <label class="field check"><input type="checkbox" name="daily_report" value="1" <?= $engine['notify']['daily'] ? 'checked' : '' ?>><span>گزارش روزانه (ساعت بالا)</span></label>
            </div>

            <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
        </form>

        <div class="row-actions" style="margin-top:12px">
            <form method="post" action="<?= $e($url('/manager/telegram/test')) ?>">
                <input type="hidden" name="_token" value="<?= $csrf() ?>">
                <button type="submit" class="btn btn-ghost btn-sm">ارسال پیام آزمایشی</button>
            </form>
            <form method="post" action="<?= $e($url('/manager/telegram/daily')) ?>">
                <input type="hidden" name="_token" value="<?= $csrf() ?>">
                <input type="hidden" name="date" value="<?= $e(date('Y-m-d')) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">ارسال گزارش امروز</button>
            </form>
            <form method="post" action="<?= $e($url('/manager/telegram/drain')) ?>">
                <input type="hidden" name="_token" value="<?= $csrf() ?>">
                <button type="submit" class="btn btn-ghost btn-sm">پردازش صف (Worker)</button>
            </form>
        </div>
        <p class="muted small">
            ارسال خودکار توسط Cron انجام می‌شود:
            <span class="ltr">*​/1 * * * * php tools/telegram_worker.php</span>
        </p>
    </article>

    <article class="card">
        <div class="card-head"><h2>رویدادها و صف ارسال</h2></div>
        <?php if ($engine['queue'] === []): ?>
            <p class="muted small">صف ارسال خالی است.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>رویداد</th><th>وضعیت</th><th>تلاش</th><th>زمان</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($engine['queue'], 0, 12) as $row): ?>
                        <tr>
                            <td class="ltr small"><?= $e((string) $row['event']) ?></td>
                            <td>
                                <span class="badge <?= (string) $row['status'] === 'failed' ? 'badge-warn' : '' ?>"><?= $e((string) $row['status']) ?></span>
                                <?php if (($row['last_error'] ?? null) !== null): ?>
                                    <div class="muted small"><?= $e((string) $row['last_error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $e((string) (int) $row['attempts']) ?></td>
                            <td class="num small"><?= $e(Jalali::date((string) $row['created_at'], true)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h3 class="small">آخرین رویدادهای حضور</h3>
        <?php if ($engine['events'] === []): ?>
            <p class="muted small">رویدادی ثبت نشده است.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach (array_slice($engine['events'], 0, 10) as $event): ?>
                    <li class="list-row">
                        <span class="ltr small"><?= $e((string) $event['event']) ?></span>
                        <span class="muted small">
                            <?= $e((string) $event['status']) ?>
                            — <?= $e(Jalali::date((string) $event['created_at'], true)) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
</section>

<section class="card">
    <div class="card-head"><h2>لاگ ارسال‌ها</h2><span class="badge">توکن ربات هرگز در لاگ ذخیره نمی‌شود</span></div>
    <?php if ($engine['logs'] === []): ?>
        <p class="muted small">هنوز ارسالی ثبت نشده است.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>رویداد</th><th>Chat</th><th>وضعیت</th><th>کد خطا</th><th>پیام</th><th>زمان</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($engine['logs'], 0, 20) as $log): ?>
                    <tr>
                        <td class="ltr small"><?= $e((string) $log['event']) ?></td>
                        <td class="ltr small num"><?= $e((string) ($log['chat_id'] ?? '')) ?></td>
                        <td>
                            <span class="badge <?= (string) $log['status'] === 'failed' ? 'badge-warn' : '' ?>"><?= $e((string) $log['status']) ?></span>
                        </td>
                        <td class="ltr small"><?= $e((string) ($log['error_code'] ?? '')) ?></td>
                        <td class="small muted"><?= $e((string) ($log['error_message'] ?? '')) ?></td>
                        <td class="num small"><?= $e(Jalali::date((string) $log['created_at'], true)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
