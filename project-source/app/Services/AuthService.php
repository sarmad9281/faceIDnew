<?php
/**
 * SARMAD Attendance - authentication service.
 *
 * Responsibilities:
 *   - credential verification with constant time behaviour
 *   - brute force throttling (per identifier + IP, DB backed)
 *   - session payload creation (never contains the password hash)
 *   - audit trail for every attempt
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Security;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LoginAttempt;
use App\Models\Role;
use App\Models\User;

final class AuthService
{
    public const RESULT_OK                = 'ok';
    public const RESULT_INVALID           = 'invalid_credentials';
    public const RESULT_LOCKED            = 'locked';
    public const RESULT_INACTIVE          = 'inactive';
    public const RESULT_CUSTOMER_INACTIVE = 'customer_inactive';
    public const RESULT_ROLE_INVALID      = 'role_invalid';

    /* =================================================================
     * Login
     * ===============================================================*/

    /**
     * @return array{result:string, message:string, user:array<string,mixed>|null, redirect:string}
     */
    public function attempt(string $identifier, string $password, bool $rememberTarget = false, string $intended = ''): array
    {
        $identifier = trim($identifier);
        $ip         = Request::ip();

        if ($identifier === '' || $password === '') {
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), null, null, 'empty_credentials');

            return $this->fail(self::RESULT_INVALID, 'نام کاربری یا رمز عبور نادرست است.');
        }

        // 1) Throttling before touching the database user row.
        $maxAttempts   = (int) Config::get('security.max_login_attempts', 5);
        $attemptWindow = (int) Config::get('security.attempt_window_min', 30);

        if (LoginAttempt::isLocked($identifier, $ip, $maxAttempts, $attemptWindow)) {
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), null, null, 'throttled');
            AuditLog::auth('auth.login.blocked', 'denied', 'ورود به دلیل تلاش‌های ناموفق زیاد مسدود شد.', [
                'identifier' => $identifier,
            ]);
            Logger::channel('security')->warning('Login throttled', ['identifier' => $identifier]);

            return $this->fail(
                self::RESULT_LOCKED,
                'به دلیل تلاش‌های ناموفق زیاد، ورود شما موقتاً مسدود شده است. لطفاً بعداً تلاش کنید.'
            );
        }

        // 2) Resolve the account.
        $user = User::findByLogin($identifier);

        if ($user === null) {
            // Same work as a real verify => no user enumeration through timing.
            Security::dummyVerify($password);
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), null, null, 'user_not_found');
            AuditLog::auth('auth.login.failed', 'failure', 'تلاش ورود با شناسه ناموجود.', [
                'identifier' => $identifier,
            ]);

            return $this->fail(self::RESULT_INVALID, 'نام کاربری یا رمز عبور نادرست است.');
        }

        // 3) Locked account?
        if (User::isLocked($user)) {
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), (int) $user['id'], (int) ($user['customer_id'] ?? 0), 'account_locked');
            AuditLog::auth('auth.login.blocked', 'denied', 'حساب کاربری قفل است.', [
                'user_id' => (int) $user['id'],
            ], );

            return $this->fail(self::RESULT_LOCKED, 'حساب کاربری شما موقتاً قفل شده است. لطفاً بعداً تلاش کنید.');
        }

        // 4) Password.
        if (!Security::verifyPassword($password, (string) $user['password_hash'])) {
            $userRowId = (int) $user['id'];

            User::recordFailedAttempt(
                $userRowId,
                (int) Config::get('security.max_login_attempts', 5),
                (int) Config::get('security.lockout_minutes', 15)
            );

            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), $userRowId, (int) ($user['customer_id'] ?? 0), 'wrong_password');
            AuditLog::auth('auth.login.failed', 'failure', 'رمز عبور نادرست بود.', [
                'user_id' => $userRowId,
            ]);

            return $this->fail(self::RESULT_INVALID, 'نام کاربری یا رمز عبور نادرست است.');
        }

        // 5) Account state.
        if ((int) $user['is_active'] !== 1 || !empty($user['deleted_at'])) {
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), (int) $user['id'], (int) ($user['customer_id'] ?? 0), 'inactive');
            AuditLog::auth('auth.login.blocked', 'denied', 'حساب کاربری غیرفعال است.', [
                'user_id' => (int) $user['id'],
            ]);

            return $this->fail(self::RESULT_INACTIVE, 'حساب کاربری شما غیرفعال است. با مدیر سامانه تماس بگیرید.');
        }

        // 6) Role must exist and the cached slug must match.
        $role = Role::byName((string) $user['role']);

        if ($role === null || (int) $role['id'] !== (int) $user['role_id']) {
            LoginAttempt::log($identifier, false, $ip, Request::userAgent(), (int) $user['id'], (int) ($user['customer_id'] ?? 0), 'role_mismatch');
            AuditLog::auth('auth.login.blocked', 'error', 'نقش کاربری نامعتبر است.', [
                'user_id' => (int) $user['id'],
                'role'    => (string) $user['role'],
            ]);

            return $this->fail(self::RESULT_ROLE_INVALID, 'نقش کاربری این حساب معتبر نیست. با مدیر سامانه تماس بگیرید.');
        }

        // 7) Customer must exist and be active (managers / supervisors only).
        $customerId = isset($user['customer_id']) && $user['customer_id'] !== null ? (int) $user['customer_id'] : null;

        if ((string) $user['role'] !== Role::OWNER) {
            if ($customerId === null || $customerId <= 0 || !Customer::isActive($customerId)) {
                LoginAttempt::log($identifier, false, $ip, Request::userAgent(), (int) $user['id'], $customerId, 'customer_inactive');
                AuditLog::auth('auth.login.blocked', 'denied', 'مشتری مرتبط فعال نیست.', [
                    'user_id' => (int) $user['id'],
                ]);

                return $this->fail(self::RESULT_CUSTOMER_INACTIVE, 'حساب مشتری مرتبط با شما فعال نیست.');
            }
        }

        // 8) Success: session hardening + bookkeeping.
        Session::regenerateForLogin();
        Session::loginUser(User::sessionPayload($user));

        User::touchLogin((int) $user['id'], $ip);
        LoginAttempt::log($identifier, true, $ip, Request::userAgent(), (int) $user['id'], $customerId, 'success');
        AuditLog::auth('auth.login.success', 'success', 'ورود موفق به سامانه.', [
            'user_id' => (int) $user['id'],
            'role'    => (string) $user['role'],
        ], $customerId > 0 ? $customerId : null);

        $redirect = $this->defaultRedirectFor((string) $user['role']);

        if ($intended !== '') {
            $redirect = \App\Support\Url::safeRedirect($intended, $redirect);
        }

        return [
            'result'   => self::RESULT_OK,
            'message'  => 'خوش آمدید ' . (string) $user['name'],
            'user'     => User::sessionPayload($user),
            'redirect' => $redirect,
        ];
    }

    /**
     * Password change (also used for the forced first-login change).
     *
     * @return array{ok:bool, message:string}
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $repeat): array
    {
        $user = User::find($userId);

        if ($user === null) {
            return ['ok' => false, 'message' => 'کاربر یافت نشد.'];
        }

        if (!Security::verifyPassword($currentPassword, (string) $user['password_hash'])) {
            AuditLog::auth('auth.password.change', 'failure', 'رمز عبور فعلی نادرست بود.', [
                'user_id' => $userId,
            ]);

            return ['ok' => false, 'message' => 'رمز عبور فعلی نادرست است.'];
        }

        $problems = Security::passwordProblems($newPassword, $repeat);

        if ($problems !== []) {
            return ['ok' => false, 'message' => implode(' ', $problems)];
        }

        if ($currentPassword === $newPassword) {
            return ['ok' => false, 'message' => 'رمز عبور جدید باید با رمز فعلی متفاوت باشد.'];
        }

        User::setPassword($userId, $newPassword, false);

        $sessionUser = Session::user();

        if ($sessionUser !== null && (int) $sessionUser['id'] === $userId) {
            $sessionUser['must_change_password'] = 0;
            Session::loginUser($sessionUser);
        }

        AuditLog::auth('auth.password.change', 'success', 'رمز عبور با موفقیت تغییر کرد.', [
            'user_id' => $userId,
        ]);

        return ['ok' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.'];
    }

    public function logout(): void
    {
        $label = Session::actorLabel();
        $userId = Session::userId();

        AuditLog::auth('auth.logout', 'success', 'خروج از حساب کاربری.', [
            'user_id' => $userId,
        ], Session::customerId());

        Logger::channel('auth')->info('Logout', ['actor' => $label]);

        Session::logout();
    }

    /* =================================================================
     * Helpers
     * ===============================================================*/

    public static function defaultRedirectFor(string $role): string
    {
        switch (strtolower($role)) {
            case Role::OWNER:
                return \App\Support\Url::to('/owner');
            case Role::MANAGER:
            case Role::SUPERVISOR:
            default:
                return \App\Support\Url::to('/dashboard');
            case Role::DEVICE:
                return \App\Support\Url::to('/device');
        }
    }

    /**
     * @return array{result:string, message:string, user:null, redirect:string}
     */
    private function fail(string $result, string $message): array
    {
        return [
            'result'   => $result,
            'message'  => $message,
            'user'     => null,
            'redirect' => '',
        ];
    }
}
