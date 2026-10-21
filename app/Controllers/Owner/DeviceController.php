<?php
/**
 * SARMAD Attendance - owner panel: device management.
 *
 * Device RESET is owner-only. A manager may register / activate / revoke a
 * device of their own customer (stage 1 routes), but rotating or resetting
 * the registration is a platform level operation and lives here.
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Database;
use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceActivationCode;
use App\Models\DeviceLog;
use App\Models\DeviceSession;
use App\Services\AuditService;
use App\Services\DeviceService;

final class DeviceController
{
    public function index(Request $request): Response
    {
        $filters = [
            'q'           => (string) $request->input('q', ''),
            'customer_id' => $request->int('customer_id', 0),
            'status'      => (string) $request->input('status', ''),
            'online'      => (string) $request->input('online', ''),
        ];

        $offlineAfter = (int) \App\Models\Setting::get('device.offline_after', 300);

        $listing = Device::paginateForOwner($filters, $request->page(), $request->perPage(20), $offlineAfter);

        // Stage 4: activation-code state per device (one query for the page) and
        // the newest device events (activation / auth / heartbeat / errors).
        $deviceIds = array_map(static fn (array $row): int => (int) $row['id'], $listing['rows']);

        return Response::html(View::render('owner/devices/index', [
            'title'        => 'مدیریت دستگاه‌ها',
            'listing'      => $listing,
            'filters'      => $filters,
            'customers'    => $this->customerOptions(),
            'offlineAfter' => $offlineAfter,
            'activations'  => DeviceActivationCode::statesForDevices($deviceIds),
            'logs'         => DeviceLog::recentForDevices(15),
            'errorCount'   => DeviceLog::errorCount(60),
            'active'       => 'devices',
        ], 'layouts/owner'));
    }

    /**
     * Change a device status (activate / disable).
     */
    public function status(Request $request): Response
    {
        $device = Device::findScoped($request->int('id', 0));

        if ($device === null) {
            Session::flash('error', 'دستگاه یافت نشد.');

            return Redirect::response('/owner/devices');
        }

        $status = (string) $request->input('status', '');

        if (!in_array($status, [Device::STATUS_ACTIVE, Device::STATUS_DISABLED], true)) {
            Session::flash('error', 'وضعیت درخواستی معتبر نیست.');

            return Redirect::response('/owner/devices');
        }

        Device::setStatus((int) $device['id'], $status);

        AuditLog::record(
            $status === Device::STATUS_ACTIVE ? 'device.activate' : 'device.disable',
            'success',
            'دستگاه «' . (string) $device['name'] . '» ' . ($status === Device::STATUS_ACTIVE ? 'فعال' : 'غیرفعال') . ' شد.',
            [
                'device_id'   => (int) $device['id'],
                'customer_id' => (int) $device['customer_id'],
                'status'      => $status,
            ],
            'device',
            'device',
            (int) $device['id'],
            (int) $device['customer_id']
        );

        Session::flash('success', 'وضعیت دستگاه به‌روزرسانی شد.');

        return Redirect::response('/owner/devices?customer_id=' . (int) $device['customer_id']);
    }

    /**
     * RESET: invalidate the token, revoke sessions, require a new
     * registration/activation and issue a new pairing code.
     */
    public function reset(Request $request): Response
    {
        $device = Device::findScoped($request->int('id', 0));

        if ($device === null) {
            Session::flash('error', 'دستگاه یافت نشد.');

            return Redirect::response('/owner/devices');
        }

        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        $result = Device::resetRegistration($deviceId, 'owner_reset');

        // Stage 4: the reset is not complete without a usable one-time code.
        // The new code is hashed and single-use; the plain value is shown once.
        $activation = (new DeviceService())->issueActivationCode($deviceId, null, (int) (Session::userId() ?? 0));
        $code       = $activation['ok'] ? $activation['activation']['code'] : (string) $result['pairing_code'];

        AuditLog::record('device.reset', 'success',
            'دستگاه «' . (string) $device['name'] . '» ریست شد؛ توکن قبلی باطل و کد اتصال جدید صادر شد.',
            [
                'device_id'    => $deviceId,
                'customer_id'  => $customerId,
                'token_prefix' => (string) ($device['token_prefix'] ?? ''),
                // Stage 4: never write a usable code into the audit trail -
                // only its (non-secret) 4 character marker.
                'code_prefix'  => substr((string) $result['pairing_code'], 0, 4),
            ],
            'device',
            'device',
            $deviceId,
            $customerId
        );

        Session::flash('success', 'دستگاه ریست شد. کد فعال‌سازی یک‌بارمصرف جدید: ' . $code . ' (یک ساعت اعتبار) — توکن و نشست‌های قبلی دیگر کار نمی‌کنند.');

        return Redirect::response('/owner/devices?customer_id=' . $customerId);
    }

    /**
     * ISSUE ACTIVATION CODE (stage 4, origin of the whole activation flow).
     *
     * A one-time, time limited code that a device exchanges for its token. The
     * code is stored HASHED, is refused after one successful use and is void
     * after its TTL or after the owner resets/revokes the device. Only the
     * owner can do this: a manager can see devices of its own customer, but a
     * code grants a token, which is a platform level capability.
     */
    public function activationCode(Request $request): Response
    {
        $device = Device::findScoped($request->int('id', 0));

        if ($device === null) {
            Session::flash('error', 'دستگاه یافت نشد.');

            return Redirect::response('/owner/devices');
        }

        $ttl    = $request->int('ttl', 60);
        $result = (new DeviceService())->issueActivationCode((int) $device['id'], $ttl, (int) (Session::userId() ?? 0));

        if (!$result['ok']) {
            Session::flash('error', $result['message']);

            return Redirect::response('/owner/devices?customer_id=' . (int) $device['customer_id']);
        }

        Session::flash(
            'success',
            'کد فعال‌سازی یک‌بارمصرف «' . (string) $device['name'] . '»: '
            . $result['activation']['code']
            . ' — معتبر تا ' . $result['activation']['expires_at']
            . ' (یک بار قابل استفاده، سپس باطل می‌شود).'
        );

        return Redirect::response('/owner/devices?customer_id=' . (int) $device['customer_id']);
    }

    /**
     * ROTATE TOKEN: keep the registration, replace the API token.
     */
    public function rotateToken(Request $request): Response
    {
        $device = Device::findScoped($request->int('id', 0));

        if ($device === null) {
            Session::flash('error', 'دستگاه یافت نشد.');

            return Redirect::response('/owner/devices');
        }

        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        $token  = Device::issueToken($deviceId, 365);
        $result = DeviceSession::revokeAllForDevice($deviceId, 'token_rotated');

        AuditLog::record('device.token.reset', 'success',
            'توکن دستگاه «' . (string) $device['name'] . '» بازتولید شد.',
            [
                'device_id'    => $deviceId,
                'customer_id'  => $customerId,
                'new_prefix'   => $token['prefix'],
                'old_prefix'   => (string) ($device['token_prefix'] ?? ''),
                'sessions_revoked' => $result,
            ],
            'device',
            'device',
            $deviceId,
            $customerId
        );

        Session::flash('success', 'توکن جدید صادر شد. توکن دستگاه: ' . $token['token'] . ' — این مقدار فقط همین یک بار نمایش داده می‌شود.');

        return Redirect::response('/owner/devices?customer_id=' . $customerId);
    }

    /**
     * REVOKE: the device is permanently locked out (registration kept for
     * history so attendance records stay attributable).
     */
    public function revoke(Request $request): Response
    {
        $device = Device::findScoped($request->int('id', 0));

        if ($device === null) {
            Session::flash('error', 'دستگاه یافت نشد.');

            return Redirect::response('/owner/devices');
        }

        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        Device::revoke($deviceId, 'owner_revoke');
        DeviceSession::revokeAllForDevice($deviceId, 'owner_revoke');

        AuditLog::record('device.revoke', 'success',
            'ثبت دستگاه «' . (string) $device['name'] . '» لغو شد.',
            ['device_id' => $deviceId, 'customer_id' => $customerId],
            'device',
            'device',
            $deviceId,
            $customerId
        );

        Session::flash('success', 'دستگاه لغو شد و دیگر نمی‌تواند وارد شود.');

        return Redirect::response('/owner/devices?customer_id=' . $customerId);
    }

    /**
     * Soft delete (the row is kept, `deleted_at` is set).
     */
    public function destroy(Request $request): Response
    {
        $device = Device::findScoped($request->int('id', 0));

        if ($device === null) {
            Session::flash('error', 'دستگاه یافت نشد.');

            return Redirect::response('/owner/devices');
        }

        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];

        Device::revoke($deviceId, 'owner_delete');
        Device::deleteById($deviceId, 'owner_delete');

        AuditLog::record('device.delete', 'success',
            'دستگاه «' . (string) $device['name'] . '» حذف شد.',
            ['device_id' => $deviceId, 'customer_id' => $customerId],
            'device',
            'device',
            $deviceId,
            $customerId
        );

        Session::flash('success', 'دستگاه حذف شد.');

        return Redirect::response('/owner/devices?customer_id=' . $customerId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function customerOptions(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            Database::select(
                'SELECT `id`, `name` FROM `' . Database::table('customers') . '` WHERE `deleted_at` IS NULL ORDER BY `name` ASC LIMIT 500'
            )
        );
    }
}
