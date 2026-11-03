<?php
// SARMAD Attendance - Emergency Diagnostic Script
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<!DOCTYPE html><html dir='ltr'><head><meta charset='utf-8'><title>Diagnostic</title></head><body style='font-family:monospace;padding:20px;background:#1e1e1e;color:#ddd;'>";
echo "<h1>SARMAD Diagnostic Tool</h1>";

// 1. Show latest log entries
echo "<h2>1. Latest Log File Entries:</h2>";
$logFiles = glob(__DIR__ . '/storage/logs/*.log');
if ($logFiles) {
    rsort($logFiles);
    $latestLog = $logFiles[0];
    echo "<p>Reading: <b>" . htmlspecialchars(basename($latestLog)) . "</b></p>";
    $lines = @file($latestLog);
    if ($lines) {
        $tail = array_slice($lines, -25);
        echo "<pre style='background:#111;color:#0f0;padding:10px;border-radius:8px;overflow-x:auto;'>" . htmlspecialchars(implode("", $tail)) . "</pre>";
    } else {
        echo "<p>Log file is empty.</p>";
    }
} else {
    echo "<p>No log files found in storage/logs/</p>";
}

// 2. Test Boot & Routing
echo "<h2>2. Testing App Boot & Dispatch:</h2>";
define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', __DIR__);

try {
    require SARMAD_ROOT . '/app/Bootstrap.php';
    App\Bootstrap::boot();
    echo "<p style='color:#0f0;'>✓ Bootstrap::boot(): OK</p>";

    $router = new App\Core\Router();
    $registerWeb = require SARMAD_ROOT . '/routes/web.php';
    $registerApi = require SARMAD_ROOT . '/routes/api.php';
    $registerWeb($router);
    $registerApi($router);
    echo "<p style='color:#0f0;'>✓ Routes Registration: OK</p>";

    $request = App\Core\Request::capture();
    echo "<p>Requested Path: <b>" . htmlspecialchars($request->path()) . "</b></p>";

    ob_start();
    $router->dispatch($request);
    $output = ob_get_clean();
    echo "<p style='color:#0f0;'>✓ Router Dispatch: OK (Length: " . strlen($output) . " bytes)</p>";
} catch (\Throwable $e) {
    if (ob_get_level() > 0) ob_end_clean();
    echo "<div style='background:#500;color:#fff;padding:15px;border-radius:8px;'>";
    echo "<h3>✗ EXCEPTION CAUGHT!</h3>";
    echo "<p><b>Message:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><b>File:</b> " . htmlspecialchars($e->getFile()) . " : <b>Line " . $e->getLine() . "</b></p>";
    echo "<pre style='background:#200;padding:10px;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "</body></html>";
