<?php
/**
 * SARMAD Attendance - role based access control.
 *
 * Usage:  ['role:owner']  or  ['role:owner,manager']
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        $required = array_values(array_filter(array_map(
            static fn (string $role): string => strtolower(trim($role)),
            $parameters
        )));

        if ($required === []) {
            return $next($request);
        }

        $role = Session::role();

        if ($role === null) {
            return Deny::access($request, 'ابتدا وارد حساب کاربری خود شوید.', 401);
        }

        if (!in_array($role, $required, true)) {
            return Deny::access(
                $request,
                'شما مجوز دسترسی به این بخش را ندارید.',
                403,
                ['role' => $role, 'required' => $required]
            );
        }

        return $next($request);
    }
}
