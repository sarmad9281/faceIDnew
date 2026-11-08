<?php
/**
 * SARMAD Attendance - device lifecycle service.
 *
 * Stage 1 scope: registration, activation, token issuing / rotation,
 * authentication of device API calls, heartbeat and status. The camera,
 * face recognition and punch logic arrive in the next stage and will
 * build on exactly these primitives.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Security;
use App\Core\TenantContext;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Core\Session;
use App\Models\Device;
use App\Models\DeviceActivationCode;
use App\Models\DeviceLog;
use App\Models\DeviceRequest;
use App\Models\DeviceSession;
use App\Models\DeviceSetting;
use App\Models\Employee;
use App\Models\FaceEmbedding;
use App\Services\FaceDatasetService;

final class DeviceService
{
    /* =================================================================
     * Registration & activation
     * ===============================================================*/

    /**
     * Register a new device inside a customer. The device cannot do
     * anything yet: it is created with status "pending".
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool, message:string, device?:array<string,mixed>}
     */
    public function register(int $customerId, array $data): array
    {
        if ($customerId <= 0 || !Customer::isActive($customerId)) {
            return ['ok' => false, 'message' => 'مشتری معتبر نیست.'];
        }

        $limit = 5;

        $customer = Customer::find($customerId);

        if ($customer !== null) {
            $limit = (int) $customer['max_devices'];
        }

        $current = Device::countWhere(['customer_id' => $customerId, 'deleted_at' => null]);

        if ($current >= $limit) {
            AuditLog::device('device.register', 'denied', 'سقف تعداد دستگاه‌های مجاز مشتری پر شده است.', [
                'customer_id' => $customerId,
                'limit'       => $limit,
            ]);

            return ['ok' => false, 'message' => 'سقف تعداد دستگاه‌های مجاز این مشتری (' . $limit . ') تکمیل شده است.'];
        }

        $deviceUid = isset($data['device_uid']) ? (string) $data['device_uid'] : '';

        if ($deviceUid !== '') {
            $existing = Device::findByDeviceUid($deviceUid);

            if ($existing !== null) {
                if ((int) $existing['customer_id'] !== $customerId) {
                    AuditLog::device('device.register', 'denied', 'تلاش برای ثبت دستگاه متعلق به مشتری دیگر.', [
                        'device_uid' => $deviceUid,
                    ]);

                    return ['ok' => false, 'message' => 'این شناسه دستگاه قبلاً ثبت شده است.'];
                }

                return [
                    'ok'      => true,
                    'message' => 'این دستگاه قبلاً ثبت شده است.',
                    'device'  => $existing,
                ];
            }
        } else {
            $deviceUid = 'dev-' . strtolower(Security::randomHex(8));
        }

        $result = Device::register([
            'customer_id'    => $customerId,
            'branch_id'      => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
            'manager_id'     => isset($data['manager_id']) && (int) $data['manager_id'] > 0 ? (int) $data['manager_id'] : null,
            'name'           => (string) ($data['name'] ?? 'دستگاه جدید'),
            'type'           => (string) ($data['type'] ?? 'kiosk'),
            'platform'       => $data['platform'] ?? null,
            'app_version'    => $data['app_version'] ?? null,
            'serial_number'  => $data['serial_number'] ?? null,
            'device_uid'     => $deviceUid,
            'registered_by'  => \App\Core\Session::userId(),
        ]);

        $device = Device::find($result['device_id']);

        AuditLog::device('device.register', 'success', 'دستگاه جدید ثبت شد و در انتظار فعال‌سازی است.', [
            'device_id'   => $result['device_id'],
            'customer_id' => $customerId,
        ],);

        return [
            'ok'      => true,
            'message' => 'دستگاه ثبت شد و در انتظار فعال‌سازی است.',
            'device'  => $device,
            'pairing_code' => $result['pairing_code'],
        ];
    }

    /**
     * Activate a pending device and return its API token (shown once).
     *
     * @return array{ok:bool, message:string, token?:string, device?:array<string,mixed>}
     */
    /**
     * Activate a device and hand out its API token (shown once).
     *
     * Stage 4 tightening: this tenant level activation is for a device that has
     * NO usable credential yet (the normal "manager pairs a new kiosk" flow).
     * Re-issuing a token for a device that is already active would be a token
     * rotation, and rotation/reset is an OWNER capability - so it is refused
     * here with an explicit message instead of silently rotating. The owner
     * paths (panel reset / rotate-token / a one-time activation code) do not
     * come through this method; they are authorised and audited separately.
     *
     * @return array{ok:bool, message:string, token?:string, device?:array<string,mixed>}
     */
    public function activate(int $deviceId): array
    {
        $device = Device::findForTenant($deviceId);

        if ($device === null) {
            return ['ok' => false, 'message' => 'دستگاه یافت نشد.'];
        }

        if ((string) $device['status'] === Device::STATUS_REVOKED) {
            return ['ok' => false, 'message' => 'این دستگاه لغو شده است و قابل فعال‌سازی نیست.'];
        }

        $alreadyActive = (string) $device['status'] === Device::STATUS_ACTIVE
            && trim((string) ($device['api_token_hash'] ?? '')) !== '';

        if ($alreadyActive) {
            AuditLog::device('device.activate', 'denied', 'تلاش برای بازتولید توکن دستگاه فعال بدون دسترسی مالک.', [
                'device_id' => $deviceId,
            ]);

            return [
                'ok'      => false,
                'message' => 'این دستگاه فعال است و توکن معتبر دارد. بازتولید توکن فقط توسط مالک سامانه انجام می‌شود.',
            ];
        }

        $token = Device::issueToken($deviceId, (int) Config::get('security.device_token_days', 365));

        Device::activate($deviceId);

        DeviceSession::revokeAllForDevice($deviceId, 'reactivated');

        AuditLog::device('device.activate', 'success', 'دستگاه فعال شد و توکن جدید صادر گردید.', [
            'device_id' => $deviceId,
            'prefix'    => $token['prefix'],
        ]);

        $fresh = Device::find($deviceId);

        return [
            'ok'      => true,
            'message' => 'دستگاه فعال شد. توکن زیر فقط یک بار نمایش داده می‌شود.',
            'token'   => $token['token'],
            'prefix'  => $token['prefix'],
            'expires_at' => $token['expires_at'],
            'device'  => $fresh,
        ];
    }

    public function revoke(int $deviceId, string $reason = ''): array
    {
        $device = Device::findForTenant($deviceId);

        if ($device === null) {
            return ['ok' => false, 'message' => 'دستگاه یافت نشد.'];
        }

        Device::revoke($deviceId, $reason);

        AuditLog::device('device.revoke', 'success', 'دستگاه لغو شد.', [
            'device_id' => $deviceId,
            'reason'    => $reason,
        ]);

        return ['ok' => true, 'message' => 'دستگاه لغو شد.'];
    }

    /**
     * Rotate the device token without changing its state.
     *
     * @return array{ok:bool, message:string, token?:string}
     */
    public function rotateToken(int $deviceId): array
    {
        $device = Device::findForTenant($deviceId);

        if ($device === null) {
            return ['ok' => false, 'message' => 'دستگاه یافت نشد.'];
        }

        $token = Device::issueToken($deviceId, (int) Config::get('security.device_token_days', 365));

        DeviceSession::revokeAllForDevice($deviceId, 'token rotated');

        AuditLog::device('device.token.rotate', 'success', 'توکن دستگاه بازنشانی شد.', [
            'device_id' => $deviceId,
        ]);

        return [
            'ok'      => true,
            'message' => 'توکن دستگاه بازنشانی شد. توکن جدید فقط یک بار نمایش داده می‌شود.',
            'token'   => $token['token'],
            'prefix'  => $token['prefix'],
        ];
    }

    /* =================================================================
     * Authentication of API calls
     * ===============================================================*/

    /**
     * Validate a bearer token and return the device row.
     *
     * @return array<string,mixed>|null
     */
    public function authenticateToken(string $plainToken): ?array
    {
        $result = $this->authenticateTokenDetailed($plainToken);

        return $result['ok'] ? $result['device'] : null;
    }

    /**
     * Same check as authenticateToken() but it also says WHY the token was
     * refused, so the API can answer with the documented device code:
     *
     *   DEVICE_UNAUTHORIZED  - absent / malformed / unknown / expired token
     *   DEVICE_INACTIVE      - registered but never activated, or suspended
     *   DEVICE_REVOKED       - the owner revoked this device
     *   CUSTOMER_DISABLED    - the customer account is not active
     *
     * Every refusal is written to the device log (and to the audit trail by the
     * caller) - never silently ignored.
     *
     * @return array{ok:bool, code:string, reason:string, device?:array<string,mixed>}
     */
    public function authenticateTokenDetailed(string $plainToken): array
    {
        $refuse = function (string $code, string $reason, ?array $device = null): array {
            $status = $device === null ? 'unknown' : (string) $device['status'];
            $this->failAuth($reason, $status, $device);

            DeviceLog::record(
                'device.auth.failed',
                'warning',
                'احراز هویت دستگاه رد شد (' . $reason . ').',
                $device === null ? null : (int) $device['id'],
                $device === null ? 0 : (int) $device['customer_id'],
                ['reason' => $reason, 'code' => $code]
            );

            return ['ok' => false, 'code' => $code, 'reason' => $reason, 'device' => $device];
        };

        if ($plainToken === '' || strncmp($plainToken, 'att_', 4) !== 0) {
            return $refuse('DEVICE_UNAUTHORIZED', 'malformed_token');
        }

        $device = Device::findByTokenHash(Security::hashToken($plainToken));

        if ($device === null) {
            return $refuse('DEVICE_UNAUTHORIZED', 'unknown_token');
        }

        $status = (string) $device['status'];

        if ($status === Device::STATUS_REVOKED) {
            return $refuse('DEVICE_REVOKED', 'revoked_device', $device);
        }

        if ($status !== Device::STATUS_ACTIVE) {
            return $refuse('DEVICE_INACTIVE', 'inactive_device', $device);
        }

        if (Device::tokenExpired($device)) {
            return $refuse('DEVICE_UNAUTHORIZED', 'expired_token', $device);
        }

        // The device's customer must still be active.
        if (!Customer::isActive((int) $device['customer_id'])) {
            return $refuse('CUSTOMER_DISABLED', 'customer_inactive', $device);
        }

        return ['ok' => true, 'code' => 'OK', 'reason' => '', 'device' => $device];
    }

    /**
     * Attendance must be enabled for the customer before a device may use the
     * recognition / attendance endpoints (backend enforcement of the feature
     * flag - the kiosk UI is never the guard).
     */
    public function attendanceEnabled(int $customerId): bool
    {
        if (function_exists('feature_enabled')) {
            return \feature_enabled($customerId, 'attendance');
        }

        return true;
    }

    /**
     * Full device login: exchanges a token for a session record and binds
     * the tenant context for the current request.
     *
     * @return array{ok:bool, message:string, device?:array<string,mixed>, session_token?:string}
     */
    public function loginWithToken(string $plainToken): array
    {
        $device = $this->authenticateToken($plainToken);

        if ($device === null) {
            AuditLog::device('device.auth.failed', 'failure', 'احراز هویت دستگاه ناموفق بود.');
            Logger::channel('device')->warning('Device auth failed', ['prefix' => substr($plainToken, 0, 12)]);

            return ['ok' => false, 'message' => 'توکن دستگاه نامعتبر یا منقضی است.'];
        }

        $sessionToken = 'att_sess_' . Security::randomToken(24);

        DeviceSession::issue(
            (int) $device['id'],
            (int) $device['customer_id'],
            Security::hashToken($sessionToken),
            Request::ip(),
            Request::userAgent(),
            30
        );

        $this->touch((int) $device['id']);

        TenantContext::set((int) $device['customer_id'], 'device');

        \App\Core\Session::loginDevice([
            'id'          => (int) $device['id'],
            'uuid'        => (string) $device['uuid'],
            'device_uid'  => (string) $device['device_uid'],
            'name'        => (string) $device['name'],
            'customer_id' => (int) $device['customer_id'],
            'branch_id'   => isset($device['branch_id']) ? (int) $device['branch_id'] : null,
            'status'      => (string) $device['status'],
        ]);

        AuditLog::device('device.auth.success', 'success', 'احراز هویت دستگاه موفق بود.', [
            'device_id' => (int) $device['id'],
        ]);

        return [
            'ok'            => true,
            'message'       => 'دستگاه احراز هویت شد.',
            'device'        => Device::find((int) $device['id']),
            'session_token' => $sessionToken,
        ];
    }

    public function touch(int $deviceId, int $clockOffset = 0, ?string $appVersion = null): void
    {
        Device::heartbeat($deviceId, Request::ip(), $clockOffset, $appVersion);
    }

    /**
     * Device status snapshot for the panels and the device itself.
     *
     * @return array<string,mixed>
     */
    public function status(int $deviceId): array
    {
        $device = Device::find($deviceId);

        if ($device === null) {
            return ['ok' => false, 'message' => 'دستگاه یافت نشد.'];
        }

        $offlineAfter = (int) Config::get('security.device_offline_after', 300);

        return [
            'ok'       => true,
            'device'   => [
                'id'          => (int) $device['id'],
                'uuid'        => (string) $device['uuid'],
                'device_uid'  => (string) $device['device_uid'],
                'name'        => (string) $device['name'],
                'status'      => (string) $device['status'],
                'branch_id'   => isset($device['branch_id']) ? (int) $device['branch_id'] : null,
                'online'      => Device::isOnline($device, $offlineAfter),
                'last_seen_at'=> $device['last_seen_at'],
                'app_version' => $device['app_version'],
            ],
            'server_time' => date('c'),
        ];
    }

    /**
     * Statistics used by the device inventory screen.
     *
     * @return array<string,mixed>
     */
    public function inventory(?int $customerId): array
    {
        $sql    = 'SELECT `id`, `name`, `status`, `last_seen_at`, `branch_id` FROM `' . Database::table('devices') . '` WHERE `deleted_at` IS NULL';
        $params = [];

        if ($customerId !== null && $customerId > 0) {
            $sql .= ' AND `customer_id` = ?';
            $params[] = $customerId;
        }

        $devices      = Database::select($sql, $params);
        $offlineAfter = (int) Config::get('security.device_offline_after', 300);
        $online       = 0;

        foreach ($devices as $device) {
            if (Device::isOnline($device, $offlineAfter)) {
                $online++;
            }
        }

        return [
            'total'  => count($devices),
            'online' => $online,
            'offline'=> count($devices) - $online,
        ];
    }

    /* ================================================================= */

    /**
     * @param array<string,mixed>|null $device
     */
    /* =================================================================
     * Stage 4 - activation codes, kiosk configuration, engine contracts
     * ===============================================================*/

    /**
     * Issue a NEW one-time activation code for a device.
     *
     * Owner level operation: the call sites are the owner panel and the owner
     * API. The plain code is returned exactly once (it is stored hashed), and
     * every previous open code of that device is revoked in the same step.
     *
     * @return array{ok:bool, code:string, message:string, activation?:array<string,mixed>}
     */
    public function issueActivationCode(int $deviceId, ?int $ttlMinutes = null, int $actorUserId = 0): array
    {
        $device = Device::find($deviceId);

        if ($device === null) {
            return ['ok' => false, 'code' => 'DEVICE_NOT_FOUND', 'message' => 'دستگاه یافت نشد.'];
        }

        $customerId = (int) $device['customer_id'];

        if ((string) $device['status'] === Device::STATUS_REVOKED) {
            return ['ok' => false, 'code' => 'DEVICE_REVOKED', 'message' => 'دستگاه لغو شده است و کد فعال‌سازی نمی‌گیرد.'];
        }

        $ttl = $ttlMinutes ?? DeviceActivationCode::DEFAULT_TTL_MINUTES;

        if ($actorUserId <= 0) {
            $actorUserId = (int) (Session::userId() ?? 0);
        }

        $issued = DeviceActivationCode::issue($deviceId, $customerId, $actorUserId, $ttl);

        // The stage 1 `devices.pairing_code` column is NOT used for stage 4:
        // a usable code must exist nowhere but in the owner's one-time flash
        // (the table keeps only an HMAC hash + a 4 character marker). Leaving
        // the legacy column empty also means a manager reading the device list
        // can never pick up a code and turn it into a token.
        Database::run(
            'UPDATE `' . Database::table('devices') . '` SET `pairing_code` = NULL, `pairing_expires_at` = NULL WHERE `id` = ?',
            [$deviceId]
        );

        DeviceLog::record(
            'device.activation.code_issued',
            'info',
            'کد فعال‌سازی یک‌بارمصرف صادر شد (اعتبار تا ' . $issued['expires_at'] . ').',
            $deviceId,
            $customerId,
            ['prefix' => $issued['prefix'], 'expires_at' => $issued['expires_at'], 'ttl_minutes' => $ttl]
        );

        AuditLog::record(
            'device.activation.code_issued',
            'success',
            'کد فعال‌سازی یک‌بارمصرف دستگاه صادر شد.',
            [
                'device_id' => $deviceId,
                'prefix'    => $issued['prefix'],
                'expires_at' => $issued['expires_at'],
            ],
            'device',
            'device',
            $deviceId,
            $customerId
        );

        return [
            'ok'         => true,
            'code'       => 'OK',
            'message'    => 'کد فعال‌سازی صادر شد. این کد فقط یک بار قابل استفاده است.',
            'activation' => [
                'code'       => $issued['code'],
                'prefix'     => $issued['prefix'],
                'expires_at' => $issued['expires_at'],
                'ttl_minutes' => $ttl,
                'device_id'  => $deviceId,
                'customer_id' => $customerId,
            ],
        ];
    }

    /**
     * Activate a device with a one-time code (the kiosk side of the flow).
     *
     * On success the device receives its long lived token ONCE, the code is
     * burned, every previous session of that device is revoked and the old
     * token (if any) stops working immediately.
     *
     * @param array<string,mixed> $context
     * @return array{ok:bool, code:string, message:string, token?:string, expires_at?:string, device?:array<string,mixed>, http?:int}
     */
    public function activateWithCode(string $plainCode, array $context = []): array
    {
        $verify = DeviceActivationCode::verify($plainCode);

        if (!$verify['ok']) {
            $codeRow  = $verify['row'] ?? null;
            $deviceId = $codeRow === null ? null : (int) $codeRow['device_id'];
            $customer = $codeRow === null ? 0 : (int) $codeRow['customer_id'];

            if ($codeRow !== null) {
                // The code was recognised (wrong state): count the try on it.
                DeviceActivationCode::registerAttemptById((int) $codeRow['id']);
            } else {
                // A guess. If its prefix matches an open code we know which
                // device is under attack and burn that device's codes; a fully
                // random guess is bounded by the endpoint's IP rate limit
                // (throttle:10,5), which is the guard that can not be bypassed.
                $prefix = substr(DeviceActivationCode::normalize($plainCode), 0, 4);
                $target = DeviceActivationCode::deviceIdForPrefix($prefix);

                if ($target !== null) {
                    DeviceActivationCode::registerFailedAttempt($target);
                    $deviceId = $target;
                    $customer = (int) (DeviceActivationCode::openForDevice($target)['customer_id'] ?? 0);
                }
            }

            DeviceLog::record('device.activation.failed', 'warning', $verify['message'], $deviceId, $customer, [
                'code'      => $verify['code'],
                'platform'  => (string) ($context['platform'] ?? ''),
                'app_version' => (string) ($context['app_version'] ?? ''),
            ]);

            AuditLog::record(
                'device.activation.failed',
                'failure',
                'فعال‌سازی دستگاه ناموفق بود: ' . $verify['message'],
                ['code' => $verify['code']],
                'device',
                'device',
                $deviceId === null ? null : $deviceId,
                $customer > 0 ? $customer : null
            );

            // HTTP is chosen from the refusal reason so a client can tell
            // "already used" (409, do not retry) from "wrong code" (422).
            $http = in_array($verify['code'], ['ACTIVATION_CODE_USED', 'ACTIVATION_CODE_EXPIRED'], true) ? 409 : 422;

            return ['ok' => false, 'code' => $verify['code'], 'message' => $verify['message'], 'http' => $http];
        }

        /** @var array<string,mixed> $row */
        $row        = $verify['row'];
        $deviceId   = (int) $row['device_id'];
        $customerId = (int) $row['customer_id'];

        $device = Device::find($deviceId);

        if ($device === null) {
            return ['ok' => false, 'code' => 'DEVICE_NOT_FOUND', 'message' => 'دستگاه مربوط به این کد یافت نشد.', 'http' => 404];
        }

        // Defensive: the code row must point at the same tenant as the device.
        if ((int) $device['customer_id'] !== $customerId) {
            DeviceLog::record('device.activation.failed', 'error', 'ناسازگاری مشتری در کد فعال‌سازی.', $deviceId, $customerId, [
                'code' => 'TENANT_MISMATCH',
            ]);

            return ['ok' => false, 'code' => 'TENANT_MISMATCH', 'message' => 'کد فعال‌سازی با این دستگاه هم‌خوانی ندارد.', 'http' => 403];
        }

        if ((string) $device['status'] === Device::STATUS_REVOKED) {
            return ['ok' => false, 'code' => 'DEVICE_REVOKED', 'message' => 'این دستگاه لغو شده است.', 'http' => 403];
        }

        if (!Customer::isActive($customerId)) {
            return ['ok' => false, 'code' => 'CUSTOMER_DISABLED', 'message' => 'حساب این مجموعه فعال نیست.', 'http' => 403];
        }

        // Single use: the UPDATE ... WHERE used_at IS NULL is the lock, so two
        // parallel activations can not both succeed.
        if (!DeviceActivationCode::consume((int) $row['id'], Request::ip())) {
            return ['ok' => false, 'code' => 'ACTIVATION_CODE_USED', 'message' => 'این کد فعال‌سازی همین حالا استفاده شده است.', 'http' => 409];
        }

        $days  = (int) Config::get('security.device_token_days', 365);
        $token = Device::issueToken($deviceId, $days);

        Device::activate($deviceId);
        DeviceSession::revokeAllForDevice($deviceId, 'activated_with_code');

        Database::run(
            'UPDATE `' . Database::table('devices') . '` SET `platform` = COALESCE(?, `platform`),'
            . ' `app_version` = COALESCE(?, `app_version`), `last_seen_at` = NOW() WHERE `id` = ?',
            [
                isset($context['platform']) && (string) $context['platform'] !== '' ? substr((string) $context['platform'], 0, 60) : null,
                isset($context['app_version']) && (string) $context['app_version'] !== '' ? substr((string) $context['app_version'], 0, 30) : null,
                $deviceId,
            ]
        );

        DeviceLog::record('device.activation.success', 'info', 'دستگاه با کد یک‌بارمصرف فعال شد.', $deviceId, $customerId, [
            'prefix'   => $token['prefix'],
            'platform' => (string) ($context['platform'] ?? ''),
        ]);

        AuditLog::record(
            'device.activation.success',
            'success',
            'دستگاه با کد فعال‌سازی یک‌بارمصرف فعال شد.',
            ['device_id' => $deviceId, 'prefix' => $token['prefix']],
            'device',
            'device',
            $deviceId,
            $customerId
        );

        TenantContext::set($customerId, 'device');

        return [
            'ok'         => true,
            'code'       => 'OK',
            'message'    => 'دستگاه فعال شد.',
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
            'device'     => Device::find($deviceId),
        ];
    }

    /**
     * Everything the kiosk needs to render itself: branding of ITS OWN
     * customer, branch (only when the branches feature is on), device
     * settings, feature flags and the engine contract.
     *
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function configFor(array $device): array
    {
        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        $customer = Customer::find($customerId);
        $branch   = null;

        if (isset($device['branch_id']) && (int) $device['branch_id'] > 0) {
            // Branches are always resolved WITH the customer id, so a device can
            // never show another tenant's branch name.
            $branch = Database::selectOne(
                'SELECT `id`, `name`, `code`, `address` FROM `' . Database::table('branches') . '`'
                . ' WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
                [(int) $device['branch_id'], $customerId]
            );
        }

        $logoPath = $customer === null ? '' : trim((string) ($customer['logo_path'] ?? ''));

        return [
            'device' => [
                'id'             => $deviceId,
                'uuid'           => (string) $device['uuid'],
                'device_uid'     => (string) $device['device_uid'],
                'name'           => (string) $device['name'],
                'type'           => (string) $device['type'],
                'status'         => (string) $device['status'],
                'branch_id'      => isset($device['branch_id']) ? (int) $device['branch_id'] : null,
                'app_version'    => (string) ($device['app_version'] ?? ''),
                'last_seen_at'   => $device['last_seen_at'] ?? null,
                'activated_at'   => $device['activated_at'] ?? null,
            ],
            'customer' => [
                'id'          => $customerId,
                'name'        => $customer === null ? '' : (string) $customer['name'],
                'logo_url'    => $logoPath === '' ? null : \App\Support\Url::upload($logoPath),
                'timezone'    => $customer === null ? 'Asia/Tehran' : (string) $customer['timezone'],
                'date_system' => $customer === null ? 'jalali' : (string) $customer['date_system'],
            ],
            'branch' => $branch === null ? null : [
                'id'      => (int) $branch['id'],
                'name'    => (string) $branch['name'],
                'code'    => (string) ($branch['code'] ?? ''),
                'address' => (string) ($branch['address'] ?? ''),
            ],
            'features' => [
                'branches'   => \feature_enabled($customerId, 'branches'),
                'attendance' => \feature_enabled($customerId, 'attendance'),
                'employees'  => \feature_enabled($customerId, 'employees'),
            ],
            'settings'           => DeviceSetting::allTyped($deviceId),
            'face'               => array_merge(
                FaceDatasetService::clientConfig(),
                (new FaceDatasetService())->versionInfoForDevice($device)
            ),
            'engine'             => $this->engineContract(),
            'server_time'        => date('c'),
            'server_time_local'  => date('Y-m-d H:i:s'),
            'heartbeat_interval' => (int) Config::get('security.device_heartbeat_interval', 60),
        ];
    }

    /**
     * The face recognition engine description.
     *
     * STAGE 5 - the truth:
     *   * The engine is REAL, it runs LOCALLY in the browser (face-api.js on
     *     the kiosk / enrolment pages). Detection, embedding, matching all
     *     happen on the device; no camera image is uploaded.
     *   * There is therefore NO server side recognition endpoint: an image is
     *     never sent to PHP for matching. The old `/device/recognition`
     *     endpoint answers FACE_ENGINE_UNAVAILABLE on purpose - it is the
     *     server side (image) engine that does not exist and never will.
     *   * Liveness / anti-spoofing is NOT implemented - not optional, absent.
     *
     * @return array<string,mixed>
     */
    public function engineContract(): array
    {
        $face = FaceDatasetService::clientConfig();

        return [
            'face_engine'        => 'local-browser',
            'face_engine_stage'  => 5,
            'engine'             => $face['engine'],
            'engine_version'     => $face['model_version'],
            'model_path'         => $face['model_path'],
            'library_paths'      => $face['library_paths'],
            'detector'           => $face['detector'],
            'metric'             => $face['metric'],
            'dimensions'         => $face['dimensions'],
            'samples_required'   => $face['samples_required'],
            'match_threshold'    => $face['match_threshold'],
            'ambiguity_margin'   => $face['ambiguity_margin'],
            'server_side_recognition' => false,
            'image_upload_for_recognition' => false,
            'liveness'           => 'not_used',
            'endpoints'          => [
                'face_data'          => '/api/v1/device/face-data',
                'recognition_result' => '/api/v1/device/recognition-result',
                'attendance'         => '/api/v1/device/attendance',
                'server_recognition' => '/api/v1/device/recognition',
            ],
            'recognition_codes' => ['FACE_MATCHED', 'NO_FACE', 'MULTIPLE_FACES', 'UNKNOWN_FACE', 'FACE_MATCH_AMBIGUOUS', 'EMPLOYEE_INACTIVE', 'EMPLOYEE_FACE_INCOMPLETE'],
            'attendance_actions' => ['entry', 'exit'],
            'requires_request_id' => true,
            'attendance_engine'  => 'not_ready',
        ];
    }

    /**
     * Save the NON SENSITIVE device settings (allow list lives in
     * App\Models\DeviceSetting). Security relevant switches are impossible
     * here by construction.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $values
     * @return array{ok:bool, code:string, message:string, saved:array<int,string>, rejected:array<int,string>, settings:array<string,mixed>}
     */
    public function applySettings(array $device, array $values, int $updatedBy = 0): array
    {
        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        $rejected = DeviceSetting::rejectedKeys($values);
        $saved    = DeviceSetting::putMany($deviceId, $customerId, $values, $updatedBy);

        if ($saved !== []) {
            DeviceLog::record('device.config.loaded', 'info', 'تنظیمات دستگاه به‌روزرسانی شد.', $deviceId, $customerId, [
                'saved' => $saved,
            ]);

            AuditLog::device('device.settings.update', 'success', 'تنظیمات محلی دستگاه تغییر کرد.', [
                'device_id'   => $deviceId,
                'customer_id' => $customerId,
                'keys'        => $saved,
            ]);
        }

        return [
            'ok'       => true,
            'code'     => $rejected === [] ? 'OK' : 'SETTINGS_PARTIALLY_SAVED',
            'message'  => $rejected === []
                ? 'تنظیمات دستگاه ذخیره شد.'
                : 'کلیدهای غیرمجاز نادیده گرفته شدند: ' . implode(', ', $rejected),
            'saved'    => $saved,
            'rejected' => $rejected,
            'settings' => DeviceSetting::allTyped($deviceId),
        ];
    }

    /**
     * Heartbeat: last_seen + status. Heartbeats are logged in
     * attendance_device_logs only when the previous heartbeat is old (so the
     * log stays readable and the shared host's table stays small), while
     * last_seen_at is always updated.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function heartbeatFor(array $device, array $payload = []): array
    {
        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        $previousSeen = (string) ($device['last_seen_at'] ?? '');
        $clockOffset  = isset($payload['clock_offset']) ? (int) $payload['clock_offset'] : 0;
        $appVersion   = isset($payload['app_version']) ? (string) $payload['app_version'] : null;

        $this->touch($deviceId, $clockOffset, $appVersion);

        $stale = $previousSeen === '' || (time() - (int) strtotime($previousSeen)) > 300;

        if ($stale) {
            DeviceLog::record('device.heartbeat', 'info', 'ارتباط دستگاه برقرار شد.', $deviceId, $customerId, [
                'clock_offset' => $clockOffset,
                'app_version'  => (string) $appVersion,
            ]);
        }

        $fresh = Device::find($deviceId);

        return [
            'ok'          => true,
            'code'        => 'OK',
            'message'     => 'وضعیت دستگاه ثبت شد.',
            'device'      => [
                'id'           => $deviceId,
                'status'       => $fresh === null ? (string) $device['status'] : (string) $fresh['status'],
                'last_seen_at' => $fresh === null ? null : $fresh['last_seen_at'],
                'clock_offset' => $clockOffset,
            ],
            'server_time' => date('c'),
            'config_version' => $this->configVersion($deviceId),
            'pending_settings' => DeviceSetting::allTyped($deviceId),
            // Stage 5: a device polls the face dataset version here and only
            // downloads the gallery when it really changed.
            'face' => (new FaceDatasetService())->versionInfoForDevice(
                $fresh === null ? $device : $fresh
            ),
        ];
    }

    /**
     * Cheap, deterministic "has anything changed" marker for the kiosk.
     */
    public function configVersion(int $deviceId): string
    {
        $row = Database::selectOne(
            'SELECT MAX(`updated_at`) AS touched FROM `' . Database::table('device_settings') . '` WHERE `device_id` = ?',
            [$deviceId]
        );

        $settings = $row === null || $row['touched'] === null ? 'none' : (string) $row['touched'];

        return substr(hash('sha256', $deviceId . '|' . $settings), 0, 12);
    }

    /**
     * FACE RECOGNITION CONTRACT (stage 4)
     *
     * The request is fully validated - device status, tenant, branch scoping,
     * required idempotency key, payload limits - and then answered with
     * FACE_ENGINE_UNAVAILABLE, because the engine itself is stage 5. Nothing is
     * written to attendance and no employee data is returned, so this endpoint
     * can never be mistaken for a working recogniser.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function recognitionContract(array $device, array $payload): array
    {
        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        $requestId = (string) ($payload['request_id'] ?? '');

        if (strlen($requestId) < 8 || strlen($requestId) > 64) {
            return [
                'ok'     => false,
                'code'   => 'INVALID_REQUEST_ID',
                'message' => 'شناسه درخواست (request_id) لازم است (۸ تا ۶۴ کاراکتر).',
                'http'   => 422,
            ];
        }

        if (!DeviceRequest::claim($deviceId, $customerId, 'recognition', $requestId)) {
            DeviceLog::record('device.recognition.error', 'warning', 'درخواست تکراری تشخیص چهره رد شد.', $deviceId, $customerId, [
                'request_id' => $requestId,
                'code'       => 'DUPLICATE_REQUEST',
            ]);

            return [
                'ok'      => false,
                'code'    => 'DUPLICATE_REQUEST',
                'message' => 'این درخواست قبلاً پردازش شده است.',
                'http'    => 409,
            ];
        }

        if (isset($payload['branch_id']) && (int) $payload['branch_id'] > 0) {
            $deviceBranch = isset($device['branch_id']) ? (int) $device['branch_id'] : 0;

            if ($deviceBranch > 0 && (int) $payload['branch_id'] !== $deviceBranch) {
                DeviceRequest::finish($deviceId, $requestId, 'BRANCH_MISMATCH', false);

                return [
                    'ok'      => false,
                    'code'    => 'BRANCH_MISMATCH',
                    'message' => 'شعبه درخواست با شعبه ثبت‌شده دستگاه هم‌خوانی ندارد.',
                    'http'    => 409,
                ];
            }
        }

        if (isset($payload['frame']) && is_string($payload['frame']) && strlen($payload['frame']) > 2_000_000) {
            DeviceRequest::finish($deviceId, $requestId, 'PAYLOAD_TOO_LARGE', false);

            return [
                'ok'      => false,
                'code'    => 'PAYLOAD_TOO_LARGE',
                'message' => 'تصویر ارسالی بزرگ‌تر از حد مجاز است.',
                'http'    => 413,
            ];
        }

        DeviceRequest::finish($deviceId, $requestId, 'FACE_ENGINE_UNAVAILABLE', false);

        DeviceLog::record('device.recognition.error', 'warning', 'درخواست تشخیص چهره دریافت شد اما موتور تشخیص متصل نیست.', $deviceId, $customerId, [
            'request_id' => $requestId,
            'code'       => 'FACE_ENGINE_UNAVAILABLE',
        ]);

        return [
            'ok'       => false,
            'code'     => 'FACE_ENGINE_UNAVAILABLE',
            'message'  => 'موتور تشخیص چهره هنوز به دستگاه متصل نشده است (مرحله ۵).',
            'http'     => 503,
            'contract' => $this->engineContract(),
        ];
    }

    /**
     * Map an engine result code to the documented API answer. This is the
     * function stage 5 will call with the real engine output; it is a real,
     * tested mapping (see tests/e2e_stage4.sh) and not a placeholder.
     *
     * @param array<string,mixed> $context employee + action data from the engine
     * @return array{ok:bool, code:string, message:string, http:int, data:array<string,mixed>}
     */
    public function engineResponse(string $engineCode, array $context = []): array
    {
        switch ($engineCode) {
            case 'no_face':
                return [
                    'ok'      => false,
                    'code'    => 'NO_FACE',
                    'message' => 'چهره‌ای شناسایی نشد. لطفاً روبه‌روی دوربین قرار بگیرید.',
                    'http'    => 422,
                    'data'    => [],
                ];

            case 'multiple_faces':
                return [
                    'ok'      => false,
                    'code'    => 'MULTIPLE_FACES',
                    'message' => 'لطفاً فقط یک نفر مقابل دوربین قرار بگیرد.',
                    'http'    => 422,
                    'data'    => ['faces' => (int) ($context['faces'] ?? 2)],
                ];

            case 'unknown_face':
                return [
                    'ok'      => false,
                    'code'    => 'UNKNOWN_FACE',
                    'message' => 'چهره شما در سیستم ثبت نشده است. لطفاً با مسئول مجموعه تماس بگیرید.',
                    'http'    => 422,
                    'data'    => [],
                ];

            case 'employee_inactive':
                return [
                    'ok'      => false,
                    'code'    => 'EMPLOYEE_INACTIVE',
                    'message' => 'این پرسنل غیرفعال است و ثبت حضور انجام نمی‌شود.',
                    'http'    => 403,
                    'data'    => [],
                ];

            case 'recognized':
                $employee = (array) ($context['employee'] ?? []);

                return [
                    'ok'      => true,
                    'code'    => 'OK',
                    'message' => 'چهره شناسایی شد.',
                    'http'    => 200,
                    'data'    => [
                        'employee' => [
                            'id'          => (int) ($employee['id'] ?? 0),
                            'full_name'   => trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? '')),
                            'employee_code' => (string) ($employee['employee_code'] ?? ''),
                            'position'    => (string) ($employee['position'] ?? ''),
                            'photo_url'   => isset($employee['photo_path']) && (string) $employee['photo_path'] !== ''
                                ? \App\Support\Url::upload((string) $employee['photo_path'])
                                : null,
                        ],
                        'confidence' => isset($context['confidence']) ? (float) $context['confidence'] : null,
                    ],
                ];

            default:
                return [
                    'ok'      => false,
                    'code'    => 'FACE_ENGINE_UNAVAILABLE',
                    'message' => 'نتیجه موتور تشخیص چهره قابل تفسیر نبود.',
                    'http'    => 503,
                    'data'    => [],
                ];
        }
    }

    /**
     * NOTE (stage 6): the attendance submission contract that used to live here
     * (validate the payload, then answer ATTENDANCE_ENGINE_NOT_READY) has been
     * REPLACED by the real engine - see App\Services\AttendanceEngine, called
     * from DeviceKioskController::attendanceEntry()/attendanceExit()/attendanceSync().
     * A device can no longer submit a bare employee_id: attendance is only
     * written with a valid one time recognition proof.
     */

    private function failAuth(string $reason, string $status, ?array $device = null): void
    {
        Logger::channel('device')->warning('Device token rejected', [
            'reason' => $reason,
            'status' => $status,
            'device_id' => $device === null ? null : (int) $device['id'],
            'ip'     => Request::ip(),
        ]);
    }
}
