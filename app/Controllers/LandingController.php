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
     * No phone number or messenger handle is hard-coded in the codebase:
     * the operator fills `landing.*` in config/config.php (see
     * config.sample.php) and the cards/form link straight to those
     * channels. Until then every href falls back to the on-page
     * consultation form, so the page never ships a dead or invented
     * address.
     *
     * @return array{phoneDisplay:string,phoneHref:string,whatsappUrl:string,whatsappHref:string,telegramUrl:string,telegramHref:string}
     */
    private function contact(): array
    {
        $phone        = $this->configText('landing.phone');
        $phoneDisplay = $this->configText('landing.phone_display') ?: $phone;
        $whatsappUrl  = $this->whatsappUrl($this->configText('landing.whatsapp') ?: $phone);
        $telegramUrl  = $this->telegramUrl($this->configText('landing.telegram'));

        $dial = (string) preg_replace('/[^0-9+]/', '', $phone);

        return [
            'phoneDisplay' => $phoneDisplay,
            'phoneHref'    => $dial !== '' && $dial !== '+' ? 'tel:' . $dial : '#consult',
            'whatsappUrl'  => $whatsappUrl,
            'whatsappHref' => $whatsappUrl !== '' ? $whatsappUrl : '#consult',
            'telegramUrl'  => $telegramUrl,
            'telegramHref' => $telegramUrl !== '' ? $telegramUrl : '#consult',
        ];
    }

    private function configText(string $key): string
    {
        $value = Config::get($key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Accept a full wa.me link or a bare number (local 09… form included)
     * and normalise it to https://wa.me/<international digits>.
     */
    private function whatsappUrl(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
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
        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        $username = ltrim($value, '@');

        return preg_match('/^[A-Za-z0-9_]{3,}$/', $username) ? 'https://t.me/' . $username : '';
    }
}
