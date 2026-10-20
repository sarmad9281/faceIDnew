<?php
/**
 * SARMAD Attendance - KIOSK page controller (stage 4).
 *
 * Serves the installable PWA shell of the attendance device:
 *
 *   GET /device            -> the kiosk screen (activation + home + camera UI)
 *   GET /device/offline    -> the offline shell cached by sw.js
 *
 * Security notes (why this page can be public):
 *   - it renders NO customer data at all; branding, branch and settings are
 *     fetched by the browser from /api/v1/device/config with the device token,
 *     so a cached page can never show customer A to a device of customer B;
 *   - it exposes no navigation to /owner or /manager (a kiosk user can not walk
 *     into a panel from here);
 *   - it never contains the device token or any employee data;
 *   - it is served with a strict Content-Security-Policy, no-referrer and
 *     no-store, plus X-Robots-Tag so a kiosk URL is never indexed.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\Url;

final class DeviceKioskPageController
{
    public function index(Request $request): Response
    {
        return $this->shell('device/kiosk', 'unactivated');
    }

    /**
     * Cached by sw.js and used as the navigation fallback when the tablet is
     * offline.
     */
    public function offline(Request $request): Response
    {
        return $this->shell('device/offline', 'offline');
    }

    /**
     * @return array<string,mixed>
     */
    private function locals(string $state): array
    {
        $base = Url::basePath();
        $base = $base === '' ? '' : rtrim($base, '/');

        return [
            'pageTitle'   => 'SARMAD Attendance - دستگاه حضور و غیاب',
            'deviceState' => $state,
            'base'        => $base . '/',
            'manifestUrl' => Url::to('/manifest.webmanifest'),
            'swUrl'       => 'sw.js',
            'apiBase'     => Url::to('/api/v1/device'),
            'heartbeat'   => (int) Config::get('security.device_heartbeat_interval', 60),
            'appVersion'  => (string) Config::get('app.version', '1.0.0'),
        ];
    }

    private function shell(string $view, string $state): Response
    {
        $html = View::render($view, $this->locals($state), 'layouts/device');

        return Response::html($html)
            ->withHeader('Content-Security-Policy', $this->contentSecurityPolicy())
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->withHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The kiosk needs: its own stylesheet/script, the camera (media-src blob:
     * for the video element), and same-origin API calls. Everything else is
     * refused, which is what makes it safe to keep a long-lived device token in
     * the browser.
     */
    private function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "media-src 'self' blob: mediastream:",
            "connect-src 'self'",
            "font-src 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
