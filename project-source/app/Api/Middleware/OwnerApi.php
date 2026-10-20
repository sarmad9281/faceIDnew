<?php
/**
 * SARMAD Attendance - owner only guard for API routes.
 *
 * Runs AFTER ApiAuth (which resolves the bearer token into a session
 * actor). A manager token can never reach an /api/v1/owner/* endpoint, even
 * if the token carries every ability: the role is checked against the
 * database, not against the token payload.
 */

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\ApiResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Middleware\MiddlewareInterface;

final class OwnerApi implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        $userId = Session::userId();

        if ($userId === null) {
            return ApiResponse::unauthorized('احراز هویت لازم است.');
        }

        $user = User::find($userId);

        if ($user === null || (string) $user['role'] !== Role::OWNER || (int) $user['is_active'] !== 1) {
            AuditLog::record('access.denied', 'denied', 'Non owner token tried to reach the owner API.', [
                'user_id' => $userId,
                'path'    => $request->path(),
            ], 'security');

            return ApiResponse::forbidden('این بخش فقط برای مالک سامانه است.');
        }

        return $next($request);
    }
}
