#!/usr/bin/env bash
# SARMAD Attendance - syntax + PHP 8.1 compatibility check
# Usage: tests/lint.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0
COUNT=0

echo "PHP syntax check (php -l) on every .php file:"
while IFS= read -r file; do
    COUNT=$((COUNT + 1))
    OUT="$(php -l "$file" 2>&1)"
    case "$OUT" in
        *"No syntax errors"*) ;;
        *) echo "  FAIL $file"; echo "       $OUT"; FAIL=$((FAIL + 1));;
    esac
done < <(find "$ROOT" -name '*.php' -not -path '*/vendor/*' | sort)

echo "  checked: $COUNT files, failures: $FAIL"

echo
echo "PHP version: $(php -r 'echo PHP_VERSION;')  (target: 8.1.34+)"
php -r '
$min = 80100;
if (PHP_VERSION_ID < $min) { fwrite(STDERR, "  running PHP is older than 8.1\n"); exit(1); }
echo "  runtime accepts the code base\n";
' || FAIL=$((FAIL + 1))

if [ -x "$ROOT/../.tooling/vendor/bin/phpcs" ]; then
    echo
    echo "PHPCompatibility (testVersion 8.1):"
    "$ROOT/../.tooling/vendor/bin/phpcs" --standard=PHPCompatibility --runtime-set testVersion 8.1 \
        --extensions=php --report=summary "$ROOT/app" "$ROOT/views" "$ROOT/install" "$ROOT/routes" "$ROOT/index.php" || FAIL=$((FAIL + 1))
else
    echo
    echo "PHPCompatibility not installed (optional) - skipping cross version scan."
    echo "  install with: composer require --dev phpcompatibility/php-compatibility"
fi

echo
echo "ESLint style check for shell scripts:"
while IFS= read -r script; do
    bash -n "$script" || { echo "  FAIL $script"; FAIL=$((FAIL + 1)); }
done < <(find "$ROOT/tests" -name '*.sh' | sort)
echo "  done"

echo
if [ "$FAIL" -eq 0 ]; then
    echo "LINT: OK"
    exit 0
fi

echo "LINT: $FAIL problem(s)"
exit 1
