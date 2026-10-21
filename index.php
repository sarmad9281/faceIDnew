<?php
/**
 * SARMAD Attendance - Web Front Controller
 * ------------------------------------------------------------------
 * This is the ONLY PHP file that must be reachable from the browser.
 * Everything else lives outside the "public surface" logic and is
 * protected by .htaccess rules + PHP guards.
 *
 * @author  SARMAD
 * @version 1.0.0
 */
declare(strict_types=1);

define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', __DIR__);

require SARMAD_ROOT . '/app/Bootstrap.php';

App\Bootstrap::run();
