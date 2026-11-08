<?php
/**
 * SARMAD Attendance - manager panel: telegram (stage 7).
 *
 * REAL integration, feature gated end to end:
 *   - the bot token is stored encrypted and shown only as ************1234,
 *   - a test message and the daily report go through the real Telegram API
 *     (App\Services\TelegramService), never through a fake "sent" flag,
 *   - the queue and its failures are visible in the panel,
 *   - the legacy stage 3 settings (customer_settings.telegram_*) are still
 *     written, so nothing that worked before breaks.
 *
 * If the feature is off for the customer, the route middleware already answers
 * 403 and every method here checks again.
 */

declare(strict_types=1);

namespace App\Controllers\Manager;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerSetting;
use App\Services\EventDispatcher;
use App\Services\FeatureService;
use App\Services\TelegramScheduler;
use App\Services\TelegramService;
use App\Support\Capability;
use App\Support\Input;

final class TelegramController
{
    public const NOTIFICATIONS = [
        'telegram_daily_summary' => 'خلاصه روزانه حضور و غیاب',
        'telegram_late_alert'    => 'اعلان تأخیر',
        'telegram_absent_alert'  => 'اعلان غیبت',
        'telegram_leave_alert'   => 'اعلان درخواست مرخصی',
    ];

    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('telegram', $customerId);

        $settings = CustomerSetting::all($customerId);
        $service  = new TelegramService();
        $engine   = $service->settings($customerId);

        $notifications = [];

        foreach (self::NOTIFICATIONS as $key => $label) {
            $notifications[$key] = [
                'label'   => $label,
                'enabled' => ($settings[$key] ?? '0') === '1',
            ];
        }

        return Response::html(View::render('manager/telegram/index', [
            'title'         => 'ربات تلگرام',
            'chatId'        => $settings['telegram_chat_id'] ?? '',
            'notifications' => $notifications,
            'configured'    => ($settings['telegram_chat_id'] ?? '') !== '',
            'lastSaved'     => $settings['telegram_updated_at'] ?? null,
            'customer'      => Customer::find($customerId),
            'engine'        => [
                'settings'       => $engine,
                'tokenMasked'    => $service->maskToken($engine),
                'hasToken'       => trim((string) ($engine['bot_token_hint'] ?? '')) !== '',
                'usable'         => $service->usable($customerId),
                'queueStats'     => $service->queueStats($customerId),
                'queue'          => $service->queueRows($customerId, 40),
                'logs'           => $service->logs($customerId, 40),
                'events'         => \App\Models\AttendanceEvent::recentForCustomer($customerId, 25),
                'eventPending'   => \App\Models\AttendanceEvent::countForCustomer($customerId),
                'notify'         => [
                    'entry'    => (int) $engine['notify_entry'] === 1,
                    'exit'     => (int) $engine['notify_exit'] === 1,
                    'late'     => (int) $engine['notify_late'] === 1,
                    'overtime' => (int) $engine['notify_overtime'] === 1,
                    'daily'    => (int) $engine['daily_report'] === 1,
                ],
                'dailyTime'      => (string) $engine['daily_report_time'],
                'isActive'       => (int) $engine['is_active'] === 1,
                'lastOk'         => $engine['last_ok_at'] ?? null,
                'lastError'      => $engine['last_error'] ?? null,
                'lastErrorAt'    => $engine['last_error_at'] ?? null,
            ],
            'error'         => Session::getFlash('error'),
            'success'       => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function save(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('telegram', $customerId);

        if (($denied = Capability::check($request, 'telegram.manage')) !== null) {
            return $denied;
        }

        /* ---------- stage 3 compatibility: customer_settings ---------- */
        $chatId = Input::text($request->input('telegram_chat_id', ''), 40);

        if ($chatId !== '' && !preg_match('/^-?[0-9]{5,20}$|^@[A-Za-z0-9_]{4,32}$/', $chatId)) {
            Session::flash('error', 'شناسه چت باید عددی (مثل ‎-1001234567890) یا نام کاربری (مثل @my_channel) باشد.');

            return Redirect::response('/manager/telegram');
        }

        $values = ['telegram_chat_id' => $chatId, 'telegram_updated_at' => date('Y-m-d H:i:s')];

        foreach (array_keys(self::NOTIFICATIONS) as $key) {
            $values[$key] = Input::bool($request->input($key, '0')) === 1 ? '1' : '0';
        }

        $changed = CustomerSetting::putMany($customerId, $values, (int) (Session::userId() ?? 0));

        /* ---------- stage 7 engine settings ---------- */
        $engineInput = [
            'chat_id'           => $chatId,
            'notify_entry'      => $request->input('notify_entry', $request->input('telegram_entry_alert', '1')),
            'notify_exit'       => $request->input('notify_exit', $request->input('telegram_exit_alert', '1')),
            'notify_late'       => $request->input('notify_late', $values['telegram_late_alert'] ?? '0'),
            'notify_overtime'   => $request->input('notify_overtime', '0'),
            'daily_report'      => $request->input('daily_report', $values['telegram_daily_summary'] ?? '0'),
            'daily_report_time' => $request->input('daily_report_time', '23:55'),
            'is_active'         => $request->input('is_active', $request->input('telegram_is_active', '0')),
        ];

        $token = trim((string) $request->input('bot_token', ''));

        if ($token !== '') {
            $engineInput['bot_token'] = $token;
        }

        $result = (new TelegramService())->saveSettings($this->actor(), $customerId, $engineInput);

        AuditLog::record('telegram.settings.update', 'success', 'تنظیمات ربات تلگرام مشتری ذخیره شد.', [
            'changed_keys' => $changed,
            'token_replaced' => $token !== '',
        ], 'app', 'telegram', 0, $customerId);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/telegram');
    }

    /**
     * POST /manager/telegram/test - real test message through the bot.
     */
    public function test(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('telegram', $customerId);

        if (($denied = Capability::check($request, 'telegram.manage')) !== null) {
            return $denied;
        }

        $result = (new TelegramService())->testSend($customerId, Input::text((string) $request->input('text', ''), 300));

        AuditLog::record('telegram.test.sent', $result['ok'] ? 'success' : 'failure', $result['message'], [
            'code' => $result['code'],
        ], 'telegram', 'telegram_settings', 0, $customerId);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/telegram');
    }

    /**
     * POST /manager/telegram/daily - force the daily report of one date.
     */
    public function daily(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('telegram', $customerId);

        if (($denied = Capability::check($request, 'telegram.manage')) !== null) {
            return $denied;
        }

        $date = Input::date((string) $request->input('date', '')) ?? date('Y-m-d');
        $result = (new TelegramScheduler())->sendNow($customerId, $date);

        $status = (string) ($result['status'] ?? 'skipped');
        $message = match ($status) {
            'sent'    => 'گزارش روزانه تلگرام ارسال شد.',
            'queued'  => 'گزارش روزانه در صف ارسال قرار گرفت و توسط Worker ارسال می‌شود.',
            'skipped' => 'گزارش ارسال نشد: ' . $this->reasonLabel((string) ($result['reason'] ?? '')),
            default   => 'ارسال گزارش روزانه ناموفق بود.',
        };

        Session::flash($status === 'sent' ? 'success' : 'error', $message);

        return Redirect::response('/manager/telegram');
    }

    /**
     * POST /manager/telegram/drain - run the worker now (events + queue).
     */
    public function drain(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('telegram', $customerId);

        if (($denied = Capability::check($request, 'telegram.manage')) !== null) {
            return $denied;
        }

        $events = (new EventDispatcher())->dispatch(100);
        $drain  = (new TelegramService())->drain(30);

        AuditLog::record('telegram.worker.manual', 'success', 'پردازش دستی صف تلگرام اجرا شد.', [
            'events_scanned' => $events['scanned'],
            'notified'       => $events['notified'],
            'sent'           => $drain['sent'],
            'failed'         => $drain['failed'],
            'retried'        => $drain['retried'],
        ], 'telegram', 'telegram_settings', 0, $customerId);

        Session::flash('success', 'پردازش انجام شد: ' . $events['notified'] . ' اعلان تازه، '
            . $drain['sent'] . ' ارسال موفق، ' . $drain['retried'] . ' تلاش مجدد، ' . $drain['failed'] . ' ناموفق.');

        return Redirect::response('/manager/telegram');
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'already_sent_or_in_flight' => 'گزارش این روز قبلاً ارسال یا در حال ارسال است.',
            'telegram_unavailable'      => 'ربات فعال یا تنظیم نشده است.',
            'feature_disabled'          => 'سرویس تلگرام برای این مجموعه غیرفعال است.',
            'max_attempts_reached'      => 'حداکثر تلاش ارسال انجام شده است.',
            default                     => $reason,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function actor(): array
    {
        return [
            'id'   => (int) (Session::userId() ?? 0),
            'name' => (string) (Session::get('user_name') ?? ''),
            'role' => (string) (Session::role() ?? ''),
            'ip'   => Request::ip(),
        ];
    }
}
