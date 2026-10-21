<?php
/**
 * SARMAD Attendance - platform (owner) guard.
 *
 * Stricter than 'role:owner': the account must be a real, active owner
 * account in the database - not just a session that happens to carry the
 * owner role string. It also blocks a session that is still in emergency
 * access mode from touching owner-only mutations without leaving the mode
 * first, which keeps the audit trail unambiguous.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Role;
use App\Models\User;
use App\Services\ImpersonationService;

final class OwnerMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        $allowWhileImpersonating = in_array('allow-impersonation', $parameters, true);

        $user = Session::user();

        if ($user === null) {
            return Deny::access($request, 'ابتدا وارد حساب کاربری خود شوید.', 401);
        }

        if ((string) ($user['role'] ?? '') !== Role::OWNER) {
            return Deny::access($request, 'این بخش فقط برای مالک سامانه است.', 403, [
                'role' => (string) ($user['role'] ?? ''),
            ]);
        }

        // Re-read the account so a disabled or deleted owner loses access
        // immediately, even with a live session cookie.
        $fresh = User::find((int) $user['id']);

        if ($fresh === null || (int) $fresh['is_active'] !== 1 || !empty($fresh['deleted_at'])) {
            Session::logout();

            return Deny::access($request, 'حساب مالک سامانه فعال نیست.', 401);
        }

        if (!$allowWhileImpersonating && ImpersonationService::active()) {
            return Deny::access($request, 'در حالت دسترسی اضطراری، برای این عملیات ابتدا از پنل مشتری خارج شوید.', 403, [
                'impersonation' => true,
            ]);
        }

        return $next($request);
    }
}
