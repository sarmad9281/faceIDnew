<?php
/**
 * SARMAD Attendance - minimal, dependency free router.
 *
 * Routes are declared as:
 *   $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
 *   $router->post('/api/v1/devices/register', [DeviceController::class, 'register'],
 *                 ['api', 'device.token']);
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Router
{
    /** @var array<string, array<string, array{handler:mixed, middleware:array<int,string>, name:?string, tenant:string}>> */
    private array $routes = [];

    /** @var array<string,string> */
    private array $groupStack = [];

    /* =================================================================
     * Registration
     * ===============================================================*/

    /**
     * @param array<int,string> $middleware
     */
    public function get(string $path, $handler, array $middleware = [], ?string $name = null, string $tenant = 'auto'): self
    {
        return $this->add('GET', $path, $handler, $middleware, $name, $tenant);
    }

    /**
     * @param array<int,string> $middleware
     */
    public function post(string $path, $handler, array $middleware = [], ?string $name = null, string $tenant = 'auto'): self
    {
        return $this->add('POST', $path, $handler, $middleware, $name, $tenant);
    }

    /**
     * @param array<int,string> $middleware
     */
    public function put(string $path, $handler, array $middleware = [], ?string $name = null, string $tenant = 'auto'): self
    {
        return $this->add('PUT', $path, $handler, $middleware, $name, $tenant);
    }

    /**
     * @param array<int,string> $middleware
     */
    public function patch(string $path, $handler, array $middleware = [], ?string $name = null, string $tenant = 'auto'): self
    {
        return $this->add('PATCH', $path, $handler, $middleware, $name, $tenant);
    }

    /**
     * @param array<int,string> $middleware
     */
    public function delete(string $path, $handler, array $middleware = [], ?string $name = null, string $tenant = 'auto'): self
    {
        return $this->add('DELETE', $path, $handler, $middleware, $name, $tenant);
    }

    /**
     * @param array<int,string> $middleware
     */
    public function add(string $method, string $path, $handler, array $middleware = [], ?string $name = null, string $tenant = 'auto'): self
    {
        $method = strtoupper($method);
        $path   = '/' . trim($path, '/');
        $path   = $path === '/' ? '/' : rtrim($path, '/');

        if ($this->groupStack !== []) {
            $prefix     = $this->groupStack['prefix'] ?? '';
            $groupMw    = $this->groupStack['middleware'] ?? '';
            $groupTenant = $this->groupStack['tenant'] ?? '';

            if ($prefix !== '') {
                $path = rtrim($prefix, '/') . $path;
                $path = $path === '' ? '/' : $path;
            }

            if ($groupMw !== '') {
                $middleware = array_merge(explode(',', $groupMw), $middleware);
            }

            if ($groupTenant !== '' && $tenant === 'auto') {
                $tenant = $groupTenant;
            }
        }

        if (isset($this->routes[$method][$path])) {
            throw new RuntimeException('Duplicate route: ' . $method . ' ' . $path);
        }

        $this->routes[$method][$path] = [
            'handler'    => $handler,
            'middleware' => array_values(array_filter($middleware)),
            'name'       => $name,
            'tenant'     => $tenant,
        ];

        return $this;
    }

    /**
     * @param array<int,string> $middleware
     */
    public function group(string $prefix, array $middleware = [], string $tenant = ''): void
    {
        $this->groupStack = [
            'prefix'     => '/' . trim($prefix, '/'),
            'middleware' => implode(',', $middleware),
            'tenant'     => $tenant,
        ];
    }

    public function endGroup(): void
    {
        $this->groupStack = [];
    }

    /* =================================================================
     * Dispatch
     * ===============================================================*/

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = $request->path();

        if ($method === 'HEAD' && !isset($this->routes['HEAD'][$path])) {
            $method = 'GET';
        }

        // Allow method spoofing for forms that must send PUT/DELETE.
        if ($method === 'POST' && ($spoof = $request->input('_method')) !== null) {
            $candidate = strtoupper((string) $spoof);

            if (isset($this->routes[$candidate][$path])) {
                $method = $candidate;
            }
        }

        $route  = null;
        $params = [];

        if (isset($this->routes[$method][$path])) {
            $route = $this->routes[$method][$path];
        } else {
            // Named parameters: /owner/customers/{uuid}/edit
            foreach ($this->routes[$method] ?? [] as $pattern => $candidate) {
                if (strpos($pattern, '{') === false) {
                    continue;
                }

                $names   = [];
                $regex   = '#^' . self::routeFragment($pattern, $names) . '$#';

                if (preg_match($regex, $path, $matches) === 1) {
                    array_shift($matches);

                    foreach ($names as $index => $name) {
                        $params[$name] = isset($matches[$index]) ? rawurldecode($matches[$index]) : '';
                    }

                    $route = $candidate;
                    break;
                }
            }
        }

        if ($params !== []) {
            $request->setRouteParams($params);
        }

        if ($route === null) {
            $this->notFound($request);

            return;
        }

        $this->run($route, $request);
    }

    /**
     * @param array{handler:mixed, middleware:array<int,string>, name:?string, tenant:string} $route
     */
    private function run(array $route, Request $request): void
    {
        $pipeline = \App\Middleware\Pipeline::make();

        foreach ($route['middleware'] as $middleware) {
            $pipeline = $pipeline->pipe(\App\Middleware\Registry::resolve($middleware, $route['tenant']));
        }

        $pipeline->then(function (Request $passRequest) use ($route): Response {
            return $this->invoke($route['handler'], $passRequest);
        })->handle($request);
    }

    private function invoke($handler, Request $request): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $action] = $handler;

            if (!class_exists($class)) {
                throw new RuntimeException('Controller not found: ' . $class);
            }

            $controller = new $class();
            $result     = $controller->{$action}($request);
        } else {
            throw new RuntimeException('Invalid route handler.');
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html('');
    }

    /**
     * Build the regex fragment of a route pattern.
     *
     *   /owner/customers/{uuid}/edit  ->  /owner/customers/([^/]+)/edit
     *
     * Everything that is not a {placeholder} is quoted with preg_quote();
     * preg_quote() also escapes { and }, and both PCRE and this matcher
     * treat a lone brace as a literal character, so the escaped braces are
     * normalised back before the placeholders are replaced.
     *
     * @param array<int,string> $names filled with the placeholder names in order
     */
    private static function routeFragment(string $pattern, array &$names): string
    {
        $quoted = str_replace(
            [chr(92) . '{', chr(92) . '}'],
            ['{', '}'],
            preg_quote($pattern, '#')
        );

        return (string) preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            static function (array $match) use (&$names): string {
                $names[] = $match[1];

                return '([^/]+)';
            },
            $quoted
        );
    }

    private function notFound(Request $request): void
    {
        if ($request->isApi()) {
            \App\Api\ApiResponse::error('not_found', 'مسیر درخواستی یافت نشد.', [], 404)->send();

            return;
        }

        Response::html(View::render('errors/404', ['title' => 'صفحه یافت نشد'], 'layouts/blank'), 404)->send();
    }
}
