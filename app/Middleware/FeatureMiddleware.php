<?php
/**
 * SARMAD Attendance - backend feature gate.
 *
 * Usage:  ['feature:branches']  or  ['feature:payroll,reports'] (any of)
 *
 * A disabled feature is refused HERE, before the controller runs, so a
 * manually typed URL or a hand crafted API call behaves exactly like a
 * hidden menu item: no data, no side effect.
 *
 *   - humans get a 403 page + an ACCESS_DENIED audit row,
 *   - API clients get the standard JSON error envelope.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Api\ApiResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\TenantContext;
use App\Models\AuditLog;
use App\Models\Feature;
use App\Services\FeatureService;

final class FeatureMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        $keys = array_values(array_filter(array_map(
            static fn (string $key): string => trim($key),
            $parameters
        )));

        if ($keys === []) {
            return $next($request);
        }

        $customerId = TenantContext::customerId();

        // Platform wide owner without a customer scope: nothing to gate.
        if ($customerId === null || $customerId <= 0) {
            return $next($request);
        }

        foreach ($keys as $key) {
            if (FeatureService::allows($customerId, $key)) {
                return $next($request);
            }
        }

        $titles = [];

        foreach ($keys as $key) {
            $feature = Feature::byKey($key);
            $titles[] = $feature === null ? $key : (string) $feature['title_fa'];
        }

        $message = 'امکانات «' . implode(' / ', $titles) . '» برای این مشتری فعال نیست.';

        AuditLog::record('access.denied', 'denied', $message, [
            'feature'     => $keys,
            'customer_id' => $customerId,
            'path'        => $request->path(),
        ], 'security');

        if ($request->isApi()) {
            return ApiResponse::forbidden($message);
        }

        return Deny::access($request, $message, 403, ['feature' => $keys]);
    }
}
