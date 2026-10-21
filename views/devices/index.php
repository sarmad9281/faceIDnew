<?php
/**
 * Device inventory (management only - kiosk UI is stage 3).
 *
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 * @var array $devices
 * @var array $branches
 * @var array $stats
 * @var bool  $scopeMissing
 * @var int   $onlineAfter
 */
$issued = $session()->userId() !== null ? \App\Core\Session::get('device_token_once') : null;
if (is_array($issued)) {
    \App\Core\Session::pull('device_token_once');
}
?>
<section class="page-head">
    <div>
        <h1>دستگاه‌های حضور و غیاب</h1>
        <p class="muted">ثبت، فعال‌سازی، توکن و وضعیت دستگاه‌ها.</p>
    </div>
</section>

<?php if (!empty($scopeMissing)): ?>
    <div class="alert alert-error">
        برای مشاهده دستگاه‌ها باید محدوده یک مشتری مشخص باشد. از پنل مالک، مشتری مورد نظر را انتخاب کنید.
    </div>
<?php else: ?>

<section class="stats">
    <?php
    foreach ([
        ['label' => 'کل دستگاه‌ها', 'value' => $stats['total'] ?? 0],
        ['label' => 'آنلاین', 'value' => $stats['online'] ?? 0],
        ['label' => 'آفلاین', 'value' => $stats['offline'] ?? 0],
    ] as $card) {
        $label = $card['label'];
        $value = $card['value'];
        require dirname(__DIR__) . '/partials/stat.php';
    }
    ?>
</section>

<?php if (is_array($issued)): ?>
    <div class="alert alert-success">
        <strong>توکن دستگاه<?= !empty($issued['name']) ? ' «' . $e($issued['name']) . '»' : '' ?>:</strong>
        <code class="ltr token-box"><?= $e($issued['token']) ?></code>
        <small>این توکن فقط یک بار نمایش داده می‌شود؛ آن را در دستگاه ثبت کنید.</small>
    </div>
<?php endif; ?>

<section class="card">
    <h2>افزودن دستگاه</h2>
    <form method="post" action="<?= $e($url('/devices')) ?>" autocomplete="off" novalidate>
        <?= $csrf() ?>
        <div class="grid-2">
            <label class="field">
                <span>نام دستگاه *</span>
                <input type="text" name="name" required maxlength="120">
            </label>

            <label class="field">
                <span>شعبه</span>
                <select name="branch_id">
                    <option value="0">— بدون شعبه —</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= $e($branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>نوع دستگاه</span>
                <select name="type">
                    <option value="kiosk">کیوسک</option>
                    <option value="tablet">تبلت</option>
                    <option value="mobile">موبایل</option>
                    <option value="desktop">دسکتاپ</option>
                </select>
            </label>

            <label class="field">
                <span>پلتفرم</span>
                <input type="text" name="platform" placeholder="Android 13 / Windows 11" maxlength="60" dir="ltr">
            </label>
        </div>

        <button type="submit" class="btn btn-primary">ثبت دستگاه</button>
    </form>
</section>

<section class="card">
    <h2>فهرست دستگاه‌ها</h2>
    <?php if ($devices === []): ?>
        <p class="muted">دستگاهی ثبت نشده است.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>#</th><th>نام</th><th>شناسه دستگاه</th><th>وضعیت</th><th>آنلاین؟</th>
                <th>آخرین ارتباط</th><th>پیشوند توکن</th><th>عملیات</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $device): ?>
                <tr>
                    <td><?= (int) $device['id'] ?></td>
                    <td><?= $e($device['name']) ?><small class="muted"><?= $e($device['type']) ?></small></td>
                    <td class="ltr small"><?= $e($device['device_uid']) ?></td>
                    <td>
                        <span class="badge badge-<?= $e($device['status'] === 'active' ? 'ok' : ($device['status'] === 'pending' ? 'warn' : 'muted')) ?>">
                            <?= $e($device['status']) ?>
                        </span>
                    </td>
                    <td><?= \App\Models\Device::isOnline($device, $onlineAfter) ? 'آنلاین' : 'آفلاین' ?></td>
                    <td class="ltr small"><?= $e($device['last_seen_at'] ?? '—') ?></td>
                    <td class="ltr small"><?= $e($device['token_prefix'] ?? '—') ?></td>
                    <td class="row-actions">
                        <?php if ($device['status'] !== 'active'): ?>
                            <form method="post" action="<?= $e($url('/devices/activate')) ?>" class="inline-form">
                                <?= $csrf() ?>
                                <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                <button class="btn btn-primary" type="submit">فعال‌سازی</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= $e($url('/devices/rotate-token')) ?>" class="inline-form">
                                <?= $csrf() ?>
                                <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                <button class="btn btn-ghost" type="submit">توکن جدید</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($device['status'] !== 'revoked'): ?>
                            <form method="post" action="<?= $e($url('/devices/revoke')) ?>" class="inline-form"
                                  onsubmit="return confirm('دستگاه لغو شود؟');">
                                <?= $csrf() ?>
                                <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                <button class="btn btn-danger" type="submit">لغو</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php endif; ?>
