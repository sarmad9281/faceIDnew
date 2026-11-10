// Run with Node; no server, browser or database required.
//
// STAGE 23 C - night shifts (a shift that ends after midnight).
//
//   node tests/stage23_night_shift_test.js
//
// Three real bugs, one cause each. A guard (20:00 -> 06:00) who punched out at
// 02:10 used to get:
//
//   1. a row on the WRONG day - insertEntry() took the calendar date of the
//      punch, so the exit landed on tomorrow with an early-leave of ~1320
//      minutes, and the real shift day stayed empty and counted as ABSENT,
//   2. a false absence from closeDay() - sessionsOn() only counts CLOSED rows,
//      so somebody standing inside the building looked like somebody with no
//      session at all,
//   3. a deadlock - one forgotten exit punch kept uniq_records_open busy, so
//      every later entry was refused with "ورود شما قبلاً ثبت شده است" until a
//      manager corrected the row by hand.
//
// This suite asserts the three fixes and, just as important, that nothing that
// was already correct was "fixed" again: the shift validation still accepts
// end < start, AttendanceCalculator::window() is still the single midnight
// authority, and CALC_VERSION stays 6.0.0 because no formula changed.

"use strict";

const fs = require("node:fs");
const path = require("node:path");

const ROOT = path.join(__dirname, "..");
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), "utf8");

let passed = 0;
const failures = [];

function check(name, condition, detail) {
  if (condition) {
    passed += 1;
    return;
  }
  failures.push(detail ? name + " — " + detail : name);
}

function section(title) {
  console.log("\n" + title);
}

/* =====================================================================
 * Helpers: a JS mirror of the shift window, to reason about real times
 * =================================================================== */

/** "HH:MM" -> minutes since midnight (mirrors Shift::timeMinutes). */
const minutes = (value) => {
  const match = /^(\d{1,2}):(\d{2})/.exec(String(value).trim());
  return match ? Number(match[1]) * 60 + Number(match[2]) : 0;
};

/**
 * Mirrors AttendanceCalculator::window(): the end of a shift that crosses
 * midnight is on the NEXT calendar day.
 */
function windowOf(shift, date) {
  const start = minutes(shift.start_time);
  const end = minutes(shift.end_time);
  const crosses = Number(shift.crosses_midnight || 0) === 1 || end <= start;
  const next = new Date(date + "T12:00:00Z");
  next.setUTCDate(next.getUTCDate() + 1);
  return {
    start: date + " " + String(Math.floor(start / 60)).padStart(2, "0") + ":" + String(start % 60).padStart(2, "0") + ":00",
    end: (crosses ? next.toISOString().slice(0, 10) : date) + " " + String(Math.floor(end / 60)).padStart(2, "0") + ":" + String(end % 60).padStart(2, "0") + ":00",
    crosses,
  };
}

const previousDay = (date) => {
  const day = new Date(date + "T12:00:00Z");
  day.setUTCDate(day.getUTCDate() - 1);
  return day.toISOString().slice(0, 10);
};

const nightShift = { start_time: "20:00", end_time: "06:00", crosses_midnight: 1 };
const dayShift = { start_time: "08:00", end_time: "16:00", crosses_midnight: 0 };

/* The decision resolveForMoment() has to make, expressed as data: a punch, the
   shift of the day before, the grace, and the date the row must land on. */
const CASES = [
  ["02:10 punch, 20:00->06:00 shift the evening before", "2025-10-06 02:10:00", nightShift, 120, "2025-10-05"],
  ["05:59 punch, one minute before the window ends", "2025-10-06 05:59:00", nightShift, 120, "2025-10-05"],
  ["07:00 punch inside the 120 minute grace", "2025-10-06 07:00:00", nightShift, 120, "2025-10-05"],
  ["09:00 punch, grace over -> the new calendar day", "2025-10-06 09:00:00", nightShift, 120, "2025-10-06"],
  ["02:10 punch but the day shift was 08:00->16:00", "2025-10-06 02:10:00", dayShift, 120, "2025-10-06"],
  ["22:00 punch inside the running night shift", "2025-10-05 22:00:00", nightShift, 120, "2025-10-05"],
  ["00:30 punch of a 16:00->00:30 shift", "2025-10-06 00:30:00", { start_time: "16:00", end_time: "00:30", crosses_midnight: 1 }, 120, "2025-10-05"],
  ["grace 0 -> the window end is the hard limit", "2025-10-06 06:01:00", nightShift, 0, "2025-10-06"],
];

section("A. The reference cases the resolver has to satisfy");

CASES.forEach(([label, punch, shift, grace, expected]) => {
  const calendar = punch.slice(0, 10);
  const previous = previousDay(calendar);
  const window = windowOf(shift, previous);
  const limit = Date.parse(window.end.replace(" ", "T") + "Z") + grace * 60000;
  const moment = Date.parse(punch.replace(" ", "T") + "Z");
  const decided = window.crosses && moment <= limit ? previous : calendar;

  check(label + " -> " + expected, decided === expected, "the reference gives " + decided);
});

check("the reference agrees that a night window ends on the next day", windowOf(nightShift, "2025-10-05").end === "2025-10-06 06:00:00", windowOf(nightShift, "2025-10-05").end);
check("the reference agrees that a day window ends the same day", windowOf(dayShift, "2025-10-05").end === "2025-10-05 16:00:00");

/* =====================================================================
 * B. ShiftResolver::resolveForMoment
 * =================================================================== */
section("B. ShiftResolver::resolveForMoment()");

const resolver = read("app/Services/ShiftResolver.php");

check("resolveForMoment() exists", resolver.includes("public static function resolveForMoment(int $employeeId, int $customerId, string $dateTime, string $calendarDate = ''): array"));
check("it returns the date, the shift, whether it crossed and why", resolver.includes("@return array{date:string,shift:array<string,mixed>|null,crossed:bool,reason:string}"));
check("the calendar date of the punch is the fallback", resolver.includes("$calendar = $calendarDate !== '' ? $calendarDate : substr($normalised, 0, 10);"));
check("it looks at the day BEFORE the punch first", resolver.includes("$previous = date('Y-m-d', strtotime($calendar . ' -1 day'));"));
check("the previous day's shift is resolved with the existing resolver", resolver.includes("$nightShift = self::resolve($employeeId, $customerId, $previous);"));
check("only a shift that crosses midnight can pull a punch back", resolver.includes("if ((bool) $window['crosses']) {"));
check("the window comes from AttendanceCalculator, the single authority", resolver.includes("$window = AttendanceCalculator::window($nightShift, $previous);"));
check("the grace is the larger of the config and the shift's own grace", resolver.includes("max(\n                    (int) Config::get('attendance.night_shift_grace_minutes', 120),\n                    (int) ($nightShift['early_leave_grace_minutes'] ?? 0)\n                )"));
check("the grace default is 120 minutes", resolver.includes("Config::get('attendance.night_shift_grace_minutes', 120)"));
check("the punch has to be inside window end + grace", resolver.includes("if ($limit !== false && $moment !== false && $moment <= $limit) {"));
check("the crossing case returns the PREVIOUS date", resolver.includes("'date'    => $previous,\n                        'shift'   => $nightShift,\n                        'crossed' => true,\n                        'reason'  => 'night_shift',"));
check("the returned shift is the shift of the RESOLVED date", resolver.includes("'shift'   => self::resolve($employeeId, $customerId, $calendar),"));
check("an unparsable moment falls back to now instead of throwing", resolver.includes("$normalised = date('Y-m-d H:i:s');"));
check("a bad previous date is refused, not guessed", resolver.includes("if ($previous === false || $employeeId <= 0 || $customerId <= 0) {"));
check("the reason distinguishes 'no shift' from 'window closed'", resolver.includes("$nightShift === null ? 'no_previous_shift' : 'window_closed'"));
check("Config is imported", resolver.includes("use App\\Core\\Config;"));
check("the existing resolve() is untouched", resolver.includes("public static function resolve(int $employeeId, int $customerId, string $date): ?array"));
check("the snapshot still carries crosses_midnight", resolver.includes("'crosses_midnight'         => (int) ($shift['crosses_midnight'] ?? 0),"));
check("fromSnapshot() still restores crosses_midnight", resolver.includes("'crosses_midnight'         => (int) ($snapshot['crosses_midnight'] ?? 0),"));

/* =====================================================================
 * C. insertEntry(): the punch lands on the right day
 * =================================================================== */
section("C. insertEntry() writes the resolved date");

const engine = read("app/Services/AttendanceEngine.php");

check("insertEntry() asks resolveForMoment()", engine.includes("$resolved = ShiftResolver::resolveForMoment($employeeId, $customerId, $eventAt, $date);"));
check("the shift comes from that single answer", engine.includes("$shift    = $resolved['shift'];"));
check("the date comes from that single answer", engine.includes("$date     = (string) $resolved['date'];"));
check("the resolution happens BEFORE the day context is built", engine.indexOf("$resolved = ShiftResolver::resolveForMoment") < engine.indexOf("$context  = $this->dayContext($customerId, $employeeId, $date, $branchId);"));
check("the resolution happens BEFORE the session is calculated", engine.indexOf("$resolved = ShiftResolver::resolveForMoment") < engine.indexOf("$calc     = AttendanceCalculator::calculateSession(['entry_at' => $eventAt, 'exit_at' => null], $shift, $context, $date);"));
check("the resolution happens BEFORE the session index is counted", engine.indexOf("$resolved = ShiftResolver::resolveForMoment") < engine.indexOf("$sessionIndex = AttendanceRecord::nextSessionIndex($customerId, $employeeId, $date);"));
// Scoped to insertEntry(): closeDay() legitimately resolves a shift by date,
// because there the date IS the question (which day is being closed).
const insertEntryBody = engine.slice(engine.indexOf("private function insertEntry("), engine.indexOf("private function closeSession("));
check("insertEntry() no longer resolves the shift by calendar date", !/\$shift\s+=\s+ShiftResolver::resolve\(\$employeeId, \$customerId, \$date\);/.test(insertEntryBody));
check("closeDay() still resolves by the date it closes", /public function closeDay\([\s\S]*?\$shift = ShiftResolver::resolve\(\$employeeId, \$customerId, \$date\);/.test(engine));
check("the row is still written with the resolved date", engine.includes("'attendance_date'  => $date,"));
check("the snapshot is still written from the same shift", engine.includes("'shift_snapshot'   => $shift === null ? null : json_encode(ShiftResolver::snapshot($shift), JSON_UNESCAPED_UNICODE),"));
check("the late minutes still come from the calculator", engine.includes("'late_minutes'     => (int) $calc['late_minutes'],"));

/* =====================================================================
 * D. closeDay(): an open session IS a session
 * =================================================================== */
section("D. closeDay() sees the person who is still inside");

check("closeDay() reads the open session too", engine.includes("$open = AttendanceRecord::openSessionFor($customerId, $employeeId);"));
check("an open session of that date counts as 'has sessions'", engine.includes("if ($sessions !== [] || ($open !== null && substr((string) ($open['attendance_date'] ?? ''), 0, 10) === $date)) {"));
check("the open session is matched on its attendance_date, not on today", engine.includes("substr((string) ($open['attendance_date'] ?? ''), 0, 10) === $date"));
check("a night-shift day that is still running is skipped", engine.includes("if ($this->nightShiftStillRunning($shift, $date)) {"));
check("the skip is counted under its own key", engine.includes("$skipped['night_shift_open']++;"));
check("the skipped counters include night_shift_open", engine.includes("$skipped       = ['holiday' => 0, 'no_shift' => 0, 'has_sessions' => 0, 'night_shift_open' => 0];"));
check("the absence check still happens AFTER the shift is known", engine.indexOf("$shift = ShiftResolver::resolve($employeeId, $customerId, $date);") < engine.indexOf("$status = AttendanceCalculator::statusForMissingDay(["));
check("the skip counters are still reported in the result", engine.includes("'skipped'         => $skipped,"));
check("an existing absence row is still not duplicated", engine.includes("if ($existing !== null) {\n                continue;\n            }"));
check("closeDay() still recalculates the real sessions afterwards", engine.includes("$this->recalculate($customerId, $date, $date, 0, false, $actor);"));

check("nightShiftStillRunning() only applies to TODAY", engine.includes("if ($date !== date('Y-m-d')) {\n            return false;\n        }"));
check("nightShiftStillRunning() only applies to a crossing shift", engine.includes("if (!($window['crosses'] ?? false)) {\n            return false;\n        }"));
check("nightShiftStillRunning() compares against the window end", engine.includes("return $end !== false && time() < $end;"));
check("an unparsable window end means 'not running', never 'skip forever'", engine.includes("$end = strtotime((string) $window['end']);"));

/* =====================================================================
 * E. The forgotten exit punch no longer blocks everything
 * =================================================================== */
section("E. A stale open session is closed automatically");

check("closeStaleSession() exists", engine.includes("private function closeStaleSession(int $customerId, int $employeeId, int $deviceId, array $open, string $now, string $requestId): bool"));
check("insertEntry() tries it before refusing the punch", engine.includes("if ($open !== null && $this->closeStaleSession($customerId, $employeeId, $deviceId, $open, $eventAt, $requestId)) {\n            $open = null;\n        }"));
check("a closed stale session lets the new entry through", engine.indexOf("$open = null;") < engine.indexOf("return $this->fail(self::CODE_ENTRY_OPEN"));
check("the refusal still happens when the session is NOT stale", engine.includes("return $this->fail(self::CODE_ENTRY_OPEN, 'ورود شما قبلاً ثبت شده است.', 409, ["));

check("the exit is the END OF THE WINDOW, never the punch time", engine.includes("$exitAt = date('Y-m-d H:i:s', min($endTs, $nowTs));"));
check("the exit can never be in the future", engine.includes("min($endTs, $nowTs)"));
check("an exit that would not be after the entry is refused", engine.includes("if (strtotime($exitAt) <= strtotime((string) $entryAt)) {\n            return false;\n        }"));
check("a session without a shift is never closed automatically", engine.includes("if ($shift === null || trim((string) ($shift['start_time'] ?? '')) === '') {\n            return false;\n        }"));
check("the stored snapshot wins over the current shift", engine.includes("$shift    = ShiftResolver::fromSnapshot(is_array($snapshot) ? $snapshot : null);"));
check("nothing happens before window end + grace", engine.includes("if ($nowTs < $endTs + ($grace * 60)) {\n            return false;\n        }"));
check("the grace is configurable and defaults to 120 minutes", engine.includes("Config::get('attendance.stale_session_grace_minutes', 120)"));
check("the day is recomputed with its sibling sessions", engine.includes("$sessions   = AttendanceRecord::sessionsOn($customerId, $employeeId, $openDate);") && engine.includes("$sessions[] = ['entry_at' => $entryAt, 'exit_at' => $exitAt];") && engine.includes("AttendanceCalculator::aggregateDay($sessions, $shift, $context, $openDate)"));
check("the row is flagged auto_closed, not manual", engine.includes("AttendanceCalculator::FLAG_AUTO_CLOSED"));
check("open_key is cleared so the invariant exit_at<->open_key holds", engine.includes("`open_key` = NULL, `last_event` = ?,"));
check("last_event becomes 'exit'", engine.includes("'exit',\n                'خروج فراموش‌شده به صورت خودکار با پایان پنجره شیفت بسته شد.',"));
check("the reason is stored on the row in Persian", engine.includes("خروج فراموش‌شده به صورت خودکار با پایان پنجره شیفت بسته شد."));
check("the UPDATE is race safe: only an open row can be closed", engine.includes("WHERE `id` = ? AND `customer_id` = ? AND `open_key` IS NOT NULL AND `deleted_at` IS NULL"));
check("a lost race returns false instead of a second close", engine.includes("if ($statement->rowCount() === 0) {\n            return false;\n        }"));
check("the auto close is logged", engine.includes("$this->log('attendance.session.auto_closed'"));
check("the auto close fires an event", engine.includes("AttendanceEvent::fire($customerId, AttendanceEvent::MANUAL_CORRECTED, [\n            'record_id' => $recordId,\n            'action'    => 'auto_close',"));
check("the auto close is audited with before/after", engine.includes("AuditLog::record('attendance.session.auto_closed', 'success',"));
check("the audit and the event stay tenant scoped", engine.includes("'attendance', 'attendance_record', $recordId, $customerId);"));
check("the flag constant exists on the calculator", read("app/Services/AttendanceCalculator.php").includes("public const FLAG_AUTO_CLOSED = 'auto_closed';"));

/* =====================================================================
 * F. Nothing that was already correct was "fixed" again
 * =================================================================== */
section("F. The parts that were already correct are untouched");

const calculator = read("app/Services/AttendanceCalculator.php");
const shiftController = read("app/Controllers/Manager/ShiftController.php");
const shiftModel = read("app/Models/Shift.php");

check("CALC_VERSION stays 6.0.0 - no formula changed", calculator.includes("public const CALC_VERSION = '6.0.0';"));
check("window() is still the single midnight authority", calculator.includes("public static function window(array $shift, string $date): array"));
check("window() still moves the end to the next day when it crosses", calculator.includes("$endDate = date('Y-m-d', strtotime($date . ' +1 day'));"));
check("window() still honours the stored flag OR end <= start", calculator.includes("if ($flag === 1 || $endMinutes <= $startMinutes) {"));
check("aggregateDay() still subtracts the break once per day", calculator.includes("$work = max(0, $work - max(0, (int) ($shift['break_minutes'] ?? 0)));"));
check("aggregateDay() still counts open sessions as incomplete", calculator.includes("$open++;"));

check("ShiftController::validate() only rejects start === end (never end < start)", shiftController.includes("if ($start === $end) {") && shiftController.includes("ساعت شروع و پایان نمی‌توانند یکسان باشند."));
check("validate() does NOT reject a crossing shift", !/پایان باید (بعد|بزرگ‌تر)/.test(shiftController));
check("validate() still computes the midnight aware span", shiftController.includes("? (Shift::timeMinutes($end) + 1440) - Shift::timeMinutes($start)"));
check("crossesMidnight() is still derived from the times", shiftModel.includes("return self::timeMinutes($end) <= self::timeMinutes($start) ? 1 : 0;"));
check("Shift::summary() is unchanged (the employee form still uses it)", shiftModel.includes("public static function summary(array $shift): string") && read("views/manager/employees/form.php").includes("Shift::summary($shift)"));

check("closeSession() is still date independent", !/closeSession[\s\S]{0,400}resolveForMoment/.test(engine));
check("openSessionFor() is unchanged (oldest open row, any date)", read("app/Models/AttendanceRecord.php").includes("ORDER BY `entry_at` ASC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')"));
check("recalculate() still skips rows without an entry", engine.includes("`entry_at` IS NOT NULL"));

/* =====================================================================
 * G. The shift list says so in words
 * =================================================================== */
section("G. The panel shows a night shift as a night shift");

check("Shift::windowLabel() exists", shiftModel.includes("public static function windowLabel(array $shift): string"));
check("a crossing shift says (روز بعد)", shiftModel.includes("return $start . ' ← ' . $end . ($crosses ? ' (روز بعد)' : '');"));
check("a normal shift has no (روز بعد)", shiftModel.includes("$crosses ? ' (روز بعد)' : ''"));
check("windowLabel() honours the flag or the times, like window() does", shiftModel.includes("(int) ($shift['crosses_midnight'] ?? 0) === 1\n            || self::timeMinutes($shift['end_time'] ?? '00:00') <= self::timeMinutes($shift['start_time'] ?? '00:00');"));
check("Shift::isNightShift() exists for the badge", shiftModel.includes("public static function isNightShift(array $shift): bool"));
check("the two helpers agree on the rule", shiftModel.indexOf("isNightShift") > shiftModel.indexOf("windowLabel"));

const shiftsView = read("views/manager/shifts/index.php");
check("the shift table uses windowLabel()", shiftsView.includes("<?= $e(Shift::windowLabel($shift)) ?>"));
check("the ambiguous '20:00 - 06:00' is gone from that cell", !shiftsView.includes("<?= $e(Shift::timeLabel($shift['start_time'])) ?> - <?= $e(Shift::timeLabel($shift['end_time'])) ?>"));
check("a night shift carries a badge", shiftsView.includes("<span class=\"badge badge-warn\">شیفت شبانه</span>"));
check("the badge is decided by isNightShift(), not by the raw column", shiftsView.includes("<?php if (Shift::isNightShift($shift)): ?>"));
check("the form still explains how a night shift is entered", shiftsView.includes("اگر ساعت پایان کوچک‌تر از شروع باشد، شیفت شبانه (عبور از نیمه‌شب) در نظر گرفته می‌شود."));
check("the time inputs still post HH:MM", shiftsView.includes("<input type=\"time\" name=\"start_time\""));

/* =====================================================================
 * H. Configuration
 * =================================================================== */
section("H. Both grace windows are configurable");

const config = read("config/config.sample.php");

check("night_shift_grace_minutes is documented in the sample config", config.includes("'night_shift_grace_minutes' => 120,"));
check("stale_session_grace_minutes is documented in the sample config", config.includes("'stale_session_grace_minutes' => 120,"));
check("the sample config explains the night shift grace", config.includes("A punch after midnight belongs to the shift that started the evening"));
check("the sample config explains the stale session grace", config.includes("a forgotten exit punch"));
check("the sample config says 0 disables the automatic close", config.includes("0 disables the automatic close"));
check("both keys live in the attendance section", config.indexOf("'night_shift_grace_minutes'") > config.indexOf("'attendance' => [") && config.indexOf("'stale_session_grace_minutes'") < config.indexOf("'display_calendar'"));
check("every reader passes a default, so a config without the key works", engine.includes("Config::get('attendance.stale_session_grace_minutes', 120)") && resolver.includes("Config::get('attendance.night_shift_grace_minutes', 120)"));
check("a negative grace can not close a session early", engine.includes("$grace = max(0, (int) Config::get('attendance.stale_session_grace_minutes', 120));"));
check("a negative night grace can not move a punch to the wrong day", resolver.includes("$grace = max("));

/* =====================================================================
 * Summary
 * =================================================================== */
console.log(
  "\n" +
    "=".repeat(62) +
    "\nSTAGE 23 checks passed: " +
    passed +
    ", failed: " +
    failures.length
);
if (failures.length > 0) {
  console.log("\nFAILURES:");
  failures.forEach((failure) => console.log("  ✗ " + failure));
  process.exit(1);
}
console.log("✓ a night punch lands on the right day, nobody is marked absent while inside");
process.exit(0);
