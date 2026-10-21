<?php
/**
 * SARMAD Attendance - JSON API boundary.
 *
 * Guarantees:
 *   - only JSON answers (never an HTML error page)
 *   - no CSRF for token authenticated API calls
 *   - request id header for traceability
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;

final class ApiMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        // The API is authenticated by bearer token, so the session based
        // CSRF check does not apply - but a JSON content type is required
        // for state changing requests.
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $contentType = strtolower($request->header('content-type'));

            $hasJson = strpos($contentType, 'application/json') !== false;
            $hasForm = strpos($contentType, 'application/x-www-form-urlencoded') !== false
                || strpos($contentType, 'multipart/form-data') !== false;

            if (!$hasJson && !$hasForm) {
                return \App\Api\ApiResponse::error(
                    'unsupported_media_type',
                    'Content-Type باید application/json باشد.',
                    [],
                    415
                );
            }
        }

        // Defence in depth: browsers attach an Origin header on cross-site
        // requests. A token is not meant to be used from another site, so a
        // cross-origin call is refused outright (no CORS headers are sent
        // either, so a real browser cannot even read the response).
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && !$request->isSameOrigin()
        ) {
            Logger::channel('security')->warning('Cross origin API call blocked', [
                'path'   => $request->path(),
                'origin' => $request->header('origin'),
                'ip'     => Request::ip(),
            ]);

            return \App\Api\ApiResponse::error(
                'cross_origin_denied',
                'درخواست از یک دامنه دیگر مجاز نیست.',
                [],
                403
            );
        }

        $response = $next($request);

        return $response->withHeader('X-Request-Id', Security::randomHex(8));
    }
}
