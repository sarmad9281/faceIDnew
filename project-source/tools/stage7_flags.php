<?php
/**
 * Stage-7 flag helper (CLI) - flips the real feature flags of customer 1
 * through the same service the owner panel uses.
 *
 *   php tools/stage7_flags.php on|off
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$root = dirname(__DIR__);

define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', $root);

require $root . '/app/Bootstrap.php';

if (!App\Bootstrap::bootCli()) {
    fwrite(STDERR, "boot failed\n");
    exit(1);
}

use App\Services\FeatureService;

$mode = $argv[1] ?? 'status';
$customerId = (int) ($argv[2] ?? 1);
$features = ['payroll', 'telegram', 'excel_reports', 'advanced_reports', 'branches', 'reports'];

foreach ($features as $feature) {
    if ($mode === 'on') {
        FeatureService::set($customerId, $feature, true, 0);
    } elseif ($mode === 'off') {
        FeatureService::set($customerId, $feature, false, 0);
    }

    printf("%-18s %s\n", $feature, FeatureService::allows($customerId, $feature) ? 'on' : 'off');
}
