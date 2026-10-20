<?php
/**
 * Manager - devices of the active customer (limited surface).
 *
 * @var array<int,array<string,mixed>> $devices
 * @var array<string,mixed> $stats
 * @var array<string,string> $statuses
 * @var string $filter
 * @var array<int,array<string,mixed>> $branches
 * @var array<string,mixed>|null $tokenOnce
 * @var int $offlineAfter
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */

use App\Models\Device;
use App\Support\Jalali;

$badge = static function (string $status): string {
    switch ($status) {
        case Device::STATUS_ACTIVE: return 'badge-ok';
        case Device::STATUS_PENDING: return 'badge-warn';
        case Device::STATUS_DISABLED: return 'badge-muted';
        default: return 'badge-error';
    }
};
?>
<div class="page-head">
    <div>
        <h1>دستگاه‌ها</h1>
        <p class="muted small">
            دستگاه‌های همین کسب‌وکار. ثبت اولیه دستگاه در همین صفحه انجام می‌شود؛
            ریست، چرخش توکن و لغو دستگاه فقط توسط مالک سامانه امکان‌پذیر است.
        </p>
    </div>
    <span class="m-chip">در آنلاین‌بودن: <?= $e(intdiv($offlineAfter, 60)) ?> دقیقه</span>
</div>

<?php if (is_array($tokenOnce) && !empty($tokenOnce['token'])): ?>
    <section class="card danger-zone">
        <div class="card-head"><h2>توکن دستگاه «<?= $e($tokenOnce['name'] ?? '') ?>»</h2></div>
        <p class="small">این توکن فقط همین یک بار نمایش داده می‌شود؛ در جای امن نگهداری کنید.</p>
        <div class="token-box ltr" id="device-token"><?= $e($tokenOnce['token']) ?></div>
        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px" data-copy="<?= $e($tokenOnce['token']) ?>">کپی توکن</button>
    </section>
<?php endif; ?>

<section class="kpi-grid">
    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">کل دستگاه‌ها</span></div>
        <div class="kpi-value num"><?= $e($stats['total'] ?? 0) ?></div>
    </article>
    <article class="kpi kpi-ok">
        <div class="kpi-top"><span class="kpi-label">فعال</span></div>
        <div class="kpi-value num"><?= $e($stats['active'] ?? 0) ?></div>
        <div class="kpi-foot">آنلاین در ۵ دقیقه اخیر: <?= $e($stats['online'] ?? 0) ?></div>
    </article>
    <article class="kpi kpi-warn">
        <div class="kpi-top"><span class="kpi-label">در انتظار فعال‌سازی</span></div>
        <div class="kpi-value num"><?= $e($stats['pending'] ?? 0) ?></div>
    </article>
    <article class="kpi">
        <div class="kpi-top"><span class="kpi-label">غیرفعال / لغو شده</span></div>
        <div class="kpi-value num"><?= $e(((int) ($stats['disabled'] ?? 0)) + ((int) ($stats['revoked'] ?? 0))) ?></div>
    </article>
</section>

<section class="card">
    <div class="card-head"><h2>ثبت اولیه دستگاه</h2></div>
    <form method="post" action="<?= $e($url('/manager/devices')) ?>">
        <?= $csrf() ?>
        <div class="grid-3">
            <label class="field"><span>نام دستگاه *</span><input type="text" name="name" maxlength="120" required placeholder="مثلاً تبلت ورودی"></label>
            <label class="field">
                <span>نوع</span>
                <select name="type">
                    <option value="kiosk">کیوسک</option>
                    <option value="tablet">تبلت</option>
                    <option value="mobile">موبایل</option>
                    <option value="desktop">رایانه</option>
                </select>
            </label>
            <label class="field"><span>پلتفرم</span><input type="text" name="platform" maxlength="60" class="ltr" placeholder="Android 13"></label>
            <?php if ($branchesEnabled): ?>
                <label class="field">
                    <span>شعبه</span>
                    <select name="branch_id">
                        <option value="0">— بدون شعبه —</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $e($branch['id']) ?>"><?= $e($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">ثبت دستگاه و دریافت کد جفت‌سازی</button>
        <span class="muted small">پس از ثبت، ثبت‌نام دستگاه توسط مالک سامانه مدیریت می‌شود.</span>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>فهرست دستگاه‌ها</h2>
        <form method="get" action="<?= $e($url('/manager/devices')) ?>" class="inline-form">
            <select name="status" class="input" data-autosubmit style="width:160px">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= $e($key) ?>" <?= $filter === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($devices === []): ?>
        <div class="empty"><strong>دستگاهی ثبت نشده است</strong>اولین دستگاه را از فرم بالا ثبت کنید.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>نام</th><th>شناسه دستگاه</th><th>شعبه</th><th>وضعیت</th><th>آخرین ارتباط</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($devices as $device): ?>
                    <tr>
                        <td><?= $e($device['name']) ?><small><?= $e($device['platform'] ?? '') ?></small></td>
                        <td class="ltr small"><?= $e($device['device_uid'] ?? '—') ?></td>
                        <td><?= $e($device['branch_name'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= $badge((string) $device['status']) ?>"><?= $e($statuses[$device['status']] ?? $device['status']) ?></span>
                            <?php if (!empty($device['pairing_code']) && (string) $device['status'] === Device::STATUS_PENDING): ?>
                                <small>کد جفت‌سازی: <span class="ltr"><?= $e($device['pairing_code']) ?></span></small>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if (!empty($device['last_seen_at'])): ?>
                                <span class="dot <?= Device::isOnline($device, $offlineAfter) ? 'dot-online' : 'dot-offline' ?>"></span>
                                <?= $e(Jalali::date($device['last_seen_at'], true)) ?>
                            <?php else: ?>
                                <span class="dot dot-offline"></span> بدون ارتباط
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <?php if ((string) $device['status'] === Device::STATUS_PENDING): ?>
                                <form method="post" action="<?= $e($url('/manager/devices/activate')) ?>" class="inline-form" data-confirm="این دستگاه فعال شود؟ توکن فقط یک بار نمایش داده می‌شود.">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="device_id" value="<?= $e($device['id']) ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">تکمیل فعال‌سازی</button>
                                </form>
                            <?php else: ?>
                                <span class="muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="muted small" style="margin-top:12px">
        این پنل عمداً هیچ دکمه‌ای برای ریست، چرخش توکن یا لغو دستگاه ندارد. اگر دستگاه نیاز به ریست دارد،
        مالک سامانه این کار را از پنل خود انجام می‌دهد و رویداد آن ثبت می‌شود.
    </p>
</section>
