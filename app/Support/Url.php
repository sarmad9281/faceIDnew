<?php
/**
 * SARMAD Attendance - URL generation.
 *
 * Works both when the project is extracted into the domain root
 * (public_html/) and when it lives in a sub-directory, which keeps the
 * package portable on DirectAdmin.
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Request;

final class Url
{
    private static ?string $basePath = null;
    private static ?string $baseUrl = null;

    /**
     * Base path of the installation, e.g. '' or '/attendance'.
     *
     * The runtime location (SCRIPT_NAME) is authoritative when app.url does
     * not carry a path, so a configuration copied between a root install and
     * a sub-directory install can never silently break every route.
     */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        $configuredPath = '';

        $configured = (string) Config::get('app.url', '');

        if ($configured !== '') {
            $configuredPath = rtrim((string) (parse_url($configured, PHP_URL_PATH) ?: ''), '/');
        }

        // An explicit path in app.url is honoured - unless the running script
        // proves the installation lives in another folder (the common case is
        // renaming the folder after install, e.g. /barber -> /attendance).
        if ($configuredPath !== '' && $configuredPath !== '/') {
            $runtime = self::runtimeBasePath();

            if ($runtime !== null && $runtime !== $configuredPath) {
                return self::$basePath = $runtime;
            }

            return self::$basePath = $configuredPath;
        }

        return self::$basePath = self::detectBasePath();
    }

    /**
     * Base path proven by the current HTTP request, or null when the request
     * proves nothing (CLI, or a URL that is not part of this installation).
     */
    private static function runtimeBasePath(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if ($uri !== '') {
            $uri = (string) (parse_url($uri, PHP_URL_PATH) ?: '');
        }

        $detected = self::detectBasePath();

        if ($detected === '') {
            // Root install: only trust the runtime when the URL is at the root.
            return ($uri === '' || $uri === '/') ? '' : null;
        }

        // Trust the runtime only while the requested URL really lives under it.
        return ($uri === $detected || strpos($uri, $detected . '/') === 0) ? $detected : null;
    }

    /**
     * Derive the base path from the running script (handles the installer,
     * the front controller, sub-directories and CLI usage).
     */
    private static function detectBasePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        if ($script === '' && PHP_SAPI === 'cli') {
            return '';
        }

        $dir = str_replace('\\', '/', dirname($script));

        // /index.php             -> ''
        // /attendance/index.php  -> '/attendance'
        // /install/index.php     -> '/install'  => collapse to the app root
        if (substr($dir, -8) === '/install') {
            $dir = substr($dir, 0, -8);
        }

        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            $dir = '';
        }

        return rtrim($dir, '/');
    }

    public static function base(): string
    {
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }

        $configured = (string) Config::get('app.url', '');

        if ($configured !== '') {
            $runtime        = self::runtimeBasePath();
            $configuredPath = rtrim((string) (parse_url($configured, PHP_URL_PATH) ?: ''), '/');

            if ($runtime !== null && $runtime !== $configuredPath) {
                // Keep the operator's scheme + host, correct only the folder.
                $parts  = parse_url($configured) ?: [];
                $scheme = (string) ($parts['scheme'] ?? (\App\Core\Security::isHttps() ? 'https' : 'http'));
                $host   = (string) ($parts['host'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'));

                if (isset($parts['port'])) {
                    $host .= ':' . $parts['port'];
                }

                return self::$baseUrl = rtrim($scheme . '://' . $host . $runtime, '/');
            }

            return self::$baseUrl = rtrim($configured, '/');
        }

        $scheme = \App\Core\Security::isHttps() ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Strip a port from the host if present - the header already has it.
        if (!preg_match('/^[A-Za-z0-9\.\-]+(:\d+)?$/', $host)) {
            $host = 'localhost';
        }

        return self::$baseUrl = $scheme . '://' . $host . self::basePath();
    }

    /**
     * Internal absolute path (Web path), e.g. Url::to('/login').
     */
    public static function to(string $path = ''): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $path = '/' . ltrim($path, '/');

        return self::basePath() . ($path === '/' ? '' : rtrim($path, '/')) ?: '/';
    }

    /**
     * STAGE 21 - one version for every stylesheet.
     *
     * The cache-busting parameter of all CSS is decided HERE and nowhere else:
     * layouts call Url::css(), so a new rule can never be shadowed by a
     * browser holding the old file. Bump this constant and every panel
     * (manager, owner, kiosk) asks for the fresh file on its next load.
     */
    public const CSS_VERSION = '1.2.1';

    /**
     * Asset URL with a version query.
     *
     * The query is built exactly once - `?v=1.2.1` - so a caller can never
     * produce the historical `?v=1.0.9&v=1.1.0` double parameter, which made
     * browsers (and PHP) keep reading the FIRST, stale value.
     */
    public static function asset(string $path, ?string $version = null): string
    {
        if ($version === null || $version === '') {
            $version = (string) Config::get('app.version', '1.0.9');

            if (in_array($version, ['1.0.0', '1.0.1', '1.0.2', '1.0.3', '1.0.4', '1.0.5', '1.0.6', '1.0.7', '1.0.8'], true)) {
                $version = '1.0.9';
            }
        }

        $path = '/' . ltrim($path, '/');

        // Drop any query/hash the caller might have pasted in, then add ours.
        $path = (string) (preg_split('/[?#]/', $path)[0] ?? $path);

        return self::to('/assets' . $path) . '?v=' . rawurlencode($version);
    }

    /**
     * Stylesheet URL - always pinned to Url::CSS_VERSION.
     */
    public static function css(string $path): string
    {
        return self::asset($path, self::CSS_VERSION);
    }

    public static function full(string $path = ''): string
    {
        return rtrim(self::base(), '/') . self::to($path);
    }

    /**
     * Absolute, verified redirect target - blocks open redirects.
     */
    public static function safeRedirect(string $candidate, string $fallback = '/dashboard'): string
    {
        $candidate = trim($candidate);

        if ($candidate === '' || strpos($candidate, "\n") !== false || strpos($candidate, "\r") !== false) {
            return self::to($fallback);
        }

        // Only same-site, path-like targets are accepted.
        if (preg_match('#^(https?:)?//#i', $candidate)) {
            $host = parse_url($candidate, PHP_URL_HOST);
            $self = (string) ($_SERVER['HTTP_HOST'] ?? '');

            if ($host === null || strcasecmp((string) $host, $self) !== 0) {
                return self::to($fallback);
            }
        }

        if (strpos($candidate, '/') !== 0) {
            return self::to($fallback);
        }

        // Never bounce back to the installer.
        if (stripos($candidate, '/install') === 0) {
            return self::to($fallback);
        }

        return self::to($candidate);
    }

    public static function current(): string
    {
        return self::to(Request::capture()->path());
    }

    /**
     * Make sure an upload path can never escape the uploads directory.
     */
    /**
     * Public URL of a stored upload (uploads/ is web readable, PHP execution
     * inside it is blocked by uploads/.htaccess).
     */
    public static function upload(string $relative): string
    {
        $relative = str_replace(['..', '\\'], '', $relative);
        $relative = ltrim($relative, '/');

        return self::to('/uploads/' . $relative);
    }

    /**
     * Public URL of a customer logo with a cache busting stamp.
     *
     * @param array<string,mixed> $customer
     */
    public static function customerLogo(array $customer): ?string
    {
        $path = trim((string) ($customer['logo_path'] ?? ''));

        if ($path === '') {
            return null;
        }

        return self::upload($path) . '?v=' . rawurlencode(substr((string) ($customer['updated_at'] ?? ''), 0, 19));
    }

    public static function uploadPath(string $relative): string
    {
        $relative = str_replace(['..', '\\'], '', $relative);
        $relative = ltrim($relative, '/');

        return Config::path('uploads/' . $relative);
    }
}
