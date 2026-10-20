<?php
/**
 * SARMAD Attendance - owner API (stage 2).
 *
 * Endpoints (all under /api/v1/owner, all owner-only, all audited):
 *
 *   GET    /api/v1/owner/overview
 *   GET    /api/v1/owner/customers
 *   POST   /api/v1/owner/customers
 *   GET    /api/v1/owner/customers/{uuid}
 *   PATCH  /api/v1/owner/customers/{uuid}
 *   POST   /api/v1/owner/customers/{uuid}/status
 *   GET    /api/v1/owner/customers/{uuid}/features
 *   PATCH  /api/v1/owner/customers/{uuid}/features
 *   GET    /api/v1/owner/customers/{uuid}/subscription
 *   PATCH  /api/v1/owner/customers/{uuid}/subscription
 *   GET    /api/v1/owner/managers        POST /api/v1/owner/managers
 *   PATCH  /api/v1/owner/managers/{id}   POST /api/v1/owner/managers/{id}/password
 *   GET    /api/v1/owner/devices
 *   POST   /api/v1/owner/devices/{id}/reset
 *   POST   /api/v1/owner/devices/{id}/status
 *   GET    /api/v1/owner/logs
 *
 * The API reuses exactly the same models and audit vocabulary as the web
 * panel, so an action performed through the API is indistinguishable in the
 * audit trail from the same action performed in the browser.
 */

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiResponse;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Manager;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FeatureService;
use App\Services\OwnerDashboardService;
use App\Services\SubscriptionService;

final class OwnerApiController
{
    /* =================================================================
     * Overview
     * ===============================================================*/

    public function overview(Request $request): Response
    {
        $service = new OwnerDashboardService();
        $data    = $service->overview((int) \App\Models\Setting::get('device.offline_after', 300));

        return ApiResponse::success([
            'counters'     => $data['counters'],
            'subscription' => $data['subscription'],
            'totals'       => $data['totals'],
            'environment'  => $data['environment'],
        ]);
    }

    /* =================================================================
     * Customers
     * ===============================================================*/

    public function customers(Request $request): Response
    {
        $filters = [
            'q'            => $request->string('q'),
            'status'       => $request->string('status'),
            'subscription' => $request->string('subscription'),
            'plan'         => $request->string('plan'),
            'sort'         => $request->string('sort', 'id DESC'),
        ];

        $listing         = Customer::paginateForOwner($filters, max(1, $request->int('page', 1)), min(100, max(5, $request->int('per_page', 20))));
        $listing['rows'] = Customer::withCounters($listing['rows']);

        return ApiResponse::success($listing['rows'], [
            'page'     => $listing['page'],
            'per_page' => $listing['per_page'],
            'total'    => $listing['total'],
            'pages'    => $listing['pages'],
        ]);
    }

    public function createCustomer(Request $request): Response
    {
        $payload = $request->json() + $request->all();

        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 150) {
            return ApiResponse::validation(['name' => ['نام مشتری الزامی است (حداکثر ۱۵۰ کاراکتر).']]);
        }

        $email = trim((string) ($payload['contact_email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ApiResponse::validation(['contact_email' => ['ایمیل معتبر نیست.']]);
        }

        $ownerId = (int) (\App\Core\Session::userId() ?? 0);

        $customerId = Customer::createCustomer([
            'name'          => $name,
            'company_name'  => $payload['company_name'] ?? null,
            'contact_email' => $email !== '' ? $email : null,
            'contact_phone' => $payload['contact_phone'] ?? null,
            'address'       => $payload['address'] ?? null,
            'timezone'      => (string) ($payload['timezone'] ?? 'Asia/Tehran'),
            'date_system'   => (string) ($payload['date_system'] ?? 'jalali'),
            'status'        => 'active',
            'created_by'    => $ownerId,
            'plan_name'                => (string) ($payload['plan_name'] ?? 'trial'),
            'subscription_plan'        => (string) ($payload['plan_name'] ?? 'trial'),
            'subscription_status'      => (string) ($payload['subscription_status'] ?? 'trial'),
            'subscription_start_date'  => date('Y-m-d'),
            'subscription_expire_date' => date('Y-m-d', time() + (14 * 86400)),
        ]);

        $customer = Customer::findOrFail($customerId);

        FeatureService::seedDefaults($customerId, $ownerId);

        Subscription::record([
            'customer_id' => $customerId,
            'plan_name'   => (string) ($payload['plan_name'] ?? 'trial'),
            'status'      => (string) ($payload['subscription_status'] ?? 'trial'),
            'start_date'  => date('Y-m-d'),
            'expire_date' => date('Y-m-d', time() + (14 * 86400)),
            'created_by'  => $ownerId,
            'notes'       => 'ایجاد از طریق API',
        ]);

        AuditLog::record('customer.create', 'success', 'مشتری از طریق API ایجاد شد: ' . $name, [
            'customer_id' => $customerId,
            'channel'     => 'api',
        ], 'app', 'customer', $customerId, $customerId);

        return ApiResponse::success([
            'id'   => $customerId,
            'uuid' => (string) $customer['uuid'],
            'name' => (string) $customer['name'],
        ], [], 201);
    }

    /**
     * `{id}` of the route, read from the request route parameters.
     * Router::invoke() passes only the request, so a route parameter is never
     * a method argument; the body/query id stays as a fallback for callers
     * that already send it.
     */
    private function routeId(Request $request, string $key = 'id'): int
    {
        $fromRoute = (string) $request->param($key, '');

        return $fromRoute !== '' ? (int) $fromRoute : (int) $request->int($key, 0);
    }

    public function customer(Request $request): Response
    {
        $customer = $this->resolveCustomer($request->param('uuid'));

        if ($customer === null) {
            return ApiResponse::notFound('مشتری یافت نشد.');
        }

        $customerId = (int) $customer['id'];

        return ApiResponse::success([
            'customer'     => $customer,
            'counters'     => (Customer::withCounters([$customer]))[0],
            'features'     => FeatureService::stateFor($customerId),
            'subscription' => Subscription::currentFor($customerId),
            'managers'     => Manager::forCustomer($customerId),
        ]);
    }

    public function updateCustomer(Request $request): Response
    {
        $customer = $this->resolveCustomer($request->param('uuid'));

        if ($customer === null) {
            return ApiResponse::notFound('مشتری یافت نشد.');
        }

        $payload = $request->json() + $request->all();
        $allowed = ['name', 'company_name', 'contact_email', 'contact_phone', 'address', 'timezone', 'date_system'];

        $data = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $payload)) {
                $data[$field] = $payload[$field] === '' ? null : $payload[$field];
            }
        }

        if (isset($data['name']) && trim((string) $data['name']) === '') {
            return ApiResponse::validation(['name' => ['نام مشتری نمی‌تواند خالی باشد.']]);
        }

        if (isset($data['contact_email']) && $data['contact_email'] !== null
            && filter_var((string) $data['contact_email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            return ApiResponse::validation(['contact_email' => ['ایمیل معتبر نیست.']]);
        }

        if ($data === []) {
            return ApiResponse::validation(['body' => ['هیچ فیلد قابل تغییری ارسال نشده است.']]);
        }

        Customer::updateById((int) $customer['id'], $data);

        AuditLog::record('customer.update', 'success', 'مشتری از طریق API ویرایش شد.', [
            'customer_id' => (int) $customer['id'],
            'fields'      => array_keys($data),
        ], 'app', 'customer', (int) $customer['id'], (int) $customer['id']);

        return ApiResponse::success(['updated' => array_keys($data)]);
    }

    public function customerStatus(Request $request): Response
    {
        $customer = $this->resolveCustomer($request->param('uuid'));

        if ($customer === null) {
            return ApiResponse::notFound('مشتری یافت نشد.');
        }

        $status = (string) ($request->json()['status'] ?? $request->input('status', ''));

        if (!in_array($status, ['active', 'suspended'], true)) {
            return ApiResponse::validation(['status' => ['وضعیت باید active یا suspended باشد.']]);
        }

        $customerId = (int) $customer['id'];

        Customer::setStatus($customerId, $status);

        $enabled = $status === 'active';

        AuditLog::record($enabled ? 'customer.enable' : 'customer.disable', 'success',
            $enabled ? 'مشتری از طریق API فعال شد.' : 'مشتری از طریق API غیرفعال شد.',
            ['customer_id' => $customerId],
            'app',
            'customer',
            $customerId,
            $customerId
        );

        if (!$enabled) {
            Database::run(
                'UPDATE `' . Database::table('users') . '` SET `is_active` = 0 WHERE `customer_id` = ? AND `deleted_at` IS NULL',
                [$customerId]
            );

            Device::setStatusForCustomer($customerId, Device::STATUS_DISABLED);
        }

        return ApiResponse::success(['status' => $status]);
    }

    /* =================================================================
     * Features
     * ===============================================================*/

    public function features(Request $request): Response
    {
        $customer = $this->resolveCustomer($request->param('uuid'));

        if ($customer === null) {
            return ApiResponse::notFound('مشتری یافت نشد.');
        }

        return ApiResponse::success(FeatureService::stateFor((int) $customer['id']));
    }

    public function updateFeatures(Request $request): Response
    {
        $customer = $this->resolveCustomer($request->param('uuid'));

        if ($customer === null) {
            return ApiResponse::notFound('مشتری یافت نشد.');
        }

        $payload = $request->json() + $request->all();

        // Accept either {"features": {"branches": true}} or {"branches": true}.
        $map = $payload['features'] ?? $payload;

        if (!is_array($map) || $map === []) {
            return ApiResponse::validation(['features' => ['فهرست امکانات ارسال نشده است.']]);
        }

        $customerId = (int) $customer['id'];
        $actorId    = (int) (\App\Core\Session::userId() ?? 0);
        $changed    = [];
        $unknown    = [];

        foreach ($map as $key => $value) {
            if (!\App\Models\Feature::existsByKey((string) $key)) {
                $unknown[] = (string) $key;
                continue;
            }

            $enabled = in_array($value, [true, 1, '1', 'true', 'on'], true);
            $result  = FeatureService::set($customerId, (string) $key, $enabled, $actorId);

            if ($result['changed']) {
                $changed[(string) $key] = $enabled;
            }
        }

        if ($unknown !== []) {
            return ApiResponse::error('unknown_feature', 'امکان ناشناخته: ' . implode(', ', $unknown), [], 422);
        }

        if ($changed !== []) {
            AuditLog::record('feature.update', 'success', 'امکانات مشتری از طریق API تغییر کرد.', [
                'customer_id' => $customerId,
                'changed'     => $changed,
            ], 'app', 'customer', $customerId, $customerId);
        }

        return ApiResponse::success([
            'changed' => $changed,
            'state'   => FeatureService::stateFor($customerId),
        ]);
    }

    /* =================================================================
     * Subscription
     * ===============================================================*/

    public function subscription(Request $request): Response
    {
        $customer = $this->resolveCustomer($request->param('uuid'));

        if ($customer === null) {
            return ApiResponse::notFound('مشتری یافت نشد.');
        }

        $customerId = (int) $customer['id'];
        $current    = Subscription::currentFor($customerId);

        return ApiResponse::success([
            'current'        => $current,
            'status'         => Subscription::effectiveStatus($current),
            'days_remaining' => Subscription::daysRemaining($current),
            'history'        => Subscription::historyFor($customerId, 10),
        ]);
    }

    public function updateSubscription(Request $request): Response
    {
        $customer = $this->resolveCustomer($request->param('uuid'));

        if ($customer === null) {
            return ApiResponse::notFound('مشتری یافت نشد.');
        }

        $payload = $request->json() + $request->all();

        try {
            $result = (new SubscriptionService())->update((int) $customer['id'], [
                'plan_name'     => (string) ($payload['plan_name'] ?? 'trial'),
                'status'        => (string) ($payload['status'] ?? 'trial'),
                'start_date'    => (string) ($payload['start_date'] ?? ''),
                'expire_date'   => (string) ($payload['expire_date'] ?? ''),
                'price'         => (string) ($payload['price'] ?? ''),
                'max_employees' => (int) ($payload['max_employees'] ?? 50),
                'max_devices'   => (int) ($payload['max_devices'] ?? 5),
                'notes'         => (string) ($payload['notes'] ?? ''),
            ], (int) (\App\Core\Session::userId() ?? 0));
        } catch (\RuntimeException $e) {
            return ApiResponse::validation(['subscription' => [$e->getMessage()]]);
        }

        return ApiResponse::success([
            'subscription_id' => $result['subscription_id'],
            'changed'         => $result['changed'],
        ]);
    }

    /* =================================================================
     * Managers
     * ===============================================================*/

    public function managers(Request $request): Response
    {
        $filters = [
            'q'           => $request->string('q'),
            'status'      => $request->string('status'),
            'customer_id' => $request->int('customer_id', 0),
        ];

        $listing = Manager::paginateForOwner($filters, max(1, $request->int('page', 1)), min(100, max(5, $request->int('per_page', 20))));

        // Never expose a password hash through the API.
        foreach ($listing['rows'] as $index => $row) {
            unset($listing['rows'][$index]['password_hash']);
        }

        return ApiResponse::success($listing['rows'], [
            'page'     => $listing['page'],
            'per_page' => $listing['per_page'],
            'total'    => $listing['total'],
            'pages'    => $listing['pages'],
        ]);
    }

    public function createManager(Request $request): Response
    {
        $payload    = $request->json() + $request->all();
        $customerId = (int) ($payload['customer_id'] ?? 0);

        $customer = Customer::find($customerId);

        if ($customer === null) {
            return ApiResponse::validation(['customer_id' => ['مشتری معتبر نیست.']]);
        }

        $username = strtolower(trim((string) ($payload['username'] ?? '')));
        $email    = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        $errors = [];

        if (preg_match('/^[a-z0-9_.\-]{3,60}$/', $username) !== 1) {
            $errors['username'] = ['نام کاربری باید ۳ تا ۶۰ کاراکتر و شامل حروف کوچک، رقم، نقطه، خط تیره یا زیرخط باشد.'];
        } elseif (User::usernameTaken($username)) {
            $errors['username'] = ['این نام کاربری قبلاً استفاده شده است.'];
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = ['ایمیل معتبر نیست.'];
        } elseif (User::emailTaken($email)) {
            $errors['email'] = ['این ایمیل قبلاً ثبت شده است.'];
        }

        $problems = Security::passwordProblems($password);

        if ($problems !== []) {
            $errors['password'] = $problems;
        }

        if ($errors !== []) {
            return ApiResponse::validation($errors);
        }

        $name = trim((string) ($payload['name'] ?? $username));

        $managerId = Manager::createManager([
            'customer_id' => $customerId,
            'name'        => $name,
            'mobile'      => $payload['mobile'] ?? null,
            'email'       => $email,
            'is_primary'  => !empty($payload['is_primary']) ? 1 : 0,
            'is_active'   => 1,
            'created_by'  => (int) (\App\Core\Session::userId() ?? 0),
        ]);

        $userId = User::createUser([
            'role'                 => Role::MANAGER,
            'role_id'              => Role::idFor(Role::MANAGER),
            'customer_id'          => $customerId,
            'manager_id'           => $managerId,
            'name'                 => $name,
            'username'             => $username,
            'email'                => $email,
            'mobile'               => $payload['mobile'] ?? null,
            'password'             => $password,
            'must_change_password' => 1,
            'is_active'            => 1,
            'created_by'           => (int) (\App\Core\Session::userId() ?? 0),
        ]);

        Manager::linkUser($managerId, $userId);

        AuditService::log('manager.create', 'success', 'مدیر از طریق API ایجاد شد.', [
            'manager_id'  => $managerId,
            'user_id'     => $userId,
            'customer_id' => $customerId,
            'username'    => $username,
        ], 'app', 'manager', $managerId, $customerId);

        return ApiResponse::success([
            'manager_id' => $managerId,
            'user_id'    => $userId,
            'username'   => $username,
        ], [], 201);
    }

    public function updateManager(Request $request): Response
    {
        $manager = Manager::find($this->routeId($request));

        if ($manager === null) {
            return ApiResponse::notFound('مدیر یافت نشد.');
        }

        $payload = $request->json() + $request->all();

        $data = [];

        foreach (['name', 'mobile', 'email', 'title'] as $field) {
            if (array_key_exists($field, $payload)) {
                $data[$field] = $payload[$field];
            }
        }

        if ($data === []) {
            return ApiResponse::validation(['body' => ['هیچ فیلد قابل تغییری ارسال نشده است.']]);
        }

        Manager::updateById((int) $manager['id'], $data);

        if ($manager['user_id'] !== null) {
            $userData = [];

            foreach (['name', 'mobile', 'email'] as $field) {
                if (isset($data[$field])) {
                    $userData[$field] = $data[$field];
                }
            }

            if ($userData !== []) {
                User::updateById((int) $manager['user_id'], $userData);
            }
        }

        AuditService::log('manager.update', 'success', 'مدیر از طریق API ویرایش شد.', [
            'manager_id' => (int) $manager['id'],
            'fields'     => array_keys($data),
        ], 'app', 'manager', (int) $manager['id'], (int) $manager['customer_id']);

        return ApiResponse::success(['updated' => array_keys($data)]);
    }

    public function managerPassword(Request $request): Response
    {
        $manager = Manager::find($this->routeId($request));

        if ($manager === null) {
            return ApiResponse::notFound('مدیر یافت نشد.');
        }

        if ($manager['user_id'] === null) {
            return ApiResponse::validation(['user_id' => ['این مدیر حساب کاربری ندارد.']]);
        }

        $payload  = $request->json() + $request->all();
        $provided = (string) ($payload['password'] ?? '');

        if ($provided === '') {
            $provided = Security::suggestPassword(14);
        } else {
            $problems = Security::passwordProblems($provided);

            if ($problems !== []) {
                return ApiResponse::validation(['password' => $problems]);
            }
        }

        User::setPassword((int) $manager['user_id'], $provided, true);

        Database::run(
            'UPDATE `' . Database::table('api_tokens') . '` SET `revoked_at` = NOW() WHERE `user_id` = ? AND `revoked_at` IS NULL',
            [(int) $manager['user_id']]
        );

        AuditService::log('manager.password.reset', 'success', 'رمز مدیر از طریق API بازنشانی شد.', [
            'manager_id' => (int) $manager['id'],
            'user_id'    => (int) $manager['user_id'],
        ], 'security', 'manager', (int) $manager['id'], (int) $manager['customer_id']);

        // The password is returned once only, and never logged.
        return ApiResponse::success([
            'manager_id' => (int) $manager['id'],
            'password'   => $provided,
            'note'       => 'این رمز فقط همین یک بار نمایش داده می‌شود و در اولین ورود باید تغییر کند.',
        ]);
    }

    /* =================================================================
     * Devices
     * ===============================================================*/

    public function devices(Request $request): Response
    {
        $filters = [
            'q'           => $request->string('q'),
            'status'      => $request->string('status'),
            'online'      => $request->string('online'),
            'customer_id' => $request->int('customer_id', 0),
        ];

        $offlineAfter = (int) \App\Models\Setting::get('device.offline_after', 300);

        $listing = Device::paginateForOwner($filters, max(1, $request->int('page', 1)), min(100, max(5, $request->int('per_page', 20))), $offlineAfter);

        foreach ($listing['rows'] as $index => $row) {
            // Never expose the token hash.
            unset($listing['rows'][$index]['api_token_hash']);
            $listing['rows'][$index]['online'] = Device::isOnline($row, $offlineAfter);
        }

        return ApiResponse::success($listing['rows'], [
            'page'     => $listing['page'],
            'per_page' => $listing['per_page'],
            'total'    => $listing['total'],
            'pages'    => $listing['pages'],
        ]);
    }

    public function deviceReset(Request $request): Response
    {
        $device = Device::findScoped($this->routeId($request));

        if ($device === null) {
            return ApiResponse::notFound('دستگاه یافت نشد.');
        }

        $result = Device::resetRegistration((int) $device['id'], 'owner_api_reset');

        AuditLog::record('device.reset', 'success', 'دستگاه از طریق API ریست شد.', [
            'device_id'    => (int) $device['id'],
            'customer_id'  => (int) $device['customer_id'],
            'pairing_code' => $result['pairing_code'],
        ], 'device', 'device', (int) $device['id'], (int) $device['customer_id']);

        return ApiResponse::success([
            'device_id'    => (int) $device['id'],
            'status'       => 'pending',
            'pairing_code' => $result['pairing_code'],
            'note'         => 'توکن قبلی باطل شد؛ دستگاه باید دوباره فعال‌سازی شود.',
        ]);
    }

    public function deviceStatus(Request $request): Response
    {
        $device = Device::findScoped($this->routeId($request));

        if ($device === null) {
            return ApiResponse::notFound('دستگاه یافت نشد.');
        }

        $status = (string) ($request->json()['status'] ?? $request->input('status', ''));

        if (!in_array($status, [Device::STATUS_ACTIVE, Device::STATUS_DISABLED], true)) {
            return ApiResponse::validation(['status' => ['وضعیت باید active یا disabled باشد.']]);
        }

        Device::setStatus((int) $device['id'], $status);

        AuditLog::record(
            $status === Device::STATUS_ACTIVE ? 'device.activate' : 'device.disable',
            'success',
            'وضعیت دستگاه از طریق API تغییر کرد.',
            ['device_id' => (int) $device['id'], 'status' => $status],
            'device',
            'device',
            (int) $device['id'],
            (int) $device['customer_id']
        );

        return ApiResponse::success(['device_id' => (int) $device['id'], 'status' => $status]);
    }

    /* =================================================================
     * Logs
     * ===============================================================*/

    public function logs(Request $request): Response
    {
        $filters = [
            'action'      => $request->string('action'),
            'actor'       => $request->string('actor'),
            'result'      => $request->string('result'),
            'customer_id' => $request->int('customer_id', 0),
            'from'        => $request->string('from'),
            'to'          => $request->string('to'),
            'q'           => $request->string('q'),
        ];

        $listing = AuditLog::paginate($filters, max(1, $request->int('page', 1)), min(100, max(5, $request->int('per_page', 30))));

        return ApiResponse::success($listing['rows'], [
            'page'     => $listing['page'],
            'per_page' => $listing['per_page'],
            'total'    => $listing['total'],
            'pages'    => $listing['pages'],
            'actions'  => AuditService::actions(),
        ]);
    }

    /* =================================================================
     * Helpers
     * ===============================================================*/

    /**
     * @return array<string,mixed>|null
     */
    private function resolveCustomer(string $key): ?array
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $key) === 1) {
            return Customer::find((int) $key);
        }

        return Customer::findByUuid($key);
    }
}
