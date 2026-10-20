<?php
/**
 * SARMAD Attendance - static API auditor (development helper).
 * ------------------------------------------------------------
 * Scans app/ and views/ for `SomeKnownClass::method(` calls and reports any
 * call whose class or method does not exist. Only classes that are real
 * application classes (App\*) are checked, so it never reports false alarms
 * for PHP built-ins.
 *
 *   php tools/audit_api.php            # report
 *   php tools/audit_api.php --views    # only views/
 *
 * It is a developer tool: it is not part of the runtime application.
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
    fwrite(STDERR, "config/config.php missing\n");
    exit(1);
}

/** Collect every declared class in the app. */
function declared_classes(string $root): array
{
    $classes = [];
    $it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));

    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        $namespace = '';

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $m)) {
            $namespace = trim($m[1]);
        }

        if (preg_match_all('/^(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/m', $source, $m2)) {
            foreach ($m2[1] as $short) {
                $classes[$short] = $namespace . '\\' . $short;
            }
        }
    }

    return $classes;
}

$classes = declared_classes($root);
$roots   = ($argv[1] ?? '') === '--views' ? ['views'] : ['app', 'views'];
$problems = [];
$checked  = 0;

foreach ($roots as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));

    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path   = $file->getPathname();
        $source = file_get_contents($path);
        $alias  = [];

        // use App\Foo\Bar;  /  use App\Foo\Bar as Baz;
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $fqcn = ltrim($row[1], '\\');
                $short = $row[2] ?? substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
                $alias[$short] = $fqcn;
            }
        }

        if (preg_match_all('/(?<![\w$>\\\\])([A-Z][A-Za-z0-9_]*)\s*::\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $row) {
                $short  = $row[1][0];
                $method = $row[2][0];
                $line   = substr_count(substr($source, 0, $row[0][1]), "\n") + 1;
                $fqcn   = $alias[$short] ?? ($classes[$short] ?? null);

                if ($fqcn === null) {
                    continue; // built-in (PDO, DateTime, ...) or dynamic
                }

                $checked++;

                if (!class_exists($fqcn)) {
                    $problems[] = sprintf('%s:%d  class %s does not load', str_replace($root . '/', '', $path), $line, $fqcn);
                    continue;
                }

                if (!method_exists($fqcn, $method)) {
                    $problems[] = sprintf('%s:%d  %s::%s() undefined', str_replace($root . '/', '', $path), $line, $short, $method);
                }
            }
        }
    }
}

sort($problems);
echo "AUDIT: {$checked} static calls checked, " . count($problems) . " problems\n";

foreach ($problems as $problem) {
    echo '  - ' . $problem . "\n";
}

exit($problems === [] ? 0 : 1);
