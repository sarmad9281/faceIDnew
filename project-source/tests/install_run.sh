#!/usr/bin/env bash
# SARMAD Attendance - runs the web installer against a freshly extracted copy.
# Usage: tests/install_run.sh [BASE_URL]
set -eu

BASE="${1:-http://127.0.0.1:8080}"

# Database password used by the installer step (never hard code a real
# password here):  SARMAD_TEST_DB_PASS='...' tests/install_run.sh
if [ -z "${SARMAD_TEST_DB_PASS:-}" ]; then
    echo "note: SARMAD_TEST_DB_PASS is not set - the database step will fail unless the account has no password."
fi
DB_PASS="${SARMAD_TEST_DB_PASS:-}"
WORK="$(mktemp -d)"
cd "$WORK"

curl -s -c jar -o step1.html "$BASE/install/"
T1="$(grep -oP 'name="_token" value="\K[^"]+' step1.html | head -1)"
curl -s -b jar -c jar -o step2.html -X POST "$BASE/install/" \
  --data-urlencode "_token=$T1" --data-urlencode "action=test_connection" \
  --data-urlencode "db_host=localhost" --data-urlencode "db_port=3306" \
  --data-urlencode "db_database=sarmadfa_clinic" --data-urlencode "db_username=sarmadfa_youruser_lumiere" \
  --data-urlencode "db_password=$DB_PASS" --data-urlencode "db_prefix=attendance_"

if ! grep -q "حساب مالک سامانه" step2.html; then
    echo "INSTALL: FAILED at the database step"
    sed -e 's/<[^>]*>/ /g' step2.html | tr -s ' ' | head -20
    exit 1
fi

T2="$(grep -oP 'name="_token" value="\K[^"]+' step2.html | head -1)"
curl -s -b jar -c jar -o done.html -X POST "$BASE/install/" \
  --data-urlencode "_token=$T2" --data-urlencode "action=install" \
  --data-urlencode "db_host=localhost" --data-urlencode "db_port=3306" \
  --data-urlencode "db_database=sarmadfa_clinic" --data-urlencode "db_username=sarmadfa_youruser_lumiere" \
  --data-urlencode "db_password=$DB_PASS" --data-urlencode "db_prefix=attendance_" \
  --data-urlencode "owner_name=سرمد فلاح‌زاده" --data-urlencode "owner_username=owner" \
  --data-urlencode "owner_email=owner@sarmadfallahzadeh.ir" \
  --data-urlencode "owner_password=Owner#Pass2026" --data-urlencode "owner_password_confirmation=Owner#Pass2026" \
  --data-urlencode "timezone=Asia/Tehran" --data-urlencode "date_system=jalali" \
  --data-urlencode "app_name=SARMAD Attendance" --data-urlencode "app_url=$BASE" \
  --data-urlencode "customer_name=کلینیک نمونه" --data-urlencode "customer_company=نمونه شرکت" \
  --data-urlencode "manager_name=مدیر نمونه" --data-urlencode "manager_email=manager@example.com" \
  --data-urlencode "manager_password=Manager#Pass2026"

if grep -q "نصب با موفقیت" done.html; then
    echo "INSTALL: SUCCESS"
else
    echo "INSTALL: FAILED"
    sed -e 's/<[^>]*>/ /g' done.html | tr -s ' ' | head -30
    exit 1
fi
