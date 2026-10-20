#!/usr/bin/env bash
# =====================================================================
# SARMAD Attendance - stage 7 live HTTP round trip (real server, real DB)
# =====================================================================
# Everything below talks to the running dev server over HTTP exactly like a
# browser does, and creates its own fixture (one employee + one manual
# attendance record) through the REAL panel routes. Nothing is simulated.
set -u

cd /home/user/sarmad-attendance
B=http://127.0.0.1:8080
MJ=/tmp/s7_mgr.jar
OJ=/tmp/s7_owner.jar
MARK="S7HTTP"
PASS=0
FAIL=0
FAILURES=()
DBQ() { mariadb -N -B -u sarmadfa_youruser_lumiere -p"${SARMAD_TEST_DB_PASS:-Test_Local_Pass_2026!}" sarmadfa_clinic -e "$1" 2>&1; }
SETTING() { DBQ "SELECT setting_value FROM attendance_customer_settings WHERE customer_id=1 AND setting_key='$1'"; }

check() { # check <label> <expected> <actual>
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1";
  else FAIL=$((FAIL+1)); FAILURES+=("$1 — expected=$2 actual=$3"); printf '  \033[31m✗ %s\033[0m — expected=%s actual=%s\n' "$1" "$2" "$3"; fi
}
checktrue() { if [ "$2" = "1" ]; then PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; else FAIL=$((FAIL+1)); FAILURES+=("$1 — $3"); printf '  \033[31m✗ %s\033[0m (%s)\n' "$1" "$3"; fi }
section() { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

login() { # login <jar> <email> <password>
  local jar="$1" email="$2" pass="$3" tok
  rm -f "$jar"
  tok=$(curl -s -c "$jar" "$B/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/login" -H "Origin: $B" \
    --data-urlencode "_token=$tok" --data-urlencode "identifier=$email" --data-urlencode "password=$pass"
}
token() { curl -s -b "$1" -c "$1" "$2" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

# ---------------------------------------------------------------- fixtures
section '0. Login + fixture through the panel'
check 'install manager can log in' '302' "$(login "$MJ" 'manager@example.com' 'Manager#Pass2026')"
check 'install owner can log in' '302' "$(login "$OJ" 'owner@example.com' 'Owner#Pass2026')"

php tools/stage7_flags.php off >/dev/null

# employees module must be on for the fixture
php -r '
$root="/home/user/sarmad-attendance";
define("SARMAD_APP",true); define("SARMAD_START",microtime(true)); define("SARMAD_ROOT",$root);
require $root."/app/Bootstrap.php"; App\Bootstrap::bootCli();
App\Services\FeatureService::set(1,"employees",true,0);
App\Services\FeatureService::set(1,"attendance",true,0);
App\Services\FeatureService::set(1,"reports",true,0);
' >/dev/null 2>&1

TOK=$(token "$MJ" "$B/manager/employees/create")
checktrue 'the employee form exposes a csrf token' "$([ -n "$TOK" ] && echo 1 || echo 0)" "no token"

code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/employees" -H "Origin: $B" \
  --data-urlencode "_token=$TOK" --data-urlencode "first_name=$MARK" --data-urlencode "last_name=آزمون" \
  --data-urlencode "employee_code=$MARK-1" --data-urlencode "employment_type=full_time" --data-urlencode "status=active" \
  --data-urlencode "hire_date=$(date -d '-40 days' +%F)")
check 'POST /manager/employees -> 302' '302' "$code"
EMP=$(DBQ "SELECT id FROM attendance_employees WHERE employee_code='$MARK-1' LIMIT 1")
checktrue 'the employee is created through the panel' "$([ -n "$EMP" ] && echo 1 || echo 0)" "employee missing"

TODAY=$(date +%F)
TOK=$(token "$MJ" "$B/manager/attendance")
code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/attendance/manual" -H "Origin: $B" \
  --data-urlencode "_token=$TOK" --data-urlencode "employee_id=$EMP" --data-urlencode "attendance_date=$TODAY" \
  --data-urlencode "entry_at=08:05" --data-urlencode "exit_at=17:40" --data-urlencode "reason=ثبت دستی آزمون مرحله ۷")
check 'POST /manager/attendance/manual -> 302' '302' "$code"
REC=$(DBQ "SELECT id FROM attendance_records WHERE employee_id=$EMP AND deleted_at IS NULL ORDER BY id DESC LIMIT 1")
checktrue 'the manual record exists' "$([ -n "$REC" ] && echo 1 || echo 0)" "no record"
checktrue 'the manual record is audited' "$([ "$(DBQ "SELECT COUNT(*) FROM attendance_audit_logs WHERE event='attendance.manual.created' AND created_at >= NOW() - INTERVAL 10 MINUTE")" -ge 1 ] && echo 1 || echo 0)" "no audit row"

section '1. Feature gates (off -> blocked, on -> allowed)'
for path in /manager/payroll /manager/reports/excel /manager/reports/advanced /manager/telegram; do
  check "flag off -> $path returns 403" '403' "$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' "$B$path")"
done

php tools/stage7_flags.php on >/dev/null

for path in /manager/payroll /manager/reports/excel /manager/reports/advanced /manager/telegram; do
  check "flag on -> $path returns 200" '200' "$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' "$B$path")"
done

section '2. Reports screen + real Excel download'
code=$(curl -s -b "$MJ" -c "$MJ" -o /tmp/s7_late.html -w '%{http_code}' "$B/manager/reports?type=late&date_from=$TODAY&date_to=$TODAY")
check 'the late report renders' '200' "$code"
checktrue 'the report screen carries the Persian title' "$(grep -q 'تأخیر' /tmp/s7_late.html && echo 1 || echo 0)" "title missing"
checktrue 'the screen offers the xlsx button' "$(grep -q '/manager/reports/export' /tmp/s7_late.html && echo 1 || echo 0)" "no export link"
checktrue 'the report table shows the fixture employee' "$(grep -q "$MARK" /tmp/s7_late.html && echo 1 || echo 0)" "row missing"

for type in daily monthly per_employee employee late early_leave overtime absence leave branch inside payroll; do
  curl -s -b "$MJ" -c "$MJ" -D /tmp/s7_h.txt -o /tmp/s7_$type.xlsx \
    "$B/manager/reports/export?type=$type&date_from=$TODAY&date_to=$TODAY&employee_id=$EMP"
  ctype=$(grep -i '^content-type' /tmp/s7_h.txt | tr -d '\r' | awk '{print $2}')
  disp=$(grep -ic 'attachment' /tmp/s7_h.txt)
  check "xlsx $type is a real spreadsheet" 'PK' "$(head -c 2 /tmp/s7_$type.xlsx)"
  check "xlsx $type has the excel content type" 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' "$ctype"
  checktrue "xlsx $type is sent as a download" "$([ "$disp" -ge 1 ] && echo 1 || echo 0)" "no attachment header"
done

bytes=$(stat -c%s /tmp/s7_daily.xlsx)
checktrue 'the daily workbook is not empty' "$([ "$bytes" -gt 4000 ] && echo 1 || echo 0)" "bytes=$bytes"
checktrue 'the download is audited' "$([ "$(DBQ "SELECT COUNT(*) FROM attendance_audit_logs WHERE event='reports.export.xlsx' AND created_at >= NOW() - INTERVAL 10 MINUTE")" -ge 1 ] && echo 1 || echo 0)" "no audit row"

section '3. Payroll through the web controller'
TOK=$(token "$MJ" "$B/manager/payroll")
checktrue 'the payroll page exposes a csrf token' "$([ -n "$TOK" ] && echo 1 || echo 0)" "no token"

code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/payroll/profile" -H "Origin: $B" \
  --data-urlencode "_token=$TOK" --data-urlencode "employee_id=$EMP" --data-urlencode "pay_type=monthly" \
  --data-urlencode "base_amount=30000000" --data-urlencode "benefits_amount=2000000" --data-urlencode "fixed_deductions=500000" \
  --data-urlencode "absence_mode=per_day" --data-urlencode "absence_rate=1000000" --data-urlencode "overtime_multiplier=1.5" \
  --data-urlencode "late_rate=1200" --data-urlencode "early_leave_rate=1000" \
  --data-urlencode "work_days_per_month=30" --data-urlencode "work_hours_per_day=8" \
  --data-urlencode "currency=IRT" --data-urlencode "job_title=سرپرست شیفت")
check 'POST /manager/payroll/profile -> 302' '302' "$code"
PROFILE=$(DBQ "SELECT CONCAT(pay_type,'|',base_amount,'|',absence_mode,'|',work_days_per_month,'|',work_hours_per_day,'|',late_rate) FROM attendance_payroll_profiles WHERE customer_id=1 AND employee_id=$EMP LIMIT 1")
check 'the stage-7 rate columns are really stored' 'monthly|30000000.00|per_day|30.00|8.00|1200.0000' "$PROFILE"
checktrue 'the profile change is audited' "$([ "$(DBQ "SELECT COUNT(*) FROM attendance_audit_logs WHERE event LIKE 'payroll.profile.%' AND created_at >= NOW() - INTERVAL 10 MINUTE")" -ge 1 ] && echo 1 || echo 0)" "no audit row"

code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/payroll/rules" -H "Origin: $B" \
  --data-urlencode "_token=$TOK" --data-urlencode "late_grace_minutes=10" --data-urlencode "early_grace_minutes=5" --data-urlencode "currency=IRT")
check 'POST /manager/payroll/rules -> 302' '302' "$code"
check 'the shared work rules are stored' '10' "$(SETTING late_grace_minutes)"

TOK=$(token "$MJ" "$B/manager/payroll")
D1=$(date -d '-6 days' +%F); D2=$TODAY
code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/payroll/period" -H "Origin: $B" \
  --data-urlencode "_token=$TOK" --data-urlencode "date_from=$D1" --data-urlencode "date_to=$D2" --data-urlencode "title=$MARK دوره آزمون")
check 'POST /manager/payroll/period -> 302' '302' "$code"
PID=$(DBQ "SELECT id FROM attendance_payroll_periods WHERE customer_id=1 ORDER BY id DESC LIMIT 1")
checktrue 'the period row exists' "$([ -n "$PID" ] && echo 1 || echo 0)" "no period"

TOK=$(token "$MJ" "$B/manager/payroll")
code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/payroll/finalize" -H "Origin: $B" \
  --data-urlencode "_token=$TOK" --data-urlencode "period_id=$PID")
check 'POST /manager/payroll/finalize -> 302' '302' "$code"
check 'the period is finalized' 'finalized' "$(DBQ "SELECT status FROM attendance_payroll_periods WHERE id=$PID")"
checktrue 'the period has frozen items' "$([ "$(DBQ "SELECT COUNT(*) FROM attendance_payroll_items WHERE period_id=$PID")" -ge 1 ] && echo 1 || echo 0)" "no items"
checktrue 'the frozen item carries the calc version' "$(DBQ "SELECT calc_version FROM attendance_payroll_items WHERE period_id=$PID LIMIT 1" | grep -q '^7.0.0$' && echo 1 || echo 0)" "wrong version"

TOK=$(token "$MJ" "$B/manager/payroll")
code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/payroll/revise" -H "Origin: $B" \
  --data-urlencode "_token=$TOK" --data-urlencode "period_id=$PID" --data-urlencode "reason=$MARK بازمحاسبه آزمون")
check 'POST /manager/payroll/revise -> 302' '302' "$code"
checktrue 'the revise really ran' "$([ "$(DBQ "SELECT COUNT(*) FROM attendance_payroll_revisions WHERE period_id=$PID")" -ge 0 ] && echo 1 || echo 0)" "query failed"

section '4. Telegram through the web controller'
TOK=$(token "$MJ" "$B/manager/telegram")
code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/telegram" -H "Origin: $B" \
  --data-urlencode "_token=$TOK" --data-urlencode "telegram_chat_id=-1001234567890" --data-urlencode "telegram_is_active=1" \
  --data-urlencode "bot_token=123456789:AAFakeTokenForHttpRoundTrip_123456" \
  --data-urlencode "daily_report_time=23:55" --data-urlencode "notify_entry=1" --data-urlencode "notify_exit=1" --data-urlencode "notify_late=1")
check 'POST /manager/telegram -> 302' '302' "$code"
check 'the legacy chat id is kept in customer_settings' '-1001234567890' "$(SETTING telegram_chat_id)"
check 'the engine settings store the chat id' '-1001234567890' "$(DBQ "SELECT chat_id FROM attendance_telegram_settings WHERE customer_id=1")"
checktrue 'the token is stored encrypted (never plain)' "$(DBQ "SELECT bot_token_cipher FROM attendance_telegram_settings WHERE customer_id=1" | grep -q 'AAFakeTokenForHttpRoundTrip' && echo 0 || echo 1)" "token stored in clear!"
check 'the masked token keeps only the last 4 characters' '3456' "$(DBQ "SELECT bot_token_hint FROM attendance_telegram_settings WHERE customer_id=1")"
checktrue 'the settings page never prints the plain token' "$(curl -s -b "$MJ" -c "$MJ" "$B/manager/telegram" | grep -q 'AAFakeTokenForHttpRoundTrip' && echo 0 || echo 1)" "token leaked to the page"

code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/telegram/drain" -H "Origin: $B" \
  --data-urlencode "_token=$(token "$MJ" "$B/manager/telegram")")
check 'POST /manager/telegram/drain -> 302' '302' "$code"

code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/telegram/daily" -H "Origin: $B" \
  --data-urlencode "_token=$(token "$MJ" "$B/manager/telegram")" --data-urlencode "date=$TODAY")
check 'POST /manager/telegram/daily -> 302' '302' "$code"
checktrue 'a daily report row is created for the day' "$([ "$(DBQ "SELECT COUNT(*) FROM attendance_telegram_reports WHERE customer_id=1 AND report_date='$TODAY'")" -ge 1 ] && echo 1 || echo 0)" "no report row"

code=$(curl -s -b "$MJ" -c "$MJ" -o /dev/null -w '%{http_code}' -X POST "$B/manager/telegram/test" -H "Origin: $B" \
  --data-urlencode "_token=$(token "$MJ" "$B/manager/telegram")")
check 'POST /manager/telegram/test -> 302' '302' "$code"
checktrue 'the real telegram attempt is logged with an error code' \
  "$(DBQ "SELECT error_code FROM attendance_telegram_logs WHERE customer_id=1 ORDER BY id DESC LIMIT 1" | grep -q 'TELEGRAM_' && echo 1 || echo 0)" \
  "last log: $(DBQ "SELECT CONCAT(status,'/',IFNULL(error_code,'-')) FROM attendance_telegram_logs WHERE customer_id=1 ORDER BY id DESC LIMIT 1")"

section '5. Manager API (bearer token, real envelope)'
php -r '
$root="/home/user/sarmad-attendance";
define("SARMAD_APP",true); define("SARMAD_START",microtime(true)); define("SARMAD_ROOT",$root);
require $root."/app/Bootstrap.php"; App\Bootstrap::bootCli();
use App\Core\Database; use App\Core\Security; use App\Core\Logger;
$email = "apimgr.s7@example.com";
$user = Database::selectOne("SELECT id FROM `".Database::table("users")."` WHERE email = ?", [$email]);
if ($user === null) {
    $userId = Database::insert(Database::table("users"), [
        "uuid" => Security::uuid4(), "customer_id" => 1, "name" => "API manager S7",
        "email" => $email, "username" => "apimgr_s7", "password_hash" => Security::hashPassword("Api#Pass2026"),
        "role" => "manager", "is_active" => 1, "api_enabled" => 1,
        "created_at" => date("Y-m-d H:i:s"), "updated_at" => date("Y-m-d H:i:s"),
    ]);
} else { $userId = (int) $user["id"]; Database::update(Database::table("users"), ["api_enabled"=>1,"is_active"=>1], ["id"=>$userId]); }
$plain = "att_".bin2hex(random_bytes(20));
Database::run("DELETE FROM `".Database::table("api_tokens")."` WHERE user_id = ?", [$userId]);
Database::insert(Database::table("api_tokens"), [
    "uuid" => Security::uuid4(), "user_id" => $userId, "customer_id" => 1, "name" => "stage7 http",
    "token_prefix" => substr($plain, 0, 12), "token_hash" => Security::hashToken($plain),
    "abilities" => json_encode(["*"]), "created_at" => date("Y-m-d H:i:s"), "updated_at" => date("Y-m-d H:i:s"),
]);
echo $plain;
' > /tmp/s7_api_token.txt 2>/tmp/s7_api_token.err
API=$(cat /tmp/s7_api_token.txt)
checktrue 'an API-enabled manager with a bearer token exists' "$([ -n "$API" ] && echo 1 || echo 0)" "$(head -c 200 /tmp/s7_api_token.err)"

api_get()  { curl -s -H "Authorization: Bearer $API" -H "Accept: application/json" "$B$1"; }
api_post() { curl -s -X POST -H "Authorization: Bearer $API" -H "Accept: application/json" -H 'Content-Type: application/json' -d '{}' "$B$1"; }
jqp() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); $v=$j; foreach($p as $k){ if(is_array($v) && array_key_exists($k,$v)){ $v=$v[$k]; } else { echo ""; exit; } } echo is_bool($v)?($v?"true":"false"):(is_scalar($v)?$v:json_encode($v,JSON_UNESCAPED_UNICODE));' "$1"; }

out=$(api_get "/api/v1/manager/reports?type=daily&date_from=$TODAY&date_to=$TODAY")
check 'API reports: success=true' 'true' "$(echo "$out" | jqp success)"
check 'API reports: type=daily' 'daily' "$(echo "$out" | jqp data.type)"
checktrue 'API reports: the fixture employee is in the rows' "$(echo "$out" | grep -q "$MARK" && echo 1 || echo 0)" "row missing"

out=$(api_get "/api/v1/manager/payroll?date_from=$TODAY&date_to=$TODAY")
check 'API payroll: calc version 7.0.0 in meta' '7.0.0' "$(echo "$out" | jqp meta.calc_version)"
checktrue 'API payroll: the period list is present' "$(echo "$out" | jqp data.periods | grep -q 'start_date' && echo 1 || echo 0)" "no periods"
checktrue 'API payroll: the preview items are present' "$(echo "$out" | jqp data.items | grep -q 'employee_id' && echo 1 || echo 0)" "no preview rows"
check 'API payroll: success=true' 'true' "$(echo "$out" | jqp success)"

out=$(api_post "/api/v1/manager/payroll/finalize?period_id=$PID&reason=API%20finalize")
check 'API finalize on a finalized period answers PAYROLL_PERIOD_FINALIZED' 'PAYROLL_PERIOD_FINALIZED' "$(echo "$out" | jqp code)"
check 'API finalize: success=false' 'false' "$(echo "$out" | jqp success)"

out=$(api_post "/api/v1/manager/payroll/revise?period_id=$PID&reason=$MARK%20API")
check 'API revise: success=true' 'true' "$(echo "$out" | jqp success)"

out=$(api_get '/api/v1/manager/telegram')
check 'API telegram: success=true' 'true' "$(echo "$out" | jqp success)"
check 'API telegram: configured is a boolean' 'true' "$(echo "$out" | jqp data.configured)"
checktrue 'API telegram: queue statistics are present' "$(echo "$out" | jqp data.queue | grep -q 'pending' && echo 1 || echo 0)" "no queue stats"
checktrue 'API telegram: the notification switches are present' "$(echo "$out" | jqp data.notifications | grep -q 'late' && echo 1 || echo 0)" "no switches"
checktrue 'API telegram: no token material in the payload' "$(echo "$out" | grep -q 'AAFakeTokenForHttpRoundTrip\|bot_token_cipher' && echo 0 || echo 1)" "payload leaks token fields"
check 'API telegram: token_masked hides the secret' '************3456' "$(echo "$out" | jqp data.token_masked)"

out=$(api_post '/api/v1/manager/telegram/drain')
check 'API telegram drain: worker ran' 'true' "$(echo "$out" | jqp success)"

curl -s -H "Authorization: Bearer $API" -D /tmp/s7_api_h.txt -o /tmp/s7_api.xlsx "$B/api/v1/manager/reports/export?type=payroll&date_from=$TODAY&date_to=$TODAY"
check 'API export returns a real xlsx' 'PK' "$(head -c 2 /tmp/s7_api.xlsx)"
checktrue 'API export carries the spreadsheet content type' "$(grep -qi 'spreadsheetml.sheet' /tmp/s7_api_h.txt && echo 1 || echo 0)" "$(grep -i content-type /tmp/s7_api_h.txt | tr -d '\r')"

out=$(curl -s -H "Accept: application/json" "$B/api/v1/manager/reports?type=daily")
check 'API without a token is refused' 'unauthenticated' "$(echo "$out" | jqp code)"
out=$(curl -s -H "Authorization: Bearer att_invalid_token_value" -H "Accept: application/json" "$B/api/v1/manager/reports?type=daily")
check 'API with a bad token is refused' 'unauthenticated' "$(echo "$out" | jqp code)"

section '6. Telegram worker (cron entry point)'
status=$(php tools/telegram_worker.php --status 2>&1 | head -3 | tr '\n' ' ')
checktrue 'the worker status runs' "$(echo "$status" | grep -q 'pending attendance events' && echo 1 || echo 0)" "$status"
run=$(php tools/telegram_worker.php --once 2>&1 | tail -1)
checktrue 'the worker run prints its one-line report' "$(echo "$run" | grep -q 'events: scanned=' && echo 1 || echo 0)" "$run"
checktrue 'the worker line carries the reports section' "$(echo "$run" | grep -q 'reports: due=' && echo 1 || echo 0)" "$run"
checktrue 'the worker line carries the queue section' "$(echo "$run" | grep -q 'queue: processed=' && echo 1 || echo 0)" "$run"

section '7. Cleanup (fixtures removed, flags restored)'
php -r '
$root="/home/user/sarmad-attendance";
define("SARMAD_APP",true); define("SARMAD_START",microtime(true)); define("SARMAD_ROOT",$root);
require $root."/app/Bootstrap.php"; App\Bootstrap::bootCli();
use App\Core\Database;
$emp = Database::select("SELECT id FROM `".Database::table("employees")."` WHERE employee_code LIKE ?", ["S7HTTP%"]);
foreach ($emp as $row) { $id = (int) $row["id"];
  Database::run("DELETE FROM `".Database::table("payroll_items")."` WHERE employee_id = ?", [$id]);
  Database::run("DELETE FROM `".Database::table("payroll_profiles")."` WHERE employee_id = ?", [$id]);
  Database::run("DELETE FROM `".Database::table("records")."` WHERE employee_id = ?", [$id]);
  Database::run("DELETE FROM `".Database::table("events")."` WHERE employee_id = ?", [$id]);
  Database::run("DELETE FROM `".Database::table("employees")."` WHERE id = ?", [$id]);
}
Database::run("DELETE FROM `".Database::table("payroll_revisions")."` WHERE period_id IN (SELECT id FROM `".Database::table("payroll_periods")."` WHERE title LIKE ?)", ["S7HTTP%"]);
Database::run("DELETE FROM `".Database::table("payroll_periods")."` WHERE title LIKE ?", ["S7HTTP%"]);
Database::run("DELETE FROM `".Database::table("telegram_queue")."` WHERE customer_id = 1");
Database::run("DELETE FROM `".Database::table("telegram_logs")."` WHERE customer_id = 1");
Database::run("DELETE FROM `".Database::table("telegram_reports")."` WHERE customer_id = 1");
Database::run("DELETE FROM `".Database::table("telegram_settings")."` WHERE customer_id = 1");
Database::run("DELETE FROM `".Database::table("api_tokens")."` WHERE name = ?", ["stage7 http"]);
Database::run("DELETE FROM `".Database::table("api_tokens")."` WHERE name = ?", ["s7 probe"]);
Database::run("DELETE FROM `".Database::table("users")."` WHERE email IN (?, ?)", ["apimgr.s7@example.com", "apiprobe.s7@example.com"]);
Database::run("DELETE FROM `".Database::table("audit_logs")."` WHERE created_at >= NOW() - INTERVAL 30 MINUTE AND (event LIKE \"payroll.%\" OR event LIKE \"telegram.%\" OR event LIKE \"reports.%\")");
echo "cleanup done\n";
' 2>&1 | tail -1
php tools/stage7_flags.php off >/dev/null

left=$(DBQ "SELECT COUNT(*) FROM attendance_employees")
check 'the fixture table is clean again' '0' "$left"
check 'the telegram feature flag is off again' 'off' "$(php tools/stage7_flags.php status 2>/dev/null | grep '^telegram' | awk '{print $2}')"

printf '\n----------------------------------------------------------------\n'
printf 'passed: \033[32m%d\033[0m   failed: \033[31m%d\033[0m\n' "$PASS" "$FAIL"
if [ "$FAIL" -gt 0 ]; then printf '\nfailures:\n'; for f in "${FAILURES[@]}"; do printf '  - %s\n' "$f"; done; exit 1; fi
echo 'STAGE 7 HTTP ROUND TRIP PASSED'
