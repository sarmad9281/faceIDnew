<?php
/**
 * SARMAD Attendance - owner panel: platform settings + database structure.
 *
 * The settings here are the global scope rows of attendance_settings, read
 * through App\Models\Setting (hierarchical: device -> branch -> customer ->
 * global), so a customer specific override later needs no code change.
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\MigrationService;
use App\Services\SubscriptionService;

final class SettingsController
{
    /**
     * Keys the owner may edit, with their type for the form.
     *
     * @var array<string,array{label:string,type:string,options?:array<int,string>}>
     */
    private const EDITABLE = [
        'platform.name'                 => ['label' => 'نام سامانه', 'type' => 'string'],
        'platform.support_email'        => ['label' => 'ایمیل پشتیبانی', 'type' => 'string'],
        'platform.support_phone'        => ['label' => 'تلفن پشتیبانی', 'type' => 'string'],
        'platform.default_plan'         => ['label' => 'پلن پیش‌فرض مشتری جدید', 'type' => 'string'],
        'platform.default_trial_days'   => ['label' => 'روزهای آزمایشی پیش‌فرض', 'type' => 'int'],
        'app.timezone'                  => ['label' => 'منطقه زمانی سامانه', 'type' => 'string'],
        'app.date_system'               => ['label' => 'تقویم پیش‌فرض', 'type' => 'select', 'options' => ['jalali', 'gregorian']],
        'security.max_login_attempts'   => ['label' => 'حداکثر تلاش ناموفق ورود', 'type' => 'int'],
        'security.lockout_minutes'      => ['label' => 'مدت قفل حساب (دقیقه)', 'type' => 'int'],
        'security.audit_retention_days' => ['label' => 'نگهداری لاگ (روز)', 'type' => 'int'],
        'device.offline_after'          => ['label' => 'آستانه آفلاین دستگاه (ثانیه)', 'type' => 'int'],
        'attendance.round_minutes'      => ['label' => 'گرد کردن دقیقه تردد', 'type' => 'int'],
        'attendance.allow_manual'       => ['label' => 'اجازه ثبت دستی تردد', 'type' => 'bool'],
        'owner.impersonation_enabled'   => ['label' => 'دسترسی اضطراری مالک', 'type' => 'bool'],
    ];

    public function index(Request $request): Response
    {
        $migrations = new MigrationService();

        return Response::html(View::render('owner/settings/index', [
            'title'      => 'تنظیمات سامانه',
            'settings'   => $this->currentValues(),
            'editable'   => self::EDITABLE,
            'pending'    => $migrations->pending(),
            'history'    => $migrations->history(),
            'tables'     => count($migrations->projectTables()),
            'tokens'     => \App\Models\ApiToken::forUser((int) (Session::userId() ?? 0)),
            'issuedToken' => Session::pull('issued_api_token', null),
            'active'     => 'settings',
        ], 'layouts/owner'));
    }

    public function update(Request $request): Response
    {
        $values  = [];
        $actorId = (int) (Session::userId() ?? 0);

        foreach (self::EDITABLE as $key => $definition) {
            if ($definition['type'] === 'bool') {
                $field = str_replace('.', '_', $key);

                // The real settings form posts every boolean (hidden 0 +
                // checkbox 1). A request that does not carry the field at all
                // must not silently switch the setting off.
                if (!$request->has($field)) {
                    continue;
                }

                $values[$key] = (string) $request->input($field, '0') === '1' ? '1' : '0';
                continue;
            }

            $value = $request->input(str_replace('.', '_', $key), null);

            if ($value === null) {
                continue;
            }

            $value = trim((string) $value);

            if ($definition['type'] === 'int') {
                $value = (string) max(0, (int) preg_replace('/[^0-9\-]/', '', $value));
            }

            if ($definition['type'] === 'select' && isset($definition['options'])
                && !in_array($value, $definition['options'], true)
            ) {
                continue;
            }

            $values[$key] = $value;
        }

        $changed = [];

        foreach ($values as $key => $value) {
            $before = (string) Setting::get($key, '');

            if ($before === $value) {
                continue;
            }

            Setting::put($key, $value, Setting::SCOPE_GLOBAL, 0, 0, 0, $actorId);
            $changed[$key] = [$before, $value];
        }

        if ($changed === []) {
            Session::flash('success', 'تنظیمات تغییری نداشت.');
        } else {
            AuditLog::record('settings.update', 'success', 'تنظیمات سامانه به‌روزرسانی شد.', [
                'changed' => $changed,
            ], 'app', 'setting', null, null);

            Session::flash('success', count($changed) . ' تنظیم ذخیره شد.');
        }

        return Redirect::response('/owner/settings');
    }

    /**
     * Run pending database migrations from the panel.
     *
     * DirectAdmin often means no SSH access, so this is the practical way to
     * apply the stage 2 schema. It can only create attendance_* tables and
     * columns (see MigrationService) and every statement passes SqlGuard.
     */
    public function migrate(Request $request): Response
    {
        $service = new MigrationService();

        try {
            $result = $service->run();
        } catch (\Throwable $e) {
            Session::flash('error', 'اجرای به‌روزرسانی ناموفق بود: ' . $e->getMessage());

            return Redirect::response('/owner/settings');
        }

        AuditService::log('schema.migrate', 'success', 'به‌روزرسانی ساختار دیتابیس اجرا شد.', [
            'files'   => $result['files'],
            'columns' => $result['columns'],
        ], 'app', 'schema', null, null);

        Session::flash('success', implode(' | ', $result['messages']));

        return Redirect::response('/owner/settings');
    }

    /**
     * Maintenance: expire overdue subscriptions (idempotent).
     */
    public function maintenance(Request $request): Response
    {
        $expired = (new SubscriptionService())->expireOverdue();
        $stale   = \App\Models\Impersonation::closeStale(12);

        $messages = [];

        $messages[] = $expired === []
            ? 'اشتراک منقضی‌شده‌ای برای بستن وجود نداشت.'
            : count($expired) . ' اشتراک منقضی شد.';

        $messages[] = $stale === 0
            ? 'نشست دسترسی اضطراری بازی‌مانده‌ای نبود.'
            : $stale . ' نشست اضطراری قدیمی بسته شد.';

        AuditService::log('settings.maintenance', 'success', 'اجرای عملیات نگهداری سامانه.', [
            'expired' => count($expired),
            'stale_impersonations' => $stale,
        ], 'app', 'setting', null, null);

        Session::flash('success', implode(' | ', $messages));

        return Redirect::response('/owner/settings');
    }

    /**
     * Issue an API token for the signed in owner.
     *
     * The plain token is displayed exactly once (it is stored as a SHA-256
     * hash and can never be read back - same rule as passwords).
     */
    public function issueToken(Request $request): Response
    {
        $actorId = (int) (Session::userId() ?? 0);
        $name    = trim((string) $request->input('name', ''));
        $days    = max(1, min(3650, (int) $request->input('days', 90)));

        if ($name === '') {
            $name = 'توکن مالک ' . date('Y-m-d');
        }

        $issued = \App\Models\ApiToken::issue($actorId, null, $name, ['*'], $days, $actorId);

        AuditService::log('api.token.issue', 'success', 'توکن API برای مالک سامانه صادر شد.', [
            'token_id' => $issued['id'],
            'prefix'   => substr($issued['token'], 0, 12),
            'days'     => $days,
        ], 'security', 'api_token', $issued['id'], null);

        Session::flash('issued_api_token', [
            'id'    => $issued['id'],
            'token' => $issued['token'],
            'name'  => $name,
            'days'  => $days,
        ]);
        Session::flash('success', 'توکن API صادر شد. متن توکن فقط همین یک بار نمایش داده می‌شود.');

        return Redirect::response('/owner/settings');
    }

    public function revokeToken(Request $request): Response
    {
        $tokenId = (int) $request->input('token_id', 0);
        $actorId = (int) (Session::userId() ?? 0);

        $tokens = \App\Models\ApiToken::forUser($actorId);
        $owned  = false;

        foreach ($tokens as $token) {
            if ((int) $token['id'] === $tokenId) {
                $owned = true;
                break;
            }
        }

        if (!$owned) {
            Session::flash('error', 'توکن یافت نشد یا به حساب شما تعلق ندارد.');

            return Redirect::response('/owner/settings');
        }

        \App\Models\ApiToken::revoke($tokenId);

        AuditService::log('api.token.revoke', 'success', 'توکن API مالک سامانه باطل شد.', [
            'token_id' => $tokenId,
        ], 'security', 'api_token', $tokenId, null);

        Session::flash('success', 'توکن باطل شد.');

        return Redirect::response('/owner/settings');
    }

    /**
     * @return array<string,mixed>
     */
    private function currentValues(): array
    {
        $values = [];

        foreach (array_keys(self::EDITABLE) as $key) {
            $values[$key] = Setting::get($key, '');
        }

        return $values;
    }
}
