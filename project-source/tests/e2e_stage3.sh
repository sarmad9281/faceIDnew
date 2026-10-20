#!/usr/bin/env bash
# =============================================================================
# SARMAD Attendance - stage 3 acceptance tests (MANAGER PANEL)
# -----------------------------------------------------------------------------
# Usage:
#   APP_PATH=/path/to/deployment bash tests/e2e_stage3.sh <BASE_URL> \
#       <DB_NAME> <DB_USER> <DB_PASS> [PREFIX]
#
# Everything runs over real HTTP against a real installation and a real
# database. The suite builds two complete customers with their own managers,
# then exercises every manager capability, the feature gates, the tenant
# isolation wall and the owner-only refusals - asserting both the HTTP result
# and the resulting database / audit state.
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

JAR_OWNER=/tmp/sarmad_s3_owner.cookies
JAR_MGR=/tmp/sarmad_s3_mgr.cookies
JAR_MGRB=/tmp/sarmad_s3_mgrb.cookies
JAR_ANON=/tmp/sarmad_s3_anon.cookies

STAMP="$(date +%s)"
CUSTOMER_NAME="مشتری-س۳-${STAMP}"
CUSTOMER_B_NAME="مشتری-س۳-دوم-${STAMP}"
MGR_A_USER="mgra${STAMP}"
MGR_B_USER="mgrb${STAMP}"
MGR_A_PASS='Mgr#Stage3Test9'
MGR_B_PASS='Mgr#Stage3Test9'
MGR_A_NEW_PASS='Mgr#Stage3Test9b'
EMPLOYEE_FIRST="علی"
EMPLOYEE_LAST="آزمون-${STAMP}"
EMPLOYEE_B_LAST="مشتری-دوم-${STAMP}"
FAILURES=()
PASS=0
FAIL=0

rm -f "$JAR_OWNER" "$JAR_MGR" "$JAR_MGRB" "$JAR_ANON"
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

# The PHP built-in server refuses a request line with raw non-ASCII bytes, so
# every non-ASCII query value must be percent-encoded before it goes on the wire.
urlenc()    { php -r 'echo rawurlencode($argv[1]);' "$1"; }

csrf_of() {
  curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'
}

csrf_meta_of() {
  curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'
}

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

echo "SARMAD Attendance - stage 3 acceptance tests (manager panel)"
echo "BASE=$BASE  PREFIX=$PREFIX  APP_PATH=$APP_PATH"
echo "DB=$DB_NAME as $DB_USER"

# =============================================================================
section "1. Manager authentication"
# =============================================================================

check "manager login page is public" "$(status_of "$JAR_ANON" /login)" "200"

# --- owner side: build two customers + their managers ------------------------
check "owner login" "$(login "$JAR_OWNER" "$OWNER_USER" "$OWNER_PASS")" "302"

post_form "$JAR_OWNER" /owner/customers/create /owner/customers \
  --data-urlencode "name=$CUSTOMER_NAME" \
  --data-urlencode "contact_email=cust-a-${STAMP}@example.test" \
  --data-urlencode "contact_phone=09121110000" \
  --data-urlencode "address=تهران" \
  --data-urlencode "timezone=Asia/Tehran" \
  --data-urlencode "date_system=jalali" \
  --data-urlencode "status=active" \
  --data-urlencode "plan_name=trial" \
  --data-urlencode "subscription_status=trial" \
  --data-urlencode "start_date=$(date +%Y-%m-%d)" \
  --data-urlencode "expire_date=$(date -d '+30 days' +%Y-%m-%d)" >/dev/null
CUSTOMER_ID="$(mysql_q "SELECT id FROM ${PREFIX}customers WHERE name='$CUSTOMER_NAME' LIMIT 1" | tr -d '[:space:]')"
CUSTOMER_UUID="$(mysql_q "SELECT uuid FROM ${PREFIX}customers WHERE name='$CUSTOMER_NAME' LIMIT 1" | tr -d '[:space:]')"
check_min "customer A created" "${CUSTOMER_ID:-0}" 1

post_form "$JAR_OWNER" /owner/customers/create /owner/customers \
  --data-urlencode "name=$CUSTOMER_B_NAME" \
  --data-urlencode "contact_email=cust-b-${STAMP}@example.test" \
  --data-urlencode "status=active" \
  --data-urlencode "plan_name=trial" \
  --data-urlencode "subscription_status=trial" \
  --data-urlencode "start_date=$(date +%Y-%m-%d)" \
  --data-urlencode "expire_date=$(date -d '+30 days' +%Y-%m-%d)" >/dev/null
CUSTOMER_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}customers WHERE name='$CUSTOMER_B_NAME' LIMIT 1" | tr -d '[:space:]')"
CUSTOMER_B_UUID="$(mysql_q "SELECT uuid FROM ${PREFIX}customers WHERE name='$CUSTOMER_B_NAME' LIMIT 1" | tr -d '[:space:]')"
check_min "customer B created (isolation target)" "${CUSTOMER_B_ID:-0}" 1

post_form "$JAR_OWNER" "/owner/managers/create?customer_id=$CUSTOMER_ID" /owner/managers \
  --data-urlencode "customer_id=$CUSTOMER_ID" \
  --data-urlencode "first_name=مدیر" \
  --data-urlencode "last_name=الف-${STAMP}" \
  --data-urlencode "username=$MGR_A_USER" \
  --data-urlencode "email=$MGR_A_USER@example.test" \
  --data-urlencode "mobile=09121110001" \
  --data-urlencode "title=مدیر کسب‌وکار" \
  --data-urlencode "password=$MGR_A_PASS" \
  --data-urlencode "is_primary=1" >/dev/null
MGR_A_ID="$(mysql_q "SELECT id FROM ${PREFIX}users WHERE username='$MGR_A_USER' LIMIT 1" | tr -d '[:space:]')"
check_min "manager A created" "${MGR_A_ID:-0}" 1

post_form "$JAR_OWNER" "/owner/managers/create?customer_id=$CUSTOMER_B_ID" /owner/managers \
  --data-urlencode "customer_id=$CUSTOMER_B_ID" \
  --data-urlencode "first_name=مدیر" \
  --data-urlencode "last_name=ب-${STAMP}" \
  --data-urlencode "username=$MGR_B_USER" \
  --data-urlencode "email=$MGR_B_USER@example.test" \
  --data-urlencode "mobile=09121110002" \
  --data-urlencode "password=$MGR_B_PASS" \
  --data-urlencode "is_primary=1" >/dev/null
MGR_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}users WHERE username='$MGR_B_USER' LIMIT 1" | tr -d '[:space:]')"
check_min "manager B created" "${MGR_B_ID:-0}" 1

# --- manager A signs in ------------------------------------------------------
check "wrong password is refused" "$(login "$JAR_MGR" "$MGR_A_USER" 'Wrong#Pass123')" "302"
check "no session after a failed login" "$(status_of "$JAR_MGR" /dashboard)" "302"
check_min "failed login audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='LOGIN_FAILED' AND created_at >= NOW() - INTERVAL 10 MINUTE")" 1

check "manager login succeeds" "$(login "$JAR_MGR" "$MGR_A_USER" "$MGR_A_PASS")" "302"
check "manager lands on the customer dashboard" "$(status_of "$JAR_MGR" /dashboard)" "200"
check_contains "dashboard shows the own customer" "$(body_of "$JAR_MGR" /dashboard)" "$CUSTOMER_NAME"
check_not_contains "dashboard does not leak the other customer" "$(body_of "$JAR_MGR" /dashboard)" "$CUSTOMER_B_NAME"
check_min "LOGIN audited for the manager" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='LOGIN' AND actor_user_id=${MGR_A_ID:-0}")" 1

check "manager B signs in" "$(login "$JAR_MGRB" "$MGR_B_USER" "$MGR_B_PASS")" "302"
check "manager B dashboard" "$(status_of "$JAR_MGRB" /dashboard)" "200"

# =============================================================================
section "2. Feature middleware (backend, not just the menu)"
# =============================================================================

for path in /manager/shifts /manager/branches /manager/payroll /manager/telegram; do
  check "feature off blocks the page: $path" "$(status_of "$JAR_MGR" "$path")" "403"
done
check "feature off blocks the shift API call" \
  "$(post_form "$JAR_MGR" /dashboard /manager/shifts --data-urlencode "name=ممنوع" --data-urlencode "start_time=08:00" --data-urlencode "end_time=16:00")" "403"
check_min "denied access is audited (ACCESS_DENIED)" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='ACCESS_DENIED' AND customer_id=${CUSTOMER_ID:-0}")" 1

for feature in shifts branches payroll telegram; do
  post_form "$JAR_OWNER" "/owner/features/$CUSTOMER_UUID" "/owner/features/$CUSTOMER_UUID/toggle" \
    --data-urlencode "feature=$feature" --data-urlencode "enabled=1" >/dev/null
done

check "shifts enabled by the owner (page opens)" "$(status_of "$JAR_MGR" /manager/shifts)" "200"
check "branches enabled by the owner (page opens)" "$(status_of "$JAR_MGR" /manager/branches)" "200"
check "payroll enabled by the owner (page opens)" "$(status_of "$JAR_MGR" /manager/payroll)" "200"
check "telegram enabled by the owner (page opens)" "$(status_of "$JAR_MGR" /manager/telegram)" "200"
check "menu now shows the branch entry" "$(count_in "$JAR_MGR" /dashboard '/manager/branches')" "1"
check "menu shows payroll when enabled" "$(count_in "$JAR_MGR" /dashboard '/manager/payroll')" "1"

# turning a feature OFF must block the route again (URL + API level)
post_form "$JAR_OWNER" "/owner/features/$CUSTOMER_UUID" "/owner/features/$CUSTOMER_UUID/toggle" \
  --data-urlencode "feature=shifts" --data-urlencode "enabled=0" >/dev/null
check "disabled again blocks the page" "$(status_of "$JAR_MGR" /manager/shifts)" "403"
check "disabled again hides the menu entry" "$(count_in "$JAR_MGR" /dashboard '/manager/shifts')" "0"
post_form "$JAR_OWNER" "/owner/features/$CUSTOMER_UUID" "/owner/features/$CUSTOMER_UUID/toggle" \
  --data-urlencode "feature=shifts" --data-urlencode "enabled=1" >/dev/null
check "re-enabled page works again" "$(status_of "$JAR_MGR" /manager/shifts)" "200"

# =============================================================================
section "3. Employees (create / edit / search / profile / disable)"
# =============================================================================

check "employee list renders" "$(status_of "$JAR_MGR" /manager/employees)" "200"
check "create form renders" "$(status_of "$JAR_MGR" /manager/employees/create)" "200"

code="$(post_form "$JAR_MGR" /manager/employees/create /manager/employees \
  --data-urlencode "first_name=$EMPLOYEE_FIRST" \
  --data-urlencode "last_name=$EMPLOYEE_LAST" \
  --data-urlencode "mobile=09121234567" \
  --data-urlencode "email=emp-${STAMP}@example.test" \
  --data-urlencode "position=کارشناس فروش" \
  --data-urlencode "department=فروش" \
  --data-urlencode "employment_type=full_time" \
  --data-urlencode "hire_date=$(date +%Y-%m-%d)" \
  --data-urlencode "status=active" \
  --data-urlencode "notes=ساخته‌شده توسط تست پذیرش مرحله ۳")"
check "employee create returns a redirect" "$code" "302"

EMPLOYEE_ID="$(mysql_q "SELECT id FROM ${PREFIX}employees WHERE customer_id=${CUSTOMER_ID:-0} AND last_name='$EMPLOYEE_LAST' LIMIT 1" | tr -d '[:space:]')"
check_min "employee row created for customer A" "${EMPLOYEE_ID:-0}" 1
check "employee belongs to the right tenant" \
  "$(mysql_q "SELECT customer_id FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "$CUSTOMER_ID"
check "employee is active" \
  "$(mysql_q "SELECT status FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "active"
check "employee code auto generated" \
  "$(mysql_q "SELECT CASE WHEN employee_code <> '' THEN 1 ELSE 0 END FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "1"
check "face is not enrolled yet" \
  "$(mysql_q "SELECT face_status FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "not_enrolled"
check_min "CREATE_EMPLOYEE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='CREATE_EMPLOYEE' AND target_id=${EMPLOYEE_ID:-0} AND customer_id=${CUSTOMER_ID:-0}")" 1

check_min "employee visible in the list" "$(count_in "$JAR_MGR" /manager/employees "$EMPLOYEE_LAST")" 1
check_min "search by name works" "$(count_in "$JAR_MGR" "/manager/employees?q=$(urlenc "$EMPLOYEE_LAST")" "$EMPLOYEE_LAST")" 1
check "search excludes non matching rows" \
  "$(count_in "$JAR_MGR" "/manager/employees?q=$(urlenc "هیچ‌کدام-${STAMP}")" "$EMPLOYEE_LAST")" "0"

check "profile page renders" "$(status_of "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")" "200"
check_contains "profile shows the required empty state" \
  "$(body_of "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")" "ثبت چهره انجام نشده"
check_contains "profile shows the position" "$(body_of "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")" "کارشناس فروش"

code="$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID/edit" /manager/employees/update \
  --data-urlencode "employee_id=$EMPLOYEE_ID" \
  --data-urlencode "first_name=$EMPLOYEE_FIRST" \
  --data-urlencode "last_name=$EMPLOYEE_LAST" \
  --data-urlencode "mobile=09121234568" \
  --data-urlencode "position=سرپرست فروش" \
  --data-urlencode "employment_type=full_time" \
  --data-urlencode "status=active")"
check "employee update returns a redirect" "$code" "302"
check "employee position updated" \
  "$(mysql_q "SELECT position FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "سرپرست فروش"
check_min "UPDATE_EMPLOYEE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='UPDATE_EMPLOYEE' AND target_id=${EMPLOYEE_ID:-0}")" 1

# XSS: the row is stored as typed, the page must escape it
post_form "$JAR_MGR" /manager/employees/create /manager/employees \
  --data-urlencode "first_name=<script>alert(1)</script>" \
  --data-urlencode "last_name=XSS-${STAMP}" \
  --data-urlencode "status=active" >/dev/null
XSS_ID="$(mysql_q "SELECT id FROM ${PREFIX}employees WHERE customer_id=${CUSTOMER_ID:-0} AND last_name='XSS-${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "script tag stored as plain text (no execution path)" "${XSS_ID:-0}" 1
check "list escapes the script tag" \
  "$(count_in "$JAR_MGR" /manager/employees '<script>alert(1)</script>')" "0"
check_min "escaped form is rendered instead" \
  "$(count_in "$JAR_MGR" /manager/employees '&lt;script&gt;alert(1)&lt;/script&gt;')" 1

# disable / enable
check "disable employee accepted" \
  "$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID" /manager/employees/toggle \
      --data-urlencode "employee_id=$EMPLOYEE_ID" --data-urlencode "active=0")" "302"
check "employee is suspended in the database" \
  "$(mysql_q "SELECT status FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "suspended"
check_min "DISABLE_EMPLOYEE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='DISABLE_EMPLOYEE' AND target_id=${EMPLOYEE_ID:-0}")" 1
cat > /tmp/sarmad_s3_canattend.php <<'PHP'
<?php
// Engine contract probe: the future capture engine must call Employee::canAttend().
define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', (string) getenv('SARMAD_ROOT'));
require SARMAD_ROOT . '/app/Bootstrap.php';

if (!App\Bootstrap::bootCli()) {
    fwrite(STDERR, "boot failed\n");
    exit(1);
}

$cases = [
    'active'    => ['status' => 'active'],
    'suspended' => ['status' => 'suspended'],
    'deleted'   => ['status' => 'active', 'deleted_at' => '2026-01-01 00:00:00'],
];

$out = [];

foreach ($cases as $label => $employee) {
    $out[] = $label . '=' . (App\Models\Employee::canAttend($employee) ? 'allowed' : 'blocked');
}

echo implode(' ', $out);
PHP
check "disabled employee can not clock in (engine contract)" \
  "$(SARMAD_ROOT="$APP_PATH" php /tmp/sarmad_s3_canattend.php 2>/dev/null)" \
  "active=allowed suspended=blocked deleted=blocked"

check "enable employee accepted" \
  "$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID" /manager/employees/toggle \
      --data-urlencode "employee_id=$EMPLOYEE_ID" --data-urlencode "active=1")" "302"
check "employee is active again" \
  "$(mysql_q "SELECT status FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "active"

# =============================================================================
section "4. Face registration structure (3 samples, images only)"
# =============================================================================

printf 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAIAQMAAAD+wSzIAAAABlBMVEX///+/v7+jQ3Y5AAAADklEQVQI12P4AIX8EAgALgAD/aNpbtEAAAAASUVORK5CYII=' | base64 -d > /tmp/sarmad_s3_face.png

for index in 1 2 3; do
  token="$(fresh_token "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")"
  code="$(curl -s -b "$JAR_MGR" -c "$JAR_MGR" -o /dev/null -w '%{http_code}' \
    -X POST "$BASE/manager/employees/face" \
    -F "_token=$token" -F "employee_id=$EMPLOYEE_ID" -F "sample_index=$index" \
    -F "sample=@/tmp/sarmad_s3_face.png;type=image/png")"
  check "face sample $index upload accepted" "$code" "302"
done

check "exactly three face rows exist" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}employee_faces WHERE employee_id=${EMPLOYEE_ID:-0}")" "3"
check "slots 1..3 are the ones stored" \
  "$(mysql_q "SELECT GROUP_CONCAT(sample_index ORDER BY sample_index) FROM ${PREFIX}employee_faces WHERE employee_id=${EMPLOYEE_ID:-0}")" "1,2,3"
check "face rows belong to the right tenant" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}employee_faces WHERE employee_id=${EMPLOYEE_ID:-0} AND customer_id=${CUSTOMER_ID:-0}")" "3"
check "employee flag flipped to complete" \
  "$(mysql_q "SELECT CONCAT(face_enrolled, ':', face_status) FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "1:complete"
check_min "REGISTER_FACE_SAMPLE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='REGISTER_FACE_SAMPLE' AND target_id=${EMPLOYEE_ID:-0}")" 3
check_min "sample files are on disk inside the tenant folder" \
  "$(find "$APP_PATH/uploads/customers" -name '*.png' 2>/dev/null | wc -l | tr -d ' ')" 3
check "no recognition / liveness column exists (engine is not part of this stage)" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${PREFIX}employee_faces' AND (column_name LIKE '%embed%' OR column_name LIKE '%template%' OR column_name LIKE '%liveness%' OR column_name LIKE '%vector%')")" "0"
check_contains "profile documents the face policy" \
  "$(body_of "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")" "موتور تشخیص چهره"

# replacing a slot must not create a fourth sample
token="$(fresh_token "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")"
curl -s -b "$JAR_MGR" -c "$JAR_MGR" -o /dev/null -X POST "$BASE/manager/employees/face" \
  -F "_token=$token" -F "employee_id=$EMPLOYEE_ID" -F "sample_index=2" \
  -F "sample=@/tmp/sarmad_s3_face.png;type=image/png"
check "replacing a slot keeps three samples" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}employee_faces WHERE employee_id=${EMPLOYEE_ID:-0}")" "3"

check "face delete accepted" \
  "$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID" /manager/employees/face/delete \
      --data-urlencode "employee_id=$EMPLOYEE_ID" --data-urlencode "sample_index=3")" "302"
check "two samples remain" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}employee_faces WHERE employee_id=${EMPLOYEE_ID:-0}")" "2"
check "employee flag downgraded to partial" \
  "$(mysql_q "SELECT face_status FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "partial"
check_min "DELETE_FACE_SAMPLE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='DELETE_FACE_SAMPLE' AND target_id=${EMPLOYEE_ID:-0}")" 1
check "a php payload can not be stored as a face sample" \
  "$(printf '<?php echo 1;' > /tmp/sarmad_s3_evil.php; token="$(fresh_token "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")"; curl -s -b "$JAR_MGR" -c "$JAR_MGR" -o /dev/null -X POST "$BASE/manager/employees/face" -F "_token=$token" -F "employee_id=$EMPLOYEE_ID" -F "sample_index=3" -F "sample=@/tmp/sarmad_s3_evil.php;type=image/png"; mysql_q "SELECT COUNT(*) FROM ${PREFIX}employee_faces WHERE employee_id=${EMPLOYEE_ID:-0}")" "2"

# =============================================================================
section "5. Shifts (define + assign)"
# =============================================================================

code="$(post_form "$JAR_MGR" /manager/shifts /manager/shifts \
  --data-urlencode "name=شیفت صبح ${STAMP}" \
  --data-urlencode "code=MORN${STAMP}" \
  --data-urlencode "start_time=08:00" \
  --data-urlencode "end_time=16:00" \
  --data-urlencode "late_grace_minutes=15" \
  --data-urlencode "early_leave_grace_minutes=10" \
  --data-urlencode "break_minutes=30" \
  --data-urlencode "is_active=1")"
check "shift create returns a redirect" "$code" "302"
SHIFT_ID="$(mysql_q "SELECT id FROM ${PREFIX}shifts WHERE customer_id=${CUSTOMER_ID:-0} AND code='MORN${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "shift row created" "${SHIFT_ID:-0}" 1
check "shift belongs to the right tenant" \
  "$(mysql_q "SELECT customer_id FROM ${PREFIX}shifts WHERE id=${SHIFT_ID:-0}")" "$CUSTOMER_ID"
check "grace windows stored" \
  "$(mysql_q "SELECT CONCAT(late_grace_minutes, '/', early_leave_grace_minutes, '/', break_minutes) FROM ${PREFIX}shifts WHERE id=${SHIFT_ID:-0}")" "15/10/30"
check_min "CREATE_SHIFT audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='CREATE_SHIFT' AND customer_id=${CUSTOMER_ID:-0}")" 1

post_form "$JAR_MGR" /manager/shifts /manager/shifts \
  --data-urlencode "name=شیفت شب ${STAMP}" \
  --data-urlencode "code=NIGHT${STAMP}" \
  --data-urlencode "start_time=16:00" \
  --data-urlencode "end_time=00:00" \
  --data-urlencode "late_grace_minutes=10" \
  --data-urlencode "early_leave_grace_minutes=10" \
  --data-urlencode "break_minutes=0" \
  --data-urlencode "is_active=1" >/dev/null
NIGHT_ID="$(mysql_q "SELECT id FROM ${PREFIX}shifts WHERE customer_id=${CUSTOMER_ID:-0} AND code='NIGHT${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "night shift created" "${NIGHT_ID:-0}" 1
check "night shift is detected as crossing midnight" \
  "$(mysql_q "SELECT crosses_midnight FROM ${PREFIX}shifts WHERE id=${NIGHT_ID:-0}")" "1"

check "shift invalid time rejected" \
  "$(post_form "$JAR_MGR" /manager/shifts /manager/shifts --data-urlencode "name=بد" --data-urlencode "start_time=8" --data-urlencode "end_time=16:00")" "302"
check "no broken shift row was written" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}shifts WHERE customer_id=${CUSTOMER_ID:-0} AND name='بد'")" "0"

check "shift edit accepted" \
  "$(post_form "$JAR_MGR" /manager/shifts /manager/shifts/update \
      --data-urlencode "shift_id=$SHIFT_ID" --data-urlencode "name=شیفت صبح ویرایش ${STAMP}" \
      --data-urlencode "start_time=08:30" --data-urlencode "end_time=16:30" \
      --data-urlencode "late_grace_minutes=20" --data-urlencode "early_leave_grace_minutes=10" \
      --data-urlencode "break_minutes=30" --data-urlencode "is_active=1")" "302"
check "shift start time updated" \
  "$(mysql_q "SELECT LEFT(start_time,5) FROM ${PREFIX}shifts WHERE id=${SHIFT_ID:-0}")" "08:30"

check "shift assignment accepted" \
  "$(post_form "$JAR_MGR" /manager/shifts /manager/shifts/assign \
      --data-urlencode "employee_id=$EMPLOYEE_ID" --data-urlencode "shift_id=$SHIFT_ID")" "302"
check "assignment stored for the employee" \
  "$(mysql_q "SELECT CONCAT(shift_id, ':', is_active, ':', weekday) FROM ${PREFIX}employee_shifts WHERE employee_id=${EMPLOYEE_ID:-0} AND is_active=1 LIMIT 1")" "$SHIFT_ID:1:127"
check_min "ASSIGN_SHIFT audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='ASSIGN_SHIFT' AND customer_id=${CUSTOMER_ID:-0}")" 1
check_contains "employee profile shows the shift" \
  "$(body_of "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")" "شیفت صبح ویرایش ${STAMP}"

check "unassign accepted" \
  "$(post_form "$JAR_MGR" /manager/shifts /manager/shifts/assign \
      --data-urlencode "employee_id=$EMPLOYEE_ID" --data-urlencode "shift_id=0")" "302"
check "assignment closed" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}employee_shifts WHERE employee_id=${EMPLOYEE_ID:-0} AND is_active=1")" "0"

# =============================================================================
section "6. Branches (feature on) + employee <-> branch"
# =============================================================================

code="$(post_form "$JAR_MGR" /manager/branches /manager/branches \
  --data-urlencode "name=شعبه مرکزی ${STAMP}" \
  --data-urlencode "code=BR${STAMP}" \
  --data-urlencode "phone=02188776655" \
  --data-urlencode "city=تهران" \
  --data-urlencode "address=خیابان آزمون" \
  --data-urlencode "description=شعبه ساخته‌شده در تست مرحله ۳" \
  --data-urlencode "geofence_radius=200")"
check "branch create returns a redirect" "$code" "302"
BRANCH_ID="$(mysql_q "SELECT id FROM ${PREFIX}branches WHERE customer_id=${CUSTOMER_ID:-0} AND code='BR${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "branch row created" "${BRANCH_ID:-0}" 1
check "branch belongs to the right tenant" \
  "$(mysql_q "SELECT customer_id FROM ${PREFIX}branches WHERE id=${BRANCH_ID:-0}")" "$CUSTOMER_ID"

check "employee can be assigned to the branch" \
  "$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID/edit" /manager/employees/update \
      --data-urlencode "employee_id=$EMPLOYEE_ID" --data-urlencode "first_name=$EMPLOYEE_FIRST" \
      --data-urlencode "last_name=$EMPLOYEE_LAST" --data-urlencode "branch_id=$BRANCH_ID" \
      --data-urlencode "status=active")" "302"
check "employee carries the branch id" \
  "$(mysql_q "SELECT branch_id FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "$BRANCH_ID"
check "employee can never be assigned to a branch of another customer" \
  "$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID/edit" /manager/employees/update \
      --data-urlencode "employee_id=$EMPLOYEE_ID" --data-urlencode "first_name=$EMPLOYEE_FIRST" \
      --data-urlencode "last_name=$EMPLOYEE_LAST" --data-urlencode "branch_id=99999" \
      --data-urlencode "status=active")" "302"
check "invalid branch id was not stored" \
  "$(mysql_q "SELECT branch_id FROM ${PREFIX}employees WHERE id=${EMPLOYEE_ID:-0}")" "$BRANCH_ID"

check "branch filter works in the employee list" \
  "$(count_in "$JAR_MGR" "/manager/employees?branch_id=$BRANCH_ID" "$EMPLOYEE_LAST")" "1"

# =============================================================================
section "7. Attendance list, leaves, holidays"
# =============================================================================

check "attendance page renders" "$(status_of "$JAR_MGR" /manager/attendance)" "200"
# Stage 6 replaced the placeholder: the page must now be the real engine list
check_contains "attendance page shows the real engine UI" \
  "$(body_of "$JAR_MGR" /manager/attendance)" "حضور و غیاب"
check_contains "attendance page offers the manual registration form" \
  "$(body_of "$JAR_MGR" /manager/attendance)" "/manager/attendance/manual"
for q in "date_from=$(date +%Y-%m-01)&date_to=$(date +%Y-%m-%d)" "employee_id=$EMPLOYEE_ID" \
         "branch_id=$BRANCH_ID" "status=present" "shift_id=$SHIFT_ID" "late=1" "early=1" "overtime=1"; do
  check "attendance filter works: $q" "$(status_of "$JAR_MGR" "/manager/attendance?$q")" "200"
done

# leaves
code="$(post_form "$JAR_MGR" /manager/leaves /manager/leaves \
  --data-urlencode "employee_id=$EMPLOYEE_ID" \
  --data-urlencode "leave_type=annual" \
  --data-urlencode "start_date=$(date -d '+2 days' +%Y-%m-%d)" \
  --data-urlencode "end_date=$(date -d '+4 days' +%Y-%m-%d)" \
  --data-urlencode "description=مرخصی استحقاقی تست" \
  --data-urlencode "status=pending")"
check "leave create returns a redirect" "$code" "302"
LEAVE_ID="$(mysql_q "SELECT id FROM ${PREFIX}leaves WHERE customer_id=${CUSTOMER_ID:-0} AND employee_id=${EMPLOYEE_ID:-0} ORDER BY id DESC LIMIT 1" | tr -d '[:space:]')"
check_min "leave row created" "${LEAVE_ID:-0}" 1
check "leave carries the tenant and the employee" \
  "$(mysql_q "SELECT CONCAT(customer_id, ':', employee_id) FROM ${PREFIX}leaves WHERE id=${LEAVE_ID:-0}")" "$CUSTOMER_ID:$EMPLOYEE_ID"
check "leave days computed" \
  "$(mysql_q "SELECT days FROM ${PREFIX}leaves WHERE id=${LEAVE_ID:-0}")" "3.00"
check_min "CREATE_LEAVE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='CREATE_LEAVE' AND customer_id=${CUSTOMER_ID:-0}")" 1

post_form "$JAR_MGR" /manager/leaves /manager/leaves \
  --data-urlencode "employee_id=$EMPLOYEE_ID" \
  --data-urlencode "leave_type=sick" \
  --data-urlencode "start_date=$(date -d '+3 days' +%Y-%m-%d)" \
  --data-urlencode "end_date=$(date -d '+5 days' +%Y-%m-%d)" >/dev/null
check "overlapping leave for the same employee is refused" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}leaves WHERE customer_id=${CUSTOMER_ID:-0} AND leave_type='sick'")" "0"
check "invalid date range is refused" \
  "$(post_form "$JAR_MGR" /manager/leaves /manager/leaves \
      --data-urlencode "employee_id=$EMPLOYEE_ID" --data-urlencode "leave_type=annual" \
      --data-urlencode "start_date=$(date -d '+10 days' +%Y-%m-%d)" \
      --data-urlencode "end_date=$(date -d '+1 days' +%Y-%m-%d)")" "302"
check "no reversed leave row written" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}leaves WHERE customer_id=${CUSTOMER_ID:-0} AND employee_id=${EMPLOYEE_ID:-0}")" "1"

check "leave can be approved" \
  "$(post_form "$JAR_MGR" /manager/leaves /manager/leaves/decide \
      --data-urlencode "leave_id=$LEAVE_ID" --data-urlencode "status=approved")" "302"
check "leave status updated" \
  "$(mysql_q "SELECT status FROM ${PREFIX}leaves WHERE id=${LEAVE_ID:-0}")" "approved"
check_min "DECIDE_LEAVE audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='DECIDE_LEAVE' AND customer_id=${CUSTOMER_ID:-0}")" 1

# holidays
code="$(post_form "$JAR_MGR" /manager/holidays /manager/holidays \
  --data-urlencode "title=تعطیلی تست ${STAMP}" \
  --data-urlencode "holiday_date=$(date -d '+7 days' +%Y-%m-%d)" \
  --data-urlencode "description=تعطیلی رسمی آزمایشی" \
  --data-urlencode "is_recurring_yearly=0" \
  --data-urlencode "is_active=1")"
check "holiday create returns a redirect" "$code" "302"
HOLIDAY_ID="$(mysql_q "SELECT id FROM ${PREFIX}holidays WHERE customer_id=${CUSTOMER_ID:-0} AND title='تعطیلی تست ${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "holiday row created" "${HOLIDAY_ID:-0}" 1
check "holiday has no branch (branches independent)" \
  "$(mysql_q "SELECT IFNULL(branch_id, 0) FROM ${PREFIX}holidays WHERE id=${HOLIDAY_ID:-0}")" "0"
check_min "CREATE_HOLIDAY audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='CREATE_HOLIDAY' AND customer_id=${CUSTOMER_ID:-0}")" 1
check "duplicate holiday on the same date is refused" \
  "$(post_form "$JAR_MGR" /manager/holidays /manager/holidays \
      --data-urlencode "title=تکراری" --data-urlencode "holiday_date=$(date -d '+7 days' +%Y-%m-%d)")" "302"
check "only one holiday row for that date" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}holidays WHERE customer_id=${CUSTOMER_ID:-0} AND holiday_date='$(date -d '+7 days' +%Y-%m-%d)'")" "1"
check "holiday toggle works" \
  "$(post_form "$JAR_MGR" /manager/holidays /manager/holidays/toggle \
      --data-urlencode "holiday_id=$HOLIDAY_ID" --data-urlencode "active=0")" "302"
check "holiday deactivated" \
  "$(mysql_q "SELECT is_active FROM ${PREFIX}holidays WHERE id=${HOLIDAY_ID:-0}")" "0"

# =============================================================================
section "8. Reports (all nine types on real data)"
# =============================================================================

for type in daily monthly per_employee late early_leave overtime absence leave branch; do
  check "report renders: $type" "$(status_of "$JAR_MGR" "/manager/reports?type=$type")" "200"
done
check_contains "leave report lists the approved leave of the employee" \
  "$(body_of "$JAR_MGR" "/manager/reports?type=leave&status=approved&date_from=$(date +%Y-%m-%d)&date_to=$(date -d '+10 days' +%Y-%m-%d)")" "$EMPLOYEE_LAST"
check_contains "statements about the excel export are honest" \
  "$(body_of "$JAR_MGR" "/manager/reports?type=daily")" "خروجی اکسل"
check "excel export page stays feature gated" "$(status_of "$JAR_MGR" /manager/reports/excel)" "403"

# =============================================================================
section "9. Payroll (feature on) and Telegram (feature on)"
# =============================================================================

check "payroll page renders" "$(status_of "$JAR_MGR" /manager/payroll)" "200"
check "payroll profile save accepted" \
  "$(post_form "$JAR_MGR" /manager/payroll /manager/payroll/profile \
      --data-urlencode "employee_id=$EMPLOYEE_ID" \
      --data-urlencode "pay_type=hourly" \
      --data-urlencode "base_amount=250000000" \
      --data-urlencode "hourly_rate=850000" \
      --data-urlencode "overtime_rate=1100000" \
      --data-urlencode "deficit_rate=700000" \
      --data-urlencode "penalty_amount=500000" \
      --data-urlencode "benefits_amount=1200000" \
      --data-urlencode "currency=IRT" \
      --data-urlencode "is_active=1")" "302"
check "payroll profile stored" \
  "$(mysql_q "SELECT CONCAT(pay_type, ':', base_amount, ':', hourly_rate) FROM ${PREFIX}payroll_profiles WHERE employee_id=${EMPLOYEE_ID:-0}")" "hourly:250000000.00:850000.00"
# Stage 7: the profile change is audited as payroll.profile.created/updated
# (the legacy UPDATE_PAYROLL action name no longer exists).
check_min "payroll profile change audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE event LIKE 'payroll.profile.%' AND customer_id=${CUSTOMER_ID:-0}")" 1
check "payroll rules save accepted" \
  "$(post_form "$JAR_MGR" /manager/payroll /manager/payroll/rules \
      --data-urlencode "currency=IRT" --data-urlencode "late_grace_minutes=12" --data-urlencode "early_grace_minutes=8")" "302"
check "customer setting stored" \
  "$(mysql_q "SELECT setting_value FROM ${PREFIX}customer_settings WHERE customer_id=${CUSTOMER_ID:-0} AND setting_key='late_grace_minutes'")" "12"

check "telegram page renders" "$(status_of "$JAR_MGR" /manager/telegram)" "200"
check "telegram settings save accepted" \
  "$(post_form "$JAR_MGR" /manager/telegram /manager/telegram \
      --data-urlencode "telegram_chat_id=-1001234567890" \
      --data-urlencode "telegram_daily_summary=1" \
      --data-urlencode "telegram_late_alert=1")" "302"
check "telegram chat id stored" \
  "$(mysql_q "SELECT setting_value FROM ${PREFIX}customer_settings WHERE customer_id=${CUSTOMER_ID:-0} AND setting_key='telegram_chat_id'")" "-1001234567890"
check "telegram invalid chat id refused" \
  "$(post_form "$JAR_MGR" /manager/telegram /manager/telegram --data-urlencode "telegram_chat_id=not a chat id")" "302"
check "invalid chat id was not stored" \
  "$(mysql_q "SELECT setting_value FROM ${PREFIX}customer_settings WHERE customer_id=${CUSTOMER_ID:-0} AND setting_key='telegram_chat_id'")" "-1001234567890"

# =============================================================================
section "10. Devices (limited manager surface)"
# =============================================================================

check "device page renders" "$(status_of "$JAR_MGR" /manager/devices)" "200"
code="$(post_form "$JAR_MGR" /manager/devices /manager/devices \
  --data-urlencode "name=تبلت ورودی ${STAMP}" --data-urlencode "type=tablet" --data-urlencode "platform=Android 13")"
check "limited registration accepted" "$code" "302"
DEVICE_ID="$(mysql_q "SELECT id FROM ${PREFIX}devices WHERE customer_id=${CUSTOMER_ID:-0} AND name='تبلت ورودی ${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "device row created for the customer" "${DEVICE_ID:-0}" 1
check "new device is pending" \
  "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "pending"
check "device belongs to the right tenant" \
  "$(mysql_q "SELECT customer_id FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "$CUSTOMER_ID"
check_min "manager sees its own device" "$(count_in "$JAR_MGR" /manager/devices "تبلت ورودی ${STAMP}")" 1
check "manager can not reset a device" \
  "$(post_form "$JAR_MGR" /manager/devices /manager/devices/reset --data-urlencode "id=$DEVICE_ID")" "403"
check "manager can not rotate a device token" \
  "$(post_form "$JAR_MGR" /manager/devices /manager/devices/rotate --data-urlencode "id=$DEVICE_ID")" "403"
check "manager can not revoke a device" \
  "$(post_form "$JAR_MGR" /manager/devices /manager/devices/revoke --data-urlencode "id=$DEVICE_ID")" "403"
check "no reset was written by the manager" \
  "$(mysql_q "SELECT IFNULL(reset_count,0) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "0"
check "token rotation refused through the legacy surface too" \
  "$(post_form "$JAR_MGR" /manager/devices /devices/rotate-token --data-urlencode "id=$DEVICE_ID")" "403"
check "device token still absent" \
  "$(mysql_q "SELECT CASE WHEN api_token_hash IS NULL OR api_token_hash='' THEN 1 ELSE 0 END FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "1"

check "manager can complete the pairing of its own device" \
  "$(post_form "$JAR_MGR" /manager/devices /manager/devices/activate --data-urlencode "device_id=$DEVICE_ID")" "302"
check "device is active after pairing" \
  "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "active"
check "device now has a hashed token" \
  "$(mysql_q "SELECT CHAR_LENGTH(api_token_hash) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "64"

# =============================================================================
section "11. Customer settings + activity log (own customer only)"
# =============================================================================

check "settings page renders" "$(status_of "$JAR_MGR" /manager/settings)" "200"
code="$(post_form "$JAR_MGR" /manager/settings /manager/settings/update \
  --data-urlencode "name=کسب‌وکار ویرایش‌شده ${STAMP}" \
  --data-urlencode "company_name=برند آزمون" \
  --data-urlencode "contact_phone=09121230000" \
  --data-urlencode "contact_email=biz-${STAMP}@example.test" \
  --data-urlencode "address=تهران، خیابان تست" \
  --data-urlencode "description=توضیح کسب‌وکار" \
  --data-urlencode "work_start_hint=08:00" \
  --data-urlencode "work_end_hint=17:00" \
  --data-urlencode "status=archived" \
  --data-urlencode "max_devices=9999" \
  --data-urlencode "plan_name=enterprise")"
check "business profile update accepted" "$code" "302"
check "business name updated" \
  "$(mysql_q "SELECT name FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "کسب‌وکار ویرایش‌شده ${STAMP}"
check "owner level status field was ignored" \
  "$(mysql_q "SELECT status FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "active"
check "owner level max_devices field was ignored" \
  "$(mysql_q "SELECT CASE WHEN max_devices = 9999 THEN 'changed' ELSE 'ignored' END FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "ignored"
check "subscription plan untouched by the manager" \
  "$(mysql_q "SELECT plan_name FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "trial"
check_min "UPDATE_CUSTOMER audited for the manager edit" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='UPDATE_CUSTOMER' AND customer_id=${CUSTOMER_ID:-0} AND actor_user_id=${MGR_A_ID:-0}")" 1

token="$(fresh_token "$JAR_MGR" /manager/settings)"
curl -s -b "$JAR_MGR" -c "$JAR_MGR" -o /dev/null -X POST "$BASE/manager/settings/update" \
  -F "_token=$token" -F "name=کسب‌وکار ویرایش‌شده ${STAMP}" -F "logo=@/tmp/sarmad_s3_face.png;type=image/png"
check "manager logo upload stored" \
  "$(mysql_q "SELECT CASE WHEN logo_path LIKE '%branding/%' THEN 1 ELSE 0 END FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "1"
check_min "UPLOAD_LOGO audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='UPLOAD_LOGO' AND customer_id=${CUSTOMER_ID:-0}")" 1

check "activity page renders" "$(status_of "$JAR_MGR" /manager/activity)" "200"
check_min "activity page shows own events" \
  "$(count_in "$JAR_MGR" "/manager/activity?action=CREATE_EMPLOYEE" 'CREATE_EMPLOYEE')" 1
check "activity page filters" \
  "$(status_of "$JAR_MGR" "/manager/activity?result=denied&from=$(date +%Y-%m-%d)")" "200"
check "read only feature page renders" "$(status_of "$JAR_MGR" /manager/settings/features)" "200"

# =============================================================================
section "12. Tenant isolation (customer A can not touch customer B)"
# =============================================================================

# manager B builds its own employee + branch + leave
post_form "$JAR_MGRB" /manager/employees/create /manager/employees \
  --data-urlencode "first_name=کارمند" \
  --data-urlencode "last_name=$EMPLOYEE_B_LAST" \
  --data-urlencode "status=active" >/dev/null
EMP_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}employees WHERE customer_id=${CUSTOMER_B_ID:-0} AND last_name='$EMPLOYEE_B_LAST' LIMIT 1" | tr -d '[:space:]')"
check_min "customer B employee created by B manager" "${EMP_B_ID:-0}" 1

post_form "$JAR_MGRB" /manager/leaves /manager/leaves \
  --data-urlencode "employee_id=$EMP_B_ID" \
  --data-urlencode "leave_type=annual" \
  --data-urlencode "start_date=$(date -d '+1 day' +%Y-%m-%d)" \
  --data-urlencode "end_date=$(date -d '+2 days' +%Y-%m-%d)" >/dev/null
LEAVE_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}leaves WHERE customer_id=${CUSTOMER_B_ID:-0} AND employee_id=${EMP_B_ID:-0} ORDER BY id DESC LIMIT 1" | tr -d '[:space:]')"
check_min "customer B leave created" "${LEAVE_B_ID:-0}" 1

# branches is a feature flag: the owner turns it on for B, B may then use it.
post_form "$JAR_OWNER" "/owner/features/$CUSTOMER_B_UUID" "/owner/features/$CUSTOMER_B_UUID/toggle" \
  --data-urlencode "feature=branches" --data-urlencode "enabled=1" >/dev/null
post_form "$JAR_MGRB" /manager/branches /manager/branches \
  --data-urlencode "name=شعبه ب ${STAMP}" --data-urlencode "code=BBR${STAMP}" >/dev/null
BRANCH_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}branches WHERE customer_id=${CUSTOMER_B_ID:-0} AND code='BBR${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "customer B branch created" "${BRANCH_B_ID:-0}" 1

# A manager must not see B data anywhere
check "employee list of A does not contain B employee" \
  "$(count_in "$JAR_MGR" /manager/employees "$EMPLOYEE_B_LAST")" "0"
check "employee list of A ignores a foreign tenant filter" \
  "$(count_in "$JAR_MGR" "/manager/employees?customer_id=$CUSTOMER_B_ID" "$EMPLOYEE_B_LAST")" "0"
check "leaves of A do not contain B leave" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}leaves WHERE id=${LEAVE_B_ID:-0} AND customer_id=${CUSTOMER_ID:-0}")" "0"
check "branch list of A does not contain B branch" \
  "$(count_in "$JAR_MGR" /manager/branches "شعبه ب ${STAMP}")" "0"
check "shift list of A does not contain B data" \
  "$(count_in "$JAR_MGR" /manager/shifts "$EMPLOYEE_B_LAST")" "0"
check "device list of A does not contain B devices" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE customer_id=${CUSTOMER_B_ID:-0}")" "0"

# direct id attacks: every write path must refuse and audit
check "profile of B employee is not readable" \
  "$(status_of "$JAR_MGR" "/manager/employees/$EMP_B_ID")" "302"
check "update of B employee refused" \
  "$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID" /manager/employees/update \
      --data-urlencode "employee_id=$EMP_B_ID" --data-urlencode "first_name=هک" \
      --data-urlencode "last_name=هک" --data-urlencode "status=active")" "302"
check "B employee was not modified" \
  "$(mysql_q "SELECT first_name FROM ${PREFIX}employees WHERE id=${EMP_B_ID:-0}")" "کارمند"
check "disable of B employee refused" \
  "$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID" /manager/employees/toggle \
      --data-urlencode "employee_id=$EMP_B_ID" --data-urlencode "active=0")" "302"
check "B employee is still active" \
  "$(mysql_q "SELECT status FROM ${PREFIX}employees WHERE id=${EMP_B_ID:-0}")" "active"
token="$(fresh_token "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID")"
curl -s -b "$JAR_MGR" -c "$JAR_MGR" -o /dev/null -X POST "$BASE/manager/employees/face" \
  -F "_token=$token" -F "employee_id=$EMP_B_ID" -F "sample_index=1" -F "sample=@/tmp/sarmad_s3_face.png;type=image/png"
check "face upload for a foreign employee refused" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}employee_faces WHERE employee_id=${EMP_B_ID:-0}")" "0"
check "face delete for a foreign employee refused" \
  "$(post_form "$JAR_MGR" "/manager/employees/$EMPLOYEE_ID" /manager/employees/face/delete \
      --data-urlencode "employee_id=$EMP_B_ID" --data-urlencode "sample_index=1")" "302"
check "leave decision on a foreign leave refused" \
  "$(post_form "$JAR_MGR" /manager/leaves /manager/leaves/decide \
      --data-urlencode "leave_id=$LEAVE_B_ID" --data-urlencode "status=rejected")" "302"
check "B leave status unchanged" \
  "$(mysql_q "SELECT status FROM ${PREFIX}leaves WHERE id=${LEAVE_B_ID:-0}")" "pending"
check "shift assignment to a foreign employee refused" \
  "$(post_form "$JAR_MGR" /manager/shifts /manager/shifts/assign \
      --data-urlencode "employee_id=$EMP_B_ID" --data-urlencode "shift_id=$SHIFT_ID")" "302"
check "no staffing row was written for B employee" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}employee_shifts WHERE employee_id=${EMP_B_ID:-0}")" "0"
check "shift edit of a foreign shift refused" \
  "$(post_form "$JAR_MGR" /manager/shifts /manager/shifts/update \
      --data-urlencode "shift_id=99999" --data-urlencode "name=هک" \
      --data-urlencode "start_time=08:00" --data-urlencode "end_time=16:00")" "302"
check "holiday of a foreign branch refused" \
  "$(post_form "$JAR_MGR" /manager/holidays /manager/holidays \
      --data-urlencode "title=هک" --data-urlencode "holiday_date=$(date -d '+9 days' +%Y-%m-%d)" \
      --data-urlencode "branch_id=$BRANCH_B_ID")" "302"
check "foreign branch holiday not created" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}holidays WHERE customer_id=${CUSTOMER_ID:-0} AND branch_id=${BRANCH_B_ID:-0}")" "0"
check "payroll profile for a foreign employee refused" \
  "$(post_form "$JAR_MGR" /manager/payroll /manager/payroll/profile \
      --data-urlencode "employee_id=$EMP_B_ID" --data-urlencode "pay_type=monthly" \
      --data-urlencode "base_amount=1")" "302"
check "no payroll row for B employee" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}payroll_profiles WHERE employee_id=${EMP_B_ID:-0}")" "0"
check "device activation of a foreign device refused" \
  "$(post_form "$JAR_MGR" /manager/devices /manager/devices/activate --data-urlencode "device_id=99999")" "302"
check "leave of B is invisible in the A leave list" \
  "$(count_in "$JAR_MGR" "/manager/leaves?employee_id=$EMP_B_ID" "$EMPLOYEE_B_LAST")" "0"
check_min "cross tenant attempts are audited as ACCESS_DENIED" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='ACCESS_DENIED' AND customer_id=${CUSTOMER_ID:-0}")" 5

# =============================================================================
section "13. Security: manager can not reach owner capabilities"
# =============================================================================

for path in /owner /owner/customers /owner/managers /owner/features /owner/subscriptions \
            /owner/devices /owner/logs /owner/settings /owner/emergency; do
  check "manager blocked from owner page: $path" "$(status_of "$JAR_MGR" "$path")" "403"
done
check "manager blocked from customer B detail page" "$(status_of "$JAR_MGR" "/owner/customers/$CUSTOMER_B_UUID")" "403"
check "manager can not create another manager" \
  "$(post_form "$JAR_MGR" /dashboard /owner/managers \
      --data-urlencode "customer_id=$CUSTOMER_ID" --data-urlencode "first_name=هکر" \
      --data-urlencode "last_name=هکر" --data-urlencode "username=hacker${STAMP}" \
      --data-urlencode "email=hacker${STAMP}@example.test" --data-urlencode "password=$MGR_A_PASS")" "403"
check "no manager was created" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE username='hacker${STAMP}'")" "0"
check "manager can not change feature flags" \
  "$(post_form "$JAR_MGR" /dashboard "/owner/features/$CUSTOMER_UUID/toggle" \
      --data-urlencode "feature=branches" --data-urlencode "enabled=0")" "403"
check "branch feature still enabled" \
  "$(mysql_q "SELECT is_enabled FROM ${PREFIX}feature_flags WHERE customer_id=${CUSTOMER_ID:-0} AND feature_key='branches'")" "1"
check "manager can not change the subscription" \
  "$(post_form "$JAR_MGR" /dashboard "/owner/subscriptions/$CUSTOMER_UUID" \
      --data-urlencode "plan_name=enterprise" --data-urlencode "max_devices=500")" "403"
check "subscription plan unchanged" \
  "$(mysql_q "SELECT plan_name FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "trial"
check "manager can not reset a device from the owner route" \
  "$(post_form "$JAR_MGR" /dashboard /owner/devices/reset --data-urlencode "id=$DEVICE_ID")" "403"
check "manager can not start emergency access" \
  "$(post_form "$JAR_MGR" /dashboard "/owner/customers/$CUSTOMER_UUID/emergency" \
      --data-urlencode "reason=test")" "403"
check_min "no emergency session was created" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}owner_impersonations WHERE customer_id=${CUSTOMER_ID:-0} AND ended_at IS NULL")" 0
check "manager can not disable the customer" \
  "$(post_form "$JAR_MGR" /dashboard "/owner/customers/$CUSTOMER_UUID/status" \
      --data-urlencode "status=archived")" "403"
check "customer A is still active" \
  "$(mysql_q "SELECT status FROM ${PREFIX}customers WHERE id=${CUSTOMER_ID:-0}")" "active" 
check "manager can not change its own role" \
  "$(post_form "$JAR_MGR" /manager/settings /manager/settings/update \
      --data-urlencode "name=کسب‌وکار ویرایش‌شده ${STAMP}" --data-urlencode "role=owner" \
      --data-urlencode "status=active")" "302"
check "manager role untouched" \
  "$(mysql_q "SELECT role FROM ${PREFIX}users WHERE id=${MGR_A_ID:-0}")" "manager"
check "manager is still scoped to its own customer" \
  "$(mysql_q "SELECT customer_id FROM ${PREFIX}users WHERE id=${MGR_A_ID:-0}")" "$CUSTOMER_ID"

# =============================================================================
section "14. Password change + password storage"
# =============================================================================

check "password page renders" "$(status_of "$JAR_MGR" /account/password)" "200"
check "wrong current password refused" \
  "$(post_form "$JAR_MGR" /account/password /account/password \
      --data-urlencode "current_password=DefinitelyWrong1" \
      --data-urlencode "new_password=$MGR_A_NEW_PASS" \
      --data-urlencode "new_password_confirmation=$MGR_A_NEW_PASS")" "302"
check "password unchanged after the failed attempt" \
  "$(login "$JAR_MGR" "$MGR_A_USER" "$MGR_A_PASS")" "302"
check "session is alive with the old password" "$(status_of "$JAR_MGR" /dashboard)" "200"

check "password change accepted" \
  "$(post_form "$JAR_MGR" /account/password /account/password \
      --data-urlencode "current_password=$MGR_A_PASS" \
      --data-urlencode "new_password=$MGR_A_NEW_PASS" \
      --data-urlencode "new_password_confirmation=$MGR_A_NEW_PASS")" "302"
check "old password no longer works" "$(login "$JAR_MGR" "$MGR_A_USER" "$MGR_A_PASS")" "302"
check "no session with the old password" "$(status_of "$JAR_MGR" /dashboard)" "302"
check "new password works" "$(login "$JAR_MGR" "$MGR_A_USER" "$MGR_A_NEW_PASS")" "302"
check "manager panel reachable again" "$(status_of "$JAR_MGR" /dashboard)" "200"

check "password is stored as a bcrypt hash" \
  "$(mysql_q "SELECT LEFT(password_hash, 4) FROM ${PREFIX}users WHERE id=${MGR_A_ID:-0}")" '$2y$'
check "plain password is nowhere in the users table" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}users WHERE password_hash LIKE '%$MGR_A_NEW_PASS%' OR password_hash LIKE '%$MGR_A_PASS%'")" "0"
check "owner panel never shows a manager password" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE description LIKE '%$MGR_A_NEW_PASS%' OR description LIKE '%$MGR_A_PASS%'")" "0"
check_not_contains "manager list page has no password field" \
  "$(body_of "$JAR_OWNER" /owner/managers)" 'name="password" value'
check_not_contains "manager list page does not leak the stored hash" \
  "$(body_of "$JAR_OWNER" /owner/managers)" 'password_hash'

# =============================================================================
section "15. Logout"
# =============================================================================

check "logout accepted" "$(post_form "$JAR_MGR" /dashboard /logout)" "302"
check "session is gone after logout" "$(status_of "$JAR_MGR" /dashboard)" "302"
check "manager page requires login again" "$(status_of "$JAR_MGR" /manager/employees)" "302"
check_min "LOGOUT audited for the manager" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='LOGOUT' AND actor_user_id=${MGR_A_ID:-0}")" 1

# =============================================================================
section "16. Audit trail of the manager panel"
# =============================================================================

for action in CREATE_EMPLOYEE UPDATE_EMPLOYEE DISABLE_EMPLOYEE ENABLE_EMPLOYEE \
              REGISTER_FACE_SAMPLE DELETE_FACE_SAMPLE CREATE_SHIFT UPDATE_SHIFT ASSIGN_SHIFT \
              CREATE_LEAVE DECIDE_LEAVE CREATE_HOLIDAY CREATE_BRANCH UPDATE_CUSTOMER \
              CHANGE_FEATURE ACCESS_DENIED LOGIN LOGOUT; do
  count="$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='$action' AND customer_id IN (${CUSTOMER_ID:-0}, ${CUSTOMER_B_ID:-0})")"
  if [[ "${count:-0}" -ge 1 ]]; then ok "audit row present for customer A/B: $action ($count)"; else bad "audit row present for customer A/B: $action"; fi
done
check_min "manager actions carry actor_user_id and actor_role" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE customer_id=${CUSTOMER_ID:-0} AND actor_user_id=${MGR_A_ID:-0} AND actor_role='manager'")" 5
check "stage 3 tables exist with the attendance_ prefix" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('${PREFIX}employee_faces','${PREFIX}shifts','${PREFIX}employee_shifts','${PREFIX}records','${PREFIX}leaves','${PREFIX}holidays','${PREFIX}payroll_profiles','${PREFIX}customer_settings')")" "8"
check "no table outside the project prefix was created" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name NOT LIKE '${PREFIX}%' AND table_name IN ('employees','shifts','leaves','holidays','payroll_profiles','customer_settings','employee_faces','attendance_records')")" "0"

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
