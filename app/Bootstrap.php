<?php
/**
 * SARMAD Attendance - Application bootstrap.
 */

declare(strict_types=1);

namespace App;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Redirect;
use App\Core\Request;
use App\Core\Router;
use App\Core\Security;
use App\Core\Session;
use App\Models\Setting;
use App\Support\Url;
use Throwable;

final class Bootstrap
{
    private static bool $booted = false;

    /**
     * Entry point called by index.php
     */
    public static function run(): void
    {
        self::registerAutoloader();
        self::registerErrorHandlers();

        $installed = self::loadConfig();

        if (!$installed) {
            // Nothing configured yet -> send the visitor to the installer.
            if (self::isInstallerRequest()) {
                require SARMAD_ROOT . '/install/index.php';
                return;
            }
            Redirect::to(Url::base() . '/install/');
        }

        self::boot();
        self::dispatch();
    }

    /* =================================================================
     * Boot steps
     * ===============================================================*/

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        Security::sendHeaders();

        try {
            Database::connect();
        } catch (Throwable $e) {
            Logger::channel('database')->error('Database connection failed', [
                'exception' => $e->getMessage(),
            ]);
            self::renderFatal('اتصال به دیتابیس برقرار نشد. لطفاً تنظیمات فایل config/config.php را بررسی کنید.');
        }

        Session::start();

        // config.php exists but the schema does not: the installation was
        // interrupted. Say so instead of showing an "unexpected error" page.
        // The lock file check keeps this free of extra queries on a healthy install.
        if (!is_file(SARMAD_ROOT . '/storage/installed.lock') && !Database::tableExists('settings')) {
            self::renderFatal(
                'ساختار دیتابیس کامل نیست و نصب نیمه‌کاره مانده است. '
                . 'لطفاً نصب‌کننده را از مسیر /install/ دوباره اجرا کنید '
                . 'یا در خط فرمان دستور php tools/migrate.php migrate را اجرا نمایید.'
            );
        }

        if ((bool) Config::get('log.db_audit', true)) {
            Setting::loadOverrides();
        }

        self::$booted = true;
    }

    /**
     * CLI bootstrap (tools/migrate.php and any future console command).
     *
     * Registers the autoloader, loads the configuration and opens the
     * database connection - but never starts a session, never reads the
     * request and never dispatches a route.
     *
     * @return bool true when the application is installed and the DB is reachable.
     */
    public static function bootCli(): bool
    {
        self::registerAutoloader();
        self::registerErrorHandlers();

        if (!self::loadConfig()) {
            return false;
        }

        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        try {
            Database::connect();
        } catch (Throwable $e) {
            Logger::channel('database')->error('CLI database connection failed', ['exception' => $e->getMessage()]);

            return false;
        }

        if ((bool) Config::get('log.db_audit', true)) {
            Setting::loadOverrides();
        }

        return true;
    }

    /**
     * Load config/config.php. Returns false when the app is not installed.
     */
    public static function loadConfig(): bool
    {
        $file = SARMAD_ROOT . '/config/config.php';

        if (!is_file($file)) {
            return false;
        }

        /** @var array $config */
        $config = require $file;

        if (!is_array($config) || !isset($config['db']['password'], $config['app']['key'])) {
            return false;
        }

        if (empty($config['installed']) || empty($config['db']['password'])) {
            return false;
        }

        Config::hydrate($config, SARMAD_ROOT);

        return true;
    }

    private static function dispatch(): void
    {
        $router = new Router();

        $registerWeb = require SARMAD_ROOT . '/routes/web.php';
        $registerApi = require SARMAD_ROOT . '/routes/api.php';

        $registerWeb($router);
        $registerApi($router);

        $request = Request::capture();

        try {
            $router->dispatch($request);
        } catch (Throwable $e) {
            Logger::channel('app')->error('Unhandled exception', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'uri'       => $request->uri(),
            ]);

            if ($request->isApi()) {
                \App\Api\ApiResponse::error('server_error', 'خطای غیرمنتظره سرور.', [], 500);
            }

            if ((bool) Config::get('app.debug', false)) {
                self::renderFatal(
                    '<pre style="white-space:pre-wrap;direction:ltr;text-align:left">'
                    . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8')
                    . '</pre>'
                );
            }

            self::renderFatal('خطای غیرمنتظره سرور رخ داد. مورد در فایل‌های لاگ ثبت شد.');
        }
    }

    /* =================================================================
     * Infrastructure
     * ===============================================================*/

    private static function isInstallerRequest(): bool
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return (bool) preg_match('#/install(/|$)#', (string) $uri);
    }

    private static function registerAutoloader(): void
    {
        // Global helpers (feature_enabled()) are needed by the web surface and
        // by every CLI tool, so they are loaded with the autoloader.
        $helpers = SARMAD_ROOT . '/app/Support/helpers.php';

        if (is_file($helpers)) {
            require_once $helpers;
        }

        // Third party libraries (currently PhpSpreadsheet, vendored by
        // Composer so shared hosting without a Composer binary still works).
        // The project's own classes stay on the small autoloader below, so a
        // missing vendor directory never breaks the rest of the application -
        // only the Excel export tells the operator what is missing.
        $composer = SARMAD_ROOT . '/vendor/autoload.php';

        if (is_file($composer)) {
            require_once $composer;
        }

        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\';

            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file     = SARMAD_ROOT . '/app/' . $relative . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }

    private static function registerErrorHandlers(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', SARMAD_ROOT . '/storage/logs/php-error.log');

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false; // suppressed with @ or excluded by error_reporting()
            }

            // Deprecations caused by PHP version changes must never take
            // the application down on shared hosting (PHP 8.1 -> 8.4).
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                Logger::channel('app')->debug('PHP deprecation', [
                    'message' => $message,
                    'file'    => $file . ':' . $line,
                ]);

                return true;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            Logger::channel('app')->error('Uncaught exception', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            self::renderFatal('خطای غیرمنتظره سرور رخ داد.');
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Logger::channel('app')->critical('Fatal error', [
                'message' => $error['message'],
                'file'    => $error['file'] . ':' . $error['line'],
            ]);
        });

        // Make sure our log directory exists before the first error happens.
        if (is_dir(SARMAD_ROOT . '/storage/logs') === false) {
            @mkdir(SARMAD_ROOT . '/storage/logs', 0755, true);
        }
    }

    private static function renderFatal(string $message): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<title>خطای سیستم - SARMAD Attendance</title>'
            . '<style>body{font-family:Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;'
            . 'align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . '.box{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:32px 36px;max-width:640px}'
            . 'h1{font-size:18px;margin:0 0 12px}p{line-height:1.9;font-size:14px;margin:0;color:#cbd5e1}</style>'
            . '</head><body><div class="box"><h1>SARMAD Attendance</h1><p>' . $safe . '</p></div></body></html>';

        exit;
    }
}
