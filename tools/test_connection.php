<?php
/**
 * SARMAD Attendance - Standalone Database Connection Test
 * ---------------------------------------------------------------------
 * Tests the database connection on DirectAdmin using the exact
 * credentials and standard PDO settings.
 *
 * It checks and reports:
 *   1. PHP version
 *   2. PDO MySQL driver status
 *   3. Host
 *   4. Port
 *   5. Database name
 *   6. Username
 *   7. Password length
 *   8. Presence of '#' in password
 *   9. Real PDO connection result
 *
 * THE PASSWORD VALUE IS NEVER PRINTED.
 *
 * Can be run from CLI:
 *   php tools/test_connection.php
 * Or from browser (while installer is active):
 *   https://example.com/barber/tools/test_connection.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// Load config/config.php if present, else fallback to deployment defaults
$configPath = $root . '/config/config.php';
$conf = [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'sarmadfa_clinic',
    'username' => 'sarmadfa_youruser_lumiere',
    'password' => '',
    'prefix'   => 'attendance_',
    'source'   => 'defaults',
];

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
    }
}

$phpVersion   = PHP_VERSION;
$pdoMysqlOk   = extension_loaded('pdo_mysql');
$host         = (string) $conf['host'];
$port         = (int) $conf['port'];
$database     = (string) $conf['database'];
$username     = (string) $conf['username'];
$password     = (string) $conf['password'];
$passLength   = strlen($password);
$hasHashChar  = strpos($password, '#') !== false;

// 9. Real PDO connection test
$pdoResult = [
    'ok'       => false,
    'message'  => '',
    'server'   => '',
    'host'     => $host,
    'tables'   => 0,
    'foreign'  => [],
];

if (!$pdoMysqlOk) {
    $pdoResult['message'] = 'PHP extension pdo_mysql is NOT installed or enabled.';
} else {
    // Try the configured host first, then try alternate (localhost <-> 127.0.0.1)
    $hostsToTry = [$host];
    $alternate  = ($host === 'localhost') ? '127.0.0.1' : (($host === '127.0.0.1') ? 'localhost' : null);
    if ($alternate !== null && !in_array($alternate, $hostsToTry, true)) {
        $hostsToTry[] = $alternate;
    }

    $lastError = '';
    foreach ($hostsToTry as $currentHost) {
        $dsn = "mysql:host={$currentHost};port={$port};dbname={$database};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            // Count tables
            $tablesStmt = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
            $allTables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $prefix = (string) $conf['prefix'];
            $myTables = array_filter($allTables, static fn ($t) => strpos((string) $t, $prefix) === 0);
            $foreignTables = array_diff($allTables, $myTables);

            $pdoResult['ok']      = true;
            $pdoResult['server']  = $serverVersion;
            $pdoResult['host']    = $currentHost;
            $pdoResult['tables']  = count($myTables);
            $pdoResult['foreign'] = array_values($foreignTables);
            $pdoResult['message'] = 'اتصال با موفقیت برقرار شد (host: ' . $currentHost . ')';
            break;
        } catch (Throwable $e) {
            $err = $e->getMessage();
            if ($password !== '') {
                $err = str_replace($password, '***', $err);
            }
            $lastError = $err;
        }
    }

    if (!$pdoResult['ok']) {
        $pdoResult['message'] = 'اتصال برقرار نشد: ' . $lastError;
    }
}

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    echo "=====================================================\n";
    echo " SARMAD Attendance - Standalone Connection Test\n";
    echo "=====================================================\n\n";
    printf("1. PHP version:                  %s\n", $phpVersion);
    printf("2. PDO MySQL active:             %s\n", $pdoMysqlOk ? 'YES' : 'NO');
    printf("3. Host:                         %s\n", $host);
    printf("4. Port:                         %d\n", $port);
    printf("5. Database:                     %s\n", $database);
    printf("6. Username:                     %s\n", $username);
    printf("7. Password length:              %d characters\n", $passLength);
    printf("8. Password contains '#':        %s\n", $hasHashChar ? 'YES' : 'NO');
    printf("9. Real PDO connection:          %s\n", $pdoResult['ok'] ? 'SUCCESS (' . $pdoResult['server'] . ')' : 'FAILED');
    if ($pdoResult['ok']) {
        printf("   Connected Host:               %s\n", $pdoResult['host']);
        printf("   Project tables (%s*):  %d\n", $conf['prefix'], $pdoResult['tables']);
        printf("   Other tables (untouched):     %s\n", empty($pdoResult['foreign']) ? 'none' : implode(', ', $pdoResult['foreign']));
    } else {
        printf("   Error:                        %s\n", $pdoResult['message']);
    }
    echo "\n(Password value was NOT printed)\n";
    exit($pdoResult['ok'] ? 0 : 1);
}

// Web output
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تست مستقل اتصال دیتابیس</title>
<style>
body { background:#0f1115; color:#e6e6e6; font-family:tahoma,system-ui,sans-serif; margin:0; padding:20px; }
.card { max-width:680px; margin:0 auto; background:#151922; border-radius:12px; padding:24px; box-shadow:0 8px 24px rgba(0,0,0,0.4); }
h1 { font-size:20px; margin-top:0; color:#38bdf8; border-bottom:1px solid #334155; padding-bottom:12px; }
.item { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #1e293b; font-size:14px; }
.item-label { color:#94a3b8; }
.item-val { font-family:ui-monospace,monospace; direction:ltr; font-weight:700; }
.badge-ok { background:#0f5132; color:#d1e7dd; padding:3px 10px; border-radius:6px; font-weight:700; }
.badge-err { background:#842029; color:#f8d7da; padding:3px 10px; border-radius:6px; font-weight:700; }
.alert-ok { background:#064e3b; border:1px solid #059669; color:#d1fae5; padding:12px 16px; border-radius:8px; margin:16px 0; }
.alert-err { background:#7f1d1d; border:1px solid #dc2626; color:#fee2e2; padding:12px 16px; border-radius:8px; margin:16px 0; }
.note { font-size:12px; color:#64748b; margin-top:16px; text-align:center; }
</style>
</head>
<body>
<div class="card">
    <h1>گزارش تست مستقل اتصال دیتابیس (SARMAD)</h1>

    <?php if ($pdoResult['ok']): ?>
        <div class="alert-ok">
            <strong>اتصال با موفقیت برقرار شد!</strong><br>
            سرور: <?= htmlspecialchars($pdoResult['server'], ENT_QUOTES, 'UTF-8') ?> | هاست متصل: <?= htmlspecialchars($pdoResult['host'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php else: ?>
        <div class="alert-err">
            <strong>خطا در برقراری اتصال:</strong><br>
            <?= htmlspecialchars($pdoResult['message'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="item">
        <span class="item-label">۱. نسخه PHP</span>
        <span class="item-val"><?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <div class="item">
        <span class="item-label">۲. اکستنشن PDO MySQL</span>
        <span class="item-val">
            <?php if ($pdoMysqlOk): ?>
                <span class="badge-ok">فعال (YES)</span>
            <?php else: ?>
                <span class="badge-err">غیرفعال (NO)</span>
            <?php endif; ?>
        </span>
    </div>

    <div class="item">
        <span class="item-label">۳. میزبان دیتابیس (Host)</span>
        <span class="item-val"><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <div class="item">
        <span class="item-label">۴. پورت دیتابیس (Port)</span>
        <span class="item-val"><?= (int) $port ?></span>
    </div>

    <div class="item">
        <span class="item-label">۵. نام دیتابیس (Database)</span>
        <span class="item-val"><?= htmlspecialchars($database, ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <div class="item">
        <span class="item-label">۶. نام کاربری (Username)</span>
        <span class="item-val"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <div class="item">
        <span class="item-label">۷. طول Password</span>
        <span class="item-val"><?= $passLength ?> کاراکتر</span>
    </div>

    <div class="item">
        <span class="item-label">۸. وجود کاراکتر # در Password</span>
        <span class="item-val">
            <?php if ($hasHashChar): ?>
                <span class="badge-ok">بله (موجود است)</span>
            <?php else: ?>
                <span class="badge-err">خیر</span>
            <?php endif; ?>
        </span>
    </div>

    <div class="item">
        <span class="item-label">۹. اتصال واقعی PDO</span>
        <span class="item-val">
            <?php if ($pdoResult['ok']): ?>
                <span class="badge-ok">موفق (CONNECT OK)</span>
            <?php else: ?>
                <span class="badge-err">ناموفق (FAILED)</span>
            <?php endif; ?>
        </span>
    </div>

    <p class="note">امنیت: خود پسورد به هیچ عنوان چاپ نشده و در کد HTML نمایش داده نمی‌شود.</p>
</div>
</body>
</html>
