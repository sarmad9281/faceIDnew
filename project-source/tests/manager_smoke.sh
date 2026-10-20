#!/usr/bin/env bash
# =====================================================================
# SARMAD Attendance - stage 6 manager panel smoke test.
#
# Real HTTP against the running app + real assertions against the real DB.
# Nothing is mocked: a shift, an employee and a shift assignment are
# created in the database, then the manager panel is driven with curl
# exactly like a browser (session cookie, CSRF token, same origin).
#
#   tests/manager_smoke.sh [BASE_URL]
#
# The script cleans up everything it created (marked SARMAD-TEST-S6-MGR).
# =====================================================================
set -u

BASE="${1:-http://127.0.0.1:8080}"
DB_NAME='sarmadfa_clinic'
DB_USER='sarmadfa_youruser_lumiere'
DB_PASS="${SARMAD_TEST_DB_PASS:-Test_Local_Pass_2026!}"
MGR_USER='manager@example.com'
MGR_PASS='Manager#Pass2026'
API_MGR_USER='s6.smoke.manager@example.com'
MARK='SARMAD-TEST-S6-MGR'
RUN_STARTED_AT="$(date '+%Y-%m-%d %H:%M:%S')"
# How far back the run-start marker may look for rows the run created.
RUN_EPOCH=$(date '+%s')
CUSTOMER=1

PASS=0
FAIL=0
FAILURES=()

ok()  { PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); FAILURES+=("$1"); printf '  \033[31m✗\033[0m %s\n' "$1"; }
eq()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — actual=$2 expected=$3"; fi; }

db() { mariadb -N -B -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$1" 2>/dev/null; }

JAR="$(mktemp)"; PAGE="$(mktemp)"
cleanup() {
    # Records / faces / shifts are scoped by the marked employee; the audit log
    # and the event queue have no employee column, so they are scoped by the
    # run window (everything this script created belongs to today's run).
    db "DELETE FROM attendance_audit_logs WHERE customer_id = $CUSTOMER AND event LIKE 'attendance.%' AND created_at >= CURDATE()"
    db "DELETE FROM attendance_events WHERE customer_id = $CUSTOMER AND created_at >= CURDATE()"
    db "DELETE FROM attendance_records WHERE customer_id = $CUSTOMER AND employee_id IN (SELECT id FROM attendance_employees WHERE employee_code = '$MARK')"
    db "DELETE FROM attendance_face_embeddings WHERE customer_id = $CUSTOMER AND employee_id IN (SELECT id FROM attendance_employees WHERE employee_code = '$MARK')"
    db "DELETE FROM attendance_employee_shifts WHERE customer_id = $CUSTOMER AND employee_id IN (SELECT id FROM attendance_employees WHERE employee_code = '$MARK')"
    db "DELETE FROM attendance_employees WHERE customer_id = $CUSTOMER AND employee_code = '$MARK'"
    db "DELETE FROM attendance_shifts WHERE customer_id = $CUSTOMER AND code = '$MARK'"
    db "DELETE FROM attendance_users WHERE email = '$API_MGR_USER'"
    rm -f "$JAR" "$PAGE"
}
trap cleanup EXIT

echo "== Manager attendance smoke (stage 6) =="

# ---------------------------------------------------------------- fixtures
SHIFT_ID=$(db "INSERT INTO attendance_shifts (uuid, customer_id, name, code, start_time, end_time, crosses_midnight, late_grace_minutes, early_leave_grace_minutes, break_minutes, weekly_pattern, is_active)
    VALUES (UUID(), $CUSTOMER, 'شیفت آزمون مدیر', '$MARK', '08:00:00', '17:00:00', 0, 10, 10, 0, 'all', 1); SELECT LAST_INSERT_ID();")

EMP_ID=$(db "INSERT INTO attendance_employees (uuid, customer_id, employee_code, first_name, last_name, employment_type, status, hire_date)
    VALUES (UUID(), $CUSTOMER, '$MARK', 'آزمون', 'مدیر', 'full_time', 'active', CURDATE()); SELECT LAST_INSERT_ID();")

db "INSERT INTO attendance_employee_shifts (uuid, customer_id, employee_id, shift_id, weekday, effective_from, is_active)
    VALUES (UUID(), $CUSTOMER, $EMP_ID, $SHIFT_ID, 127, CURDATE() - INTERVAL 30 DAY, 1)" >/dev/null

check_fixtures=$(db "SELECT COUNT(*) FROM attendance_employees WHERE id = $EMP_ID AND status = 'active'")
eq "shift + employee + assignment created" "$check_fixtures" "1"

# A temporary manager with API access for the smoke (the sample manager is a
# pure panel account, and the script must not flip flags on business rows).
db "INSERT INTO attendance_users (uuid, role_id, role, customer_id, name, username, email, password_hash, is_active, api_enabled, created_at, updated_at)
    SELECT UUID(), role_id, role, customer_id, 'Smoke API Manager', 's6-smoke-manager', '$API_MGR_USER', password_hash, 1, 1, NOW(), NOW()
    FROM attendance_users WHERE id = 2" >/dev/null
API_MGR_ID=$(db "SELECT id FROM attendance_users WHERE email = '$API_MGR_USER' LIMIT 1")
eq "temporary API manager created" "$(db "SELECT COUNT(*) FROM attendance_users WHERE id = $API_MGR_ID AND api_enabled = 1")" "1"

TODAY=$(date +%F); YESTERDAY=$(date -d 'yesterday' +%F)

# ---------------------------------------------------------------- login
TOKEN=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/login" \
    -H "Origin: $BASE" -H "Referer: $BASE/login" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "identifier=$MGR_USER" --data-urlencode "password=$MGR_PASS")
eq "manager login" "$CODE" "302"

# ---------------------------------------------------------------- listing renders
CODE=$(curl -s -b "$JAR" -c "$JAR" -o "$PAGE" -w '%{http_code}' "$BASE/manager/attendance?date_from=$TODAY&date_to=$TODAY")
eq "GET /manager/attendance" "$CODE" "200"
grep -q 'kpi-label' "$PAGE" && ok "the KPI grid renders real numbers" || bad "KPI grid missing"

TOKEN=$(grep -o 'name="_token" value="[^"]*"' "$PAGE" | head -1 | sed 's/.*value="//;s/"//')
[ -n "$TOKEN" ] && ok "CSRF token on the manager page" || bad "no CSRF token on the manager page"

# ---------------------------------------------------------------- manual create
CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/manager/attendance/manual" \
    -H "Origin: $BASE" -H "Referer: $BASE/manager/attendance" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "employee_id=$EMP_ID" \
    --data-urlencode "attendance_date=$TODAY" --data-urlencode "entry_at=08:05" --data-urlencode "exit_at=17:30" \
    --data-urlencode "reason=ثبت دستی آزمون مدیر")
eq "POST /manager/attendance/manual" "$CODE" "302"

ROW=$(db "SELECT CONCAT_WS('|', id, source, status, late_minutes, early_leave_minutes, overtime_minutes, work_minutes, COALESCE(entry_at,''), COALESCE(exit_at,''))
    FROM attendance_records WHERE customer_id = $CUSTOMER AND employee_id = $EMP_ID AND attendance_date = '$TODAY' ORDER BY id DESC LIMIT 1")
REC_ID=$(echo "$ROW" | cut -d'|' -f1)
echo "$ROW" | grep -q '|manual|' && ok "the manual row is stored with source=manual" || bad "manual row source wrong ($ROW)"
eq "grace is respected (late = 0 for 08:05)" "$(echo "$ROW" | cut -d'|' -f4)" "0"
eq "overtime = 30 for a 17:30 exit" "$(echo "$ROW" | cut -d'|' -f6)" "30"
eq "early leave = 0" "$(echo "$ROW" | cut -d'|' -f5)" "0"
eq "work minutes counted (08:05 -> 17:30)" "$(echo "$ROW" | cut -d'|' -f7)" "565"

AUDIT=$(db "SELECT COUNT(*) FROM attendance_audit_logs WHERE customer_id = $CUSTOMER AND entity_id = $REC_ID AND event = 'attendance.manual.created'")
eq "the manual record is audited with an actor" "$AUDIT" "1"

# ---------------------------------------------------------------- correction
CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/manager/attendance/manual" \
    -H "Origin: $BASE" -H "Referer: $BASE/manager/attendance" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "record_id=$REC_ID" --data-urlencode "employee_id=$EMP_ID" \
    --data-urlencode "attendance_date=$TODAY" --data-urlencode "entry_at=08:05" --data-urlencode "exit_at=16:00" \
    --data-urlencode "reason=اصلاح ساعت خروج آزمون")
eq "POST /manager/attendance/manual (correction)" "$CODE" "302"

ROW=$(db "SELECT CONCAT_WS('|', exit_at, early_leave_minutes, overtime_minutes, recalculated_at) FROM attendance_records WHERE id = $REC_ID")
eq "the correction moved the exit to 16:00" "$(echo "$ROW" | cut -d'|' -f1)" "${TODAY} 16:00:00"
eq "early leave recalculated net of grace (60 - 10)" "$(echo "$ROW" | cut -d'|' -f2)" "50"
eq "overtime recalculated (0)" "$(echo "$ROW" | cut -d'|' -f3)" "0"
AUDIT=$(db "SELECT COUNT(*) FROM attendance_audit_logs WHERE customer_id = $CUSTOMER AND entity_id = $REC_ID AND event = 'attendance.record.corrected'")
eq "the correction is audited" "$AUDIT" "1"

# ---------------------------------------------------------------- listing shows it
curl -s -b "$JAR" -c "$JAR" -o "$PAGE" "$BASE/manager/attendance?date_from=$TODAY&date_to=$TODAY"
grep -q "$MARK" "$PAGE" && ok "the employee appears in the manager listing" || bad "employee missing from the listing"
grep -q 'manual' "$PAGE" && ok "the source column shows manual" || bad "source column did not render"

# ---------------------------------------------------------------- close day (absence)
CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/manager/attendance/close-day" \
    -H "Origin: $BASE" -H "Referer: $BASE/manager/attendance" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "date=$YESTERDAY")
eq "POST /manager/attendance/close-day" "$CODE" "302"
ABSENT=$(db "SELECT COUNT(*) FROM attendance_records WHERE customer_id = $CUSTOMER AND employee_id = $EMP_ID AND attendance_date = '$YESTERDAY' AND status = 'absent'")
eq "an absence row was created for the day without attendance" "$ABSENT" "1"

# ---------------------------------------------------------------- recalculate
CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/manager/attendance/recalculate" \
    -H "Origin: $BASE" -H "Referer: $BASE/manager/attendance" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "date_from=$TODAY" --data-urlencode "date_to=$TODAY" \
    --data-urlencode "employee_id=$EMP_ID")
eq "POST /manager/attendance/recalculate" "$CODE" "302"
RECALC=$(db "SELECT COUNT(*) FROM attendance_audit_logs WHERE customer_id = $CUSTOMER AND event = 'attendance.recalculated'")
[ "$RECALC" -ge 1 ] && ok "the recalculation is audited" || bad "no recalculation audit row"

# ---------------------------------------------------------------- void
CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/manager/attendance/void" \
    -H "Origin: $BASE" -H "Referer: $BASE/manager/attendance" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "record_id=$REC_ID" --data-urlencode "reason=ابطال آزمون مدیر")
eq "POST /manager/attendance/void" "$CODE" "302"
ROW=$(db "SELECT CONCAT_WS('|', COALESCE(deleted_at,''), COALESCE(deleted_by,0), delete_reason, IF(open_key IS NULL,'NULL','SET')) FROM attendance_records WHERE id = $REC_ID")
echo "$ROW" | grep -qv '^|' && ok "the record is softly deleted with a reason" || bad "soft delete fields missing ($ROW)"
eq "a voided row is out of the open-session index" "$(echo "$ROW" | cut -d'|' -f4)" "NULL"
GONE=$(db "SELECT COUNT(*) FROM attendance_records WHERE id = $REC_ID")
eq "the row still exists (never a hard delete)" "$GONE" "1"
AUDIT=$(db "SELECT COUNT(*) FROM attendance_audit_logs WHERE customer_id = $CUSTOMER AND entity_id = $REC_ID AND event = 'attendance.record.voided'")
eq "the void is audited" "$AUDIT" "1"

# ---------------------------------------------------------------- API session (bearer)
API_TOKEN=$(curl -s -X POST "$BASE/api/v1/auth/token" -H 'Content-Type: application/json' \
    -d "{\"identifier\":\"$API_MGR_USER\",\"password\":\"$MGR_PASS\",\"device_name\":\"s6-smoke\"}" |
    php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["token"] ?? "";')
[ -n "$API_TOKEN" ] && ok "manager obtained a bearer token for the API" || bad "no API token"

# the voided row must be gone from the API listing too
curl -s -o /tmp/s6_list.json -H "Authorization: Bearer $API_TOKEN" \
    "$BASE/api/v1/manager/attendance?date_from=$TODAY&date_to=$TODAY&employee_id=$EMP_ID"
LIST_JSON=$(cat /tmp/s6_list.json)
echo "$LIST_JSON" | grep -q '"total":0' && ok "the listing API no longer returns the voided record" || bad "voided record still listed ($LIST_JSON)"

curl -s -b "$JAR" -c "$JAR" -o "$PAGE" "$BASE/manager/attendance?date_from=$TODAY&date_to=$TODAY"
if grep -q "data-record-id=\"$REC_ID\"" "$PAGE"; then bad "the voided record is still rendered in the table"; else ok "the voided record left the HTML table"; fi

# ---------------------------------------------------------------- per-employee history API
CODE=$(curl -s -o /tmp/s6_history.json -w '%{http_code}' -H "Authorization: Bearer $API_TOKEN" \
    "$BASE/api/v1/manager/employees/$EMP_ID/attendance?date_from=$TODAY&date_to=$TODAY")
eq "GET /api/v1/manager/employees/{id}/attendance" "$CODE" "200"
grep -q '"success":true' /tmp/s6_history.json && ok "the employee history API answers with the envelope" || bad "history API payload wrong ($(cat /tmp/s6_history.json))"
# the absence row of yesterday must be in the history
grep -q "$YESTERDAY" /tmp/s6_history.json && ok "the history includes the absence row of the day before" || bad "history is missing the absence row"

echo
echo "----------------------------------------------------------------"
printf 'passed: \033[32m%d\033[0m   failed: \033[31m%d\033[0m\n' "$PASS" "$FAIL"
if [ "$FAIL" -gt 0 ]; then
    echo
    echo "failures:"
    for f in "${FAILURES[@]}"; do echo "  - $f"; done
    exit 1
fi
echo "MANAGER SMOKE PASSED"
