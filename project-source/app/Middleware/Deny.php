<?php
/**
 * SARMAD Attendance - shared "access denied" helper for middleware.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\AuditLog;

final class Deny
{
    /**
     * @param array<string,mixed> $context
     */
    public static function access(Request $request, string $message, int $status = 403, array $context = []): Response
    {
        // Audit only authenticated actors; anonymous probing is noisy.
        if (Session::isAuthenticated()) {
            AuditLog::denied($message, $context);
        }

        if ($request->isApi()) {
            return \App\Api\ApiResponse::error(
                $status === 401 ? 'unauthenticated' : 'forbidden',
                $message,
                [],
                $status
            );
        }

        if ($status === 401) {
            $next = urlencode($request->uri());
            $next = $next !== '' ? '?next=' . $next : '';

            return Redirect::response('/login' . ($request->path() === '/login' ? '' : $next));
        }

        Session::flash('error', $message);

        return Response::html(
            \App\Core\View::render('errors/403', [
                'title'   => 'دسترسی غیرمجاز',
                'message' => $message,
            ], 'layouts/blank'),
            $status
        );
    }
}
