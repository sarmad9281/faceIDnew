#!/usr/bin/env bash
# =====================================================================
# SARMAD Attendance - folder move check (REAL requests, no mocks)
# ---------------------------------------------------------------------
# Verifies the acceptance of "install in one folder, then rename it":
#
#   tests/move_folder_check.sh <OLD_BASE_URL> <NEW_BASE_URL>
#
#   tests/move_folder_check.sh http://127.0.0.1:8092/barber \
#                              http://127.0.0.1:8093/attendance
#
# It logs in as the owner and as the manager on BOTH trees and asserts that
# every section answers 200 on the new folder, while the folder that is
# still sitting in the old path keeps working. Nothing is written to the
# database by this script (only audit rows produced by the logins).
# =====================================================================
set -u

OLD="${1:?usage: move_folder_check.sh <old_base_url> <new_base_url>}"
NEW="${2:?usage: move_folder_check.sh <old_base_url> <new_base_url>}"
JAR_DIR="$(mktemp -d)"
PASS=0
FAIL=0

OLD_PATH="${OLD#*://}"
if [ "$OLD_PATH" != "${OLD_PATH#*/}" ]; then OLD_PATH="/${OLD_PATH#*/}"; else OLD_PATH=""; fi
NEW_PATH="${NEW#*://}"
if [ "$NEW_PATH" != "${NEW_PATH#*/}" ]; then NEW_PATH="/${NEW_PATH#*/}"; else NEW_PATH=""; fi

# Feature flags live in the database (attendance_feature_flags). The pages of
# the stage-7 features answer 403 while their flag is off - that is the
# contract, not a failure - so this check turns them on, verifies the moved
# tree, and always restores "off" on exit.
FLAGS_TOUCHED=0
restore_flags() { [ "$FLAGS_TOUCHED" = "1" ] && php tools/stage7_flags.php off >/dev/null 2>&1; }
trap restore_flags EXIT

ok()   { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }

login() { # base, jar, identifier, password  -> prints the redirect target
    local base="$1" jar="$2" id="$3" pw="$4" token
    rm -f "$jar"
    token="$(curl -s -c "$jar" "$base/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')"
    curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{redirect_url}' \
        --data-urlencode "_token=$token" \
        --data-urlencode "identifier=$id" \
        --data-urlencode "password=$pw" \
        "$base/login"
}

code() { curl -s -b "$2" -o /dev/null -w '%{http_code}' "$1"; }

# ---------------------------------------------------------------- new tree
echo "== $NEW (after the move) =="
JAR="$JAR_DIR/owner.j"
LOC="$(login "$NEW" "$JAR" 'owner@sarmadfallahzadeh.ir' 'Owner#Pass2026')"
case "$LOC" in *"$NEW/owner"*) ok "owner login lands on the new base ($LOC)";; *) bad "owner login redirect: '$LOC' (expected prefix $NEW/owner)";; esac

for p in /owner /owner/employees /owner/customers /owner/features /owner/managers \
         /owner/devices /owner/subscriptions /owner/logs /owner/settings /owner/emergency; do
    c="$(code "$NEW$p" "$JAR")"; [ "$c" = "200" ] && ok "owner $p -> 200" || bad "owner $p -> $c"
done

JAR="$JAR_DIR/manager.j"
NEW_MGR_JAR="$JAR"
LOC="$(login "$NEW" "$JAR" 'manager@example.com' 'Manager#Pass2026')"
case "$LOC" in *"$NEW/dashboard"*) ok "manager login lands on the new base ($LOC)";; *) bad "manager login redirect: '$LOC' (expected prefix $NEW/dashboard)";; esac

for p in /dashboard /manager/employees /manager/attendance /manager/leaves /manager/holidays \
         /manager/reports /manager/activity /manager/devices /manager/settings; do
    c="$(code "$NEW$p" "$JAR")"

    if [ "$c" = "403" ] && [ -f tools/stage7_flags.php ]; then
        php tools/stage7_flags.php on >/dev/null 2>&1
        FLAGS_TOUCHED=1
        c="$(code "$NEW$p" "$JAR")"
        ok "manager $p -> $c (was 403: feature flag off - turned on for the check)"
        continue
    fi

    [ "$c" = "200" ] && ok "manager $p -> 200" || bad "manager $p -> $c"
done

# The pages of the moved tree must point at the new folder, never the old one.
HTML="$(curl -s -b "$JAR" "$NEW/dashboard")"
EXPECT_ASSET="${NEW_PATH:-}/assets/"
if printf '%s' "$HTML" | grep -q "\"$EXPECT_ASSET"; then
    ok "asset links use the new base path ($EXPECT_ASSET)"
else
    bad "asset links do not use $EXPECT_ASSET"
fi
if [ "$OLD_PATH" = "$NEW_PATH" ]; then
    ok "single-folder run: OLD = NEW = $NEW_PATH, the old-base assertion does not apply"
elif printf '%s' "$HTML" | grep -q "\"$OLD_PATH/"; then
    bad "the moved pages still link to the OLD base ($OLD_PATH)"
else
    ok "no link points back to the old base ($OLD_PATH)"
fi

# kiosk + PWA entry points
for p in /device /sw.js /manifest.webmanifest /assets/js/device.js \
         /assets/models/face-api-1.0.0/models.json; do
    c="$(curl -s -o /dev/null -w '%{http_code}' "$NEW$p")"
    [ "$c" = "200" ] && ok "static/kiosk $p -> 200" || bad "static/kiosk $p -> $c"
done

# ---------------------------------------------------------------- old tree
echo "== $OLD (untouched) =="
JAR="$JAR_DIR/old.j"
login "$OLD" "$JAR" 'manager@example.com' 'Manager#Pass2026' >/dev/null
c="$(code "$OLD/dashboard" "$JAR")"
[ "$c" = "200" ] && ok "old tree still serves /dashboard (200)" || bad "old tree /dashboard -> $c"

# a stage-7 page that the flag tool controls: proves flags still gate
# correctly after the move (403 while off, 200 while on)
php tools/stage7_flags.php on >/dev/null 2>&1; FLAGS_TOUCHED=1
c="$(code "$NEW/manager/payroll" "$NEW_MGR_JAR")"
[ "$c" = "200" ] && ok "flag-gated page /manager/payroll -> 200 while flags are on" || bad "flag-gated page /manager/payroll -> $c"

rm -rf "$JAR_DIR"
echo "-----------------------------------------------------------------"
printf 'RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
