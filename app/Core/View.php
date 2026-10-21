<?php
/**
 * SARMAD Attendance - plain PHP template renderer.
 *
 * Templates live in /views and receive data as extracted variables plus
 * the $view helper (e() to escape output).
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /** @var array<string,mixed> */
    private static array $shared = [];

    /**
     * @param mixed $value
     */
    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::renderTemplate($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderTemplate($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderTemplate(string $__template, array $__data): string
    {
        // Locals are prefixed with "__" on purpose: the template body is
        // included in THIS scope, so extract() with EXTR_SKIP must never
        // collide with a template variable (e.g. a view that receives $data
        // or $content of its own).
        $__path = self::resolve($__template);

        $__variables = array_merge(self::$shared, $__data, [
            'e'         => static fn ($value): string => self::e($value),
            'asset'     => static fn (string $path): string => \App\Support\Url::asset($path),
            'url'       => static fn (string $path): string => \App\Support\Url::to($path),
            'csrf'      => static fn (): string => Csrf::field(),
            'csrfMeta'  => static fn (): string => Csrf::meta(),
            'flash'     => static fn (string $key): ?string => Session::getFlash($key),
            'session'   => static fn (): object => new class {
                public function user(): ?array
                {
                    return Session::user();
                }

                public function role(): ?string
                {
                    return Session::role();
                }

                public function isAuthenticated(): bool
                {
                    return Session::isAuthenticated();
                }

                public function userId(): ?int
                {
                    return Session::userId();
                }
            },
        ]);

        extract($__variables, EXTR_SKIP);

        ob_start();

        try {
            /** @noinspection PhpIncludeInspection */
            include $__path;
        } catch (\Throwable $__exception) {
            ob_end_clean();

            throw $__exception;
        }

        return (string) ob_get_clean();
    }

    public static function exists(string $template): bool
    {
        try {
            self::resolve($template);

            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    private static function resolve(string $template): string
    {
        if (!preg_match('#^[A-Za-z0-9_\-/]+$#', $template) || strpos($template, '..') !== false) {
            throw new RuntimeException('Invalid view name: ' . $template);
        }

        $path = Config::path('views/' . $template . '.php');

        if (!is_file($path)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        return $path;
    }

    /**
     * Escape any value for HTML context (XSS protection).
     *
     * @param mixed $value
     */
    public static function e($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return htmlspecialchars((string) json_encode($value, JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape for an HTML attribute.
     *
     * @param mixed $value
     */
    public static function attr($value): string
    {
        return self::e($value);
    }
}
