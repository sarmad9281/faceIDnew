#!/usr/bin/env bash
# =====================================================================
# SARMAD Attendance - end to end verification suite (stage 1)
# =====================================================================
# Usage:
#   tests/e2e.sh [BASE_URL] [DB_NAME] [DB_USER] [DB_PASS] [PREFIX]
#
# Defaults target the local development stack:
#   BASE_URL=http://127.0.0.1:8080
#   DB_NAME=sarmadfa_clinic   DB_USER=sarmadfa_youruser_lumiere
#
# The suite doubles as the pre-delivery checklist: it proves
# connectivity, installer + self lock, table prefix isolation, protected
# files, login/logout, sessions, role protection, CSRF, SQL injection
# resistance, XSS escaping, tenant isolation (web + API), the device
# lifecycle, the JSON API envelope and the audit trail.
#
# It is re-runnable: every run creates its own customers/devices using a
# unique run id, and it never drops or alters a table outside the
# project prefix.
# =====================================================================
set -u

BASE="${1:-http://127.0.0.1:8080}"
DB_NAME="${2:-sarmadfa_clinic}"
DB_USER="${3:-sarmadfa_youruser_lumiere}"
DB_PASS="${4:-${SARMAD_TEST_DB_PASS:-Test_Local_Pass_2026!}}"
PREFIX="${5:-attendance_}"

RUN="$(date +%s)$$"
APP_PATH="${APP_PATH:-$(cd "$(dirname "$0")/.." && pwd)}"

WORK="$(mktemp -d)"
JAR_OWNER="$WORK/owner.jar"

OWNER_USER='owner'
OWNER_PASS='Owner#Pass2026'
MGR_PASS='Tenant#Pass2026'

PASS=0
FAIL=0
SKIP=0

green() { printf '\033[32m%s\033[0m' "$1"; }
red()   { printf '\033[31m%s\033[0m' "$1"; }

ok()   { PASS=$((PASS+1)); printf '  %s %s\n' "$(green 'PASS')" "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  %s %s\n' "$(red 'FAIL')" "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
skip() { SKIP=$((SKIP+1)); printf '  %s %s\n' '[SKIP]' "$1"; }

section() { printf '\n=== %s ===\n' "$1"; }

# --- helpers ---------------------------------------------------------
export DB_NAME DB_USER DB_PASS

db() {
    php -r '
        $pdo = new PDO("mysql:host=localhost;dbname=".getenv("DB_NAME").";charset=utf8mb4",
            getenv("DB_USER"), getenv("DB_PASS"), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->query($argv[1]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) { echo implode("|", array_map("strval", $row)), PHP_EOL; }
    ' "$1"
}

csrf() { grep -oP 'name="_token" value="\K[^"]+' "$1" | head -1; }

csrf_meta() {
    local token
    token="$(grep -oP 'name="csrf-token" content="\K[^"]+' "$1" | head -1)"
    [ -z "$token" ] && token="$(csrf "$1")"
    printf '%s' "$token"
}

contains() { grep -qF -- "$2" "$1"; }

api_token() { # api_token <identifier> <password>
    curl -s -X POST "$BASE/api/v1/auth/token" -H 'Content-Type: application/json' \
        -d "{\"identifier\":\"$1\",\"password\":\"$2\",\"device_name\":\"e2e\"}" |
        php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["token"] ?? "";'
}

json_get() { # json_get <path>  e.g. data/device/id
    php -r '
        $j = json_decode(stream_get_contents(STDIN), true);
        foreach (explode("/", $argv[1]) as $key) { if (!is_array($j) || !isset($j[$key])) { echo ""; exit; } $j = $j[$key]; }
        echo is_scalar($j) ? (string) $j : "";
    ' "$1"
}

# login <jar> <identifier> <password> -> prints the http code
web_login() {
    local jar="$1" id="$2" pass="$3"
    curl -s -c "$jar" -b "$jar" -o "$WORK/loginpage.html" "$BASE/login" >/dev/null
    local token
    token="$(csrf_meta "$WORK/loginpage.html")"
    curl -s -o "$WORK/loginresult.html" -w '%{http_code}' -c "$jar" -b "$jar" -X POST "$BASE/login" \
        -H "Origin: $BASE" --data-urlencode "_token=$token" \
        --data-urlencode "identifier=$id" --data-urlencode "password=$pass"
}

# The suite performs several logins per run; clear the application's own
# rate limit counters (storage/cache) so repeated runs stay meaningful.
# Brute force protection itself is verified via attendance_login_attempts.
if [ -d "$APP_PATH/storage/cache" ]; then
    rm -f "$APP_PATH"/storage/cache/throttle_*.json 2>/dev/null || true
fi

printf 'SARMAD Attendance E2E suite (run %s)\nBase: %s\nDB: %s@localhost/%s (prefix %s)\n' \
    "$RUN" "$BASE" "$DB_USER" "$DB_NAME" "$PREFIX"

# =====================================================================
section "1. Database & table prefix isolation"
# =====================================================================
FOREIGN_BEFORE="$(db "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME NOT LIKE '${PREFIX}%' ORDER BY TABLE_NAME" | md5sum)"
FOREIGN_LIST="$(db "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME NOT LIKE '${PREFIX}%' ORDER BY TABLE_NAME" | tr '\n' ' ')"
FOREIGN_COUNT="$(db "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME NOT LIKE '${PREFIX}%'")"
printf '  foreign tables present (must stay untouched): %s\n' "${FOREIGN_LIST:-none}"

PREFIXED_TABLES="$(db "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '${PREFIX}%'")"
if [ "$PREFIXED_TABLES" -ge 15 ]; then ok "project tables exist: $PREFIXED_TABLES"; else bad "project tables: $PREFIXED_TABLES (< 15)"; fi

FOREIGN_FK="$(db "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND (REFERENCED_TABLE_NAME NOT LIKE '${PREFIX}%' OR TABLE_NAME NOT LIKE '${PREFIX}%')")"
if [ "$FOREIGN_FK" = "0" ]; then ok "no foreign key points outside ${PREFIX}*"; else bad "foreign keys leaking outside the prefix: $FOREIGN_FK"; fi

OWNER_COUNT="$(db "SELECT COUNT(*) FROM ${PREFIX}users WHERE role='owner' AND deleted_at IS NULL")"
if [ "$OWNER_COUNT" -ge 1 ]; then ok "owner account exists"; else bad "owner account missing"; fi

PLAIN="$(db "SELECT COUNT(*) FROM ${PREFIX}users WHERE password_hash NOT LIKE '\$2y\$%' AND password_hash NOT LIKE '\$argon2%'")"
if [ "$PLAIN" = "0" ]; then ok "all passwords are hashed (no plain text)"; else bad "found passwords stored without bcrypt/argon2"; fi

# =====================================================================
section "2. Installer self lock"
# =====================================================================
HTTP="$(curl -s -o "$WORK/install.html" -w '%{http_code}' "$BASE/install/")"
INSTALLER_LOCKED=0
if [ "$HTTP" = "404" ]; then ok "installer is disabled after install (HTTP 404)"; INSTALLER_LOCKED=1; else bad "installer still reachable (HTTP $HTTP)"; fi

HTTP="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/install/?step=2")"
if [ "$HTTP" = "404" ]; then ok "installer direct step access blocked"; else bad "installer step reachable (HTTP $HTTP)"; fi

if [ -f "$APP_PATH/storage/installed.lock" ]; then
    ok "lock file present (storage/installed.lock)"
elif [ "$INSTALLER_LOCKED" = "1" ]; then
    skip "lock file belongs to the deployed instance (APP_PATH=$APP_PATH)"
else
    bad "lock file missing"
fi

if [ -f "$APP_PATH/config/config.php" ]; then
    ok "config/config.php generated"
elif [ "$INSTALLER_LOCKED" = "1" ]; then
    skip "config/config.php belongs to the deployed instance"
else
    bad "config/config.php missing"
fi

# =====================================================================
section "3. Sensitive files are not web reachable"
# =====================================================================
for path in "/config/config.php" "/app/Core/Database.php" "/storage/installed.lock" "/sql/attendance_install.sql" "/views/auth/login.php" "/routes/web.php"; do
    HTTP="$(curl -s -o "$WORK/probe.html" -w '%{http_code}' "$BASE$path")"
    if [ "$HTTP" = "403" ] || [ "$HTTP" = "404" ]; then ok "blocked: $path (HTTP $HTTP)"; else bad "exposed: $path (HTTP $HTTP)"; fi
done

# =====================================================================
section "4. Authentication"
# =====================================================================
curl -s -c "$WORK/anon.jar" -b "$WORK/anon.jar" -o "$WORK/login.html" "$BASE/login"
if contains "$WORK/login.html" "ورود به SARMAD Attendance"; then ok "login page renders"; else bad "login page does not render"; fi

TOKEN="$(csrf "$WORK/login.html")"
if [ -n "$TOKEN" ]; then ok "login form contains a CSRF token"; else bad "no CSRF token in the login form"; fi

SID_BEFORE="$(awk '/sarmad_att_sid/ {print $7}' "$WORK/anon.jar" | tail -1)"

HTTP="$(curl -s -o "$WORK/nocsrf.html" -w '%{http_code}' -b "$WORK/anon.jar" -c "$WORK/anon.jar" \
    -X POST "$BASE/login" --data-urlencode "identifier=$OWNER_USER" --data-urlencode "password=$OWNER_PASS")"
if [ "$HTTP" = "419" ]; then ok "login without CSRF token rejected (419)"; else bad "CSRF missing on login: HTTP $HTTP"; fi

curl -s -c "$WORK/anon.jar" -b "$WORK/anon.jar" -o "$WORK/login.html" "$BASE/login" >/dev/null
TOKEN="$(csrf_meta "$WORK/login.html")"
curl -s -o /dev/null -b "$WORK/anon.jar" -c "$WORK/anon.jar" -X POST "$BASE/login" -H "Origin: $BASE" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "identifier=$OWNER_USER" --data-urlencode "password=DefinitelyWrong#1" >/dev/null
BAD_ATTEMPTS="$(db "SELECT COUNT(*) FROM ${PREFIX}login_attempts WHERE successful=0")"
if [ "$BAD_ATTEMPTS" -ge 1 ]; then ok "failed login recorded in ${PREFIX}login_attempts"; else bad "failed login not recorded"; fi

curl -s -c "$WORK/anon.jar" -b "$WORK/anon.jar" -o "$WORK/login.html" "$BASE/login" >/dev/null
TOKEN="$(csrf_meta "$WORK/login.html")"
SQLI_BODY="$(curl -s -b "$WORK/anon.jar" -c "$WORK/anon.jar" -X POST "$BASE/login" -H "Origin: $BASE" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "identifier=owner' OR '1'='1' -- " --data-urlencode 'password=x')"
if echo "$SQLI_BODY" | grep -qi "داشبورد\|dashboard\|owner panel"; then bad "SQL injection payload authenticated"; else ok "SQL injection payload did not authenticate"; fi
NEW_USERS="$(db "SELECT COUNT(*) FROM ${PREFIX}users WHERE username LIKE '%OR%' OR username LIKE '%--%'")"
if [ "$NEW_USERS" = "0" ]; then ok "no user was created by the injection attempt"; else bad "injection created $NEW_USERS user rows"; fi

# correct login + session fixation check
curl -s -c "$JAR_OWNER" -b "$JAR_OWNER" -o "$WORK/login.html" "$BASE/login" >/dev/null
TOKEN="$(csrf_meta "$WORK/login.html")"
SID_BEFORE="$(awk '/sarmad_att_sid/ {print $7}' "$JAR_OWNER" | tail -1)"
HTTP="$(curl -s -o "$WORK/afterlogin.html" -w '%{http_code}' -c "$JAR_OWNER" -b "$JAR_OWNER" \
    -X POST "$BASE/login" -H "Origin: $BASE" --data-urlencode "_token=$TOKEN" \
    --data-urlencode "identifier=$OWNER_USER" --data-urlencode "password=$OWNER_PASS")"
SID_AFTER="$(awk '/sarmad_att_sid/ {print $7}' "$JAR_OWNER" | tail -1)"
if [ "$HTTP" = "302" ]; then ok "owner login succeeded (302)"; else bad "owner login HTTP $HTTP"; fi
if [ -n "$SID_AFTER" ] && [ "$SID_AFTER" != "$SID_BEFORE" ]; then ok "session id rotated on login (anti fixation)"; else bad "session id did not change on login"; fi

# =====================================================================
section "5. Role protection"
# =====================================================================
HTTP="$(curl -s -o "$WORK/owner.html" -w '%{http_code}' -b "$JAR_OWNER" -c "$JAR_OWNER" "$BASE/owner")"
if [ "$HTTP" = "200" ]; then ok "owner can open /owner"; else bad "owner /owner HTTP $HTTP"; fi

HTTP="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/dashboard")"
if [ "$HTTP" = "302" ]; then ok "anonymous /dashboard redirects to login"; else bad "anonymous /dashboard HTTP $HTTP"; fi

# =====================================================================
section "6. XSS escaping"
# =====================================================================
XSS_NAME="XSS<Script>alert(1)</Script>Co-$RUN"
curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$WORK/custform.html" "$BASE/owner/customers/create" >/dev/null
TOKEN="$(csrf_meta "$WORK/custform.html")"
curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -X POST "$BASE/owner/customers" -H "Origin: $BASE" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "name=$XSS_NAME" \
    --data-urlencode "timezone=Asia/Tehran" --data-urlencode "date_system=jalali" >/dev/null
curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$WORK/custlist.html" "$BASE/owner/customers" >/dev/null
if contains "$WORK/custlist.html" "&lt;Script&gt;alert(1)&lt;/Script&gt;"; then ok "malicious name is HTML escaped on output"; else bad "escaped name not found in output"; fi
if contains "$WORK/custlist.html" "<Script>alert(1)</Script>"; then bad "raw script tag rendered (XSS)"; else ok "no raw script tag rendered"; fi
XSS_ID="$(db "SELECT id FROM ${PREFIX}customers WHERE name LIKE 'XSS%${RUN}%' LIMIT 1")"
if [ -n "$XSS_ID" ]; then ok "payload stored safely as data (id $XSS_ID)"; else bad "customer row not stored"; fi

# =====================================================================
section "7. Tenant isolation"
# =====================================================================
CUST_1=""
CUST_2=""
for i in 1 2; do
    curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$WORK/c.html" "$BASE/owner/customers/create" >/dev/null
    TOKEN="$(csrf_meta "$WORK/c.html")"
    curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -X POST "$BASE/owner/customers" -H "Origin: $BASE" \
        --data-urlencode "_token=$TOKEN" \
        --data-urlencode "name=Tenant $RUN-$i" \
        --data-urlencode "timezone=Asia/Tehran" --data-urlencode "date_system=jalali" \
        --data-urlencode "manager_first_name=Manager $RUN-$i" \
        --data-urlencode "manager_last_name=Tenant" \
        --data-urlencode "manager_email=tenant${RUN}.$i@example.com" \
        --data-urlencode "manager_username=tenant${RUN}a$i" \
        --data-urlencode "manager_password=$MGR_PASS" >/dev/null
done

CUST_1="$(db "SELECT id FROM ${PREFIX}customers WHERE name='Tenant $RUN-1' LIMIT 1")"
CUST_2="$(db "SELECT id FROM ${PREFIX}customers WHERE name='Tenant $RUN-2' LIMIT 1")"
[ -n "$CUST_1" ] && ok "customer #1 created (id $CUST_1)" || bad "customer #1 not created"
[ -n "$CUST_2" ] && ok "customer #2 created (id $CUST_2)" || bad "customer #2 not created"

db "UPDATE ${PREFIX}users SET api_enabled=1 WHERE email IN ('tenant${RUN}.1@example.com','tenant${RUN}.2@example.com')" >/dev/null

TOK_1="$(api_token "tenant${RUN}.1@example.com" "$MGR_PASS")"
TOK_2="$(api_token "tenant${RUN}.2@example.com" "$MGR_PASS")"
[ -n "$TOK_1" ] && ok "API token issued for tenant 1 manager" || bad "API token for tenant 1 failed"
[ -n "$TOK_2" ] && ok "API token issued for tenant 2 manager" || bad "API token for tenant 2 failed"

DEV_1="$(curl -s -X POST "$BASE/api/v1/devices" -H "Authorization: Bearer $TOK_1" -H 'Content-Type: application/json' \
    -d "{\"name\":\"Kiosk $RUN-1\"}" | json_get data/device/id)"
DEV_2="$(curl -s -X POST "$BASE/api/v1/devices" -H "Authorization: Bearer $TOK_2" -H 'Content-Type: application/json' \
    -d "{\"name\":\"Kiosk $RUN-2\"}" | json_get data/device/id)"
[ -n "$DEV_1" ] && ok "device registered for tenant 1 (id $DEV_1)" || bad "device registration for tenant 1 failed"
[ -n "$DEV_2" ] && ok "device registered for tenant 2 (id $DEV_2)" || bad "device registration for tenant 2 failed"

DB_OWNER_1="$(db "SELECT customer_id FROM ${PREFIX}devices WHERE id=${DEV_1:-0}")"
DB_OWNER_2="$(db "SELECT customer_id FROM ${PREFIX}devices WHERE id=${DEV_2:-0}")"
if [ -n "$DB_OWNER_1" ] && [ "$DB_OWNER_1" != "$DB_OWNER_2" ]; then ok "each device is bound to its own customer ($DB_OWNER_1 / $DB_OWNER_2)"; else bad "devices share the same customer"; fi

curl -s -o "$WORK/devlist1.json" -H "Authorization: Bearer $TOK_1" "$BASE/api/v1/devices"
if contains "$WORK/devlist1.json" "Kiosk $RUN-2"; then bad "tenant 1 can see tenant 2 devices"; else ok "tenant 1 device list is isolated"; fi

RESP="$(curl -s -X POST "$BASE/api/v1/devices/activate" -H "Authorization: Bearer $TOK_1" -H 'Content-Type: application/json' -d "{\"device_id\":${DEV_2:-0}}")"
if echo "$RESP" | grep -q '"success":false'; then ok "cross tenant device activation refused"; else bad "cross tenant activation was possible" "$RESP"; fi

# manager of tenant 2 in the web UI
curl -s -c "$WORK/m2.jar" -b "$WORK/m2.jar" -o /dev/null "$BASE/login"
web_login "$WORK/m2.jar" "tenant${RUN}.2@example.com" "$MGR_PASS" >/dev/null
HTTP="$(curl -s -o "$WORK/forbidden.html" -w '%{http_code}' -b "$WORK/m2.jar" "$BASE/owner")"
if [ "$HTTP" = "403" ]; then ok "manager gets 403 on the owner panel"; else bad "manager /owner HTTP $HTTP"; fi

UUID_A="$(db "SELECT uuid FROM ${PREFIX}customers WHERE id=${CUST_1:-0} LIMIT 1")"
curl -s -o "$WORK/scope.html" -b "$WORK/m2.jar" "$BASE/devices?customer=$UUID_A"
MGR2_OWN="$(db "SELECT customer_id FROM ${PREFIX}users WHERE email='tenant${RUN}.2@example.com'")"
if contains "$WORK/scope.html" "Kiosk $RUN-1"; then bad "manager saw another tenant's devices via ?customer="; else ok "manager cannot switch tenant scope (pinned to customer $MGR2_OWN)"; fi

# =====================================================================
section "8. Device architecture"
# =====================================================================
ACT="$(curl -s -X POST "$BASE/api/v1/devices/activate" -H "Authorization: Bearer $TOK_1" -H 'Content-Type: application/json' -d "{\"device_id\":${DEV_1:-0}}")"
DEV_TOKEN="$(echo "$ACT" | json_get data/token)"
[ -n "$DEV_TOKEN" ] && ok "device activated and token issued once" || bad "device activation failed" "$ACT"

if [ -n "$DEV_TOKEN" ] && [ -z "$(db "SELECT id FROM ${PREFIX}devices WHERE api_token_hash='$DEV_TOKEN'")" ]; then
    ok "plain device token is not stored (hash only)"
else
    bad "plain token stored in the database"
fi

TOKEN_PREFIX_DB="$(db "SELECT token_prefix FROM ${PREFIX}devices WHERE id=${DEV_1:-0}")"
if [ -n "$TOKEN_PREFIX_DB" ]; then ok "token prefix stored for identification ($TOKEN_PREFIX_DB)"; else bad "token prefix missing"; fi

DEV_PENDING="$(curl -s -X POST "$BASE/api/v1/devices" -H "Authorization: Bearer $TOK_1" -H 'Content-Type: application/json' \
    -d "{\"name\":\"Kiosk Pending $RUN\"}" | json_get data/device/id)"
PENDING_STATUS="$(db "SELECT status FROM ${PREFIX}devices WHERE id=${DEV_PENDING:-0}")"
if [ "$PENDING_STATUS" = "pending" ]; then ok "new device starts as pending"; else bad "new device status is '$PENDING_STATUS'"; fi

LOGIN_JSON="$(curl -s -X POST "$BASE/api/v1/device/login" -H 'Content-Type: application/json' -d "{\"token\":\"$DEV_TOKEN\"}")"
SESS_TOKEN="$(echo "$LOGIN_JSON" | json_get data/session_token)"
[ -n "$SESS_TOKEN" ] && ok "device authenticated and received a session token" || bad "device login failed" "$LOGIN_JSON"

HB="$(curl -s -o "$WORK/hb.json" -w '%{http_code}' -X POST "$BASE/api/v1/device/heartbeat" \
    -H "Authorization: Bearer $DEV_TOKEN" -H 'Content-Type: application/json' -d '{"clock_offset_seconds":3,"app_version":"1.0.0"}')"
if [ "$HB" = "200" ]; then ok "device heartbeat accepted"; else bad "device heartbeat HTTP $HB"; fi
if [ -n "$(db "SELECT last_seen_at FROM ${PREFIX}devices WHERE id=${DEV_1:-0}")" ]; then ok "last_seen_at recorded"; else bad "last_seen_at not recorded"; fi

HB_SESS="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v1/device/heartbeat" \
    -H "Authorization: Bearer $SESS_TOKEN" -H 'Content-Type: application/json' -d '{}')"
if [ "$HB_SESS" = "200" ]; then ok "device session token also accepted"; else bad "device session token rejected (HTTP $HB_SESS)"; fi

HB_BAD="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v1/device/heartbeat" \
    -H "Authorization: Bearer att_invalid_token_value" -H 'Content-Type: application/json' -d '{}')"
if [ "$HB_BAD" = "401" ]; then ok "invalid device token rejected (401)"; else bad "invalid device token HTTP $HB_BAD"; fi

curl -s -X POST "$BASE/api/v1/devices/revoke" -H "Authorization: Bearer $TOK_1" -H 'Content-Type: application/json' -d "{\"device_id\":${DEV_1:-0}}" >/dev/null
HB_REVOKED="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v1/device/heartbeat" -H "Authorization: Bearer $DEV_TOKEN" -H 'Content-Type: application/json' -d '{}')"
if [ "$HB_REVOKED" = "401" ]; then ok "revoked device is locked out"; else bad "revoked device still accepted (HTTP $HB_REVOKED)"; fi
if [ -n "$(db "SELECT revoked_at FROM ${PREFIX}device_sessions WHERE device_id=${DEV_1:-0} AND revoked_at IS NOT NULL LIMIT 1")" ]; then ok "device sessions revoked and logged"; else bad "device sessions not revoked"; fi

# =====================================================================
section "9. API hygiene"
# =====================================================================
HTTP="$(curl -s -o "$WORK/apihealth.json" -w '%{http_code}' "$BASE/api/v1/health")"
if [ "$HTTP" = "200" ] && grep -q '"success":true' "$WORK/apihealth.json"; then ok "health endpoint uses the standard JSON envelope"; else bad "health endpoint broken (HTTP $HTTP)"; fi

HTTP="$(curl -s -o "$WORK/api401.json" -w '%{http_code}' "$BASE/api/v1/me")"
if [ "$HTTP" = "401" ] && grep -q '"code":"unauthenticated"' "$WORK/api401.json"; then ok "unauthenticated API call rejected with a JSON error"; else bad "API 401 handling wrong (HTTP $HTTP)"; fi

HTTP="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v1/devices" -H "Authorization: Bearer $TOK_1" -H 'Content-Type: text/plain' -d 'x')"
if [ "$HTTP" = "415" ]; then ok "unsupported media type rejected (415)"; else bad "media type check HTTP $HTTP"; fi

HTTP="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/does-not-exist")"
if [ "$HTTP" = "404" ]; then ok "unknown API route answers 404"; else bad "unknown API route HTTP $HTTP"; fi

OWNER_TOKEN="$(api_token "$OWNER_USER" "$OWNER_PASS")"
NOSCOPE="$(curl -s "$BASE/api/v1/devices" -H "Authorization: Bearer $OWNER_TOKEN")"
if echo "$NOSCOPE" | grep -q 'tenant_scope_required'; then ok "owner API requires an explicit tenant scope"; else bad "owner API returned data without a scope" "$NOSCOPE"; fi

UUID_2="$(db "SELECT uuid FROM ${PREFIX}customers WHERE id=${CUST_2:-0} LIMIT 1")"
SCOPED="$(curl -s "$BASE/api/v1/devices?customer=$UUID_2" -H "Authorization: Bearer $OWNER_TOKEN")"
if echo "$SCOPED" | grep -q "Kiosk $RUN-2"; then ok "owner can scope to a customer explicitly"; else bad "owner scoping failed" "$SCOPED"; fi

# =====================================================================
section "10. CSRF, cross origin and logout"
# =====================================================================
HTTP="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_OWNER" -X POST "$BASE/owner/customers" --data-urlencode 'name=NoCsrf')"
if [ "$HTTP" = "419" ]; then ok "state changing POST without CSRF token rejected"; else bad "missing CSRF protection: HTTP $HTTP"; fi
NO_CSRF_ROW="$(db "SELECT COUNT(*) FROM ${PREFIX}customers WHERE name='NoCsrf'")"
if [ "$NO_CSRF_ROW" = "0" ]; then ok "no row was written without a CSRF token"; else bad "row written without a CSRF token"; fi

HTTP="$(curl -s -o "$WORK/cross.json" -w '%{http_code}' -X POST "$BASE/api/v1/devices" \
    -H "Authorization: Bearer $TOK_2" -H 'Content-Type: application/json' -H "Origin: http://evil.example" -d '{"name":"evil"}')"
if grep -q '"success":false' "$WORK/cross.json"; then ok "cross origin API write not accepted"; else bad "cross origin write accepted"; fi

curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$WORK/page.html" "$BASE/owner" >/dev/null
TOKEN="$(csrf_meta "$WORK/page.html")"
HTTP="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_OWNER" -c "$JAR_OWNER" -X POST "$BASE/logout" --data-urlencode "_token=$TOKEN")"
if [ "$HTTP" = "302" ]; then ok "logout accepted"; else bad "logout HTTP $HTTP"; fi
HTTP="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_OWNER" "$BASE/dashboard")"
if [ "$HTTP" = "302" ]; then ok "session invalidated after logout"; else bad "session still valid after logout (HTTP $HTTP)"; fi

# =====================================================================
section "11. Logging coverage"
# =====================================================================
for event in 'auth.login.success' 'auth.login.failed' 'auth.logout' 'device.auth.success' 'device.register' 'access.denied' 'customer.create'; do
    COUNT="$(db "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE event='$event'")"
    if [ "$COUNT" -ge 1 ]; then ok "audit event recorded: $event ($COUNT)"; else bad "missing audit event: $event"; fi
done

COLUMNS="$(db "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${PREFIX}audit_logs' AND COLUMN_NAME IN ('created_at','actor_label','event','ip','description','result')")"
if [ "$COLUMNS" = "6" ]; then ok "audit rows carry time, actor, event, ip, description, result"; else bad "audit table misses columns ($COLUMNS/6)"; fi

# =====================================================================
section "12. Foreign projects untouched"
# =====================================================================
FOREIGN_AFTER="$(db "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME NOT LIKE '${PREFIX}%' ORDER BY TABLE_NAME" | md5sum)"
if [ "$FOREIGN_BEFORE" = "$FOREIGN_AFTER" ]; then ok "foreign table list unchanged ($FOREIGN_COUNT tables)"; else bad "foreign table list changed!"; fi

FOREIGN_ROWS="$(db "SELECT COUNT(*) FROM barber_appointments")$(db "SELECT COUNT(*) FROM cafe_orders")$(db "SELECT COUNT(*) FROM clinic_patients")$(db "SELECT COUNT(*) FROM zibaei_reservations")$(db "SELECT COUNT(*) FROM sarmadpf_posts")"
if [ "$FOREIGN_ROWS" = "11111" ]; then ok "foreign table data intact (5 decoy rows)"; else bad "foreign table data changed: $FOREIGN_ROWS"; fi

# =====================================================================
printf '\n=====================================\n'
printf 'RESULT: %s passed, %s failed, %s skipped\n' "$PASS" "$FAIL" "$SKIP"
printf '=====================================\n'
rm -rf "$WORK"
[ "$FAIL" -eq 0 ]
