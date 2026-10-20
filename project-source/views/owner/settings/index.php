<?php
/**
 * Owner - platform settings, database structure and maintenance.
 *
 * @var array<string,mixed> $settings
 * @var array<int,array<string,mixed>> $tokens
 * @var array<string,mixed>|null $issuedToken
 * @var array<string,array{label:string,type:string,options?:array<int,string>}> $editable
 * @var array<int,string> $pending
 * @var array<int,array<string,mixed>> $history
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$fieldName = static fn (string $key): string => str_replace('.', '_', $key);
?>
<div class="o-page-head">
    <div>
        <h1>تنظیمات سامانه</h1>
        <p>تنظیمات سراسری سامانه، وضعیت ساختار دیتابیس و عملیات نگهداری. همه مقادیر از جدول <code class="o-ltr">attendance_settings</code> خوانده می‌شوند.</p>
    </div>
    <div class="o-actions">
        <form method="post" action="<?= $e($url('/owner/settings/maintenance')) ?>" class="o-inline-form"
              data-confirm="عملیات نگهداری اجرا شود؟ (انقضای اشتراک‌های گذشته از موعد و بستن نشست‌های اضطراری قدیمی)">
            <?= $csrf() ?>
            <button type="submit" class="o-btn o-btn-warn">اجرای عملیات نگهداری</button>
        </form>
        <a class="o-btn" href="<?= $e($url('/owner/logs?action=UPDATE_SETTINGS')) ?>">تاریخچه تغییر تنظیمات</a>
    </div>
</div>

<?php if ($pending !== []): ?>
    <div class="o-alert o-alert-warn">
        <strong>ساختار دیتابیس به‌روز نیست.</strong>
        موارد در انتظار اجرا: <span class="o-ltr"><?= $e(implode(', ', $pending)) ?></span>
        <form method="post" action="<?= $e($url('/owner/settings/migrate')) ?>" class="o-inline-form" data-confirm="به‌روزرسانی ساختار اجرا شود؟">
            <?= $csrf() ?>
            <button type="submit" class="o-btn o-btn-warn o-btn-sm">اجرای به‌روزرسانی ساختار</button>
        </form>
    </div>
<?php else: ?>
    <div class="o-alert o-alert-success">ساختار دیتابیس به‌روز است (<?= $e($tables) ?> جدول پروژه با پیشوند <code class="o-ltr">attendance_</code>).</div>
<?php endif; ?>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head"><h2>تنظیمات سراسری</h2></div>

        <form method="post" action="<?= $e($url('/owner/settings')) ?>">
            <?= $csrf() ?>

            <div class="o-form-grid">
                <?php foreach ($editable as $key => $definition): ?>
                    <?php $name = $fieldName($key); ?>

                    <?php if ($definition['type'] === 'bool'): ?>
                        <label class="o-check">
                            <input type="checkbox" name="<?= $e($name) ?>" value="1"
                                   <?= in_array((string) ($settings[$key] ?? ''), ['1', 'true', 'on'], true) ? 'checked' : '' ?>>
                            <span><?= $e($definition['label']) ?></span>
                        </label>
                    <?php elseif ($definition['type'] === 'select'): ?>
                        <label class="o-field">
                            <span><?= $e($definition['label']) ?></span>
                            <select name="<?= $e($name) ?>">
                                <?php foreach (($definition['options'] ?? []) as $option): ?>
                                    <option value="<?= $e($option) ?>" <?= (string) ($settings[$key] ?? '') === (string) $option ? 'selected' : '' ?>>
                                        <?= $e($option === 'jalali' ? 'شمسی' : ($option === 'gregorian' ? 'میلادی' : $option)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php else: ?>
                        <label class="o-field">
                            <span><?= $e($definition['label']) ?></span>
                            <input type="<?= $definition['type'] === 'int' ? 'number' : 'text' ?>" name="<?= $e($name) ?>"
                                   class="<?= $definition['type'] === 'int' ? 'o-ltr' : '' ?>"
                                   value="<?= $e((string) ($settings[$key] ?? '')) ?>" maxlength="190">
                            <small class="o-ltr"><?= $e($key) ?></small>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="o-btn o-btn-primary o-mt">ذخیره تنظیمات</button>
        </form>
    </section>

    <div>
        <section class="o-card">
            <div class="o-card-head"><h2>ساختار دیتابیس و مهاجرت‌ها</h2></div>

            <?php if ($history === []): ?>
                <div class="o-empty"><strong>تاریخچه مهاجرت خالی است</strong></div>
            <?php else: ?>
                <div class="o-table-wrap">
                    <table class="o-table" style="min-width:420px">
                        <thead><tr><th>مهاجرت</th><th>دسته</th><th>زمان اجرا</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td class="o-ltr o-small"><?= $e($row['migration']) ?></td>
                                <td><?= $e($row['batch']) ?></td>
                                <td class="o-small"><?= $e($row['applied_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="o-small o-muted o-mt">
                اجرای به‌روزرسانی ساختار فقط روی جدول‌های <code class="o-ltr">attendance_</code> انجام می‌شود؛ هر دستور
                SQL پیش از اجرا از فیلتر <code class="o-ltr">SqlGuard</code> عبور می‌کند و ساختار جداول سایر پروژه‌ها
                هرگز تغییر نمی‌کند.
            </p>
        </section>

        <section class="o-card">
            <div class="o-card-head"><h2>توکن‌های API مالک<span class="o-sub">دسترسی برنامه‌نویسی به سامانه</span></h2></div>

            <?php if (is_array($issuedToken) && !empty($issuedToken['token'])): ?>
                <div class="o-alert o-alert-warn">
                    <strong>توکن جدید ساخته شد — همین حالا کپی کنید.</strong>
                    متن توکن فقط همین یک بار نمایش داده می‌شود و در دیتابیس فقط هش آن ذخیره شده است.
                    <div class="o-ltr o-mt" style="word-break:break-all"><code><?= $e($issuedToken['token']) ?></code></div>
                    <p class="o-small o-mt">
                        نمونه فراخوانی:
                        <code class="o-ltr">curl -H "Authorization: Bearer &lt;token&gt;" <?= $e($url('/api/v1/owner/overview')) ?></code>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= $e($url('/owner/settings/api-token')) ?>" class="o-filters">
                <?= $csrf() ?>
                <label class="o-field">
                    <span>نام توکن</span>
                    <input type="text" name="name" maxlength="120" placeholder="مثلاً پنل موبایل مالک">
                </label>
                <label class="o-field">
                    <span>اعتبار (روز)</span>
                    <input type="number" name="days" value="90" min="1" max="3650">
                </label>
                <div class="o-field">
                    <span>&nbsp;</span>
                    <button type="submit" class="o-btn o-btn-primary">صدور توکن</button>
                </div>
            </form>

            <?php if ($tokens === []): ?>
                <div class="o-empty"><strong>توکن فعالی وجود ندارد</strong>برای اتصال برنامه‌های بیرونی، یک توکن بسازید.</div>
            <?php else: ?>
                <div class="o-table-wrap">
                    <table class="o-table">
                        <thead>
                        <tr>
                            <th>نام</th>
                            <th>پیشوند</th>
                            <th>دسترسی</th>
                            <th>ساخت</th>
                            <th>انقضا</th>
                            <th>آخرین استفاده</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tokens as $token): ?>
                            <?php
                            $revoked = !empty($token['revoked_at']);
                            $expired = !empty($token['expires_at']) && strtotime((string) $token['expires_at']) < time();
                            ?>
                            <tr>
                                <td><?= $e($token['name']) ?></td>
                                <td class="o-ltr o-small"><?= $e($token['token_prefix']) ?>…</td>
                                <td class="o-ltr o-small"><?= $e($token['abilities'] ?? '*') ?></td>
                                <td class="o-small"><?= $e($token['created_at']) ?></td>
                                <td class="o-small"><?= $e($token['expires_at'] ?? '—') ?></td>
                                <td class="o-small"><?= $e($token['last_used_at'] ?? '—') ?></td>
                                <td>
                                    <span class="o-badge <?= $revoked ? 'o-badge-bad' : ($expired ? 'o-badge-warn' : 'o-badge-ok') ?>">
                                        <?= $revoked ? 'باطل‌شده' : ($expired ? 'منقضی' : 'فعال') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!$revoked): ?>
                                        <form method="post" action="<?= $e($url('/owner/settings/api-token/revoke')) ?>" class="o-inline-form" data-confirm="این توکن باطل شود؟">
                                            <?= $csrf() ?>
                                            <input type="hidden" name="token_id" value="<?= $e($token['id']) ?>">
                                            <button type="submit" class="o-btn o-btn-danger o-btn-sm">ابطال</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="o-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="o-small o-muted o-mt">
                توکن‌ها فقط به حساب مالک سامانه تعلق دارند و به همه مسیرهای
                <code class="o-ltr">/api/v1/owner/*</code> دسترسی می‌دهند. هیچ توکنی نمی‌تواند از پنل مدیر مشتری
                برای دسترسی به مسیرهای مالک استفاده کند.
            </p>
        </section>

        <section class="o-card">
            <div class="o-card-head"><h2>امنیت و دسترسی</h2></div>
            <div class="o-kv">
                <div><span>دسترسی اضطراری مالک</span><strong><?= in_array((string) ($settings['owner.impersonation_enabled'] ?? '1'), ['1', 'true'], true) ? 'فعال' : 'غیرفعال' ?></strong></div>
                <div><span>حداکثر تلاش ورود</span><strong><?= $e((string) ($settings['security.max_login_attempts'] ?? '5')) ?></strong></div>
                <div><span>مدت قفل حساب</span><strong><?= $e((string) ($settings['security.lockout_minutes'] ?? '15')) ?> دقیقه</strong></div>
                <div><span>نگهداری لاگ</span><strong><?= $e((string) ($settings['security.audit_retention_days'] ?? '365')) ?> روز</strong></div>
            </div>
            <p class="o-small o-muted o-mt">
                این تنظیمات بلافاصله روی احراز هویت و دسترسی اضطراری اعمال می‌شوند.
            </p>
        </section>
    </div>
</div>
