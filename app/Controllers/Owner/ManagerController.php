<?php
/**
 * SARMAD Attendance - owner panel: manager management.
 *
 * Managers are the customer side administrators. Only the owner creates
 * them (a manager can never create another manager - Role::assignableBy
 * enforces that as well, see app/Models/Role.php).
 *
 * Two identities are kept in sync:
 *   attendance_managers   - the business identity of the person
 *   attendance_users      - the login (role = manager, pinned to customer)
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Database;
use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\Customer;
use App\Models\Manager;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Url;

final class ManagerController
{
    /* =================================================================
     * Listing
     * ===============================================================*/

    public function index(Request $request): Response
    {
        $filters = [
            'q'           => (string) $request->input('q', ''),
            'status'      => (string) $request->input('status', ''),
            'customer_id' => $request->int('customer_id', 0),
        ];

        $listing = Manager::paginateForOwner($filters, $request->page(), $request->perPage(20));

        return Response::html(View::render('owner/managers/index', [
            'title'    => 'مدیران مشتریان',
            'listing'  => $listing,
            'filters'  => $filters,
            'customers'=> $this->customerOptions(),
            'active'   => 'managers',
        ], 'layouts/owner'));
    }

    /* =================================================================
     * Create
     * ===============================================================*/

    public function create(Request $request): Response
    {
        $customer = $this->customerFromQuery($request);

        if ($customer === null) {
            Session::flash('error', 'ابتدا مشتری مورد نظر را انتخاب کنید.');

            return Redirect::response('/owner/managers');
        }

        return Response::html(View::render('owner/managers/form', [
            'title'    => 'افزودن مدیر برای «' . (string) $customer['name'] . '»',
            'manager'  => null,
            'user'     => null,
            'customer' => $customer,
            'mode'     => 'create',
            'active'   => 'managers',
            'old'      => Session::pull('old_input', []),
        ], 'layouts/owner'));
    }

    public function store(Request $request): Response
    {
        $customer = Customer::find($request->int('customer_id', 0));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/managers');
        }

        $customerId = (int) $customer['id'];
        $ownerId    = (int) (Session::userId() ?? 0);

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:80',
            'last_name'  => 'required|string|max:80',
            'mobile'     => 'phone',
            'email'      => 'required|email|max:190',
            'title'      => 'string|max:120',
            'username'   => 'required|alpha_dash|max:60',
            'password'   => 'required|password_strength',
            'is_primary' => 'string|max:3',
        ], [
            'first_name' => 'نام',
            'last_name'  => 'نام خانوادگی',
            'mobile'     => 'موبایل',
            'email'      => 'ایمیل',
            'title'      => 'سمت',
            'username'   => 'نام کاربری',
            'password'   => 'رمز عبور',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/owner/managers/create?customer_id=' . $customerId, $validator->errors(), $request->all());
        }

        $data = $validator->validated();

        if (User::usernameTaken((string) $data['username'])) {
            return $this->backWithErrors('/owner/managers/create?customer_id=' . $customerId, [
                'username' => ['این نام کاربری قبلاً استفاده شده است.'],
            ], $request->all());
        }

        if (User::emailTaken((string) $data['email'])) {
            return $this->backWithErrors('/owner/managers/create?customer_id=' . $customerId, [
                'email' => ['این ایمیل قبلاً ثبت شده است.'],
            ], $request->all());
        }

        $fullName = trim((string) $data['first_name'] . ' ' . (string) $data['last_name']);

        $managerId = Manager::createManager([
            'customer_id' => $customerId,
            'name'        => $fullName,
            'title'       => $data['title'] ?? null,
            'mobile'      => $data['mobile'] ?? null,
            'email'       => (string) $data['email'],
            'is_primary'  => (string) ($data['is_primary'] ?? '0') === '1' ? 1 : 0,
            'is_active'   => 1,
            'created_by'  => $ownerId,
        ]);

        $userId = User::createUser([
            'role'                 => Role::MANAGER,
            'role_id'              => Role::idFor(Role::MANAGER),
            'customer_id'          => $customerId,
            'manager_id'           => $managerId,
            'name'                 => $fullName,
            'username'             => (string) $data['username'],
            'email'                => (string) $data['email'],
            'mobile'               => $data['mobile'] ?? null,
            'password'             => (string) $data['password'],
            'must_change_password' => 1,
            'is_active'            => 1,
            'created_by'           => $ownerId,
        ]);

        Manager::linkUser($managerId, $userId);

        AuditService::log('manager.create', 'success', 'مدیر «' . $fullName . '» ایجاد شد.', [
            'manager_id'  => $managerId,
            'user_id'     => $userId,
            'username'    => (string) $data['username'],
            'customer_id' => $customerId,
        ], 'app', 'manager', $managerId, $customerId);

        Session::flash('success', 'مدیر «' . $fullName . '» ایجاد شد و هنگام اولین ورود باید رمز خود را تغییر دهد.');

        return Redirect::response('/owner/managers?customer_id=' . $customerId);
    }

    /* =================================================================
     * Edit / update
     * ===============================================================*/

    public function edit(Request $request): Response
    {
        $manager = Manager::find($this->managerId($request));

        if ($manager === null) {
            Session::flash('error', 'مدیر یافت نشد.');

            return Redirect::response('/owner/managers');
        }

        $customer = Customer::find((int) $manager['customer_id']);

        if ($customer === null) {
            Session::flash('error', 'مشتری این مدیر یافت نشد.');

            return Redirect::response('/owner/managers');
        }

        return Response::html(View::render('owner/managers/form', [
            'title'    => 'ویرایش مدیر: ' . (string) $manager['name'],
            'manager'  => $manager,
            'user'     => $manager['user_id'] !== null ? User::find((int) $manager['user_id']) : null,
            'customer' => $customer,
            'mode'     => 'edit',
            'active'   => 'managers',
            'old'      => Session::pull('old_input', []),
        ], 'layouts/owner'));
    }

    public function update(Request $request): Response
    {
        $manager = Manager::find($this->managerId($request));

        if ($manager === null) {
            Session::flash('error', 'مدیر یافت نشد.');

            return Redirect::response('/owner/managers');
        }

        $managerId  = (int) $manager['id'];
        $customerId = (int) $manager['customer_id'];

        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:150',
            'mobile'   => 'phone',
            'email'    => 'required|email|max:190',
            'title'    => 'string|max:120',
            'username' => 'required|alpha_dash|max:60',
        ], [
            'name'     => 'نام مدیر',
            'mobile'   => 'موبایل',
            'email'    => 'ایمیل',
            'username' => 'نام کاربری',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/owner/managers/' . $managerId . '/edit', $validator->errors(), $request->all());
        }

        $data = $validator->validated();

        $user = $manager['user_id'] !== null ? User::find((int) $manager['user_id']) : null;

        if ($user !== null) {
            if (User::usernameTaken((string) $data['username'], (int) $user['id'])) {
                return $this->backWithErrors('/owner/managers/' . $managerId . '/edit', [
                    'username' => ['این نام کاربری قبلاً استفاده شده است.'],
                ], $request->all());
            }

            if (User::emailTaken((string) $data['email'], (int) $user['id'])) {
                return $this->backWithErrors('/owner/managers/' . $managerId . '/edit', [
                    'email' => ['این ایمیل قبلاً ثبت شده است.'],
                ], $request->all());
            }
        }

        $before = [
            'name'     => (string) $manager['name'],
            'mobile'   => (string) ($manager['mobile'] ?? ''),
            'email'    => (string) ($manager['email'] ?? ''),
            'title'    => (string) ($manager['title'] ?? ''),
            'username' => $user !== null ? (string) $user['username'] : '',
        ];

        Manager::updateById($managerId, [
            'name'   => (string) $data['name'],
            'mobile' => $data['mobile'] ?? null,
            'email'  => (string) $data['email'],
            'title'  => $data['title'] ?? null,
        ]);

        if ($user !== null) {
            User::updateById((int) $user['id'], [
                'name'     => (string) $data['name'],
                'username' => (string) $data['username'],
                'email'    => (string) $data['email'],
                'mobile'   => $data['mobile'] ?? null,
            ]);

            // Role and customer can never be changed through this form.
            Database::run(
                'UPDATE `' . Database::table('users') . '` SET `role` = ?, `role_id` = ? WHERE `id` = ? AND `role` = ?',
                [Role::MANAGER, Role::idFor(Role::MANAGER), (int) $user['id'], Role::MANAGER]
            );
        }

        AuditService::log('manager.update', 'success', 'اطلاعات مدیر «' . (string) $data['name'] . '» ویرایش شد.', [
            'manager_id'  => $managerId,
            'customer_id' => $customerId,
            'before'      => $before,
            'after'       => [
                'name'     => (string) $data['name'],
                'email'    => (string) $data['email'],
                'username' => (string) $data['username'],
            ],
        ], 'app', 'manager', $managerId, $customerId);

        Session::flash('success', 'اطلاعات مدیر ذخیره شد.');

        return Redirect::response('/owner/managers/' . $managerId . '/edit');
    }

    /* =================================================================
     * Activate / deactivate
     * ===============================================================*/

    public function toggle(Request $request): Response
    {
        $manager = Manager::find($this->managerId($request));

        if ($manager === null) {
            Session::flash('error', 'مدیر یافت نشد.');

            return Redirect::response('/owner/managers');
        }

        $managerId  = (int) $manager['id'];
        $customerId = (int) $manager['customer_id'];
        $active     = $request->input('active', '') === '1';

        Manager::setActive($managerId, $active);

        if ($manager['user_id'] !== null) {
            $userId = (int) $manager['user_id'];

            User::updateById($userId, ['is_active' => $active ? 1 : 0]);

            // Turning a manager off must also revoke their API tokens.
            if (!$active) {
                Database::run(
                    'UPDATE `' . Database::table('api_tokens') . '` SET `revoked_at` = NOW() WHERE `user_id` = ? AND `revoked_at` IS NULL',
                    [$userId]
                );
            }
        }

        AuditService::log(
            $active ? 'manager.enable' : 'manager.disable',
            'success',
            ($active ? 'مدیر «' : 'مدیر «') . (string) $manager['name'] . ($active ? '» فعال شد.' : '» غیرفعال شد.'),
            ['manager_id' => $managerId, 'customer_id' => $customerId],
            'app',
            'manager',
            $managerId,
            $customerId
        );

        Session::flash('success', $active ? 'مدیر فعال شد.' : 'مدیر غیرفعال شد.');

        return Redirect::response('/owner/managers?customer_id=' . $customerId);
    }

    /* =================================================================
     * Password reset
     * ===============================================================*/

    public function resetPassword(Request $request): Response
    {
        $manager = Manager::find($this->managerId($request));

        if ($manager === null) {
            Session::flash('error', 'مدیر یافت نشد.');

            return Redirect::response('/owner/managers');
        }

        $managerId  = (int) $manager['id'];
        $customerId = (int) $manager['customer_id'];

        if ($manager['user_id'] === null) {
            Session::flash('error', 'این مدیر حساب کاربری فعال ندارد.');

            return Redirect::response('/owner/managers/' . $managerId . '/edit');
        }

        $userId = (int) $manager['user_id'];

        $password = Security::suggestPassword(14);
        $problems = Security::passwordProblems($password);

        if ($problems !== []) {
            Session::flash('error', 'تولید رمز عبور با خطا مواجه شد: ' . (string) $problems[0]);

            return Redirect::response('/owner/managers/' . $managerId . '/edit');
        }

        User::setPassword($userId, $password, true);

        // Any live session of that user is invalidated by the password
        // change policy: revoke API tokens as well.
        Database::run(
            'UPDATE `' . Database::table('api_tokens') . '` SET `revoked_at` = NOW() WHERE `user_id` = ? AND `revoked_at` IS NULL',
            [$userId]
        );

        AuditService::log('manager.password.reset', 'success', 'رمز عبور مدیر «' . (string) $manager['name'] . '» بازنشانی شد.', [
            'manager_id'  => $managerId,
            'user_id'     => $userId,
            'customer_id' => $customerId,
        ], 'security', 'manager', $managerId, $customerId);

        // The generated password is shown exactly once, in the session flash.
        Session::flash('success', 'رمز عبور جدید: ' . $password . ' — این رمز فقط همین یک بار نمایش داده می‌شود و هنگام اولین ورود باید تغییر کند.');

        return Redirect::response('/owner/managers/' . $managerId . '/edit');
    }

    /* =================================================================
     * Helpers
     * ===============================================================*/

    /**
     * @return array<int,array<string,mixed>>
     */
    private function customerOptions(): array
    {
        return array_map(
            static fn (array $row): array => [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
            ],
            Database::select(
                'SELECT `id`, `name` FROM `' . Database::table('customers') . '` WHERE `deleted_at` IS NULL ORDER BY `name` ASC LIMIT 500'
            )
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function customerFromQuery(Request $request): ?array
    {
        $id = $request->int('customer_id', 0);

        return $id > 0 ? Customer::find($id) : null;
    }

    /**
     * @param array<string,array<int,string>> $errors
     * @param array<string,mixed> $old
     */
    /**
     * Manager id from the route (/owner/managers/{id}/...) or from the
     * submitted form field, whichever is present.
     */
    private function managerId(Request $request): int
    {
        $param = (string) $request->param('id');

        if ($param !== '' && preg_match('/^\d+$/', $param) === 1) {
            return (int) $param;
        }

        $posted = $request->int('manager_id', 0);

        return $posted > 0 ? $posted : $request->int('id', 0);
    }

    private function backWithErrors(string $path, array $errors, array $old): Response
    {
        Session::put('old_input', $old);
        Session::flash('errors', (string) json_encode($errors, JSON_UNESCAPED_UNICODE));

        $first = 'اطلاعات ارسالی معتبر نیست.';

        foreach ($errors as $messages) {
            if (isset($messages[0])) {
                $first = (string) $messages[0];
                break;
            }
        }

        Session::flash('error', $first);

        return Redirect::response($path);
    }
}
