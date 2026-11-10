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
 * @var array<string,mixed>|null $dailyPreview   stage 23: text the bot would send
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Support\Jalali;
use App\Support\JalaliHint;

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
        <?php if (!empty($sharedGatewayConfigured)): ?>
            <span class="badge">درگاه ارتباطی تلگرام: فعال (سرور خارجی / بدون فیلتر)</span>
            <small class="muted">درگاه تنظیم شده است؛ اتصال را با پیام آزمایشی بررسی کنید.</small>
        <?php endif; ?>
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
            <?= $csrf() ?>
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

        <?php if (!$engine['hasToken'] || empty($engine['settings']['chat_id'])): ?>
            <p class="alert alert-warn">لطفاً ابتدا توکن ربات و شناسه چت را ذخیره کنید، سپس پیام آزمایشی ارسال نمایید.</p>
        <?php else: ?>
            <p class="muted small">پیام آزمایشی با تنظیمات ذخیره‌شده ارسال می‌شود؛ تغییرات جدید را ابتدا ذخیره کنید.</p>
        <?php endif; ?>
        <div class="row-actions" style="margin-top:12px">
            <form method="post" action="<?= $e($url('/manager/telegram/test')) ?>">
                <?= $csrf() ?>
                <button type="submit" class="btn btn-ghost btn-sm">ارسال پیام آزمایشی</button>
            </form>
            <form method="post" action="<?= $e($url('/manager/telegram/daily')) ?>">
                <?= $csrf() ?>
                <input type="hidden" name="date" value="<?= $e(date('Y-m-d')) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">ارسال فوری گزارش امروز به تلگرام</button>
            </form>
            <form method="post" action="<?= $e($url('/manager/telegram/drain')) ?>">
                <?= $csrf() ?>
                <button type="submit" class="btn btn-ghost btn-sm">پردازش صف (Worker)</button>
            </form>
        </div>
        <p class="muted small">
            ارسال خودکار توسط Cron انجام می‌شود:
            <span class="ltr">*​/1 * * * * php tools/telegram_worker.php</span>
        </p>
    </article>

    <?php /* =================================================================
     * STAGE 23 - گزارش روزانه: exactly what the bot writes, with the NAMES
     * of the absent, late and on-leave people. Nothing is sent from here; the
     * preview is built by TelegramScheduler::previewFor(), the same code the
     * worker runs, and the send button stays the one above.
     * ================================================================== */ ?>
    <article class="card">
        <div class="card-head">
            <h2>گزارش روزانه</h2>
            <span class="badge"><?= $e(Jalali::date((string) ($dailyPreview['date'] ?? date('Y-m-d')))) ?></span>
        </div>

        <form method="get" action="<?= $e($url('/manager/telegram')) ?>">
            <div class="filters">
                <label class="field">
                    <span>تاریخ پیش‌نمایش</span>
                    <input type="date" name="preview_date" class="ltr" value="<?= $e((string) ($dailyPreview['date'] ?? date('Y-m-d'))) ?>"><?= JalaliHint::render(($dailyPreview['date'] ?? date('Y-m-d'))) ?>
                </label>
                <div class="field"><button type="submit" class="btn btn-ghost btn-block">نمایش پیش‌نمایش</button></div>
            </div>
        </form>

        <?php if (!empty($dailyPreview['ok'])): ?>
            <?php
            $summary = (array) ($dailyPreview['summary'] ?? []);
            ?>
            <div class="table-wrap" style="margin-top:10px">
                <table class="table">
                    <thead><tr><th>پرسنل</th><th>حاضر</th><th>غایب</th><th>مرخصی</th><th>تأخیر</th><th>داخل مجموعه</th></tr></thead>
                    <tbody>
                        <tr class="num">
                            <td><?= $e((string) (int) ($summary['employees'] ?? 0)) ?></td>
                            <td><?= $e((string) (int) ($summary['present'] ?? 0)) ?></td>
                            <td><?= $e((string) count((array) ($summary['absent_names'] ?? []))) ?></td>
                            <td><?= $e((string) count((array) ($summary['on_leave_names'] ?? []))) ?></td>
                            <td><?= $e((string) count((array) ($summary['late_names'] ?? []))) ?></td>
                            <td><?= $e((string) count((array) ($summary['in_progress'] ?? []))) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3 class="small" style="margin-top:12px">
                متن ارسالی
                <span class="badge"><?= $e((string) (int) ($dailyPreview['chars'] ?? 0)) ?> نویسه</span>
                <?php if (!empty($dailyPreview['truncated'])): ?>
                    <span class="badge badge-warn">کوتاه‌شده برای تلگرام</span>
                <?php endif; ?>
            </h3>

            <?php /* STAGE 24 - contrast fix. This box asked for --surface-2 and
                       --border, which NO stylesheet of this project defines, so the
                       light fallbacks (#f6f7f9 / #e3e6ea) always won while the text
                       inherited the dark theme's near-white --m-text: white on
                       white. The real tokens are the --m-* ones from manager.css and
                       the fallbacks are dark now too, so the box stays readable even
                       when the stylesheet is cached from an older release or fails to
                       load: 13.5:1 with the tokens, 14.4:1 with the fallbacks, both
                       AAA. "ltr-reset" was invented as well and defined nowhere, so
                       it is gone. */ ?>
            <pre dir="rtl" style="white-space:pre-wrap;overflow-wrap:anywhere;background:var(--m-surface-2,#151b30);color:var(--m-text,#e8ecf8);border:1px solid var(--m-line-strong,rgba(255,255,255,.16));border-radius:var(--m-radius-sm,12px);padding:12px 14px;margin-top:6px;font-family:inherit;font-size:.86rem;line-height:1.9"><?= $e((string) ($dailyPreview['message'] ?? '')) ?></pre>

            <p class="muted small" style="margin-top:8px">
                این متن همان چیزی است که Cron در ساعت تعیین‌شده یا دکمه «ارسال فوری گزارش امروز به تلگرام» می‌فرستد.
                فهرست اسامی فقط وقتی اضافه می‌شود که آن بخش خالی نباشد؛ هر بخش حداکثر ۱۵ نام و سپس «و N نفر دیگر» دارد.
            </p>
        <?php else: ?>
            <p class="alert alert-warn" style="margin-top:10px"><?= $e((string) ($dailyPreview['message'] ?? 'پیش‌نمایش در دسترس نیست.')) ?></p>
        <?php endif; ?>
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
