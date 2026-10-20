<?php
/**
 * SARMAD Attendance - simple request throttling.
 *
 * Usage: ['throttle:60,1']  => 60 requests per 1 minute per actor/IP.
 * Uses a small file based counter inside storage/cache (no extra
 * extensions required on the DirectAdmin host).
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class ThrottleMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        $limit    = (int) ($parameters[0] ?? 60);
        $minutes  = (int) ($parameters[1] ?? 1);
        $limit    = max(1, $limit);
        $minutes  = max(1, $minutes);
        $window   = $minutes * 60;

        $key  = self::key($request);
        $file = Config::path('storage/cache/throttle_' . sha1($key) . '.json');

        $state = ['start' => time(), 'hits' => 0];

        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['start'], $decoded['hits'])) {
                $state = ['start' => (int) $decoded['start'], 'hits' => (int) $decoded['hits']];
            }
        }

        if ((time() - $state['start']) > $window) {
            $state = ['start' => time(), 'hits' => 0];
        }

        $state['hits']++;

        @file_put_contents($file, (string) json_encode($state), LOCK_EX);

        if ($state['hits'] > $limit) {
            $retry = max(1, $window - (time() - $state['start']));

            Logger::channel('security')->warning('Rate limit exceeded', [
                'actor' => Session::actorLabel(),
                'path'  => $request->path(),
                'hits'  => $state['hits'],
            ]);

            if ($request->isApi()) {
                return \App\Api\ApiResponse::error(
                    'rate_limited',
                    'تعداد درخواست‌ها بیش از حد مجاز است.',
                    ['retry_after' => $retry],
                    429
                );
            }

            return Response::html(
                \App\Core\View::render('errors/429', ['title' => 'تعداد درخواست بیش از حد', 'retry' => $retry], 'layouts/blank'),
                429
            );
        }

        return $next($request);
    }

    private static function key(Request $request): string
    {
        $actor = Session::actorLabel();

        return $actor . '|' . Request::ip() . '|' . $request->path();
    }
}
