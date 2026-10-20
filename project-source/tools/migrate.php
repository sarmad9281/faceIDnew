<?php
/**
 * SARMAD Attendance - CLI migration / maintenance runner (stage 2).
 * ------------------------------------------------------------------
 * Usage (SSH or DirectAdmin cron):
 *
 *   php tools/migrate.php status
 *   php tools/migrate.php migrate
 *   php tools/migrate.php tables
 *
 * It NEVER touches a table outside the attendance_ prefix: every statement
 * goes through App\Core\SqlGuard and App\Core\Database::table().
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);

define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', $root);

require $root . '/app/Bootstrap.php';

// Minimal CLI bootstrap: autoloader + config + DB. No session, no router.
if (!App\Bootstrap::bootCli()) {
    fwrite(STDERR, "config/config.php یافت نشد یا اتصال دیتابیس برقرار نشد.\n");
    exit(1);
}

$command  = $argv[1] ?? 'status';
$migrator = new App\Services\MigrationService($root);

switch ($command) {
    case 'migrate':
        $result = $migrator->run();

        foreach ($result['messages'] as $message) {
            echo ' - ' . $message . "\n";
        }

        echo 'وضعیت: ' . ($result['ok'] ? 'OK' : 'FAILED') . "\n";
        exit($result['ok'] ? 0 : 1);

    case 'tables':
        $tables = App\Core\Database::projectTables();
        sort($tables);

        echo 'جداول پروژه (' . count($tables) . "):\n";

        foreach ($tables as $table) {
            echo ' - ' . $table . "\n";
        }

        $required = App\Services\InstallerService::REQUIRED_TABLES;
        $missing  = [];

        foreach ($required as $short) {
            if (!in_array(App\Core\Database::prefix() . $short, $tables, true)) {
                $missing[] = App\Core\Database::prefix() . $short;
            }
        }

        echo $missing === []
            ? "همه جداول الزامی موجود است.\n"
            : 'جداول غایب: ' . implode(', ', $missing) . "\n";

        exit($missing === [] ? 0 : 2);

    case 'status':
    default:
        $pending = $migrator->pending();

        echo 'Prefixo: ' . App\Core\Database::prefix() . "\n";
        echo 'جداول پروژه: ' . count(App\Core\Database::projectTables()) . "\n";
        echo $pending === []
            ? "ساختار دیتابیس به‌روز است.\n"
            : "مهاجرت‌های در انتظار:\n - " . implode("\n - ", $pending) . "\n";

        exit($pending === [] ? 0 : 3);
}
