<?php
/**
 * SARMAD Attendance - HTTP request object.
 */

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $query;
    private array $post;
    private array $files;
    private array $server;
    private array $headers;
    private ?array $json = null;

    /** @var array<string,string> named route parameters (e.g. {uuid}) */
    private array $routeParams = [];

    private function __construct()
    {
    }

    public static function capture(): self
    {
        $request = new self();

        $request->server  = $_SERVER;
        $request->query   = $_GET;
        $request->post    = $_POST;
        $request->files   = $_FILES;
        $request->method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $request->uri     = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $request->path    = self::normalizePath((string) (parse_url($request->uri, PHP_URL_PATH) ?: '/'));
        $request->headers = self::collectHeaders();

        // JSON APIs: expose the decoded body through the same accessors as
        // classic form posts (int(), string(), bool(), input(), ...).
        $contentType = strtolower($request->header('content-type'));

        if (strpos($contentType, 'application/json') !== false) {
            $decoded = $request->json();

            if ($decoded !== []) {
                $request->post = array_merge($request->post, $decoded);
            }
        }

        return $request;
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        // Drop the base directory when the app runs in a sub-folder.
        $base = \App\Support\Url::basePath();

        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return $path === '' ? '/' : $path;
    }

    /* =================================================================
     * Accessors
     * ===============================================================*/

    public function method(): string
    {
        return $this->method;
    }

    /**
     * @param array<string,string> $params
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * A named parameter captured by the router ('' when absent).
     *
     * Route parameters take precedence over query/post values so a
     * "?uuid=..." cannot override the path the user actually opened.
     */
    public function param(string $key, string $default = ''): string
    {
        $value = $this->routeParams[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return array<string,string>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Current page number for paginated listings (1 based, never < 1).
     */
    public function page(): int
    {
        return max(1, $this->int('page', 1));
    }

    /**
     * Items per page, clamped to a sane range so a hostile query string
     * cannot ask for the whole table.
     */
    public function perPage(int $default = 20, int $max = 100): int
    {
        $value = $this->int('per_page', $default);

        return max(5, min($max, $value));
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isApi(): bool
    {
        return strpos($this->path, '/api/') === 0;
    }

    public function header(string $name, string $default = ''): string
    {
        $key = strtolower($name);

        return $this->headers[$key] ?? $default;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function bearerToken(): string
    {
        $header = $this->header('authorization');

        if (stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }

        // Some kiosk/proxy setups cannot send headers.
        $fallback = $this->input('token');

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    /**
     * Does the request carry this field at all?
     *
     * Used where "absent" and "empty" must stay distinguishable, e.g. the
     * owner settings form: an unchecked checkbox is posted as 0 by the form
     * itself, while a request that does not mention the field at all must
     * not silently disable the setting.
     */
    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->query) || array_key_exists($key, $this->post)) {
            return true;
        }

        return array_key_exists($key, $this->files);
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default ? '1' : '0');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /**
     * JSON body for API calls.
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return $this->json = [];
        }

        $decoded = json_decode($raw, true);

        return $this->json = is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function payload(string $key, $default = null)
    {
        $json = $this->json();

        if (array_key_exists($key, $json)) {
            $value = $json[$key];

            return is_string($value) ? trim($value) : $value;
        }

        return $this->input($key, $default);
    }

    /**
     * Every value sent with the request, whatever the encoding: query + form
     * body merged first, then the JSON body on top. Used by the device API
     * where a client may post JSON or a plain form.
     *
     * @return array<string,mixed>
     */
    public function payloadAll(): array
    {
        return array_merge($this->all(), $this->json());
    }

    /**
     * @return array<string,mixed>
     */
    public function file(string $key): array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : [];
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);

        return $file !== [] && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /* =================================================================
     * Client information
     * ===============================================================*/

    public static function ip(): string
    {
        $candidates = ['REMOTE_ADDR'];

        if (self::trustProxy()) {
            array_unshift($candidates, 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP');
        }

        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            foreach (explode(',', (string) $_SERVER[$key]) as $ip) {
                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    public static function trustProxy(): bool
    {
        return Config::loaded() && (bool) Config::get('security.trust_proxy', false);
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function referer(): string
    {
        return substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
    }

    /**
     * Same-origin check for state changing requests (extra CSRF layer).
     */
    public function isSameOrigin(): bool
    {
        $origin = $this->header('origin');

        if ($origin === '') {
            return true; // No cross-site hint at all; CSRF token still applies.
        }

        $host = (string) ($this->server['HTTP_HOST'] ?? '');

        if ($host === '') {
            return false;
        }

        $originHost = parse_url($origin, PHP_URL_HOST) ?: '';
        $originPort = parse_url($origin, PHP_URL_PORT) ?: '';
        $target     = $originPort !== '' ? $originHost . ':' . $originPort : $originHost;
        $expected   = $host;

        return strcasecmp($target, $expected) === 0 || strcasecmp($originHost, $host) === 0;
    }

    /* ================================================================= */

    /**
     * @return array<string,string>
     */
    private static function collectHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));

            if ($name === 'authorization') {
                // Some SAPIs strip it from getallheaders(); keep it here.
                $headers[$name] = (string) $value;
                continue;
            }

            $headers[$name] = is_scalar($value) ? (string) $value : '';
        }

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
            }
        }

        if (!isset($headers['authorization']) && isset($headers['x-auth-token'])) {
            $headers['authorization'] = 'Bearer ' . $headers['x-auth-token'];
        }

        return $headers;
    }
}
