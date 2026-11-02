<?php
/**
 * SARMAD Attendance - only for visitors who are NOT logged in.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;

final class GuestMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        if (Session::isUser()) {
            // Send every role to ITS OWN home - the same mapping the login
            // flow uses. A hard-coded '/dashboard' here sent the platform
            // owner (who belongs to no customer) into the tenant-scoped
            // manager panel, which answers 403. effectiveRole() keeps an
            // owner who is impersonating a customer inside that customer's
            // panel instead of bouncing them to the blocked /owner area.
            return Redirect::response(
                AuthService::defaultRedirectFor((string) Session::effectiveRole())
            );
        }

        return $next($request);
    }
}
