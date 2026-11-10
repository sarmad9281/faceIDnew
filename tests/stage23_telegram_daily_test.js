// Run with Node; no server, browser or database required.
//
// STAGE 23 B - the Telegram daily report.
//
//   node tests/stage23_telegram_daily_test.js
//
// A manager who reads "غایب: ۳" on a phone still has to open the panel to find
// out WHO. The daily message now carries the names behind three of its numbers,
// and the panel shows the exact text before anybody sends it:
//
//   1. the names come from the SAME report query as the numbers - one query,
//      never a second source that could disagree with the count,
//   2. rows are grouped per employee (two sessions of one person are one name),
//      and the buckets have a severity order (absent beats late),
//   3. a bucket that is empty adds nothing, so a clean day stays the short
//      ten-line message it always was,
//   4. the lists are capped (15 names, then "و N نفر دیگر") and the whole
//      message is clamped to Telegram's hard 4096 character limit - by dropping
//      lines from the END, never a number from the top,
//   5. the preview is built by the same code the worker runs and sends nothing,
//   6. the send button stays the ONE existing route; no second send path.

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
 * Sources
 * =================================================================== */

const scheduler = read("app/Services/TelegramScheduler.php");
const controller = read("app/Controllers/Manager/TelegramController.php");
const view = read("views/manager/telegram/index.php");
const routes = read("routes/web.php");
const notifier = read("app/Services/TelegramNotifier.php");
const reports = read("app/Services/ReportService.php");

/* =====================================================================
 * A. One source for the numbers AND the names
 * =================================================================== */
section("A. The names come from the same report query as the numbers");

check("summary() still calls ReportService::daily()", scheduler.includes("$reports->daily($customerId, ['date_from' => $date, 'date_to' => $date]"));
check("summary() asks for up to 500 rows (the report hard cap)", scheduler.includes("1, 500);"), "the page size must be the cap, not 10");
check("ReportService::daily() really caps a page at 500", reports.includes("$perPage = max(10, min(500, $perPage));"));
check("the counts are still read from the summary query, unchanged", scheduler.includes("'present'          => (int) $summary['present_days'],") && scheduler.includes("'absent'           => (int) $summary['absent_days'],") && scheduler.includes("'on_leave'         => (int) $summary['leave_days'],") && scheduler.includes("'late_minutes'     => (int) $summary['late_minutes'],"));
check("no second query is opened for the names", scheduler.includes("] + $this->nameBuckets($data['rows'] ?? [], (int) $summary['employees']);"));
check("nameBuckets() reads the report rows only", /private function nameBuckets\(array \$rows, int \$employeeCount\): array/.test(scheduler));
check("the daily report query already selects the names", reports.includes("e.`first_name`, e.`last_name`, e.`employee_code`"));
check("the daily report query already selects the status", reports.includes("r.`status`, r.`flags`, r.`source`"));
check("the daily report query already selects late_minutes", reports.includes("r.`late_minutes`"));
check("the daily report query already selects entry_at / exit_at", reports.includes("r.`entry_at`, r.`exit_at`"));

/* =====================================================================
 * B. Grouping and the severity order
 * =================================================================== */
section("B. Grouping per employee and the bucket order");

check("rows are grouped by employee id", scheduler.includes("if (!isset($people[$employeeId])) {"));
check("a row without an employee id or a name is skipped", scheduler.includes("if ($employeeId <= 0 || $name === '') {\n                continue;\n            }"));
check("the late minutes of all sessions are summed", scheduler.includes("$people[$employeeId]['late']      += max(0, (int) ($row['late_minutes'] ?? 0));"));
check("an employee counts as entered when ANY session has an entry", scheduler.includes("$people[$employeeId]['entered']    = $people[$employeeId]['entered'] || ($row['entry_at'] ?? null) !== null;"));
check("an employee counts as exited when ANY session has an exit", scheduler.includes("$people[$employeeId]['exited']     = $people[$employeeId]['exited'] || ($row['exit_at'] ?? null) !== null;"));
check("absent wins over every other bucket", scheduler.includes("if (in_array('absent', $statuses, true)) {"));
check("approved leave wins over late", scheduler.indexOf("in_array('on_leave', $statuses, true)") < scheduler.indexOf("$person['late'] > 0"));
check("late wins over 'still inside'", scheduler.indexOf("$person['late'] > 0") < scheduler.indexOf("if ($person['entered'] && !$person['exited']) {"));
check("a person who entered and left is not listed at all", scheduler.includes("if ($person['entered'] && !$person['exited']) {\n                $inProgress[] = $person['name'];\n            }"));
check("every branch continues, so nobody is listed twice", (scheduler.slice(scheduler.indexOf("foreach ($people as $person)"), scheduler.indexOf("usort($late")).match(/continue;/g) || []).length >= 3);
check("the late list is ordered by the longest delay first", scheduler.includes("usort($late, static fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);"));
check("a late entry carries the name AND the minutes", scheduler.includes("$late[] = ['name' => $person['name'], 'minutes' => $person['late']];"));
check("the four buckets are returned under stable keys", ["'absent_names'    => $absent,", "'late_names'      => $late,", "'on_leave_names'  => $onLeave,", "'in_progress'     => $inProgress,"].every((needle) => scheduler.includes(needle)));
check("an incomplete row set is reported, never hidden", scheduler.includes("'list_complete'   => count($rows) < 500,"));
check("the message says so when the list may be incomplete", scheduler.includes("فهرست اسامی ممکن است کامل نباشد"));

/* =====================================================================
 * C. The message text
 * =================================================================== */
section("C. The message: ten numbers, then the names");

check("message() still opens with the report title", scheduler.includes("'گزارش روزانه حضور و غیاب',"));
check("message() still names the customer", scheduler.includes("'مجموعه: ' . (string) ($customer['name'] ?? '—'),"));
check("the date is shown in Jalali", scheduler.includes("'تاریخ: ' . Jalali::date($date),"));
check("all ten original numeric lines are untouched", ["'تعداد پرسنل: '", "'حاضر: '", "'غایب: '", "'مرخصی: '", "'تأخیر: '", "'اضافه‌کاری: '", "'مجموع کارکرد: '"].every((needle) => scheduler.includes(needle)));
check("minutes are still labelled through TelegramNotifier", scheduler.includes("TelegramNotifier::minutesLabel((int) $summary['late_minutes'])"));
check("TelegramNotifier still owns the minutes label", notifier.includes("public static function minutesLabel("));

check("an empty absent bucket adds no section", scheduler.includes("if ($absent !== []) {"));
check("an empty late bucket adds no section", scheduler.includes("if ($late !== []) {"));
check("an empty leave bucket adds no section", scheduler.includes("if ($leave !== []) {"));
check("an empty 'still inside' bucket adds no section", scheduler.includes("if ($open !== []) {"));
check("each section states how many people it lists", ["'غایبان (' . count($absent) . ' نفر):'", "'تأخیر (' . count($late) . ' نفر):'", "'مرخصی (' . count($leave) . ' نفر):'", "'هنوز داخل مجموعه (' . count($open) . ' نفر):'"].every((needle) => scheduler.includes(needle)));
check("every name is a bullet", scheduler.includes("$lines[] = '• ' . $name;"));
check("names are cut at 15 entries", scheduler.includes("private const NAME_LIMIT = 15;") && scheduler.includes("array_slice($names, 0, self::NAME_LIMIT)"));
check("the remainder is reported as a count", scheduler.includes("$lines[] = '• و ' . $rest . ' نفر دیگر';"));
check("the remainder line is only added when there is one", scheduler.includes("if ($rest > 0) {"));
check("a late bullet shows the delay next to the name", scheduler.includes("$late[] = (string) ($entry['name'] ?? '') . ' — ' . TelegramNotifier::minutesLabel((int) ($entry['minutes'] ?? 0));"));
check("an empty name never becomes a bullet", scheduler.includes("array_values(array_filter(array_map('strval', (array) ($summary['absent_names'] ?? []))))"));
check("a late entry that is a plain string still works", scheduler.includes("} elseif (trim((string) $entry) !== '') {"));

/* =====================================================================
 * D. Telegram's 4096 character limit
 * =================================================================== */
section("D. The 4096 character limit");

check("the limit is a named constant", scheduler.includes("public const MESSAGE_LIMIT = 4096;"));
check("every message goes through clamp()", scheduler.includes("return $this->clamp(implode(\"\\n\", $lines));"));
check("a message inside the limit is returned unchanged", scheduler.includes("if (strlen($text) <= self::MESSAGE_LIMIT) {\n            return $text;\n        }"));
check("lines are dropped from the END, never from the top", scheduler.includes("array_pop($lines);"));
check("the loop always keeps at least one line", scheduler.includes("while (count($lines) > 1 &&"));
check("the clamp accounts for the note it appends", scheduler.includes("strlen(implode(\"\\n\", $lines)) + strlen($note) + 1 > self::MESSAGE_LIMIT"));
check("the reader is told the list was cut", scheduler.includes("… (ادامه فهرست به دلیل محدودیت طول پیام تلگرام حذف شد)"));

/* =====================================================================
 * E. The preview
 * =================================================================== */
section("E. The preview is built by the same code and sends nothing");

check("previewFor() exists on the scheduler", scheduler.includes("public function previewFor(int $customerId, string $date): array"));
check("previewFor() calls summary()", scheduler.includes("$summary = $this->summary($customerId, $date);"));
check("previewFor() calls message()", scheduler.includes("$text    = $this->message($customerId, $date, $summary);"));
// The body of previewFor() only: from its signature to the closing brace at the
// class indentation level (the next method would otherwise be included).
const previewStart = scheduler.indexOf("public function previewFor");
const previewEnd = scheduler.indexOf("\n    }\n", previewStart);
const previewBody = scheduler.slice(previewStart, previewEnd);
check("previewFor() never sends", !/send|queue|dispatch|telegram_service|TelegramService/i.test(previewBody), previewBody.replace(/\s+/g, " ").slice(0, 120));
check("previewFor() never writes to the database", !/Database::(run|insert|update|delete)/.test(previewBody));
check("previewFor() reports the length and whether it was cut", previewBody.includes("'chars'      => strlen($text),") && previewBody.includes("'truncated'  => strlen($text) > self::MESSAGE_LIMIT,"));
check("previewFor() reports the Jalali date of the preview", previewBody.includes("'jalali'     => Jalali::date($date),"));

check("the controller builds the preview for the page", controller.includes("(new TelegramScheduler())->previewFor($customerId, $previewDate)"));
check("the preview date is validated as a real date", controller.includes("Input::date((string) $request->input('preview_date', '')) ?? date('Y-m-d')"));
check("a preview failure can not break the telegram page", controller.includes("catch (\\Throwable $e) {") && controller.includes("'message' => 'پیش‌نمایش گزارش روزانه ساخته نشد.',"));
check("the preview is passed to the view", controller.includes("'dailyPreview'  => $dailyPreview,"));
check("the preview is built inside the telegram feature gate", controller.indexOf("FeatureService::requireFeature('telegram', $customerId)") < controller.indexOf("previewFor("));

/* =====================================================================
 * F. One send path only
 * =================================================================== */
section("F. One send route, one send button");

check("the daily send route is still POST /manager/telegram/daily", routes.includes("$router->post('/manager/telegram/daily', [ManagerTelegramController::class, 'daily']"));
check("no second daily send route was added", (routes.match(/telegram\/daily/g) || []).length === 1, String((routes.match(/telegram\/daily/g) || []).length));
check("no preview route was added (the preview is part of the page)", !routes.includes("telegram/preview"));
check("the send route still carries csrf", /telegram\/daily', \[ManagerTelegramController::class, 'daily'\],\s*\['auth', 'role:manager,owner', 'tenant:single', 'feature:telegram', 'csrf'\]/.test(routes));
check("the send button is relabelled so nobody clicks it by accident", view.includes("ارسال فوری گزارش امروز به تلگرام"));
check("the send button posts today's date to the existing route", view.includes("action=\"<?= $e($url('/manager/telegram/daily')) ?>\"") && view.includes("<input type=\"hidden\" name=\"date\" value=\"<?= $e(date('Y-m-d')) ?>\">"));
check("the preview form is a GET and can not send", view.includes("<form method=\"get\" action=\"<?= $e($url('/manager/telegram')) ?>\">"));
check("the preview form carries no csrf token (it sends nothing)", !/<form method="get" action="[^"]*manager\/telegram[^>]*>[\s\S]{0,120}\$csrf\(\)/.test(view));

/* =====================================================================
 * G. The panel card
 * =================================================================== */
section("G. The «گزارش روزانه» card");

check("the card has a heading a screen reader can find", view.includes("<h2>گزارش روزانه</h2>"));
check("the card shows the Jalali date it previews", view.includes("$e(Jalali::date((string) ($dailyPreview['date'] ?? date('Y-m-d'))))"));
/* STAGE 25 rewrote these two. The card used to carry a native
   <input type="date">, which showed the browser's Gregorian calendar and stored
   11/11/2026 in the box; every date field of the panel is a Jalali field now, so
   the assertion follows the markup instead of the other way round. What stage 23
   actually cared about - that the card previews ONE requested date and keeps it -
   is still what these check. */
check("the card has a Jalali date picker", view.includes("<input type=\"text\" data-shamsi=\"1\" name=\"preview_date\" class=\"ltr\""));
check("no native Gregorian picker is left on the card", !view.includes("type=\"date\""));
check("the picker keeps the requested date", view.includes("value=\"<?= $e(Jalali::input((string) ($dailyPreview['date'] ?? date('Y-m-d')))) ?>\""));
check("the exact message text is shown in a preformatted block", view.includes("<pre dir=\"rtl\""));
check("the preview block wraps long lines instead of scrolling off screen", view.includes("white-space:pre-wrap"));
check("the preview text is escaped", view.includes("<?= $e((string) ($dailyPreview['message'] ?? '')) ?>"));
check("the card counts each bucket in a table", view.includes("<th>غایب</th><th>مرخصی</th><th>تأخیر</th><th>داخل مجموعه</th>"));
check("the table counts come from the name lists, not the totals", view.includes("count((array) ($summary['absent_names'] ?? []))") && view.includes("count((array) ($summary['in_progress'] ?? []))"));
check("the message length is shown", view.includes("<?= $e((string) (int) ($dailyPreview['chars'] ?? 0)) ?> نویسه"));
check("a clamped message is flagged", view.includes("کوتاه‌شده برای تلگرام"));
check("the card explains that it sends nothing", view.includes("این متن همان چیزی است که Cron در ساعت تعیین‌شده یا دکمه «ارسال فوری گزارش امروز به تلگرام» می‌فرستد."));
check("the card explains the 15 name cap", view.includes("هر بخش حداکثر ۱۵ نام و سپس «و N نفر دیگر» دارد."));
check("a failed preview shows a warning, not an empty box", view.includes("<?= $e((string) ($dailyPreview['message'] ?? 'پیش‌نمایش در دسترس نیست.')) ?>"));
check("the card is guarded against a missing preview", view.includes("<?php if (!empty($dailyPreview['ok'])): ?>"));
check("the new variable is documented for static analysis", view.includes("@var array<string,mixed>|null $dailyPreview"));
check("the card is an <article class=\"card\"> like its neighbours", view.includes("<article class=\"card\">\n        <div class=\"card-head\">\n            <h2>گزارش روزانه</h2>"));

/* =====================================================================
 * H. Nothing else changed
 * =================================================================== */
section("H. The scheduler contract around the message is untouched");

check("the report type is still 'daily'", scheduler.includes("public const REPORT_TYPE = 'daily';"));
check("the retry limit is unchanged", scheduler.includes("public const MAX_ATTEMPTS = 3;"));
check("the dedupe key of a daily report is unchanged", scheduler.includes("'daily:'"));
check("runDue() is still the cron entry point", scheduler.includes("public function runDue(?string $now = null, ?TelegramService $service = null): array"));
check("sendNow() is still the manual entry point", scheduler.includes("public function sendNow(int $customerId, string $date, ?TelegramService $service = null): array"));
check("reconcile() is still there for the failed queue", scheduler.includes("public function reconcile(int $limit = 200): array"));
check("message() is still public so the worker can call it", scheduler.includes("public function message(int $customerId, string $date, array $summary): string"));
check("the summary docblock was updated for the new shape", scheduler.includes("@return array<string,mixed>\n     */\n    public function summary("));

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
console.log("✓ the daily telegram report names the absent, late and on-leave people");
process.exit(0);
