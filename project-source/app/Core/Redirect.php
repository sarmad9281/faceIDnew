<?php
/**
 * SARMAD Attendance - redirect helper (always keeps the sub-folder base).
 */

declare(strict_types=1);

namespace App\Core;

final class Redirect
{
    public static function response(string $location, int $status = 302): Response
    {
        if (!preg_match('#^https?://#i', $location)) {
            $location = \App\Support\Url::to($location);
        }

        return Response::redirect($location, $status);
    }

    public static function to(string $location, int $status = 302): void
    {
        self::response($location, $status)->send();

        exit;
    }

    public static function back(string $fallback = '/'): void
    {
        $referer = Request::referer();

        if ($referer !== '' && self::isInternal($referer)) {
            Response::redirect($referer)->send();

            exit;
        }

        self::to($fallback);
    }

    private static function isInternal(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $self = (string) ($_SERVER['HTTP_HOST'] ?? '');

        return $host === null || $host === '' || strcasecmp((string) $host, $self) === 0;
    }
}
