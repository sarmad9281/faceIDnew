// Run with Node; no server, browser, database or PHP binary required.
//
// STAGE 24 - the Telegram preview box is readable again, and every date field
// of the manager panel says what its date means in the Persian calendar.
//
//   node tests/stage24_jalali_date_hint_test.js
//
// Two bugs, one root cause each:
//
//   1. The daily-report preview asked the stylesheet for --surface-2 and
//      --border. NO stylesheet of this project defines either, so the light
//      fallbacks (#f6f7f9 / #e3e6ea) always won - while the text inherited the
//      dark theme's near-white --m-text. White on white: 1.10:1. The box now
//      uses the --m-* tokens that really exist, with dark fallbacks, and this
//      suite computes the contrast from the real token values instead of
//      trusting a comment.
//   2. A date field showed 2026-11-03 to somebody who thinks ۱۴۰۵/۰۸/۱۲. Only
//      the employee form carried the equivalence. All 29 date inputs of the
//      manager panel now render it in PHP (correct with JavaScript off) and
//      assets/js/manager.js keeps it in sync while the picker moves.
//
// The script's calendar port is not taken on faith: section E runs it against a
// table produced by the real PHP Jalali class and, when the platform has ICU,
// against the browser engine's own Persian calendar for 20000 consecutive days.

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
 * Small helpers shared by the sections below
 * =================================================================== */

/** Every .php file under views/manager, plus the layout they render inside. */
function managerViews() {
  const out = [];
  const walk = (dir) => {
    for (const entry of fs.readdirSync(path.join(ROOT, dir), { withFileTypes: true })) {
      const rel = dir + "/" + entry.name;
      if (entry.isDirectory()) walk(rel);
      else if (entry.name.endsWith(".php")) out.push(rel);
    }
  };
  walk("views/manager");
  return out.sort();
}

/**
 * The stylesheet text a manager page can rely on: every file in assets/css plus
 * the <style> blocks the views carry themselves (the installer keeps its own
 * rules inline). Used to decide whether a class or a custom property exists.
 */
function styleText() {
  let css = "";
  for (const file of fs.readdirSync(path.join(ROOT, "assets/css"))) {
    if (file.endsWith(".css")) css += read("assets/css/" + file) + "\n";
  }
  for (const view of managerViews().concat(["views/layouts/app.php"])) {
    const source = read(view);
    for (const match of source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)) css += match[1] + "\n";
  }
  return css;
}

/** #rgb / #rrggbb / rgba(r, g, b, a) -> [r, g, b, a] in 0..255 (a in 0..1). */
function parseColor(text) {
  const value = String(text).trim();
  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value);
  if (hex) {
    let digits = hex[1];
    if (digits.length === 3) digits = digits.split("").map((c) => c + c).join("");
    return [
      parseInt(digits.slice(0, 2), 16),
      parseInt(digits.slice(2, 4), 16),
      parseInt(digits.slice(4, 6), 16),
      1,
    ];
  }
  const rgb = /^rgba?\(([^)]+)\)$/i.exec(value);
  if (rgb) {
    const parts = rgb[1].split(",").map((part) => parseFloat(part));
    return [parts[0], parts[1], parts[2], parts.length > 3 ? parts[3] : 1];
  }
  throw new Error("cannot parse colour: " + text);
}

/** Paint `over` on top of `base` and return the opaque result. */
function composite(over, base) {
  const alpha = over[3];
  return [
    over[0] * alpha + base[0] * (1 - alpha),
    over[1] * alpha + base[1] * (1 - alpha),
    over[2] * alpha + base[2] * (1 - alpha),
    1,
  ];
}

/** WCAG 2.1 relative luminance. */
function luminance(color) {
  const channel = (value) => {
    const s = value / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * channel(color[0]) + 0.7152 * channel(color[1]) + 0.0722 * channel(color[2]);
}

/** WCAG 2.1 contrast ratio, 1..21. */
function contrast(one, two) {
  const a = luminance(one);
  const b = luminance(two);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/** The value of a custom property in manager.css's :root block. */
const managerCss = read("assets/css/manager.css");
const rootStart = managerCss.indexOf(":root {");
const rootBlock = managerCss.slice(rootStart, managerCss.indexOf("}", rootStart));
function token(name) {
  const match = new RegExp("--" + name + ":\\s*([^;]+);").exec(rootBlock);
  if (!match) throw new Error("manager.css does not define --" + name);
  return match[1].trim();
}

/**
 * Quote aware scan of every <input ...> tag, because these tags carry a PHP
 * echo inside an attribute (value="<?= $e($filters['from']) ?>"): a naive
 * /<input[^>]*>/ stops at the ">" of "?>" and reads half the tag.
 */
function inputTags(source) {
  const found = [];
  let cursor = 0;
  for (;;) {
    const start = source.indexOf("<input", cursor);
    if (start < 0) return found;
    let index = start + "<input".length;
    let quote = null;
    while (index < source.length) {
      const char = source[index];
      if (quote !== null) {
        if (char === quote) quote = null;
      } else if (char === '"' || char === "'") {
        quote = char;
      } else if (char === ">") {
        break;
      }
      index += 1;
    }
    if (index >= source.length) throw new Error("unterminated <input> tag at " + start);
    const end = index + 1;
    found.push({ start: start, end: end, tag: source.slice(start, end) });
    cursor = end;
  }
}

/** The arguments of a call whose "(" is at `open`, paren and quote aware. */
function callArguments(source, open) {
  let depth = 0;
  let quote = null;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (quote !== null) {
      if (char === quote && source[index - 1] !== "\\") quote = null;
      continue;
    }
    if (char === "'" || char === '"') {
      quote = char;
      continue;
    }
    if (char === "(") depth += 1;
    else if (char === ")") {
      depth -= 1;
      if (depth === 0) return source.slice(open + 1, index);
    }
  }
  return null;
}

/* =====================================================================
 * A. The preview box: dark on dark is gone
 * =================================================================== */

section("A. The Telegram preview box is readable");

const telegram = read("views/manager/telegram/index.php");
const preMatches = telegram.match(/<pre\b[^>]*>/g) || [];
const preTag = preMatches[0] || "";

check("the daily report still previews in exactly one <pre>", preMatches.length === 1, "found " + preMatches.length);
check("the box asks for the real surface token", preTag.includes("background:var(--m-surface-2,#151b30)"), preTag);
check("the box sets its own text colour", preTag.includes("color:var(--m-text,#e8ecf8)"), preTag);
check("the border is the soft strong line of the design system", preTag.includes("border:1px solid var(--m-line-strong,rgba(255,255,255,.16))"), preTag);
check("the corner radius comes from the design system", preTag.includes("border-radius:var(--m-radius-sm,12px)"), preTag);
check("it no longer asks for --surface-2, which nothing defines", !preTag.includes("--surface-2"));
check("it no longer asks for --border, which nothing defines", !preTag.includes("var(--border"));
check("no light fallback survived", !preTag.includes("#f6f7f9") && !preTag.includes("#e3e6ea"));
check(
  "the invented ltr-reset class is gone from the markup (it survives only in the comment that explains it)",
  !/class="[^"]*ltr-reset/.test(telegram) && !/<pre[^>]*\bclass=/.test(telegram)
);
check("the <pre> carries no class attribute at all now", !/class=/.test(preTag), preTag);
check("long words wrap instead of overflowing the card", preTag.includes("white-space:pre-wrap") && preTag.includes("overflow-wrap:anywhere"));
check("the preview stays right to left", preTag.includes('dir="rtl"'));
check("the message is still escaped on the way out", telegram.includes("><?= $e((string) ($dailyPreview['message'] ?? '')) ?></pre>"));
check("the fix says why the old one was broken", telegram.includes("STAGE 24 - contrast fix"));

/* The contrast is computed from the REAL token values, through the layers the
   box actually sits on: the page gradient's lightest highlight, the card
   surface, then the box surface. Nothing here trusts a comment. */
const pageBase = composite(parseColor("rgba(79, 125, 255, 0.20)"), parseColor(token("m-bg")));
const cardSurface = composite(parseColor(token("m-surface")), pageBase);
const boxSurface = composite(parseColor(token("m-surface-2")), cardSurface);
const boxText = parseColor(token("m-text"));

const tokenContrast = contrast(boxText, boxSurface);
check(
  "text on the token surface is AAA (>= 7:1)",
  tokenContrast >= 7,
  tokenContrast.toFixed(2) + ":1"
);
console.log("      --m-text on --m-surface-2 over the card: " + tokenContrast.toFixed(2) + ":1");

const fallbackContrast = contrast(parseColor("#e8ecf8"), parseColor("#151b30"));
check(
  "text on the inline fallback is AAA too (stylesheet missing or cached old)",
  fallbackContrast >= 7,
  fallbackContrast.toFixed(2) + ":1"
);
console.log("      fallback #e8ecf8 on #151b30:            " + fallbackContrast.toFixed(2) + ":1");

/* Prove the bug was real: the old combination, measured the same way. */
const oldContrast = contrast(parseColor(token("m-text")), parseColor("#f6f7f9"));
check(
  "the old box really was unreadable (< 4.5:1), so this was a bug and not a taste call",
  oldContrast < 4.5,
  oldContrast.toFixed(2) + ":1"
);
console.log("      the old #f6f7f9 box with --m-text:      " + oldContrast.toFixed(2) + ":1");

/* =====================================================================
 * B. Nothing else in the panel points at a variable or class that is not there
 * =================================================================== */

section("B. No undefined custom property, no invented class");

const css = styleText();

const definedVars = new Set();
for (const match of css.matchAll(/(--[A-Za-z0-9-]+)\s*:/g)) definedVars.add(match[1]);

/* Set at runtime by the kiosk and landing scripts, never by a stylesheet. */
const RUNTIME_VARS = ["--k-face-left", "--k-face-width", "--fw-face-left", "--fw-face-width", "--ry", "--rx"];

const views = managerViews().concat(["views/layouts/app.php"]);
const undefinedVars = [];
const undefinedClasses = [];
const definedClasses = new Set();
for (const match of css.matchAll(/\.([A-Za-z][A-Za-z0-9_-]*)/g)) definedClasses.add(match[1]);

for (const view of views) {
  const source = read(view);
  for (const match of source.matchAll(/var\((--[A-Za-z0-9-]+)/g)) {
    const name = match[1];
    if (!definedVars.has(name) && !RUNTIME_VARS.includes(name)) undefinedVars.push(view + " -> " + name);
  }
}

/* Classes are judged on views/manager alone. The layout carries one pre-existing
   token that no stylesheet defines ("m-brand-logo", next to the "brand-logo"
   that does); it predates this stage and fixing it would mean editing a
   stylesheet, i.e. bumping Url::CSS_VERSION - out of scope for a contrast fix
   that deliberately changes no CSS. Only static class attributes are read: a
   class printed by PHP (class="<?= ... ?>") is decided at runtime. */
for (const view of managerViews()) {
  const source = read(view);
  for (const match of source.matchAll(/class="([^"<?]*)"/g)) {
    for (const name of match[1].split(/\s+/)) {
      if (name && !definedClasses.has(name)) undefinedClasses.push(view + " -> " + name);
    }
  }
}

check(
  "every custom property a manager view reads is defined somewhere",
  undefinedVars.length === 0,
  undefinedVars.join(", ")
);
check(
  "every static class a manager view uses exists in a stylesheet",
  undefinedClasses.length === 0,
  undefinedClasses.join(", ")
);

/* The tokens the new inline styles lean on must keep existing. */
for (const name of ["m-surface-2", "m-text", "m-line-strong", "m-radius-sm", "m-dim"]) {
  check("manager.css still defines --" + name, new RegExp("--" + name + "\\s*:").test(rootBlock));
}
check("manager.css still has the .field-hint rule the hint is built on", /\.field-hint\s*\{/.test(managerCss));
check("manager.css still has the .num rule", /\.num\s*\{/.test(managerCss));

/* =====================================================================
 * C. The helper that renders the hint
 * =================================================================== */

section("C. App\\Support\\JalaliHint");

const helper = read("app/Support/JalaliHint.php");

check("the helper is a final class", helper.includes("final class JalaliHint"));
check("it lives in App\\Support", helper.includes("namespace App\\Support;"));
check("it declares strict types", helper.includes("declare(strict_types=1);"));
check("it is a view helper that owns its markup", helper.includes("public static function render($value, bool $compact = false): string"));
check("label() is public so the script's contract can be tested against it", helper.includes("public static function label($value): string"));

check("the conversion is delegated to Jalali, not reimplemented", helper.includes("Jalali::date($value)"));
check(
  "only a real date is accepted, so a stray query value can never be echoed back",
  helper.includes("preg_match('#^\\d{4}/\\d{2}/\\d{2}$#', $converted) !== 1")
);
check("the output is escaped", helper.includes("htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')"));

check("the hook the script looks for is on the span", helper.includes('data-jalali-hint'));
check("the span uses only classes that exist", helper.includes('class="field-hint num"'));
check("no new class was invented for it", !helper.includes("jalali-hint\"") && !/class="[^"]*jalali/.test(helper));
check(
  "the hint is kept out of the input's accessible name",
  helper.includes('aria-hidden="true"') && helper.includes("accessible NAME")
);
check(
  "the colour is --m-dim (7.7:1), not the .field-hint default --m-faint (4.2:1)",
  helper.includes("color:var(--m-dim,#9aa6c8)") && !helper.includes("--m-faint,")
);

/* An empty hint has to disappear twice over: [hidden]{display:none} comes from
   the user agent sheet and loses to any inline display. */
check(
  "an empty hint is hidden by attribute AND by inline display",
  helper.includes('" hidden></span>') && helper.includes("self::style('none')")
);
check("a filled hint is displayed", helper.includes("self::style('block')"));

check("the compact variant is marked for the script", helper.includes('data-jalali-compact="1"'));
check(
  "the compact tooltip is derived from PREFIX so the two cannot drift",
  helper.includes('rtrim(self::PREFIX, ": \\t\\n\\r")')
);

/* The hint colour, measured: --m-dim on the card surface. AA for small text is
   4.5:1; 6.9:1 passes it with room to spare and sits just under the 7:1 AAA
   line, because the card floats over the page's blue highlight rather than over
   flat black. What matters is the distance from the default. */
const hintContrast = contrast(parseColor(token("m-dim")), cardSurface);
check("the hint text passes AA on the card (>= 4.5:1)", hintContrast >= 4.5, hintContrast.toFixed(2) + ":1");
console.log("      --m-dim on the card surface:            " + hintContrast.toFixed(2) + ":1");
const faintContrast = contrast(parseColor(token("m-faint")), cardSurface);
check(
  "and the .field-hint default really would have been below AA (< 4.5:1)",
  faintContrast < 4.5,
  faintContrast.toFixed(2) + ":1"
);
check(
  "the chosen colour is at least 1.5x the contrast of that default",
  hintContrast / faintContrast >= 1.5,
  (hintContrast / faintContrast).toFixed(2) + "x"
);
console.log("      --m-faint (the default) would have been: " + faintContrast.toFixed(2) + ":1");

/* =====================================================================
 * D. Every date field of the manager panel is paired with its hint
 * =================================================================== */

section("D. All date fields of the manager panel carry the hint");

const PREFIX_CALL = "JalaliHint::render(";
let totalInputs = 0;
let totalHints = 0;
const unpaired = [];
const mismatched = [];
const notImported = [];
const duplicated = [];

for (const view of managerViews()) {
  const source = read(view);
  const dates = inputTags(source).filter((entry) => /type="date"/.test(entry.tag));
  if (dates.length === 0) continue;

  totalInputs += dates.length;

  const calls = source.split(PREFIX_CALL).length - 1;
  totalHints += calls;

  if (calls !== dates.length) duplicated.push(view + " (" + dates.length + " inputs, " + calls + " calls)");

  if (source.includes(PREFIX_CALL) && !source.includes("use App\\Support\\JalaliHint;")) {
    notImported.push(view);
  }

  /* The hand written hint the employee form used to carry must be gone, or the
     field would show the date twice. */
  if (/class="field-hint">معادل شمسی:/.test(source)) duplicated.push(view + " still has a hand written hint");

  for (const entry of dates) {
    /* The call has to start exactly where the tag ends: that is what makes the
       span the input's next element sibling, which is how the script pairs them
       without an id (names repeat on one page - "date_from" appears twice on
       the attendance screen). */
    const after = source.slice(entry.end, entry.end + PREFIX_CALL.length + 80);
    if (!after.startsWith("<?= " + PREFIX_CALL)) {
      unpaired.push(view + " -> " + entry.tag.slice(0, 70));
      continue;
    }

    const open = entry.end + ("<?= " + PREFIX_CALL).length - 1;
    const args = callArguments(source, source.indexOf("(", open));
    if (args === null) {
      unpaired.push(view + " -> unbalanced call");
      continue;
    }

    const compact = /,\s*true\s*$/.test(args);
    const argument = args.replace(/,\s*true\s*$/, "").trim();

    /* The label must be built from the SAME expression as the field's value,
       otherwise the hint can show another day than the one being submitted. */
    const valueMatch = /value="<\?=\s*([\s\S]*?)\s*\?>"/.exec(entry.tag);
    let expected = "''";
    if (valueMatch) {
      expected = valueMatch[1].trim();
      if (expected.startsWith("$e(") && expected.endsWith(")")) expected = expected.slice(3, -1).trim();
      expected = expected.replace(/^\(string\)\s*/, "");
    }

    if (argument !== expected) {
      mismatched.push(view + " -> render(" + argument + ") but value is " + expected);
    }

    /* Only the tight inline forms of a table row may use the compact variant. */
    const inlineRow = /class="input ltr"/.test(entry.tag);
    if (compact !== inlineRow) {
      mismatched.push(view + " -> compact=" + compact + " but inline row=" + inlineRow);
    }
  }
}

check("the panel has the date fields this stage was written for", totalInputs === 29, "found " + totalInputs);
check("and exactly one hint call per field", totalHints === totalInputs, totalInputs + " inputs, " + totalHints + " calls");
check("every hint call sits immediately after its input tag", unpaired.length === 0, unpaired.join(" | "));
check("every hint is built from the field's own value expression", mismatched.length === 0, mismatched.join(" | "));
check("every view that calls the helper imports it", notImported.length === 0, notImported.join(", "));
check("no field shows the date twice", duplicated.length === 0, duplicated.join(" | "));

/* The three fields that live in a table row keep their row narrow. */
const compactSites = [];
for (const view of managerViews()) {
  const source = read(view);
  for (const match of source.matchAll(/JalaliHint::render\(([^)]*(?:\([^)]*\)[^)]*)*),\s*true\)/g)) {
    compactSites.push(view);
  }
}
check("the holiday and the two leave rows use the compact hint", compactSites.length === 3, compactSites.join(", "));

/* =====================================================================
 * E. The script: same calendar as the server, and it follows the picker
 * =================================================================== */

section("E. assets/js/manager.js keeps the hint in sync");

const manager = read("assets/js/manager.js");
const jalaliStart = manager.indexOf("var JALALI_PREFIX = ");
const jalaliEnd = manager.indexOf("function hintFor(");

check("the jalali section is there", jalaliStart > 0 && jalaliEnd > jalaliStart);

const pureSlice = manager.slice(jalaliStart, jalaliEnd);
const wiring = manager.slice(jalaliEnd);

/* Lifted out of the IIFE and run for real. */
const jalaliLabel = new Function(pureSlice + "\nreturn jalaliLabel;")();
const scriptPrefix = new Function(pureSlice + "\nreturn JALALI_PREFIX;")();

/* The wording has to be byte identical to the PHP constant, or the label would
   change shape the moment somebody touches the picker. */
const phpPrefixMatch = /public const PREFIX = '([^']*)';/.exec(helper);
check("the helper still declares its PREFIX", phpPrefixMatch !== null);
const phpPrefix = phpPrefixMatch ? phpPrefixMatch[1] : "";
check(
  "the script rebuilds the very same wording as PHP",
  scriptPrefix === phpPrefix,
  JSON.stringify(scriptPrefix) + " vs " + JSON.stringify(phpPrefix)
);
check(
  "…byte for byte, including the trailing space",
  Buffer.from(scriptPrefix, "utf8").toString("hex") === Buffer.from(phpPrefix, "utf8").toString("hex")
);

/* Values produced by the real PHP Jalali class (app/Support/Jalali.php through
   JalaliHint::label(), run under php-wasm), including the example from that
   file's own docblock, every Nowruz boundary near a Gregorian leap year, and
   1403 - a Persian leap year, where Esfand has 30 days. */
const KNOWN = [
  ["2025-10-06", "1404/07/14"],
  ["2024-03-20", "1403/01/01"],
  ["2025-03-21", "1404/01/01"],
  ["2026-03-21", "1405/01/01"],
  ["2026-11-03", "1405/08/12"],
  ["2000-01-01", "1378/10/11"],
  ["1970-01-01", "1348/10/11"],
  ["2024-02-10", "1402/11/21"],
  ["2025-03-20", "1403/12/30"],
  ["2025-03-19", "1403/12/29"],
  ["2021-03-21", "1400/01/01"],
  ["2026-02-28", "1404/12/09"],
  ["2026-03-01", "1404/12/10"],
  ["2029-03-21", "1408/01/02"],
  ["1996-03-20", "1375/01/01"],
  ["2012-02-29", "1390/12/10"],
];

const knownWrong = KNOWN.filter(([iso, label]) => jalaliLabel(iso) !== label).map(
  ([iso, label]) => iso + " -> " + jalaliLabel(iso) + " (expected " + label + ")"
);
check("the port agrees with PHP on the reference dates", knownWrong.length === 0, knownWrong.join(" | "));

/* And on 20000 consecutive days, against the platform's own Persian calendar -
   an implementation neither side was derived from. */
let icuAvailable = true;
let intl = null;
try {
  intl = new Intl.DateTimeFormat("en-u-ca-persian", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    timeZone: "UTC",
  });
  if (intl.resolvedOptions().calendar !== "persian") icuAvailable = false;
} catch (error) {
  icuAvailable = false;
}

if (icuAvailable) {
  const fromIntl = (iso) => {
    const parts = intl.formatToParts(new Date(iso + "T00:00:00Z"));
    const get = (type) => parts.find((part) => part.type === type).value;
    return get("year").padStart(4, "0") + "/" + get("month") + "/" + get("day");
  };

  const days = 20000;
  const start = Date.UTC(1990, 0, 1);
  const wrong = [];
  let leapDays = 0;
  let nowruz = 0;
  for (let index = 0; index < days; index += 1) {
    const date = new Date(start + index * 86400000);
    const iso = date.toISOString().slice(0, 10);
    const mine = jalaliLabel(iso);
    const theirs = fromIntl(iso);
    if (mine !== theirs && wrong.length < 6) wrong.push(iso + ": " + mine + " vs " + theirs);
    if (iso.slice(5) === "02-29") leapDays += 1;
    if (mine.endsWith("/01/01")) nowruz += 1;
  }

  check(
    "the port agrees with the ICU Persian calendar on " + days + " consecutive days",
    wrong.length === 0,
    wrong.join(" | ")
  );
  check("that window really contains Gregorian leap days", leapDays >= 5, String(leapDays));
  check("and it really crosses Nowruz repeatedly", nowruz >= 40, String(nowruz));
  console.log("      " + days + " days, " + leapDays + " leap days, " + nowruz + " Nowruz crossings");
} else {
  check("ICU Persian calendar available for the wide comparison", false, "platform has no full ICU");
}

/* The port must be the PHP algorithm, not a look-alike that happens to work. */
const php = read("app/Support/Jalali.php");
for (const constant of ["1600", "12053", "1461", "979", "33 * ", "366", "365", "79"]) {
  check(
    "the port keeps the PHP constant " + constant.trim(),
    php.includes(constant) && pureSlice.includes(constant)
  );
}
check(
  "the port keeps the same cumulative Gregorian month table",
  pureSlice.includes("[0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]")
);
check(
  "and the same 31-then-30 Jalali month rule",
  pureSlice.includes("i < 6 ? 31 : 30") && php.includes("$i < 6 ? 31 : 30")
);
/* Comments are stripped first: the one above intdiv() mentions Math.floor to say
   why it is NOT used, and judging the prose would fail the code. */
const jalaliCode = pureSlice
  .replace(/\/\*[\s\S]*?\*\//g, "")
  .replace(/^[ \t]*\/\/.*$/gm, "");
check(
  "intdiv() truncates toward zero like PHP, not down like Math.floor",
  /function intdiv\([^)]*\)\s*\{\s*return Math\.trunc\(/.test(jalaliCode) && !jalaliCode.includes("Math.floor")
);
check("the label is zero padded to 4/2/2 like sprintf('%04d/%02d/%02d')", pureSlice.includes("pad(jalali[0], 4)"));

/* What the script accepts, and what it refuses. */
check("an empty field yields no label", jalaliLabel("") === "");
check("a value that is not a date yields no label", jalaliLabel("garbage") === "" && jalaliLabel("2025-1-6") === "");
check("nothing is thrown for null or undefined", jalaliLabel(null) === "" && jalaliLabel(undefined) === "");
check(
  "an already Jalali value is refused (a date input cannot hold one, and PHP - which can - rendered the label first)",
  jalaliLabel("1404/07/14") === ""
);
check("a datetime is refused too (a date input never yields one)", jalaliLabel("2025-10-06 08:30:00") === "");

/* Wiring. */
check("the script looks the hint up through the marker attribute", wiring.includes("data-jalali-hint"));
check(
  "and never past the next form control, so two date inputs in one row keep their own hints",
  wiring.includes("tag === 'input'") && wiring.includes("tag === 'select'") && wiring.includes("tag === 'button'")
);
check("it pairs through nextElementSibling, not through an id", wiring.includes("input.nextElementSibling"));
check("it follows the picker as it moves", wiring.includes("addEventListener('input', update)"));
check("and catches a picked date as well", wiring.includes("addEventListener('change', update)"));
check("it honours the compact rows", wiring.includes("data-jalali-compact"));
check(
  "it hides an emptied field twice over, because [hidden] loses to an inline display",
  wiring.includes("hint.hidden = true") && wiring.includes("hint.style.display = 'none'")
);
check("and shows it again the same way", wiring.includes("hint.hidden = false") && wiring.includes("hint.style.display = 'block'"));

/* PHP rendered the label from the stored value; a script must not rewrite
   correct server output before anybody touched the field. The counter section
   above does call update() once, so this really is looking at the right block. */
const jalaliWiring = wiring.slice(0, wiring.indexOf("});\n})();") + 3);
check(
  "the hint is not rewritten on load - PHP already got it right",
  !/^\s*update\(\);/m.test(jalaliWiring) && jalaliWiring.includes("Deliberately NOT called once on load")
);
check(
  "…while the character counter still is (proof the assertion above is scoped)",
  /\[data-counter\][\s\S]{0,400}update\(\);/.test(manager)
);

/* The panel is served to old browsers on shared hosting: the file is ES5 and
   the new section has to stay that way. */
check("the file still opts into strict mode", manager.includes("'use strict';"));
check("the file is still one IIFE", manager.startsWith("/*") && manager.trim().endsWith("})();"));
const newSection = manager.slice(jalaliStart);
check(
  "the new section stays ES5 (no let/const/arrow/template literal/optional chaining)",
  !/\blet\s/.test(newSection) &&
    !/\bconst\s/.test(newSection) &&
    !newSection.includes("=>") &&
    !newSection.includes("`") &&
    !newSection.includes("?."),
  "found a modern construct"
);
check("the new section is inside the IIFE, not appended after it", manager.lastIndexOf("})();") > jalaliStart);

/* =====================================================================
 * F. Cache busting, and no stylesheet was touched
 * =================================================================== */

section("F. The new script reaches the browser");

const layout = read("views/layouts/app.php");
const url = read("app/Support/Url.php");

const scriptTag = /<script src="<\?= \$e\(\$asset\('js\/manager\.js'([^)]*)\)\) \?>" defer><\/script>/.exec(layout);
check("the manager layout still loads manager.js deferred", scriptTag !== null);
check(
  "and now pins an explicit version, so a browser cannot keep the cached copy",
  scriptTag !== null && /^,\s*'[\d.]+'\s*$/.test(scriptTag[1] || ""),
  scriptTag ? JSON.stringify(scriptTag[1]) : "no tag"
);
check("the version goes through $asset(), never a hand written query", layout.includes("$asset('js/manager.js', '"));
check("no view appends a second &v= parameter", !layout.includes("&v="));

check(
  "Url::CSS_VERSION is untouched at 1.2.1 - no stylesheet changed in this stage",
  /public\s+const\s+CSS_VERSION\s*=\s*'1\.2\.1'/.test(url)
);
const jsVersion = scriptTag ? (/'([\d.]+)'/.exec(scriptTag[1]) || [])[1] : "";
check("the script version is not the stylesheet literal", jsVersion !== "1.2.1", jsVersion);

let literalLeak = false;
for (const view of views) {
  if (read(view).includes("'1.2.1'")) literalLeak = true;
}
check("no view hardcodes the CSS_VERSION literal (stage 21 rule)", !literalLeak);

/* The fix is inline on purpose: a stylesheet change would invalidate every
   cached panel stylesheet, so this stage must not have made one. */
check(
  "manager.css was not edited for this stage (it still has no jalali rule)",
  !/jalali/i.test(managerCss)
);
check(
  "and the design tokens it needed were already there",
  ["--m-surface-2", "--m-text", "--m-line-strong", "--m-radius-sm", "--m-dim"].every((name) => rootBlock.includes(name + ":"))
);

/* =====================================================================
 * G. Nothing from stage 23 was disturbed
 * =================================================================== */

section("G. Stage 23 still intact");

check("the telegram page still has exactly one date field", (telegram.match(/type="date"/g) || []).length === 1);
check("the preview picker is still a GET on the same page", telegram.includes('method="get"') && telegram.includes('name="preview_date"'));
check(
  "the send button still posts to the one daily route",
  (telegram.match(/\/manager\/telegram\/daily/g) || []).length >= 1 && telegram.includes('method="post"')
);
check("the preview still shows the character count", telegram.includes("$dailyPreview['chars']"));
check("and the truncation badge", telegram.includes("$dailyPreview['truncated']"));
check("the daily card still renders the name buckets", telegram.includes("absent_names") && telegram.includes("late_names"));

const scheduler = read("app/Services/TelegramScheduler.php");
check("the scheduler still builds the preview without sending", scheduler.includes("public function previewFor("));

/* =====================================================================
 * Summary
 * =================================================================== */
console.log(
  "\n" +
    "=".repeat(62) +
    "\nSTAGE 24 checks passed: " +
    passed +
    ", failed: " +
    failures.length
);
if (failures.length > 0) {
  console.log("\nFAILURES:");
  failures.forEach((failure) => console.log("  ✗ " + failure));
  process.exit(1);
}
console.log("✓ the preview is readable on the dark theme and every date field shows its Jalali equivalent");
process.exit(0);
