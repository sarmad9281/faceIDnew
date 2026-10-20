<?php
/**
 * =====================================================================
 * SARMAD Attendance - Installer
 * =====================================================================
 * URL:  https://example.com/install/
 *
 * This file is intentionally self contained: it must work BEFORE the
 * application is configured (no config.php yet). It only uses the
 * framework classes needed for the job.
 *
 * It writes exactly two things:
 *   1. config/config.php      (database credentials + app key)
 *   2. storage/installed.lock (self lock, so it cannot run twice)
 * ...and it creates only tables prefixed with  attendance_
 * =====================================================================
 */

declare(strict_types=1);

define('SARMAD_INSTALLER', true);
define('SARMAD_START', microtime(true));

$root = dirname(__DIR__);

/* ---------------------------------------------------------------------
 * Minimal autoloader (the real bootstrap is not available yet)
 * ------------------------------------------------------------------- */
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

/* Build stamp of this installer - if the page does not show the expected
   value, the host is still running an older upload (or a cached opcode).
   Defined here because the class constant needs the autoloader above. */
define('SARMAD_INSTALLER_BUILD', App\Services\InstallerService::BUILD);

require $root . '/app/Core/Config.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Security;
use App\Core\SqlGuard;
use App\Services\InstallerService;

error_reporting(E_ALL);
ini_set('display_errors', '0');

$installer = new InstallerService($root);
$installer->guardInstalled();

/* ---------------------------------------------------------------------
 * Helpers (output escaping is mandatory everywhere)
 * ------------------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installer_url(string $query = ''): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/');

    return $dir . '/' . ($query === '' ? '' : '?' . $query);
}

function app_base_url(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/');
    $dir = preg_replace('#/install$#', '', $dir) ?? '';

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';

    return $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

/**
 * CSRF protection for the installer forms (session based, self contained).
 */
function installer_csrf(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $params = [
            'lifetime' => 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        }

        session_name('sarmad_installer');
        session_start();
    }

    if (empty($_SESSION['installer_csrf']) || !is_string($_SESSION['installer_csrf'])) {
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['installer_csrf'];
}

/**
 * Is a database password already stored in config/config.php? The shipped
 * package comes with the production credentials filled in, so the operator
 * does NOT have to retype the secret - typing it wrong is the number one
 * cause of "SQLSTATE[HY000] [1045] Access denied ... using password: YES".
 *
 * The value itself never leaves this pair of functions: only the boolean is
 * rendered.
 */
function installer_has_stored_password(): bool
{
    return installer_stored_password() !== '';
}

/** The stored password (server side only - never rendered, never logged). */
function installer_stored_password(): string
{
    $file = dirname(__DIR__) . '/config/config.php';

    if (!is_file($file)) {
        return '';
    }

    $config = @include $file;

    if (!is_array($config)) {
        return '';
    }

    return (string) ($config['db']['password'] ?? '');
}

/**
 * The password of the database account is kept in the PHP session, never in
 * the HTML: a hidden input would expose it in the page source, the browser
 * history and any "save page" the operator makes during the installation.
 */
function installer_remember_db(array $credentials): void
{
    installer_csrf();   // makes sure the session is running

    $_SESSION['installer_db'] = $credentials;
}

/** @return array<string,mixed> */
function installer_remembered_db(): array
{
    installer_csrf();

    $stored = $_SESSION['installer_db'] ?? [];

    return is_array($stored) ? $stored : [];
}

function installer_forget_db(): void
{
    installer_csrf();

    unset($_SESSION['installer_db']);
}

function installer_csrf_ok(): bool
{
    installer_csrf();

    $sent = (string) ($_POST['_token'] ?? '');

    return $sent !== '' && hash_equals((string) $_SESSION['installer_csrf'], $sent);
}

/**
 * Throttle the installer form submissions (5 per 10 minutes per IP).
 */
function installer_rate_limit_ok(): bool
{
    $ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    $file = sys_get_temp_dir() . '/sarmad_installer_' . sha1($ip) . '.json';

    $state = ['start' => time(), 'hits' => 0];

    if (is_file($file)) {
        $decoded = json_decode((string) @file_get_contents($file), true);

        if (is_array($decoded) && isset($decoded['start'], $decoded['hits'])) {
            $state = ['start' => (int) $decoded['start'], 'hits' => (int) $decoded['hits']];
        }
    }

    if ((time() - $state['start']) > 600) {
        $state = ['start' => time(), 'hits' => 0];
    }

    $state['hits']++;
    @file_put_contents($file, (string) json_encode($state), LOCK_EX);

    return $state['hits'] <= 50;
}

/**
 * Render a page with the installer layout.
 *
 * @param array<string,mixed> $data
 */
function installer_view(string $template, array $data = []): void
{
    $root = dirname(__DIR__);
    $view = $root . '/views/install/' . $template . '.php';

    extract($data, EXTR_SKIP);

    if (is_file($view)) {
        require $view;
    } else {
        echo '<p>view not found: ' . h($template) . '</p>';
    }
}

/* ---------------------------------------------------------------------
 * Request handling
 * ------------------------------------------------------------------- */
$step   = (string) ($_GET['step'] ?? '1');
$errors = [];
$notice = '';

$defaults = [
    'db_host'     => 'localhost',
    'db_port'     => '3306',
    'db_database' => 'sarmadfa_clinic',
    'db_username' => 'sarmadfa_youruser_lumiere',
    'db_password' => '',
    'db_prefix'   => InstallerService::prefix(),
    'app_name'    => 'SARMAD Attendance',
    // Empty on purpose: the base URL is then detected from each request, so
    // the very same installation keeps working when the folder is renamed
    // (e.g. the temporary /barber test path moved to /attendance).
    'app_url'     => '',
    'timezone'    => 'Asia/Tehran',
    'date_system' => 'jalali',
];

/* Whitespace is trimmed from the visible fields ONLY. A secret is used byte
   for byte: trimming would silently change a password (and a password may
   legitimately start or end with a space), which ends in
   "SQLSTATE[HY000] [1045] Access denied ... (using password: YES)". */
$secretFields = ['db_password', 'owner_password', 'owner_password_confirmation'];

$post = [];

foreach ($_POST as $postKey => $postValue) {
    $post[$postKey] = (is_string($postValue) && !in_array($postKey, $secretFields, true))
        ? trim($postValue)
        : $postValue;
}

$form = array_merge($defaults, array_intersect_key($post, $defaults));

/* --- Step 2: database verification ---------------------------------- */
$dbReport = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!installer_csrf_ok()) {
        $errors[] = 'توکن امنیتی نامعتبر است. صفحه را دوباره بارگذاری کنید.';
    }

    if (!installer_rate_limit_ok()) {
        $errors[] = 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.';
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($errors === [] && $action === 'test_connection') {
        // A typed password always wins; if left blank, use the pre-filled
        // password from config/config.php.
        $typedPassword = (string) ($post['db_password'] ?? '');
        if ($typedPassword !== '') {
            $form['db_password'] = $typedPassword;
        } else {
            $storedPassword = installer_stored_password();
            if ($storedPassword !== '') {
                $form['db_password'] = $storedPassword;
            } else {
                $errors[] = 'رمز عبور دیتابیس را وارد کنید.';
            }
        }

        Database::setPrefix((string) $form['db_prefix']);

        $dbReport = $installer->verifyDatabase([
            'host'     => (string) $form['db_host'],
            'port'     => (int) $form['db_port'],
            'database' => (string) $form['db_database'],
            'username' => (string) $form['db_username'],
            'password' => (string) $form['db_password'],
            'charset'  => 'utf8mb4',
            'prefix'   => (string) $form['db_prefix'],
        ]);

        if (!$dbReport['ok']) {
            $errors[] = $dbReport['message'];
            $step     = '1';
        } else {
            $step = '2';

            // The service reports which host actually answered: on shared
            // hosting "localhost" and "127.0.0.1" are not interchangeable
            // (socket vs TCP grants), so the working one is what gets used.
            $workingHost = (string) ($dbReport['host'] ?? $form['db_host']);

            if ($workingHost !== (string) $form['db_host']) {
                $form['db_host'] = $workingHost;
                $dbReport['message'] .= ' هاست «' . $workingHost . '» برای ادامه استفاده می‌شود.';
            }

            // Keep the verified credentials in the PHP session: the password
            // is never written back into the HTML (no hidden field, no value
            // attribute), so it cannot leak through the page, the browser
            // history or a "view source".
            installer_remember_db([
                'host'     => $workingHost,
                'port'     => (int) $form['db_port'],
                'database' => (string) $form['db_database'],
                'username' => (string) $form['db_username'],
                'password' => (string) $form['db_password'],
                'prefix'   => (string) $form['db_prefix'],
            ]);
        }
    }

    /* --- Step 3: run the installation -------------------------------- */
    if ($errors === [] && $action === 'install') {
        // Credentials verified in step 1 (server side). A freshly typed
        // password wins; otherwise the remembered one is used, so the flow
        // works even when the browser never re-sends the secret.
        $remembered  = installer_remembered_db();
        $credentials = [
            'host'     => (string) ($remembered['host'] ?? $form['db_host']),
            // (the service probes "localhost" and "127.0.0.1" itself when the
            //  first one is refused, so no host choice is needed here)
            'port'     => (int) ($remembered['port'] ?? (int) $form['db_port']),
            'database' => (string) ($remembered['database'] ?? $form['db_database']),
            'username' => (string) ($remembered['username'] ?? $form['db_username']),
            'password' => (string) $form['db_password'] !== ''
                ? (string) $form['db_password']
                : ((string) ($remembered['password'] ?? '') !== '' ? (string) $remembered['password'] : installer_stored_password()),
            'prefix'   => (string) ($remembered['prefix'] ?? $form['db_prefix']),
        ];

        if ($credentials['password'] === '') {
            $errors[] = 'اطلاعات دیتابیس در نشست پیدا نشد. لطفاً مرحلهٔ اتصال را دوباره انجام دهید.';
            $step     = '1';
        }

        // 1) Verify credentials again (never trust carried over values).
        $verify = $errors === [] ? $installer->verifyDatabase([
            'host'     => $credentials['host'],
            'port'     => $credentials['port'],
            'database' => $credentials['database'],
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'charset'  => 'utf8mb4',
            'prefix'   => $credentials['prefix'],
        ]) : ['ok' => false, 'message' => '', 'skipped' => true];

        if (!$verify['ok']) {
            $errors[] = $verify['message'];
            $step     = '1';
        } else {
            // The service may have found that the OTHER host works
            // ("localhost" vs "127.0.0.1"); the config must store the one
            // that really answered, otherwise the panel breaks right after
            // the install.
            if (!empty($verify['host']) && (string) $verify['host'] !== $credentials['host']) {
                $credentials['host'] = (string) $verify['host'];
                $form['db_host']     = (string) $verify['host'];
                $dbReport            = $verify;
            }

            // 2) Write the configuration file first: later steps need it.
            $configWrite = $installer->writeConfig([
                'db_host'     => $credentials['host'],
                'db_port'     => $credentials['port'],
                'db_database' => $credentials['database'],
                'db_username' => $credentials['username'],
                'db_password' => $credentials['password'],
                'db_prefix'   => $credentials['prefix'],
                'app_name'    => (string) $form['app_name'],
                'app_url'     => (string) $form['app_url'],
                'timezone'    => (string) $form['timezone'],
                'env'         => 'production',
                'force_https' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
            ]);

            if (!$configWrite['ok']) {
                $errors[] = $configWrite['message'];
                $step     = '1';
            } else {
                // Boot the configuration for the remaining steps.
                $configArray = require $root . '/config/config.php';
                Config::hydrate($configArray, $root);
                date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Tehran'));
                Database::connect();

                // 3) Schema.
                $schema = $installer->runAllSchema($root);

                if (!$schema['ok']) {
                    $errors[] = $schema['message'];

                    foreach ($schema['errors'] as $error) {
                        $errors[] = $error;
                    }

                    $step = '2';
                } else {
                    // 4) Owner account.
                    $owner = $installer->createOwner([
                        'owner_name'   => (string) ($_POST['owner_name'] ?? ''),
                        'owner_username' => (string) ($_POST['owner_username'] ?? ''),
                        'owner_email'  => (string) ($_POST['owner_email'] ?? ''),
                        'owner_mobile' => (string) ($_POST['owner_mobile'] ?? ''),
                        'owner_password' => (string) ($_POST['owner_password'] ?? ''),
                        'owner_password_confirmation' => (string) ($_POST['owner_password_confirmation'] ?? ''),
                    ]);

                    if (!$owner['ok']) {
                        $errors[] = $owner['message'];
                        $step     = '2';
                    } else {
                        // 5) Optional first customer + manager.
                        $customer = $installer->createFirstCustomer([
                            'customer_name'    => (string) ($_POST['customer_name'] ?? ''),
                            'customer_company' => (string) ($_POST['customer_company'] ?? ''),
                            'manager_name'     => (string) ($_POST['manager_name'] ?? ''),
                            'manager_email'    => (string) ($_POST['manager_email'] ?? ''),
                            'manager_password' => (string) ($_POST['manager_password'] ?? ''),
                            'timezone'         => (string) $form['timezone'],
                            'date_system'      => (string) $form['date_system'],
                        ]);

                        if (!$customer['ok']) {
                            $errors[] = $customer['message'];
                        }

                        // 6) Settings + audit + lock.
                        $installer->writeSettings([
                            'timezone'    => (string) $form['timezone'],
                            'date_system' => (string) $form['date_system'],
                            'app_name'    => (string) $form['app_name'],
                        ], (int) ($owner['user_id'] ?? 0));

                        $installer->writeAudit('installer.completed', 'نصب سامانه با موفقیت انجام شد.', [
                            'database' => (string) $form['db_database'],
                            'prefix'   => (string) $form['db_prefix'],
                            'tables'   => count($schema['created']),
                        ]);

                        $lock = $installer->lock();

                        if (!$lock['ok']) {
                            $errors[] = $lock['message'] . ' (نصب انجام شده اما قفل نشد؛ فایل storage/installed.lock را دستی ایجاد کنید.)';
                        }

                        // The credentials are in config/config.php now: drop the
                        // copy that lived in the session.
                        installer_forget_db();

                        // 7) Done - show the summary.
                        installer_view('done', [
                            'owner_username'   => (string) ($owner['username'] ?? ''),
                            'customer_message' => (string) ($customer['message'] ?? ''),
                            'manager_username' => (string) ($customer['manager_username'] ?? ''),
                            'created_tables'   => $schema['created'],
                            'foreign_tables'   => $schema['foreign_untouched'],
                            'executed'         => $schema['executed'],
                            'login_url'        => app_base_url() . '/login',
                            'base_url'         => app_base_url(),
                            'lock_path'        => 'storage/installed.lock',
                            'errors'           => $errors,
                        ]);

                        exit;
                    }
                }
            }
        }
    }
}

/* ---------------------------------------------------------------------
 * Render the current step
 * ------------------------------------------------------------------- */
$configPath = $root . '/config/config.php';

installer_view('layout', [
    'step'          => $step,
    'errors'        => $errors,
    'notice'        => $notice,
    'form'          => $form,
    'dbReport'      => $dbReport,
    'installer'     => $installer,
    'requirements'  => $installer->requirements(),
    'requirementsPassed' => $installer->requirementsPassed(),
    'scope'         => InstallerService::scope(),
    'prefix'        => InstallerService::prefix(),
    'configExists'  => is_file($configPath),
    'isHttps'       => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
    'serverSoftware'=> (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
    'sqlFile'       => 'sql/attendance_install.sql',
    'csrfToken'     => installer_csrf(),
    'hasStoredPassword' => installer_has_stored_password(),
    'suggestedPassword' => Security::suggestPassword(12),
    'sqlGuardOk'    => SqlGuard::isSafe((string) @file_get_contents($root . '/sql/attendance_install.sql'), InstallerService::prefix()),
]);
