// Run with Node; no server, browser, database or PHP binary required.
//
// STAGE 25 - the panel's date fields ARE Jalali now.
//
//   node tests/stage25_shamsi_date_test.js
//
// What changed, and why this suite looks the way it does:
//
//   Stage 24 put a Gregorian <input type="date"> on the page and wrote
//   "معادل شمسی: 1404/07/14" underneath it. That was rejected, and rightly: the
//   box still held a number in the wrong calendar, clicking it still opened the
//   browser's Gregorian picker, and the labels still said "(میلادی)". Explaining a
//   wrong calendar is not the same as using the right one.
//
//   So stage 25 removes the Gregorian box entirely. Every date field now holds
//   the Jalali date itself - 1405/08/20 - and opens a Jalali calendar. The
//   database is untouched: columns are still real Gregorian DATEs, because
//   reports, BETWEEN ranges and MySQL's date functions need them to be.
//   Input::date() converts on the way in, Jalali::input() converts on the way
//   out, and assets/js/shamsi-date.js is the calendar the user clicks.
//
// Sections:
//   A - no Gregorian box, hint or apology survives anywhere in the panel
//   B - Jalali::toGregorian() is the exact inverse of fromGregorian()
//   C - Input::date() accepts the Jalali a field posts
//   D - the picker, driven for real in jsdom
//   E - the conversion the browser runs matches the platform's Persian calendar
//   F - both panels load the picker, and no stylesheet had to change
//   G - the views render Jalali values, and the owner panel matches
//
// The stage 24 suite (stage24_jalali_date_hint_test.js) is gone on purpose: it
// asserted 29 hint pairings that this stage deletes. Its one part that is still
// true - the Telegram preview contrast fix - is re-asserted in section F.

"use strict";

const fs = require("node:fs");
const path = require("node:path");
const { JSDOM } = require("jsdom");

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

/* Date literals are assembled from parts. A tool in this pipeline was observed
   rewriting date-looking strings in files it wrote, and a test whose expected
   value was silently edited is worse than no test at all. */
const DASH = "-";
const SLASH = "/";
const ymd = (y, m, d) => String(y) + DASH + pad2(m) + DASH + pad2(d);
const jmd = (y, m, d) => String(y) + SLASH + pad2(m) + SLASH + pad2(d);

function pad2(value) {
  const text = String(value);
  return text.length < 2 ? "0" + text : text;
}

/** Every .php file under views/, as paths relative to the repo root. */
function allViews() {
  const out = [];
  const walk = (dir) => {
    for (const entry of fs.readdirSync(path.join(ROOT, dir), { withFileTypes: true })) {
      const rel = dir + "/" + entry.name;
      if (entry.isDirectory()) walk(rel);
      else if (entry.name.endsWith(".php")) out.push(rel);
    }
  };
  walk("views");
  return out;
}

/* =====================================================================
 * A. No Gregorian box, hint or apology survives
 * =================================================================== */

section("A. the panel has no Gregorian date box left");

const views = allViews();
const viewSources = new Map(views.map((rel) => [rel, read(rel)]));

let dateInputs = 0;
let dateInputFiles = [];
for (const [rel, source] of viewSources) {
  const found = (source.match(/type="date"/g) || []).length;
  if (found > 0) {
    dateInputs += found;
    dateInputFiles.push(rel + " (" + found + ")");
  }
}
check("no view contains a single <input type=\"date\">", dateInputs === 0, dateInputFiles.join(", "));

/* The count is asserted exactly, not "at least": a field that lost its
   data-shamsi attribute would silently become a plain text box nobody can pick
   a date with, and a field added later should make this number move on purpose. */
const SHAMSI_FIELDS = 35;
let shamsiInputs = 0;
const shamsiByFile = new Map();
for (const [rel, source] of viewSources) {
  const found = (source.match(/<input[^>]*data-shamsi="1"/g) || []).length;
  if (found > 0) shamsiByFile.set(rel, found);
  shamsiInputs += found;
}
check(
  "every one of the " + SHAMSI_FIELDS + " former date inputs is a Jalali field",
  shamsiInputs === SHAMSI_FIELDS,
  "found " + shamsiInputs + " in " + shamsiByFile.size + " files"
);

const FIELD_COUNTS = {
  "views/manager/leaves/index.php": 6,
  "views/manager/attendance/index.php": 6,
  "views/manager/payroll/index.php": 5,
  "views/manager/activity/index.php": 2,
  "views/manager/holidays/index.php": 2,
  "views/manager/reports/index.php": 2,
  "views/manager/reports/excel.php": 2,
  "views/manager/reports/advanced.php": 2,
  "views/manager/employees/form.php": 1,
  "views/manager/telegram/index.php": 1,
  "views/owner/subscriptions/show.php": 2,
  "views/owner/logs/index.php": 2,
  "views/owner/customers/form.php": 2,
};
for (const [rel, expected] of Object.entries(FIELD_COUNTS)) {
  check(
    rel + " carries its " + expected + " Jalali field(s)",
    shamsiByFile.get(rel) === expected,
    "found " + (shamsiByFile.get(rel) || 0)
  );
}

/* The owner panel is in scope too: a subscription's start and expiry are dates
   somebody reads, and the audit log filter is a date range. */
const ownerFields = [...shamsiByFile.keys()].filter((rel) => rel.startsWith("views/owner/"));
check("the owner panel's 3 date views were converted as well", ownerFields.length === 3, ownerFields.join(", "));

check("App\\Support\\JalaliHint is deleted", !fs.existsSync(path.join(ROOT, "app/Support/JalaliHint.php")));

let hintReferences = 0;
/* "tests" is not scanned: this suite has to name the deleted class to say what
   happened to it, and a prose mention is not a reference. */
for (const dir of ["app", "views", "assets"]) {
  const walk = (rel) => {
    for (const entry of fs.readdirSync(path.join(ROOT, rel), { withFileTypes: true })) {
      if (entry.name === "node_modules" || entry.name === ".phpcheck") continue;
      const child = rel + "/" + entry.name;
      if (entry.isDirectory()) walk(child);
      else if (/\.(php|js|css)$/.test(entry.name) && read(child).includes("JalaliHint")) hintReferences += 1;
    }
  };
  walk(dir);
}
check("nothing references JalaliHint any more", hintReferences === 0, hintReferences + " file(s)");
check(
  "the stage 24 hint suite is retired with the design it asserted",
  !fs.existsSync(path.join(ROOT, "tests/stage24_jalali_date_hint_test.js")),
  "it asserted 29 hint pairings that this stage deletes"
);

const manager = read("assets/js/manager.js");
check("manager.js no longer computes a Jalali label", !/toJalali|jalaliLabel|JALALI_PREFIX/.test(manager));
check("manager.js no longer looks for hint elements", !manager.includes("data-jalali-hint"));
check(
  "manager.js points the next reader at the picker",
  manager.includes("assets/js/shamsi-date.js"),
  "the 140 deleted lines should leave a signpost, not a hole"
);

/* The apology sentences. A label that says "(میلادی)" or a hint that says
   "تاریخ‌ها میلادی ثبت می‌شوند" tells the user the form speaks the wrong
   calendar; with Jalali fields there is nothing left to apologise for. */
const APOLOGIES = ["(میلادی)", "میلادی ثبت می‌شوند", "تاریخ میلادی؛", "معادل شمسی"];
let apologies = [];
for (const [rel, source] of viewSources) {
  for (const phrase of APOLOGIES) {
    if (source.includes(phrase)) apologies.push(rel + " → " + phrase);
  }
}
check("no view apologises for the calendar any more", apologies.length === 0, apologies.join(", "));

/* ... but the customer's calendar PREFERENCE is a real setting, not an apology.
   A customer may run their kiosk on the Gregorian calendar, and the option that
   says so must keep its label - deleting it would break the setting. */
const preferenceOffers = ["views/customers/create.php", "views/install/layout.php", "views/owner/customers/form.php"];
for (const rel of preferenceOffers) {
  check(
    rel + " still offers the Gregorian calendar as a customer choice",
    viewSources.get(rel).includes('value="gregorian"'),
    "the calendar preference is a setting, not an apology"
  );
}
/* Two views only DISPLAY the preference: the customer page names the column, the
   settings page labels the option it was given. Neither is an apology for the
   panel's own calendar, and deleting the word "میلادی" from them would leave a
   setting that cannot be read. */
check(
  "views/owner/customers/show.php still reports the customer's calendar",
  viewSources.get("views/owner/customers/show.php").includes("date_system")
);
check(
  "views/owner/settings/index.php still labels the Gregorian option",
  viewSources.get("views/owner/settings/index.php").includes("'gregorian'")
);

/* The holiday list used to print the stored Gregorian date in its own column,
   next to the Jalali one, because the field that wrote it was Gregorian. With a
   Jalali field there is nothing to cross-check and it was the last Gregorian
   number the manager panel displayed. */
const holidays = viewSources.get("views/manager/holidays/index.php");
check("the holiday table dropped its Gregorian column", !holidays.includes("<th>تاریخ میلادی</th>"));
const holidayHead = (holidays.match(/<th>/g) || []).length;
const holidayCells = (holidays.match(/<td[ >]/g) || []).length;
check(
  "the holiday table's header and row still agree",
  holidayHead > 0 && holidayCells >= holidayHead,
  holidayHead + " <th> vs " + holidayCells + " <td>"
);

/* =====================================================================
 * B. Jalali::toGregorian() is the exact inverse of fromGregorian()
 * =================================================================== */

section("B. the server-side inverse");

const jalali = read("app/Support/Jalali.php");

check("Jalali::toGregorian() exists", /public static function toGregorian\(int \$jy, int \$jm, int \$jd\): array/.test(jalali));
check("Jalali::monthLength() exists", /public static function monthLength\(int \$jy, int \$jm\): int/.test(jalali));
check("Jalali::isJalaliDate() exists", /public static function isJalaliDate\(int \$jy, int \$jm, int \$jd\): bool/.test(jalali));
check("Jalali::input() exists", /public static function input\(\$value\): string/.test(jalali));
check("fromGregorian() is untouched", jalali.includes("public static function fromGregorian(int $year, int $month, int $day): array"));

/* The epoch constant is the one number in the inverse that cannot be retyped
   from memory or copied from another library: two days of difference silently
   moves every stored date. PHP and the browser port must carry the same one. */
const EPOCH = "-355668";
check("Jalali::toGregorian() uses the " + EPOCH + " epoch", jalali.includes(EPOCH));

const picker = read("assets/js/shamsi-date.js");
check("the browser port uses the same " + EPOCH + " epoch", picker.includes(EPOCH));

/* PHP intdiv() truncates toward zero. Math.floor() does not for a negative
   dividend, and this algorithm produces negative intermediates. */
check("the port divides with Math.trunc", /function intdiv\(dividend, divisor\) \{ return Math\.trunc\(dividend \/ divisor\); \}/.test(picker));
const portBody = picker.slice(picker.indexOf("function toGregorian"), picker.indexOf("function monthLength"));
check("the port does not floor its divisions", !portBody.includes("Math.floor"));

/* monthLength() decides Esfand by walking one day past 29 Esfand. Doing that
   through mktime() would inherit time_t's range: on a 32-bit build it warns and
   returns false past January 2038, which is well inside the years this panel
   renders. The harness that runs this file saw exactly that warning before the
   integer version replaced it. */
const monthLengthBody = jalali.slice(jalali.indexOf("public static function monthLength"), jalali.indexOf("public static function isJalaliDate"));

/* Comments are stripped before looking for a call: monthLength() documents why
   it does NOT use mktime(), and matching the word in that sentence would be
   matching the explanation instead of the code. */
const withoutComments = (php) => php.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const monthLengthCode = withoutComments(monthLengthBody);

check("monthLength() does not call mktime()", !monthLengthCode.includes("mktime"));
check("monthLength() does not call strtotime()", !monthLengthCode.includes("strtotime"));
check("... and says why in a comment", monthLengthBody.includes("mktime"));
check("the day step is integer arithmetic", jalali.includes("private static function nextGregorianDay"));

/* Jalali::input() is what a field SHOWS, and it must never answer the em dash
   that Jalali::date() uses for a table cell: an input holding "—" would post
   it straight back. */
const inputBody = jalali.slice(jalali.indexOf("public static function input("));
check("input() reuses date() rather than re-converting", inputBody.includes("self::date($value)"));
check("input() keeps only the Y/m/d shape", /preg_match\('#\^\\d\{4\}\/\\d\{2\}\/\\d\{2\}\$#', \$label\) === 1/.test(inputBody));

/* =====================================================================
 * C. Input::date() accepts what a Jalali field posts
 * =================================================================== */

section("C. Input::date() speaks Jalali");

const input = read("app/Support/Input.php");

check("Input::date() folds Persian and Arabic-Indic digits first", input.includes("Encoding::toLatinDigits"));
check("Input::date() hands Jalali values to jalaliDate()", input.includes("return self::jalaliDate($value);"));
check("Input::jalaliDate() exists", /public static function jalaliDate\(\$value\): \?string/.test(input));
check("Input::isJalaliDate() exists", /public static function isJalaliDate\(\$value\): bool/.test(input));
/* Built from parts rather than written as a regex literal: the thing being
   matched is itself a regex, and a literal that has to escape a terminating
   slash is exactly where this kind of check goes wrong. */
const IS_DATE_PATTERN = "/" + "^" + "(" + "\\d{4}" + ")" + DASH + "(" + "\\d{2}" + ")" + DASH + "(" + "\\d{2}" + ")" + "$" + "/";
check("Input::isDate() still demands the strict Gregorian shape", input.includes(IS_DATE_PATTERN), IS_DATE_PATTERN);
check("Input::isDate() still proves the day exists with checkdate()", input.includes("checkdate"));
check("the Jalali branch validates the day, not just the shape", input.includes("Jalali::isJalaliDate($jy, $jm, $jd)"));
check("the conversion goes through Jalali, never a second table", input.includes("Jalali::toGregorian($jy, $jm, $jd)"));
check("Input.php imports the classes it calls", input.includes("namespace App\\Support;") || !input.includes("use App\\Support\\Jalali;"));

/* The year decides the calendar, and it has to be decided BEFORE the Gregorian
   shape is accepted: 1405-08-20 is a perfectly valid Gregorian date too (the
   year 1405), so a dash cannot be allowed to mean "already converted". */
const dateBody = input.slice(input.indexOf("public static function date("), input.indexOf("public static function jalaliDate("));
check("date() reads the year before trusting a dash", dateBody.indexOf("$year >= 1900") < dateBody.indexOf("jalaliDate"));
check("date() refuses an impossible Gregorian day", dateBody.includes("checkdate"));
check("jalaliDate() refuses a Gregorian-shaped year", /if \(\$jy < 1000 \|\| \$jy >= 1900/.test(input));

/* Every caller keeps working: 31 sites in 11 files convert request values with
   Input::date() and store what it returns. If the signature or the contract had
   changed, each of them would have had to change too. */
const CALLERS = [
  "app/Controllers/Manager/ActivityController.php",
  "app/Controllers/Manager/AttendanceController.php",
  "app/Controllers/Manager/EmployeeController.php",
  "app/Controllers/Manager/HolidayController.php",
  "app/Controllers/Manager/LeaveController.php",
  "app/Controllers/Manager/ModuleController.php",
  "app/Controllers/Manager/PayrollController.php",
  "app/Controllers/Manager/ReportController.php",
  "app/Controllers/Manager/TelegramController.php",
  "app/Models/DeviceLog.php",
  "app/Services/PayrollService.php",
];
let callSites = 0;
for (const rel of CALLERS) {
  const source = read(rel);
  callSites += (source.match(/Input::(date|isDate)\(/g) || []).length;
}
check("all 11 callers still exist", CALLERS.every((rel) => fs.existsSync(path.join(ROOT, rel))));
/* 31 call sites were already there, and this stage adds exactly three: the
   payroll profile's birth_date, id_issue_date and insurance_end_date, which
   were read as raw strings and would now store a Jalali string in a DATE
   column. The number is pinned so a fourth appears only on purpose. */
check(
  "the 31 existing call sites are untouched and exactly 3 were added",
  callSites === 34,
  "found " + callSites + " - the contract moved somewhere this suite does not know about"
);

/* Not every date in the panel arrived through Input::date() before this stage.
   Four controllers read their date fields as raw strings, which was harmless
   while the field posted Gregorian and is silently wrong now that it posts
   1405/08/20: a Jalali string in a DATE column, or a Jalali string compared
   against created_at. Each of them has to convert. */
const CONVERTERS = [
  ["app/Controllers/Owner/LogController.php", "Input::date($request->input('from', ''))", 2],
  ["app/Controllers/Owner/LogController.php", "Input::date($request->input('to', ''))", 2],
  ["app/Controllers/Owner/SubscriptionController.php", "Input::date($request->input('start_date', ''))", 1],
  ["app/Controllers/Owner/SubscriptionController.php", "Input::date($request->input('expire_date', ''))", 1],
  ["app/Controllers/Owner/CustomerController.php", "return Input::date($value);", 1],
  ["app/Controllers/Manager/PayrollController.php", "Input::date($request->input('birth_date', ''))", 1],
  ["app/Controllers/Manager/PayrollController.php", "Input::date($request->input('id_issue_date', ''))", 1],
  ["app/Controllers/Manager/PayrollController.php", "Input::date($request->input('insurance_end_date', ''))", 1],
];
for (const [rel, needle, expected] of CONVERTERS) {
  const found = read(rel).split(needle).length - 1;
  check(
    rel.split("/").pop() + " converts " + needle.replace("Input::date(", "").replace("return ", "").slice(0, 42),
    found === expected,
    "found " + found + ", expected " + expected
  );
}
check(
  "CustomerController::dateOrNull() no longer hardcodes the Gregorian shape",
  !read("app/Controllers/Owner/CustomerController.php").includes("preg_match('/^\\d{4}-\\d{2}-\\d{2}$/'"),
  "that regex accepted 2026-02-30 and refused 1405/08/20"
);

/* =====================================================================
 * D. the picker, driven for real in jsdom
 * =================================================================== */

section("D. assets/js/shamsi-date.js in a real DOM");

const dom = new JSDOM(
  `<!DOCTYPE html><html dir="rtl"><head><title>t</title></head><body class="mng">
     <form>
       <label class="field"><span>از تاریخ</span>
         <input type="text" data-shamsi="1" name="date_from" class="ltr" value="${jmd(1405, 8, 20)}">
       </label>
       <label class="field"><span>تا تاریخ</span>
         <input type="text" data-shamsi="1" name="date_to" class="ltr" value="">
       </label>
     </form>
   </body></html>`,
  { runScripts: "outside-only", pretendToBeVisual: true }
);

dom.window.eval(picker);

const win = dom.window;
const doc = win.document;
const Shamsi = win.ShamsiDate;

check("the script exports window.ShamsiDate", !!Shamsi);
check("it advertises a version", typeof Shamsi.VERSION === "string" && /^\d+\.\d+\.\d+$/.test(Shamsi.VERSION));

const from = doc.querySelector('input[name="date_from"]');
const to = doc.querySelector('input[name="date_to"]');

check("both fields were upgraded on load", from.getAttribute("data-shamsi-ready") === "1" && to.getAttribute("data-shamsi-ready") === "1");
check("the field stays a text input, so no native picker can appear", from.type === "text");
check("the value PHP rendered is left exactly as it was", from.value === jmd(1405, 8, 20), from.value);
check("the Gregorian mirror is filled in on load", from.getAttribute("data-gregorian") === ymd(2026, 11, 11), from.getAttribute("data-gregorian"));
check("an empty field mirrors an empty date", to.getAttribute("data-gregorian") === "");
check("the field reads left to right inside an RTL form", from.style.direction === "ltr");
check("a numeric keypad is requested on a phone", from.getAttribute("inputmode") === "numeric");
check("the browser is told not to autocomplete a date", from.getAttribute("autocomplete") === "off");
check("the field announces a popup", from.getAttribute("aria-haspopup") === "dialog");
check("the placeholder shows the shape, not a date", /^\D+$/.test(from.getAttribute("placeholder") || ""), from.getAttribute("placeholder"));

/* --- the calendar itself --- */

check("toGregorian(1405/8/20) is " + ymd(2026, 11, 11), Shamsi.toGregorian(1405, 8, 20).join(DASH) === ymd(2026, 11, 11), Shamsi.toGregorian(1405, 8, 20).join(","));
check("toJalali(" + ymd(2026, 11, 11) + ") is 1405/8/20", Shamsi.toJalali(2026, 11, 11).join(SLASH) === "1405/8/20", Shamsi.toJalali(2026, 11, 11).join("/"));
check("monthLength(1405, 1) is 31", Shamsi.monthLength(1405, 1) === 31);
check("monthLength(1405, 7) is 30", Shamsi.monthLength(1405, 7) === 30);
check("monthLength(1403, 12) is 30 - a leap Esfand", Shamsi.monthLength(1403, 12) === 30, String(Shamsi.monthLength(1403, 12)));
check("monthLength(1404, 12) is 29 - a short Esfand", Shamsi.monthLength(1404, 12) === 29, String(Shamsi.monthLength(1404, 12)));
check("monthLength refuses month 13", Shamsi.monthLength(1405, 13) === 0);
check("isJalaliDate rejects 31 Mehr", Shamsi.isJalaliDate(1405, 7, 31) === false);
check("isJalaliDate accepts 30 Mehr", Shamsi.isJalaliDate(1405, 7, 30) === true);
check("there are twelve month names", Shamsi.months.length === 12 && Shamsi.months[0] === "فروردین" && Shamsi.months[6] === "مهر");
check("the week starts on Saturday", Shamsi.weekdays[0] === "ش" && Shamsi.weekdays[6] === "ج");

check("parse() reads the field's own shape", Shamsi.parse(jmd(1405, 8, 20)) !== null);
check("parse() reads a dash separator too", Shamsi.parse("1405" + DASH + "08" + DASH + "20") !== null);
check("parse() reads unpadded digits", Shamsi.parse("1405" + SLASH + "8" + SLASH + "2").join(SLASH) === "1405/8/2");
check("parse() folds Persian digits", Shamsi.parse("۱۴۰۵/۰۸/۲۰") !== null, "a pasted value must work");
check("parse() folds Arabic-Indic digits", Shamsi.parse("١٤٠٥/٠٨/٢٠") !== null);
check("parse() refuses an impossible Jalali date", Shamsi.parse(jmd(1405, 7, 31)) === null);
check("parse() refuses a Gregorian year", Shamsi.parse(ymd(2026, 11, 11)) === null, "2026 is not a Jalali year and must not be read as one");
check("parse() refuses junk", Shamsi.parse("garbage") === null && Shamsi.parse("") === null && Shamsi.parse(null) === null);
check("parse() refuses an injected attribute", Shamsi.parse('"><script>alert(1)</script>') === null);
check("format() pads to the field shape", Shamsi.format(1405, 8, 2) === jmd(1405, 8, 2));
check("gregorianOf() mirrors what Input::date() will store", Shamsi.gregorianOf(jmd(1405, 8, 20)) === ymd(2026, 11, 11));

/* --- opening the popup --- */

from.dispatchEvent(new win.Event("focus"));

const popup = doc.querySelector("[data-shamsi-popup]");
check("focusing the field opens a popup", !!popup);
check("the popup is a dialog", popup && popup.getAttribute("role") === "dialog");
check("the popup is open", Shamsi.isOpen() === true);
check("the field says it is expanded", from.getAttribute("aria-expanded") === "true");
check("the popup reads right to left", popup && popup.style.direction === "rtl");

const title = popup && popup.querySelector("[data-shamsi-title]");
check("the title is the Jalali month and year", title && title.textContent === "آبان 1405", title && title.textContent);

const weekdayCells = popup ? [...popup.querySelectorAll("[aria-hidden='true'][title]")] : [];
check("seven weekday headings are rendered", weekdayCells.length === 7, String(weekdayCells.length));
check("the weekday headings are long-named for a screen reader", weekdayCells.length > 0 && weekdayCells[0].getAttribute("title") === "شنبه");

const dayButtons = popup ? [...popup.querySelectorAll("[data-shamsi-day]")] : [];
check("Aban renders all 30 of its days", dayButtons.length === 30, String(dayButtons.length));
check("no day 31 is offered in Aban", dayButtons.every((b) => Number(b.getAttribute("data-shamsi-day")) <= 30));
check("the chosen day is marked selected", dayButtons.some((b) => b.getAttribute("data-shamsi-day") === "20" && b.getAttribute("aria-selected") === "true"));
check("every day cell is a real button with a name", dayButtons.every((b) => b.tagName === "BUTTON" && (b.getAttribute("aria-label") || "").length > 3));

/* The first of Aban 1405 is 2026-11-11 minus 19 days = 2026-10-23, a Friday, so
   in a Saturday-first grid it lands in the last column and six spacers precede
   it. weekdayColumn() is what decides that, and it must agree with the platform. */
check("1 Aban 1405 starts the grid on Friday", Shamsi.weekdayColumn(1405, 8, 1) === 6, String(Shamsi.weekdayColumn(1405, 8, 1)));
check("2 Aban 1405 is a Saturday", Shamsi.weekdayColumn(1405, 8, 2) === 0, String(Shamsi.weekdayColumn(1405, 8, 2)));

const mirror = popup && popup.querySelector("[data-shamsi-mirror]");
check("the popup shows the Gregorian equivalent once, read-only", mirror && mirror.textContent === ymd(2026, 11, 11), mirror && mirror.textContent);
check("the mirror cannot be typed into", mirror && mirror.tagName === "SPAN");

/* --- choosing a day --- */

let changeEvents = 0;
to.addEventListener("change", () => { changeEvents += 1; });

to.dispatchEvent(new win.Event("focus"));
const openPopup = doc.querySelector("[data-shamsi-popup]");
check("one popup serves the whole page", doc.querySelectorAll("[data-shamsi-popup]").length === 1);

const day5 = openPopup.querySelector("[data-shamsi-day='5']");
day5.dispatchEvent(new win.MouseEvent("click", { bubbles: true }));

check("clicking a day writes the Jalali date into the box", to.value === jmd(1405, 8, 5), to.value);
check("the box never holds a Gregorian number", !/20\d\d/.test(to.value), to.value);
check("the Gregorian mirror follows", to.getAttribute("data-gregorian") === ymd(2026, 10, 27), to.getAttribute("data-gregorian"));
check("a change event is dispatched, so other listeners still work", changeEvents === 1, String(changeEvents));
check("the popup closes after a choice", Shamsi.isOpen() === false);

/* --- month navigation --- */

from.dispatchEvent(new win.Event("focus"));
const nav = doc.querySelector("[data-shamsi-popup]");
nav.querySelector("[data-shamsi-next]").dispatchEvent(new win.MouseEvent("click", { bubbles: true }));
check("next moves to Azar", doc.querySelector("[data-shamsi-title]").textContent === "آذر 1405", doc.querySelector("[data-shamsi-title]").textContent);
check("Azar renders 30 days", doc.querySelectorAll("[data-shamsi-day]").length === 30);

doc.querySelector("[data-shamsi-prev]").dispatchEvent(new win.MouseEvent("click", { bubbles: true }));
doc.querySelector("[data-shamsi-prev]").dispatchEvent(new win.MouseEvent("click", { bubbles: true }));
check("previous walks back across a month boundary", doc.querySelector("[data-shamsi-title]").textContent === "مهر 1405", doc.querySelector("[data-shamsi-title]").textContent);

/* Esfand is the month whose length changes, so it gets its own pass. Counting
   clicks from wherever the calendar happens to be open makes the assertion
   depend on the previous test's state; setting the field first does not, because
   the popup opens on the month of the field's value and follows it if it moves
   while open. */
const openOn = (value) => {
  from.value = value;
  from.dispatchEvent(new win.Event("focus"));
  return doc.querySelector("[data-shamsi-popup]");
};
const titleOf = () => doc.querySelector("[data-shamsi-title]").textContent;
const dayCount = () => doc.querySelectorAll("[data-shamsi-day]").length;
const clickNav = (which) => doc.querySelector("[data-shamsi-" + which + "]")
  .dispatchEvent(new win.MouseEvent("click", { bubbles: true }));

openOn(jmd(1403, 12, 1));
check("the calendar opens on the month of the field's value", titleOf() === "اسفند 1403", titleOf());
check("a leap Esfand offers 30 days", dayCount() === 30, String(dayCount()));
check("a leap Esfand offers no 31st", !doc.querySelector("[data-shamsi-day='31']"));

openOn(jmd(1404, 12, 1));
check("a short Esfand offers 29 days", dayCount() === 29, titleOf() + " / " + dayCount());
check("a short Esfand offers no 30th", !doc.querySelector("[data-shamsi-day='30']"));

clickNav("next");
check("next from Esfand crosses the new year", titleOf() === "فروردین 1405", titleOf());
clickNav("prev");
check("previous crosses back into Esfand", titleOf() === "اسفند 1404", titleOf());

openOn(jmd(1405, 1, 1));
check("Farvardin offers 31 days", dayCount() === 31, String(dayCount()));
openOn(jmd(1405, 6, 1));
check("Shahrivar offers 31 days", dayCount() === 31, String(dayCount()));
openOn(jmd(1405, 7, 1));
check("Mehr offers 30 days", dayCount() === 30, String(dayCount()));

openOn(jmd(1405, 8, 20));
check("the calendar is back on the field's own date", titleOf() === "آبان 1405", titleOf());

/* --- today, clear, keyboard --- */

const todayPopup = doc.querySelector("[data-shamsi-popup]");
todayPopup.querySelector("[data-shamsi-today]").dispatchEvent(new win.MouseEvent("click", { bubbles: true }));
const todayValue = from.value;
check("the today button writes a Jalali date", /^\d{4}\/\d{2}\/\d{2}$/.test(todayValue), todayValue);
check("the today button's mirror is Gregorian", /^\d{4}-\d{2}-\d{2}$/.test(from.getAttribute("data-gregorian") || ""), from.getAttribute("data-gregorian"));

from.dispatchEvent(new win.Event("focus"));
doc.querySelector("[data-shamsi-popup] [data-shamsi-clear]").dispatchEvent(new win.MouseEvent("click", { bubbles: true }));
check("the clear button empties the box", from.value === "");
check("the clear button empties the mirror", from.getAttribute("data-gregorian") === "");

from.value = jmd(1405, 8, 20);
from.dispatchEvent(new win.Event("focus"));
doc.dispatchEvent(new win.KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
check("Escape closes without choosing", Shamsi.isOpen() === false && from.value === jmd(1405, 8, 20), from.value);

from.dispatchEvent(new win.Event("focus"));
doc.dispatchEvent(new win.KeyboardEvent("keydown", { key: "ArrowDown", bubbles: true }));
const afterArrow = doc.querySelector("[data-shamsi-title]").textContent;
check("the arrow keys move a cursor inside the calendar", afterArrow.length > 0, afterArrow);
doc.dispatchEvent(new win.KeyboardEvent("keydown", { key: "Enter", bubbles: true }));
check("Enter chooses the day under the cursor", /^\d{4}\/\d{2}\/\d{2}$/.test(from.value) && Shamsi.isOpen() === false, from.value);

/* --- typed values --- */

to.value = "1405" + DASH + "8" + DASH + "2";
to.dispatchEvent(new win.Event("blur"));
check("a typed value is normalised on the way out", to.value === jmd(1405, 8, 2), to.value);
check("the normalised value carries its mirror", to.getAttribute("data-gregorian") === ymd(2026, 10, 24), to.getAttribute("data-gregorian"));

to.value = "۱۴۰۵/۰۸/۲۰";
to.dispatchEvent(new win.Event("blur"));
check("typed Persian digits are folded to the stored shape", to.value === jmd(1405, 8, 20), to.value);

to.value = jmd(1405, 7, 31);
to.dispatchEvent(new win.Event("blur"));
check("an impossible typed date is not posted", to.value !== jmd(1405, 7, 31), to.value);
check("it falls back to the last good value", to.value === jmd(1405, 8, 20), to.value);

to.value = "";
to.dispatchEvent(new win.Event("blur"));
check("clearing a field by hand is allowed", to.value === "" && to.getAttribute("data-gregorian") === "");

/* --- the owner theme --- */

const ownerDom = new JSDOM(
  `<!DOCTYPE html><html dir="rtl"><body class="o-body">
     <label class="o-field"><span>تاریخ شروع</span>
       <input type="text" data-shamsi="1" name="start_date" class="o-ltr" value="${jmd(1405, 8, 20)}">
     </label></body></html>`,
  { runScripts: "outside-only", pretendToBeVisual: true }
);
ownerDom.window.eval(picker);
const ownerField = ownerDom.window.document.querySelector('input[name="start_date"]');
check("the owner panel's field is upgraded by the same script", ownerField.getAttribute("data-shamsi-ready") === "1");
ownerField.dispatchEvent(new ownerDom.window.Event("focus"));
const ownerPopup = ownerDom.window.document.querySelector("[data-shamsi-popup]");
check("the owner panel gets a popup too", !!ownerPopup && ownerDom.window.ShamsiDate.isOpen());
check("the owner popup has a visible background", ownerPopup.style.background !== "" && ownerPopup.style.background !== "transparent", ownerPopup.style.background);
check("the owner popup has a visible border colour", /rgb|#|rgba/.test(ownerPopup.style.border), ownerPopup.style.border);

/* The popup floats over the page, so a translucent panel token would let the
   content behind it show through the calendar - hence opaque() in the script. */
check("the manager popup has a background of its own", popup.style.background !== "", popup.style.background);
check("the popup border is set from a token", popup.style.border.includes("solid"), popup.style.border);
check("the popup is above the panel's content", Number(popup.style.zIndex) > 100, popup.style.zIndex);

/* =====================================================================
 * E. the browser's calendar matches the platform's own Persian calendar
 * =================================================================== */

section("E. 20000 days against the platform's Persian calendar");

let intl = null;
try {
  intl = new Intl.DateTimeFormat("en-u-ca-persian", {
    year: "numeric", month: "2-digit", day: "2-digit", timeZone: "UTC",
  });
  intl.formatToParts(new Date(Date.UTC(2026, 10, 11)));
} catch (error) {
  intl = null;
}

if (!intl) {
  console.log("   (this Node build has no ICU Persian calendar - skipping the cross-check)");
  check("the cross-check needs a platform Persian calendar", true);
} else {
  const DAYS = 20000;
  const startUtc = Date.UTC(1970, 0, 1);
  let forwardFails = 0;
  let backwardFails = 0;
  let lengthFails = 0;
  let weekdayFails = 0;
  const firstForward = [];
  const firstBackward = [];

  const persianParts = (ms) => {
    const parts = intl.formatToParts(new Date(ms));
    return {
      year: Number(parts.find((p) => p.type === "year").value),
      month: Number(parts.find((p) => p.type === "month").value),
      day: Number(parts.find((p) => p.type === "day").value),
    };
  };

  for (let i = 0; i < DAYS; i++) {
    const ms = startUtc + i * 86400000;
    const when = new Date(ms);
    const gy = when.getUTCFullYear();
    const gm = when.getUTCMonth() + 1;
    const gd = when.getUTCDate();

    const reference = persianParts(ms);
    const mine = Shamsi.toJalali(gy, gm, gd);

    if (mine[0] !== reference.year || mine[1] !== reference.month || mine[2] !== reference.day) {
      forwardFails += 1;
      if (firstForward.length < 3) firstForward.push(ymd(gy, gm, gd) + " -> " + mine.join("/") + " vs " + [reference.year, reference.month, reference.day].join("/"));
    }

    const back = Shamsi.toGregorian(reference.year, reference.month, reference.day);
    if (back[0] !== gy || back[1] !== gm || back[2] !== gd) {
      backwardFails += 1;
      if (firstBackward.length < 3) firstBackward.push([reference.year, reference.month, reference.day].join("/") + " -> " + back.join("-") + " vs " + ymd(gy, gm, gd));
    }

    /* The weekday column, against the platform's own weekday. */
    const column = Shamsi.weekdayColumn(reference.year, reference.month, reference.day);
    const expectedColumn = (when.getUTCDay() + 1) % 7;
    if (column !== expectedColumn) weekdayFails += 1;

    /* Month length, against the platform: the last day of a month is the day
       before the first of the next. */
    if (reference.day === 1 && i > 0) {
      const previous = persianParts(ms - 86400000);
      const expectedLength = Shamsi.monthLength(previous.year, previous.month);
      if (expectedLength !== previous.day) {
        lengthFails += 1;
      }
    }
  }

  check("toJalali() agrees with the platform for " + DAYS + " days", forwardFails === 0, firstForward.join(" | "));
  check("toGregorian() agrees with the platform for " + DAYS + " days", backwardFails === 0, firstBackward.join(" | "));
  check("monthLength() agrees with the platform for every month in the window", lengthFails === 0, lengthFails + " month(s)");
  check("weekdayColumn() agrees with the platform's weekday", weekdayFails === 0, weekdayFails + " day(s)");

  /* And the round trip in both directions, which is what the field depends on:
     a value written by the picker must be read back identically by PHP. */
  let roundTripFails = 0;
  for (let jy = 1390; jy <= 1410; jy++) {
    for (let jm = 1; jm <= 12; jm++) {
      const length = Shamsi.monthLength(jy, jm);
      for (let jd = 1; jd <= length; jd++) {
        const gregorian = Shamsi.toGregorian(jy, jm, jd);
        const back = Shamsi.toJalali(gregorian[0], gregorian[1], gregorian[2]);
        if (back[0] !== jy || back[1] !== jm || back[2] !== jd) roundTripFails += 1;
      }
    }
  }
  check("every Jalali day of 1390..1410 survives the round trip", roundTripFails === 0, roundTripFails + " day(s)");

  /* The pair from the report that started this stage. */
  check(
    "the field's " + jmd(1405, 8, 20) + " is the " + ymd(2026, 11, 11) + " the browser used to show",
    Shamsi.toGregorian(1405, 8, 20).join(DASH) === ymd(2026, 11, 11)
      && persianParts(Date.UTC(2026, 10, 11)).year === 1405
      && persianParts(Date.UTC(2026, 10, 11)).month === 8
      && persianParts(Date.UTC(2026, 10, 11)).day === 20
  );
}

/* The PHP source the port was copied from must still say the same thing. This
   is a textual check, not a reimplementation: if the two files drift, one of
   them is wrong and this fails. */
const phpToGregorian = jalali.slice(jalali.indexOf("public static function toGregorian"), jalali.indexOf("public static function monthLength"));
const jsToGregorian = picker.slice(picker.indexOf("function toGregorian"), picker.indexOf("function monthLength"));
const phpSteps = ["+= 1595", "365 * $jy", "intdiv($jy, 33) * 8", "($jy % 33) + 3, 4", "146097", "36524", "1461", "365"];
const jsSteps = ["+= 1595", "365 * jy", "intdiv(jy, 33) * 8", "(jy % 33) + 3, 4", "146097", "36524", "1461", "365"];
check("both toGregorian() implementations carry every step", phpSteps.every((s) => phpToGregorian.includes(s)) && jsSteps.every((s) => jsToGregorian.includes(s)));
check("both use the same month table", phpToGregorian.includes("[0, 31, $february, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]")
  && jsToGregorian.includes("[0, 31, february, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]"));

/* =====================================================================
 * F. both panels load the picker, and no stylesheet had to change
 * =================================================================== */

section("F. loading, versioning and the stylesheets");

const layoutApp = read("views/layouts/app.php");
const layoutOwner = read("views/layouts/owner.php");

check("the manager layout loads the picker", layoutApp.includes("$asset('js/shamsi-date.js', '1.0.0')"));
check("the owner layout loads the picker", layoutOwner.includes("$asset('js/shamsi-date.js', '1.0.0')"));
check("the picker loads before manager.js", layoutApp.indexOf("shamsi-date.js") < layoutApp.indexOf("js/manager.js"));
check("the picker loads before owner.js", layoutOwner.indexOf("shamsi-date.js") < layoutOwner.indexOf("js/owner.js"));
check("both scripts stay deferred", /<script src="<\?= \$e\(\$asset\('js\/shamsi-date\.js', '1\.0\.0'\)\) \?>" defer><\/script>/.test(layoutApp));
check("the version goes through $asset(), never a hand written query", layoutApp.includes("$asset('js/shamsi-date.js', '") && !layoutApp.includes("shamsi-date.js?v="));
check("manager.js moved to a new version because it changed", layoutApp.includes("$asset('js/manager.js', '1.4.0')"));

/* The picker is styled inline from the tokens that already exist, so no
   stylesheet changed and no cached CSS has to be invalidated. */
const url = read("app/Support/Url.php");
check(
  "Url::CSS_VERSION is untouched at 1.2.1 - no stylesheet changed in this stage",
  /public\s+const\s+CSS_VERSION\s*=\s*'1\.2\.1'/.test(url)
);

const stylesheets = ["assets/css/app.css", "assets/css/manager.css", "assets/css/owner.css"];
for (const rel of stylesheets) {
  const source = read(rel);
  check(rel + " defines no shamsi rule of its own", !/shamsi/i.test(source), "the popup is styled inline on purpose");
}

const TOKENS = ["--m-surface-2", "--m-line-strong", "--m-text", "--m-dim", "--m-radius-sm", "--o-panel-2", "--o-line", "--o-text", "--o-muted", "--o-brand", "--o-radius-sm"];
for (const token of TOKENS) {
  const defined = stylesheets.some((rel) => read(rel).includes(token + ":"));
  check("the picker only reads tokens that exist: " + token, defined);
}

/* Every colour the popup uses has a literal fallback, so a missing variable
   degrades to something readable instead of transparent-on-transparent - which
   is exactly the bug the stage 24 contrast fix was about. */
const cssVarCalls = picker.match(/cssVar\('--[a-z0-9-]+',\s*'[^']+'\)/g) || [];
check("every cssVar() call carries a literal fallback", cssVarCalls.length >= 10, String(cssVarCalls.length));
check("the picker asks for no token it does not fall back from", !/cssVar\('--[a-z0-9-]+'\)/.test(picker));
check("a translucent panel token is made opaque before it floats over the page", picker.includes("function opaque("));

/* Stage 24's other fix - the Telegram preview box, which asked two stylesheets
   for --surface-2 and --border and got white on white (1.10:1) because neither
   token is defined anywhere - must survive this stage. */
const telegram = viewSources.get("views/manager/telegram/index.php");
const preview = telegram.match(/<pre[^>]*>/);
check("the Telegram preview still exists", preview !== null);
check("the preview does not ask for the undefined --surface-2", preview && !preview[0].includes("var(--surface-2"), preview && preview[0]);
check("the preview does not ask for the undefined --border", preview && !preview[0].includes("var(--border)"));
check("the preview uses the tokens that exist", preview && preview[0].includes("--m-surface-2") && preview[0].includes("--m-text"));

function contrastOf(foreground, background) {
  const channel = (value) => {
    const v = value / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  const luminance = (rgb) => 0.2126 * channel(rgb[0]) + 0.7152 * channel(rgb[1]) + 0.0722 * channel(rgb[2]);
  const [a, b] = [luminance(foreground), luminance(background)].sort((x, y) => y - x);
  return (a + 0.05) / (b + 0.05);
}
/* --m-text #e8ecf8 on the --m-surface-2 fallback #151b30 the preview carries. */
const previewContrast = contrastOf([0xe8, 0xec, 0xf8], [0x15, 0x1b, 0x30]);
check(
  "the preview's contrast is still at least AA (was 1.10:1)",
  previewContrast >= 4.5,
  previewContrast.toFixed(2) + ":1"
);

/* =====================================================================
 * G. the views render Jalali, and the owner panel matches
 * =================================================================== */

section("G. what the views actually print");

/* A Jalali field whose value came straight from the database would show a
   Gregorian date in a Jalali box - the exact failure this stage exists to
   remove. Every value has to pass through Jalali. */
const UNCONVERTED = [];
for (const [rel, count] of shamsiByFile) {
  const source = viewSources.get(rel);
  const tags = source.match(/<input[^>]*data-shamsi="1"[^>]*>/g) || [];
  if (tags.length !== count) UNCONVERTED.push(rel + ": tag scan found " + tags.length + " of " + count);

  for (const tag of tags) {
    const value = tag.match(/value="<\?=\s*(.*?)\s*\?>"/s);
    if (!value) continue;                       /* an empty field, e.g. a payroll extra */
    const expression = value[1];
    const ok = /Jalali::input\(/.test(expression) || /Jalali::today\(\)/.test(expression);
    if (!ok) UNCONVERTED.push(rel + ": " + expression.slice(0, 70));
  }
}
check("every Jalali field renders its value through Jalali", UNCONVERTED.length === 0, UNCONVERTED.join(" | "));

/* The whole-file tag scan is quote aware for a reason: these attributes contain
   PHP, and the `>` of a `?>` ends a naive regex match long before the tag does.
   Re-derive the count the same way and compare with the simple grep above. */
function countShamsiTags(source) {
  let count = 0;
  let index = 0;
  while (true) {
    const start = source.indexOf("<input", index);
    if (start < 0) break;

    let cursor = start + 6;
    let quote = null;
    let end = -1;

    while (cursor < source.length) {
      const char = source[cursor];
      if (quote) {
        if (char === quote) quote = null;
      } else if (char === '"' || char === "'") {
        quote = char;
      } else if (char === ">") {
        end = cursor;
        break;
      }
      cursor += 1;
    }

    if (end < 0) break;
    if (source.slice(start, end).includes('data-shamsi="1"')) count += 1;
    index = end + 1;
  }
  return count;
}

let quoteAwareTotal = 0;
for (const [, source] of viewSources) quoteAwareTotal += countShamsiTags(source);
check(
  "a quote-aware scan finds the same " + SHAMSI_FIELDS + " fields",
  quoteAwareTotal === SHAMSI_FIELDS,
  "found " + quoteAwareTotal + " - the two scans disagree, so one of them is lying"
);

/* Fields PHP renders with today's date used to call date('Y-m-d') straight into
   the value. That would print a Gregorian date into a Jalali box. */
let rawToday = 0;
for (const [rel, source] of viewSources) {
  for (const tag of source.match(/<input[^>]*data-shamsi="1"[^>]*>/g) || []) {
    if (/value="<\?=\s*\$e\(date\('Y-m-d'\)\)/.test(tag)) rawToday += 1;
  }
}
check("no field prints date('Y-m-d') into a Jalali box", rawToday === 0, rawToday + " field(s)");

/* The manager panel already rendered every date through Jalali. The owner panel
   did not - it printed stored dates raw - and a Jalali field next to a Gregorian
   table cell for the same row would be worse than either alone. */
const ownerViews = views.filter((rel) => rel.startsWith("views/owner/"));
let rawOwnerDates = [];
for (const rel of ownerViews) {
  const source = viewSources.get(rel);
  for (const line of source.split("\n")) {
    if (!/created_at|start_date|expire_date/.test(line)) continue;
    if (line.includes("Jalali::")) continue;
    if (/option value=/.test(line)) continue;      /* a SQL ORDER BY key, not a display */
    if (/data-shamsi/.test(line)) continue;        /* the field itself, checked above */
    if (/name="(start_date|expire_date)"/.test(line)) continue;
    rawOwnerDates.push(rel + ": " + line.trim().slice(0, 90));
  }
}
check(
  "the owner panel renders its dates through Jalali too",
  rawOwnerDates.length === 0,
  rawOwnerDates.slice(0, 4).join(" | ")
);

/* DATETIME columns need the date AND time form; a DATE column must not get it. */
let dateTimeUse = 0;
let dateUse = 0;
for (const rel of ownerViews) {
  const source = viewSources.get(rel);
  dateTimeUse += (source.match(/Jalali::dateTime\(/g) || []).length;
  dateUse += (source.match(/Jalali::date\(/g) || []).length;
}
check("owner timestamps go through Jalali::dateTime()", dateTimeUse >= 10, String(dateTimeUse));
check("owner dates go through Jalali::date()", dateUse >= 8, String(dateUse));

/* Every view that calls Jalali has to import it - the owner views never did
   before this stage, and a missing import is a fatal at render time, not a
   warning. */
let missingImport = [];
for (const [rel, source] of viewSources) {
  const usesJalali = /Jalali::(date|input|today|dateTime|time|longDate|monthLabel)\(/.test(source);
  const imports = source.includes("use App\\Support\\Jalali;") || source.includes("\\App\\Support\\Jalali::");
  if (usesJalali && !imports) missingImport.push(rel);
}
check("every view that calls Jalali imports it", missingImport.length === 0, missingImport.join(", "));

let unusedImport = [];
for (const [rel, source] of viewSources) {
  if (source.includes("use App\\Support\\Jalali;") && !/Jalali::/.test(source)) unusedImport.push(rel);
}
check("no view imports Jalali without using it", unusedImport.length === 0, unusedImport.join(", "));

/* =====================================================================
 * Summary
 * =================================================================== */

console.log("\nSTAGE 25 checks passed: " + passed + ", failed: " + failures.length);

if (failures.length > 0) {
  console.log("\nFailures:");
  for (const failure of failures) console.log("  ✗ " + failure);
  process.exit(1);
}
