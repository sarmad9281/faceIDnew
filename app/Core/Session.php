<?php
/**
 * SARMAD Attendance - Secure session handling.
 */

declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static bool $started = false;

    private const KEY_AUTH         = 'auth_user';
    private const KEY_DEVICE       = 'auth_device';
    private const KEY_ACTOR        = 'actor';
    private const KEY_FLASH        = 'flash';
    private const KEY_STARTED_AT   = 'session_started_at';
    private const KEY_LAST_SEEN_AT = 'session_last_seen_at';
    private const KEY_LAST_ROTATE  = 'session_last_rotate';
    private const KEY_BIND_UA      = 'session_bind_ua';
    private const KEY_BIND_IP      = 'session_bind_ip';
    private const KEY_IMPERSONATE  = 'app_impersonation';

    /* ================================================================= */

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        if (headers_sent()) {
            return;
        }

        $lifetime = (int) Config::get('session.lifetime', 3600);
        $secure   = Config::get('session.secure');
        $secure   = $secure === null ? Security::isHttps() : (bool) $secure;

        // Keep session data inside the project's protected storage folder.
        $savePath = Config::path('storage/tmp/sessions');
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0700, true);
        }
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        session_name((string) Config::get('session.name', 'sarmad_att_sid'));

        $params = [
            'lifetime' => $lifetime,
            'path'     => (string) Config::get('session.path', '/'),
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => (string) Config::get('session.samesite', 'Lax'),
        ];

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            session_set_cookie_params(
                $params['lifetime'],
                $params['path'] . '; samesite=' . $params['samesite'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Reject uninitialised session ids supplied by the client.
        // Values are applied only when the running PHP still supports them
        // (session.sid_length became deprecated in PHP 8.4).
        self::iniSet('session.use_strict_mode', '1');
        self::iniSet('session.use_only_cookies', '1');
        self::iniSet('session.use_trans_sid', '0');
        self::iniSet('session.cookie_httponly', '1');
        self::iniSet('session.gc_maxlifetime', (string) max($lifetime, (int) Config::get('session.absolute_lifetime', 43200)));
        self::iniSet('session.sid_bits_per_character', '5');

        if (PHP_VERSION_ID < 80400) {
            self::iniSet('session.sid_length', '48');
        }

        session_start();
        self::$started = true;

        self::fingerprintGuard();
        self::lifetimeGuard();
    }

    /* =================================================================
     * Hardening
     * ===============================================================*/

    /**
     * Bind the session to the browser fingerprint (protects against a
     * stolen cookie being replayed from another client).
     */
    private static function fingerprintGuard(): void
    {
        if (!isset($_SESSION[self::KEY_STARTED_AT])) {
            $_SESSION[self::KEY_STARTED_AT]   = time();
            $_SESSION[self::KEY_LAST_SEEN_AT] = time();
            $_SESSION[self::KEY_LAST_ROTATE]  = time();
            $_SESSION[self::KEY_BIND_UA]      = self::fingerprint();
            $_SESSION[self::KEY_BIND_IP]      = self::ipClass();

            // A brand new session id for a brand new visitor.
            session_regenerate_id(true);

            return;
        }

        $_SESSION[self::KEY_LAST_SEEN_AT] = time();

        if ((bool) Config::get('session.bind_user_agent', true)
            && !hash_equals((string) $_SESSION[self::KEY_BIND_UA], self::fingerprint())
        ) {
            Logger::channel('security')->warning('Session fingerprint mismatch (user agent)', [
                'actor' => self::actorLabel(),
            ]);
            self::destroy();
            self::start();
            self::flash('error', 'نشست شما به دلیل تغییر غیرمنتظره در مرورگر باطل شد. لطفاً دوباره وارد شوید.');

            return;
        }

        if ((bool) Config::get('session.bind_ip_class', false)
            && !hash_equals((string) $_SESSION[self::KEY_BIND_IP], self::ipClass())
        ) {
            Logger::channel('security')->warning('Session fingerprint mismatch (ip)', [
                'actor' => self::actorLabel(),
            ]);
            self::destroy();
            self::start();
            self::flash('error', 'نشست شما باطل شد. لطفاً دوباره وارد شوید.');
        }
    }

    private static function lifetimeGuard(): void
    {
        $now      = time();
        $started  = (int) ($_SESSION[self::KEY_STARTED_AT] ?? $now);
        $lastSeen = (int) ($_SESSION[self::KEY_LAST_SEEN_AT] ?? $now);

        $idleLimit     = (int) Config::get('session.lifetime', 3600);
        $absoluteLimit = (int) Config::get('session.absolute_lifetime', 43200);

        if (($now - $lastSeen) > $idleLimit || ($now - $started) > $absoluteLimit) {
            $wasLoggedIn = self::isAuthenticated();
            self::destroy();
            self::start();
            self::flash('error', 'نشست شما منقضی شد. لطفاً دوباره وارد شوید.');

            if ($wasLoggedIn) {
                Logger::channel('auth')->info('Session expired', ['actor' => 'system']);
            }

            return;
        }

        self::rotate($now);
    }

    /**
     * Periodic session id rotation (session fixation / hijacking defence).
     */
    public static function rotate(?int $now = null): void
    {
        $now      = $now ?? time();
        $every    = (int) Config::get('session.rotate_every', 900);
        $lastTurn = (int) ($_SESSION[self::KEY_LAST_ROTATE] ?? 0);

        if ($every <= 0 || ($now - $lastTurn) < $every) {
            return;
        }

        session_regenerate_id(true);
        $_SESSION[self::KEY_LAST_ROTATE] = $now;
        $_SESSION[self::KEY_BIND_UA]     = self::fingerprint();
        $_SESSION[self::KEY_BIND_IP]     = self::ipClass();
    }

    /**
     * Called right after a successful login.
     */
    public static function regenerateForLogin(): void
    {
        session_regenerate_id(true);
        $_SESSION[self::KEY_STARTED_AT]  = time();
        $_SESSION[self::KEY_LAST_SEEN_AT] = time();
        $_SESSION[self::KEY_LAST_ROTATE] = time();
        $_SESSION[self::KEY_BIND_UA]     = self::fingerprint();
        $_SESSION[self::KEY_BIND_IP]     = self::ipClass();
        Csrf::rotate();
    }

    /* =================================================================
     * Auth payloads
     * ===============================================================*/

    /**
     * @param array<string,mixed> $user
     */
    public static function loginUser(array $user): void
    {
        $_SESSION[self::KEY_AUTH]  = $user;
        $_SESSION[self::KEY_ACTOR] = [
            'type' => 'user',
            'role' => (string) ($user['role'] ?? ''),
            'id'   => (int) ($user['id'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $device
     */
    public static function loginDevice(array $device): void
    {
        $_SESSION[self::KEY_DEVICE] = $device;
        $_SESSION[self::KEY_ACTOR]  = [
            'type' => 'device',
            'id'   => (int) ($device['id'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        $user = $_SESSION[self::KEY_AUTH] ?? null;

        return is_array($user) ? $user : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function device(): ?array
    {
        $device = $_SESSION[self::KEY_DEVICE] ?? null;

        return is_array($device) ? $device : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function actor(): ?array
    {
        $actor = $_SESSION[self::KEY_ACTOR] ?? null;

        return is_array($actor) ? $actor : null;
    }

    public static function actorLabel(): string
    {
        $actor = self::actor();

        if ($actor === null) {
            return 'guest';
        }

        if (($actor['type'] ?? '') === 'device') {
            return 'device#' . (int) $actor['id'];
        }

        $user = self::user();

        return 'user#' . (int) ($actor['id'] ?? 0)
            . '(' . (string) ($user['role'] ?? $actor['role'] ?? 'unknown') . ')';
    }

    public static function isAuthenticated(): bool
    {
        return self::user() !== null || self::device() !== null;
    }

    public static function isUser(): bool
    {
        return self::user() !== null;
    }

    public static function isDevice(): bool
    {
        return self::device() !== null;
    }

    public static function userId(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function role(): ?string
    {
        $user = self::user();

        if ($user !== null && !empty($user['role'])) {
            return (string) $user['role'];
        }

        return self::isDevice() ? 'device' : null;
    }

    /**
     * Emergency access payload (owner viewing a customer panel), if any.
     *
     * @return array<string,mixed>|null
     */
    public static function impersonation(): ?array
    {
        $payload = $_SESSION[self::KEY_IMPERSONATE] ?? null;

        return is_array($payload) && !empty($payload['id']) ? $payload : null;
    }

    public static function impersonating(): bool
    {
        return self::impersonation() !== null;
    }

    /**
     * The role the panel should treat the session as: during emergency
     * access the owner is acting as the customer manager.
     */
    public static function effectiveRole(): ?string
    {
        return self::impersonating() ? 'manager' : self::role();
    }

    public static function customerId(): ?int
    {
        $impersonation = self::impersonation();

        if ($impersonation !== null && !empty($impersonation['customer_id'])) {
            return (int) $impersonation['customer_id'];
        }

        $user = self::user();

        if ($user !== null && isset($user['customer_id']) && $user['customer_id'] !== null) {
            return (int) $user['customer_id'];
        }

        $device = self::device();

        if ($device !== null && isset($device['customer_id'])) {
            return (int) $device['customer_id'];
        }

        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => (string) Config::get('session.samesite', 'Lax'),
            ]);
        }

        session_destroy();
        session_start();
        session_regenerate_id(true);
        self::$started = true;
        Csrf::rotate();
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Touch the session (keeps it alive for the device heartbeat).
     */
    public static function keepAlive(): void
    {
        $_SESSION[self::KEY_LAST_SEEN_AT] = time();
    }

    /* =================================================================
     * Flash messages
     * ===============================================================*/

    public static function flash(string $key, string $message): void
    {
        $_SESSION[self::KEY_FLASH][$key] = $message;
    }

    public static function getFlash(string $key): ?string
    {
        $message = $_SESSION[self::KEY_FLASH][$key] ?? null;

        if ($message !== null) {
            unset($_SESSION[self::KEY_FLASH][$key]);
        }

        return is_string($message) ? $message : null;
    }

    /* =================================================================
     * Misc
     * ===============================================================*/

    /**
     * @param mixed $value
     */
    public static function put(string $key, $value): void
    {
        $_SESSION['app_' . $key] = $value;
    }

    /**
     * @return mixed
     */
    public static function pull(string $key, $default = null)
    {
        $storageKey = 'app_' . $key;
        $value      = $_SESSION[$storageKey] ?? $default;

        unset($_SESSION[$storageKey]);

        return $value;
    }

    /**
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return $_SESSION['app_' . $key] ?? $default;
    }

    public static function id(): string
    {
        return session_id() ?: '';
    }

    /**
     * Set a session ini value without ever turning a deprecation notice
     * into a fatal error (PHP 8.4 deprecates several session settings).
     */
    private static function iniSet(string $key, string $value): void
    {
        $current = ini_get($key);

        if ($current !== false && (string) $current === $value) {
            return;
        }

        @ini_set($key, $value);
    }

    private static function fingerprint(): string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return hash_hmac('sha256', (string) $agent, Config::get('app.key', 'sarmad'));
    }

    private static function ipClass(): string
    {
        $ip = Request::ip();

        if (strpos($ip, ':') !== false) {
            // IPv6 -> first four groups
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 4));
        }

        $parts = explode('.', $ip);

        return implode('.', array_slice($parts, 0, 2));
    }
}
