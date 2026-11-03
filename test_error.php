<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', __DIR__);

require SARMAD_ROOT . '/app/Bootstrap.php';

// 1. Check Log Files first
echo "<!DOCTYPE html><html dir='ltr'><head><meta charset='utf-8'><title>Debug Logs</title></head><body style='font-family:monospace;padding:20px;background:#18181b;color:#e4e4e7;'>";
echo "<h1 style='color:#38bdf8;'>SARMAD System Diagnostics</h1>";

echo "<h2 style='color:#facc15;'>Recent System Logs:</h2>";
$logs = glob(SARMAD_ROOT . '/storage/logs/*.log');
if ($logs) {
    rsort($logs);
    foreach (array_slice($logs, 0, 2) as $log) {
        echo "<p>File: <b style='color:#4ade80;'>" . htmlspecialchars(basename($log)) . "</b></p>";
        $lines = @file($log);
        if ($lines) {
            $tail = array_slice($lines, -15);
            echo "<pre style='background:#09090b;color:#a3e635;padding:12px;border-radius:8px;overflow-x:auto;'>" . htmlspecialchars(implode("", $tail)) . "</pre>";
        }
    }
} else {
    echo "<p>No log files in storage/logs/</p>";
}

// 2. Run App with Debug Enabled
echo "<h2 style='color:#facc15;'>App Execution Trace:</h2>";
try {
    // Register autoloader and error handlers exactly like Bootstrap::run
    $refMethod = new ReflectionMethod('App\Bootstrap', 'registerAutoloader');
    $refMethod->setAccessible(true);
    $refMethod->invoke(null);

    $refConfig = new ReflectionMethod('App\Bootstrap', 'loadConfig');
    $refConfig->setAccessible(true);
    $refConfig->invoke(null);

    // Force debug ON so full details are visible
    \App\Core\Config::set('app.debug', true);

    App\Bootstrap::boot();
    echo "<p style='color:#4ade80;'>✓ Boot completed successfully!</p>";

    // Now test dispatching request
    $refDispatch = new ReflectionMethod('App\Bootstrap', 'dispatch');
    $refDispatch->setAccessible(true);
    $refDispatch->invoke(null);
} catch (\Throwable $e) {
    echo "<div style='background:#450a0a;border:2px solid #ef4444;color:#fee2e2;padding:16px;border-radius:10px;'>";
    echo "<h3 style='color:#f87171;margin-top:0;'>CRITICAL ERROR FOUND:</h3>";
    echo "<p><b>Message:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><b>File:</b> " . htmlspecialchars($e->getFile()) . " : line <b>" . $e->getLine() . "</b></p>";
    echo "<pre style='background:#1c0404;padding:12px;border-radius:6px;overflow-x:auto;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "</body></html>";
