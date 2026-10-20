<?php
/**
 * SARMAD Attendance - device inventory management (web, manager/owner).
 *
 * The kiosk UI itself (camera, face recognition) is out of scope for
 * this stage - here we only manage device identity, tokens and status.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Core\View;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Services\DeviceService;
use App\Support\Url;

final class DeviceController
{
    private DeviceService $devices;

    public function __construct()
    {
        $this->devices = new DeviceService();
    }

    public function index(Request $request): Response
    {
        // Owners reach this screen through an explicit customer scope.
        $customerId = TenantContext::customerId();

        if ($customerId === null) {
            return Response::html(View::render('devices/index', [
                'title'    => 'دستگاه‌ها',
                'devices'  => [],
                'branches' => [],
                'stats'    => ['total' => 0, 'online' => 0, 'offline' => 0],
                'scopeMissing' => true,
                'error'   => Session::getFlash('error'),
                'success' => Session::getFlash('success'),
            ], 'layouts/app'));
        }

        return Response::html(View::render('devices/index', [
            'title'    => 'دستگاه‌های حضور و غیاب',
            'devices'  => Device::forCustomer($customerId),
            'branches' => Branch::forCustomer($customerId),
            'stats'    => (new DeviceService())->inventory($customerId),
            'onlineAfter' => 300,
            'scopeMissing' => false,
            'error'    => Session::getFlash('error'),
            'success'  => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:120',
            'branch_id'   => 'integer',
            'type'        => 'in:kiosk,tablet,mobile,desktop',
            'platform'    => 'string|max:60',
            'device_uid'  => 'alpha_dash|max:64',
            'serial_number' => 'string|max:80',
        ], [
            'name'   => 'نام دستگاه',
            'type'   => 'نوع دستگاه',
        ]);

        if ($validator->fails()) {
            Session::flash('error', $validator->firstError());

            return Redirect::response('/devices');
        }

        $data = $validator->validated();

        $result = $this->devices->register($customerId, $data);

        if (!$result['ok']) {
            Session::flash('error', $result['message']);

            return Redirect::response('/devices');
        }

        $message = $result['message'];

        if (!empty($result['pairing_code'])) {
            $message .= ' کد جفت‌سازی: ' . (string) $result['pairing_code'];
        }

        Session::flash('success', $message);

        return Redirect::response('/devices');
    }

    public function activate(Request $request): Response
    {
        $deviceId = $request->int('device_id');

        $result = $this->devices->activate($deviceId);

        if (!$result['ok']) {
            Session::flash('error', $result['message']);

            return Redirect::response('/devices');
        }

        // The device token is a secret: it is shown exactly once.
        Session::put('device_token_once', [
            'device_id' => $deviceId,
            'name'      => (string) ($result['device']['name'] ?? ''),
            'token'     => (string) $result['token'],
            'prefix'    => (string) ($result['prefix'] ?? ''),
        ]);

        Session::flash('success', $result['message']);

        return Redirect::response('/devices?token=1');
    }

    public function rotate(Request $request): Response
    {
        $result = $this->devices->rotateToken($request->int('device_id'));

        if (!$result['ok']) {
            Session::flash('error', $result['message']);

            return Redirect::response('/devices');
        }

        Session::put('device_token_once', [
            'device_id' => $request->int('device_id'),
            'token'     => (string) $result['token'],
            'prefix'    => (string) ($result['prefix'] ?? ''),
        ]);

        Session::flash('success', $result['message']);

        return Redirect::response('/devices?token=1');
    }

    public function revoke(Request $request): Response
    {
        $result = $this->devices->revoke($request->int('device_id'), 'revoked from panel');

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/devices');
    }
}
