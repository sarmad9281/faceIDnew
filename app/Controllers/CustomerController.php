<?php
/**
 * SARMAD Attendance - customer (tenant) administration.
 *
 * Only owners may create customers. The action is intentionally small:
 * a real onboarding wizard belongs to the next stage.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Manager;
use App\Models\Role;
use App\Models\User;
use App\Core\Security;
use App\Support\Url;

final class CustomerController
{
    public function index(Request $request): Response
    {
        $search = (string) $request->input('q', '');

        return Response::html(View::render('customers/index', [
            'title'     => 'مشتریان',
            'customers' => Customer::listAll($search, 50, 0),
            'search'    => $search,
            'error'     => Session::getFlash('error'),
            'success'   => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function create(Request $request): Response
    {
        return Response::html(View::render('customers/create', [
            'title'   => 'افزودن مشتری',
            'error'   => Session::getFlash('error'),
            'success' => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $ownerId = (int) (Session::userId() ?? 0);

        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:150',
            'company_name'  => 'string|max:180',
            'contact_email' => 'email|max:190',
            'contact_phone' => 'phone',
            'timezone'      => 'timezone',
            'date_system'   => 'in:jalali,gregorian',
            // initial manager account
            'manager_name'  => 'string|max:150',
            'manager_email' => 'email|max:190',
            'manager_password' => 'password_strength',
        ], [
            'name'             => 'نام مشتری',
            'company_name'     => 'نام شرکت',
            'contact_email'    => 'ایمیل تماس',
            'contact_phone'    => 'تلفن تماس',
            'timezone'         => 'منطقه زمانی',
            'date_system'      => 'تقویم',
            'manager_name'     => 'نام مدیر',
            'manager_email'    => 'ایمیل مدیر',
            'manager_password' => 'رمز عبور مدیر',
        ]);

        if ($validator->fails()) {
            Session::flash('error', $validator->firstError());

            return Redirect::response('/owner/customers/create');
        }

        $data = $validator->validated();

        $customerId = Customer::createCustomer([
            'name'          => (string) $data['name'],
            'company_name'  => $data['company_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'timezone'      => $data['timezone'] ?? 'Asia/Tehran',
            'date_system'   => $data['date_system'] ?? 'jalali',
            'status'        => 'active',
            'created_by'    => $ownerId,
        ]);

        $customer = Customer::findOrFail($customerId);

        AuditLog::record('customer.create', 'success', 'مشتری جدید ایجاد شد.', [
            'customer_id' => $customerId,
            'name'        => (string) $customer['name'],
        ], 'app', 'customer', $customerId, $customerId);

        $message = 'مشتری «' . (string) $customer['name'] . '» ایجاد شد.';

        // Optional: create the first manager account for the customer.
        if (!empty($data['manager_email']) && !empty($data['manager_password'])) {
            $managerId = Manager::createManager([
                'customer_id' => $customerId,
                'name'        => (string) ($data['manager_name'] ?? $data['name']),
                'email'       => (string) $data['manager_email'],
                'is_primary'  => 1,
                'created_by'  => $ownerId,
            ]);

            $userId = User::createUser([
                'role'         => Role::MANAGER,
                'role_id'      => Role::idFor(Role::MANAGER),
                'customer_id'  => $customerId,
                'manager_id'   => $managerId,
                'name'         => (string) ($data['manager_name'] ?? $data['name']),
                'username'     => $this->usernameFrom($data['manager_email']),
                'email'        => (string) $data['manager_email'],
                'password'     => (string) $data['manager_password'],
                'must_change_password' => 1,
                'is_active'    => 1,
                'created_by'   => $ownerId,
            ]);

            Manager::linkUser($managerId, $userId);

            AuditLog::record('user.create', 'success', 'کاربر مدیر برای مشتری ایجاد شد.', [
                'user_id'     => $userId,
                'customer_id' => $customerId,
                'role'        => Role::MANAGER,
            ], 'app', 'user', $userId, $customerId);

            $managerUser = User::find($userId);
            $message .= ' کاربر مدیر با نام کاربری «' . (string) ($managerUser['username'] ?? '') . '» ساخته شد.';
        }

        Session::flash('success', $message);

        return Redirect::response('/owner/customers');
    }

    public function show(Request $request): Response
    {
        $uuid = (string) $request->input('customer', '');

        $customer = Customer::findByUuid($uuid);

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $customerId = (int) $customer['id'];

        return Response::html(View::render('customers/show', [
            'title'    => 'جزئیات مشتری',
            'customer' => $customer,
            'stats'    => Customer::stats($customerId),
            'managers' => Manager::forCustomer($customerId),
            'branches' => \App\Models\Branch::forCustomer($customerId),
            'devices'  => Device::forCustomer($customerId, '', 20),
            'audit'    => AuditLog::recent($customerId, 20),
        ], 'layouts/app'));
    }

    private function usernameFrom(string $email): string
    {
        $local = explode('@', $email)[0] ?? 'manager';

        return preg_replace('/[^a-z0-9_\.]/', '', strtolower($local)) ?: 'manager';
    }
}
