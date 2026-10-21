<?php
/**
 * SARMAD Attendance - CSRF middleware for web (session based) requests.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $tokenValid = Csrf::validate($request);
        $originOk   = $request->isSameOrigin();

        if ($tokenValid && $originOk) {
            return $next($request);
        }

        Logger::channel('security')->warning('CSRF validation failed', [
            'actor'  => Session::actorLabel(),
            'path'   => $request->path(),
            'method' => $request->method(),
            'reason' => $tokenValid ? 'cross_origin' : 'invalid_token',
        ]);

        if ($request->isApi()) {
            return \App\Api\ApiResponse::error('csrf_failed', 'توکن امنیتی نامعتبر است.', [], 419);
        }

        Session::flash('error', 'توکن امنیتی نامعتبر یا منقضی شده است. لطفاً فرم را دوباره ارسال کنید.');

        return Response::html(
            \App\Core\View::render('errors/419', ['title' => 'توکن امنیتی نامعتبر'], 'layouts/blank'),
            419
        );
    }
}
