// Run with Node; no server, browser or database required.
//
// STAGE 21 - rigid logo sizing, standard badge card dimensions and cache
// busting for every stylesheet.
//
//   node tests/stage21_assets_test.js
//
// The suite reads the real markup and CSS that ships to the host, so it fails
// the moment one of these contracts is edited away:
//
//   1. every logo <img> carries its size INLINE (42x130, contain, block),
//      independent of any stylesheet,
//   2. the manager sidebar logo window is a hard 132x44 box with overflow:
//      hidden, so an upload can never push the menu or the dashboard buttons,
//   3. the owner preview is a 160x60 frame,
//   4. the badge card is <=320px on screen and exactly 80mm wide on paper with
//      a small lockup and a proportional QR symbol,
//   5. every stylesheet link is versioned `?v=1.2.1` exactly once, and the
//      service worker precaches that same URL.

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

/* ---------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------- */

// The exact inline declaration STAGE 21 pins on a logo <img>.
const INLINE_42x130 =
  "max-height: 42px; max-width: 130px; width: auto; height: auto; object-fit: contain; display: block;";
const INLINE_60x160 =
  "max-height: 60px; max-width: 160px; width: auto; height: auto; object-fit: contain; display: block;";

/** All <img> tags of a view (tags may span several lines AND contain PHP
 *  such as `<?= $e($url) ?>`, whose closing `?>` must not end the tag). */
function imgTags(source) {
  const tags = [];
  const re = /<img\b[\s\S]*?(?<!\?)>/g;
  let match;
  while ((match = re.exec(source)) !== null) tags.push(match[0]);
  return tags;
}

/** The value of an attribute inside one tag ("" when absent). */
function attr(tag, name) {
  const match = tag.match(new RegExp("\\b" + name + "\\s*=\\s*\"([^\"]*)\"", "s"));
  return match ? match[1] : "";
}

/** The CSS block of the first rule whose selector matches exactly. */
function cssBlock(css, selector) {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const match = css.match(new RegExp("(?:^|\\n)\\s*" + escaped + "\\s*\\{([^}]*)\\}", "s"));
  return match ? match[1] : "";
}

/** The body of every @media print block concatenated. */
function printCss(css) {
  const out = [];
  const re = /@media\s+print\s*\{/g;
  let match;
  while ((match = re.exec(css)) !== null) {
    let depth = 1;
    let i = re.lastIndex;
    while (i < css.length && depth > 0) {
      const ch = css[i];
      if (ch === "{") depth += 1;
      else if (ch === "}") depth -= 1;
      i += 1;
    }
    out.push(css.slice(re.lastIndex, i - 1));
  }
  return out.join("\n");
}

/* ---------------------------------------------------------------------
 * A. Inline containment on every logo image
 * ------------------------------------------------------------------- */

section("A. Logo images carry their own hard size (inline, no CSS needed)");

const layoutsApp = read("views/layouts/app.php");
const ownerForm = read("views/owner/customers/form.php");
const kioskView = read("views/device/kiosk.php");
const badgeView = read("views/manager/employees/profile.php");
const ownerShow = read("views/owner/customers/show.php");
const settingsView = read("views/manager/settings/index.php");

const sidebarLogo = imgTags(layoutsApp).find((tag) => attr(tag, "class").includes("m-brand-logo"));
check("the sidebar logo <img> exists", Boolean(sidebarLogo));
check(
  "the sidebar logo is capped inline at 42x130",
  sidebarLogo && attr(sidebarLogo, "style").includes(INLINE_42x130),
);

const letterheadLogo = imgTags(layoutsApp).find(
  (tag) => attr(tag, "class").trim() === "brand-logo",
);
check(
  "the printed letterhead logo is capped inline too",
  letterheadLogo && attr(letterheadLogo, "style").includes(INLINE_42x130),
);

const kioskLogo = imgTags(kioskView).find((tag) => attr(tag, "class").includes("k-logo"));
check("the kiosk logo <img> exists", Boolean(kioskLogo));
check(
  "the kiosk logo is capped inline at 42x130",
  kioskLogo && attr(kioskLogo, "style").includes(INLINE_42x130),
);
check(
  "the kiosk logo stays hidden until a real logo arrives",
  kioskLogo && /\bhidden\b/.test(kioskLogo),
);

const badgeLogo = imgTags(badgeView).find((tag) => attr(tag, "class").includes("brand-logo"));
check("the badge card logo <img> exists", Boolean(badgeLogo));
check(
  "the badge card logo is capped inline at 42x130",
  badgeLogo && attr(badgeLogo, "style").includes(INLINE_42x130),
);

const formLogo = imgTags(ownerForm).find((tag) => attr(tag, "class").includes("brand-logo"));
check("the owner preview logo <img> exists", Boolean(formLogo));
check(
  "the owner preview logo is contained in 60x160 inline",
  formLogo && attr(formLogo, "style").includes(INLINE_60x160),
);

const previewFrame = ownerForm.match(/<div class="o-logo-frame"[^>]*>/s);
check("the owner preview has a dedicated frame", Boolean(previewFrame));
check(
  "the owner preview frame is 160x60 with overflow hidden",
  previewFrame && /max-height:\s*60px/.test(previewFrame[0]) &&
    /max-width:\s*160px/.test(previewFrame[0]) &&
    /overflow:\s*hidden/.test(previewFrame[0]),
);

const showLogo = imgTags(ownerShow).find((tag) => attr(tag, "class").includes("brand-logo"));
check(
  "the owner customer page logo is capped inline as well",
  showLogo && attr(showLogo, "style").includes(INLINE_42x130),
);

const settingsLogo = imgTags(settingsView).find((tag) => attr(tag, "class").includes("brand-logo"));
check(
  "the read-only manager preview logo is capped inline as well",
  settingsLogo && attr(settingsLogo, "style").includes(INLINE_42x130),
);

/* No logo image anywhere in the views may escape the rule - this is the
   regression net: a new screen cannot silently ship an unbounded <img>. */
const viewFiles = [];
(function walk(dir) {
  for (const entry of fs.readdirSync(path.join(ROOT, dir), { withFileTypes: true })) {
    const rel = dir + "/" + entry.name;
    if (entry.isDirectory()) walk(rel);
    else if (entry.name.endsWith(".php")) viewFiles.push(rel);
  }
})("views");

const offenders = [];
for (const file of viewFiles) {
  for (const tag of imgTags(read(file))) {
    const cls = attr(tag, "class");
    if (!/(^|\s)(brand-logo|k-logo)(\s|$)/.test(cls)) continue;
    const style = attr(tag, "style");
    const ok =
      style.includes("object-fit: contain") &&
      /max-height:\s*\d+px/.test(style) &&
      /max-width:\s*\d+px/.test(style) &&
      /width:\s*auto/.test(style) &&
      /height:\s*auto/.test(style) &&
      /display:\s*block/.test(style);
    if (!ok) offenders.push(file + " → " + tag.replace(/\s+/g, " ").slice(0, 90));
  }
}
check(
  "every logo <img> in views/ is size-capped inline",
  offenders.length === 0,
  offenders.join(" | "),
);

/* ---------------------------------------------------------------------
 * B. The manager sidebar cannot be stretched
 * ------------------------------------------------------------------- */

section("B. Sidebar brand mark = hard box (overflow: hidden)");

const managerCss = read("assets/css/manager.css");

const mark = cssBlock(managerCss, ".m-brand-mark");
check(".m-brand-mark has a fixed width/height", /width:\s*44px/.test(mark) && /height:\s*44px/.test(mark));
check(".m-brand-mark has hard max dimensions", /max-width:\s*132px/.test(mark) && /max-height:\s*44px/.test(mark));
check(".m-brand-mark hides any overflow", /overflow:\s*hidden/.test(mark));
check(".m-brand-mark cannot be squeezed by the flex row", /flex:\s*0\s+0\s+auto/.test(mark));

const markWithImage = cssBlock(managerCss, ".m-brand-mark:has(img)");
check(
  "the logo window is exactly 132x44",
  /width:\s*132px/.test(markWithImage) && /height:\s*44px/.test(markWithImage),
);
check(
  "the logo window adds no padding that would clip the image",
  /padding:\s*0/.test(markWithImage),
);
check(
  "the logo window keeps overflow hidden",
  /overflow:\s*hidden/.test(markWithImage) || /overflow:\s*hidden/.test(mark),
);

const markImg = cssBlock(managerCss, ".m-brand-mark img");
check(
  "the sidebar <img> is contained (never cropped, never stretched)",
  /object-fit:\s*contain/.test(markImg) && /width:\s*auto/.test(markImg) && /height:\s*auto/.test(markImg),
);
check(
  "the sidebar <img> is capped at 130x42 in CSS too",
  /max-width:\s*130px/.test(markImg) && /max-height:\s*42px/.test(markImg),
);

/* ---------------------------------------------------------------------
 * C. Badge card: 320px on screen, 80mm on paper
 * ------------------------------------------------------------------- */

section("C. Standard staff card dimensions");

const card = cssBlock(managerCss, ".qr-badge-card");
check("the on-screen card is at most 320px wide", /max-width:\s*320px/.test(card));
check("the on-screen symbol is 148px (fits the 320px card on one row)", /width:\s*148px/.test(cssBlock(managerCss, ".qr-code")));
check("the card is centred", /margin:\s*14px\s+auto/.test(card));
check("the card keeps box-sizing: border-box", /box-sizing:\s*border-box/.test(card));

const print = printCss(managerCss);
const printCard = print.match(/\.qr-badge-card\s*\{([^}]*)\}/s);
check("the card has print rules", Boolean(printCard));
check(
  "the printed card is exactly 80mm wide",
  printCard && /width:\s*80mm/.test(printCard[1]) && /max-width:\s*80mm/.test(printCard[1]),
);
check(
  "the printed card is at least 54mm tall (real card height)",
  printCard && /min-height:\s*54mm/.test(printCard[1]),
);
check(
  "the printed card never splits across pages",
  printCard && /break-inside:\s*avoid/.test(printCard[1]) && /page-break-inside:\s*avoid/.test(printCard[1]),
);
check("the printed card is centred on the sheet", printCard && /margin:\s*0\s+auto/.test(printCard[1]));
check("the printed card is white with a thin black border",
  printCard && /background:\s*#fff/.test(printCard[1]) && /border:\s*0?\.?4mm\s+solid\s+#111/.test(printCard[1]));

const printLogo = print.match(/\.qr-badge-card img,[^{]*\{([^}]*)\}/s);
check("the printed card has a dedicated small logo rule", Boolean(printLogo));
check(
  "the printed logo overrides the inline cap (28px / 90px, contain)",
  printLogo &&
    /max-height:\s*28px\s*!important/.test(printLogo[1]) &&
    /max-width:\s*90px\s*!important/.test(printLogo[1]) &&
    /object-fit:\s*contain\s*!important/.test(printLogo[1]),
);

check(
  "the printed card stacks logo / symbol / person (a long name cannot overflow)",
  /\.qr-badge-card \.qr-card-middle\s*\{[^}]*flex-direction:\s*column/s.test(print),
);

const printQr = print.match(/\.qr-badge-card \.qr-code\s*\{([^}]*)\}/s);
check(
  "the printed symbol is a proportional 34mm square",
  printQr && /width:\s*34mm/.test(printQr[1]) && /height:\s*34mm/.test(printQr[1]),
);
check(
  "the printed symbol never splits across pages",
  printQr && /break-inside:\s*avoid/.test(printQr[1]),
);
check(
  "the panel chrome is dropped when printing the card",
  /\.qr-card \.card-head\s*\{[^}]*display:\s*none\s*!important/s.test(print),
);
check(
  "only the badge card reaches the printer",
  /\.page-head[^{]*\{[^}]*display:\s*none\s*!important/s.test(print) &&
    /\.m-print-brand\s*\{[^}]*display:\s*none\s*!important/s.test(print),
);

/* The card markup still carries what the print rules size. */
check("the badge card has a brand lockup", /class="qr-card-brand"/.test(badgeView));
check("the badge card has the QR container", /class="qr-code"[^>]*data-qr-badge=/.test(badgeView));
check("the badge card prints the person", /class="qr-card-person"/.test(badgeView));
check("the badge card renders an inline SVG (no image file)", /qr-badge\.js/.test(badgeView));

/* ---------------------------------------------------------------------
 * D. Cache busting for every stylesheet
 * ------------------------------------------------------------------- */

section("D. Every stylesheet is ?v=1.2.1");

const urlSource = read("app/Support/Url.php");
const viewSource = read("app/Core/View.php");
const layoutOwner = read("views/layouts/owner.php");
const layoutDevice = read("views/layouts/device.php");
const layoutBlank = read("views/layouts/blank.php");
const landing = read("views/landing.php");

check(
  "Url::CSS_VERSION is the single source of truth (1.2.1)",
  /public\s+const\s+CSS_VERSION\s*=\s*'1\.2\.1'/.test(urlSource),
);
check(
  "Url::css() pins the stylesheet to that constant",
  /public static function css\(string \$path\): string\s*\{[^}]*asset\(\$path,\s*self::CSS_VERSION\)/s.test(urlSource),
);
check(
  "Url::asset() accepts an explicit version",
  /public static function asset\(string \$path,\s*\?string \$version = null\)/.test(urlSource),
);
check(
  "Url::asset() strips a query the caller pasted in",
  /preg_split\('\/\[\?#\]\/'/.test(urlSource),
);
check(
  "Url::asset() builds exactly one ?v= parameter",
  /'\?v='\s*\.\s*rawurlencode\(\$version\)/.test(urlSource) &&
    !/\?v='[^;]*&v=/.test(urlSource),
);
check(
  "views expose the css() helper",
  /'css'\s*=>\s*static fn \(string \$path\): string => .*Url::css\(\$path\)/.test(viewSource),
);
check(
  "views can pass an explicit version to asset()",
  /'asset'\s*=>\s*static fn \(string \$path, \?string \$version = null\)/.test(viewSource),
);

const layoutFiles = {
  "views/layouts/app.php": layoutsApp,
  "views/layouts/owner.php": layoutOwner,
  "views/layouts/device.php": layoutDevice,
};
for (const [file, source] of Object.entries(layoutFiles)) {
  const links = source.match(/<link\b[^>]*rel="stylesheet"[^>]*>/gs) || [];
  check(file + " has at least one stylesheet link", links.length > 0);
  for (const link of links) {
    check(
      file + " versions the stylesheet through $css(): " + (attr(link, "href") || "?"),
      link.includes("$css("),
    );
  }
  check(file + " never appends a second &v= parameter", !source.includes("&v="));
}

check("the login/auth layout is versioned as well", layoutBlank.includes("$css("));
check("the landing page stylesheet is versioned as well", landing.includes("$css("));

const allViews = viewFiles.map((file) => [file, read(file)]);
const doubleVersion = allViews.filter(([, source]) => /\)\s*\?>\s*&v=/.test(source));
check(
  "no view appends &v= after a helper call (the old double-parameter bug)",
  doubleVersion.length === 0,
  doubleVersion.map(([file]) => file).join(", "),
);
check(
  "the CSS_VERSION literal appears in exactly one place (Url.php)",
  !allViews.some(([, source]) => source.includes("'1.2.1'")),
);

/* The kiosk scripts keep their own explicit versions - through $asset(), not
   through a hand-written query string. */
for (const [file, version] of [
  ["/js/device.js", "1.2.0"],
  ["/js/device-qr.js", "1.2.0"],
  ["/js/device-queue.js", "1.1.0"],
  ["/js/face/face-engine.js", "1.1.0"],
  ["/js/face/face-store.js", "1.1.0"],
]) {
  check(
    "device.php passes " + version + " to $asset(" + file + ")",
    layoutDevice.includes("$asset('" + file + "', '" + version + "')"),
  );
}

/* ---------------------------------------------------------------------
 * E. Service worker serves the same URLs the pages ask for
 * ------------------------------------------------------------------- */

section("E. Service worker cache is bumped for the new CSS");

const sw = read("sw.js");
check("the kiosk cache is v12", /const CACHE = 'sarmad-kiosk-v12';/.test(sw));
check("v11 is kept as a model donor during the upgrade", /PREVIOUS_MODEL_CACHES = \['sarmad-kiosk-v11'/.test(sw));
check(
  "the shell precaches the exact stylesheet URL",
  sw.includes("'./assets/css/device.css?v=1.2.1'"),
);
check(
  "the shell still precaches the badge modules",
  sw.includes("jsQR.min.js") && sw.includes("device-qr.js?v=1.2.0") && sw.includes("device.js?v=1.2.0"),
);
check(
  "shell assets are matched ignoring the query string",
  /ignoreSearch:\s*shellAsset/.test(sw),
);

/* ---------------------------------------------------------------------
 * F. Nothing regressed in the "never crop" rules of stage 20
 * ------------------------------------------------------------------- */

section("F. Stage 20 logo rules are still intact");

const appCss = read("assets/css/app.css");
const deviceCss = read("assets/css/device.css");
const ownerCss = read("assets/css/owner.css");

const brandLogo = cssBlock(appCss, ".brand-logo");
check(".brand-logo is contained", /object-fit:\s*contain/.test(brandLogo));
check(".brand-logo uses width/height auto", /width:\s*auto/.test(brandLogo) && /height:\s*auto/.test(brandLogo));

const kioskCss = cssBlock(deviceCss, "img.k-logo");
check("img.k-logo stays contained", /object-fit:\s*contain/.test(kioskCss));
check(
  "img.k-logo uses the same 130x42 cap as the inline style",
  /max-width:\s*130px/.test(kioskCss) && /max-height:\s*42px/.test(kioskCss),
);
check("no kiosk rule crops the logo with cover", !/\.k-logo\s*\{[^}]*object-fit:\s*cover/s.test(deviceCss));

check("owner.css defines the preview frame", /\.o-logo-frame\s*\{/.test(ownerCss));
check(
  "the owner frame contains the logo instead of cropping it",
  /\.o-logo-frame \.brand-logo\s*\{[^}]*object-fit:\s*contain/s.test(ownerCss),
);
check(
  "the owner list thumbnail is still a fixed 34px box",
  /\.o-logo\s*\{[^}]*width:\s*34px[^}]*object-fit:\s*contain/s.test(ownerCss),
);

/* ---------------------------------------------------------------------
 * Summary
 * ------------------------------------------------------------------- */

console.log("\n" + "-".repeat(64));
if (failures.length === 0) {
  console.log("passed: " + passed + "   failed: 0");
  console.log("ALL STAGE 21 ASSET TESTS PASSED");
  process.exit(0);
}

console.log("passed: " + passed + "   failed: " + failures.length);
console.log("\nfailures:");
for (const failure of failures) console.log("  - " + failure);
process.exit(1);
