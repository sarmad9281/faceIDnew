<?php
/**
 * SARMAD Attendance - requires an authenticated user session.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        if (!Session::isUser()) {
            return Deny::access($request, 'برای دسترسی به این بخش باید وارد حساب کاربری خود شوید.', 401);
        }

        $user = Session::user();

        // A blocked / soft-deleted account must lose access immediately.
        if ($user !== null && (!empty($user['is_blocked']) || !empty($user['deleted_at']))) {
            \App\Models\AuditLog::denied('Blocked account tried to access the panel', [
                'user_id' => (int) $user['id'],
            ]);
            Session::logout();

            return Deny::access($request, 'حساب کاربری شما غیرفعال شده است.', 401);
        }

        return $next($request);
    }
}
