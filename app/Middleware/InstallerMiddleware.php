<?php
/**
 * SARMAD Attendance - protects the /install area.
 *
 * Rules:
 *  - installer is only reachable while the lock file is absent
 *  - an already installed system always answers 404 for /install
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class InstallerMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        if (Config::path('storage/installed.lock') !== '' && is_file(Config::path('storage/installed.lock'))) {
            return Response::html(
                \App\Core\View::render('errors/404', ['title' => 'صفحه یافت نشد'], 'layouts/blank'),
                404
            );
        }

        return $next($request);
    }
}
