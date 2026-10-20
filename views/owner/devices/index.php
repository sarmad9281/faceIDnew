<?php
/**
 * Owner - device management (search / customer / status / online filters).
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $listing
 * @var array<int,array<string,mixed>> $customers
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 * @var array<int,array{state:string,prefix:string,expires_at:?string,attempts:int}> $activations
 * @var array<int,array<string,mixed>> $logs
 * @var int $errorCount
 */
$rows = $listing['rows'];
$activations = $activations ?? [];
$logs = $logs ?? [];
$errorCount = (int) ($errorCount ?? 0);
$activationBadge = [
    'open'    => ['o-badge-ok', 'کد فعال‌سازی فعال'],
    'expired' => ['o-badge-warn', 'کد منقضی'],
    'used'    => ['o-badge-muted', 'کد استفاده شده'],
    'revoked' => ['o-badge-bad', 'کد باطل'],
    'none'    => ['o-badge-muted', 'کد فعال ندارد'],
];
$statusTitles = [
    'pending'  => 'در انتظار فعال‌سازی',
    'active'   => 'فعال',
    'disabled' => 'غیرفعال',
    'revoked'  => 'لغو شده',
];
$statusBadge = [
    'pending'  => 'o-badge-warn',
    'active'   => 'o-badge-ok',
    'disabled' => 'o-badge-muted',
    'revoked'  => 'o-badge-bad',
];
?>
<div class="o-page-head">
    <div>
        <h1>مدیریت دستگاه‌ها</h1>
        <p>
            دستگاه‌های حضور و غیاب همه مشتریان. «کد فعال‌سازی» یک کد یک‌بارمصرف و زمان‌دار می‌سازد که روی خود دستگاه
            وارد می‌شود؛ دستگاه با آن توکن امن می‌گیرد و از آن پس پرسنل فقط با چهره ورود/خروج ثبت می‌کنند. «ریست دستگاه»
            توکن و نشست‌های قبلی را باطل می‌کند و کد جدید صادر می‌شود. تولید کد و ریست فقط در اختیار مالک سامانه است.
        </p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/logs?action=RESET_DEVICE')) ?>">تاریخچه ریست دستگاه‌ها</a>
    </div>
</div>

<section class="o-card">
    <form method="get" action="<?= $e($url('/owner/devices')) ?>" class="o-filters">
        <label class="o-field">
            <span>جستجو</span>
            <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="نام دستگاه، Device ID، پیشوند توکن، مشتری">
        </label>

        <label class="o-field">
            <span>مشتری</span>
            <select name="customer_id" data-autosubmit>
                <option value="0">همه مشتریان</option>
                <?php foreach ($customers as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= (int) $filters['customer_id'] === (int) $option['id'] ? 'selected' : '' ?>><?= $e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="o-field">
            <span>وضعیت</span>
            <select name="status" data-autosubmit>
                <option value="">همه</option>
                <?php foreach ($statusTitles as $key => $title): ?>
                    <option value="<?= $e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= $e($title) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="o-field">
            <span>اتصال</span>
            <select name="online" data-autosubmit>
                <option value="">همه</option>
                <option value="online" <?= $filters['online'] === 'online' ? 'selected' : '' ?>>آنلاین</option>
                <option value="offline" <?= $filters['online'] === 'offline' ? 'selected' : '' ?>>آفلاین</option>
            </select>
        </label>

        <div class="o-field">
            <span>&nbsp;</span>
            <button type="submit" class="o-btn o-btn-primary">اعمال فیلتر</button>
        </div>
    </form>
</section>

<section class="o-card">
    <div class="o-card-head">
        <h2>فهرست دستگاه‌ها<span class="o-sub"><?= $e($listing['total']) ?> دستگاه — آستانه آنلاین: <?= $e($offlineAfter) ?> ثانیه</span></h2>
        <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/devices')) ?>">حذف فیلترها</a>
    </div>

    <?php if ($rows === []): ?>
        <div class="o-empty"><strong>دستگاهی با این فیلترها یافت نشد</strong>دستگاه‌ها از پنل مدیر مشتری یا API ثبت می‌شوند.</div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>دستگاه</th>
                    <th>مشتری</th>
                    <th>شعبه</th>
                    <th>Device ID</th>
                    <th>وضعیت</th>
                    <th>اتصال</th>
                    <th>آخرین تماس</th>
                    <th>کد فعال‌سازی</th>
                    <th>ایجاد</th>
                    <th>عملیات</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $device): ?>
                    <?php
                    $online = \App\Models\Device::isOnline($device, (int) $offlineAfter);
                    $status = (string) $device['status'];
                    ?>
                    <tr>
                        <td>
                            <strong><?= $e($device['name']) ?></strong>
                            <small><?= $e($device['type']) ?> — <?= $e($device['platform'] ?? '') ?></small>
                            <?php if (!empty($device['token_prefix'])): ?>
                                <small class="o-ltr">token: <?= $e($device['token_prefix']) ?>…</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($device['customer_uuid'])): ?>
                                <a href="<?= $e($url('/owner/customers/' . $device['customer_uuid'])) ?>"><?= $e($device['customer_name'] ?? '—') ?></a>
                            <?php else: ?>
                                <?= $e($device['customer_name'] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($device['branch_name'] ?? '—') ?></td>
                        <td class="o-ltr o-small"><?= $e($device['device_uid']) ?></td>
                        <td><span class="o-badge <?= $statusBadge[$status] ?? 'o-badge-muted' ?>"><?= $e($statusTitles[$status] ?? $status) ?></span></td>
                        <td>
                            <span class="o-dot <?= $online ? 'o-dot-on' : 'o-dot-off' ?>"></span>
                            <?= $online ? 'آنلاین' : 'آفلاین' ?>
                        </td>
                        <td class="o-small"><?= $e($device['last_seen_at'] ?? '—') ?></td>
                        <td class="o-small">
                            <?php $activation = $activations[(int) $device['id']] ?? ['state' => 'none', 'prefix' => '', 'expires_at' => null, 'attempts' => 0]; ?>
                            <?php [$badgeClass, $badgeTitle] = $activationBadge[$activation['state']] ?? ['o-badge-muted', 'کد فعال ندارد']; ?>
                            <span class="o-badge <?= $badgeClass ?>"><?= $e($badgeTitle) ?></span>
                            <?php if ($activation['prefix'] !== ''): ?>
                                <small class="o-ltr"><?= $e($activation['prefix']) ?>…</small>
                                <small>تا <?= $e($activation['expires_at'] ?? '—') ?></small>
                            <?php endif; ?>
                            <?php if ((int) $activation['attempts'] > 0): ?>
                                <small>تلاش ناموفق: <?= (int) $activation['attempts'] ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="o-small"><?= $e($device['created_at']) ?></td>
                        <td>
                            <div class="o-actions">
                                <form method="post" action="<?= $e($url('/owner/devices/activation-code')) ?>" class="o-inline-form"
                                      data-confirm="کد فعال‌سازی یک‌بارمصرف برای این دستگاه ساخته شود؟ کدهای باز قبلی باطل می‌شوند.">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="id" value="<?= $e($device['id']) ?>">
                                    <button type="submit" class="o-btn o-btn-sm o-btn-primary">کد فعال‌سازی</button>
                                </form>

                                <?php if ($status === 'active'): ?>
                                    <form method="post" action="<?= $e($url('/owner/devices/status')) ?>" class="o-inline-form"
                                          data-confirm="این دستگاه غیرفعال شود؟ نشست‌های فعال آن قطع می‌شود.">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="id" value="<?= $e($device['id']) ?>">
                                        <input type="hidden" name="status" value="disabled">
                                        <button type="submit" class="o-btn o-btn-sm o-btn-warn">غیرفعال</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= $e($url('/owner/devices/status')) ?>" class="o-inline-form"
                                          data-confirm="این دستگاه فعال شود؟">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="id" value="<?= $e($device['id']) ?>">
                                        <input type="hidden" name="status" value="active">
                                        <button type="submit" class="o-btn o-btn-sm o-btn-ok">فعال‌سازی</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" action="<?= $e($url('/owner/devices/reset')) ?>" class="o-inline-form"
                                      data-confirm="دستگاه ریست شود؟ توکن فعلی باطل و دستگاه باید دوباره فعال‌سازی شود.">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="id" value="<?= $e($device['id']) ?>">
                                    <button type="submit" class="o-btn o-btn-sm">ریست</button>
                                </form>

                                <form method="post" action="<?= $e($url('/owner/devices/rotate-token')) ?>" class="o-inline-form"
                                      data-confirm="توکن دستگاه بازتولید شود؟ توکن قبلی باطل می‌شود.">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="id" value="<?= $e($device['id']) ?>">
                                    <button type="submit" class="o-btn o-btn-sm">ریست توکن</button>
                                </form>

                                <form method="post" action="<?= $e($url('/owner/devices/revoke')) ?>" class="o-inline-form"
                                      data-confirm="ثبت این دستگاه لغو شود؟ دستگاه دیگر نمی‌تواند وارد شود.">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="id" value="<?= $e($device['id']) ?>">
                                    <button type="submit" class="o-btn o-btn-sm o-btn-danger">لغو ثبت</button>
                                </form>

                                <form method="post" action="<?= $e($url('/owner/devices/delete')) ?>" class="o-inline-form"
                                      data-confirm="این دستگاه حذف شود؟ (سابقه آن حفظ می‌شود ولی از فهرست خارج می‌شود)">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="id" value="<?= $e($device['id']) ?>">
                                    <button type="submit" class="o-btn o-btn-sm o-btn-danger">حذف</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::render('partials/pagination', [
            'listing' => $listing,
            'query'   => [
                '_path'       => '/owner/devices',
                'q'           => $filters['q'],
                'customer_id' => (int) $filters['customer_id'],
                'status'      => $filters['status'],
                'online'      => $filters['online'],
            ],
        ]) ?>
    <?php endif; ?>
</section>

<section class="o-card">
    <div class="o-card-head">
        <h2>
            آخرین رویدادهای دستگاه‌ها
            <span class="o-sub">
                فعال‌سازی، احراز هویت، ضربان، بازتولید توکن، غیرفعال‌سازی، خطای ارتباط، خطای تشخیص و ارسال حضور —
                خطاهای یک ساعت گذشته: <?= (int) $errorCount ?>
            </span>
        </h2>
        <a class="o-btn o-btn-sm" href="<?= $e($url('/owner/logs')) ?>">گزارش کامل رویدادها</a>
    </div>

    <?php if ($logs === []): ?>
        <div class="o-empty"><strong>رویدادی ثبت نشده است</strong>با فعال‌سازی اولین دستگاه، رویدادها اینجا نمایش داده می‌شوند.</div>
    <?php else: ?>
        <div class="o-table-wrap">
            <table class="o-table">
                <thead>
                <tr>
                    <th>زمان</th>
                    <th>رویداد</th>
                    <th>سطح</th>
                    <th>دستگاه</th>
                    <th>مشتری</th>
                    <th>توضیح</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $levelBadge = [
                        'info'    => 'o-badge-muted',
                        'warning' => 'o-badge-warn',
                        'error'   => 'o-badge-bad',
                    ][(string) $log['level']] ?? 'o-badge-muted';
                    ?>
                    <tr>
                        <td class="o-small"><?= $e($log['created_at']) ?></td>
                        <td><?= $e(\App\Models\DeviceLog::eventLabel((string) $log['event'])) ?><small class="o-ltr"><?= $e($log['event']) ?></small></td>
                        <td><span class="o-badge <?= $levelBadge ?>"><?= $e($log['level']) ?></span></td>
                        <td>
                            <?= $e($log['device_name'] ?? '—') ?>
                            <?php if (!empty($log['device_uid'])): ?><small class="o-ltr"><?= $e($log['device_uid']) ?></small><?php endif; ?>
                        </td>
                        <td><?= $e($log['customer_name'] ?? '—') ?></td>
                        <td class="o-small"><?= $e($log['message'] ?? '') ?></td>
                        <td class="o-small o-ltr"><?= $e($log['ip'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
