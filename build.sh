#!/usr/bin/env bash
# =====================================================================
# SARMAD Attendance - release packager (DirectAdmin)
# =====================================================================
# Creates a ZIP whose CONTENT can be extracted directly into
#   /domains/<domain>/public_html/
# WITHOUT creating an extra folder: index.php sits at the archive root.
#
# Usage:
#   ./build.sh                  safe package  (NO config/config.php -
#                               for sharing / committing / archiving)
#   ./build.sh --with-config    PRIVATE DirectAdmin package: includes the
#                               pre-filled config/config.php (database
#                               password + app.key) so the operator only
#                               extracts it and confirms the installer
#                               with one click. NEVER share or commit
#                               this ZIP.
#   ./build.sh [--with-config] --output /path/to/file.zip
#
# This script never deletes or modifies the working tree: runtime files
# (installer lock, logs, sessions, caches) are EXCLUDED from the archive,
# not removed from disk.
#
# Always excluded:
#   .git/*                   (repository metadata)
#   release/*                (previous packages - no ZIP inside the ZIP)
#   storage/installed.lock   (installation state of THIS machine)
#   storage/logs/*.log, storage/cache/*.json, session/temp files
# =====================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
WITH_CONFIG=0
OUT=""

while [ $# -gt 0 ]; do
    case "$1" in
        --with-config) WITH_CONFIG=1 ;;
        --output)      shift; OUT="${1:-}" ;;
        -h|--help)     sed -n '2,30p' "$0"; exit 0 ;;
        *) echo "ERROR: unknown argument: $1" >&2; exit 2 ;;
    esac
    shift
done

if [ "$WITH_CONFIG" -eq 1 ]; then
    NAME="sarmad-attendance-v1.0.0-directadmin-private.zip"
else
    NAME="sarmad-attendance-v1.0.0.zip"
fi
[ -n "$OUT" ] || OUT="$ROOT/release/$NAME"
mkdir -p "$(dirname "$OUT")"

echo "==> pre-flight checks"
[ -f "$ROOT/index.php" ]                   || { echo "ERROR: index.php not found in $ROOT"; exit 1; }
[ -f "$ROOT/install/index.php" ]           || { echo "ERROR: install/index.php missing"; exit 1; }
[ -f "$ROOT/sql/attendance_install.sql" ]  || { echo "ERROR: sql/attendance_install.sql missing"; exit 1; }
[ -f "$ROOT/config/config.sample.php" ]    || { echo "ERROR: config/config.sample.php missing"; exit 1; }

if [ -f "$ROOT/storage/installed.lock" ]; then
    echo "  NOTE: storage/installed.lock exists locally - it is EXCLUDED, so the"
    echo "        package installs fresh on the target host."
fi

if [ "$WITH_CONFIG" -eq 1 ]; then
    echo "==> PRIVATE package requested: verifying config/config.php"
    CONFIG="$ROOT/config/config.php"
    [ -f "$CONFIG" ] || { echo "ERROR: --with-config needs config/config.php (copy config.sample.php and fill it in first)"; exit 1; }
    grep -q "'password'[[:space:]]*=>[[:space:]]*'.\+'" "$CONFIG" \
        || { echo "ERROR: db password is empty in config/config.php"; exit 1; }
    if grep -q "CHANGE_ME" "$CONFIG"; then
        echo "ERROR: app.key is still the CHANGE_ME placeholder in config/config.php"; exit 1
    fi
    grep -q "'database'[[:space:]]*=>[[:space:]]*'sarmadfa_clinic'" "$CONFIG" \
        || { echo "ERROR: db.database is not sarmadfa_clinic in config/config.php"; exit 1; }
    grep -q "'prefix'[[:space:]]*=>[[:space:]]*'attendance_'" "$CONFIG" \
        || { echo "ERROR: db.prefix is not attendance_ in config/config.php"; exit 1; }
    echo "  config OK (values checked, secrets not printed)"
fi

echo "==> packing $OUT"
rm -f "$OUT"
cd "$ROOT"

EXCLUDES=(
    ".git/*" ".git*"
    "release/*"
    "storage/installed.lock"
    "storage/logs/*.log"
    "storage/cache/*.json"
    "storage/sessions/sess_*"
    "storage/tmp/sessions/*"
    "config/config.php.tmp"
    "*.tmp"
    "*/.DS_Store"
    "*/__MACOSX/*"
)
if [ "$WITH_CONFIG" -eq 0 ]; then
    EXCLUDES+=("config/config.php")
fi

ZIP_ARGS=()
for pattern in "${EXCLUDES[@]}"; do
    ZIP_ARGS+=(-x "$pattern")
done

zip -r -q -X "$OUT" . "${ZIP_ARGS[@]}"

echo "==> verifying package"
LIST="$(unzip -Z1 "$OUT")"
FAIL=0
need()    { grep -F -x -q "$1" <<< "$LIST" || { echo "  ERROR: missing from package: $1"; FAIL=1; }; }
forbid()  { grep -F -x -q "$1" <<< "$LIST" && { echo "  ERROR: must not be shipped: $1"; FAIL=1; }; return 0; }
forbid_re() { grep -E -q "$1" <<< "$LIST" && { echo "  ERROR: $2"; FAIL=1; }; return 0; }

# The whole point of this package: extract straight into public_html/.
need "index.php"
need "install/index.php"
need "sql/attendance_install.sql"
need "config/config.sample.php"
need ".htaccess"
forbid_re '^project-source/' "package wraps everything in a project-source/ folder"
forbid_re '^\./'              "package entries start with ./"
forbid "storage/installed.lock"
forbid ".git/config"
forbid_re '^release/' "a previous release ZIP was packed inside this one"
forbid_re '\.log$' "log files in the package"

if [ "$WITH_CONFIG" -eq 1 ]; then
    need "config/config.php"
    COUNT="$(grep -F -x -c 'config/config.php' <<< "$LIST" || true)"
    [ "$COUNT" = "1" ] || { echo "  ERROR: config/config.php must appear exactly once (found: $COUNT)"; FAIL=1; }
else
    forbid "config/config.php"
fi

[ "$FAIL" -eq 0 ] || { echo "PACKAGE VERIFICATION FAILED"; exit 1; }
echo "  package clean: index.php at archive root, no lock, no logs, no .git"

echo "==> top level of the archive (extracts directly into public_html/)"
printf '%s\n' "$LIST" | awk -F/ '{print $1}' | sort -u | sed 's/^/  /'

echo
echo "size: $(du -h "$OUT" | cut -f1)   files: $(printf '%s\n' "$LIST" | grep -cv '/$' || true)"
echo "sha256: $(sha256sum "$OUT" 2>/dev/null | cut -d' ' -f1 || shasum -a 256 "$OUT" | cut -d' ' -f1)"
echo "file: $OUT"

if [ "$WITH_CONFIG" -eq 1 ]; then
    echo
    echo "********************************************************************"
    echo " WARNING: this ZIP contains config/config.php with the DATABASE"
    echo " PASSWORD and the app.key. Upload it ONLY to the target host,"
    echo " delete the ZIP from the server after extracting it, and never"
    echo " commit it to git or send it through shared channels."
    echo "********************************************************************"
fi
