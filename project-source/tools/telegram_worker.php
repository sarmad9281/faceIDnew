<?php
/**
 * SARMAD Attendance - Telegram worker (stage 7).
 * ------------------------------------------------------------------
 * This is the cron entry point. DirectAdmin / cPanel:
 *
 *     *​/1 * * * * /usr/bin/php /home/USER/domains/DOMAIN/public_html/tools/telegram_worker.php >> /home/USER/telegram_worker.log 2>&1
 *
 * One run does three things, in this order:
 *
 *   1. DISPATCH  reads the pending attendance_events rows (written by the
 *                attendance engine) and turns the notification-worthy ones into
 *                telegram queue rows,
 *   2. REPORTS   sends the daily summary of any customer whose configured time
 *                has passed - at most once per customer and day, enforced by the
 *                unique key on attendance_telegram_reports,
 *   3. DRAIN     sends the queued messages, retries the failed ones with
 *                backoff and gives up after max_attempts.
 *
 * Attendance is never involved: the engine already committed the record. A
 * Telegram outage only shows up in attendance_telegram_logs / the queue.
 *
 * Usage:
 *   php tools/telegram_worker.php            # normal cron run
 *   php tools/telegram_worker.php --once     # same, explicit
 *   php tools/telegram_worker.php --status   # show the queue state, send nothing
 *   php tools/telegram_worker.php --limit=50
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

if (!App\Bootstrap::bootCli()) {
    fwrite(STDERR, "config/config.php یافت نشد یا اتصال دیتابیس برقرار نشد.\n");
    exit(1);
}

use App\Core\Database;
use App\Services\EventDispatcher;
use App\Services\TelegramScheduler;
use App\Services\TelegramService;

$command = 'run';
$limit   = 20;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--status') {
        $command = 'status';
    } elseif ($argument === '--once') {
        $command = 'run';
    } elseif (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(200, (int) substr($argument, 8)));
    }
}

$service = new TelegramService();

if ($command === 'status') {
    $rows = Database::select(
        'SELECT `status`, COUNT(*) AS total FROM `' . Database::table('telegram_queue') . '` GROUP BY `status`'
    );

    echo "Telegram queue:\n";

    foreach ($rows as $row) {
        printf("  %-12s %d\n", (string) $row['status'], (int) $row['total']);
    }

    $events = Database::scalar(
        'SELECT COUNT(*) FROM `' . Database::table('events') . "` WHERE `status` = 'pending'"
    );

    printf("pending attendance events: %d\n", (int) $events);

    $reports = Database::select(
        'SELECT `status`, COUNT(*) AS total FROM `' . Database::table('telegram_reports') . '` GROUP BY `status`'
    );

    echo "Daily reports:\n";

    foreach ($reports as $row) {
        printf("  %-12s %d\n", (string) $row['status'], (int) $row['total']);
    }

    exit(0);
}

$events  = (new EventDispatcher())->dispatch(200);
$reports = (new TelegramScheduler())->runDue();
$queue   = $service->drain($limit);

printf(
    "[%s] events: scanned=%d notified=%d skipped=%d failed=%d | reports: due=%d sent=%d failed=%d reconciled=%d | queue: processed=%d sent=%d retried=%d failed=%d\n",
    date('Y-m-d H:i:s'),
    $events['scanned'], $events['notified'], $events['skipped'], $events['failed'],
    $reports['due'], $reports['sent'], $reports['failed'], $reports['reconciled'],
    $queue['processed'], $queue['sent'], $queue['retried'], $queue['failed']
);

exit(0);
