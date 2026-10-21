<?php
/**
 * SARMAD Attendance - KIOSK DEVICE API (stage 4).
 *
 * These are the endpoints the attendance device (tablet / phone / desktop in
 * kiosk mode) talks to. Everything here is scoped to the authenticated device:
 *
 *   - the device token identifies the device,
 *   - the device row identifies the customer,
 *   - the customer identifies the tenant.
 *
 * A device therefore can never ask for another customer's data: there is no
 * parameter that changes the tenant, and every lookup uses the customer id that
 * came from the token (never from the request body/query).
 *
 * Stage 4 scope:
 *   activation (one-time code), config/branding, heartbeat, status, non
 *   sensitive settings, connection error reporting.
 *
 * Stage 5 scope (face recognition):
 *   - GET  /face-data            the descriptor gallery of THIS device's own
 *                                customer (branch scoped) + data version,
 *   - POST /recognition-result   the device reports the outcome of the LOCAL
 *                                match it performed (employee id + distance);
 *                                the server validates tenant, branch, employee
 *                                status and the 3/3 face requirement,
 *   - GET  /employees/{id}/photo the enrolment snapshot / profile photo,
 *                                streamed only to an authenticated device of
 *                                the same customer.
 *
 *   Recognition itself NEVER happens on the server: no camera image leaves the
 *   device. `/recognition` (server side, image based) therefore keeps
 *   answering FACE_ENGINE_UNAVAILABLE - that engine does not exist on purpose.
 *   Recording an attendance row is still the next stage: /attendance validates
 *   everything and then answers ATTENDANCE_ENGINE_NOT_READY. Nothing is faked.
 */

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiResponse;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceLog;
use App\Models\DeviceSession;
use App\Models\Employee;
use App\Models\DeviceRequest;
use App\Models\DeviceSetting;
use App\Models\FaceEmbedding;
use App\Services\AttendanceEngine;
use App\Services\DeviceService;
use App\Services\FaceDatasetService;

final class DeviceKioskController
{
    private DeviceService $devices;

    public function __construct()
    {
        $this->devices = new DeviceService();
    }

    /* =================================================================
     * Activation (no device token yet - the code IS the credential)
     * ===============================================================*/

    /**
     * POST /api/v1/device/activate
     *
     * Body: { code, platform?, app_version?, device_uid? }
     */
    public function activate(Request $request): Response
    {
        $code = trim((string) $request->payload('code', ''));

        if ($code === '') {
            return ApiResponse::deviceError('ACTIVATION_CODE_REQUIRED', 'کد فعال‌سازی را وارد کنید.', [], 422);
        }

        if (strlen($code) > 32) {
            return ApiResponse::deviceError('ACTIVATION_CODE_INVALID', 'کد فعال‌سازی نامعتبر است.', [], 422);
        }

        $result = $this->devices->activateWithCode($code, [
            'platform'    => (string) $request->payload('platform', ''),
            'app_version' => (string) $request->payload('app_version', ''),
            'device_uid'  => (string) $request->payload('device_uid', ''),
        ]);

        if (!$result['ok']) {
            return ApiResponse::deviceError(
                (string) $result['code'],
                (string) $result['message'],
                [],
                (int) ($result['http'] ?? 422)
            );
        }

        /** @var array<string,mixed> $device */
        $device = $result['device'];

        return ApiResponse::deviceSuccess([
            'token'      => $result['token'],
            'expires_at' => $result['expires_at'],
            'device'     => self::publicDevice($device),
            'config'     => $this->devices->configFor($device),
        ], 'OK', 'دستگاه فعال شد.');
    }

    /* =================================================================
     * Authenticated device endpoints (device.token middleware)
     * ===============================================================*/

    /**
     * GET /api/v1/device/config
     *
     * Branding (logo, business name, branch), feature flags, device settings
     * and the engine contract. Only ever the device's OWN customer.
     */
    public function config(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        $config = $this->devices->configFor($device);

        DeviceLog::record('device.config.loaded', 'info', 'تنظیمات و برندینگ دریافت شد.', (int) $device['id'], (int) $device['customer_id'], [
            'config_version' => $this->devices->configVersion((int) $device['id']),
        ]);

        return ApiResponse::deviceSuccess($config);
    }

    /**
     * GET /api/v1/device/status
     */
    public function status(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        $deviceId = (int) $device['id'];
        $fresh    = Device::find($deviceId) ?? $device;

        return ApiResponse::deviceSuccess([
            'device' => self::publicDevice($fresh) + [
                'last_seen_at'   => $fresh['last_seen_at'] ?? null,
                'last_error_at'  => $fresh['last_error_at'] ?? null,
                'last_error_code' => $fresh['last_error_code'] ?? null,
            ],
            'online'         => Device::isOnline($fresh, (int) Config::get('security.device_offline_after', 300)),
            'open_requests'  => DeviceRequest::recentCount($deviceId, 60),
            'server_time'    => date('c'),
            'config_version' => $this->devices->configVersion($deviceId),
        ]);
    }

    /**
     * POST /api/v1/device/heartbeat
     *
     * Body: { clock_offset?, app_version? }
     */
    public function heartbeat(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        $payload = [
            // `clock_offset` is the stage 4 name; `clock_offset_seconds` is the
            // stage 1 name and still accepted so old clients keep working.
            'clock_offset' => (int) $request->payload('clock_offset', (int) $request->payload('clock_offset_seconds', 0)),
            'app_version'  => (string) $request->payload('app_version', ''),
        ];

        $result = $this->devices->heartbeatFor($device, $payload);

        return ApiResponse::deviceSuccess([
            'device'           => $result['device'],
            'server_time'      => $result['server_time'],
            'config_version'   => $result['config_version'],
            'pending_settings' => $result['pending_settings'],
            'offline_after'    => (int) Config::get('security.device_offline_after', 300),
        ]);
    }

    /**
     * GET|POST /api/v1/device/settings - read / write the NON SENSITIVE
     * device preferences (allow list is enforced server side).
     */
    public function settings(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        $deviceId = (int) $device['id'];

        if ($request->method() !== 'POST') {
            return ApiResponse::deviceSuccess([
                'settings' => DeviceSetting::allTyped($deviceId),
                'allowed'  => array_keys(DeviceSetting::KEYS),
            ]);
        }

        $values = $request->payload('settings', []);

        if (!is_array($values) || $values === []) {
            return ApiResponse::deviceError('NO_SETTINGS', 'هیچ تنظیمی ارسال نشد.', [], 422);
        }

        if (count($values) > 20) {
            return ApiResponse::deviceError('TOO_MANY_SETTINGS', 'تعداد کلیدهای ارسالی بیش از حد مجاز است.', [], 422);
        }

        // updated_by = 0: this is a device local preference change, the audit
        // row records the device (actor) instead of a user.
        $result = $this->devices->applySettings($device, $values, 0);

        return ApiResponse::deviceSuccess([
            'settings' => $result['settings'],
            'saved'    => $result['saved'],
            'rejected' => $result['rejected'],
        ], (string) $result['code'], (string) $result['message']);
    }

    /**
     * POST /api/v1/device/error - the kiosk reports a connection / camera
     * failure so the owner panel can see why a device is silent.
     */
    public function reportError(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        $code    = strtoupper(substr(preg_replace('/[^A-Za-z0-9_:.-]/', '', (string) $request->payload('code', 'DEVICE_ERROR')) ?? '', 0, 60));
        $message = substr((string) $request->payload('message', ''), 0, 255);

        DeviceLog::record(
            'device.connection.error',
            'error',
            $message === '' ? 'خطای سمت دستگاه گزارش شد.' : $message,
            (int) $device['id'],
            (int) $device['customer_id'],
            ['code' => $code]
        );

        DeviceLog::markDeviceError((int) $device['id'], $code);

        return ApiResponse::deviceSuccess(['logged' => true, 'code' => $code], 'OK', 'خطا ثبت شد.');
    }

    /**
     * GET /api/v1/device/engine - the contract discovery endpoint.
     */
    public function engine(Request $request): Response
    {
        if ($this->requireDevice() === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        return ApiResponse::deviceSuccess($this->devices->engineContract());
    }

    /**
     * POST /api/v1/device/recognition
     *
     * Contract only: validated, then answered FACE_ENGINE_UNAVAILABLE (503).
     */
    public function recognition(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        if (!$this->devices->attendanceEnabled((int) $device['customer_id'])) {
            DeviceLog::record('device.recognition.error', 'warning', 'سرویس حضور و غیاب برای این مجموعه غیرفعال است.', (int) $device['id'], (int) $device['customer_id'], [
                'code' => 'FEATURE_DISABLED',
            ]);

            return ApiResponse::deviceError('FEATURE_DISABLED', 'سرویس حضور و غیاب برای این مجموعه فعال نیست.', [], 403);
        }

        $result = $this->devices->recognitionContract($device, $request->payloadAll());

        if ($result['ok']) {
            return ApiResponse::deviceSuccess($result['data'] ?? [], 'OK', (string) $result['message']);
        }

        return ApiResponse::deviceError(
            (string) $result['code'],
            (string) $result['message'],
            ['contract' => $result['contract'] ?? $this->devices->engineContract()],
            (int) ($result['http'] ?? 422)
        );
    }

    /**
     * POST /api/v1/device/attendance
     *
     * Contract only: validated (tenant, employee, canAttend, branch, duplicate)
     * then answered ATTENDANCE_ENGINE_NOT_READY (503).
     */
    public function attendance(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        if (!$this->devices->attendanceEnabled((int) $device['customer_id'])) {
            DeviceLog::record('device.recognition.error', 'warning', 'سرویس حضور و غیاب برای این مجموعه غیرفعال است.', (int) $device['id'], (int) $device['customer_id'], [
                'code' => 'FEATURE_DISABLED',
            ]);

            return ApiResponse::deviceError('FEATURE_DISABLED', 'سرویس حضور و غیاب برای این مجموعه فعال نیست.', [], 403);
        }

        // Compatibility entry point: the action MUST be explicit (entry|exit).
        // There is no default, so a client can never turn a "read" style call
        // into a write by accident, and both paths share one engine.
        $action = strtolower(trim((string) $request->payload('action', '')));

        if ($action === 'entry') {
            return $this->respond($this->attendanceEngine()->recordEntry($device, $request->payloadAll()));
        }

        if ($action === 'exit') {
            return $this->respond($this->attendanceEngine()->recordExit($device, $request->payloadAll()));
        }

        return ApiResponse::deviceError('ATTENDANCE_ACTION_REQUIRED',
            'نوع عملیات (entry یا exit) مشخص نشده است.', [], 422);
    }

    /**
     * POST /api/v1/device/attendance/entry
     *
     * The kiosk chose «ورود», recognised the face locally and comes with the
     * one time proof of that recognition. The engine validates everything
     * server side (device, customer, employee, shift, open session) and writes
     * the record - the client can NOT register attendance by posting an
     * employee id.
     */
    public function attendanceEntry(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        if (!$this->devices->attendanceEnabled((int) $device['customer_id'])) {
            return $this->featureDisabled($device);
        }

        return $this->respond($this->attendanceEngine()->recordEntry($device, $request->payloadAll()));
    }

    /**
     * POST /api/v1/device/attendance/exit
     *
     * Closes the OPEN session of the recognised employee. No open session ->
     * «برای شما ورود فعالی ثبت نشده است.» and nothing is written.
     */
    public function attendanceExit(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        if (!$this->devices->attendanceEnabled((int) $device['customer_id'])) {
            return $this->featureDisabled($device);
        }

        return $this->respond($this->attendanceEngine()->recordExit($device, $request->payloadAll()));
    }

    /**
     * POST /api/v1/device/attendance/sync
     *
     * The offline queue: a batch of events the device recognised and stored
     * while it had no connection. Each item is re-validated here (proof, tenant,
     * employee, duplicate) and answered separately with ACCEPTED / DUPLICATE /
     * REJECTED / INVALID, so the device knows exactly what it may drop from its
     * queue - it never drops an item the server refused.
     */
    public function attendanceSync(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        if (!$this->devices->attendanceEnabled((int) $device['customer_id'])) {
            return $this->featureDisabled($device);
        }

        return $this->respond($this->attendanceEngine()->syncBatch($device, $request->payloadAll()));
    }

    private function attendanceEngine(): AttendanceEngine
    {
        return new AttendanceEngine();
    }

    /** @param array<string,mixed> $result */
    private function respond(array $result): Response
    {
        if (!empty($result['ok'])) {
            return ApiResponse::deviceSuccess(
                (array) ($result['data'] ?? []),
                (string) ($result['code'] ?? 'OK'),
                (string) ($result['message'] ?? '')
            );
        }

        return ApiResponse::deviceError(
            (string) $result['code'],
            (string) $result['message'],
            (array) ($result['data'] ?? []),
            (int) ($result['http'] ?? 422)
        );
    }

    private function featureDisabled(array $device): Response
    {
        DeviceLog::record('device.attendance.error', 'warning', 'سرویس حضور و غیاب برای این مجموعه غیرفعال است.', (int) $device['id'], (int) $device['customer_id'], [
            'code' => 'FEATURE_DISABLED',
        ]);

        return ApiResponse::deviceError('FEATURE_DISABLED', 'سرویس حضور و غیاب برای این مجموعه فعال نیست.', [], 403);
    }

    /**
     * POST /api/v1/device/forget - the kiosk drops its local credential.
     * Server side this only clears the sessions; the long lived token can only
     * be revoked from the OWNER panel.
     */
    public function forget(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        \App\Models\DeviceSession::revokeAllForDevice((int) $device['id'], 'device_forget');

        DeviceLog::record('device.deactivated', 'warning', 'دستگاه اعتبار محلی خود را حذف کرد.', (int) $device['id'], (int) $device['customer_id']);

        return ApiResponse::deviceSuccess(['forgotten' => true], 'OK', 'اعتبار محلی دستگاه حذف شد.');
    }

    /* =================================================================
     * Helpers
     * ===============================================================*/

    /**
     * The authenticated device of the current request (set by DeviceAuth).
     *
     * @return array<string,mixed>|null
     */
    private function requireDevice(): ?array
    {
        $device = Session::device();

        if ($device === null) {
            return null;
        }

        return Device::find((int) $device['id']);
    }

    /**
     * Never expose the token hash / prefix / internal columns to the client.
     *
     * @param array<string,mixed>|null $device
     * @return array<string,mixed>
     */
    public static function publicDevice(?array $device): array
    {
        if ($device === null) {
            return [];
        }

        return [
            'id'           => (int) $device['id'],
            'uuid'         => (string) $device['uuid'],
            'device_uid'   => (string) $device['device_uid'],
            'name'         => (string) $device['name'],
            'type'         => (string) $device['type'],
            'status'       => (string) $device['status'],
            'customer_id'  => (int) $device['customer_id'],
            'branch_id'    => isset($device['branch_id']) ? (int) $device['branch_id'] : null,
            'platform'     => (string) ($device['platform'] ?? ''),
            'app_version'  => (string) ($device['app_version'] ?? ''),
            'activated_at' => $device['activated_at'] ?? null,
            'last_seen_at' => $device['last_seen_at'] ?? null,
        ];
    }

    /* =================================================================
     * Stage 5 - face recognition (local on the device, verified here)
     * ===============================================================*/

    /**
     * GET /api/v1/device/face-data[?since=VERSION]
     *
     * The gallery this device is allowed to know - and nothing else:
     *   * only employees of the device's own customer,
     *   * only ACTIVE employees whose registration is complete (3/3),
     *   * branch scoped when the deployment enables it,
     *   * descriptors of the current model version only.
     *
     * With ?since=<version> the endpoint answers a tiny "unchanged" object
     * when the dataset did not move, so a kiosk can poll cheaply.
     */
    public function faceData(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        $customerId = (int) $device['customer_id'];

        if (!$this->devices->attendanceEnabled($customerId)) {
            return ApiResponse::deviceError('FEATURE_DISABLED', 'سرویس حضور و غیاب برای این مجموعه فعال نیست.', [], 403);
        }

        $face       = new FaceDatasetService();
        $versionInfo = $face->versionInfoForDevice($device);
        $since      = $request->int('since', -1);

        if ($since >= 0 && $since === (int) $versionInfo['version']) {
            // The device already has this exact dataset.
            $face->markDeviceSynced($device, (int) $versionInfo['version'], 0);

            return ApiResponse::deviceSuccess([
                'changed'       => false,
                'version'       => (int) $versionInfo['version'],
                'model_version' => $versionInfo['model_version'],
            ], 'OK', 'داده چهره تغییری نکرده است.');
        }

        $dataset = $face->datasetForDevice($device);

        $face->markDeviceSynced($device, (int) $dataset['version'], count($dataset['employees']));

        \App\Models\AuditLog::device('device.face.sync', 'success', 'داده چهره دستگاه هم‌گام شد.', [
            'device_id'   => (int) $device['id'],
            'customer_id' => $customerId,
            'version'     => (int) $dataset['version'],
            'employees'   => count($dataset['employees']),
        ]);

        return ApiResponse::deviceSuccess([
            'changed' => true,
        ] + $dataset, 'OK', 'داده چهره هم‌گام شد.');
    }

    /**
     * POST /api/v1/device/recognition-result
     *
     * Body: { request_id, outcome, action?, employee_id?, confidence?, distance?, sample_index?, model_version? }
     *
     * outcome: matched | no_face | multiple_faces | unknown_face | ambiguous
     *
     * The match was computed ON THE DEVICE from the descriptors downloaded
     * from /face-data. The server verifies everything it can without an image
     * (tenant, branch, employee status, 3/3 face registration, distance
     * threshold) and writes the device log / audit trail. Nothing about a
     * failed recognition is written to attendance.
     */
    public function recognitionResult(Request $request): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        if (!$this->devices->attendanceEnabled($customerId)) {
            return ApiResponse::deviceError('FEATURE_DISABLED', 'سرویس حضور و غیاب برای این مجموعه فعال نیست.', [], 403);
        }

        $requestId = trim((string) $request->payload('request_id', ''));

        if (preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $requestId) !== 1) {
            return ApiResponse::deviceError('INVALID_REQUEST_ID', 'شناسه درخواست (request_id) لازم است.', [], 422);
        }

        if (!DeviceRequest::claim($deviceId, $customerId, 'recognition', $requestId)) {
            // A double tap on the kiosk must not produce two log rows.
            $claim = DeviceRequest::findClaim($deviceId, $requestId);

            return ApiResponse::deviceSuccess([
                'duplicate' => true,
                'outcome'   => $claim === null ? null : (string) $claim['status'],
            ], 'DUPLICATE_REQUEST', 'این درخواست قبلاً پردازش شده است.', 200);
        }

        $payload = [
            'outcome'       => strtolower(trim((string) $request->payload('outcome', ''))),
            'employee_id'   => $request->int('employee_id', 0),
            'confidence'    => $request->payload('confidence', null),
            'distance'      => $request->payload('distance', null),
            'sample_index'  => $request->int('sample_index', 0),
            'model_version' => (string) $request->payload('model_version', ''),
            'action'        => strtolower(trim((string) $request->payload('action', ''))),
            'occurred_at'   => (string) $request->payload('occurred_at', date('c')),
        ];

        $service = new FaceDatasetService();
        $result  = $service->verifyRecognition($device, $payload);

        $status = (string) $result['code'];

        DeviceRequest::finish($deviceId, $requestId, $status, $result['ok']);

        // Device log + audit trail: every outcome is recorded, including the
        // failures, because the owner has to be able to see them.
        $eventMap = [
            'FACE_MATCHED'   => ['device.recognition.matched', 'info'],
            'NO_FACE'        => ['device.recognition.no_face', 'warning'],
            'MULTIPLE_FACES' => ['device.recognition.multiple_faces', 'warning'],
            'UNKNOWN_FACE'   => ['device.recognition.unknown', 'warning'],
            'AMBIGUOUS'      => ['device.recognition.ambiguous', 'warning'],
            'EMPLOYEE_INACTIVE' => ['device.recognition.rejected', 'warning'],
            'EMPLOYEE_FACE_INCOMPLETE' => ['device.recognition.rejected', 'warning'],
            'BRANCH_MISMATCH' => ['device.recognition.rejected', 'warning'],
            'EMPLOYEE_NOT_FOUND' => ['device.recognition.rejected', 'warning'],
        ];

        [$event, $level] = $eventMap[$status] ?? ['device.recognition.error', 'warning'];

        DeviceLog::record($event, $level, $result['message'], $deviceId, $customerId, [
            'request_id'  => $requestId,
            'outcome'     => $payload['outcome'],
            'code'        => $status,
            'employee_id' => $payload['employee_id'] > 0 ? $payload['employee_id'] : null,
            'distance'    => $result['data']['distance'] ?? null,
            'action'      => $payload['action'] === '' ? null : $payload['action'],
        ]);

        if ($status === 'FACE_MATCHED') {
            \App\Models\AuditLog::record('device.recognition.matched', 'success',
                'چهره شناسایی شد (کارمند #' . (int) $payload['employee_id'] . ').',
                [
                    'device_id'   => $deviceId,
                    'customer_id' => $customerId,
                    'employee_id' => (int) $payload['employee_id'],
                    'distance'    => $result['data']['distance'] ?? null,
                    'action'      => $payload['action'],
                ],
                'device', 'device', $deviceId, $customerId);
        } elseif (in_array($status, ['UNKNOWN_FACE', 'AMBIGUOUS'], true)) {
            \App\Models\AuditLog::record('device.recognition.' . strtolower($status), 'failure',
                $result['message'],
                [
                    'device_id'   => $deviceId,
                    'customer_id' => $customerId,
                    'distance'    => $result['data']['distance'] ?? null,
                ],
                'device', 'device', $deviceId, $customerId);
        }

        if (!$result['ok']) {
            return ApiResponse::deviceError($result['code'], $result['message'], $result['data'], 200);
        }

        return ApiResponse::deviceSuccess($result['data'], $result['code'], $result['message']);
    }

    /**
     * GET /api/v1/device/employees/{id}/photo
     *
     * The success screen may show the employee photo. It is streamed through a
     * device authenticated endpoint (never a public uploads URL) and only for
     * an employee of the device's own customer + branch scope.
     */
    public function employeePhoto(Request $request, string $id = '0'): Response
    {
        $device = $this->requireDevice();

        if ($device === null) {
            return ApiResponse::deviceError('DEVICE_UNAUTHORIZED', 'دستگاه احراز هویت نشده است.', [], 401);
        }

        $employeeId = (int) $id;
        $customerId = (int) $device['customer_id'];

        $employee = \App\Models\Employee::findInCustomer($employeeId, $customerId);

        if ($employee === null || !\App\Models\Branch::deviceMayUseEmployee($device, $employee)) {
            // Identical answer for "unknown" and "other tenant": a device can
            // not probe which employee ids exist elsewhere.
            return ApiResponse::deviceError('EMPLOYEE_NOT_FOUND', 'تصویر کارمند یافت نشد.', [], 404);
        }

        $photo = (new FaceDatasetService())->photoForEmployee($employeeId, $customerId);

        if ($photo === null) {
            return ApiResponse::deviceError('EMPLOYEE_PHOTO_NOT_FOUND', 'برای این کارمند تصویری ثبت نشده است.', [], 404);
        }

        $body = (string) @file_get_contents($photo['path']);

        if ($body === '') {
            return ApiResponse::deviceError('EMPLOYEE_PHOTO_NOT_FOUND', 'تصویر کارمند قابل خواندن نیست.', [], 404);
        }

        return Response::make($body, 200)
            ->withHeader('Content-Type', $photo['mime'])
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
