<?php
/**
 * SARMAD Attendance - owner panel: customer management.
 *
 * Every action is owner-only (enforced by middleware AND re-checked in the
 * controller), tenant scoped where a customer is involved, CSRF protected
 * for writes and audited with the canonical action vocabulary.
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FeatureService;
use App\Services\UploadService;
use App\Support\Url;

final class CustomerController
{
    /* =================================================================
     * Listing
     * ===============================================================*/

    public function index(Request $request): Response
    {
        $filters = [
            'q'            => (string) $request->input('q', ''),
            'status'       => (string) $request->input('status', ''),
            'subscription' => (string) $request->input('subscription', ''),
            'plan'         => (string) $request->input('plan', ''),
            'sort'         => (string) $request->input('sort', 'id DESC'),
        ];

        $listing = Customer::paginateForOwner($filters, $request->page(), $request->perPage(20));
        $listing['rows'] = Customer::withCounters($listing['rows']);

        return Response::html(View::render('owner/customers/index', [
            'title'    => 'مدیریت مشتریان',
            'listing'  => $listing,
            'filters'  => $filters,
            'plans'    => Subscription::planTitles(),
            'statuses' => Subscription::statusTitles(),
            'active'   => 'customers',
        ], 'layouts/owner'));
    }

    /* =================================================================
     * Create
     * ===============================================================*/

    public function create(Request $request): Response
    {
        return Response::html(View::render('owner/customers/form', [
            'title'       => 'افزودن مشتری جدید',
            'customer'    => null,
            'managers'    => [],
            'mode'        => 'create',
            'trialDays'   => (int) \App\Models\Setting::get('platform.default_trial_days', 14),
            'defaultPlan' => (string) \App\Models\Setting::get('platform.default_plan', 'trial'),
            'plans'       => Subscription::planTitles(),
            'statuses'    => Subscription::statusTitles(),
            'active'      => 'customers',
            'old'         => Session::pull('old_input', []),
        ], 'layouts/owner'));
    }

    public function store(Request $request): Response
    {
        $ownerId = (int) (Session::userId() ?? 0);

        $validator = Validator::make($request->all(), [
            'name'                 => 'required|string|max:150',
            'company_name'         => 'string|max:180',
            'contact_email'        => 'email|max:190',
            'contact_phone'        => 'phone',
            'address'              => 'string|max:255',
            'notes'                => 'string|max:500',
            'timezone'             => 'timezone',
            'date_system'          => 'in:jalali,gregorian',
            'plan_name'            => 'string|max:40',
            'subscription_status'  => 'in:trial,active,expired,suspended,canceled',
            'start_date'           => 'string|max:10',
            'expire_date'          => 'string|max:10',
            // optional first manager account
            'manager_first_name'   => 'string|max:80',
            'manager_last_name'    => 'string|max:80',
            'manager_mobile'       => 'phone',
            'manager_email'        => 'email|max:190',
            'manager_username'     => 'alpha_dash|max:60',
            'manager_password'     => 'password_strength',
        ], [
            'name'               => 'نام کسب‌وکار',
            'company_name'       => 'نام شرکت',
            'contact_email'      => 'ایمیل تماس',
            'contact_phone'      => 'تلفن تماس',
            'timezone'           => 'منطقه زمانی',
            'plan_name'          => 'پلن',
            'subscription_status' => 'وضعیت اشتراک',
            'manager_email'      => 'ایمیل مدیر',
            'manager_username'   => 'نام کاربری مدیر',
            'manager_password'   => 'رمز عبور مدیر',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/owner/customers/create', $validator->errors(), $request->all());
        }

        $data = $validator->validated();

        $wantsManager = trim((string) ($data['manager_email'] ?? '')) !== ''
            || trim((string) ($data['manager_username'] ?? '')) !== '';

        if ($wantsManager) {
            // If a manager account is requested, the three mandatory fields
            // must all be present - no half created accounts.
            $missing = [];

            if (trim((string) ($data['manager_email'] ?? '')) === '') {
                $missing['manager_email'] = ['ایمیل مدیر الزامی است.'];
            }

            if (trim((string) ($data['manager_username'] ?? '')) === '') {
                $missing['manager_username'] = ['نام کاربری مدیر الزامی است.'];
            }

            if (strlen((string) ($data['manager_password'] ?? '')) < 8) {
                $missing['manager_password'] = ['رمز عبور مدیر باید حداقل ۸ کاراکتر باشد.'];
            }

            if ($missing !== []) {
                return $this->backWithErrors('/owner/customers/create', $missing, $request->all());
            }

            if (User::usernameTaken((string) $data['manager_username'])) {
                return $this->backWithErrors('/owner/customers/create', [
                    'manager_username' => ['این نام کاربری قبلاً استفاده شده است.'],
                ], $request->all());
            }

            if (User::emailTaken((string) $data['manager_email'])) {
                return $this->backWithErrors('/owner/customers/create', [
                    'manager_email' => ['این ایمیل قبلاً ثبت شده است.'],
                ], $request->all());
            }
        }

        $start  = $this->dateOrNull($data['start_date'] ?? '') ?? date('Y-m-d');
        $expire = $this->dateOrNull($data['expire_date'] ?? '');

        if ($expire === null) {
            $days   = max(1, (int) \App\Models\Setting::get('platform.default_trial_days', 14));
            $expire = date('Y-m-d', time() + ($days * 86400));
        }

        $customerId = Customer::createCustomer([
            'name'           => (string) $data['name'],
            'company_name'   => $data['company_name'] ?? null,
            'contact_email'  => $data['contact_email'] ?? null,
            'contact_phone'  => $data['contact_phone'] ?? null,
            'address'        => $data['address'] ?? null,
            'timezone'       => $data['timezone'] ?? 'Asia/Tehran',
            'date_system'    => $data['date_system'] ?? 'jalali',
            'status'         => 'active',
            'created_by'     => $ownerId,
            // Current-state subscription columns (kept in sync with the
            // history row written just below).
            'plan_name'                => (string) ($data['plan_name'] ?? 'trial'),
            'subscription_plan'        => (string) ($data['plan_name'] ?? 'trial'),
            'subscription_status'      => (string) ($data['subscription_status'] ?? 'trial'),
            'subscription_start_date'  => $start,
            'subscription_expire_date' => $expire,
        ]);

        $customer = Customer::findOrFail($customerId);

        AuditLog::record('customer.create', 'success', 'مشتری «' . (string) $customer['name'] . '» ایجاد شد.', [
            'customer_id' => $customerId,
            'name'        => (string) $customer['name'],
            'plan'        => (string) ($data['plan_name'] ?? 'trial'),
        ], 'app', 'customer', $customerId, $customerId);

        // 1) Feature defaults (explicit rows, never implicit).
        $features = FeatureService::seedDefaults($customerId, $ownerId);

        AuditService::log('feature.update', 'success', 'امکانات پیش‌فرض برای مشتری جدید فعال شد.', [
            'customer_id' => $customerId,
            'created'     => $features,
        ], 'app', 'customer', $customerId, $customerId);

        // 2) First subscription history row.
        $subscriptionId = Subscription::record([
            'customer_id'   => $customerId,
            'plan_name'     => (string) ($data['plan_name'] ?? 'trial'),
            'status'        => (string) ($data['subscription_status'] ?? 'trial'),
            'start_date'    => $start,
            'expire_date'   => $expire,
            'max_employees' => (int) ($customer['max_employees'] ?? 50),
            'max_devices'   => (int) ($customer['max_devices'] ?? 5),
            'notes'         => 'اشتراک اولیه هنگام ایجاد مشتری',
            'created_by'    => $ownerId,
        ]);

        $message = 'مشتری «' . (string) $customer['name'] . '» ایجاد شد.';

        // 3) Optional manager account.
        if ($wantsManager) {
            $result = $this->createManagerFor($customerId, [
                'first_name' => (string) ($data['manager_first_name'] ?? ''),
                'last_name'  => (string) ($data['manager_last_name'] ?? ''),
                'email'      => (string) $data['manager_email'],
                'mobile'     => (string) ($data['manager_mobile'] ?? ''),
                'username'   => (string) $data['manager_username'],
                'password'   => (string) $data['manager_password'],
                'is_primary' => true,
            ], $ownerId);

            if ($result['ok']) {
                $message .= ' مدیر «' . $result['name'] . '» با نام کاربری «' . $result['username'] . '» ساخته شد.';
            } else {
                Session::flash('error', 'مشتری ساخته شد، اما ایجاد مدیر ناموفق بود: ' . $result['message']);
            }
        }

        Subscription::find($subscriptionId); // touch + keeps the FK relationship warm

        Session::flash('success', $message);

        return Redirect::response('/owner/customers/' . (string) $customer['uuid']);
    }

    /* =================================================================
     * Show
     * ===============================================================*/

    public function show(Request $request): Response
    {
        $customer = $this->customerOr404($request->param('uuid'));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $customerId = (int) $customer['id'];

        $managers = Manager::forCustomer($customerId);
        $devices  = Device::forCustomer($customerId, '', 20);

        return Response::html(View::render('owner/customers/show', [
            'title'        => 'جزئیات مشتری: ' . (string) $customer['name'],
            'customer'     => $customer,
            'stats'        => Customer::stats($customerId),
            'counters'     => (Customer::withCounters([$customer]))[0],
            'managers'     => $managers,
            'managersFull' => Manager::paginateForOwner(['customer_id' => $customerId], 1, 50),
            'branches'     => Branch::forCustomerDetailed($customerId),
            'employees'    => Employee::paginate(['customer_id' => $customerId], 1, 10),
            'devices'      => $devices,
            'features'     => FeatureService::stateFor($customerId),
            'subscription' => Subscription::currentFor($customerId),
            'subHistory'   => Subscription::historyFor($customerId, 10),
            'subStatus'    => Subscription::effectiveStatus(Subscription::currentFor($customerId)),
            'daysLeft'     => Subscription::daysRemaining(Subscription::currentFor($customerId)),
            'audit'        => AuditLog::recent($customerId, 12),
            'impersonation'=> \App\Services\ImpersonationService::history(10, 0, $customerId),
            'logoUrl'      => Url::customerLogo($customer),
            'active'       => 'customers',
        ], 'layouts/owner'));
    }

    /* =================================================================
     * Edit / update
     * ===============================================================*/

    public function edit(Request $request): Response
    {
        $customer = $this->customerOr404($request->param('uuid'));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        return Response::html(View::render('owner/customers/form', [
            'title'       => 'ویرایش مشتری: ' . (string) $customer['name'],
            'customer'    => $customer,
            'managers'    => Manager::forCustomer((int) $customer['id']),
            'mode'        => 'edit',
            'trialDays'   => (int) \App\Models\Setting::get('platform.default_trial_days', 14),
            'defaultPlan' => (string) ($customer['plan_name'] ?? 'trial'),
            'plans'       => Subscription::planTitles(),
            'statuses'    => Subscription::statusTitles(),
            'active'      => 'customers',
            'subStatus'   => Subscription::effectiveStatus(Subscription::currentFor((int) $customer['id'])),
            'logoUrl'     => Url::customerLogo($customer),
            'old'         => Session::pull('old_input', []),
        ], 'layouts/owner'));
    }

    public function update(Request $request): Response
    {
        $customer = $this->customerOr404($request->param('uuid'));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $customerId = (int) $customer['id'];

        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:150',
            'company_name'  => 'string|max:180',
            'contact_email' => 'email|max:190',
            'contact_phone' => 'phone',
            'address'       => 'string|max:255',
            'notes'         => 'string|max:500',
            'timezone'      => 'timezone',
            'date_system'   => 'in:jalali,gregorian',
            'status'        => 'in:active,suspended,archived',
            'max_employees' => 'integer|max:100000',
            'max_devices'   => 'integer|max:10000',
        ], [
            'name'          => 'نام کسب‌وکار',
            'company_name'  => 'نام شرکت',
            'contact_email' => 'ایمیل تماس',
            'contact_phone' => 'تلفن تماس',
            'timezone'      => 'منطقه زمانی',
            'status'        => 'وضعیت مشتری',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/owner/customers/' . (string) $customer['uuid'] . '/edit', $validator->errors(), $request->all());
        }

        $data   = $validator->validated();
        $before = [
            'name'         => (string) $customer['name'],
            'company_name' => (string) ($customer['company_name'] ?? ''),
            'contact_email'=> (string) ($customer['contact_email'] ?? ''),
            'contact_phone'=> (string) ($customer['contact_phone'] ?? ''),
            'address'      => (string) ($customer['address'] ?? ''),
            'status'       => (string) $customer['status'],
            'timezone'     => (string) $customer['timezone'],
        ];

        Customer::updateById($customerId, [
            'name'          => (string) $data['name'],
            'company_name'  => $data['company_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'address'       => $data['address'] ?? null,
            'timezone'      => $data['timezone'] ?? 'Asia/Tehran',
            'date_system'   => $data['date_system'] ?? 'jalali',
            'status'        => (string) ($data['status'] ?? $customer['status']),
            'max_employees' => (int) ($data['max_employees'] ?? $customer['max_employees']),
            'max_devices'   => (int) ($data['max_devices'] ?? $customer['max_devices']),
        ]);

        if (!empty($data['notes'])) {
            \App\Models\Setting::put('customer.internal_note', (string) $data['notes'], 'customer', $customerId, 0, 0, (int) Session::userId());
        }

        AuditLog::record('customer.update', 'success', 'اطلاعات مشتری «' . (string) $data['name'] . '» ویرایش شد.', [
            'customer_id' => $customerId,
            'before'      => $before,
            'after'       => [
                'name'          => (string) $data['name'],
                'company_name'  => (string) ($data['company_name'] ?? ''),
                'contact_email' => (string) ($data['contact_email'] ?? ''),
                'contact_phone' => (string) ($data['contact_phone'] ?? ''),
                'status'        => (string) ($data['status'] ?? (string) $customer['status']),
            ],
        ], 'app', 'customer', $customerId, $customerId);

        Session::flash('success', 'تغییرات مشتری ذخیره شد.');

        return Redirect::response('/owner/customers/' . (string) $customer['uuid']);
    }

    /* =================================================================
     * Enable / disable
     * ===============================================================*/

    public function status(Request $request): Response
    {
        $customer = $this->customerOr404($request->param('uuid'));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $customerId = (int) $customer['id'];
        $action     = (string) $request->input('status', '');

        if (!in_array($action, ['active', 'suspended'], true)) {
            Session::flash('error', 'وضعیت درخواستی معتبر نیست.');

            return Redirect::response('/owner/customers/' . (string) $customer['uuid']);
        }

        Customer::setStatus($customerId, $action);

        $enabled = $action === 'active';

        AuditLog::record(
            $enabled ? 'customer.enable' : 'customer.disable',
            'success',
            $enabled
                ? 'مشتری «' . (string) $customer['name'] . '» فعال شد.'
                : 'مشتری «' . (string) $customer['name'] . '» غیرفعال (معلق) شد.',
            ['customer_id' => $customerId, 'status' => $action],
            'app',
            'customer',
            $customerId,
            $customerId
        );

        // Disabling a customer immediately kills the sessions of its users
        // and its devices so nobody keeps working against a suspended tenant.
        if (!$enabled) {
            $revokedUsers   = $this->revokeUsersFor($customerId);
            $revokedDevices = Device::setStatusForCustomer($customerId, Device::STATUS_DISABLED);

            if ($revokedUsers > 0 || $revokedDevices > 0) {
                AuditService::log('customer.sessions_revoked', 'success', 'نشست‌های کاربران و دستگاه‌های مشتری معلق شد.', [
                    'customer_id' => $customerId,
                    'users'       => $revokedUsers,
                    'devices'     => $revokedDevices,
                ], 'security', 'customer', $customerId, $customerId);
            }
        } else {
            // Re-enabling restores exactly the accounts this suspension turned
            // off. Devices are put back to "pending": their tokens stay dead,
            // so a re-enabled customer has to activate (or reset) them again -
            // the same rule as a manual device reset.
            $restored = $this->restoreUsersFor($customerId);
            $pending  = Device::setStatusForCustomer($customerId, Device::STATUS_PENDING, true);

            if ($restored > 0 || $pending > 0) {
                AuditService::log('customer.sessions_restored', 'success', 'حساب‌های کاربران مشتری دوباره فعال شد و دستگاه‌ها به حالت فعال‌سازی برگشتند.', [
                    'customer_id' => $customerId,
                    'users'       => $restored,
                    'devices'     => $pending,
                ], 'security', 'customer', $customerId, $customerId);
            }
        }

        Session::flash('success', $enabled ? 'مشتری فعال شد.' : 'مشتری غیرفعال شد.');

        return Redirect::response('/owner/customers/' . (string) $customer['uuid']);
    }

    /* =================================================================
     * Logo upload
     * ===============================================================*/

    public function logo(Request $request): Response
    {
        $customer = $this->customerOr404($request->param('uuid'));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $customerId = (int) $customer['id'];

        if (!$request->hasFile('logo')) {
            Session::flash('error', 'فایلی برای بارگذاری انتخاب نشده است.');

            return Redirect::response('/owner/customers/' . (string) $customer['uuid'] . '/edit');
        }

        $file   = $request->file('logo');
        $stored = UploadService::storeLogo($file, (string) $customer['uuid']);

        if (!$stored['ok']) {
            Session::flash('error', $stored['message']);

            return Redirect::response('/owner/customers/' . (string) $customer['uuid'] . '/edit');
        }

        $previous = (string) ($customer['logo_path'] ?? '');

        Customer::updateById($customerId, ['logo_path' => $stored['relative']]);

        if ($previous !== '' && $previous !== $stored['relative']) {
            UploadService::delete($previous);
        }

        AuditLog::record('customer.logo', 'success', 'لوگوی مشتری به‌روزرسانی شد.', [
            'customer_id' => $customerId,
            'path'        => $stored['relative'],
        ], 'app', 'customer', $customerId, $customerId);

        Session::flash('success', 'لوگو با موفقیت ذخیره شد.');

        return Redirect::response('/owner/customers/' . (string) $customer['uuid'] . '/edit');
    }

    /* =================================================================
     * Helpers
     * ===============================================================*/

    /**
     * @return array<string,mixed>|null
     */
    private function customerOr404(string $uuid): ?array
    {
        $uuid = trim($uuid);

        // Also accept a numeric id for convenience links from other pages.
        if (preg_match('/^\d+$/', $uuid) === 1) {
            return Customer::find((int) $uuid);
        }

        return Customer::findByUuid($uuid);
    }

    /**
     * @param array<string,array<int,string>> $errors
     * @param array<string,mixed> $old
     */
    private function backWithErrors(string $path, array $errors, array $old): Response
    {
        Session::put('old_input', $old);
        Session::flash('errors', (string) json_encode($errors, JSON_UNESCAPED_UNICODE));
        Session::flash('error', $this->firstError($errors));

        return Redirect::response($path);
    }

    /**
     * @param array<string,array<int,string>> $errors
     */
    private function firstError(array $errors): string
    {
        foreach ($errors as $messages) {
            if (isset($messages[0])) {
                return (string) $messages[0];
            }
        }

        return 'اطلاعات ارسالی معتبر نیست.';
    }

    /**
     * @param mixed $value
     */
    private function dateOrNull($value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Create a manager (attendance_managers) + its login (attendance_users).
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool,message:string,name:string,username:string,manager_id:int,user_id:int}
     */
    private function createManagerFor(int $customerId, array $data, int $ownerId): array
    {
        $fullName = trim(((string) ($data['first_name'] ?? '')) . ' ' . ((string) ($data['last_name'] ?? '')));

        if ($fullName === '') {
            $fullName = (string) $data['username'];
        }

        $managerId = Manager::createManager([
            'customer_id' => $customerId,
            'name'        => $fullName,
            'mobile'      => $data['mobile'] ?? null,
            'email'       => $data['email'] ?? null,
            'is_primary'  => !empty($data['is_primary']) ? 1 : 0,
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
            'email'                => $data['email'] ?? null,
            'mobile'               => $data['mobile'] ?? null,
            'password'             => (string) $data['password'],
            'must_change_password' => 1,
            'is_active'            => 1,
            'created_by'           => $ownerId,
        ]);

        Manager::linkUser($managerId, $userId);

        AuditService::log('manager.create', 'success', 'مدیر «' . $fullName . '» برای مشتری ایجاد شد.', [
            'manager_id'  => $managerId,
            'user_id'     => $userId,
            'username'    => (string) $data['username'],
            'customer_id' => $customerId,
        ], 'app', 'manager', $managerId, $customerId);

        return [
            'ok'         => true,
            'message'    => 'مدیر ایجاد شد.',
            'name'       => $fullName,
            'username'   => (string) $data['username'],
            'manager_id' => $managerId,
            'user_id'    => $userId,
        ];
    }

    /**
     * Deactivate every user of a customer (suspending a tenant must not
     * leave live logins behind).
     */
    /**
     * Suspend every user of the customer.
     *
     * `disabled_by_customer` marks the accounts that were switched off by
     * THIS suspension, so re-enabling the customer restores exactly those and
     * never touches an account the owner disabled by hand.
     */
    /**
     * Re-enable exactly the accounts that were disabled by the suspension.
     */
    private function restoreUsersFor(int $customerId): int
    {
        $stmt = \App\Core\Database::run(
            'UPDATE `' . \App\Core\Database::table('users') . '`'
            . ' SET `is_active` = 1, `disabled_by_customer` = 0'
            . ' WHERE `customer_id` = ? AND `disabled_by_customer` = 1 AND `deleted_at` IS NULL',
            [$customerId]
        );

        return $stmt->rowCount();
    }

    private function revokeUsersFor(int $customerId): int
    {
        $stmt = \App\Core\Database::run(
            'UPDATE `' . \App\Core\Database::table('users') . '`'
            . ' SET `is_active` = 0, `disabled_by_customer` = 1'
            . ' WHERE `customer_id` = ? AND `deleted_at` IS NULL',
            [$customerId]
        );

        return $stmt->rowCount();
    }
}
