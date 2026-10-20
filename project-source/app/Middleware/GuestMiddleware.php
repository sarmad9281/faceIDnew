<?php
/**
 * SARMAD Attendance - only for visitors who are NOT logged in.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class GuestMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        if (Session::isUser()) {
            return \App\Core\Redirect::response('/dashboard');
        }

        return $next($request);
    }
}
