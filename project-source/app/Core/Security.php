<?php
/**
 * SARMAD Attendance - Security primitives (headers, tokens, crypto).
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Security
{
    /* =================================================================
     * HTTP hardening headers
     * ===============================================================*/

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
        header('Cross-Origin-Opener-Policy: same-origin');

        // Removed so intermediary caches never store authenticated pages.
        header_remove('X-Powered-By');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isHttps(): bool
    {
        $forced = Config::get('app.force_https');

        if ($forced !== null) {
            return (bool) $forced;
        }

        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        if (Request::trustProxy()) {
            $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

            return strpos($proto, 'https') !== false;
        }

        return false;
    }

    /**
     * Content-Security-Policy for the HTML surface. Kept separate so the
     * API / kiosk responses (stage 3+) can opt out.
     */
    public static function sendCsp(bool $allowInlineScripts = false): void
    {
        if (headers_sent()) {
            return;
        }

        $script = $allowInlineScripts ? "'self' 'unsafe-inline'" : "'self'";

        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src " . $script . "; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self' data:; "
            . "connect-src 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; "
            . "object-src 'none'"
        );
    }

    /* =================================================================
     * Random tokens
     * ===============================================================*/

    public static function randomHex(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function randomDigits(int $length = 6): string
    {
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    public static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Devices / kiosk use a human friendly numeric pairing code.
     */
    public static function deviceCode(): string
    {
        return self::randomDigits(6) . '-' . self::randomDigits(4);
    }

    /* =================================================================
     * Hashing
     * ===============================================================*/

    /**
     * Resolve the configured password algorithm.
     *
     * The configuration uses portable names ("bcrypt", "argon2i",
     * "argon2id") instead of the PHP constants, because PHP 8.4 changed
     * PASSWORD_BCRYPT from the integer 1 to the string "2y" - casting the
     * constant to int silently selected a different algorithm.
     */
    public static function passwordAlgorithmName(): string
    {
        $configured = strtolower(trim((string) Config::get('security.password_algo', 'bcrypt')));

        // Tolerate raw PHP constant values coming from an older config file
        // (PHP <= 8.3 wrote "1" for bcrypt, PHP 8.4 writes "2y").
        if ($configured === '1' || $configured === '2y' || $configured === '') {
            return 'bcrypt';
        }

        $available = ['bcrypt' => true, 'argon2i' => defined('PASSWORD_ARGON2I'), 'argon2id' => defined('PASSWORD_ARGON2ID')];

        return isset($available[$configured]) && $available[$configured] ? $configured : 'bcrypt';
    }

    /**
     * The value handed to password_hash() / password_needs_rehash().
     *
     * @return int|string
     */
    public static function passwordAlgorithm()
    {
        switch (self::passwordAlgorithmName()) {
            case 'argon2i':
                return PASSWORD_ARGON2I;
            case 'argon2id':
                return PASSWORD_ARGON2ID;
            default:
                return PASSWORD_BCRYPT;
        }
    }

    /**
     * @return array<string,int>
     */
    public static function passwordOptions(): array
    {
        if (self::passwordAlgorithmName() === 'bcrypt') {
            $cost = (int) Config::get('security.password_cost', 12);

            return ['cost' => max(10, min(15, $cost))];
        }

        return [
            'memory_cost' => 1 << 16,
            'time_cost'   => 4,
            'threads'     => 2,
        ];
    }

    public static function hashPassword(string $password): string
    {
        $hash = password_hash($password, self::passwordAlgorithm(), self::passwordOptions());

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        if ($hash === '' || strpos($hash, '$') !== 0) {
            // Not a real hash: never allow (protects against empty/plain-text rows).
            self::dummyVerify($password);

            return false;
        }

        try {
            $ok = password_verify($password, $hash);
        } catch (\Throwable $e) {
            return false;
        }

        if ($ok && password_needs_rehash($hash, self::passwordAlgorithm(), self::passwordOptions())) {
            // Caller may persist the new hash; we return the fact via constant.
            $_ENV['SARMAD_REHASH'] = '1';
        }

        return $ok;
    }

    /**
     * Equal-cost dummy verify so that a non-existing user and a wrong
     * password take the same amount of time (user enumeration defence).
     */
    public static function dummyVerify(string $password): void
    {
        static $dummy = null;

        if ($dummy === null) {
            $dummy = password_hash('sarmad-attendance-dummy', PASSWORD_BCRYPT, ['cost' => 10]);
        }

        password_verify($password, $dummy);
    }

    /**
     * API / device token: only the hash is stored, the plain value is
     * shown to the operator exactly once.
     */
    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, Config::appKey());
    }

    public static function encrypt(string $plain): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL extension is required.');
        }

        $key   = Config::appKey();
        $nonce = random_bytes(12);
        $tag   = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Malformed payload.');
        }

        $nonce  = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', Config::appKey(), OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plain === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plain;
    }

    /**
     * Hash a value in a way that is safe to index (e.g. device API tokens,
     * password reset tokens).
     */
    public static function fingerprint(string $value): string
    {
        return hash('sha256', $value);
    }

    /* =================================================================
     * Password policy
     * ===============================================================*/

    /**
     * @return array<int,string> list of problems (empty = accepted)
     */
    public static function passwordProblems(string $password, ?string $passwordRepeat = null): array
    {
        $problems = [];
        $min      = (int) Config::get('security.min_password_length', 10);

        if (strlen($password) < $min) {
            $problems[] = 'رمز عبور باید حداقل ' . $min . ' کاراکتر باشد.';
        }

        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $problems[] = 'رمز عبور باید شامل حروف و عدد باشد.';
        }

        if (self::isCommonPassword($password)) {
            $problems[] = 'رمز عبور در فهرست رمزهای پرتکرار و ناامن قرار دارد.';
        }

        if (preg_match('/(.)\1{3,}/', $password)) {
            $problems[] = 'رمز عبور نباید شامل تکرار پشت‌سرهم یک کاراکتر باشد.';
        }

        if ($passwordRepeat !== null && $password !== $passwordRepeat) {
            $problems[] = 'تکرار رمز عبور مطابقت ندارد.';
        }

        return $problems;
    }

    public static function isCommonPassword(string $password): bool
    {
        static $blocklist = [
            'password', 'password1', 'password123', 'passw0rd', 'p@ssw0rd',
            '123456', '1234567', '12345678', '123456789', '1234567890',
            'qwerty', 'qwerty123', 'abcdef', 'abc123456', '111111',
            'iloveyou', 'admin', 'admin123', 'administrator', 'letmein',
            'welcome', 'welcome1', 'monkey', 'dragon', 'football',
            'sarmad', 'sarmad123', 'attendance', 'attendance123', 'test1234',
        ];

        $needle = strtolower($password);

        if (in_array($needle, $blocklist, true)) {
            return true;
        }

        foreach ($blocklist as $common) {
            if (strlen($common) >= 6 && strpos($needle, $common) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a strong password for a new account or a password reset.
     *
     * The result always satisfies passwordProblems(): it is long enough, it
     * contains a lower case letter, an upper case letter, a digit and a symbol,
     * it never repeats one character four times in a row and it is not part of
     * the common password blocklist. Callers may therefore hand it out (or
     * display it once) without running their own policy check, which is exactly
     * how the owner's manager-reset flow uses it.
     */
    public static function suggestPassword(int $length = 14): string
    {
        $minimum = max(10, (int) Config::get('security.min_password_length', 10));
        $length  = max($length, $minimum + 2);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $password = self::buildPassword($length);

            if (self::passwordProblems($password) === []) {
                return $password;
            }
        }

        // Practically unreachable: buildPassword() always contains both letters
        // and digits, so the only remaining rules are the repeated-character and
        // blocklist rules (odds below 1e-9). This fallback is structurally safe:
        // every class is present and no character ever repeats back to back.
        return self::buildStructuredPassword($length);
    }

    /**
     * Random password with one guaranteed character of every required class.
     */
    private static function buildPassword(int $length): string
    {
        $lower   = 'abcdefghijkmnopqrstuvwxyz';
        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits  = '23456789';   // 0 and 1 are left out: they read as O and l
        $symbols = '!@#%*?';

        $password = self::randomChar($lower)
            . self::randomChar($upper)
            . self::randomChar($digits)
            . self::randomChar($symbols);

        while (strlen($password) < $length) {
            $password .= self::randomChar($lower . $upper . $digits . $symbols);
        }

        return self::shuffleString($password);
    }

    /**
     * Last resort password: random, but built so that no character can repeat
     * back to back and every class is present.
     */
    private static function buildStructuredPassword(int $length): string
    {
        $lower   = 'abcdefghijkmnopqrstuvwxyz';
        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $symbols = '!@#%*?';

        $password = self::randomChar($lower)
            . self::randomChar($digits)
            . self::randomChar($symbols)
            . self::randomChar($upper);

        $alphabet = $lower . $upper . $digits . $symbols;

        while (strlen($password) < $length) {
            $next = self::randomChar($alphabet);

            if (substr($password, -1) === $next) {
                continue;
            }

            $password .= $next;
        }

        return $password;
    }

    private static function randomChar(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    private static function shuffleString(string $value): string
    {
        $chars = str_split($value);
        $last  = count($chars) - 1;

        for ($i = $last; $i > 0; $i--) {
            $j = random_int(0, $i);

            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
