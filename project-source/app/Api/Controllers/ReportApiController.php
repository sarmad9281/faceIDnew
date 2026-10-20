<?php
/**
 * SARMAD Attendance - stage 7 manager API: reports, payroll, telegram.
 *
 * Same rules as the stage 6 attendance API:
 *   - `api.auth:<capability>` middleware first, then the feature check here,
 *   - tenant comes from TenantContext (never from the request body),
 *   - every query is scoped by customer_id inside the service layer,
 *   - the envelope is {success, code, message, data, meta},
 *   - exports go through the same ExcelExporter as the panel, so the API can
 *     not produce a different file.
 */

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Models\Employee;
use App\Services\EventDispatcher;
use App\Services\ExcelExporter;
use App\Services\FeatureService;
use App\Services\PayrollService;
use App\Services\ReportService;
use App\Services\TelegramScheduler;
use App\Services\TelegramService;

final class ReportApiController
{
    /**
     * GET /api/v1/manager/reports
     */
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $denied = $this->requireFeature($customerId, 'reports');

        if ($denied !== null) {
            return $denied;
        }

        $type    = (string) $request->input('type', 'daily');
        $filters = $this->filters($request);
        $service = new ReportService();

        if (!array_key_exists($type, ReportService::TYPES)) {
            return ApiResponse::validation(['type' => ['نوع گزارش نامعتبر است.']]);
        }

        $data = $service->build($customerId, $type, $filters);

        return ApiResponse::success([
            'type'    => $data['type'],
            'title'   => $data['title'],
            'columns' => $data['columns'],
            'rows'    => $data['rows'],
            'summary' => $data['summary'],
            'note'    => $data['note'],
        ], [
            'count'       => count($data['rows']),
            'date_from'   => $filters['date_from'],
            'date_to'     => $filters['date_to'],
            'customer_id' => $customerId,
        ]);
    }

    /**
     * GET /api/v1/manager/reports/export - the same .xlsx as the panel.
     */
    public function export(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        foreach (['reports', 'excel_reports'] as $feature) {
            $denied = $this->requireFeature($customerId, $feature);

            if ($denied !== null) {
                return $denied;
            }
        }

        $type    = (string) $request->input('type', 'daily');
        $filters = $this->filters($request);

        if (!array_key_exists($type, ReportService::TYPES)) {
            return ApiResponse::validation(['type' => ['نوع گزارش نامعتبر است.']]);
        }

        $exporter = new ExcelExporter();
        $built    = $exporter->build($customerId, $type, $filters, ['customer_name' => '']);
        $file     = $exporter->toFile($built);

        return Response::make((string) file_get_contents($file['path']), 200)
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('Content-Length', (string) $file['bytes']);
    }

    /**
     * GET /api/v1/manager/payroll - preview (read only).
     */
    public function payroll(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $denied = $this->requireFeature($customerId, 'payroll');

        if ($denied !== null) {
            return $denied;
        }

        $service = new PayrollService();
        $filters = $this->filters($request);
        $items   = $service->preview($customerId, $filters);

        return ApiResponse::success([
            'period' => ['from' => $filters['date_from'], 'to' => $filters['date_to']],
            'items'  => $items,
            'periods' => $service->periods($customerId, 50),
        ], [
            'count'       => count($items),
            'customer_id' => $customerId,
            'calc_version' => \App\Services\PayrollCalculator::VERSION,
        ]);
    }

    /**
     * POST /api/v1/manager/payroll/finalize
     */
    public function finalize(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $denied = $this->requireFeature($customerId, 'payroll');

        if ($denied !== null) {
            return $denied;
        }

        $service  = new PayrollService();
        $filters  = $this->filters($request);
        $periodId = (int) $request->int('period_id', 0);

        if ($periodId <= 0) {
            $created = $service->createPeriod($this->actor(), $customerId, $filters['date_from'], $filters['date_to']);

            if (!$created['ok']) {
                return ApiResponse::error($created['code'], $created['message'], [], 422);
            }

            $periodId = (int) $created['data']['period_id'];
        }

        $result = $service->finalize($this->actor(), $customerId, $periodId);

        if (!$result['ok']) {
            return ApiResponse::error($result['code'], $result['message'], $result['data'], 409);
        }

        return ApiResponse::success($result['data'], [], 200, $result['code'], $result['message']);
    }

    /**
     * POST /api/v1/manager/payroll/revise
     */
    public function revise(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $denied = $this->requireFeature($customerId, 'payroll');

        if ($denied !== null) {
            return $denied;
        }

        $result = (new PayrollService())->revise(
            $this->actor(),
            $customerId,
            (int) $request->int('period_id', 0),
            (string) $request->input('reason', '')
        );

        if (!$result['ok']) {
            return ApiResponse::error($result['code'], $result['message'], $result['data'], 409);
        }

        return ApiResponse::success($result['data'], [], 200, $result['code'], $result['message']);
    }

    /**
     * GET /api/v1/manager/telegram - settings state + queue, never the token.
     */
    public function telegram(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $denied = $this->requireFeature($customerId, 'telegram');

        if ($denied !== null) {
            return $denied;
        }

        $service  = new TelegramService();
        $settings = $service->settings($customerId);

        return ApiResponse::success([
            'configured'     => $service->usable($customerId),
            'token_masked'   => $service->maskToken($settings),
            'chat_id'        => (string) ($settings['chat_id'] ?? ''),
            'is_active'      => (int) ($settings['is_active'] ?? 0) === 1,
            'notifications'  => [
                'entry'    => (int) $settings['notify_entry'] === 1,
                'exit'     => (int) $settings['notify_exit'] === 1,
                'late'     => (int) $settings['notify_late'] === 1,
                'overtime' => (int) $settings['notify_overtime'] === 1,
                'daily'    => (int) $settings['daily_report'] === 1,
            ],
            'daily_report_time' => (string) $settings['daily_report_time'],
            'queue'          => $service->queueStats($customerId),
            'last_ok_at'     => $settings['last_ok_at'] ?? null,
            'last_error'     => $settings['last_error'] ?? null,
        ], ['customer_id' => $customerId]);
    }

    /**
     * POST /api/v1/manager/telegram/daily - force today's summary.
     */
    public function telegramDaily(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $denied = $this->requireFeature($customerId, 'telegram');

        if ($denied !== null) {
            return $denied;
        }

        $date   = (string) ($request->input('date', date('Y-m-d')));
        $result = (new TelegramScheduler())->sendNow($customerId, substr($date, 0, 10));

        $status = (string) ($result['status'] ?? 'skipped');

        if ($status === 'sent' || $status === 'queued') {
            return ApiResponse::success($result, [], 200, 'TELEGRAM_REPORT_' . strtoupper($status));
        }

        return ApiResponse::error('TELEGRAM_REPORT_SKIPPED', 'گزارش روزانه ارسال نشد.', $result, 409);
    }

    /**
     * POST /api/v1/manager/telegram/drain - run the worker for this customer.
     */
    public function telegramDrain(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $denied = $this->requireFeature($customerId, 'telegram');

        if ($denied !== null) {
            return $denied;
        }

        $events = (new EventDispatcher())->dispatch(100);
        $drain  = (new TelegramService())->drain(30);

        return ApiResponse::success(['events' => $events, 'queue' => $drain], [], 200, 'TELEGRAM_WORKER_DONE');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,mixed>
     */
    private function filters(Request $request): array
    {
        $from = (string) $request->input('date_from', substr(date('Y-m-d'), 0, 8) . '01');
        $to   = (string) $request->input('date_to', date('Y-m-d'));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            $from = substr(date('Y-m-d'), 0, 8) . '01';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            $to = date('Y-m-d');
        }

        if ($to < $from) {
            $to = $from;
        }

        return [
            'date_from'   => $from,
            'date_to'     => $to,
            'employee_id' => (int) $request->int('employee_id', 0),
            'branch_id'   => (int) $request->int('branch_id', 0),
            'shift_id'    => (int) $request->int('shift_id', 0),
            'status'      => (string) $request->input('status', ''),
            'origin'      => (string) $request->input('origin', ''),
        ];
    }

    private function requireFeature(int $customerId, string $feature): ?Response
    {
        if (FeatureService::allows($customerId, $feature)) {
            return null;
        }

        return ApiResponse::error('FEATURE_DISABLED', 'این قابلیت برای مجموعه شما فعال نیست.', [
            'feature' => $feature,
        ], 403);
    }

    /**
     * @return array<string,mixed>
     */
    private function actor(): array
    {
        return [
            'id'   => (int) (Session::userId() ?? 0),
            'name' => (string) (Session::get('user_name') ?? ''),
            'role' => (string) (Session::role() ?? 'api'),
            'ip'   => Request::ip(),
        ];
    }
}
