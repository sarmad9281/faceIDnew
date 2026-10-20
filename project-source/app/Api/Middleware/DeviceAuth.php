<?php
/**
 * SARMAD Attendance - authenticates attendance devices.
 *
 * Two modes are supported:
 *   - bearer device token  (primary, issued by the panel)
 *   - device session token (short lived, returned by /api/v1/device/login)
 */

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\ApiResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Session;
use App\Core\TenantContext;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceLog;
use App\Models\DeviceSession;
use App\Middleware\MiddlewareInterface;
use App\Services\DeviceService;

final class DeviceAuth implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        $token = $request->bearerToken();

        if ($token === '') {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'توکن دستگاه ارسال نشده است.', [], 401);
        }

        // Device tokens travel in the Authorization header only. The generic
        // Request helper has a `?token=` fallback for legacy kiosk/proxy
        // setups, but a long lived device credential must never end up in a
        // URL, a browser history or a proxy log - so it is refused here.
        $header = $request->header('authorization');

        if (stripos($header, 'bearer ') !== 0) {
            DeviceLog::record('device.auth.failed', 'warning', 'توکن دستگاه از هدر ارسال نشد.', null, 0, [
                'reason' => 'token_not_in_header',
            ]);

            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'توکن دستگاه باید در هدر Authorization ارسال شود.', [], 401);
        }

        // 1) Device session token (att_sess_*)
        if (strncmp($token, 'att_sess_', 9) === 0) {
            $session = DeviceSession::findActiveByHash(Security::hashToken($token));

            if ($session === null) {
                // A reset by the owner revokes the sessions immediately, so the
                // device must activate again - it is not "unauthorised forever".
                return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'نشست دستگاه منقضی شده است. لطفاً دستگاه را دوباره فعال کنید.', [], 401);
            }

            $device = Device::find((int) $session['device_id']);

            if ($device === null) {
                return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه یافت نشد.', [], 401);
            }

            if ((string) $device['status'] !== Device::STATUS_ACTIVE) {
                return $this->refused((string) $device['status'], $device);
            }

            DeviceSession::touch((int) $session['id']);

            return $this->authorised($device, $request, $next, 'session');
        }

        // 2) Long lived device token (att_*)
        $service = new DeviceService();
        $result  = $service->authenticateTokenDetailed($token);

        if (!$result['ok']) {
            /** @var array<string,mixed>|null $device */
            $device = $result['device'] ?? null;

            AuditLog::device('device.auth.failed', 'failure', 'احراز هویت دستگاه رد شد.', [
                'device_id' => $device === null ? null : (int) $device['id'],
                'code'      => $result['code'],
                'reason'    => $result['reason'],
            ]);

            return ApiResponse::deviceError($result['code'], $this->failureMessage($result['code']), [], 401);
        }

        return $this->authorised($result['device'], $request, $next, 'token');
    }

    /**
     * Persian message per documented device auth failure code.
     */
    private function failureMessage(string $code): string
    {
        switch ($code) {
            case 'DEVICE_INACTIVE':
                return 'این دستگاه فعال نشده یا موقتاً غیرفعال است.';
            case 'DEVICE_REVOKED':
                return 'این دستگاه توسط مدیر سامانه لغو شده است.';
            case 'CUSTOMER_DISABLED':
                return 'حساب این مجموعه غیرفعال است.';
            case 'FEATURE_DISABLED':
                return 'سرویس حضور و غیاب برای این مجموعه فعال نیست.';
            default:
                return 'توکن دستگاه نامعتبر یا منقضی است.';
        }
    }

    /**
     * A device row that exists but is not active: answer with the exact code so
     * the kiosk can show the right screen (pending -> "activate me",
     * suspended -> "contact your manager", revoked -> "this device is blocked").
     *
     * @param array<string,mixed> $device
     */
    private function refused(string $status, array $device): Response
    {
        $code = $status === Device::STATUS_REVOKED ? 'DEVICE_REVOKED' : 'DEVICE_INACTIVE';

        DeviceLog::record('device.auth.failed', 'warning', 'دستگاه غیرفعال درخواست فرستاد.', (int) $device['id'], (int) $device['customer_id'], [
            'status' => $status,
            'code'   => $code,
        ]);

        return ApiResponse::deviceError($code, $this->failureMessage($code), ['status' => $status], 401);
    }

    /**
     * @param array<string,mixed> $device
     */
    private function authorised(array $device, Request $request, callable $next, string $mode = 'token'): Response
    {
        TenantContext::set((int) $device['customer_id'], 'device');

        Session::loginDevice([
            'id'          => (int) $device['id'],
            'uuid'        => (string) $device['uuid'],
            'device_uid'  => (string) $device['device_uid'],
            'name'        => (string) $device['name'],
            'customer_id' => (int) $device['customer_id'],
            'branch_id'   => isset($device['branch_id']) ? (int) $device['branch_id'] : null,
            'status'      => (string) $device['status'],
        ]);

        $deviceId = (int) $device['id'];

        // Device level trail (every authenticated request is visible on the
        // device page of the owner panel).
        $recent = DeviceLog::recentCountForEvent($deviceId, 'device.auth.success', 300);

        DeviceLog::record('device.auth.success', 'info', 'احراز هویت دستگاه موفق بود.', $deviceId, (int) $device['customer_id'], [
            'mode'    => $mode,
            'path'    => $request->path(),
            'method'  => $request->method(),
        ]);

        // The audit trail gets a throttled copy: a kiosk sending a heartbeat
        // every 60 seconds must not flood attendance_audit_logs.
        if ($recent === 0) {
            AuditLog::device('device.auth.success', 'success', 'احراز هویت دستگاه موفق بود.', [
                'device_id' => $deviceId,
                'mode'      => $mode,
            ]);
        }

        return $next($request);
    }
}
