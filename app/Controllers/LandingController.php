<?php
/**
 * SARMAD Attendance - public landing page (GET /).
 *
 * The site root used to require a login, so a first-time visitor met the
 * bare login form with no idea what the product is. The root is now the
 * public showcase: guests see the marketing page, and anyone who already
 * has a session is handed to the ONE existing role dispatcher
 * (DashboardController::home), which sends managers/supervisors to
 * /dashboard, the platform owner to /owner and a device session to
 * /device. Reusing it matters: blindly redirecting "every login" to
 * /dashboard re-introduces the owner 403 fixed earlier (the owner belongs
 * to no customer, so the tenant-scoped dashboard rejects him).
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Setting;

final class LandingController
{
    public function index(Request $request): Response
    {
        if (Session::isAuthenticated()) {
            return (new DashboardController())->home($request);
        }

        return Response::html(View::render('landing', [
            'title'   => 'سرمد | سامانه حضور و غیاب هوشمند با تشخیص چهره',
            'contact' => $this->contact(),
        ]));
    }

    /**
     * Public contact channels for the landing page.
     *
     * The platform owner maintains these values in /owner/settings. They
     * are read from the global settings store (`platform.*`); the older
     * `landing.*` config keys remain a fallback so an installation that
     * already configured its public channels in config.php does not lose
     * them during the move to the owner panel.
     *
     * Empty or invalid channels stay empty and are not rendered as dead
     * links: no phone number or social handle is invented in code.
     *
     * @return array{
     *     phoneDisplay:string,
     *     phoneHref:string,
     *     instagramUrl:string,
     *     instagramHref:string,
     *     instagramLabel:string,
     *     whatsappUrl:string,
     *     whatsappHref:string,
     *     telegramUrl:string,
     *     telegramHref:string,
     *     telegramLabel:string
     * }
     */
    private function contact(): array
    {
        $phone        = $this->contactText('platform.support_phone', 'landing.phone');
        $phoneDisplay = $this->configText('landing.phone_display') ?: $phone;
        $instagramUrl = $this->instagramUrl($this->contactText('platform.instagram', 'landing.instagram'));
        $whatsappRaw  = $this->contactText('platform.whatsapp', 'landing.whatsapp');
        $whatsappUrl  = $this->whatsappUrl($whatsappRaw);
        $telegramUrl  = $this->telegramUrl($this->contactText('platform.telegram', 'landing.telegram'));

        $dial = (string) preg_replace('/[^0-9+]/', '', $this->normaliseDigits($phone));

        return [
            'phoneDisplay'   => $phoneDisplay,
            'phoneHref'      => $dial !== '' && $dial !== '+' ? 'tel:' . $dial : '',
            'instagramUrl'   => $instagramUrl,
            'instagramHref'  => $instagramUrl,
            'instagramLabel' => $this->socialLabel($instagramUrl),
            'whatsappUrl'    => $whatsappUrl,
            'whatsappHref'   => $whatsappUrl,
            'telegramUrl'    => $telegramUrl,
            'telegramHref'   => $telegramUrl,
            'telegramLabel'  => $this->socialLabel($telegramUrl),
        ];
    }

    private function contactText(string $settingKey, string $configKey): string
    {
        return $this->settingText($settingKey) ?: $this->configText($configKey);
    }

    private function settingText(string $key): string
    {
        try {
            $value = Setting::get($key, '');
        } catch (\Throwable $exception) {
            // The public root must still render before installation has
            // created the settings table, or if the DB is briefly down.
            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function configText(string $key): string
    {
        $value = Config::get($key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normaliseDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /**
     * Accept an Instagram profile URL, an instagram.com path, or a bare
     * handle (with or without @), and normalise it to an HTTPS URL.
     */
    private function instagramUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            $parts = parse_url($value);

            if (!is_array($parts)) {
                return '';
            }

            $host  = strtolower((string) ($parts['host'] ?? ''));
            $path  = '/' . trim((string) ($parts['path'] ?? ''), '/');

            if (!in_array($host, ['instagram.com', 'www.instagram.com'], true) || $path === '/') {
                return '';
            }

            $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

            return 'https://www.instagram.com' . $path . $query;
        }

        if (preg_match('#^(?:www\.)?instagram\.com/(.+)$#i', $value, $matches)) {
            $path = '/' . trim((string) $matches[1], '/');

            return $path !== '/' ? 'https://www.instagram.com' . $path : '';
        }

        $username = ltrim($value, '@');

        if (preg_match('/^[A-Za-z0-9._]{1,30}$/', $username)
            && $username[0] !== '.'
            && substr($username, -1) !== '.'
            && strpos($username, '..') === false
        ) {
            return 'https://www.instagram.com/' . $username;
        }

        return '';
    }

    private function socialLabel(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');

        if ($path === '') {
            return '';
        }

        $segment = explode('/', $path)[0];
        $reserved = ['accounts', 'direct', 'explore', 'joinchat', 'p', 'reel', 'reels', 'stories', 'tv'];

        return !in_array(strtolower($segment), $reserved, true)
            && $segment[0] !== '+'
            && preg_match('/^[A-Za-z0-9._+=-]+$/', $segment)
            ? '@' . ltrim($segment, '@')
            : '';
    }

    /**
     * Accept a WhatsApp link or a bare number (local 09… form included)
     * and normalise numbers to https://wa.me/<international digits>.
     */
    private function whatsappUrl(string $value): string
    {
        $value = trim($this->normaliseDigits($value));

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            $host = strtolower((string) (parse_url($value, PHP_URL_HOST) ?: ''));

            return in_array($host, ['wa.me', 'api.whatsapp.com', 'chat.whatsapp.com'], true)
                ? (string) preg_replace('#^http://#i', 'https://', $value)
                : '';
        }

        if (preg_match('#^(?:www\.)?wa\.me/(.+)$#i', $value, $matches)) {
            $value = (string) $matches[1];
        }

        $digits = (string) preg_replace('/\D+/', '', $value);

        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        } elseif (strpos($digits, '0') === 0 && strlen($digits) === 11) {
            $digits = '98' . substr($digits, 1);
        } elseif (strlen($digits) === 10 && strpos($digits, '9') === 0) {
            $digits = '98' . $digits;
        }

        return strlen($digits) >= 10 ? 'https://wa.me/' . $digits : '';
    }

    private function telegramUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            $parts = parse_url($value);

            if (!is_array($parts)) {
                return '';
            }

            $host  = strtolower((string) ($parts['host'] ?? ''));
            $path  = '/' . trim((string) ($parts['path'] ?? ''), '/');

            if (!in_array($host, ['t.me', 'telegram.me', 'www.t.me', 'www.telegram.me'], true) || $path === '/') {
                return '';
            }

            $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

            return 'https://t.me' . $path . $query;
        }

        if (preg_match('#^(?:www\.)?(?:t\.me|telegram\.me)/(.+)$#i', $value, $matches)) {
            $path = '/' . trim((string) $matches[1], '/');

            return $path !== '/' ? 'https://t.me' . $path : '';
        }

        $username = ltrim($value, '@');

        return preg_match('/^[A-Za-z0-9_]{3,}$/', $username) ? 'https://t.me/' . $username : '';
    }
}
