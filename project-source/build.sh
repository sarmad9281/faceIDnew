#!/usr/bin/env bash
# =====================================================================
# SARMAD Attendance - release packager
# =====================================================================
# Creates a ZIP whose CONTENT can be extracted directly into
#   /domains/<domain>/public_html/
# without creating an extra folder.
#
# Excluded on purpose (never ship these):
#   config/config.php        (contains the DB password & app key)
#   storage/installed.lock   (installation state of the dev machine)
#   storage/logs/*           (runtime logs)
#   storage/tmp/sessions/*   (session files)
#   storage/cache/*          (runtime cache)
# =====================================================================
set -eu

ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT_DIR="$(dirname "$ROOT")"
NAME="sarmad-attendance-v1.0.0.zip"
ZIP="$OUT_DIR/$NAME"

echo "==> cleaning development artifacts"
rm -f "$ROOT/config/config.php"
rm -f "$ROOT/config/config.php.tmp"
rm -f "$ROOT/storage/installed.lock"
rm -f "$ROOT"/storage/logs/*.log
rm -f "$ROOT"/storage/logs/php-error.log
rm -f "$ROOT"/storage/cache/*.json
rm -f "$ROOT"/storage/tmp/sessions/*
find "$ROOT" -name '*.tmp' -delete 2>/dev/null || true

echo "==> packing"
rm -f "$ZIP"
cd "$ROOT"
zip -r -q -X "$ZIP" . \
    -x "*/config/config.php" \
    -x "config/config.php" \
    -x "storage/installed.lock" \
    -x "storage/logs/*.log" \
    -x "storage/cache/*.json" \
    -x "storage/tmp/sessions/*" \
    -x "*.git*" \
    -x "*/.DS_Store" \
    -x "*/__MACOSX/*"

echo "==> verifying package safety"
FAIL=0
for forbidden in "config/config.php" "storage/installed.lock" ".git/config"; do
    if unzip -l "$ZIP" | grep -q " ${forbidden}$"; then
        echo "  ERROR: $forbidden must not be shipped"; FAIL=1
    fi
done
if unzip -l "$ZIP" | grep -qE '\.log$'; then echo "  ERROR: log files in the package"; FAIL=1; fi
[ "$FAIL" -eq 0 ] && echo "  package clean: no config.php, no lock, no logs"

echo "==> top level of the archive (must contain index.php directly)"
unzip -l "$ZIP" | awk '{print $4}' | grep -E '^[^/]+/?$' | sort -u | head -20

echo
echo "size: $(du -h "$ZIP" | cut -f1)   file: $ZIP"
