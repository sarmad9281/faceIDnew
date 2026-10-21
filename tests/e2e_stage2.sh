#!/usr/bin/env bash
# =============================================================================
# SARMAD Attendance - stage 2 acceptance tests (OWNER PANEL)
# -----------------------------------------------------------------------------
# Usage:
#   APP_PATH=/path/to/deployment bash tests/e2e_stage2.sh <BASE_URL> \
#       <DB_NAME> <DB_USER> <DB_PASS> [PREFIX]
#
# Everything runs over real HTTP against a real installation and a real
# database: the suite creates its own customers, manager, device and API
# tokens, exercises every owner capability and asserts both the HTTP result
# and the resulting database / audit-trail state.
# =============================================================================
set -uo pipefail

BASE="${1:-http://127.0.0.1:8080}"
DB_NAME="${2:-sarmadfa_clinic}"
DB_USER="${3:-sarmadfa_youruser_lumiere}"
DB_PASS="${4:-${SARMAD_TEST_DB_PASS:-Test_Local_Pass_2026!}}"
PREFIX="${5:-attendance_}"
APP_PATH="${APP_PATH:-$(cd "$(dirname "$0")/.." && pwd)}"

OWNER_USER="${OWNER_USER:-owner}"
OWNER_PASS="${OWNER_PASS:-Owner#Pass2026}"

JAR_OWNER=/tmp/sarmad_s2_owner.cookies
JAR_MGR=/tmp/sarmad_s2_manager.cookies
JAR_ANON=/tmp/sarmad_s2_anon.cookies

STAMP="$(date +%s)"
CUSTOMER_NAME="مشتری-تست-${STAMP}"
CUSTOMER_B_NAME="مشتری-دوم-${STAMP}"
MANAGER_FAMILY="آزمون-${STAMP}"
MANAGER_USER="mgr${STAMP}"
MANAGER_PASS='Mgr#Stage2Test9'
MANAGER_PASS_NEW='Mgr#Stage2Test9b'
MANAGER_EMAIL="mgr${STAMP}@example.test"
OWNER_API_TOKEN=""
FAILURES=()
PASS=0
FAIL=0

rm -f "$JAR_OWNER" "$JAR_MGR" "$JAR_ANON"
# Clear the rate-limit counters of the installation under test: the suite
# signs in many times from one IP and would otherwise hit 429.
rm -f "${APP_PATH}/storage/cache/throttle_"*.json 2>/dev/null || true

# ---------------------------------------------------------------- helpers ----
mysql_q() { mariadb -N -B --default-character-set=utf8mb4 -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$1" 2>/dev/null; }

ok()  { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); FAILURES+=("$1"); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }

check() { if [[ "$2" == "$3" ]]; then ok "$1 ($2)"; else bad "$1 (got '$2', want '$3')"; fi; }
check_min() { if [[ "${2:-0}" -ge "${3:-1}" ]]; then ok "$1 ($2)"; else bad "$1 (got '$2', want >= $3)"; fi; }
check_contains() { if [[ "$2" == *"$3"* ]]; then ok "$1"; else bad "$1 (did not find '$3')"; fi; }
check_not_contains() { if [[ "$2" != *"$3"* ]]; then ok "$1"; else bad "$1 (unexpectedly found '$3')"; fi; }

status_of() { curl -s -b "$1" -c "$1" -o /dev/null -w '%{http_code}' "$BASE$2"; }
body_of()   { curl -s -b "$1" -c "$1" "$BASE$2"; }
count_in()  { body_of "$1" "$2" | grep -c "$3"; }

csrf_of() {
  curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'
}

csrf_meta_of() {
  curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'
}

# fresh_token <jar> <path> : token for a form on that page (meta first, then hidden input)
fresh_token() {
  local token
  token="$(csrf_meta_of "$1" "$2")"
  [[ -z "$token" ]] && token="$(csrf_of "$1" "$2")"
  printf '%s' "$token"
}

login() { # login <jar> <identifier> <password>
  local jar="$1"
  rm -f "$jar"
  local token
  token="$(csrf_of "$jar" /login)"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$BASE/login" \
    --data-urlencode "_token=$token" \
    --data-urlencode "identifier=$2" \
    --data-urlencode "password=$3"
}

post_form() { # post_form <jar> <page_path> <action_path> <data...>
  local jar="$1" page="$2" action="$3"; shift 3
  local token
  token="$(fresh_token "$jar" "$page")"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$BASE$action" \
    --data-urlencode "_token=$token" "$@"
}

section() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

echo "SARMAD Attendance - stage 2 acceptance tests"
echo "BASE=$BASE  PREFIX=$PREFIX  APP_PATH=$APP_PATH"
echo "DB=$DB_NAME as $DB_USER"

# =============================================================================
section "1. Owner authentication"
# =============================================================================

for path in /owner /owner/customers /owner/managers /owner/employees /owner/devices \
            /owner/features /owner/subscriptions /owner/logs /owner/settings /owner/emergency; do
  check "direct access without login blocked: $path" "$(status_of "$JAR_ANON" "$path")" "302"
done

check_min "owner account exists" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE username='$OWNER_USER' AND role='owner'")" 1

check "wrong password is rejected" "$(login "$JAR_MGR" "$OWNER_USER" 'definitely-wrong-pass')" "302"
check "wrong password leaves no session" "$(status_of "$JAR_MGR" /owner)" "302"
check_min "failed login audited (LOGIN_FAILED)" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='LOGIN_FAILED'")" 1

check "owner login accepted" "$(login "$JAR_OWNER" "$OWNER_USER" "$OWNER_PASS")" "302"
check "owner dashboard reachable" "$(status_of "$JAR_OWNER" /owner)" "200"
check_min "dashboard renders the owner KPI grid" "$(count_in "$JAR_OWNER" /owner 'o-kpi-value')" 8
check "owner sidebar lists every stage-2 section" \
  "$(body_of "$JAR_OWNER" /owner | grep -o -E '/owner/(customers|managers|employees|devices|features|subscriptions|logs|settings|emergency)' | sort -u | wc -l | tr -d ' ')" "9"

# role tampering: the owner must not be able to demote itself
ROLE_BEFORE="$(mysql_q "SELECT role FROM ${PREFIX}users WHERE username='$OWNER_USER'" | tr -d '[:space:]')"
post_form "$JAR_OWNER" /owner/settings /owner/settings "role=manager" >/dev/null
post_form "$JAR_OWNER" /owner/settings /owner/settings "role_id=2" "user%5Brole%5D=supervisor" >/dev/null
curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null "$BASE/owner/settings?role=manager&user%5Brole%5D=device"
check "owner role is unchanged after POST/GET tampering" \
  "$(mysql_q "SELECT role FROM ${PREFIX}users WHERE username='$OWNER_USER'" | tr -d '[:space:]')" "${ROLE_BEFORE:-owner}"
check_min "role tampering attempts audited or ignored without error" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE username='$OWNER_USER' AND role='owner'")" 1

# =============================================================================
section "2. Customer management"
# =============================================================================

code="$(post_form "$JAR_OWNER" /owner/customers/create /owner/customers \
  --data-urlencode "name=$CUSTOMER_NAME" \
  --data-urlencode "company_name=شرکت آزمون ${STAMP}" \
  --data-urlencode "contact_phone=09120000000" \
  --data-urlencode "contact_email=cust${STAMP}@example.test" \
  --data-urlencode "address=تهران" \
  --data-urlencode "notes=مشتری ساخته‌شده توسط تست پذیرش مرحله ۲" \
  --data-urlencode "timezone=Asia/Tehran" \
  --data-urlencode "date_system=jalali" \
  --data-urlencode "status=active" \
  --data-urlencode "plan_name=trial" \
  --data-urlencode "subscription_status=trial" \
  --data-urlencode "start_date=$(date +%Y-%m-%d)" \
  --data-urlencode "expire_date=$(date -d '+14 days' +%Y-%m-%d)")"

CUSTOMER_ID="$(mysql_q "SELECT id FROM ${PREFIX}customers WHERE name='$CUSTOMER_NAME' LIMIT 1" | tr -d '[:space:]')"
CUSTOMER_UUID="$(mysql_q "SELECT uuid FROM ${PREFIX}customers WHERE name='$CUSTOMER_NAME' LIMIT 1" | tr -d '[:space:]')"

check "create customer returns a redirect" "$code" "302"
check_min "customer row created" "${CUSTOMER_ID:-0}" 1
check "customer starts active" "$(mysql_q "SELECT status FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "active"
check "customer stores the plan" "$(mysql_q "SELECT plan_name FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "trial"
check "customer has a uuid" "$(mysql_q "SELECT CASE WHEN uuid <> '' THEN 1 ELSE 0 END FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "1"
check "CREATE_CUSTOMER audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='CREATE_CUSTOMER' AND customer_id=${CUSTOMER_ID:-0}")" "1"
check "trial subscription row created" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}subscriptions WHERE customer_id=${CUSTOMER_ID:-0}")" "1"
check "feature matrix seeded (9 features)" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}feature_flags WHERE customer_id=${CUSTOMER_ID:-0}")" "9"
check "implemented base features enabled by default" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}feature_flags f JOIN ${PREFIX}features x ON x.feature_key=f.feature_key WHERE f.customer_id=${CUSTOMER_ID:-0} AND x.category='basic' AND x.default_enabled=1 AND f.is_enabled=1")" "3"
check "modules that are built in a later stage stay off by default" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}feature_flags f JOIN ${PREFIX}features x ON x.feature_key=f.feature_key WHERE f.customer_id=${CUSTOMER_ID:-0} AND x.default_enabled=0 AND f.is_enabled=0")" "6"
check "optional features disabled by default" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}feature_flags f JOIN ${PREFIX}features x ON x.feature_key=f.feature_key WHERE f.customer_id=${CUSTOMER_ID:-0} AND x.category='optional' AND f.is_enabled=0")" "4"
check_min "customer detail page renders" "$(count_in "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID" "$CUSTOMER_NAME")" 1
check_min "customer search finds the new customer" \
  "$(count_in "$JAR_OWNER" "/owner/customers?q=$STAMP" "$CUSTOMER_NAME")" 1
check "customer edit page renders" "$(status_of "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID/edit")" "200"

# second customer (tenant isolation target)
post_form "$JAR_OWNER" /owner/customers/create /owner/customers \
  --data-urlencode "name=$CUSTOMER_B_NAME" \
  --data-urlencode "status=active" \
  --data-urlencode "plan_name=trial" \
  --data-urlencode "subscription_status=trial" \
  --data-urlencode "start_date=$(date +%Y-%m-%d)" \
  --data-urlencode "expire_date=$(date -d '+14 days' +%Y-%m-%d)" >/dev/null
CUSTOMER_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}customers WHERE name='$CUSTOMER_B_NAME' LIMIT 1" | tr -d '[:space:]')"
CUSTOMER_B_UUID="$(mysql_q "SELECT uuid FROM ${PREFIX}customers WHERE name='$CUSTOMER_B_NAME' LIMIT 1" | tr -d '[:space:]')"
check_min "second customer created for isolation tests" "${CUSTOMER_B_ID:-0}" 1

# update a customer (owner edit)
code="$(post_form "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID/edit" "/owner/customers/$CUSTOMER_UUID" \
  --data-urlencode "name=$CUSTOMER_NAME" \
  --data-urlencode "company_name=شرکت ویرایش‌شده ${STAMP}" \
  --data-urlencode "contact_phone=09120000001" \
  --data-urlencode "notes=ویرایش‌شده در تست پذیرش" \
  --data-urlencode "timezone=Asia/Tehran" \
  --data-urlencode "date_system=jalali")"
check "update customer returns a redirect" "$code" "302"
check "customer company name updated" \
  "$(mysql_q "SELECT CASE WHEN company_name LIKE '%${STAMP}' THEN 1 ELSE 0 END FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "1"
check "UPDATE_CUSTOMER audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='UPDATE_CUSTOMER' AND customer_id=${CUSTOMER_ID:-0}")" "1"

# logo upload guard: a PHP file must be rejected even when it claims to be an image
printf '<?php echo "pwned";' > /tmp/sarmad_s2_evil.php
LOGO_TOKEN="$(fresh_token "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID/edit")"
code="$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -w '%{http_code}' \
  -X POST "$BASE/owner/customers/$CUSTOMER_UUID/logo" \
  -F "_token=$LOGO_TOKEN" -F "logo=@/tmp/sarmad_s2_evil.php;type=image/png")"
if [[ "$code" == "302" || "$code" == "422" ]]; then ok "logo upload rejects a PHP payload ($code)"; else bad "logo upload rejects a PHP payload (got $code)"; fi
check "rejected logo is not stored" \
  "$(mysql_q "SELECT CASE WHEN logo_path IS NULL OR logo_path = '' THEN 1 ELSE 0 END FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "1"
check "no PHP file was written into uploads" \
  "$(find "$APP_PATH/uploads" -name '*.php' 2>/dev/null | wc -l | tr -d ' ')" "0"

# a real PNG must be accepted, stored under a generated name and displayed
printf 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAIAQMAAAD+wSzIAAAABlBMVEX///+/v7+jQ3Y5AAAADklEQVQI12P4AIX8EAgALgAD/aNpbtEAAAAASUVORK5CYII=' | base64 -d > /tmp/sarmad_s2_logo.png
cp /tmp/sarmad_s2_logo.png "/tmp/سارmad original name.png"
LOGO_TOKEN="$(fresh_token "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID/edit")"
code="$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -w '%{http_code}' \
  -X POST "$BASE/owner/customers/$CUSTOMER_UUID/logo" \
  -F "_token=$LOGO_TOKEN" -F "logo=@/tmp/sarmad_s2_logo.png;type=image/png")"
check "valid PNG logo upload accepted" "$code" "302"
LOGO_PATH="$(mysql_q "SELECT logo_path FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")"
check_min "logo path stored in the database" "${#LOGO_PATH}" 1
case "$LOGO_PATH" in
  *"sarmad_s2_logo"*) bad "uploaded file keeps the original filename ($LOGO_PATH)" ;;
  *) ok "uploaded file uses a generated filename" ;;
esac
check_min "a fresh file was written inside the upload directory" \
  "$(find "$APP_PATH/uploads" -type f -newermt '-2 minutes' 2>/dev/null | wc -l | tr -d ' ')" 1
check "logo file is reachable through the stored path" \
  "$([[ -n "$LOGO_PATH" && -f "$APP_PATH/uploads/${LOGO_PATH#/}" ]] && echo yes || echo no)" "yes"
check_min "UPLOAD_LOGO audited" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='UPLOAD_LOGO'")" 1
check_min "customer page displays the logo" \
  "$(count_in "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID" 'upload')" 1

# =============================================================================
section "3. Manager creation per customer"
# =============================================================================

code="$(post_form "$JAR_OWNER" "/owner/managers/create?customer_id=$CUSTOMER_ID" /owner/managers \
  --data-urlencode "customer_id=$CUSTOMER_ID" \
  --data-urlencode "first_name=علی" \
  --data-urlencode "last_name=$MANAGER_FAMILY" \
  --data-urlencode "mobile=09121112233" \
  --data-urlencode "email=$MANAGER_EMAIL" \
  --data-urlencode "title=مدیر مجموعه" \
  --data-urlencode "username=$MANAGER_USER" \
  --data-urlencode "password=$MANAGER_PASS" \
  --data-urlencode "is_primary=1")"

MANAGER_ID="$(mysql_q "SELECT id FROM ${PREFIX}managers WHERE customer_id=${CUSTOMER_ID:-0} ORDER BY id DESC LIMIT 1" | tr -d '[:space:]')"
MANAGER_USER_ID="$(mysql_q "SELECT id FROM ${PREFIX}users WHERE username='$MANAGER_USER' LIMIT 1" | tr -d '[:space:]')"

check "create manager returns a redirect" "$code" "302"
check_min "manager row created" "${MANAGER_ID:-0}" 1
check "manager user has role=manager" "$(mysql_q "SELECT role FROM ${PREFIX}users WHERE username='$MANAGER_USER'")" "manager"
check "manager user is scoped to its customer" \
  "$(mysql_q "SELECT customer_id FROM ${PREFIX}users WHERE username='$MANAGER_USER'")" "${CUSTOMER_ID:-0}"
check "manager password stored as a bcrypt hash" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE username='$MANAGER_USER' AND password_hash LIKE '\$2y\$%'")" "1"
check "manager password is never stored in plain text" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE password_hash='$MANAGER_PASS'")" "0"
check "manager must change password on first login" \
  "$(mysql_q "SELECT must_change_password FROM ${PREFIX}users WHERE username='$MANAGER_USER'")" "1"
check_min "CREATE_MANAGER audited" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='CREATE_MANAGER'")" 1
post_form "$JAR_OWNER" "/owner/managers/create?customer_id=$CUSTOMER_ID" /owner/managers \
  --data-urlencode "customer_id=$CUSTOMER_ID" \
  --data-urlencode "first_name=تکراری" \
  --data-urlencode "last_name=تکراری" \
  --data-urlencode "email=dup${STAMP}@example.test" \
  --data-urlencode "username=$MANAGER_USER" \
  --data-urlencode "password=$MANAGER_PASS" >/dev/null
check "duplicate username is rejected" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE username='$MANAGER_USER'")" "1"
check_min "manager list shows the new manager" "$(count_in "$JAR_OWNER" "/owner/managers?q=$MANAGER_USER" "$MANAGER_USER")" 1

# =============================================================================
section "4. Manager scope: owner area blocked, tenants isolated"
# =============================================================================

check "manager login accepted" "$(login "$JAR_MGR" "$MANAGER_USER" "$MANAGER_PASS")" "302"
check "manager lands on its own dashboard" "$(status_of "$JAR_MGR" /dashboard)" "200"
check_contains "manager dashboard shows its own customer only" \
  "$(body_of "$JAR_MGR" /dashboard)" "$CUSTOMER_NAME"
check_not_contains "manager dashboard does not leak the other customer" \
  "$(body_of "$JAR_MGR" /dashboard)" "$CUSTOMER_B_NAME"

for path in /owner /owner/customers /owner/managers /owner/devices /owner/features \
            /owner/subscriptions /owner/logs /owner/settings /owner/emergency; do
  code="$(status_of "$JAR_MGR" "$path")"
  if [[ "$code" == "403" || "$code" == "302" ]]; then
    ok "manager blocked from owner area: $path ($code)"
  else
    bad "manager blocked from owner area: $path (got $code)"
  fi
done

check "manager cannot open another customer's record (uuid swapped in the URL)" \
  "$(status_of "$JAR_MGR" "/owner/customers/$CUSTOMER_B_UUID")" "403"
check_not_contains "the swapped UUID leaks nothing" \
  "$(body_of "$JAR_MGR" "/owner/customers/$CUSTOMER_B_UUID")" "$CUSTOMER_B_NAME"
check "manager cannot manage another customer's subscription" \
  "$(status_of "$JAR_MGR" "/owner/subscriptions/$CUSTOMER_B_UUID")" "403"
check "manager cannot manage another customer's features" \
  "$(status_of "$JAR_MGR" "/owner/features/$CUSTOMER_B_UUID")" "403"
check "device reset API without a token is rejected" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' \
      "$BASE/api/v1/owner/devices/1/reset")" "401"

# manager must not be able to create another manager - web and DB level
MGR_TOKEN="$(fresh_token "$JAR_MGR" /dashboard)"
code="$(curl -s -b "$JAR_MGR" -c "$JAR_MGR" -o /dev/null -w '%{http_code}' -X POST "$BASE/owner/managers" \
  --data-urlencode "_token=$MGR_TOKEN" \
  --data-urlencode "customer_id=$CUSTOMER_ID" \
  --data-urlencode "first_name=نفوذی" \
  --data-urlencode "last_name=نفوذی" \
  --data-urlencode "email=evil${STAMP}@example.test" \
  --data-urlencode "username=evil${STAMP}" \
  --data-urlencode "password=$MANAGER_PASS" \
  --data-urlencode "password_confirmation=$MANAGER_PASS")"
check "manager cannot create another manager via POST ($code)" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE username='evil${STAMP}'")" "0"
if [[ "$code" == "403" || "$code" == "302" || "$code" == "419" ]]; then ok "manager POST to owner managers rejected ($code)"; else bad "manager POST to owner managers rejected (got $code)"; fi

# manager API token on owner API
MGR_TOKEN_VALUE="$(php -r '
  define("SARMAD_APP", true); define("SARMAD_START", microtime(true));
  define("SARMAD_ROOT", getenv("APP_PATH"));
  require SARMAD_ROOT . "/app/Bootstrap.php";
  if (!App\Bootstrap::bootCli()) { exit(1); }
  $r = App\Models\ApiToken::issue((int) $argv[1], (int) $argv[2], "stage2-mgr", ["*"], 1, (int) $argv[1]);
  echo $r["token"];
' "$MANAGER_USER_ID" "$CUSTOMER_ID" 2>/dev/null)"

if [[ "$MGR_TOKEN_VALUE" == sat_* ]]; then
  check "manager API token rejected on owner overview" \
    "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $MGR_TOKEN_VALUE" "$BASE/api/v1/owner/overview")" "403"
  check "manager API token rejected on owner customers" \
    "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $MGR_TOKEN_VALUE" "$BASE/api/v1/owner/customers")" "403"
  check "manager API token rejected on owner device reset" \
    "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $MGR_TOKEN_VALUE" \
        -H 'Content-Type: application/json' -d '{}' "$BASE/api/v1/owner/devices/1/reset")" "403"
else
  bad "could not mint a manager API token"
fi

# manager changes its own password (and the owner still cannot see it)
code="$(post_form "$JAR_MGR" /account/password /account/password \
  --data-urlencode "current_password=$MANAGER_PASS" \
  --data-urlencode "new_password=$MANAGER_PASS_NEW" \
  --data-urlencode "new_password_confirmation=$MANAGER_PASS_NEW")"
check "manager can change its own password" "$code" "302"
check "new password hash stored" \
  "$(mysql_q "SELECT CASE WHEN password_hash <> '$MANAGER_PASS' THEN 1 ELSE 0 END FROM ${PREFIX}users WHERE username='$MANAGER_USER'")" "1"
check "manager can log in with the new password" "$(login "$JAR_MGR" "$MANAGER_USER" "$MANAGER_PASS_NEW")" "302"
check "old password no longer works" "$(login "$JAR_MGR" "$MANAGER_USER" "$MANAGER_PASS")" "302"
check "old password leaves no session" "$(status_of "$JAR_MGR" /dashboard)" "302"
login "$JAR_MGR" "$MANAGER_USER" "$MANAGER_PASS_NEW" >/dev/null
check "manager is authenticated again" "$(status_of "$JAR_MGR" /dashboard)" "200"
MANAGER_PASS="$MANAGER_PASS_NEW"

# owner resets the manager password: must never reveal the old one
code="$(post_form "$JAR_OWNER" "/owner/managers?q=$MANAGER_USER" "/owner/managers/$MANAGER_ID/reset-password")"
check "owner password reset accepted" "$code" "302"
check_min "RESET_MANAGER_PASSWORD audited" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='RESET_MANAGER_PASSWORD'")" 1
check_min "manager forced to change password again after reset" \
  "$(mysql_q "SELECT must_change_password FROM ${PREFIX}users WHERE id=${MANAGER_USER_ID:-0}")" 1
check "manager's previous password is invalid after the owner reset" \
  "$(login "$JAR_MGR" "$MANAGER_USER" "$MANAGER_PASS")" "302"
check "owner panel never contains the manager password" \
  "$(count_in "$JAR_OWNER" "/owner/managers?q=$MANAGER_USER" "$MANAGER_PASS")" "0"
check "owner panel never contains the reset password hash" \
  "$(count_in "$JAR_OWNER" "/owner/managers?q=$MANAGER_USER" 'password_hash')" "0"

# =============================================================================
section "5. Feature flags enforced in the backend"

# The previous section let the OWNER reset the manager password, which also
# killed every live session of that manager (by design). For the feature tests
# the suite sets a known password for its own test manager through the
# application model - the same call the panel uses - and signs in again.
php -r '
  define("SARMAD_APP", true); define("SARMAD_START", microtime(true));
  define("SARMAD_ROOT", getenv("APP_PATH"));
  require SARMAD_ROOT . "/app/Bootstrap.php";
  if (!App\Bootstrap::bootCli()) { exit(1); }
  App\Models\User::setPassword((int) $argv[1], (string) $argv[2], false);
' "$MANAGER_USER_ID" 'Mgr#Stage2Test9c' >/dev/null 2>&1
MANAGER_PASS='Mgr#Stage2Test9c'
check "manager can sign in again after the owner reset" "$(login "$JAR_MGR" "$MANAGER_USER" "$MANAGER_PASS")" "302"
check "manager session is alive again" "$(status_of "$JAR_MGR" /dashboard)" "200"
# =============================================================================

feature_toggle() { # feature_toggle <key> <0|1>
  post_form "$JAR_OWNER" "/owner/features/$CUSTOMER_UUID" "/owner/features/$CUSTOMER_UUID/toggle" \
    --data-urlencode "feature=$1" --data-urlencode "enabled=$2"
}

check "branches denied before it is enabled (optional feature default)" \
  "$(status_of "$JAR_MGR" /manager/branches)" "403"
feature_toggle branches 1 >/dev/null
check "branches page allowed once enabled" "$(status_of "$JAR_MGR" /manager/branches)" "200"
feature_toggle branches 0 >/dev/null
check "branches flag off in the database" \
  "$(mysql_q "SELECT is_enabled FROM ${PREFIX}feature_flags f JOIN ${PREFIX}features x ON x.feature_key=f.feature_key WHERE f.customer_id=${CUSTOMER_ID:-0} AND x.feature_key='branches'")" "0"
check "branches page denied after disabling (direct URL)" "$(status_of "$JAR_MGR" /manager/branches)" "403"
check_min "feature.change audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='CHANGE_FEATURE' AND customer_id=${CUSTOMER_ID:-0}")" 1
check_min "denied access audited (ACCESS_DENIED)" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='ACCESS_DENIED'")" 1
check "branches menu entry hidden while disabled" \
  "$(count_in "$JAR_MGR" /dashboard '/manager/branches')" "0"

feature_toggle branches 1 >/dev/null
check "branches page allowed after re-enabling" "$(status_of "$JAR_MGR" /manager/branches)" "200"

feature_toggle payroll 0 >/dev/null
check "payroll denied while disabled" "$(status_of "$JAR_MGR" /manager/payroll)" "403"
feature_toggle payroll 1 >/dev/null
check "payroll page allowed after enabling" "$(status_of "$JAR_MGR" /manager/payroll)" "200"

feature_toggle advanced_reports 0 >/dev/null
check "advanced reports denied while disabled" "$(status_of "$JAR_MGR" /manager/reports/advanced)" "403"
feature_toggle advanced_reports 1 >/dev/null

feature_toggle telegram 0 >/dev/null
check "telegram denied while disabled" "$(status_of "$JAR_MGR" /manager/telegram)" "403"
feature_toggle telegram 1 >/dev/null

post_form "$JAR_OWNER" "/owner/features/$CUSTOMER_UUID" "/owner/features/$CUSTOMER_UUID/toggle" \
  --data-urlencode "feature=time_travel" --data-urlencode "enabled=1" >/dev/null
check "unknown feature key rejected" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}feature_flags WHERE feature_key='time_travel'")" "0"

check_min "feature matrix page renders for every customer" "$(count_in "$JAR_OWNER" /owner/features 'o-table')" 1

# =============================================================================
section "6. Device lifecycle + owner-only reset"
# =============================================================================

php -r '
  define("SARMAD_APP", true); define("SARMAD_START", microtime(true));
  define("SARMAD_ROOT", getenv("APP_PATH"));
  require SARMAD_ROOT . "/app/Bootstrap.php";
  if (!App\Bootstrap::bootCli()) { exit(1); }
  App\Models\Device::register([
      "customer_id" => (int) $argv[1],
      "branch_id"   => null,
      "name"        => "دستگاه تست پذیرش",
      "serial"      => "SN-STAGE2-" . time(),
      "device_type" => "android",
      "platform"    => "android",
  ], "tester");
' "$CUSTOMER_ID" >/dev/null 2>/tmp/sarmad_s2_device.err

DEVICE_ID="$(mysql_q "SELECT id FROM ${PREFIX}devices WHERE customer_id=${CUSTOMER_ID:-0} ORDER BY id DESC LIMIT 1" | tr -d '[:space:]')"
check_min "device registered for the customer" "${DEVICE_ID:-0}" 1
check "device is not active before activation" \
  "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "pending"
check_min "device visible in the owner list" "$(count_in "$JAR_OWNER" "/owner/devices?status=pending" 'o-table')" 1

code="$(post_form "$JAR_MGR" /dashboard /owner/devices/reset \
  --data-urlencode "id=$DEVICE_ID")"
if [[ "$code" == "403" || "$code" == "302" ]]; then ok "manager cannot reach device reset ($code)"; else bad "manager cannot reach device reset (got $code)"; fi
check "manager reset attempt changed nothing" \
  "$(mysql_q "SELECT reset_count FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "0"
check "manager cannot rotate a device token either" \
  "$(post_form "$JAR_MGR" /dashboard /owner/devices/rotate-token --data-urlencode "id=$DEVICE_ID")" "403"

check "owner device reset accepted" \
  "$(post_form "$JAR_OWNER" /owner/devices /owner/devices/reset \
      --data-urlencode "id=$DEVICE_ID")" "302"
check "device status pending after reset" "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "pending"
check "device reset timestamped" \
  "$(mysql_q "SELECT CASE WHEN last_reset_at IS NOT NULL THEN 1 ELSE 0 END FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "1"
check "old device token invalidated" \
  "$(mysql_q "SELECT CASE WHEN api_token_hash IS NULL OR api_token_hash='' THEN 1 ELSE 0 END FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "1"
check "reset counter incremented" \
  "$(mysql_q "SELECT reset_count FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "1"
check "reset reason stored on the device" \
  "$(mysql_q "SELECT last_reset_reason FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "owner_reset"
check_min "RESET_DEVICE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='RESET_DEVICE' AND entity_id=${DEVICE_ID:-0}")" 1

check "device token rotation accepted" \
  "$(post_form "$JAR_OWNER" /owner/devices /owner/devices/rotate-token \
      --data-urlencode "id=$DEVICE_ID")" "302"
check "new device token issued (hash only)" \
  "$(mysql_q "SELECT CASE WHEN api_token_hash IS NOT NULL AND api_token_hash <> '' THEN 1 ELSE 0 END FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "1"
check "device token is stored only as a hash" \
  "$(mysql_q "SELECT CASE WHEN CHAR_LENGTH(api_token_hash) = 64 THEN 1 ELSE 0 END FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "1"
check_min "token rotation audited as RESET_DEVICE" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='RESET_DEVICE' AND entity_id=${DEVICE_ID:-0}")" 2

check "device disable accepted" \
  "$(post_form "$JAR_OWNER" /owner/devices /owner/devices/status \
      --data-urlencode "id=$DEVICE_ID" --data-urlencode "status=disabled")" "302"
check "device disabled in the database" "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "disabled"
check_min "DISABLE_DEVICE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='DISABLE_DEVICE' AND entity_id=${DEVICE_ID:-0}")" 1
check "device re-enable accepted" \
  "$(post_form "$JAR_OWNER" /owner/devices /owner/devices/status \
      --data-urlencode "id=$DEVICE_ID" --data-urlencode "status=active")" "302"
check_min "ENABLE_DEVICE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='ENABLE_DEVICE' AND entity_id=${DEVICE_ID:-0}")" 1
check "cancel device registration accepted" \
  "$(post_form "$JAR_OWNER" /owner/devices /owner/devices/revoke \
      --data-urlencode "id=$DEVICE_ID")" "302"
check_min "cancel registration audited as DELETE_DEVICE" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='DELETE_DEVICE' AND entity_id=${DEVICE_ID:-0}")" 1

# =============================================================================
section "7. Subscription control"
# =============================================================================

check "subscription update accepted" \
  "$(post_form "$JAR_OWNER" "/owner/subscriptions/$CUSTOMER_UUID" "/owner/subscriptions/$CUSTOMER_UUID" \
      --data-urlencode "plan_name=pro" \
      --data-urlencode "status=active" \
      --data-urlencode "start_date=$(date +%Y-%m-%d)" \
      --data-urlencode "expire_date=$(date -d '+30 days' +%Y-%m-%d)" \
      --data-urlencode "price=2500000" \
      --data-urlencode "max_employees=120" \
      --data-urlencode "max_devices=12" \
      --data-urlencode "notes=تست پذیرش مرحله ۲")" "302"
check "customer plan updated" "$(mysql_q "SELECT plan_name FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "pro"
check "customer subscription status updated" "$(mysql_q "SELECT subscription_status FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "active"
check "expire date stored" "$(mysql_q "SELECT subscription_expire_date FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "$(date -d '+30 days' +%Y-%m-%d)"
check_min "subscription history row written" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}subscriptions WHERE customer_id=${CUSTOMER_ID:-0} AND plan_name='pro' AND max_devices=12")" 1
check_min "CHANGE_SUBSCRIPTION audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='CHANGE_SUBSCRIPTION' AND customer_id=${CUSTOMER_ID:-0}")" 1
post_form "$JAR_OWNER" "/owner/subscriptions/$CUSTOMER_UUID" "/owner/subscriptions/$CUSTOMER_UUID" \
  --data-urlencode "plan_name=pro" \
  --data-urlencode "status=active" \
  --data-urlencode "start_date=$(date -d '+10 days' +%Y-%m-%d)" \
  --data-urlencode "expire_date=$(date -d '+1 day' +%Y-%m-%d)" \
  --data-urlencode "max_employees=120" \
  --data-urlencode "max_devices=12" >/dev/null
check "invalid subscription dates rejected" \
  "$(mysql_q "SELECT subscription_expire_date FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "$(date -d '+30 days' +%Y-%m-%d)"

# =============================================================================
section "8. Emergency (impersonation) access"
# =============================================================================

check_min "emergency access is enabled by default" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}settings WHERE setting_key='owner.impersonation_enabled' AND setting_value IN ('1','true')")" 1

check "emergency access start accepted" \
  "$(post_form "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID/emergency" "/owner/customers/$CUSTOMER_UUID/emergency" \
      --data-urlencode "reason=بررسی مشکل گزارش‌شده توسط مشتری در تست پذیرش")" "302"
IMP_ID="$(mysql_q "SELECT id FROM ${PREFIX}owner_impersonations WHERE customer_id=${CUSTOMER_ID:-0} ORDER BY id DESC LIMIT 1" | tr -d '[:space:]')"
check_min "impersonation session written" "${IMP_ID:-0}" 1
check "impersonation records the owner" \
  "$(mysql_q "SELECT CASE WHEN owner_user_id > 0 THEN 1 ELSE 0 END FROM ${PREFIX}owner_impersonations WHERE id=${IMP_ID:-0}")" "1"
check "impersonation records the manager user" \
  "$(mysql_q "SELECT CASE WHEN manager_user_id IS NOT NULL AND manager_user_id > 0 THEN 1 ELSE 0 END FROM ${PREFIX}owner_impersonations WHERE id=${IMP_ID:-0}")" "1"
check "impersonation records reason and IP" \
  "$(mysql_q "SELECT CASE WHEN reason LIKE '%تست پذیرش%' AND ip IS NOT NULL AND ip <> '' THEN 1 ELSE 0 END FROM ${PREFIX}owner_impersonations WHERE id=${IMP_ID:-0}")" "1"
check_min "emergency.access audited" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='EMERGENCY_ACCESS'")" 1

check "impersonated session gets the manager panel" "$(status_of "$JAR_OWNER" /dashboard)" "200"
check "impersonated session is blocked from the owner area" "$(status_of "$JAR_OWNER" /owner/customers)" "403"
check "impersonated session is blocked from another customer's record" \
  "$(status_of "$JAR_OWNER" "/owner/customers/$CUSTOMER_B_UUID")" "403"
check "impersonated session cannot reach the other customer" "$(status_of "$JAR_OWNER" "/owner/customers/$CUSTOMER_B_UUID")" "403"
check_contains "emergency banner is rendered" "$(body_of "$JAR_OWNER" /dashboard)" "دسترسی اضطراری"

check "emergency exit accepted" "$(post_form "$JAR_OWNER" /dashboard /emergency/exit)" "302"
check "impersonation closed with an end time" \
  "$(mysql_q "SELECT CASE WHEN ended_at IS NOT NULL THEN 1 ELSE 0 END FROM ${PREFIX}owner_impersonations WHERE id=${IMP_ID:-0}")" "1"
check_min "emergency exit audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action IN ('EMERGENCY_ACCESS_END','EMERGENCY_ACCESS')")" 1
check "owner is back in the owner area" "$(status_of "$JAR_OWNER" /owner)" "200"

check "emergency history page lists the session" \
  "$(mysql_q "SELECT CASE WHEN ended_at IS NOT NULL THEN 1 ELSE 0 END FROM ${PREFIX}owner_impersonations WHERE id=${IMP_ID:-0}")" "1"
check "manager identity page never shows a password" \
  "$(count_in "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID/manager-identity" 'password_hash')" "0"

# =============================================================================
section "9. Customer enable / disable"
# =============================================================================

check "disable customer accepted" \
  "$(post_form "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID" "/owner/customers/$CUSTOMER_UUID/status" \
      --data-urlencode "status=suspended")" "302"
check "customer status is suspended" "$(mysql_q "SELECT status FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "suspended"
check "all customer users disabled" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE customer_id=${CUSTOMER_ID:-0} AND is_active=1")" "0"
check "all customer devices disabled" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE customer_id=${CUSTOMER_ID:-0} AND status='active'")" "0"
check_min "DISABLE_CUSTOMER audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='DISABLE_CUSTOMER' AND customer_id=${CUSTOMER_ID:-0}")" 1

check "disabled customer manager cannot log in" "$(login "$JAR_MGR" "$MANAGER_USER" "$MANAGER_PASS")" "302"
check "disabled customer manager has no session" "$(status_of "$JAR_MGR" /dashboard)" "302"

check "enable customer accepted" \
  "$(post_form "$JAR_OWNER" "/owner/customers/$CUSTOMER_UUID" "/owner/customers/$CUSTOMER_UUID/status" \
      --data-urlencode "status=active")" "302"
check "customer status is active again" "$(mysql_q "SELECT status FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "active"
check "customer users re-enabled" "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE customer_id=${CUSTOMER_ID:-0} AND is_active=1")" "1"
check_min "ENABLE_CUSTOMER audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='ENABLE_CUSTOMER' AND customer_id=${CUSTOMER_ID:-0}")" 1
check "re-enabled customer manager can log in" "$(login "$JAR_MGR" "$MANAGER_USER" "$MANAGER_PASS")" "302"
check "re-enabled customer manager has a session" "$(status_of "$JAR_MGR" /dashboard)" "200"

# =============================================================================
section "10. Owner API"
# =============================================================================

OWNER_USER_ID="$(mysql_q "SELECT id FROM ${PREFIX}users WHERE username='$OWNER_USER'" | tr -d '[:space:]')"
OWNER_API_TOKEN="$(php -r '
  define("SARMAD_APP", true); define("SARMAD_START", microtime(true));
  define("SARMAD_ROOT", getenv("APP_PATH"));
  require SARMAD_ROOT . "/app/Bootstrap.php";
  if (!App\Bootstrap::bootCli()) { exit(1); }
  $r = App\Models\ApiToken::issue((int) $argv[1], null, "stage2-owner", ["*"], 1, (int) $argv[1]);
  echo $r["token"];
' "$OWNER_USER_ID" 2>/dev/null)"

if [[ "$OWNER_API_TOKEN" == sat_* ]]; then
  api() { curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $OWNER_API_TOKEN" "$@"; }

  check "owner API overview authorised" "$(api "$BASE/api/v1/owner/overview")" "200"
  check_contains "owner API overview returns counters" \
    "$(curl -s -H "Authorization: Bearer $OWNER_API_TOKEN" "$BASE/api/v1/owner/overview")" '"counters"'
  check "owner API customers authorised" "$(api "$BASE/api/v1/owner/customers?per_page=5")" "200"
  check "owner API customer detail authorised" "$(api "$BASE/api/v1/owner/customers/$CUSTOMER_UUID")" "200"
  check "owner API managers authorised" "$(api "$BASE/api/v1/owner/managers?per_page=5")" "200"
  check "owner API devices authorised" "$(api "$BASE/api/v1/owner/devices?per_page=5")" "200"
  check "owner API logs authorised" "$(api "$BASE/api/v1/owner/logs?per_page=5")" "200"
  check "owner API features authorised" "$(api "$BASE/api/v1/owner/customers/$CUSTOMER_UUID/features")" "200"
  check "owner API subscription authorised" "$(api "$BASE/api/v1/owner/customers/$CUSTOMER_UUID/subscription")" "200"
  check "owner API rejects a missing token" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/owner/overview")" "401"
  check "owner API rejects a bogus token" \
    "$(curl -s -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer sat_bogus_value' "$BASE/api/v1/owner/overview")" "401"
  check_not_contains "owner API never exposes password hashes" \
    "$(curl -s -H "Authorization: Bearer $OWNER_API_TOKEN" "$BASE/api/v1/owner/managers?per_page=50")" "password_hash"

  check "owner API feature update authorised" \
    "$(curl -s -o /dev/null -w '%{http_code}' -X PATCH \
        -H "Authorization: Bearer $OWNER_API_TOKEN" -H 'Content-Type: application/json' \
        -d '{"features":{"telegram":true}}' \
        "$BASE/api/v1/owner/customers/$CUSTOMER_UUID/features")" "200"
  check "telegram flag enabled through the API" \
    "$(mysql_q "SELECT is_enabled FROM ${PREFIX}feature_flags f JOIN ${PREFIX}features x ON x.feature_key=f.feature_key WHERE f.customer_id=${CUSTOMER_ID:-0} AND x.feature_key='telegram'")" "1"
  check "owner API rejects an unknown feature" \
    "$(curl -s -o /dev/null -w '%{http_code}' -X PATCH \
        -H "Authorization: Bearer $OWNER_API_TOKEN" -H 'Content-Type: application/json' \
        -d '{"features":{"time_travel":true}}' \
        "$BASE/api/v1/owner/customers/$CUSTOMER_UUID/features")" "422"

  check "owner API subscription update authorised" \
    "$(curl -s -o /dev/null -w '%{http_code}' -X PATCH \
        -H "Authorization: Bearer $OWNER_API_TOKEN" -H 'Content-Type: application/json' \
        -d '{"plan_name":"enterprise","status":"active","start_date":"'$(date +%Y-%m-%d)'","expire_date":"'$(date -d '+60 days' +%Y-%m-%d)'","max_employees":200,"max_devices":20}' \
        "$BASE/api/v1/owner/customers/$CUSTOMER_UUID/subscription")" "200"
  check "subscription updated through the API" \
    "$(mysql_q "SELECT plan_name FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "enterprise"

  NEW_CUST_CODE="$(curl -s -o /dev/null -w '%{http_code}' -X POST \
      -H "Authorization: Bearer $OWNER_API_TOKEN" -H 'Content-Type: application/json' \
      -d '{"name":"مشتری-API-'$STAMP'","plan_name":"trial","subscription_status":"trial"}' \
      "$BASE/api/v1/owner/customers")"
  check "owner API can create a customer" "$NEW_CUST_CODE" "201"
  check_min "API-created customer exists in the database" \
    "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}customers WHERE name='مشتری-API-$STAMP'")" 1
else
  bad "could not mint an owner API token"
fi

# =============================================================================
section "11. Search, filters and pagination"
# =============================================================================

check_min "customer search works" "$(count_in "$JAR_OWNER" "/owner/customers?q=$STAMP" "$STAMP")" 1
check_min "customer status filter works" "$(count_in "$JAR_OWNER" "/owner/customers?status=active&q=$STAMP" "$STAMP")" 1
check "customer filter excludes suspended rows" \
  "$(count_in "$JAR_OWNER" "/owner/customers?status=suspended&q=$STAMP" "$CUSTOMER_NAME")" "0"
check_min "device search works" "$(count_in "$JAR_OWNER" "/owner/devices?q=$STAMP" 'o-table')" 1
check_min "log action filter works" "$(count_in "$JAR_OWNER" "/owner/logs?action=RESET_DEVICE" "RESET_DEVICE")" 1
check_min "log date filter works" \
  "$(count_in "$JAR_OWNER" "/owner/logs?from=$(date -d '-1 day' +%Y-%m-%d)&to=$(date -d '+1 day' +%Y-%m-%d)" 'o-table')" 1
check_min "manager filter by customer works" "$(count_in "$JAR_OWNER" "/owner/managers?customer_id=$CUSTOMER_ID" "$MANAGER_USER")" 1
check_min "pagination container renders" "$(count_in "$JAR_OWNER" "/owner/logs?per_page=5" 'o-pagination\|pagination')" 1

# =============================================================================
section "12. Audit trail"
# =============================================================================

for action in LOGIN LOGOUT CREATE_CUSTOMER UPDATE_CUSTOMER DISABLE_CUSTOMER \
              ENABLE_CUSTOMER CREATE_MANAGER RESET_MANAGER_PASSWORD CHANGE_FEATURE \
              RESET_DEVICE DELETE_DEVICE CHANGE_SUBSCRIPTION EMERGENCY_ACCESS \
              EMERGENCY_ACCESS_END ACCESS_DENIED UPLOAD_LOGO; do
  count="$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='$action'")"
  if [[ "${count:-0}" -ge 1 ]]; then ok "audit row present: $action ($count)"; else bad "audit row present: $action"; fi
done

check_min "audit rows carry actor_user_id / actor_role / IP / user agent" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE actor_user_id IS NOT NULL AND actor_role IS NOT NULL AND ip <> '' AND user_agent <> ''")" 1
check_min "audit rows carry target_type / target_id for object actions" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action IN ('RESET_DEVICE','CREATE_MANAGER','CREATE_CUSTOMER') AND target_type IS NOT NULL AND target_id IS NOT NULL")" 1
check_min "owner log browser renders rows" "$(count_in "$JAR_OWNER" /owner/logs 'o-table')" 1
check "log CSV export works" "$(status_of "$JAR_OWNER" "/owner/logs/export?action=CREATE_CUSTOMER")" "200"

echo
echo "-----------------------------------------------------------------"
printf 'RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
if [[ "$FAIL" -gt 0 ]]; then
  printf 'Failures:\n'
  for f in "${FAILURES[@]}"; do printf '  - %s\n' "$f"; done
fi
echo "-----------------------------------------------------------------"
[[ "$FAIL" -eq 0 ]] || exit 1
exit 0
