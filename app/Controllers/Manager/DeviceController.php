<?php
/**
 * SARMAD Attendance - manager panel: devices (stage 3, LIMITED surface).
 *
 * What a manager may do here:
 *   - see the devices of HIS customer (name, device id, branch, status, last seen)
 *   - start the initial registration of a new device (limited flow)
 *   - complete the pairing (activate) of a device of his own customer, which
 *     shows the device token exactly once - that is the registration
 *   - issue a fresh one-time KIOSK activation code for an own PENDING device
 *     (TASK_FOR_CODER_6): registration already hands one out, this is the
 *     "the code expired / was lost" path - still pending-only, because a
 *     re-activation code for a live kiosk would rotate its token (owner only)
 *
 * What a manager may NOT do (owner only, enforced in the routes AND here):
 *   - reset a device registration / tokens (device.reset)
 *   - rotate or revoke a token, delete a device
 * Deleting or resetting devices is an owner operation and is deliberately not
 * reachable from the manager panel - not by URL either.
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
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Services\DeviceService;
use App\Services\FeatureService;
use App\Support\Capability;
use App\Support\Input;

final class DeviceController
{
    private DeviceService $devices;

    public function __construct()
    {
        $this->devices = new DeviceService();
    }

    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $status = Input::choice($request->input('status', ''), [
            'pending' => '1', 'active' => '1', 'disabled' => '1', 'revoked' => '1',
        ]);

        return Response::html(View::render('manager/devices/index', [
            'title'           => 'دستگاه‌ها',
            'devices'         => Device::forCustomer($customerId, $status, 200),
            'stats'           => (new DeviceService())->inventory($customerId),
            'statuses'        => [
                Device::STATUS_PENDING  => 'در انتظار فعال‌سازی',
                Device::STATUS_ACTIVE   => 'فعال',
                Device::STATUS_DISABLED => 'غیرفعال',
                Device::STATUS_REVOKED  => 'لغو شده',
            ],
            'filter'          => $status,
            'branches'        => \feature_enabled($customerId, 'branches') ? Branch::forCustomer($customerId) : [],
            'branchesEnabled' => \feature_enabled($customerId, 'branches'),
            'tokenOnce'       => Session::pull('device_token_once'),
            'offlineAfter'    => 300,
            'customer'        => Customer::find($customerId),
            'error'           => Session::getFlash('error'),
            'success'         => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    /**
     * Limited registration: create a device row for THIS customer and get a
     * pairing code. Everything else (limits, branches) is validated here.
     */
    public function store(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        if (($denied = Capability::check($request, 'device.register')) !== null) {
            return $denied;
        }

        $name = Input::text($request->input('name', ''), 120);

        if ($name === '') {
            Session::flash('error', 'نام دستگاه الزامی است.');

            return Redirect::response('/manager/devices');
        }

        $branchId = 0;

        if (\feature_enabled($customerId, 'branches')) {
            $candidate = (int) $request->int('branch_id', 0);

            if ($candidate > 0) {
                if (Branch::findInCustomer($candidate, $customerId) === null) {
                    AuditLog::denied('تلاش برای ثبت دستگاه برای شعبه خارج از مشتری', [
                        'branch_id'   => $candidate,
                        'customer_id' => $customerId,
                    ]);

                    Session::flash('error', 'شعبه انتخاب شده معتبر نیست.');

                    return Redirect::response('/manager/devices');
                }

                $branchId = $candidate;
            }
        }

        $result = $this->devices->register($customerId, [
            'name'      => $name,
            'branch_id' => $branchId,
            'type'      => Input::choice($request->input('type', ''), ['kiosk' => '1', 'tablet' => '1', 'mobile' => '1', 'desktop' => '1'], 'kiosk'),
            'platform'  => Input::text($request->input('platform', ''), 60),
        ]);

        if (!$result['ok']) {
            Session::flash('error', $result['message']);

            return Redirect::response('/manager/devices');
        }

        $message = $result['message'];

        if (!empty($result['pairing_code'])) {
            // register() issues a real one-time kiosk activation code and
            // returns it under the legacy pairing_code key - say what it is.
            $message .= ' کد فعال‌سازی کیوسک: ' . (string) $result['pairing_code'] . ' (یک‌بارمصرف، ۲۴ ساعت اعتبار — روی دستگاه وارد کنید)';
        }

        Session::flash('success', $message);

        return Redirect::response('/manager/devices');
    }

    /**
     * Completing the pairing of an OWN device. The token is shown once and
     * can not be fetched again from the manager panel.
     */
    public function activate(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        if (($denied = Capability::check($request, 'device.register')) !== null) {
            return $denied;
        }

        $deviceId = (int) $request->int('device_id', 0);
        $device   = Device::findScoped($deviceId, $customerId);

        if ($device === null) {
            AuditLog::denied('تلاش برای فعال‌سازی دستگاه خارج از مشتری', [
                'device_id'   => $deviceId,
                'customer_id' => $customerId,
            ]);

            Session::flash('error', 'دستگاه یافت نشد.');

            return Redirect::response('/manager/devices');
        }

        $result = $this->devices->activate($deviceId);

        if (!$result['ok']) {
            Session::flash('error', $result['message']);

            return Redirect::response('/manager/devices');
        }

        Session::put('device_token_once', [
            'device_id' => $deviceId,
            'name'      => (string) ($result['device']['name'] ?? ''),
            'token'     => (string) $result['token'],
            'prefix'    => (string) ($result['prefix'] ?? ''),
        ]);

        Session::flash('success', $result['message']);

        return Redirect::response('/manager/devices');
    }

    /**
     * Issue a fresh one-time kiosk activation code for an OWN PENDING
     * device and flash it exactly once (TASK_FOR_CODER_6 #2c). Issuing
     * revokes the device's previous open codes, so an old printed code
     * can never outlive this click. Pending-only on purpose: for an
     * already active kiosk a new code would be a token rotation, which
     * stays an owner capability (reset / rotate in the owner panel).
     */
    public function activationCode(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        if (($denied = Capability::check($request, 'device.register')) !== null) {
            return $denied;
        }

        $deviceId = (int) $request->int('device_id', 0);
        $device   = Device::findScoped($deviceId, $customerId);

        if ($device === null) {
            AuditLog::denied('تلاش برای صدور کد فعال‌سازی دستگاه خارج از مشتری', [
                'device_id'   => $deviceId,
                'customer_id' => $customerId,
            ]);

            Session::flash('error', 'دستگاه یافت نشد.');

            return Redirect::response('/manager/devices');
        }

        if ((string) $device['status'] !== Device::STATUS_PENDING) {
            Session::flash('error', 'کد فعال‌سازی فقط برای دستگاه «در انتظار فعال‌سازی» صادر می‌شود. برای دستگاه فعال، ریست فقط از پنل مالک سامانه انجام می‌شود.');

            return Redirect::response('/manager/devices');
        }

        $result = $this->devices->issueActivationCode($deviceId, 60 * 24, (int) (Session::userId() ?? 0));

        if (!$result['ok']) {
            Session::flash('error', $result['message']);

            return Redirect::response('/manager/devices');
        }

        Session::flash(
            'success',
            'کد فعال‌سازی یک‌بارمصرف «' . (string) $device['name'] . '»: '
            . $result['activation']['code']
            . ' — معتبر تا ' . $result['activation']['expires_at']
            . ' (یک بار روی کیوسک قابل استفاده است، سپس باطل می‌شود).'
        );

        return Redirect::response('/manager/devices');
    }

    /**
     * Owner-only operations: they are refused here as well, so a route mistake
     * can never turn into a manager resetting a device.
     */
    public function reset(Request $request): Response
    {
        return $this->ownerOnly($request, 'device.reset');
    }

    public function rotate(Request $request): Response
    {
        return $this->ownerOnly($request, 'device.token.rotate');
    }

    public function revoke(Request $request): Response
    {
        return $this->ownerOnly($request, 'device.revoke');
    }

    private function ownerOnly(Request $request, string $capability): Response
    {
        return \App\Middleware\Deny::access($request, 'این عملیات فقط از پنل مالک سامانه قابل انجام است.', 403, [
            'capability' => $capability,
            'customer_id' => (int) TenantContext::customerId(),
        ]);
    }
}
