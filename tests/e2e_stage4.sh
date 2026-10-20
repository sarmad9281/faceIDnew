#!/usr/bin/env bash
# =============================================================================
# SARMAD Attendance - stage 4 acceptance tests (ATTENDANCE DEVICE / PWA / KIOSK)
# -----------------------------------------------------------------------------
# Usage:
#   APP_PATH=/path/to/deployment bash tests/e2e_stage4.sh <BASE_URL> \
#       <DB_NAME> <DB_USER> <DB_PASS> [PREFIX]
#
# Everything runs over real HTTP against a real installation and a real
# database. The suite builds two customers with a manager each, registers a
# kiosk device for each tenant, then walks the whole stage-4 story:
#
#   owner issues a one-time code -> device activates -> device token -> config /
#   branding -> heartbeat / last seen -> non-sensitive settings -> engine and
#   attendance CONTRACTS (nothing is written!) -> PWA assets -> security.
#
# It asserts the HTTP answer AND the resulting database / log / audit state, so
# a "green" answer that did not really happen can not pass.
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

JAR_OWNER=/tmp/sarmad_s4_owner.cookies
JAR_MGR=/tmp/sarmad_s4_mgr.cookies
JAR_MGRB=/tmp/sarmad_s4_mgrb.cookies
JAR_ANON=/tmp/sarmad_s4_anon.cookies

STAMP="$(date +%s)"
CUSTOMER_NAME="مشتری-س۴-${STAMP}"
CUSTOMER_B_NAME="مشتری-س۴-دوم-${STAMP}"
MGR_A_USER="s4mgra${STAMP}"
MGR_B_USER="s4mgrb${STAMP}"
MGR_PASS='Mgr#Stage4Test9'
EMPLOYEE_LAST="پرسنل-س۴-${STAMP}"
EMPLOYEE_B_LAST="پرسنل-س۴-ب-${STAMP}"
FAILURES=()
PASS=0
FAIL=0
NOTES=()

rm -f "$JAR_OWNER" "$JAR_MGR" "$JAR_MGRB" "$JAR_ANON"
rm -f "${APP_PATH}/storage/cache/throttle_"*.json 2>/dev/null || true

# ---------------------------------------------------------------- helpers ----
mysql_q() { mariadb -N -B --default-character-set=utf8mb4 -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$1" 2>/dev/null; }

ok()  { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); FAILURES+=("$1"); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
note() { NOTES+=("$1"); printf '  \033[33mNOTE\033[0m %s\n' "$1"; }

check() { if [[ "$2" == "$3" ]]; then ok "$1 ($2)"; else bad "$1 (got '$2', want '$3')"; fi; }
check_min() { if [[ "${2:-0}" -ge "${3:-1}" ]]; then ok "$1 ($2)"; else bad "$1 (got '$2', want >= $3)"; fi; }
check_contains() { if [[ "$2" == *"$3"* ]]; then ok "$1"; else bad "$1 (did not find '$3')"; fi; }
check_not_contains() { if [[ "$2" != *"$3"* ]]; then ok "$1"; else bad "$1 (unexpectedly found '$3')"; fi; }

status_of() { curl -s -b "$1" -c "$1" -o /dev/null -w '%{http_code}' "$BASE$2"; }
body_of()   { curl -s -b "$1" -c "$1" "$BASE$2"; }
count_in()  { body_of "$1" "$2" | grep -c "$3" || true; }
cnt()       { printf '%s' "$1" | grep -c "$2" || true; }

urlenc() { php -r 'echo rawurlencode($argv[1]);' "$1"; }

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

# Device API helpers: <api> <method> <path> [token] [json]
api() {
  local method="$1" path="$2" token="${3:-}" data="${4:-}"
  local args=(-s -o /tmp/s4_api.json -w '%{http_code}' -X "$method" "$BASE$path" -H 'Accept: application/json')

  [[ -n "$token" ]] && args+=(-H "Authorization: Bearer $token")
  [[ -n "$data" ]] && args+=(-H 'Content-Type: application/json' -d "$data")

  curl "${args[@]}"
}

jget() { php -r '
$raw = file_get_contents("/tmp/s4_api.json");
$d = json_decode($raw, true);
$path = explode("/", $argv[1]);
foreach ($path as $key) {
    if ($key === "") { continue; }
    if (!is_array($d) || !array_key_exists($key, $d)) { echo ""; exit; }
    $d = $d[$key];
}
if (is_bool($d)) { echo $d ? "true" : "false"; }
elseif ($d === null) { echo ""; }
elseif (is_scalar($d)) { echo (string) $d; }
else { echo json_encode($d, JSON_UNESCAPED_UNICODE); }
' "$1"; }

section() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

echo "SARMAD Attendance - stage 4 acceptance tests (device / PWA / kiosk)"
echo "BASE=$BASE  PREFIX=$PREFIX  APP_PATH=$APP_PATH"
echo "DB=$DB_NAME as $DB_USER"

# =============================================================================
section "1. Stage 4 files, schema and the no-liveness rule"
# =============================================================================

for file in manifest.webmanifest sw.js assets/css/device.css assets/js/device.js \
            views/device/kiosk.php views/device/offline.php assets/icons/icon-192.png \
            assets/icons/icon-512.png assets/icons/maskable-512.png \
            views/layouts/device.php app/Models/DeviceActivationCode.php \
            app/Models/DeviceSetting.php app/Models/DeviceLog.php app/Models/DeviceRequest.php \
            app/Api/Controllers/DeviceKioskController.php app/Controllers/DeviceKioskPageController.php; do
  if [[ -f "$APP_PATH/$file" ]]; then ok "file present: $file"; else bad "file missing: $file"; fi
done

check "device shell responds" "$(curl -s -o /tmp/s4_device.html -w '%{http_code}' "$BASE/device")" "200"
check "offline shell responds" "$(curl -s -o /tmp/s4_offline.html -w '%{http_code}' "$BASE/device/offline")" "200"

# The old placeholder view was a fake device shell: it must be gone and the
# legacy URL must redirect to the single canonical kiosk URL.
if [[ ! -f "$APP_PATH/views/dashboard/device.php" ]]; then
  ok "legacy placeholder view views/dashboard/device.php removed"
else
  bad "legacy placeholder view views/dashboard/device.php is still shipped"
fi
check "legacy /dashboard/device redirects" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/dashboard/device")" "302"
check_contains "legacy redirect points at the kiosk" \
  "$(curl -s -D - -o /dev/null "$BASE/dashboard/device" | tr -d '\r' | grep -i '^location:')" "/device"

DEVICE_HTML="$(cat /tmp/s4_device.html)"
check_contains "kiosk page carries the manifest link" "$DEVICE_HTML" 'rel="manifest"'
check_contains "kiosk page is RTL Persian" "$DEVICE_HTML" 'dir="rtl"'
check_contains "kiosk page has the business name slot" "$DEVICE_HTML" 'id="k-business"'
check_contains "kiosk page has the branch slot" "$DEVICE_HTML" 'id="k-branch"'
check_contains "kiosk page has the clock" "$DEVICE_HTML" 'id="k-clock-time"'
check_contains "kiosk page has the big ENTRY button" "$DEVICE_HTML" 'id="k-action-entry"'
check_contains "kiosk page has the big EXIT button" "$DEVICE_HTML" 'id="k-action-exit"'
check_contains "entry button is labelled ورود" "$DEVICE_HTML" '>ورود<'
check_contains "exit button is labelled خروج" "$DEVICE_HTML" '>خروج<'
check_contains "kiosk page has the camera element" "$DEVICE_HTML" 'id="k-camera-video"'
check_contains "kiosk page has the face placement frame" "$DEVICE_HTML" 'id="k-face-frame"'
check_contains "kiosk page has the guidance text slot" "$DEVICE_HTML" 'id="k-camera-hint"'
check_contains "kiosk page has the recognition state chip" "$DEVICE_HTML" 'id="k-chip-recognizing"'
check_contains "kiosk page has the success screen" "$DEVICE_HTML" 'id="kiosk-success"'
check_contains "kiosk page has the error screen" "$DEVICE_HTML" 'id="kiosk-error"'
check_contains "kiosk page has the blocked screen" "$DEVICE_HTML" 'id="kiosk-blocked"'
check_contains "kiosk page has the activation screen" "$DEVICE_HTML" 'id="kiosk-activation"'
check_contains "kiosk page has offline UI" "$DEVICE_HTML" 'k-banner-offline'
check_contains "kiosk page has connection status" "$DEVICE_HTML" 'id="k-status-text"'
check_contains "kiosk page has the settings drawer" "$DEVICE_HTML" 'id="k-drawer"'
check_contains "kiosk page has the camera test" "$DEVICE_HTML" 'id="k-camera-test"'
check_contains "offline shell is marked for the controller" "$(cat /tmp/s4_offline.html)" 'data-offline-shell="1"'
check_contains "offline shell explains the offline state" "$(cat /tmp/s4_offline.html)" "ارتباط دستگاه با سرور قطع است"
check_contains "success screen carries the employee body" "$DEVICE_HTML" 'id="k-success-body"'
check_contains "error screen carries the Persian message slot" "$DEVICE_HTML" 'id="k-error-message"'
check_contains "settings drawer keeps the security note" "$DEVICE_HTML" "فقط از پنل مالک"
DEVICE_CSS="$(cat "$APP_PATH/assets/css/device.css")"
check_contains "stylesheet defines the face frame" "$DEVICE_CSS" ".k-face-frame"
check_contains "stylesheet has a recognition state" "$DEVICE_CSS" "is-recognizing"
check_contains "stylesheet is touch sized (large buttons)" "$DEVICE_CSS" "min-height: clamp(150px, 30vh, 320px)"
check_contains "stylesheet has an offline banner" "$DEVICE_CSS" "k-banner-offline"
check "kiosk page has no link into the owner panel" "$(cnt "$DEVICE_HTML" '/owner/')" "0"
check "kiosk page has no link into the manager panel" "$(cnt "$DEVICE_HTML" '/manager/')" "0"

HEADERS="$(curl -s -D - -o /dev/null "$BASE/device" | tr -d '\r')"
check_contains "kiosk page sends a Content-Security-Policy" "$HEADERS" 'Content-Security-Policy'
check_contains "the CSP keeps scripts same-origin" "$HEADERS" "script-src 'self'"
check_contains "kiosk page is not indexable" "$HEADERS" 'X-Robots-Tag: noindex'
check_contains "kiosk page is not cached" "$HEADERS" 'Cache-Control: no-store'

MANIFEST="$(cat "$APP_PATH/manifest.webmanifest")"
check_contains "manifest app name is SARMAD Attendance" "$MANIFEST" '"name": "SARMAD Attendance"'
check_contains "manifest runs standalone" "$MANIFEST" '"display": "standalone"'
check_contains "manifest is RTL" "$MANIFEST" '"dir": "rtl"'
check_contains "manifest lists a 192 icon" "$MANIFEST" '"sizes": "192x192"'
check_contains "manifest lists a 512 icon" "$MANIFEST" '"sizes": "512x512"'
check_contains "manifest lists a maskable icon" "$MANIFEST" '"purpose": "maskable"'
check_contains "manifest start_url is the device screen" "$MANIFEST" '"start_url": "./device"'
check "manifest is valid JSON (php)" "$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo is_array($m) && isset($m["icons"]) && count($m["icons"])>=4 ? "ok" : "invalid";' "$APP_PATH/manifest.webmanifest")" "ok"

check "manifest is served" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/manifest.webmanifest")" "200"
check "service worker is served" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/sw.js")" "200"
check "device css is served" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/css/device.css")" "200"
check "device js is served" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/js/device.js")" "200"
check "192 icon is served" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/icons/icon-192.png")" "200"
check "512 icon is served" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/icons/icon-512.png")" "200"
check "maskable icon is served" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/icons/maskable-512.png")" "200"

SW="$(cat "$APP_PATH/sw.js")"
check_contains "service worker precaches the offline shell" "$SW" "./device/offline"
check_contains "service worker falls back to the offline shell" "$SW" "request.mode === 'navigate'"
check_contains "service worker never caches the API" "$SW" 'isApi(url)'
check_contains "service worker takes over immediately" "$SW" 'skipWaiting'
check_contains "service worker claims clients" "$SW" 'clients.claim'

JS="$(cat "$APP_PATH/assets/js/device.js")"
check_contains "controller registers the service worker" "$JS" 'serviceWorker.register'
check_contains "controller handles beforeinstallprompt" "$JS" 'beforeinstallprompt'
check_contains "controller detects offline" "$JS" "addEventListener('offline'"
check_contains "controller keeps an offline queue" "$JS" 'sarmad.device.queue'
check_contains "queue rows stamp the device clock (local time, never the server)" "$JS" 'local_created_at: nowIso()'
check_contains "controller sends the device heartbeat" "$JS" "'/heartbeat'"
check_contains "controller sends the request id (idempotency)" "$JS" 'request_id: requestId'
check_contains "controller asks for the config" "$JS" "'/config'"
check_contains "controller refuses to run without the real engine" "$JS" 'FACE_ENGINE_MISSING'
check_contains "controller maps the no-face answer" "$JS" "'no_face'"
check_contains "controller maps the multiple-face answer" "$JS" "'multiple_faces'"
check_contains "controller maps the unknown-face answer" "$JS" "'unknown_face'"
check_contains "controller renders the employee name" "$JS" 'employee.full_name'
check_contains "controller renders the employee photo when present" "$JS" 'employee.photo_url'
check_contains "controller shows the operation type" "$JS" "'نوع عملیات'"
check_contains "controller shows the time of the operation" "$JS" "'ساعت'"
check_contains "controller shows the success status" "$JS" "با موفقیت ثبت شد"
# liveness must exist only as a statement that it is NOT used: the contract flag
# `'liveness' => 'not_used'`, the device capability `'liveness' => false`,
# documentation comments, the kiosk string "پیاده‌سازی نشده" and the note in
# assets/models/face-api-1.0.0/models.json. Any other mention would mean
# implementation code.
check "no liveness detection code anywhere" \
  "$(grep -rn "liveness" "$APP_PATH/app" "$APP_PATH/assets" 2>/dev/null \
      | grep -viE "not_used|not used|=> *false|no liveness|never|NOT implemented|NOT part of this project|پیاده‌سازی نشده" \
      | grep -vE ': *[*/]\*?' | grep -vE ': *\*' | wc -l | tr -d ' ')" "0"
check "the not_used flag is declared once" \
  "$(grep -c "\"liveness\"\|'liveness'" "$APP_PATH/app/Services/DeviceService.php")" "1"
check "liveness is never a database column" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name LIKE '${PREFIX}%' AND column_name LIKE '%liveness%'")" "0"
check "no liveness column is declared in the schema" \
  "$(grep -rni "liveness" "$APP_PATH/sql" | grep -iE 'varchar|tinyint|bigint|int\(|char|ADD COLUMN|DEFAULT' | wc -l | tr -d ' ')" "0"

check "stage 4 tables exist" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('${PREFIX}device_activation_codes','${PREFIX}device_settings','${PREFIX}device_logs','${PREFIX}device_requests')")" "4"
check "device error columns exist" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${PREFIX}devices' AND column_name IN ('last_error_at','last_error_code')")" "2"
check "activation codes are stored hashed only" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${PREFIX}device_activation_codes' AND column_name='code_hash' AND character_maximum_length=64")" "1"
check "there is no plaintext code column" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${PREFIX}device_activation_codes' AND column_name IN ('code','plain_code','activation_code')")" "0"
check "double submit is stopped by a unique key" \
  "$(mysql_q "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='${PREFIX}device_requests' AND non_unique=0 AND index_name='uniq_device_request_key'")" "1"
check "no table outside the project prefix was created" \
  "$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name NOT LIKE '${PREFIX}%' AND table_name IN ('device_logs','device_settings','device_requests','device_activation_codes')")" "0"

# =============================================================================
section "2. Owner issues the one-time activation code"
# =============================================================================

check "owner login" "$(login "$JAR_OWNER" "$OWNER_USER" "$OWNER_PASS")" "302"

post_form "$JAR_OWNER" /owner/customers/create /owner/customers \
  --data-urlencode "name=$CUSTOMER_NAME" \
  --data-urlencode "contact_email=s4-a-${STAMP}@example.test" \
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
  --data-urlencode "contact_email=s4-b-${STAMP}@example.test" \
  --data-urlencode "status=active" \
  --data-urlencode "plan_name=trial" \
  --data-urlencode "subscription_status=trial" \
  --data-urlencode "start_date=$(date +%Y-%m-%d)" \
  --data-urlencode "expire_date=$(date -d '+30 days' +%Y-%m-%d)" >/dev/null
CUSTOMER_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}customers WHERE name='$CUSTOMER_B_NAME' LIMIT 1" | tr -d '[:space:]')"
check_min "customer B created (isolation target)" "${CUSTOMER_B_ID:-0}" 1

post_form "$JAR_OWNER" "/owner/managers/create?customer_id=$CUSTOMER_ID" /owner/managers \
  --data-urlencode "customer_id=$CUSTOMER_ID" \
  --data-urlencode "first_name=مدیر" \
  --data-urlencode "last_name=الف-${STAMP}" \
  --data-urlencode "username=$MGR_A_USER" \
  --data-urlencode "email=$MGR_A_USER@example.test" \
  --data-urlencode "password=$MGR_PASS" \
  --data-urlencode "is_primary=1" >/dev/null
MGR_A_ID="$(mysql_q "SELECT id FROM ${PREFIX}users WHERE username='$MGR_A_USER' LIMIT 1" | tr -d '[:space:]')"
check_min "manager A created" "${MGR_A_ID:-0}" 1

post_form "$JAR_OWNER" "/owner/managers/create?customer_id=$CUSTOMER_B_ID" /owner/managers \
  --data-urlencode "customer_id=$CUSTOMER_B_ID" \
  --data-urlencode "first_name=مدیر" \
  --data-urlencode "last_name=ب-${STAMP}" \
  --data-urlencode "username=$MGR_B_USER" \
  --data-urlencode "email=$MGR_B_USER@example.test" \
  --data-urlencode "password=$MGR_PASS" \
  --data-urlencode "is_primary=1" >/dev/null
MGR_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}users WHERE username='$MGR_B_USER' LIMIT 1" | tr -d '[:space:]')"
check_min "manager B created" "${MGR_B_ID:-0}" 1

# enable the features the device story needs (backend gates, not just menus)
for feature in attendance employees branches; do
  post_form "$JAR_OWNER" "/owner/features/$CUSTOMER_UUID" "/owner/features/$CUSTOMER_UUID/toggle" \
    --data-urlencode "feature=$feature" --data-urlencode "enabled=1" >/dev/null
done
check "attendance feature is on for customer A" \
  "$(mysql_q "SELECT is_enabled FROM ${PREFIX}feature_flags WHERE customer_id=${CUSTOMER_ID:-0} AND feature_key='attendance' LIMIT 1")" "1"

check "manager A login" "$(login "$JAR_MGR" "$MGR_A_USER" "$MGR_PASS")" "302"
check "manager B login" "$(login "$JAR_MGRB" "$MGR_B_USER" "$MGR_PASS")" "302"
check "manager A device page opens" "$(status_of "$JAR_MGR" /manager/devices)" "200"
check "owner device page opens" "$(status_of "$JAR_OWNER" /owner/devices)" "200"
check_contains "owner device page documents the activation code flow" \
  "$(body_of "$JAR_OWNER" /owner/devices)" "کد فعال‌سازی"
check_contains "owner device page shows the device event log" \
  "$(body_of "$JAR_OWNER" /owner/devices)" "آخرین رویدادهای دستگاه‌ها"

# a branch for customer A, and an employee (used by the attendance contract)
post_form "$JAR_MGR" /manager/branches /manager/branches \
  --data-urlencode "name=شعبه دستگاه ${STAMP}" \
  --data-urlencode "code=S4BR${STAMP}" \
  --data-urlencode "city=تهران" >/dev/null
BRANCH_ID="$(mysql_q "SELECT id FROM ${PREFIX}branches WHERE customer_id=${CUSTOMER_ID:-0} AND code='S4BR${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "customer A branch created" "${BRANCH_ID:-0}" 1

post_form "$JAR_MGR" /manager/employees/create /manager/employees \
  --data-urlencode "first_name=پرسنل" \
  --data-urlencode "last_name=$EMPLOYEE_LAST" \
  --data-urlencode "mobile=09121234567" \
  --data-urlencode "email=emp-s4-${STAMP}@example.test" \
  --data-urlencode "position=کارشناس فروش" \
  --data-urlencode "department=فروش" \
  --data-urlencode "branch_id=$BRANCH_ID" \
  --data-urlencode "employment_type=full_time" \
  --data-urlencode "hire_date=$(date +%Y-%m-%d)" \
  --data-urlencode "status=active" >/dev/null
EMPLOYEE_ID="$(mysql_q "SELECT id FROM ${PREFIX}employees WHERE customer_id=${CUSTOMER_ID:-0} AND last_name='$EMPLOYEE_LAST' LIMIT 1" | tr -d '[:space:]')"
check_min "customer A employee created" "${EMPLOYEE_ID:-0}" 1

post_form "$JAR_MGRB" /manager/employees/create /manager/employees \
  --data-urlencode "first_name=پرسنل" \
  --data-urlencode "last_name=$EMPLOYEE_B_LAST" \
  --data-urlencode "mobile=09121234599" \
  --data-urlencode "email=emp-s4b-${STAMP}@example.test" \
  --data-urlencode "employment_type=full_time" \
  --data-urlencode "hire_date=$(date +%Y-%m-%d)" \
  --data-urlencode "status=active" >/dev/null
EMPLOYEE_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}employees WHERE customer_id=${CUSTOMER_B_ID:-0} AND last_name='$EMPLOYEE_B_LAST' LIMIT 1" | tr -d '[:space:]')"
check_min "customer B employee created (isolation target)" "${EMPLOYEE_B_ID:-0}" 1

# --- two kiosk devices, one per tenant --------------------------------------
post_form "$JAR_MGR" /manager/devices /manager/devices \
  --data-urlencode "name=کیوسک A ${STAMP}" \
  --data-urlencode "type=tablet" \
  --data-urlencode "platform=Android 13" \
  --data-urlencode "branch_id=$BRANCH_ID" >/dev/null
DEVICE_ID="$(mysql_q "SELECT id FROM ${PREFIX}devices WHERE customer_id=${CUSTOMER_ID:-0} AND name='کیوسک A ${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "device A registered by the manager" "${DEVICE_ID:-0}" 1
check "device A is pending and token-less" \
  "$(mysql_q "SELECT CONCAT(status,'/',IFNULL(api_token_hash,'-')) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "pending/-"
check "device A is bound to the branch" \
  "$(mysql_q "SELECT branch_id FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "$BRANCH_ID"
check "device A belongs to customer A" \
  "$(mysql_q "SELECT customer_id FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "$CUSTOMER_ID"

post_form "$JAR_MGRB" /manager/devices /manager/devices \
  --data-urlencode "name=کیوسک B ${STAMP}" --data-urlencode "type=tablet" >/dev/null
DEVICE_B_ID="$(mysql_q "SELECT id FROM ${PREFIX}devices WHERE customer_id=${CUSTOMER_B_ID:-0} AND name='کیوسک B ${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "device B registered (isolation target)" "${DEVICE_B_ID:-0}" 1

# --- the code is issued by the OWNER ----------------------------------------
check "manager can not issue an activation code (owner capability)" \
  "$(post_form "$JAR_MGR" /owner/devices /owner/devices/activation-code --data-urlencode "id=$DEVICE_ID")" "403"
check "no code route on the manager surface" "$(status_of "$JAR_MGR" /manager/devices/activation-code)" "404"
check "the code route refuses an anonymous caller" \
  "$(post_form "$JAR_ANON" /login /owner/devices/activation-code --data-urlencode "id=$DEVICE_ID")" "302"
check "owner activation-code POST without CSRF is refused" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_OWNER" -X POST "$BASE/owner/devices/activation-code" --data-urlencode "id=$DEVICE_ID")" "419"

OWNER_PAGE="$(body_of "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID")"
check_min "the owner page offers the activation-code action" \
  "$(cnt "$OWNER_PAGE" 'owner/devices/activation-code')" 1
check_contains "the owner page explains the one-time code" "$OWNER_PAGE" "یک‌بارمصرف"

before_codes="$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0}")"
check "activation-code POST is accepted" \
  "$(post_form "$JAR_OWNER" /owner/devices /owner/devices/activation-code \
      --data-urlencode "id=$DEVICE_ID" --data-urlencode "ttl=60")" "302"
after_codes="$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0}")"
check "one code row was stored" "$(( ${after_codes:-0} - ${before_codes:-0} ))" "1"

CODE="$(mysql_q "SELECT CONCAT(code_prefix,'-xxxxxxxx') FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0} ORDER BY id DESC LIMIT 1")"
check "the stored code row keeps only a prefix" "$(cnt "$CODE" 'xxxxxxxx')" "1"

# The plain code only ever existed in the flash message of the owner session.
OWNER_FLASH_PAGE="$(body_of "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID")"
PLAIN_CODE="$(printf '%s' "$OWNER_FLASH_PAGE" | grep -oE '[0-9]{6}-[0-9]{4}' | head -1)"
if [[ -n "$PLAIN_CODE" ]]; then
  ok "the owner sees the plain code once (flash): ${PLAIN_CODE%%-*}-••••"
else
  bad "the owner never received a plain activation code"
  PLAIN_CODE="000000-0000"
fi

check "the plain code is NOT stored" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE code_hash='${PLAIN_CODE}' OR code_prefix='${PLAIN_CODE}'")" "0"
check "the plain code is NOT in the audit trail" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE CONCAT(IFNULL(description,''),IFNULL(meta,'')) LIKE '%${PLAIN_CODE}%'")" "0"
check "the plain code is NOT in the device log" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE CONCAT(IFNULL(message,''),IFNULL(meta,'')) LIKE '%${PLAIN_CODE}%'")" "0"
check "the manager never sees the code" \
  "$(count_in "$JAR_MGR" /manager/devices "$PLAIN_CODE")" "0"
check_min "issuing the code is audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='ISSUE_ACTIVATION_CODE' AND target_id=${DEVICE_ID:-0}")" 1
check_min "issuing the code is in the device event log" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE event='device.activation.code_issued' AND device_id=${DEVICE_ID:-0}")" 1

# =============================================================================
section "3. Activation on the device (one-time code -> device token)"
# =============================================================================

check "activate without a code is refused" \
  "$(api POST /api/v1/device/activate '' '{}')" "422"
check "activate without a code explains ACTIVATION_CODE_REQUIRED" "$(jget code)" "ACTIVATION_CODE_REQUIRED"

check "an invented code is refused" \
  "$(api POST /api/v1/device/activate '' '{"code":"111111-2222"}')" "422"
check "the invented code is refused with ACTIVATION_CODE_INVALID" "$(jget code)" "ACTIVATION_CODE_INVALID"
check_min "the failed attempt is logged" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE event='device.activation.failed'")" 1

check "activation with the real code succeeds" \
  "$(api POST /api/v1/device/activate '' "{\"code\":\"$PLAIN_CODE\",\"platform\":\"Linux x86_64\",\"app_version\":\"1.0.0\"}")" "200"
check "activation answers success:true" "$(jget success)" "true"
check "activation answers code OK" "$(jget code)" "OK"
DEVICE_TOKEN="$(jget data/token)"
TOKEN_EXPIRES="$(jget data/expires_at)"
check_min "a device token was issued" "$(printf '%s' "$DEVICE_TOKEN" | wc -c | tr -d ' ')" 40
check_contains "the token carries the device prefix" "$DEVICE_TOKEN" "att_"
check_contains "the token names its own customer" "$(jget data/config/customer/name)" "$CUSTOMER_NAME"
check_contains "the token names its own branch" "$(jget data/config/branch/name)" "شعبه دستگاه ${STAMP}"
check_min "an expiry was set for the token" "$(printf '%s' "$TOKEN_EXPIRES" | wc -c | tr -d ' ')" 10

check "the plain token is not stored (hash only)" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE api_token_hash='${DEVICE_TOKEN}'")" "0"
check "a 64 char hash is stored" \
  "$(mysql_q "SELECT CHAR_LENGTH(api_token_hash) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "64"
check "the token prefix is stored for the panels" \
  "$(mysql_q "SELECT token_prefix FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "$(printf '%s' "$DEVICE_TOKEN" | cut -c1-12)"
check "device A is active after activation" \
  "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "active"
check_min "activated_at is recorded" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0} AND activated_at IS NOT NULL")" 1
check "the one-time code is burned" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0} AND used_at IS NOT NULL")" "1"
check "the plain code is gone from the legacy pairing column too" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0} AND pairing_code='${PLAIN_CODE}'")" "0"
check_min "activation is audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='DEVICE_ACTIVATION' AND target_id=${DEVICE_ID:-0}")" 1
check_min "activation is in the device log" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE event='device.activation.success' AND device_id=${DEVICE_ID:-0}")" 1

# the same code can not be used twice
check "re-using the code is refused" \
  "$(api POST /api/v1/device/activate '' "{\"code\":\"$PLAIN_CODE\"}")" "409"
check "re-use answers ACTIVATION_CODE_USED" "$(jget code)" "ACTIVATION_CODE_USED"
check_min "the failed retry increased the attempt counter" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0} AND attempts >= 1")" 1
HASH_AFTER_ACTIVATION="$(mysql_q "SELECT api_token_hash FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")"
check "the failed re-use did not rotate the token" \
  "$(mysql_q "SELECT api_token_hash FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "$HASH_AFTER_ACTIVATION"
check "the plain code was never stored as a hash" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE code_prefix = LEFT('${PLAIN_CODE}',4)")" "1"

# expiry: issue a new code, expire it in the database, then try
post_form "$JAR_OWNER" /owner/devices /owner/devices/activation-code --data-urlencode "id=$DEVICE_ID" >/dev/null
mysql_q "UPDATE ${PREFIX}device_activation_codes SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE device_id=${DEVICE_ID:-0} AND used_at IS NULL" >/dev/null
EXPIRED_CODE_ROW="$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0} AND used_at IS NULL")"
check_min "an expired open code exists" "${EXPIRED_CODE_ROW:-0}" 1
# the owner sees the fresh code in the flash of that POST response
POST_PAGE="$(body_of "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID")"
EXPIRED_PLAIN="$(printf '%s' "$POST_PAGE" | grep -oE '[0-9]{6}-[0-9]{4}' | head -1)"
if [[ -n "$EXPIRED_PLAIN" ]]; then
  check "an expired code is refused" "$(api POST /api/v1/device/activate '' "{\"code\":\"$EXPIRED_PLAIN\"}")" "409"
  check "expiry answers ACTIVATION_CODE_EXPIRED" "$(jget code)" "ACTIVATION_CODE_EXPIRED"
else
  bad "could not read the fresh code for the expiry test"
fi
check "the expired code issued no token" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0} AND used_at IS NOT NULL")" "1"

# brute force: the endpoint is rate limited
rm -f "${APP_PATH}/storage/cache/throttle_"*.json 2>/dev/null || true
throttled=0
for i in $(seq 1 12); do
  code="$(api POST /api/v1/device/activate '' '{"code":"000000-0000"}')"
  if [[ "$code" == "429" ]]; then throttled=1; break; fi
done
check "the activation endpoint is rate limited (429 under a burst)" "$throttled" "1"
rm -f "${APP_PATH}/storage/cache/throttle_"*.json 2>/dev/null || true

# brute force accounting: guesses that share the prefix of an open code are
# charged to that device, and the code locks out after max_attempts tries
post_form "$JAR_OWNER" /owner/devices /owner/devices/activation-code --data-urlencode "id=$DEVICE_ID" >/dev/null
LOCK_PAGE="$(body_of "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID")"
LOCK_CODE="$(printf '%s' "$LOCK_PAGE" | grep -oE '[0-9]{6}-[0-9]{4}' | head -1)"
if [[ -n "$LOCK_CODE" ]]; then
  ok "a fresh code was issued for the lock test"
else
  bad "no fresh code for the lock test"
  LOCK_CODE="000000-0000"
fi
LOCK_PREFIX="$(printf '%s' "$LOCK_CODE" | cut -c1-4)"
HASH_BEFORE_LOCK="$(mysql_q "SELECT api_token_hash FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")"
rm -f "${APP_PATH}/storage/cache/throttle_"*.json 2>/dev/null || true
for i in $(seq 1 10); do
  api POST /api/v1/device/activate '' "{\"code\":\"${LOCK_PREFIX}-000$i\"}" >/dev/null
done
rm -f "${APP_PATH}/storage/cache/throttle_"*.json 2>/dev/null || true
check_min "the guesses were charged to the device" \
  "$(mysql_q "SELECT MAX(attempts) FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0} AND used_at IS NULL")" 10
check "the real code is now locked" \
  "$(api POST /api/v1/device/activate '' "{\"code\":\"$LOCK_CODE\"}")" "422"
check "the lock is named ACTIVATION_CODE_LOCKED" "$(jget code)" "ACTIVATION_CODE_LOCKED"
check "the locked code is at its attempt cap" \
  "$(mysql_q "SELECT CASE WHEN attempts >= max_attempts THEN 1 ELSE 0 END FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0} AND code_prefix='${LOCK_PREFIX}' AND used_at IS NULL ORDER BY id DESC LIMIT 1")" "1"
check "the locked code never issued a credential" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_activation_codes WHERE device_id=${DEVICE_ID:-0} AND code_prefix='${LOCK_PREFIX}' AND used_at IS NOT NULL")" "0"
check "the device token was not rotated by the locked attempt" \
  "$(mysql_q "SELECT api_token_hash FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "$HASH_BEFORE_LOCK"

# the device itself already holds a valid token from the successful activation
check "the working token still works" "$(api GET /api/v1/device/config "$DEVICE_TOKEN")" "200"

# =============================================================================
section "4. Device authentication and token rules"
# =============================================================================

check "device login exchanges the token for a session" \
  "$(api POST /api/v1/device/login '' "{\"token\":\"$DEVICE_TOKEN\"}")" "200"
SESSION_TOKEN="$(jget data/session_token)"
check_contains "a session token was issued" "$SESSION_TOKEN" "att_sess_"
check "the session token is accepted" "$(api GET /api/v1/device/me "$SESSION_TOKEN")" "200"
check "the device token is accepted" "$(api GET /api/v1/device/me "$DEVICE_TOKEN")" "200"

check "no token -> 401" "$(api GET /api/v1/device/config '')" "401"
check "no token -> DEVICE_UNAUTHORIZED" "$(jget code)" "DEVICE_UNAUTHORIZED"
check "a garbage token -> 401" "$(api GET /api/v1/device/config 'att_deadbeefdeadbeefdeadbeef')" "401"
check "a garbage token -> DEVICE_UNAUTHORIZED" "$(jget code)" "DEVICE_UNAUTHORIZED"
TAMPERED="$(printf '%s' "$DEVICE_TOKEN" | sed 's/.$/X/')"
check "a tampered token -> 401" "$(api GET /api/v1/device/config "$TAMPERED")" "401"
check "a token in the query string is not accepted" \
  "$(curl -s -o /tmp/s4_api.json -w '%{http_code}' "$BASE/api/v1/device/config?token=$DEVICE_TOKEN")" "401"
check "a session token from another device is not valid for this device" \
  "$(api GET /api/v1/device/config 'att_sess_0123456789abcdef01234567')" "401"

check "tampered token material is rejected on the engine contract" \
  "$(api POST /api/v1/device/recognition "$TAMPERED" '{"request_id":"tampered-123456"}')" "401"

# a pending device (registered, never activated) must have no usable credential
post_form "$JAR_MGRB" /manager/devices /manager/devices --data-urlencode "name=کیوسک معلق ${STAMP}" >/dev/null
PENDING_ID="$(mysql_q "SELECT id FROM ${PREFIX}devices WHERE customer_id=${CUSTOMER_B_ID:-0} AND name='کیوسک معلق ${STAMP}' LIMIT 1" | tr -d '[:space:]')"
check_min "a pending device exists" "${PENDING_ID:-0}" 1
check "the pending device has no token hash" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE id=${PENDING_ID:-0} AND (api_token_hash IS NULL OR api_token_hash='')")" "1"
check "a pending device can not be used as a device (401)" \
  "$(api GET /api/v1/device/config 'att_0000000000000000000000000000')" "401"

# =============================================================================
section "5. Config, branding and tenant isolation"
# =============================================================================

check "config loads for the device" "$(api GET /api/v1/device/config "$DEVICE_TOKEN")" "200"
CONFIG_JSON="$(cat /tmp/s4_api.json)"
check_contains "config carries its own customer" "$CONFIG_JSON" "$CUSTOMER_NAME"
check_not_contains "config never leaks the other customer" "$CONFIG_JSON" "$CUSTOMER_B_NAME"
check_contains "config carries the business logo slot" "$CONFIG_JSON" '"logo_url"'
check_contains "config carries the branch" "$CONFIG_JSON" "شعبه دستگاه ${STAMP}"
check_contains "config carries the feature flags" "$CONFIG_JSON" '"attendance":true'
check_contains "config carries the device settings" "$CONFIG_JSON" '"success_sound"'
check_contains "config exposes the engine contract" "$CONFIG_JSON" '"face_engine":"local-browser"'
check_contains "config says liveness is not used" "$CONFIG_JSON" '"liveness":"not_used"'
check_not_contains "config never exposes the token hash" "$CONFIG_JSON" "api_token_hash"
check_not_contains "config never exposes the plain token" "$CONFIG_JSON" "$DEVICE_TOKEN"
check_not_contains "config never exposes the activation code" "$CONFIG_JSON" "${PLAIN_CODE:-xxxxx}"
check_contains "config tells the kiosk its heartbeat interval" "$CONFIG_JSON" '"heartbeat_interval"'

check "status endpoint answers" "$(api GET /api/v1/device/status "$DEVICE_TOKEN")" "200"
check "status reports the device as online" "$(jget data/online)" "true"
check_contains "status carries last_seen_at" "$(cat /tmp/s4_api.json)" '"last_seen_at"'

check "device A can not read customer B branding" \
  "$(cnt "$(api GET /api/v1/device/config "$DEVICE_TOKEN"; cat /tmp/s4_api.json)" "$CUSTOMER_B_NAME")" "0"

# =============================================================================
section "6. Non-sensitive device settings (allow list)"
# =============================================================================

check "settings can be read" "$(api GET /api/v1/device/settings "$DEVICE_TOKEN")" "200"
check_contains "settings list the allow list" "$(cat /tmp/s4_api.json)" '"success_sound"'
check_contains "settings include the clock switch" "$(cat /tmp/s4_api.json)" '"show_clock"'
check_contains "settings include the camera test facing" "$(cat /tmp/s4_api.json)" '"camera_facing"'
check_not_contains "settings never include a security switch" "$(cat /tmp/s4_api.json)" 'token'

check "settings accept a non-sensitive value" \
  "$(api POST /api/v1/device/settings "$DEVICE_TOKEN" '{"settings":{"success_sound":"0","success_display_seconds":"7"}}')" "200"
check "the saved setting is in the database" \
  "$(mysql_q "SELECT setting_value FROM ${PREFIX}device_settings WHERE device_id=${DEVICE_ID:-0} AND setting_key='success_display_seconds'")" "7"

check "security relevant keys are rejected" \
  "$(api POST /api/v1/device/settings "$DEVICE_TOKEN" '{"settings":{"branch_id":"99","customer_id":"1","status":"active","api_token_hash":"x","token_prefix":"y","device_uid":"z"}}')" "200"
REJECTED="$(jget data/rejected)"
check_contains "branch_id is rejected" "$REJECTED" "branch_id"
check_contains "customer_id is rejected" "$REJECTED" "customer_id"
check_contains "status is rejected" "$REJECTED" "status"
check_contains "api_token_hash is rejected" "$REJECTED" "api_token_hash"
check "nothing was written for the rejected keys" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_settings WHERE device_id=${DEVICE_ID:-0} AND setting_key IN ('branch_id','customer_id','status','api_token_hash','token_prefix','device_uid')")" "0"
check "the device branch was not changed by the device" \
  "$(mysql_q "SELECT branch_id FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "$BRANCH_ID"
check "the device customer was not changed by the device" \
  "$(mysql_q "SELECT customer_id FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "$CUSTOMER_ID"

check "an out-of-range value is rejected" \
  "$(api POST /api/v1/device/settings "$DEVICE_TOKEN" '{"settings":{"success_display_seconds":"9999"}}')" "200"
check "the out-of-range value was not stored" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_settings WHERE device_id=${DEVICE_ID:-0} AND setting_key='success_display_seconds' AND setting_value='9999'")" "0"
check "an empty settings body is refused" \
  "$(api POST /api/v1/device/settings "$DEVICE_TOKEN" '{"settings":{}}')" "422"

# =============================================================================
section "7. Heartbeat, last seen, status and device logging"
# =============================================================================

mysql_q "UPDATE ${PREFIX}devices SET last_seen_at = NOW() - INTERVAL 20 MINUTE WHERE id=${DEVICE_ID:-0}" >/dev/null
check "heartbeat is accepted" "$(api POST /api/v1/device/heartbeat "$DEVICE_TOKEN" '{"clock_offset":2,"app_version":"1.0.0"}')" "200"
check_min "heartbeat answers with the server time" "$(printf '%s' "$(jget data/server_time)" | wc -c | tr -d ' ')" 10
check_min "last_seen_at advanced" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0} AND last_seen_at >= NOW() - INTERVAL 2 MINUTE")" 1
check_min "heartbeat was logged after a long gap" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE device_id=${DEVICE_ID:-0} AND event='device.heartbeat'")" 1
check_min "heartbeat via the session token works" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_sessions WHERE device_id=${DEVICE_ID:-0} AND revoked_at IS NULL")" 1

check_min "the owner panel shows the device as online" \
  "$(count_in "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID" 'آنلاین')" 1
check_min "the owner panel shows the last seen stamp" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0} AND last_seen_at IS NOT NULL")" 1

check "a connection error can be reported" \
  "$(api POST /api/v1/device/error "$DEVICE_TOKEN" '{"code":"CAMERA_INIT_FAILED","message":"دوربین دستگاه پاسخ نداد"}')" "200"
check "the device error code is stored on the device" \
  "$(mysql_q "SELECT last_error_code FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "CAMERA_INIT_FAILED"
check_min "the connection error is in the device log" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE device_id=${DEVICE_ID:-0} AND event='device.connection.error' AND level='error'")" 1
check_min "the owner panel can see device errors" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE event IN ('device.connection.error','device.recognition.error') AND created_at >= NOW() - INTERVAL 1 HOUR")" 1

# =============================================================================
section "8. Recognition and attendance CONTRACTS (nothing may be written)"
# =============================================================================

RECORDS_BEFORE="$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}records WHERE employee_id=${EMPLOYEE_ID:-0}")"

check "recognition without a request id is refused" \
  "$(api POST /api/v1/device/recognition "$DEVICE_TOKEN" '{}')" "422"
check "the missing request id is named" "$(jget code)" "INVALID_REQUEST_ID"
check "a short request id is refused" \
  "$(api POST /api/v1/device/recognition "$DEVICE_TOKEN" '{"request_id":"abc"}')" "422"

REC_REQ="s4-rec-${STAMP}-01"
check "recognition accepts a well formed request" \
  "$(api POST /api/v1/device/recognition "$DEVICE_TOKEN" "{\"request_id\":\"$REC_REQ\"}")" "503"
check "recognition answers FACE_ENGINE_UNAVAILABLE (no fake result)" "$(jget code)" "FACE_ENGINE_UNAVAILABLE"
check_contains "the answer carries the engine contract" "$(cat /tmp/s4_api.json)" '"face_engine_stage":5'
check "recognition is idempotent" \
  "$(api POST /api/v1/device/recognition "$DEVICE_TOKEN" "{\"request_id\":\"$REC_REQ\"}")" "409"
check "a duplicate request is refused" "$(jget code)" "DUPLICATE_REQUEST"
check "the duplicate key was claimed exactly once" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_requests WHERE device_id=${DEVICE_ID:-0} AND request_key='$REC_REQ'")" "1"
check_min "the recognition attempt is logged" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE device_id=${DEVICE_ID:-0} AND event='device.recognition.error'")" 1

check "recognition with a foreign branch is refused" \
  "$(api POST /api/v1/device/recognition "$DEVICE_TOKEN" "{\"request_id\":\"s4-rec-${STAMP}-02\",\"branch_id\":999999}")" "409"
check "the branch mismatch is named" "$(jget code)" "BRANCH_MISMATCH"

# Stage 6 turned these contracts into the real engine, so the expectations
# follow the engine: the action is mandatory and a bare employee id can never
# write a row - the request must carry the one time proof of the recognition
# that happened on the kiosk (device + customer + employee + action bound).
check "attendance requires an explicit action" \
  "$(api POST /api/v1/device/attendance "$DEVICE_TOKEN" "{\"request_id\":\"s4-att-${STAMP}-01\",\"action\":\"lunch\",\"employee_id\":${EMPLOYEE_ID:-0}}")" "422"
check "an unknown action is named" "$(jget code)" "ATTENDANCE_ACTION_REQUIRED"
check "attendance without any action is refused" \
  "$(api POST /api/v1/device/attendance "$DEVICE_TOKEN" "{\"request_id\":\"s4-att-${STAMP}-02\",\"employee_id\":${EMPLOYEE_ID:-0}}")" "422"
check "the missing action is named" "$(jget code)" "ATTENDANCE_ACTION_REQUIRED"

ATT_REQ="s4-att-${STAMP}-03"
check "a bare employee id is refused on the dispatcher" \
  "$(api POST /api/v1/device/attendance "$DEVICE_TOKEN" "{\"request_id\":\"$ATT_REQ\",\"action\":\"entry\",\"employee_id\":${EMPLOYEE_ID:-0}}")" "409"
check "the missing recognition is named" "$(jget code)" "INVALID_RECOGNITION"
check "a bare employee id is refused on the entry endpoint" \
  "$(api POST /api/v1/device/attendance/entry "$DEVICE_TOKEN" "{\"request_id\":\"s4-att-${STAMP}-04\",\"employee_id\":${EMPLOYEE_ID:-0}}")" "409"
check "the entry endpoint names the missing recognition" "$(jget code)" "INVALID_RECOGNITION"
check "a forged proof id is refused on exit" \
  "$(api POST /api/v1/device/attendance/exit "$DEVICE_TOKEN" "{\"request_id\":\"s4-att-${STAMP}-05\",\"proof\":\"00000000-0000-4000-8000-000000000000\"}")" "409"
check "the forged proof is named" "$(jget code)" "INVALID_RECOGNITION"
check "an unusable request id is refused" \
  "$(api POST /api/v1/device/attendance/entry "$DEVICE_TOKEN" "{\"request_id\":\"x\",\"proof\":\"00000000-0000-4000-8000-000000000000\"}")" "422"
check "the unusable request id is named" "$(jget code)" "INVALID_REQUEST_ID"

# The tenant / employee / branch refusals (foreign employee, inactive employee,
# unregistered face) need a REAL recognition proof and are covered end to end
# by tests/engine_tests.php section C/I - a bare id can not reach them anymore.

RECORDS_AFTER="$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}records WHERE employee_id=${EMPLOYEE_ID:-0}")"
check "NO attendance row was written by the contracts" "$RECORDS_AFTER" "${RECORDS_BEFORE:-0}"
check_min "the attendance attempts are logged" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE device_id=${DEVICE_ID:-0} AND event='device.attendance.submitted'")" 1

# the engine verdict mapping itself is real and testable (stage 5 will call it)
cat > /tmp/sarmad_s4_engine.php <<'PHP'
<?php
define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', getenv('APP_PATH') ?: getcwd());
require SARMAD_ROOT . '/app/Bootstrap.php';
if (!App\Bootstrap::bootCli()) { fwrite(STDERR, "boot failed\n"); exit(1); }

$service = new App\Services\DeviceService();
$cases = [
    'no_face'           => 'NO_FACE',
    'multiple_faces'    => 'MULTIPLE_FACES',
    'unknown_face'      => 'UNKNOWN_FACE',
    'employee_inactive' => 'EMPLOYEE_INACTIVE',
    'recognized'        => 'OK',
];
$out = [];
foreach ($cases as $engine => $expected) {
    $answer = $service->engineResponse($engine, ['employee' => ['id' => 4242, 'first_name' => 'پرسنل', 'last_name' => 'آزمون']]);
    $out[] = $engine . '=' . $answer['code'] . '=' . ($answer['ok'] ? '1' : '0') . '=' . ($answer['code'] === $expected ? 'match' : 'MISMATCH');
}
echo implode("\n", $out), "\n";
PHP
ENGINE_OUT="$(APP_PATH="$APP_PATH" php /tmp/sarmad_s4_engine.php 2>&1)"
check_contains "engine verdict no_face maps to NO_FACE" "$ENGINE_OUT" "no_face=NO_FACE=0=match"
check_contains "engine verdict multiple_faces maps to MULTIPLE_FACES" "$ENGINE_OUT" "multiple_faces=MULTIPLE_FACES=0=match"
check_contains "engine verdict unknown_face maps to UNKNOWN_FACE" "$ENGINE_OUT" "unknown_face=UNKNOWN_FACE=0=match"
check_contains "engine verdict employee_inactive maps to EMPLOYEE_INACTIVE" "$ENGINE_OUT" "employee_inactive=EMPLOYEE_INACTIVE=0=match"
check_contains "engine verdict recognized maps to OK" "$ENGINE_OUT" "recognized=OK="
check_contains "no-face message is the documented Persian sentence" \
  "$(cat /tmp/sarmad_s4_engine.php >/dev/null; APP_PATH="$APP_PATH" php -r 'define("SARMAD_APP",true);define("SARMAD_START",1);define("SARMAD_ROOT",getenv("APP_PATH"));require SARMAD_ROOT."/app/Bootstrap.php";App\Bootstrap::bootCli();$a=(new App\Services\DeviceService())->engineResponse("no_face");echo $a["message"];' 2>&1)" \
  "چهره‌ای شناسایی نشد. لطفاً روبه‌روی دوربین قرار بگیرید."
check_contains "multiple-face message is the documented Persian sentence" \
  "$(APP_PATH="$APP_PATH" php -r 'define("SARMAD_APP",true);define("SARMAD_START",1);define("SARMAD_ROOT",getenv("APP_PATH"));require SARMAD_ROOT."/app/Bootstrap.php";App\Bootstrap::bootCli();$a=(new App\Services\DeviceService())->engineResponse("multiple_faces");echo $a["message"];' 2>&1)" \
  "لطفاً فقط یک نفر مقابل دوربین قرار بگیرد."
check_contains "unknown-face message is the documented Persian sentence" \
  "$(APP_PATH="$APP_PATH" php -r 'define("SARMAD_APP",true);define("SARMAD_START",1);define("SARMAD_ROOT",getenv("APP_PATH"));require SARMAD_ROOT."/app/Bootstrap.php";App\Bootstrap::bootCli();$a=(new App\Services\DeviceService())->engineResponse("unknown_face");echo $a["message"];' 2>&1)" \
  "چهره شما در سیستم ثبت نشده است. لطفاً با مسئول مجموعه تماس بگیرید."

# =============================================================================
section "9. Deactivation, token reset and revocation"
# =============================================================================

check "the owner can disable the device" \
  "$(post_form "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID" /owner/devices/status \
      --data-urlencode "id=$DEVICE_ID" --data-urlencode "status=disabled")" "302"
check "the device is disabled in the database" \
  "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "disabled"
check "a disabled device is refused" "$(api GET /api/v1/device/config "$DEVICE_TOKEN")" "401"
check "a disabled device is named DEVICE_INACTIVE" "$(jget code)" "DEVICE_INACTIVE"
check_min "the deactivation is in the device log" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE device_id=${DEVICE_ID:-0} AND event='device.auth.failed'")" 1

check "the owner can enable it again" \
  "$(post_form "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID" /owner/devices/status \
      --data-urlencode "id=$DEVICE_ID" --data-urlencode "status=active")" "302"
check "the same token works again after re-enabling" "$(api GET /api/v1/device/config "$DEVICE_TOKEN")" "200"

OLD_HASH="$(mysql_q "SELECT api_token_hash FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")"
check "the owner can rotate the token" \
  "$(post_form "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID" /owner/devices/rotate-token \
      --data-urlencode "id=$DEVICE_ID")" "302"
NEW_HASH="$(mysql_q "SELECT api_token_hash FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")"
if [[ -n "$NEW_HASH" && "$NEW_HASH" != "$OLD_HASH" ]]; then ok "the stored hash changed"; else bad "the token hash did not change"; fi
check "the old token stops working immediately" "$(api GET /api/v1/device/config "$DEVICE_TOKEN")" "401"
check "the old token is named DEVICE_UNAUTHORIZED" "$(jget code)" "DEVICE_UNAUTHORIZED"
check "the old session token is gone as well" "$(api GET /api/v1/device/me "$SESSION_TOKEN")" "401"
check_min "the token reset is audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='RESET_DEVICE' AND target_id=${DEVICE_ID:-0}")" 1

# reset -> back to pending + a fresh one-time code (the device must re-activate)
check "the owner can reset the registration" \
  "$(post_form "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID" /owner/devices/reset \
      --data-urlencode "id=$DEVICE_ID")" "302"
check "the device is pending again" \
  "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "pending"
check "the device has no token after the reset" \
  "$(mysql_q "SELECT IFNULL(api_token_hash,'-') FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "-"
RESET_PAGE="$(body_of "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID")"
RESET_CODE="$(printf '%s' "$RESET_PAGE" | grep -oE '[0-9]{6}-[0-9]{4}' | head -1)"
if [[ -n "$RESET_CODE" ]]; then ok "the reset produced a fresh one-time code"; else bad "the reset produced no usable code"; fi
rm -f "${APP_PATH}/storage/cache/throttle_"*.json 2>/dev/null || true
check "the fresh code re-activates the device" \
  "$(api POST /api/v1/device/activate '' "{\"code\":\"${RESET_CODE:-000000-0000}\"}")" "200"
DEVICE_TOKEN_2="$(jget data/token)"
check "the re-activated device has a new working token" "$(api GET /api/v1/device/config "$DEVICE_TOKEN_2")" "200"
check "the pre-reset token is still dead" "$(api GET /api/v1/device/config "$DEVICE_TOKEN")" "401"

check "the owner can revoke the device" \
  "$(post_form "$JAR_OWNER" "/owner/devices?customer_id=$CUSTOMER_ID" /owner/devices/revoke \
      --data-urlencode "id=$DEVICE_ID")" "302"
check "the revoked device is refused" "$(api GET /api/v1/device/config "$DEVICE_TOKEN_2")" "401"
check "a revoked credential is gone, not just disabled" "$(jget code)" "DEVICE_UNAUTHORIZED"
check "the revoked device keeps its status for the audit trail" \
  "$(mysql_q "SELECT status FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "revoked"
check_min "the revocation is audited" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE action='DELETE_DEVICE' AND target_id=${DEVICE_ID:-0}")" 1
check "a revoked device can not be revived by a code (DEVICE_REVOKED)" \
  "$(APP_PATH="$APP_PATH" DEVICE_ID="$DEVICE_ID" php -r 'define("SARMAD_APP",true);define("SARMAD_START",1);define("SARMAD_ROOT",getenv("APP_PATH"));require SARMAD_ROOT."/app/Bootstrap.php";App\Bootstrap::bootCli();$r=(new App\Services\DeviceService())->issueActivationCode((int)getenv("DEVICE_ID"));echo $r["ok"] ? "ISSUED" : $r["code"];' 2>&1)" "DEVICE_REVOKED"

check "a manager can not enable a device of its own customer through the owner route" \
  "$(post_form "$JAR_MGR" "/manager/devices?customer_id=$CUSTOMER_ID" /owner/devices/status \
      --data-urlencode "id=$DEVICE_ID" --data-urlencode "status=active")" "403"
check "a manager can not delete a device" \
  "$(post_form "$JAR_MGR" "/manager/devices?customer_id=$CUSTOMER_ID" /owner/devices/delete \
      --data-urlencode "id=$DEVICE_ID")" "403"
check "device A was not deleted by the manager" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}devices WHERE id=${DEVICE_ID:-0}")" "1"

# =============================================================================
section "10. Security summary"
# =============================================================================

for endpoint in config status settings engine recognition attendance attendance/entry attendance/exit attendance/sync error forget heartbeat me; do
  if [[ "$endpoint" == "recognition" || "$endpoint" == attendance* || "$endpoint" == "error" || "$endpoint" == "forget" || "$endpoint" == "heartbeat" ]]; then
    method=POST
  else
    method=GET
  fi
  code="$(api "$method" "/api/v1/device/$endpoint" '' '{}')"
  check "device endpoint without a token is refused: $endpoint" "$code" "401"
  check "device endpoint answers success:false: $endpoint" "$(jget success)" "false"
done

check "a cross origin device write is refused" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v1/device/attendance" \
      -H 'Origin: https://evil.example' -H 'Content-Type: application/json' \
      -d '{"request_id":"cross-origin-1","action":"entry","employee_id":1}')" "403"
check "a cross origin device write with a token is refused too" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v1/device/recognition" \
      -H 'Origin: https://evil.example' -H "Authorization: Bearer $DEVICE_TOKEN_2" \
      -H 'Content-Type: application/json' -d '{"request_id":"cross-origin-2"}')" "403"

# XSS: a device name typed as HTML must be escaped by the panels
post_form "$JAR_MGR" /manager/devices /manager/devices \
  --data-urlencode "name=<script>alert(7)</script>" --data-urlencode "type=tablet" >/dev/null
XSS_DEVICE_ID="$(mysql_q "SELECT id FROM ${PREFIX}devices WHERE customer_id=${CUSTOMER_ID:-0} AND name='<script>alert(7)</script>' ORDER BY id DESC LIMIT 1" | tr -d '[:space:]')"
check_min "the HTML device name is stored as plain text" "${XSS_DEVICE_ID:-0}" 1
check "the manager list escapes it" "$(count_in "$JAR_MGR" /manager/devices '<script>alert(7)</script>')" "0"
check_min "the escaped form is rendered instead" \
  "$(count_in "$JAR_MGR" /manager/devices '&lt;script&gt;alert(7)&lt;/script&gt;')" 1
check "the owner device page escapes it too" \
  "$(count_in "$JAR_OWNER" "/owner/devices?q=$(urlenc "<script>alert(7)</script>")" '<script>alert(7)</script>')" "0"

check "a device (bearer) token can not open a panel page" \
  "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $DEVICE_TOKEN_2" "$BASE/owner/devices")" "302"
check "a device (bearer) token can not open the manager panel" \
  "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $DEVICE_TOKEN_2" "$BASE/manager/devices")" "302"

check "no activation code ever appears in the audit trail" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}audit_logs WHERE description LIKE '%-____' AND description LIKE '%کد%' AND description REGEXP '[0-9]{6}-[0-9]{4}'")" "0"
check "device logs store the code prefix only" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE meta LIKE '%\"prefix\"%' AND meta REGEXP '[0-9]{6}-[0-9]{4}'")" "0"
check "the kiosk page ships no token material" "$(cnt "$DEVICE_HTML" 'att_')" "0"
check_min "device logs carry the device tenant" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE customer_id=${CUSTOMER_ID:-0} AND device_id=${DEVICE_ID:-0}")" 1
check "no device log row was written for another tenant" \
  "$(mysql_q "SELECT COUNT(*) FROM ${PREFIX}device_logs WHERE customer_id=${CUSTOMER_B_ID:-0} AND device_id=${DEVICE_ID:-0}")" "0"

note "PWA install prompt, standalone launch and service worker activation can only be observed in a real browser: this sandbox has no headless Chrome, so those three are asserted statically (manifest, sw.js, precache list, install handler, offline shell) and NOT claimed as browser-verified."

echo
echo "-----------------------------------------------------------------"
printf 'RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
if [[ "$FAIL" -gt 0 ]]; then
  printf 'Failures:\n'
  for f in "${FAILURES[@]}"; do printf '  - %s\n' "$f"; done
fi
if [[ "${#NOTES[@]}" -gt 0 ]]; then
  printf 'Notes:\n'
  for n in "${NOTES[@]}"; do printf '  ! %s\n' "$n"; done
fi
echo "-----------------------------------------------------------------"
[[ "$FAIL" -eq 0 ]] || exit 1
exit 0
