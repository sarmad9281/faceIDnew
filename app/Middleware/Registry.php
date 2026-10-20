<?php
/**
 * SARMAD Attendance - middleware registry.
 *
 * Every route declares its middleware by short name, for example:
 *   ['auth', 'role:owner', 'tenant', 'csrf']
 *
 * The registry turns that into a callable for the pipeline. Parameters
 * come after a colon, several parameters are comma separated.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Api\Middleware\ApiAuth;
use App\Api\Middleware\DeviceAuth;
use App\Core\Request;
use App\Core\Response;
use RuntimeException;

final class Registry
{
    /** @var array<string,class-string> middleware name => class */
    private const MAP = [
        'csrf'         => CsrfMiddleware::class,
        'auth'         => AuthMiddleware::class,
        'guest'        => GuestMiddleware::class,
        'role'         => RoleMiddleware::class,
        'feature'      => FeatureMiddleware::class,
        'owner'        => OwnerMiddleware::class,
        'tenant'       => TenantMiddleware::class,
        'api'          => ApiMiddleware::class,
        'api.auth'     => ApiAuth::class,
        'api.owner'    => \App\Api\Middleware\OwnerApi::class,
        'device.token' => DeviceAuth::class,
        'throttle'     => ThrottleMiddleware::class,
        'installer'    => InstallerMiddleware::class,
    ];

    public static function resolve(string $definition, string $tenantMode = 'auto'): callable
    {
        $parts      = explode(':', $definition, 2);
        $name       = strtolower(trim($parts[0]));
        $parameters = isset($parts[1]) && $parts[1] !== ''
            ? array_map('trim', explode(',', $parts[1]))
            : [];

        if (!isset(self::MAP[$name])) {
            throw new RuntimeException('Unknown middleware: ' . $name);
        }

        $class = self::MAP[$name];

        return static function (Request $request, callable $next) use ($class, $name, $parameters, $tenantMode): Response {
            $middleware = new $class();

            if ($name === 'tenant' && $tenantMode !== '' && $tenantMode !== 'auto') {
                $parameters[] = $tenantMode;
            }

            return $middleware->handle($request, $next, $parameters);
        };
    }
}
