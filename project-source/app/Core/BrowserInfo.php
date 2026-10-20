<?php
/**
 * SARMAD Attendance - browser / device fingerprint label used in logs.
 */

declare(strict_types=1);

namespace App\Core;

final class BrowserInfo
{
    public static function label(): string
    {
        $agent = Request::userAgent();

        if ($agent === '') {
            return 'unknown';
        }

        $browser = self::browser($agent);
        $os      = self::os($agent);

        return trim($browser . ' / ' . $os, ' /');
    }

    private static function browser(string $agent): string
    {
        $map = [
            'Edg'      => 'Edge',
            'OPR'      => 'Opera',
            'Chrome'   => 'Chrome',
            'Safari'   => 'Safari',
            'Firefox'  => 'Firefox',
            'MSIE'     => 'IE',
            'Trident'  => 'IE',
        ];

        foreach ($map as $needle => $name) {
            if (stripos($agent, $needle) !== false) {
                return $name;
            }
        }

        return 'Other';
    }

    private static function os(string $agent): string
    {
        $map = [
            'Windows' => 'Windows',
            'Android' => 'Android',
            'iPhone'  => 'iOS',
            'iPad'    => 'iPadOS',
            'Mac OS'  => 'macOS',
            'Linux'   => 'Linux',
        ];

        foreach ($map as $needle => $name) {
            if (stripos($agent, $needle) !== false) {
                return $name;
            }
        }

        return 'Other';
    }
}
