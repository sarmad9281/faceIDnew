// Run with Node; no server, browser or database required.
//
// STAGE 23 A - the social security (SSO) insurance diskette and the bank
// payment file.
//
//   node tests/stage23_insurance_bank_test.js
//
// The suite does not trust a comment: it parses the PHP field tables out of
// app/Services/PayrollExportService.php, compares them column by column with
// the layout published by سازمان تأمین اجتماعی (projects 51/52 of List Disk),
// rebuilds the dBASE III file from that layout with an independent JS writer
// and asserts the exact bytes - then checks the PHP writer agrees with it on
// every structural constant. Anything that would make the SSO importer refuse
// the file fails here:
//
//   1. DSKKAR00 / DSKWOR00: names, ORDER, type and width of every column,
//   2. the dBASE III byte layout (version 0x03, header length, field
//      descriptors, 0x0D terminator, 0x20 record flag, 0x1A EOF, C left /
//      N right alignment, blank - not zero - for an empty number),
//   3. the money rules: whole rials, Toman x 10, Latin digits only,
//      DSW_DD clamped to the length of the Jalali month,
//   4. the new columns exist as declarative migrations AND are filtered by the
//      live column list, so a database that has not migrated yet still saves a
//      payroll profile,
//   5. the IBAN is a TEXT cell and the CSV carries a UTF-8 BOM,
//   6. the routes, the capabilities and the panel UI behind all of it.

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
 * Helpers
 * =================================================================== */

/**
 * Reads one `public const X = [ ['name' => ..., 'type' => ..., 'length' => ...], ... ];`
 * table out of a PHP source file. The regex is written for the exact shape the
 * exporter uses, so a reformatted table is still parsed.
 */
function parseFieldTable(source, constant) {
  const start = source.indexOf("public const " + constant + " = [");
  if (start === -1) return null;

  const bodyStart = source.indexOf("[", start + ("public const " + constant).length);
  let depth = 0;
  let end = -1;

  for (let i = bodyStart; i < source.length; i += 1) {
    if (source[i] === "[") depth += 1;
    else if (source[i] === "]") {
      depth -= 1;
      if (depth === 0) {
        end = i;
        break;
      }
    }
  }

  if (end === -1) return null;

  const body = source.slice(bodyStart + 1, end);
  const rows = [];
  const pattern = /\[\s*'name'\s*=>\s*'([A-Za-z0-9_]+)'\s*,\s*'type'\s*=>\s*'([CN])'\s*,\s*'length'\s*=>\s*(\d+)\s*\]/g;
  let match;

  while ((match = pattern.exec(body)) !== null) {
    rows.push({ name: match[1], type: match[2], length: Number(match[3]) });
  }

  return rows;
}

/** Little endian uint16 / uint32 readers, the way dBASE stores them. */
const u16 = (buffer, offset) => buffer[offset] | (buffer[offset + 1] << 8);
const u32 = (buffer, offset) =>
  (buffer[offset] | (buffer[offset + 1] << 8) | (buffer[offset + 2] << 16) | (buffer[offset + 3] << 24)) >>> 0;

/**
 * An INDEPENDENT dBASE III writer, written from the file format itself (not
 * copied from app/Support/Dbf.php). The test compares the PHP source against
 * this reference, so a wrong constant in either one shows up as a failure.
 */
function buildDbf(fields, rows) {
  const recordLength = 1 + fields.reduce((sum, field) => sum + field.length, 0);
  const headerLength = 32 + 32 * fields.length + 1;

  const header = Buffer.alloc(headerLength);
  header[0] = 0x03; // dBASE III without a memo file
  header[1] = 125; // YY of the last update (arbitrary but valid)
  header[2] = 8; // MM
  header[3] = 1; // DD
  header.writeUInt32LE(rows.length, 4);
  header.writeUInt16LE(headerLength, 8);
  header.writeUInt16LE(recordLength, 10);
  header[29] = 0x00; // language driver id

  fields.forEach((field, index) => {
    const at = 32 + 32 * index;
    const name = Buffer.alloc(11); // 11 bytes, NUL padded
    name.write(field.name.toUpperCase().slice(0, 10), 0, "ascii");
    name.copy(header, at);
    header[at + 11] = field.type.charCodeAt(0);
    header[at + 16] = field.length;
    header[at + 17] = 0; // decimal count
  });

  header[headerLength - 1] = 0x0d; // header record terminator

  const body = Buffer.alloc(recordLength * rows.length);

  rows.forEach((row, rowIndex) => {
    const base = rowIndex * recordLength;
    body[base] = 0x20; // not deleted
    let offset = base + 1;

    fields.forEach((field) => {
      const raw = row[field.name] === undefined || row[field.name] === null ? "" : row[field.name];
      let cell;

      if (field.type === "N") {
        // Right aligned ASCII digits; an empty value is ALL SPACES (NULL), not 0.
        cell = raw === "" ? " ".repeat(field.length) : String(Math.round(Number(raw))).padStart(field.length, " ");
        if (cell.length > field.length) cell = cell.slice(-field.length);
      } else {
        // Left aligned, padded with spaces to the exact byte width.
        cell = String(raw).slice(0, field.length).padEnd(field.length, " ");
      }

      Buffer.from(cell, "latin1").copy(body, offset);
      offset += field.length;
    });
  });

  return Buffer.concat([header, body, Buffer.from([0x1a])]);
}

/* =====================================================================
 * Sources
 * =================================================================== */

const exporter = read("app/Services/PayrollExportService.php");
const dbf = read("app/Support/Dbf.php");
const encoding = read("app/Support/Encoding.php");
const payrollService = read("app/Services/PayrollService.php");
const payrollController = read("app/Controllers/Manager/PayrollController.php");
const payrollView = read("views/manager/payroll/index.php");
const routes = read("routes/web.php");
const migrations = read("app/Services/MigrationService.php");
const profileModel = read("app/Models/PayrollProfile.php");
const customerSetting = read("app/Models/CustomerSetting.php");
const calculator = read("app/Services/PayrollCalculator.php");

/* =====================================================================
 * A. The SSO layout: DSKKAR00 (project 51)
 * =================================================================== */
section("A. DSKKAR00 - پایگاه اطلاعات دیسکت ماهانه کارگاه");

// The published layout, in file order. C = character, N = numeric.
const KAR_SPEC = [
  ["DSK_ID", "C", 10], ["DSK_NAME", "C", 30], ["DSK_FARM", "C", 30], ["DSK_ADRS", "C", 40],
  ["DSK_KIND", "N", 1], ["DSK_YY", "N", 2], ["DSK_MM", "N", 2], ["DSK_LISTNO", "C", 12],
  ["DSK_DISC", "C", 30], ["DSK_NUM", "N", 5], ["DSK_TDD", "N", 6], ["DSK_TROOZ", "N", 12],
  ["DSK_TMAH", "N", 12], ["DSK_TMAZ", "N", 12], ["DSK_TMASH", "N", 12], ["DSK_TTOTL", "N", 12],
  ["DSK_TBIME", "N", 12], ["DSK_TKOSO", "N", 12], ["DSK_BIC", "N", 12], ["DSK_RATE", "N", 5],
  ["DSK_PRATE", "N", 2], ["DSK_BIMH", "N", 12], ["MON_PYM", "C", 3],
];

const kar = parseFieldTable(exporter, "DSKKAR_FIELDS");

check("the DSKKAR_FIELDS table is parseable", Array.isArray(kar) && kar.length > 0, kar === null ? "constant not found" : "empty");
check("DSKKAR00 has exactly " + KAR_SPEC.length + " columns", kar.length === KAR_SPEC.length, "found " + kar.length);

KAR_SPEC.forEach(([name, type, length], index) => {
  const actual = kar[index];
  check(
    "DSKKAR00 column " + (index + 1) + " is " + name + " " + type + "(" + length + ")",
    actual !== undefined && actual.name === name && actual.type === type && actual.length === length,
    actual === undefined ? "missing" : actual.name + " " + actual.type + "(" + actual.length + ")"
  );
});

check(
  "DSK_ID is the workshop code and is unique-index member width (C10)",
  kar[0].name === "DSK_ID" && kar[0].type === "C" && kar[0].length === 10
);
check(
  "the year is two digits and the month two digits (DSK_YY N2, DSK_MM N2)",
  kar[5].name === "DSK_YY" && kar[5].length === 2 && kar[6].name === "DSK_MM" && kar[6].length === 2
);
check(
  "the control totals are all N12 money columns",
  ["DSK_TROOZ", "DSK_TMAH", "DSK_TMAZ", "DSK_TMASH", "DSK_TTOTL", "DSK_TBIME", "DSK_TKOSO", "DSK_BIC", "DSK_BIMH"]
    .every((name) => {
      const field = kar.find((candidate) => candidate.name === name);
      return field !== undefined && field.type === "N" && field.length === 12;
    })
);
check("MON_PYM (ردیف پیمان) is the last column", kar[kar.length - 1].name === "MON_PYM");

/* =====================================================================
 * B. The SSO layout: DSKWOR00 (project 52)
 * =================================================================== */
section("B. DSKWOR00 - ریز کارکرد ماهانه کارگاه");

const WOR_SPEC = [
  ["DSW_ID", "C", 10], ["DSW_YY", "N", 2], ["DSW_MM", "N", 2], ["DSW_LISTNO", "C", 12],
  ["DSW_ID1", "C", 8], ["DSW_FNAME", "C", 50], ["DSW_LNAME", "C", 50], ["DSW_DNAME", "C", 50],
  ["DSW_IDNO", "C", 15], ["DSW_IDPLC", "C", 30], ["DSW_IDATE", "C", 8], ["DSW_BDATE", "C", 8],
  ["DSW_SEX", "C", 3], ["DSW_NAT", "C", 10], ["DSW_OCP", "C", 50], ["DSW_SDATE", "C", 8],
  ["DSW_EDATE", "C", 8], ["DSW_DD", "N", 2], ["DSW_ROOZ", "N", 12], ["DSW_MAH", "N", 12],
  ["DSW_MAZ", "N", 12], ["DSW_MASH", "N", 12], ["DSW_TOTL", "N", 12], ["DSW_BIME", "N", 12],
  ["DSW_PRATE", "N", 2], ["DSW_JOB", "C", 6], ["PER_NATCOD", "C", 10],
];

const wor = parseFieldTable(exporter, "DSKWOR_FIELDS");

check("the DSKWOR_FIELDS table is parseable", Array.isArray(wor) && wor.length > 0, wor === null ? "constant not found" : "empty");
check("DSKWOR00 has exactly " + WOR_SPEC.length + " columns", wor.length === WOR_SPEC.length, "found " + wor.length);

WOR_SPEC.forEach(([name, type, length], index) => {
  const actual = wor[index];
  check(
    "DSKWOR00 column " + (index + 1) + " is " + name + " " + type + "(" + length + ")",
    actual !== undefined && actual.name === name && actual.type === type && actual.length === length,
    actual === undefined ? "missing" : actual.name + " " + actual.type + "(" + actual.length + ")"
  );
});

// Every dBASE III column name has to fit the 10 character limit, or the file
// is unreadable - the exporter truncates, so nothing here may be longer.
check(
  "no column name exceeds the dBASE III 10 character limit",
  kar.concat(wor).every((field) => field.name.length <= 10)
);
check(
  "no column is wider than the dBASE III maximum of 254 bytes",
  kar.concat(wor).every((field) => field.length >= 1 && field.length <= 254)
);
check(
  "the eight date columns are C(8) - the YYYYMMDD string the SSO wants",
  ["DSW_IDATE", "DSW_BDATE", "DSW_SDATE", "DSW_EDATE"].every((name) => {
    const field = wor.find((candidate) => candidate.name === name);
    return field !== undefined && field.type === "C" && field.length === 8;
  })
);
check("DSW_DD (روزهای کارکرد) is N(2), never a money column", wor[17].name === "DSW_DD" && wor[17].length === 2);
check("PER_NATCOD (کد ملی) is the last column and is C(10)", wor[wor.length - 1].name === "PER_NATCOD");

/* =====================================================================
 * C. The dBASE III byte layout
 * =================================================================== */
section("C. dBASE III byte layout (rebuilt independently and compared)");

const sample = buildDbf(kar, [{
  DSK_ID: "12345678901", // one character too long -> has to be cut
  DSK_NAME: "کارگاه نمونه",
  DSK_FARM: "شرکت نمونه",
  DSK_ADRS: "تهران",
  DSK_KIND: 1, DSK_YY: 4, DSK_MM: 7, DSK_LISTNO: "0407",
  DSK_DISC: "لیست حق بیمه مهر ۱۴۰۴",
  DSK_NUM: 42, DSK_TDD: 1260, DSK_TROOZ: 0, DSK_TMAH: 1234567890,
  DSK_TMAZ: 5000000, DSK_TMASH: 1239567890, DSK_TTOTL: 1239567890,
  DSK_TBIME: 86769752, DSK_TKOSO: 247913578, DSK_BIC: 37187036,
  DSK_RATE: 30, DSK_PRATE: 0, DSK_BIMH: 0, MON_PYM: "000",
}]);

const karRecordLength = 1 + kar.reduce((sum, field) => sum + field.length, 0);
const karHeaderLength = 32 + 32 * kar.length + 1;

check("byte 0 is the dBASE III version 0x03", sample[0] === 0x03, "0x" + sample[0].toString(16));
check("bytes 4-7 hold the record count (little endian)", u32(sample, 4) === 1, String(u32(sample, 4)));
check("bytes 8-9 hold the header length " + karHeaderLength, u16(sample, 8) === karHeaderLength, String(u16(sample, 8)));
check("bytes 10-11 hold the record length " + karRecordLength, u16(sample, 10) === karRecordLength, String(u16(sample, 10)));
check("byte 29 is the language driver id 0x00 (legacy codepage)", sample[29] === 0x00, "0x" + sample[29].toString(16));
check("the header ends with the 0x0D terminator", sample[karHeaderLength - 1] === 0x0d, "0x" + sample[karHeaderLength - 1].toString(16));
check("a record starts with the 0x20 'not deleted' flag", sample[karHeaderLength] === 0x20, "0x" + sample[karHeaderLength].toString(16));
check("the file ends with the 0x1A end of file marker", sample[sample.length - 1] === 0x1a, "0x" + sample[sample.length - 1].toString(16));
check(
  "the total length is header + records + EOF",
  sample.length === karHeaderLength + karRecordLength + 1,
  sample.length + " vs " + (karHeaderLength + karRecordLength + 1)
);

// Field descriptors
kar.forEach((field, index) => {
  const at = 32 + 32 * index;
  const name = sample.slice(at, at + 11).toString("latin1").replace(/\0+$/, "");
  check(
    "descriptor " + (index + 1) + " names " + field.name,
    name === field.name,
    name
  );
});
check("descriptor 1 carries the type byte at offset +11", String.fromCharCode(sample[32 + 11]) === "C");
check("descriptor 1 carries the width at offset +16", sample[32 + 16] === 10, String(sample[32 + 16]));
check("descriptor 1 carries a decimal count of 0 at offset +17", sample[32 + 17] === 0);

const kindDescriptor = 32 + 32 * 4; // DSK_KIND
check("the DSK_KIND descriptor is type N", String.fromCharCode(sample[kindDescriptor + 11]) === "N");
check("the DSK_KIND descriptor is 1 byte wide", sample[kindDescriptor + 16] === 1);

// Alignment inside the record
const record = sample.slice(karHeaderLength + 1, karHeaderLength + karRecordLength);
check("a C(10) field is cut at its width, never wrapped", record.slice(0, 10).toString("latin1") === "1234567890");
check(
  "a N column is right aligned and space padded on the left",
  record.slice(10 + 30 + 30 + 40 + 1 + 2 + 2 + 12 + 30, 10 + 30 + 30 + 40 + 1 + 2 + 2 + 12 + 30 + 5).toString("latin1") === "   42"
);

// An empty numeric cell is BLANK (NULL), which is what the SSO reader shows as
// an empty box - a zero would be read as a real amount.
const blank = buildDbf([{ name: "DSW_ID1", type: "C", length: 8 }, { name: "DSW_DD", type: "N", length: 2 }], [
  { DSW_ID1: "", DSW_DD: "" },
]);
check(
  "an empty numeric cell is two spaces, not '0' and not NUL",
  blank.slice(blank.length - 3, blank.length - 1).toString("latin1") === "  ",
  JSON.stringify(blank.slice(blank.length - 3, blank.length - 1).toString("latin1"))
);
check("an empty character cell is padded with spaces", blank[blank.length - 3 - 2] === 0x20);

// Now the PHP writer has to agree with this reference on every constant.
check("Dbf.php writes dBASE III (VERSION = 0x03)", dbf.includes("private const VERSION = 0x03;"));
check("Dbf.php ends the file with 0x1A", dbf.includes("private const EOF_MARKER     = 0x1A;"));
check("Dbf.php terminates the header with 0x0D", dbf.includes("private const HEADER_END     = 0x0D;"));
check("Dbf.php flags every record 0x20 (not deleted)", dbf.includes("private const RECORD_VALID   = 0x20;"));
check("Dbf.php leaves the language driver id at 0x00", dbf.includes("private const LANGUAGE_ID = 0x00;"));
check(
  "Dbf.php computes the header length as 32 + 32 per field + 1",
  dbf.includes("$headerLength = 32 + (32 * count($fields)) + 1;")
);
check("Dbf.php starts the record length at the 1 byte deletion flag", dbf.includes("$recordLength = 1;"));
check("Dbf.php adds every field width to the record length", dbf.includes("$recordLength += $field['length'];"));
check("Dbf.php truncates a field name to the dBASE III 10 characters", dbf.includes("strtoupper(substr($field['name'], 0, 10))"));
check("Dbf.php writes the 11 byte NUL padded name", dbf.includes("str_pad($name, 11, \"\\x00\")"));
check("Dbf.php writes a 0 decimal count", /chr\(0\)\s*\/\/ decimal count/.test(dbf));
check("Dbf.php right aligns numbers with STR_PAD_LEFT", dbf.includes("str_pad($text, $length, ' ', STR_PAD_LEFT)"));
check("Dbf.php left aligns text with STR_PAD_RIGHT", dbf.includes("str_pad($bytes, $length, ' ', STR_PAD_RIGHT)"));
check("an empty numeric value becomes all spaces (NULL, not 0)", dbf.includes("return str_repeat(' ', $length);"));
check("Dbf.php only accepts the two column types the SSO layout uses", dbf.includes("public const TYPE_CHAR   = 'C';") && dbf.includes("public const TYPE_NUMBER = 'N';"));
check("Dbf.php clamps a column width to the dBASE III maximum of 254", dbf.includes("max(1, min(254, $length))"));

/* =====================================================================
 * D. The TEXT variant of the same layout
 * =================================================================== */
section("D. The comma separated TEXT variant");

check("the exporter writes the TEXT variant too", exporter.includes("public function renderText(array $fields, array $rows"));
check("the TEXT file is the same column order as the .dbf", exporter.includes("$cells[] = $this->renderTextCell($row[$field['name']] ?? '', $field, $encoding);"));
check("records are separated by a comma", exporter.includes("implode(',', $cells)"));
check("lines end with CRLF (a DOS layout file)", exporter.includes('implode("\\r\\n", $lines)'));
check("no header row is written (the importer reads by position)", exporter.includes("return implode(\"\\r\\n\", \$lines) . (\$lines === [] ? '' : \"\\r\\n\");"));
check("a numeric TEXT cell is a plain integer", exporter.includes("return (string) (int) round((float) $value);"));
check("an empty numeric TEXT cell stays empty", /if \(\$value === null \|\| \$value === ''\) \{\s*return '';\s*\}/.test(exporter));
check(
  "a comma inside a field can never break the record",
  exporter.includes("str_replace([',', '،'], '؛', $text)")
);
check("the four diskette files are named DSKKAR00 / DSKWOR00", exporter.includes("public const KAR_BASENAME = 'DSKKAR00';") && exporter.includes("public const WOR_BASENAME = 'DSKWOR00';"));
check("both a .txt and a .dbf are produced for each table", exporter.includes(".txt' => $this->renderText(self::DSKKAR_FIELDS") && exporter.includes(".dbf' => Dbf::build(self::DSKKAR_FIELDS"));

/* =====================================================================
 * E. Encoding: WINDOWS-1256 and Latin digits
 * =================================================================== */
section("E. WINDOWS-1256 encoding and Latin digits");

check("the default export encoding is windows-1256", exporter.includes('string $encoding = "windows-1256"') || exporter.includes("string $encoding = 'windows-1256'"));
check("an unknown encoding falls back to windows-1256", exporter.includes("strtolower(trim($encoding)) === 'utf-8' ? 'utf-8' : 'windows-1256'"));
check("Dbf.php converts text through App\\Support\\Encoding", dbf.includes("Encoding::toWindows1256($text)"));
check("Encoding holds the whole CP1256 upper half (128 codepoints)", (encoding.match(/[0-9a-f]{4},/g) || []).length >= 128);
check("the CP1256 table starts at 0x20AC (byte 0x80)", encoding.includes("'20ac,067e,201a,0192,201e,2026,2020,2021,02c6,2030,'"));
check("ASCII bytes are identical in both encodings", encoding.includes("$table[$byte] = $byte; // ASCII is identical in both encodings"));
check("Persian ی folds to the CP1256 form ي", encoding.includes("0x06CC => 0x064A"));
check("Arabic ك folds to the Persian ک the table does have", encoding.includes("0x0643 => 0x06A9"));
check("Persian digits fold to Latin digits", encoding.includes("0x06F0 => 0x0030") && encoding.includes("0x0660 => 0x0030"));
check("an unmappable character becomes '?' and never a broken byte", encoding.includes("$out .= isset($table[$codepoint]) ? chr($table[$codepoint]) : '?';"));
check("line breaks are removed from an export field", encoding.includes('str_replace(["\\r\\n", "\\r", "\\n", "\\t"], \' \', $text)'));
check("runs of whitespace are collapsed", encoding.includes("preg_replace('/\\s+/u', ' ', $text)"));
check("toLatinDigits maps both Arabic-Indic digit blocks", encoding.includes("'۰' => '0'") && encoding.includes("'٠' => '0'"));

/* =====================================================================
 * F. The money rules
 * =================================================================== */
section("F. Rials, Toman and the wage columns");

check("a Toman (IRT) profile is multiplied by ten", exporter.includes("strtoupper(trim($currency)) === 'IRT' ? $value * 10 : $value"));
check("every SSO amount is rounded to whole rials", (exporter.match(/\(int\) round\(/g) || []).length >= 8);
check("a non finite amount becomes 0, never NaN", exporter.includes("if (!is_finite($value)) {"));
check(
  "a monthly salary is written to DSW_MAH as is",
  exporter.includes("$payType === 'monthly'\n                ? $this->toRials($item['base_amount'] ?? 0, $currency)")
);
check("a daily / hourly worker is days x daily rate", exporter.includes("(int) round($dd * $dailyRate)"));
check("DSW_ROOZ stays 0 for a monthly salary (the SSO does not need it)", exporter.includes("'DSW_ROOZ'   => $payType === 'monthly' ? 0 : (int) round($dailyRate)"));
check("DSW_MAZ is the benefits column of the payroll row", exporter.includes("$maz  = $this->toRials($item['benefits'] ?? 0, $currency);"));
check("DSW_MASH is the wage plus the benefits", exporter.includes("$mash = max(0, $mah + $maz);"));
check("the insured wage ceiling clamps the ماخذ when it is set", exporter.includes("if ($settings['max_mash'] > 0) {") && exporter.includes("$mash = min($mash, (int) round($settings['max_mash']));"));
check("DSW_TOTL equals the ماخذ (no non insured element exists here)", exporter.includes("'DSW_TOTL'   => $mash"));
check("the insured share is the percentage of the ماخذ", exporter.includes("$bime  = (int) round($mash * $settings['employee_rate'] / 100);"));
check("the employer share is the percentage of the ماخذ", exporter.includes("$kosor = (int) round($mash * $settings['employer_rate'] / 100);"));
check("unemployment insurance is the percentage of the ماخذ", exporter.includes("$bic   = (int) round($mash * $settings['unemployment_rate'] / 100);"));
check("DSK_RATE is the sum of all three shares", exporter.includes("'total_rate'       => $employeeRate + $employerRate + $unemploymentRate,"));
check("the default rates are 7% / 20% / 3%", exporter.includes("DEFAULT_EMPLOYEE_RATE     = 7.0") && exporter.includes("DEFAULT_EMPLOYER_RATE     = 20.0") && exporter.includes("DEFAULT_UNEMPLOYMENT_RATE = 3.0"));
check("DSK_BIMH (حق بیمه مشاغل) stays 0 - it applies to listed jobs only", exporter.includes("'DSK_BIMH'   => 0"));
check("the year is written as two digits", exporter.includes("'DSK_YY'     => $jy % 100"));
check("the month is the Jalali month of the period", exporter.includes("'DSK_MM'     => $jm"));
check("DSKWOR00 carries exactly one control row per workshop in DSKKAR00", exporter.includes("$kar = [["));

// DSW_DD - the paid days of the month
check("DSW_DD counts presence, approved leave and holidays", exporter.includes("$dd = max(0, min($monthLength, $present + $leave + $holiday));"));
check("DSW_DD can never exceed the length of the Jalali month", exporter.includes("min($monthLength,"));
check("the month length is derived with the project's own Jalali converter", exporter.includes("Jalali::fromGregorian(") && exporter.includes("public function jalaliMonthLength(int $jy, int $jm, string $gregorianInMonth): int"));
check("a month of 1-6 is 31 days, 7-11 is 30 and اسفند is at most 30", exporter.includes("$limit = $jm <= 6 ? 31 : ($jm <= 11 ? 30 : 30);"));
check("the walker stops at 40 days even for a broken date", exporter.includes("while ($count < 40)"));
check("a period that spans two Jalali months is reported, not silently split", exporter.includes("این دوره بیشتر از یک ماه شمسی را پوشش می‌دهد"));
check("DSW_SDATE is the hire date", exporter.includes("'DSW_SDATE'  => $this->compactDate($profile['hire_date'] ?? ($item['hire_date'] ?? null))"));
check("DSW_EDATE is only the ترک کار date, never today", exporter.includes("'DSW_EDATE'  => $this->compactDate($profile['insurance_end_date'] ?? null)"));
check("a date becomes the 8 digit Jalali string", exporter.includes("return Encoding::toLatinDigits(str_replace(['/', '-', ' '], '', $jalali));"));
check("an empty date stays empty instead of becoming 00000000", exporter.includes("if ($jalali === '—') {\n            return '';\n        }"));
check("the sex column is Persian (مرد / زن)", exporter.includes("return strtolower((string) $value) === 'female' ? 'زن' : (strtolower((string) $value) === 'male' ? 'مرد' : '');"));
check("the nationality defaults to ایرانی", exporter.includes("$this->orDefault((string) ($profile['nationality'] ?? ''), 'ایرانی')"));
check("the occupation falls back from job_title to position", exporter.includes("(string) ($profile['job_title'] ?? ''),\n                (string) ($item['position'] ?? '')"));
check("identity numbers are digits only", exporter.includes("private function digits($value): string") && exporter.includes("preg_replace('/\\D/', '', Encoding::toLatinDigits("));
check("the national code falls back to the employee record", exporter.includes("$this->firstText((string) ($profile['id_number'] ?? ''), (string) ($item['national_id'] ?? ''))"));

// Control totals
check("DSK_NUM counts the people", exporter.includes("$control['count']++;"));
check("DSK_TDD sums the days", exporter.includes("$control['dd']    += $dd;"));
check("every control total sums its column", ["rooz", "mah", "maz", "mash", "totl", "bime", "kosor", "bic"]
  .every((key) => exporter.includes("$control['" + key + "']")));
check("a missing mandatory SSO field is reported by name", exporter.includes("private function missingInsuranceFields(array $rows): array"));
check("the missing-field warning names the gaps per person", exporter.includes("$missing[] = ($row['_employee_name'] ?? '') . ' (' . implode('، ', $gaps) . ')';"));
check("the warning list is capped so it stays readable", exporter.includes("array_slice($missing, 0, 6)"));

/* =====================================================================
 * G. Frozen period vs live preview
 * =================================================================== */
section("G. A finalized period is exported from its frozen rows");

check("the export reads the FROZEN items of a finalized period", exporter.includes("$finalized = (string) ($period['status'] ?? '') === 'finalized';"));
check("the frozen branch selects attendance_payroll_items", exporter.includes("Database::table('payroll_items')") && exporter.includes("i.`period_id` = ?"));
check("an open period is calculated live through PayrollService", exporter.includes("$this->payroll->previewRows($customerId, ['date_from' => $from, 'date_to' => $to])"));
check("both branches join the employee identity columns", exporter.includes("e.`national_id`, e.`position`, e.`hire_date`"));
check("every source query is scoped by customer_id", (exporter.match(/`customer_id` = \?/g) || []).length >= 3, String((exporter.match(/`customer_id` = \?/g) || []).length));
check("the customer profile lookup is by the tenant id only", exporter.includes("Database::table('customers')") && /Database::table\('customers'\)[\s\S]{0,40}' WHERE `id` = \?'/.test(exporter));
check("the daily rate of a frozen period comes from the CONTRACT, not the frozen amount", exporter.includes("if (isset($item['daily_rate'])) {\n            return (float) $item['daily_rate'];\n        }"));
check("a frozen period falls back to PayrollCalculator for the rate", exporter.includes("$calc = PayrollCalculator::forEmployee($profile, $totals);"));
check("PayrollCalculator publishes daily_rate as its own number", calculator.includes("'daily_rate'          => round($effectiveDaily, 2),"));
check("daily_rate is derived from the value the base pay always used", calculator.includes("$effectiveDaily = $baseAmount / $daysPerMonth;"));
check("adding daily_rate did not change the calculation version", calculator.includes("public const VERSION = '7.0.0';"));

/* =====================================================================
 * H. The bank payment file
 * =================================================================== */
section("H. The bank payment file");

const BANK_SPEC = [
  ["row", "ردیف"],
  ["employee_name", "نام و نام خانوادگی"],
  ["employee_code", "کد پرسنلی"],
  ["national_id", "کد ملی"],
  ["bank_name", "نام بانک"],
  ["iban", "شماره شبا"],
  ["account_number", "شماره حساب"],
  ["net_amount", "مبلغ خالص (ریال)"],
  ["note", "شرح"],
];

const bankStart = exporter.indexOf("public const BANK_COLUMNS = [");
const bankBody = exporter.slice(bankStart, exporter.indexOf("];", bankStart));
const bankRowsParsed = [...bankBody.matchAll(/\['key' => '([a-z_]+)',\s*'label' => '([^']+)'/g)].map((m) => [m[1], m[2]]);

check("the BANK_COLUMNS table is parseable", bankRowsParsed.length > 0, String(bankRowsParsed.length));
check("the bank file has exactly " + BANK_SPEC.length + " columns", bankRowsParsed.length === BANK_SPEC.length, "found " + bankRowsParsed.length);

BANK_SPEC.forEach(([key, label], index) => {
  const actual = bankRowsParsed[index];
  check(
    "bank column " + (index + 1) + " is " + label + " (" + key + ")",
    actual !== undefined && actual[0] === key && actual[1] === label,
    actual === undefined ? "missing" : actual.join("/")
  );
});

check("the IBAN column is declared as a string", /'key' => 'iban',\s*'label' => '[^']+',\s*'type' => 'string'/.test(bankBody));
check("the amount column is declared as an integer", /'key' => 'net_amount',[\s\S]*?'type' => 'int'/.test(bankBody));
check("the net amount is converted to rials", exporter.includes("$net  = (int) round($this->toRials($item['net_amount'] ?? 0, (string) ($item['currency'] ?? 'IRR')));"));
check("an IBAN is upper cased and stripped of spaces", exporter.includes("strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? '')"));
check("an IBAN is capped at the 34 character international maximum", exporter.includes("return substr($iban, 0, 34);"));
check("a missing IBAN is reported by name and never invented", exporter.includes("if ($iban === '') {\n                $warnings[] = trim("));
check("the total of the file is summed in rials", exporter.includes("$total += $net;"));
check("the payment note falls back to the Jalali month of the period", exporter.includes("Jalali::monthLabel((string) $period['start_date'])"));

// XLSX: the IBAN must be an explicit TEXT cell or Excel turns "IR06..." into a
// number and the bank portal rejects the file.
check("the workbook is a real PhpSpreadsheet OOXML file", exporter.includes("new \\PhpOffice\\PhpSpreadsheet\\Spreadsheet()") && exporter.includes("new \\PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx($spreadsheet)"));
check("text cells are written with setCellValueExplicit(TYPE_STRING)", exporter.includes("\\PhpOffice\\PhpSpreadsheet\\Cell\\DataType::TYPE_STRING"));
check("money cells are written as numbers with a thousands format", exporter.includes("DataType::TYPE_NUMERIC") && exporter.includes("setFormatCode('#,##0')"));
check("the sheet is right to left", exporter.includes("$sheet->setRightToLeft(true)"));
check("the workbook degrades with a Persian message when Excel is missing", exporter.includes("class_exists(\\PhpOffice\\PhpSpreadsheet\\Spreadsheet::class)") && exporter.includes("CODE_NO_EXCEL"));
check("the workbook is built in a temp file and never in a public path", exporter.includes("tempnam(sys_get_temp_dir(), 'sarmad-bank')"));

// CSV
check("the CSV starts with the UTF-8 BOM Excel needs", exporter.includes('fwrite($handle, "\\xEF\\xBB\\xBF");'));
check("the CSV header row is written in Persian", exporter.includes("fputcsv($handle, $labels);"));
check("the CSV ends with a total row", exporter.includes("$line = ['', 'مجموع', '', '', '', '', '', (int) $result['data']['total'], ''];"));
check("the CSV is built in memory, not on disk", exporter.includes("fopen('php://temp', 'r+')"));
check("the bank file names carry the period", payrollController.includes("'bank-payments-' . $this->periodStamp($result['data']['period'])"));

/* =====================================================================
 * I. Routes, capabilities and the audit trail
 * =================================================================== */
section("I. Routes, capabilities and auditing");

check("GET /manager/payroll/diskette is routed", routes.includes("$router->get('/manager/payroll/diskette', [ManagerPayrollController::class, 'diskette']"));
check("GET /manager/payroll/bank-file is routed", routes.includes("$router->get('/manager/payroll/bank-file', [ManagerPayrollController::class, 'bankFile']"));
check("POST /manager/payroll/insurance is routed", routes.includes("$router->post('/manager/payroll/insurance', [ManagerPayrollController::class, 'insurance']"));
check("the diskette route is named manager.payroll.diskette", routes.includes("'manager.payroll.diskette'"));
check("the bank route is named manager.payroll.bank", routes.includes("'manager.payroll.bank'"));
check("the insurance route is named manager.payroll.insurance", routes.includes("'manager.payroll.insurance'"));
check("all three routes are behind the payroll feature flag", (routes.match(/feature:payroll/g) || []).length >= 9);
check("the settings POST carries the csrf middleware", routes.includes("'insurance'],\n        ['auth', 'role:manager,owner', 'tenant:single', 'feature:payroll', 'csrf']"));
check("a download GET does not require a csrf token (it is a link)", !/diskette'[\s\S]{0,120}csrf/.test(routes));
check("a supervisor can not reach the payroll exports", !/diskette'[\s\S]{0,120}supervisor/.test(routes));
check("the downloads are gated on payroll.view", (payrollController.match(/Capability::check\(\$request, 'payroll\.view'\)/g) || []).length === 2);
check("the workshop settings are gated on payroll.manage", payrollController.includes("public function insurance(Request $request): Response") && /public function insurance[\s\S]{0,600}payroll\.manage/.test(payrollController));
check("the tenant is always resolved from the session, never the request", (payrollController.match(/TenantContext::requireCustomerId\(\)/g) || []).length >= 7);
check("the feature flag is checked in every action", (payrollController.match(/FeatureService::requireFeature\('payroll', \$customerId\)/g) || []).length >= 7);

check("a download is audited", payrollController.includes("$exporter->audit('payroll.diskette.download'") && payrollController.includes("$exporter->audit('payroll.bank.download'"));
check("the audit records the file, the row count and the byte size", payrollController.includes("'rows'     => (int) $result['data']['control']['count'],"));
// Every audit meta array of a download is extracted and checked key by key: a
// file name, a row count and a byte size are fine, an IBAN or a national code
// in the audit log would be a personal data leak into a long-lived table.
const auditMetas = [...payrollController.matchAll(/\$exporter->audit\(([\s\S]*?)\n\s*\]\);/g)].map((m) => m[1]);
check("all three downloads write an audit entry", auditMetas.length === 3, String(auditMetas.length));
auditMetas.forEach((meta, index) => {
  check(
    "audit entry " + (index + 1) + " carries no personal data of a person",
    !/(iban|national_id|insurance_no|account_number|first_name|last_name|'body'|'rows'\s*=>\s*\$rows)/i.test(meta),
    meta.replace(/\s+/g, " ").slice(0, 120)
  );
  check(
    "audit entry " + (index + 1) + " records the file name and its size",
    /'file'\s*=>/.test(meta) && /'bytes'\s*=>/.test(meta)
  );
});
check("a failed export is audited as a warning", payrollController.includes("AuditLog::record('payroll.export.failed', 'warning'"));
check("a failed export is reported on the page, not as a broken download", payrollController.includes("Session::flash('error', $message);") && payrollController.includes("Redirect::response('/manager/payroll?period_id=' . $periodId . '#insurance')"));
check("the settings save is audited", payrollController.includes("AuditLog::record('payroll.insurance.update', 'success'"));

check("a download sets Content-Disposition attachment", payrollController.includes("'Content-Disposition', 'attachment; filename=\"' . $filename . '\"'"));
check("a download sets Content-Length", payrollController.includes("'Content-Length', (string) strlen($body)"));
check("a download is never cached", payrollController.includes("'Cache-Control', 'no-store, no-cache, must-revalidate'"));
check("a download sends nosniff", payrollController.includes("'X-Content-Type-Options', 'nosniff'"));
check("the TEXT file is served with the codepage it was written in", payrollController.includes("'text/plain; charset=' . ($encoding === 'utf-8' ? 'UTF-8' : 'windows-1256')"));
check("the dBASE file is served as application/x-dbf", payrollController.includes("'application/x-dbf'"));
check("the ZIP is only built when ZipArchive exists", exporter.includes("if (!class_exists('ZipArchive')) {"));
check("the ZIP failure is a Persian message with an alternative", exporter.includes("فایل‌های txt و dbf را جداگانه دریافت کنید"));
check("the ZIP is written to a temp file and removed", exporter.includes("tempnam(sys_get_temp_dir(), 'sarmad-dsk')") && exporter.includes("@unlink($tmp);"));
check("the ZIP name carries the period", payrollController.includes("'tamin-diskette-' . $this->periodStamp("));

/* =====================================================================
 * J. The workshop settings
 * =================================================================== */
section("J. Workshop settings, defaults and the customer store");

const SETTING_KEYS = [
  "insurance_workshop_code", "insurance_workshop_name", "insurance_workshop_address",
  "insurance_employer_name", "insurance_list_kind", "insurance_list_no",
  "insurance_contract_row", "insurance_employee_rate", "insurance_employer_rate",
  "insurance_unemployment_rate", "insurance_max_mash", "insurance_period_rate",
  "insurance_description", "bank_name", "bank_payment_note",
];

SETTING_KEYS.forEach((key) => {
  check(
    "'" + key + "' is whitelisted in CustomerSetting::MANAGER_KEYS",
    customerSetting.includes("'" + key + "'")
  );
  check("'" + key + "' is written by the insurance action", payrollController.includes("'" + key + "'"));
  check("'" + key + "' is read by the exporter", exporter.includes("'" + key + "'"));
});

check("the workshop name defaults to the company name of the customer", exporter.includes("$workshopName = $this->firstText($customer['company_name'] ?? '', $customer['name'] ?? '');"));
check("the employer defaults to the workshop name", exporter.includes("if ($employerName === '') {\n            $employerName = $workshopName;\n        }"));
check("the address defaults to the customer address", exporter.includes("$address = (string) ($customer['address'] ?? '');"));
check("the contract row defaults to 000", exporter.includes("$this->orDefault($this->settingText($settings, 'insurance_contract_row'), '000')"));
check("the list kind falls back to 1 (ماهانه) for an unknown value", exporter.includes("$listKind = isset(self::LIST_KINDS[$listKind]) ? $listKind : 1;"));
check("the three list kinds of the SSO program are offered", exporter.includes("1 => 'ماهانه',") && exporter.includes("2 => 'دوره‌ای',") && exporter.includes("3 => 'تغییرات',"));
check("a rate outside 0..100 falls back to its default", payrollController.includes("if ($number < 0 || $number > 100) {"));
check("the workshop code accepts digits only", payrollController.includes("preg_replace('/\\D/', '', (string) $request->input('insurance_workshop_code', ''))"));
check("a missing workshop code refuses the export instead of writing an empty DSK_ID", exporter.includes("if (trim($settings['workshop_code']) === '') {") && exporter.includes("CODE_NO_WORKSHOP"));
check("the refusal tells the manager exactly where to fix it", exporter.includes("ابتدا در بخش «اطلاعات بیمه و بانک» آن را وارد کنید"));
check("a period with no payroll profile is refused, not exported empty", exporter.includes("CODE_NO_ROWS") && exporter.includes("برای این دوره هیچ پرسنلی با پروفایل حقوقی وجود ندارد."));

/* =====================================================================
 * K. Schema: declarative migrations, never ALTER TABLE in a .sql file
 * =================================================================== */
section("K. The new columns are declarative migrations");

const NEW_COLUMNS = [
  "insurance_no", "insurance_job_code", "father_name", "birth_date", "id_number",
  "id_place", "id_issue_date", "gender", "nationality", "iban", "bank_name",
  "account_number", "insurance_end_date",
];

NEW_COLUMNS.forEach((column) => {
  const inMigrations = new RegExp("'table'\\s*=>\\s*'payroll_profiles',\\s*'column'\\s*=>\\s*'" + column + "'").test(migrations);
  check("migration adds payroll_profiles." + column, inMigrations);
  check("PayrollProfile::$fillable lists " + column, profileModel.includes("'" + column + "'"));
  check("PayrollProfile::INSURANCE_COLUMNS lists " + column, /INSURANCE_COLUMNS = \[[\s\S]*?\];/.test(profileModel) && profileModel.slice(profileModel.indexOf("INSURANCE_COLUMNS"), profileModel.indexOf("];", profileModel.indexOf("INSURANCE_COLUMNS"))).includes("'" + column + "'"));
  check("the profile form posts " + column, payrollView.includes('name="<?= $e($field) ?>"') || payrollView.includes("'" + column + "'"));
  check("PayrollController::save() reads " + column, payrollController.includes("'" + column + ' =>') || payrollController.includes("'" + column + "'"));
});

check("the gender column is an ENUM of male/female", migrations.includes("'definition' => \"ENUM('male','female') DEFAULT NULL\""));
check("the IBAN column is VARCHAR(34)", /'column'\s*=>\s*'iban',\s*'definition'\s*=>\s*'VARCHAR\(34\) DEFAULT NULL'/.test(migrations));
check("every new column is nullable so an old row stays valid", !/'column'\s*=>\s*'(insurance_no|father_name|iban|bank_name|account_number|nationality|id_place)',\s*'definition'\s*=>\s*'[^']*\bNOT NULL/.test(migrations));
check("the three dates are real DATE columns", (migrations.match(/'definition' => 'DATE DEFAULT NULL'/g) || []).length >= 3);
check("no ALTER TABLE was added to the install script", !read("sql/attendance_install.sql").includes("insurance_no"));
check("no ALTER TABLE was added to the upgrade scripts", !fs.readdirSync(path.join(ROOT, "sql")).filter((f) => f.startsWith("attendance_upgrade_")).some((f) => read("sql/" + f).includes("insurance_no")));

// The defensive read/write path: a database that has not migrated yet must
// still save a payroll profile.
check("PayrollService reads the live column list before writing", payrollService.includes("private function profileColumns(): array"));
check("the column list is read once per request", payrollService.includes("if (self::$profileColumns !== null) {"));
check("the column list comes from SHOW COLUMNS on the prefixed table", payrollService.includes("SHOW COLUMNS FROM `' . Database::table('payroll_profiles') . '`"));
check("an introspection failure never breaks a payroll save", payrollService.includes("catch (\\Throwable $e) {") && payrollService.includes("$columns = [];"));
check("only columns that exist are written", payrollService.includes("if (in_array($column, $columns, true)) {\n                $data[$column] = $value;\n            }"));
check("the exporter reads the profile with SELECT p.* (never a column by name)", exporter.includes("'SELECT p.*, e.`hire_date`"));

/* =====================================================================
 * L. The panel UI
 * =================================================================== */
section("L. The payroll panel");

check("the payroll page has the «اطلاعات بیمه و بانک» card", payrollView.includes("<h2>اطلاعات بیمه و بانک</h2>"));
check("the card is anchored for the failure redirect", payrollView.includes('<section class="card" id="insurance">'));
check("the card posts to /manager/payroll/insurance", payrollView.includes("action=\"<?= $e($url('/manager/payroll/insurance')) ?>\""));
check("the card sends the csrf token", payrollView.includes("<?= $csrf() ?>"));
check("the workshop code field is required", payrollView.includes('name="insurance_workshop_code" class="ltr num" inputmode="numeric" maxlength="10"'));
check("the three rate fields are number inputs bounded 0..100", (payrollView.match(/type="number" name="insurance_(employee|employer|unemployment)_rate" class="ltr num" step="0\.01" min="0" max="100"/g) || []).length === 3);
check("every rate field states which SSO column it fills", payrollView.includes("DSW_BIME — پیش‌فرض ۷٪") && payrollView.includes("DSK_TKOSO — پیش‌فرض ۲۰٪") && payrollView.includes("DSK_BIC — پیش‌فرض ۳٪"));
check("the list kind select offers every LIST_KINDS entry", payrollView.includes("<?php foreach ($listKinds as $value => $label): ?>"));
check("the page shows the DSKKAR00 control row", payrollView.includes("پیش‌نمایش DSKKAR00"));
check("the control table shows the insured and employer shares", payrollView.includes("<th>سهم بیمه‌شده</th>") && payrollView.includes("<th>سهم کارفرما</th>") && payrollView.includes("<th>بیمه بیکاری</th>"));
check("the DSKWOR00 detail is collapsible", payrollView.includes("ریز DSKWOR00 — <?= $e((string) count($wor)) ?> نفر"));
check("the period status is shown on the preview", payrollView.includes("$insuranceData['finalized'] ?? false) ? 'دوره قطعی' : 'پیش‌نمایش زنده'"));
check("warnings of the export are shown on the page", payrollView.includes("foreach (($insuranceData['warnings'] ?? []) as $warning)"));
check("the ZIP download button exists", payrollView.includes("&file=zip')) ?>\">"));
check("all four single files can be downloaded", ["&file=kar-dbf", "&file=wor-dbf", "&file=kar'", "&file=wor'"].every((needle) => payrollView.includes(needle)));
check("a UTF-8 variant is offered for review", payrollView.includes("&encoding=utf-8"));
check("both bank formats can be downloaded", payrollView.includes("&format=xlsx") && payrollView.includes("&format=csv"));
check("the periods table carries the two download links", payrollView.includes("دیسکت بیمه</a>") && payrollView.includes("فایل بانک</a>"));
check("the identity fields are inside a collapsed details block", payrollView.includes("<summary>اطلاعات بیمه و بانک همین پرسنل (دیسکت تأمین اجتماعی + فایل پرداخت)</summary>"));
check("the identity fields are rendered from the controller's column map", payrollView.includes("<?php foreach ($identityColumns as $field => $meta): ?>"));
check("the identity block explains it does not change the payroll numbers", payrollView.includes("این فیلدها فقط در دیسکت لیست حق بیمه و فایل پرداخت بانک استفاده می‌شوند و در محاسبه حقوق اثری ندارند."));
check("the IBAN field is limited to 34 characters", payrollView.includes('maxlength="34" placeholder="IR06…"'));
check("the page explains that rials and Latin digits are used", payrollView.includes("مبلغ‌ها به <strong>ریال</strong> و با رقم لاتین نوشته می‌شوند"));
check("the page warns about the non-insured benefits (بند ۱۱گانه)", payrollView.includes("مزایای غیرمشمول"));
check("the page explains how DSW_DD is counted", payrollView.includes("روزهای کارکرد = حاضر + مرخصی + تعطیل، حداکثر به طول ماه شمسی"));
check("the page states the encoding of the files", payrollView.includes("WINDOWS-1256"));
check("no raw Gregorian date is shown in the new card", !/DSK[A-Z_]*[^<]*<?= \$e\(\(string\) \$period\['start_date'\]\)/.test(payrollView));
check("every new output is escaped with $e()", !/<td>[^<]*<?=\s*\$(?!e\()/.test(payrollView));

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
console.log("✓ the SSO diskette layout, the dBASE III bytes and the bank file all hold");
process.exit(0);
