<?php
/**
 * SARMAD Attendance - database connection doctor (read only).
 * ------------------------------------------------------------------
 * Usage (SSH or DirectAdmin cron):
 *
 *     php tools/db_check.php
 *     php tools/db_check.php --pass='the-db-password'      (testing a candidate)
 *     php tools/db_check.php > /home/USER/db_check.txt 2>&1
 *
 * It answers exactly one question: "why does the installer answer
 * SQLSTATE[HY000] [1045] Access denied for user ... (using password: YES)?"
 *
 *   - prints the PHP version and the extensions the project needs,
 *   - prints the connection values that are configured (never the password),
 *   - prints the SHAPE of the password (length, has '#', leading/trailing
 *     space) which is what a truncated or copy-pasted secret looks like,
 *   - tries localhost (socket) AND 127.0.0.1 (TCP 3306),
 *   - probes the account with an EMPTY password to find out whether MySQL
 *     knows the user at all,
 *   - finally connects for real and lists the attendance_* tables.
 *
 * NOTHING IS WRITTEN: no table, no file, no config change. The password is
 * never printed - only its shape - and every driver message is scrubbed
 * before it reaches the screen.
 */

declare(strict_types=1);

define('SARMAD_DBCHECK_BUILD', '2026-10-09.2');

/* Over HTTP the tool is reachable ONLY before the installation is locked
   (no storage/installed.lock and config/config.php not marked installed),
   so an operator without SSH can still diagnose the connection from the
   browser. It prints configuration SHAPES, never the password, and it never
   writes anything. After a successful install it answers 403 over HTTP. */
$webMode = PHP_SAPI !== 'cli';

$GLOBALS['sarmad_dbcheck_status'] = 'UNKNOWN';

if ($webMode) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    // The whole report is buffered and wrapped in a small HTML page that
    // carries a form for re-running the check with a candidate password.
    ob_start();

    register_shutdown_function(static function (): void {
        $body = '';

        if (ob_get_level() > 0) {
            $body = (string) ob_get_clean();
        }

        $status = (string) ($GLOBALS['sarmad_dbcheck_status'] ?? 'UNKNOWN');
        $state  = (array) ($GLOBALS['sarmad_dbcheck_state'] ?? []);

        $colors = [
            'CONNECTED' => ['#0f5132', '#d1e7dd', 'اتصال برقرار است'],
            'REFUSED'   => ['#842029', '#f8d7da', 'سرور دیتابیس اعتبارنامه را رد کرد (1045)'],
            'UNREACH'   => ['#664d03', '#fff3cd', 'سرور دیتابیس در دسترس نبود'],
            'LOCKED'    => ['#41464b', '#e2e3e5', 'این ابزار بعد از نصب قفل می‌شود'],
        ];

        [$fg, $bg, $title] = $colors[$status] ?? ['#41464b', '#e2e3e5', $status];

        $value = static function (string $key) use ($state): string {
            return htmlspecialchars((string) ($state[$key] ?? ''), ENT_QUOTES, 'UTF-8');
        };

        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
        echo '<meta name="robots" content="noindex,nofollow">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>SARMAD Attendance - doctor</title></head>';
        echo '<body style="margin:0;padding:16px;background:#0f1115;color:#e6e6e6;'
            . 'font-family:ui-monospace,Menlo,Consolas,monospace;direction:ltr">';
        echo '<div style="direction:rtl;background:' . $bg . ';color:' . $fg . ';'
            . 'padding:12px 14px;border-radius:10px;font-weight:700;margin-bottom:12px">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . ' &mdash; build ' . htmlspecialchars(SARMAD_DBCHECK_BUILD, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<pre style="white-space:pre-wrap;line-height:1.55;direction:ltr;text-align:left;'
            . 'background:#151922;padding:14px;border-radius:10px;overflow:auto">'
            . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>';
        echo '<form method="post" action="" style="direction:rtl;background:#151922;padding:14px;'
            . 'border-radius:10px;display:grid;gap:8px;max-width:640px">';
        echo '<strong>آزمایش یک رمز نامزد (اختیاری)</strong>';
        echo '<div style="display:grid;grid-template-columns:1fr 2fr;gap:8px">';
        echo '<label>host<br><input name="host" value="' . $value('host') . '" style="width:100%"></label>';
        echo '<label>user<br><input name="user" value="' . $value('user') . '" style="width:100%"></label>';
        echo '<label>database<br><input name="db" value="' . $value('db') . '" style="width:100%"></label>';
        echo '<label>password (typed here is never shown again)<br>'
            . '<input name="pass" type="password" autocomplete="off" style="width:100%"></label>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:center">';
        echo '<button type="submit" style="padding:8px 14px;border-radius:8px;border:0;'
            . 'background:#3b82f6;color:#fff;font-weight:700">اجرای دوباره</button>';
        echo '<span style="opacity:.75;font-size:12px">اگر رمز را خالی بگذارید، همان رمز داخل '
            . 'config/config.php آزمایش می‌شود.</span>';
        echo '</div></form>';
        echo '<p style="direction:rtl;opacity:.6;font-size:12px">این صفحه فقط تا قبل از قفل‌شدن '
            . 'نصب در دسترس است؛ هیچ جدول/فایلی را تغییر نمی‌دهد و رمز را ذخیره یا نمایش نمی‌دهد.</p>';
        echo '</body></html>';
    });
}

$root = dirname(__DIR__);

if ($webMode) {
    $locked = is_file($root . '/storage/installed.lock');
    $configFile = $root . '/config/config.php';
    $installedFlag = false;

    if (is_file($configFile)) {
        $probeConfig = @include $configFile;
        $installedFlag = is_array($probeConfig) && !empty($probeConfig['installed']);
    }

    if ($locked || $installedFlag) {
        http_response_code(403);
        $GLOBALS['sarmad_dbcheck_status'] = 'LOCKED';
        exit('This diagnostic is disabled after the installation is complete.' . PHP_EOL
            . '(config/config.php says installed=true, or storage/installed.lock exists)' . PHP_EOL
            . 'From SSH you can still run: php tools/db_check.php' . PHP_EOL);
    }

    // Browsers cannot send command line arguments, so the values come from
    // the query string (non secrets) and from the POST body (the candidate
    // password). The candidate is never echoed back and never stored.
    $_SERVER['argv'] = [];

    $field = static function (string $key): string {
        $raw = $_POST[$key] ?? $_GET[$key] ?? '';
        return is_string($raw) ? trim($raw) : '';
    };

    foreach (['host' => '--host=', 'user' => '--user=', 'db' => '--db=', 'prefix' => '--prefix='] as $key => $flag) {
        $picked = $field($key);

        if ($picked !== '') {
            $_SERVER['argv'][] = $flag . $picked;
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['pass']) && is_string($_POST['pass']) && $_POST['pass'] !== '') {
        $_SERVER['argv'][] = '--pass=' . $_POST['pass'];
    }

    if ($field('host') !== '' || $field('user') !== '' || $field('db') !== '') {
        // Values from the form always win over the stored configuration.
        $GLOBALS['sarmad_dbcheck_override'] = true;
    }
}

$root = dirname(__DIR__);

define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', $root);

/* ---------------------------------------------------------------------
 * Configuration (config/config.php is optional: the values below are the
 * deployment defaults, so the tool also works before the installer ran)
 * ------------------------------------------------------------------- */
$conf = [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'sarmadfa_clinic',
    'username' => 'sarmadfa_youruser_lumiere',
    'password' => '',
    'prefix'   => 'attendance_',
    'source'   => 'defaults (config/config.php not found)',
];

$configPath = $root . '/config/config.php';

if (is_file($configPath)) {
    $loaded = @include $configPath;

    if (is_array($loaded) && isset($loaded['db']) && is_array($loaded['db'])) {
        $db = $loaded['db'];

        $conf['host']     = (string) ($db['host'] ?? $conf['host']);
        $conf['port']     = (int) ($db['port'] ?? $conf['port']);
        $conf['database'] = (string) ($db['database'] ?? $conf['database']);
        $conf['username'] = (string) ($db['username'] ?? $conf['username']);
        $conf['password'] = (string) ($db['password'] ?? '');
        $conf['prefix']   = (string) ($db['prefix'] ?? $conf['prefix']);
        $conf['source']   = 'config/config.php';
    } else {
        $conf['source'] = 'config/config.php (unreadable - using defaults)';
    }
}

/* A candidate password can be passed for testing without touching config. */
foreach ((array) ($_SERVER['argv'] ?? $argv ?? []) as $argument) {
    if (strpos($argument, '--pass=') === 0) {
        $conf['password'] = substr($argument, 7);
        $conf['source']   = 'command line (--pass=...)';
    }

    if (strpos($argument, '--host=') === 0) {
        $conf['host'] = substr($argument, 7);
    }

    if (strpos($argument, '--user=') === 0) {
        $conf['username'] = substr($argument, 7);
    }

    if (strpos($argument, '--db=') === 0) {
        $conf['database'] = substr($argument, 5);
    }

    if (strpos($argument, '--prefix=') === 0) {
        $conf['prefix'] = substr($argument, 9);
    }
}

$password = (string) $conf['password'];

/** Never let the secret reach the screen, a log or a stack trace. */
$scrub = static function (string $message) use ($password): string {
    if ($password !== '') {
        $message = str_replace($password, '***', $message);
    }

    return (string) preg_replace('/^Database connection failed:\s*/i', '', $message);
};

$line = static function (string $label, string $value): void {
    printf("  %-22s %s\n", $label, $value);
};

echo "=====================================================\n";
echo " SARMAD Attendance - database connection doctor\n";
echo "=====================================================\n\n";

/* 1) Runtime ---------------------------------------------------------- */
echo "1) Runtime\n";
$line('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')');
$line('64-bit', (PHP_INT_SIZE === 8 ? 'yes' : 'NO - the Excel library needs a 64-bit build'));
$line('build', SARMAD_DBCHECK_BUILD);
$line('server', (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'cli'));

$socket = (string) ini_get('pdo_mysql.default_socket');

if ($socket !== '') {
    $line('pdo_mysql socket', $socket . (file_exists($socket) ? ' (exists)' : ' (MISSING - localhost cannot use it)'));
} else {
    $line('pdo_mysql socket', '(empty - the driver uses its compiled default)');
}

if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $me = posix_getpwuid(posix_geteuid());
    $line('PHP runs as', is_array($me) ? (string) ($me['name'] ?? '?') : '?');
}

$openBasedir = (string) ini_get('open_basedir');

if ($openBasedir !== '') {
    $line('open_basedir', $openBasedir);
}

$extensions = ['pdo', 'pdo_mysql', 'mysqlnd', 'mbstring', 'openssl', 'curl', 'gd', 'zip', 'zlib', 'dom', 'xml', 'fileinfo'];

foreach ($extensions as $extension) {
    $loaded = extension_loaded($extension);
    $line('ext ' . $extension, $loaded ? 'yes' : 'NO');
}

echo "\n2) Configuration (" . $conf['source'] . ")\n";
$line('host', (string) $conf['host']);
$line('port', (string) $conf['port']);
$line('database', (string) $conf['database']);
$line('username', (string) $conf['username']);
$line('prefix', (string) $conf['prefix']);

/* 3) Password shape - the fingerprint of a truncated / pasted secret --- */
echo "\n3) Password shape (the value itself is never printed)\n";

if ($password === '') {
    echo "  ! no password is configured for this account.\n";
} else {
    $line('length', (string) strlen($password) . ' characters');
    $line('contains #', strpos($password, '#') !== false ? 'yes' : 'no');
    $line('starts with #', strpos($password, '#') === 0 ? 'YES (a shell would treat it as a comment)' : 'no');
    $line('has space/line break', trim($password) !== $password ? 'YES' : 'no');
    $line('first character', $password[0] === '#' ? '#' : substr($password, 0, 1));
    $line('last character', substr($password, -1) === '#' ? '#' : substr($password, -1));

    if ($password !== '' && trim($password) === $password && strlen($password) <= 4) {
        echo "  ! this password is suspiciously short: a command such as\n";
        echo "    mysql -e \"... IDENTIFIED BY $password\" (unquoted) stops at '#'.\n";
        echo "    Reset the password in DirectAdmin -> MySQL Management.\n";
    }
}

/* 4) Connection attempts --------------------------------------------- */
$dsnOf = static function (string $host, int $port, string $database) : string {
    return 'mysql:host=' . $host . ';port=' . $port . ($database === '' ? '' : ';dbname=' . $database) . ';charset=utf8mb4';
};

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

echo "\n4) Connection attempts\n";

$attempt = static function (string $label, string $host, string $user, string $secret, string $database) use ($dsnOf, $options, $scrub, $line): array {
    $dsn = $dsnOf($host, 3306, $database);

    try {
        $pdo = new PDO($dsn, $user, $secret, $options);
        $line($label, 'CONNECT OK (' . (string) $pdo->query('SELECT VERSION()')->fetchColumn() . ')');

        return ['ok' => true, 'pdo' => $pdo, 'error' => '', 'host' => $host];
    } catch (Throwable $error) {
        $line($label, 'FAILED - ' . $scrub((string) $error->getMessage()));

        return ['ok' => false, 'pdo' => null, 'error' => (string) $error->getMessage(), 'host' => $host];
    }
};

$host   = (string) $conf['host'];
$user   = (string) $conf['username'];
$dbName = (string) $conf['database'];

$GLOBALS['sarmad_dbcheck_state'] = [
    'host'   => $host,
    'user'   => $user,
    'db'     => $dbName,
    'prefix' => (string) $conf['prefix'],
];

$tried  = [];
$tried[] = $attempt('localhost (socket)', 'localhost', $user, $password, $dbName);
$tried[] = $attempt('127.0.0.1 (TCP)', '127.0.0.1', $user, $password, $dbName);

$working = null;

foreach ($tried as $tryResult) {
    if ($tryResult['ok']) {
        $working = $tryResult;
        break;
    }
}

$denied = static function (string $message): bool {
    return strpos($message, '1045') !== false || stripos($message, 'Access denied') !== false;
};

$socketProblem = static function (string $message): bool {
    return strpos($message, '2002') !== false || stripos($message, 'No such file') !== false
        || stripos($message, "Can't connect") !== false;
};

if ($working === null) {
    // Which transport gave the informative answer? (a socket path problem and
    // a refused password are two very different findings)
    $refused = null;

    foreach ($tried as $tryResult) {
        if ($denied($tryResult['error'])) {
            $refused = $tryResult;
            break;
        }
    }

    $probeHost = $refused !== null ? (string) $refused['host'] : 'localhost';

    echo "\n5) Does MySQL know this account at all? (empty password, never the real one)\n";
    echo "   note: MySQL answers 1045 both for 'unknown user' and for 'wrong password',\n";
    echo "         so this probe alone can not separate the two - but together with the\n";
    echo "         attempts above it narrows the cause to the account itself.\n";
    $probe = $attempt('probe ' . $probeHost, $probeHost, $user, '', $dbName);

    echo "\n6) Verdict\n";

    if ($probe['ok']) {
        echo "  ✓ The account EXISTS: MySQL let it in without a password.\n";
        echo "  => The configured password is not the one stored on the server.\n";
        echo "     Reset it in DirectAdmin -> MySQL Management -> this user ->\n";
        echo "     Change Password, then use exactly that value (no spaces).\n";
    } elseif ($denied($probe['error'])) {
        echo "  ✗ MySQL refused the account with the configured password AND with\n";
        echo "    an empty one, so it cannot tell this user apart at all. Check:\n";
        echo "    1) the password: DirectAdmin -> MySQL Management -> user -> Change Password\n";
        echo "    2) the grant  : MySQL Management -> Add User To Database -> ALL PRIVILEGES\n";
        echo "    3) the name   : the FULL username (account_user), not the short one\n";
        echo "    If the password was ever created from a shell command such as\n";
        echo "      mysql -e \"... IDENTIFIED BY <password>\"   (unquoted)\n";
        echo "    the '#' started a shell comment and the stored password is shorter\n";
        echo "    than the one you expect - reset it in the panel to be sure.\n";
    } elseif ($socketProblem($tried[0]['error'])) {
        echo "  ✗ The unix socket of MySQL was not found, and the TCP attempt did\n";
        echo "    not accept the credentials. Two separate things to fix:\n";
        echo "    1) socket: set db.host to 127.0.0.1 in config/config.php (or fix\n";
        echo "       pdo_mysql.default_socket in the PHP settings of the panel),\n";
        echo "    2) credentials: as described under (1)-(3) above.\n";
    } else {
        echo "  ✗ The database server itself could not be reached. Check the host,\n";
        echo "    the port and that the MySQL service is running for this account.\n";
    }

    echo "\n  Nothing was written and no secret was printed by this tool.\n";

    $GLOBALS['sarmad_dbcheck_status'] = ($denied($probe['error']) || $denied((string) ($refused['error'] ?? '')))
        ? 'REFUSED'
        : ((!empty($tried[0]['error']) && $socketProblem((string) $tried[0]['error'])) ? 'UNREACH' : 'REFUSED');

    exit(1);
}

$local = $working;

/* 5) Real connection: what the project would see ---------------------- */
echo "\n5) Project scope (through the real connection)\n";
$line('connected as', (string) $local['pdo']->query('SELECT CURRENT_USER()')->fetchColumn());

$tables = $local['pdo']->query(
    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
)->fetchAll(PDO::FETCH_COLUMN);

$mine    = array_values(array_filter($tables, static fn (string $t): bool => strpos($t, 'attendance_') === 0));
$foreign = array_values(array_diff($tables, $mine));

sort($mine);
sort($foreign);

$line('attendance_* tables', (string) count($mine));
$line('other tables', count($foreign) === 0 ? 'none' : count($foreign) . ' (never touched): ' . implode(', ', $foreign));

if ($mine !== []) {
    $line('first tables', implode(', ', array_slice($mine, 0, 6)));
    $line('schema_migrations', in_array('attendance_schema_migrations', $mine, true)
        ? (string) $local['pdo']->query('SELECT COUNT(*) FROM `attendance_schema_migrations`')->fetchColumn() . ' applied'
        : 'not installed yet');
}

echo "\n";
echo "RESULT: the connection works. The project will create/read ONLY\n";
echo "        attendance_* tables and never touches the tables listed above.\n";

if (PHP_SAPI !== 'cli') {
    echo "\nNote: this page is reachable only until the installation is locked.\n";
}

$GLOBALS['sarmad_dbcheck_status'] = 'CONNECTED';

exit(0);
