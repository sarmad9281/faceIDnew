<?php
/**
 * SARMAD Attendance - owner panel: emergency access (impersonation).
 *
 * The owner can open a customer's manager panel to help that customer.
 * Rules implemented here:
 *   - a reason is mandatory,
 *   - the whole session is recorded (entry, exit, duration, action count),
 *   - the manager password is never shown: the identity screen displays the
 *     username only. There is no way to display a password because only a
 *     bcrypt/argon hash is stored and no endpoint returns it.
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Customer;
use App\Models\Manager;
use App\Models\User;
use App\Services\ImpersonationService;

final class EmergencyController
{
    /**
     * Overview page: what emergency access is, plus every past session.
     */
    public function index(Request $request): Response
    {
        return Response::html(View::render('owner/emergency/index', [
            'title'   => 'دسترسی اضطراری',
            'open'    => ImpersonationService::openSessions(),
            'history' => ImpersonationService::history(50, 0, null),
            'enabled' => ImpersonationService::enabled(),
            'active'  => 'emergency',
        ], 'layouts/owner'));
    }

    /**
     * Confirmation screen before entering a customer panel.
     */
    public function show(Request $request): Response
    {
        $customer = $this->customer($request);

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        return Response::html(View::render('owner/emergency/confirm', [
            'title'    => 'ورود اضطراری به پنل مشتری',
            'customer' => $customer,
            'manager'  => Manager::primaryFor((int) $customer['id']),
            'enabled'  => ImpersonationService::enabled(),
            'active'   => 'customers',
        ], 'layouts/owner'));
    }

    /**
     * Start emergency access.
     */
    public function start(Request $request): Response
    {
        $customer = $this->customer($request);

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $result = (new ImpersonationService())->start(
            (int) $customer['id'],
            (string) $request->input('reason', ''),
            (int) (Session::userId() ?? 0)
        );

        if (!$result['ok']) {
            Session::flash('error', $result['message']);

            return Redirect::response('/owner/customers/' . (string) $customer['uuid'] . '/emergency');
        }

        Session::flash('success', $result['message']);

        return Redirect::response('/dashboard');
    }

    /**
     * End emergency access and go back to the owner panel.
     */
    public function stop(Request $request): Response
    {
        $payload = ImpersonationService::current();

        (new ImpersonationService())->stop('manual_exit');

        $message = 'دسترسی اضطراری پایان یافت.';

        if ($payload !== null) {
            $message .= ' مدت: ' . max(0, time() - (int) strtotime((string) $payload['started_at'])) . ' ثانیه.';
        }

        Session::flash('success', $message);

        return Redirect::response('/owner/emergency');
    }

    /**
     * Manager identity shown to the owner - username only, never a secret.
     */
    public function managerIdentity(Request $request): Response
    {
        $customer = $this->customer($request);

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $manager = Manager::primaryFor((int) $customer['id']);
        $user    = $manager !== null && $manager['user_id'] !== null ? User::find((int) $manager['user_id']) : null;

        return Response::html(View::render('owner/emergency/identity', [
            'title'    => 'اطلاعات ورود مدیر',
            'customer' => $customer,
            'manager'  => $manager,
            'user'     => $user,
            'active'   => 'customers',
        ], 'layouts/owner'));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function customer(Request $request): ?array
    {
        $uuid = $request->param('uuid');

        if ($uuid !== '') {
            return Customer::findByUuid($uuid) ?? Customer::find((int) $uuid);
        }

        $id = $request->int('customer_id', 0);

        return $id > 0 ? Customer::find($id) : null;
    }
}
