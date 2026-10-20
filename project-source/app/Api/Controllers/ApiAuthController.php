<?php
/**
 * SARMAD Attendance - API authentication (token issuing for users).
 */

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiResponse;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Validator;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LoginAttempt;
use App\Models\Role;
use App\Models\User;

final class ApiAuthController
{
    /**
     * POST /api/v1/auth/token
     * Body: { identifier, password, device_name? }
     */
    public function token(Request $request): Response
    {
        $validator = Validator::make($request->json() + $request->all(), [
            'identifier'  => 'required|string|max:190',
            'password'    => 'required|string|max:200',
            'device_name' => 'string|max:120',
        ], [
            'identifier' => 'نام کاربری یا ایمیل',
            'password'   => 'رمز عبور',
        ]);

        if ($validator->fails()) {
            return ApiResponse::validation($validator->errors());
        }

        $identifier = (string) $request->payload('identifier', '');
        $password   = (string) $request->payload('password', '');
        $ip         = Request::ip();

        $maxAttempts = (int) Config::get('security.max_login_attempts', 5);

        if (LoginAttempt::isLocked($identifier, $ip, $maxAttempts, (int) Config::get('security.attempt_window_min', 30))) {
            return ApiResponse::error('rate_limited', 'تلاش‌های ناموفق زیاد است. بعداً تلاش کنید.', [], 429);
        }

        $user = User::findByLogin($identifier);

        if ($user === null) {
            Security::dummyVerify($password);
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), null, null, 'api_user_not_found');

            return ApiResponse::unauthorized('نام کاربری یا رمز عبور نادرست است.');
        }

        if ((int) $user['is_active'] !== 1 || !empty($user['deleted_at']) || User::isLocked($user)) {
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), (int) $user['id'], null, 'api_inactive_or_locked');

            return ApiResponse::unauthorized('حساب کاربری فعال یا قابل استفاده نیست.');
        }

        if (!Security::verifyPassword($password, (string) $user['password_hash'])) {
            User::recordFailedAttempt(
                (int) $user['id'],
                $maxAttempts,
                (int) Config::get('security.lockout_minutes', 15)
            );
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), (int) $user['id'], null, 'api_wrong_password');

            return ApiResponse::unauthorized('نام کاربری یا رمز عبور نادرست است.');
        }

        $customerId = $user['customer_id'] !== null ? (int) $user['customer_id'] : null;

        if ((string) $user['role'] !== Role::OWNER && ($customerId === null || !Customer::isActive($customerId))) {
            return ApiResponse::forbidden('حساب مشتری فعال نیست.');
        }

        if ((int) ($user['api_enabled'] ?? 0) !== 1 && (string) $user['role'] !== Role::OWNER) {
            return ApiResponse::forbidden('دسترسی API برای این حساب فعال نشده است.');
        }

        $tokenName = (string) $request->payload('device_name', 'api-client');

        $issued = ApiToken::issue(
            (int) $user['id'],
            $customerId,
            $tokenName,
            Role::capabilitiesOf((string) $user['role']),
            90,
            (int) $user['id']
        );

        User::touchLogin((int) $user['id'], $ip);
        LoginAttempt::log($identifier, true, $ip, Request::userAgent(), (int) $user['id'], $customerId, 'api_success');

        AuditLog::record('api.token.issued', 'success', 'توکن API صادر شد.', [
            'user_id'  => (int) $user['id'],
            'token_id' => $issued['id'],
        ], 'api');

        return ApiResponse::success([
            'token'       => $issued['token'],
            'token_type'  => 'Bearer',
            'expires_in'  => 90 * 86400,
            'user'        => [
                'id'   => (int) $user['id'],
                'name' => (string) $user['name'],
                'role' => (string) $user['role'],
            ],
            'customer_id' => $customerId,
        ], ['notice' => 'این توکن فقط یک بار نمایش داده می‌شود.']);
    }

    /**
     * GET /api/v1/me
     */
    public function me(Request $request): Response
    {
        $user = \App\Core\Session::user();

        if ($user === null) {
            return ApiResponse::unauthorized();
        }

        return ApiResponse::success([
            'user' => [
                'id'          => (int) $user['id'],
                'uuid'        => (string) $user['uuid'],
                'name'        => (string) $user['name'],
                'username'    => (string) $user['username'],
                'email'       => $user['email'],
                'role'        => (string) $user['role'],
                'customer_id' => $user['customer_id'],
            ],
            'capabilities' => Role::capabilitiesOf((string) $user['role']),
            'customer'     => $user['customer_id'] === null ? null : self::customerSummary((int) $user['customer_id']),
        ]);
    }

    /**
     * POST /api/v1/auth/logout - revokes the presented token.
     */
    public function logout(Request $request): Response
    {
        $token = $request->bearerToken();
        $row   = ApiToken::findActiveByToken($token);

        if ($row !== null) {
            ApiToken::revoke((int) $row['id']);
        }

        AuditLog::record('api.token.revoked', 'success', 'توکن API باطل شد.', [], 'api');

        return ApiResponse::success(['revoked' => true]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function customerSummary(int $customerId): ?array
    {
        $customer = Customer::find($customerId);

        if ($customer === null) {
            return null;
        }

        return [
            'id'     => (int) $customer['id'],
            'uuid'   => (string) $customer['uuid'],
            'name'   => (string) $customer['name'],
            'status' => (string) $customer['status'],
        ];
    }
}
