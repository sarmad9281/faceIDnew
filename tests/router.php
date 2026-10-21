<?php
/**
 * Router for the PHP built-in server (development / automated tests only).
 *
 * Apache/DirectAdmin uses the .htaccess rules instead; this file makes
 * `php -S localhost:8080 tests/router.php` behave like the real host:
 *   - existing files under assets/ and uploads/ are served as-is
 *   - /install/ is served from install/index.php
 *   - everything else falls through to the front controller
 *
 * NEVER deploy this file to production hosting.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = $root . $uri;

// Block direct access to internal files, exactly like the .htaccess does.
if (preg_match('#^/(app|config|routes|sql|views|storage|docs|tests)/#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';

    return true;
}

if (preg_match('#^/tools/(db_check|test_connection)\.php$#', $uri) && is_file($path)) {
    require $path;

    return true;
}

if (preg_match('#^/install(/.*)?$#', $uri)) {
    require $root . '/install/index.php';

    return true;
}

if ($uri !== '/' && is_file($path) && !str_ends_with($uri, '.php')) {
    return false; // let the built-in server serve the static file
}

require $root . '/index.php';

return true;
