<?php
/**
 * SARMAD Attendance - device facing API.
 *
 * Stage 1 exposes the lifecycle endpoints only. Punch / face endpoints
 * are added in the next stage on top of the same authentication.
 */

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\DeviceService;

final class DeviceApiController
{
    private DeviceService $devices;

    public function __construct()
    {
        $this->devices = new DeviceService();
    }

    /**
     * POST /api/v1/device/login
     * Body: { token }  ->  short lived session token
     */
    public function login(Request $request): Response
    {
        $validator = Validator::make(['token' => $request->payload('token', $request->bearerToken())], [
            'token' => 'required|string|max:200',
        ], ['token' => 'توکن دستگاه']);

        if ($validator->fails()) {
            return ApiResponse::validation($validator->errors());
        }

        $result = $this->devices->loginWithToken((string) $request->payload('token', $request->bearerToken()));

        if (!$result['ok']) {
            return ApiResponse::unauthorized($result['message']);
        }

        return ApiResponse::success([
            'session_token' => $result['session_token'],
            'device'        => self::publicDevice($result['device']),
        ]);
    }

    /**
     * POST /api/v1/device/heartbeat
     */
    public function heartbeat(Request $request): Response
    {
        $device = Session::device();

        if ($device === null) {
            return ApiResponse::unauthorized();
        }

        $clockOffset = $request->payload('clock_offset_seconds', 0);

        $this->devices->touch(
            (int) $device['id'],
            is_numeric($clockOffset) ? (int) $clockOffset : 0,
            (string) $request->payload('app_version', '')
        );

        $fresh = Device::find((int) $device['id']);

        return ApiResponse::success([
            'device'      => self::publicDevice($fresh),
            'server_time' => date('c'),
            'config'      => [
                'offline_after' => (int) \App\Core\Config::get('security.device_offline_after', 300),
            ],
        ]);
    }

    /**
     * GET /api/v1/device/me
     */
    public function me(Request $request): Response
    {
        $device = Session::device();

        if ($device === null) {
            return ApiResponse::unauthorized();
        }

        $fresh = Device::find((int) $device['id']);

        return ApiResponse::success([
            'device'   => self::publicDevice($fresh),
            'branch'   => $fresh !== null && !empty($fresh['branch_id']) ? (int) $fresh['branch_id'] : null,
            'server_time' => date('c'),
        ]);
    }

    /**
     * POST /api/v1/devices  (manager/owner token, tenant scoped)
     */
    public function register(Request $request): Response
    {
        $customerId = TenantContext::customerId();

        if ($customerId === null || $customerId <= 0) {
            return ApiResponse::error('tenant_scope_required', 'برای ثبت دستگاه باید محدوده مشتری مشخص باشد.', [], 400);
        }

        $validator = Validator::make($request->json() + $request->all(), [
            'name'          => 'required|string|max:120',
            'branch_id'     => 'integer',
            'type'          => 'in:kiosk,tablet,mobile,desktop',
            'platform'      => 'string|max:60',
            'app_version'   => 'string|max:30',
            'device_uid'    => 'alpha_dash|max:64',
            'serial_number' => 'string|max:80',
        ], ['name' => 'نام دستگاه']);

        if ($validator->fails()) {
            return ApiResponse::validation($validator->errors());
        }

        $result = $this->devices->register($customerId, $validator->validated());

        if (!$result['ok']) {
            return ApiResponse::error('device_registration_failed', $result['message'], [], 409);
        }

        return ApiResponse::success([
            'device'       => self::publicDevice($result['device'] ?? null),
            'pairing_code' => $result['pairing_code'] ?? null,
            'status'       => 'pending',
        ], ['notice' => 'برای استفاده، دستگاه باید فعال شود و توکن دریافت کند.'], 201);
    }

    /**
     * POST /api/v1/devices/{id}/activate
     */
    public function activate(Request $request): Response
    {
        $deviceId = $request->int('device_id', 0);

        if ($deviceId <= 0) {
            return ApiResponse::validation(['device_id' => ['شناسه دستگاه الزامی است.']]);
        }

        $result = $this->devices->activate($deviceId);

        if (!$result['ok']) {
            return ApiResponse::notFound($result['message']);
        }

        return ApiResponse::success([
            'device'     => self::publicDevice($result['device'] ?? null),
            'token'      => $result['token'],
            'expires_at' => $result['expires_at'] ?? null,
        ], ['notice' => 'این توکن فقط یک بار نمایش داده می‌شود. آن را امن نگه دارید.']);
    }

    /**
     * POST /api/v1/devices/{id}/rotate-token
     */
    public function rotateToken(Request $request): Response
    {
        $deviceId = $request->int('device_id', 0);

        if ($deviceId <= 0) {
            return ApiResponse::validation(['device_id' => ['شناسه دستگاه الزامی است.']]);
        }

        $result = $this->devices->rotateToken($deviceId);

        if (!$result['ok']) {
            return ApiResponse::notFound($result['message']);
        }

        return ApiResponse::success([
            'token'   => $result['token'],
            'prefix'  => $result['prefix'] ?? null,
        ], ['notice' => 'توکن قبلی باطل شد.']);
    }

    /**
     * POST /api/v1/devices/{id}/revoke
     */
    public function revoke(Request $request): Response
    {
        $deviceId = $request->int('device_id', 0);

        if ($deviceId <= 0) {
            return ApiResponse::validation(['device_id' => ['شناسه دستگاه الزامی است.']]);
        }

        $result = $this->devices->revoke($deviceId, (string) $request->payload('reason', 'api revoke'));

        if (!$result['ok']) {
            return ApiResponse::notFound($result['message']);
        }

        return ApiResponse::success(['revoked' => true]);
    }

    /**
     * GET /api/v1/devices
     */
    public function index(Request $request): Response
    {
        $customerId = TenantContext::customerId();

        if ($customerId === null || $customerId <= 0) {
            return ApiResponse::error('tenant_scope_required', 'محدوده مشتری مشخص نیست.', [], 400);
        }

        $devices = Device::forCustomer($customerId, (string) $request->input('status', ''));

        return ApiResponse::success(array_map([self::class, 'publicDevice'], $devices), [
            'count' => count($devices),
            'stats' => $this->devices->inventory($customerId),
        ]);
    }

    /**
     * POST /api/v1/devices/{id}/status
     */
    public function status(Request $request): Response
    {
        $deviceId = $request->int('device_id', 0);

        if ($deviceId <= 0) {
            return ApiResponse::validation(['device_id' => ['شناسه دستگاه الزامی است.']]);
        }

        $result = $this->devices->status($deviceId);

        if (($result['ok'] ?? false) !== true) {
            return ApiResponse::notFound((string) ($result['message'] ?? 'دستگاه یافت نشد.'));
        }

        return ApiResponse::success($result['device']);
    }

    /**
     * Never expose the token hash or pairing secret.
     *
     * @param array<string,mixed>|null $device
     * @return array<string,mixed>|null
     */
    public static function publicDevice(?array $device): ?array
    {
        if ($device === null) {
            return null;
        }

        $offlineAfter = (int) \App\Core\Config::get('security.device_offline_after', 300);

        return [
            'id'            => (int) $device['id'],
            'uuid'          => (string) $device['uuid'],
            'device_uid'    => (string) $device['device_uid'],
            'name'          => (string) $device['name'],
            'type'          => (string) $device['type'],
            'status'        => (string) $device['status'],
            'customer_id'   => isset($device['customer_id']) ? (int) $device['customer_id'] : null,
            'branch_id'     => isset($device['branch_id']) && $device['branch_id'] !== null ? (int) $device['branch_id'] : null,
            'token_prefix'  => $device['token_prefix'] ?? null,
            'app_version'   => $device['app_version'] ?? null,
            'platform'      => $device['platform'] ?? null,
            'online'        => Device::isOnline($device, $offlineAfter),
            'last_seen_at'  => $device['last_seen_at'] ?? null,
            'last_seen_ip'  => $device['last_seen_ip'] ?? null,
            'activated_at'  => $device['activated_at'] ?? null,
            'created_at'    => $device['created_at'] ?? null,
        ];
    }
}
