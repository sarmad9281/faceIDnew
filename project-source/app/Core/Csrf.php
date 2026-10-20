<?php
/**
 * SARMAD Attendance - CSRF protection.
 *
 * One token per session (rotated on login / logout / periodically), sent
 * as a hidden field in every state changing form and validated in the
 * middleware. API requests use their own bearer tokens instead.
 */

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const FIELD_NAME = '_token';
    private const HEADER_NAME = 'X-CSRF-Token';
    private const KEY_TOKEN = 'csrf_token';
    private const KEY_TIME  = 'csrf_time';

    public static function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    public static function token(): string
    {
        $lifetime = (int) Config::get('security.csrf_lifetime', 7200);
        $issuedAt = (int) ($_SESSION[self::KEY_TIME] ?? 0);
        $token    = $_SESSION[self::KEY_TOKEN] ?? null;

        if (!is_string($token) || $token === '' || (time() - $issuedAt) > $lifetime) {
            self::rotate();
            $token = (string) $_SESSION[self::KEY_TOKEN];
        }

        return $token;
    }

    public static function rotate(): string
    {
        $token = bin2hex(random_bytes(32));

        $_SESSION[self::KEY_TOKEN] = $token;
        $_SESSION[self::KEY_TIME]  = time();

        return $token;
    }

    /**
     * Hidden input ready to embed in a form.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Meta tag for fetch()/XHR requests.
     */
    public static function meta(): string
    {
        return '<meta name="csrf-token" content="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Constant time validation against the token from the request.
     */
    public static function validate(Request $request): bool
    {
        $token = $request->input(self::FIELD_NAME);

        if (!is_string($token) || $token === '') {
            $token = $request->header(self::HEADER_NAME, '');
        }

        if (!is_string($token) || $token === '') {
            return false;
        }

        $known = $_SESSION[self::KEY_TOKEN] ?? '';

        if (!is_string($known) || $known === '') {
            return false;
        }

        $issuedAt = (int) ($_SESSION[self::KEY_TIME] ?? 0);

        if ((time() - $issuedAt) > (int) Config::get('security.csrf_lifetime', 7200)) {
            return false;
        }

        // The token stays valid for its lifetime so that several tabs open
        // at once keep working; it is rotated on login, logout and expiry.
        return hash_equals($known, $token);
    }
}
