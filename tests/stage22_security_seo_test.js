// Run with Node; no server, browser or database required.
//
//   node tests/stage22_security_seo_test.js
//
// STAGE 22 - security headers (A+), Google SEO (100/100) and accessibility
// (100/100) for the ONE public page of the product.
//
// The suite reads the real files that ship to the host, so it fails the
// moment one of these contracts is edited away:
//
//   1. /.htaccess publishes ONE enforcing Content-Security-Policy plus the
//      full graded header set (HSTS, XFO, XCTO, Referrer-Policy,
//      Permissions-Policy, X-XSS-Protection), and steps aside for /device so
//      the kiosk keeps its stricter policy,
//   2. App\Core\Security stays byte-for-byte in sync with .htaccess (the PHP
//      copy is the fallback for hosts without mod_headers), and the kiosk
//      policy of DeviceKioskPageController is a SUBSET of the site policy,
//   3. /robots.txt opens the landing page to crawlers (Allow: /$) while every
//      panel, kiosk, API, installer and internal path stays blocked -
//      verified with a real longest-match robots resolver, not a grep,
//   4. views/landing.php carries an absolute canonical, index directives,
//      Open Graph + Twitter cards and schema.org JSON-LD,
//   5. no element in views/landing.php carries aria-label/aria-labelledby
//      that its role is not allowed to carry (the div and ul errors Google
//      reported), no landmark is nested in a landmark, and nothing focusable
//      hides inside an aria-hidden subtree,
//   6. the landing CSS keeps its 48px tap targets and legible font sizes, and
//      its text colours keep a WCAG AA contrast ratio on the dark theme.

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
 * Shared helpers
 * =================================================================== */

/** One Content-Security-Policy string -> { directive: [tokens] }. */
function parseCsp(value) {
  const out = {};
  value
    .split(";")
    .map((part) => part.trim())
    .filter(Boolean)
    .forEach((directive) => {
      const tokens = directive.split(/\s+/);
      out[tokens[0]] = tokens.slice(1);
    });
  return out;
}

/** Every single/double quoted PHP literal of a method body, joined. */
function phpLiterals(source, method, quote) {
  const start = source.indexOf("function " + method + "(");
  if (start === -1) return null;
  const open = source.indexOf("{", start);
  let depth = 0;
  let end = -1;
  for (let i = open; i < source.length; i += 1) {
    if (source[i] === "{") depth += 1;
    else if (source[i] === "}") {
      depth -= 1;
      if (depth === 0) {
        end = i;
        break;
      }
    }
  }
  if (end === -1) return null;
  const body = source.slice(open, end);
  const re = new RegExp(quote + "((?:[^" + quote + "\\\\]|\\\\.)*)" + quote, "g");
  const parts = [];
  let match;
  while ((match = re.exec(body)) !== null) parts.push(match[1]);
  return parts;
}

/**
 * HTML tag tokenizer that survives PHP: `<?= ... ?>` inside an attribute is
 * kept as text, and a closing `?>` must not end the tag (the `(?<!\?)>`
 * guard). PHP control blocks are dropped first so both branches of an
 * `<?php if (): ?>` are inspected.
 */
function tokenize(html) {
  const source = html
    .replace(/<\?php[\s\S]*?\?>/g, "")
    .replace(/<\?=[\s\S]*?\?>/g, "__PHP__");
  const tags = [];
  const re = /<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|'[^']*'|[^>"'])*)>/g;
  let match;
  while ((match = re.exec(source)) !== null) {
    const attrs = {};
    const attrRe = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|'([^']*)'|([^\s">']+)))?/g;
    let a;
    while ((a = attrRe.exec(match[3])) !== null) {
      attrs[a[1].toLowerCase()] = a[2] ?? a[3] ?? a[4] ?? "";
    }
    tags.push({
      raw: match[0],
      closing: match[1] === "/",
      name: match[2].toLowerCase(),
      attrs,
      index: match.index,
    });
  }
  return tags;
}

/** Walk the tag stream and hand every open tag its ancestor chain. */
function walk(tags, visit) {
  const stack = [];
  tags.forEach((tag) => {
    if (tag.closing) {
      for (let i = stack.length - 1; i >= 0; i -= 1) {
        if (stack[i].name === tag.name) {
          stack.length = i;
          break;
        }
      }
      return;
    }
    visit(tag, stack.slice());
    if (!VOID_TAGS.has(tag.name) && !tag.raw.endsWith("/>")) stack.push(tag);
  });
}

const VOID_TAGS = new Set([
  "area", "base", "br", "col", "embed", "hr", "img", "input", "link",
  "meta", "param", "source", "track", "wbr",
]);

/**
 * Longest-match robots resolver (RFC 9309 / Googlebot): `*` is a wildcard,
 * a trailing `$` anchors the end, the longest matching pattern wins and a
 * tie is resolved in favour of Allow.
 */
function robotsRules(text) {
  const rules = [];
  let active = false;
  text.split(/\r?\n/).forEach((rawLine) => {
    const line = rawLine.replace(/#.*$/, "").trim();
    if (line === "") return;
    const sep = line.indexOf(":");
    if (sep === -1) return;
    const field = line.slice(0, sep).trim().toLowerCase();
    const value = line.slice(sep + 1).trim();
    if (field === "user-agent") {
      active = value === "*";
      return;
    }
    if (!active) return;
    if (field === "allow") rules.push({ allow: true, pattern: value });
    if (field === "disallow") rules.push({ allow: false, pattern: value });
  });
  return rules;
}

function robotsRegex(pattern) {
  const anchored = pattern.endsWith("$");
  const body = anchored ? pattern.slice(0, -1) : pattern;
  const escaped = body
    .split("*")
    .map((chunk) => chunk.replace(/[.+?^{}()|[\]\\]/g, "\\$&"))
    .join(".*");
  return new RegExp("^" + escaped + (anchored ? "$" : ""));
}

function robotsAllowed(url, rules) {
  const pathOnly = url.split("?")[0].split("#")[0];
  let best = null;
  rules.forEach((rule) => {
    if (rule.pattern === "") return; // "Disallow:" alone = allow everything
    if (!robotsRegex(rule.pattern).test(pathOnly)) return;
    if (
      best === null ||
      rule.pattern.length > best.pattern.length ||
      (rule.pattern.length === best.pattern.length && rule.allow)
    ) {
      best = rule;
    }
  });
  return best === null ? true : best.allow;
}

/* WCAG 2.x relative luminance + contrast ratio. */
function luminance(hex) {
  const int = parseInt(hex.slice(1), 16);
  const channel = (shift) => {
    const c = ((int >> shift) & 255) / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(16) + 0.7152 * channel(8) + 0.0722 * channel(0);
}

function contrast(a, b) {
  const l1 = luminance(a);
  const l2 = luminance(b);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

/** alpha-composite an rgba() colour over a hex background. */
function blend(fgHex, alpha, bgHex) {
  const f = parseInt(fgHex.slice(1), 16);
  const b = parseInt(bgHex.slice(1), 16);
  const ch = (shift) =>
    Math.round(((f >> shift) & 255) * alpha + ((b >> shift) & 255) * (1 - alpha));
  return (
    "#" +
    [16, 8, 0]
      .map((shift) => ch(shift).toString(16).padStart(2, "0"))
      .join("")
  );
}

/* =====================================================================
 * 1. .htaccess - the graded security header set
 * =================================================================== */
const htaccess = read(".htaccess");

section("1. .htaccess security headers");

const cspLine = htaccess
  .split(/\r?\n/)
  .find((line) => /^\s*Header always set Content-Security-Policy\b/.test(line));

check(".htaccess sets a Content-Security-Policy", Boolean(cspLine));
check(
  "the CSP is in ENFORCEMENT mode (not Content-Security-Policy-Report-Only)",
  Boolean(cspLine) && !/Report-Only/i.test(cspLine)
);
check(
  "the CSP lives inside <IfModule mod_headers.c> (never a 500 without mod_headers)",
  /<IfModule mod_headers\.c>[\s\S]*?Header always set Content-Security-Policy[\s\S]*?<\/IfModule>/.test(
    htaccess
  )
);
check(
  "the CSP is ONE physical line (Apache would break a multi-line header value)",
  Boolean(cspLine) && !cspLine.trimEnd().endsWith("\\")
);
check(
  "the CSP header value contains no double quote (it would close the Apache string)",
  Boolean(cspLine) &&
    (cspLine.match(/"/g) || []).length === 2 + (/env=/.test(cspLine) ? 0 : 0)
);

const cspValue = cspLine ? /^.*Content-Security-Policy "([^"]*)"/.exec(cspLine)[1] : "";
const siteCsp = parseCsp(cspValue);

const expectedCsp = {
  "default-src": ["'self'"],
  "script-src": ["'self'", "'unsafe-inline'"],
  "style-src": ["'self'", "'unsafe-inline'"],
  "img-src": ["'self'", "data:", "blob:"],
  "media-src": ["'self'", "data:", "blob:", "mediastream:"],
  "font-src": ["'self'", "data:"],
  "connect-src": ["'self'", "data:", "blob:"],
  "worker-src": ["'self'", "blob:"],
  "manifest-src": ["'self'"],
  "object-src": ["'none'"],
  "base-uri": ["'self'"],
  "form-action": ["'self'"],
  "frame-ancestors": ["'self'"],
};

Object.entries(expectedCsp).forEach(([directive, tokens]) => {
  check(
    "CSP has " + directive + " " + tokens.join(" "),
    JSON.stringify(siteCsp[directive] || []) === JSON.stringify(tokens),
    "found: " + JSON.stringify(siteCsp[directive] || null)
  );
});

check("CSP declares no directive twice", Object.keys(siteCsp).length === cspValue.split(";").filter((p) => p.trim()).length);
check("CSP forbids 'unsafe-eval' (tfjs/face-api/jsQR need no eval)", !cspValue.includes("'unsafe-eval'"));
check("CSP has no wildcard source", !/(^|\s)\*(\s|$)/.test(cspValue));
check("CSP names no remote origin", !/https?:\/\//.test(cspValue));
check("CSP allows no http: source", !/(^|\s)http:(\s|$)/.test(cspValue));
check("script-src allows no data: URL", !(siteCsp["script-src"] || []).includes("data:"));
check("object-src is 'none' (no plugin content)", siteCsp["object-src"][0] === "'none'");
check("frame-ancestors is 'self' (clickjacking)", siteCsp["frame-ancestors"][0] === "'self'");
check(
  "the policy keeps the kiosk camera alive (media-src has mediastream:)",
  siteCsp["media-src"].includes("mediastream:")
);
check(
  "the service worker stays same-origin (worker-src 'self')",
  siteCsp["worker-src"].includes("'self'")
);

// The kiosk must not lose its stricter policy: `Header set` REPLACES a header
// of the same name, so the site-wide policy has to step aside for /device.
const kioskMarker = htaccess
  .split(/\r?\n/)
  .find((line) => /SetEnvIfNoCase\s+Request_URI/.test(line) && /device/.test(line));
check(
  ".htaccess marks the kiosk URLs with mod_setenvif (Apache 2.0+, no <If>)",
  Boolean(kioskMarker) && /SARMAD_KIOSK_PAGE/.test(kioskMarker),
  kioskMarker
);
check(
  "the marker is guarded by <IfModule mod_setenvif.c>",
  /<IfModule mod_setenvif\.c>[\s\S]*?SetEnvIfNoCase[\s\S]*?<\/IfModule>/.test(htaccess)
);
check(
  "the site-wide CSP is skipped for the kiosk (env=!SARMAD_KIOSK_PAGE)",
  Boolean(cspLine) && /env=!SARMAD_KIOSK_PAGE/.test(cspLine)
);
check(
  "the kiosk marker cannot swallow /manager/devices or /assets/js/device.js",
  Boolean(kioskMarker) && /\/device\(\/\|\\\?\|\$\)/.test(kioskMarker)
);
check(
  ".htaccess uses no Apache 2.4 <If> expression (shared hosts may run 2.2)",
  !/^\s*<If[>\s]/m.test(htaccess)
);

const headerLine = (name) =>
  htaccess
    .split(/\r?\n/)
    .find((line) => new RegExp("^\\s*Header always set " + name + "\\b", "i").test(line));

const expectedHeaders = {
  "X-Content-Type-Options": ["nosniff"],
  "X-Frame-Options": ["SAMEORIGIN"],
  "Referrer-Policy": ["strict-origin-when-cross-origin"],
  "X-Permitted-Cross-Domain-Policies": ["none"],
  "X-XSS-Protection": ["0"],
};

Object.entries(expectedHeaders).forEach(([name, values]) => {
  const line = headerLine(name);
  check(".htaccess sets " + name + " " + values[0], Boolean(line));
  if (line) {
    const value = /"([^"]*)"/.exec(line);
    check(
      name + " has the expected value",
      value !== null && values.includes(value[1]),
      value ? value[1] : "unquoted"
    );
  }
});

const permissionsLine = headerLine("Permissions-Policy");
check(".htaccess sets Permissions-Policy", Boolean(permissionsLine));
const htPermissions = permissionsLine ? /"([^"]*)"/.exec(permissionsLine)[1] : "";
check("Permissions-Policy keeps the kiosk camera: camera=(self)", /camera=\(self\)/.test(htPermissions));
check("Permissions-Policy refuses the microphone", /microphone=\(\)/.test(htPermissions));
check("Permissions-Policy refuses geolocation", /geolocation=\(\)/.test(htPermissions));
check(
  "Permissions-Policy never switches off fullscreen (the kiosk uses it)",
  !/fullscreen=\(\)/.test(htPermissions)
);

const hstsLine = headerLine("Strict-Transport-Security");
check(".htaccess sets Strict-Transport-Security", Boolean(hstsLine));
check(
  "HSTS is sent only on a TLS connection (env=HTTPS)",
  Boolean(hstsLine) && /env=HTTPS\b/.test(hstsLine)
);
check(
  "HSTS max-age is at least one year",
  Boolean(hstsLine) && /max-age=(\d+)/.test(hstsLine) && Number(/max-age=(\d+)/.exec(hstsLine)[1]) >= 31536000
);

// Regression guard: stage 22 must not undo the existing hardening.
check(
  ".htaccess still denies internal files (.sql/.md/.log/.env/...)",
  /FilesMatch "\(\?i\)\\\.\(sql\|md\|log\|lock\|ini\|json\|yml\|yaml\|sh\|bak\|old\|dist\|sample\|example\|env\)\$"/.test(
    htaccess
  )
);
check(".htaccess still blocks dot-files", /<FilesMatch "\^\\\.">/.test(htaccess));
check(".htaccess still routes everything to index.php", /RewriteRule \^ index\.php \[L\]/.test(htaccess));
check(".htaccess still forbids PHP execution inside uploads/", /RewriteRule \^uploads\/.*\[F,L,NC\]/.test(htaccess));
check(".htaccess still turns directory listing off", /^Options -Indexes$/m.test(htaccess));

/* =====================================================================
 * 2. The PHP copy stays in sync, the kiosk policy stays stricter
 * =================================================================== */
section("2. PHP fallback parity (Security / Bootstrap / kiosk)");

const security = read("app/Core/Security.php");
const bootstrap = read("app/Bootstrap.php");
const kioskController = read("app/Controllers/DeviceKioskPageController.php");

const phpCspParts = phpLiterals(security, "contentSecurityPolicy", '"');
check(
  "Security::contentSecurityPolicy() exists and lists its directives",
  Array.isArray(phpCspParts) && phpCspParts.length >= 13,
  phpCspParts ? phpCspParts.length + " literals" : "method not found"
);
const phpCsp = phpCspParts ? parseCsp(phpCspParts.join("; ")) : {};
check(
  "the PHP policy is identical to the .htaccess policy (no drift)",
  JSON.stringify(phpCsp) === JSON.stringify(siteCsp),
  "php=" + JSON.stringify(phpCsp)
);

const phpPermissionsParts = phpLiterals(security, "permissionsPolicy", "'");
const phpPermissions = phpPermissionsParts ? phpPermissionsParts.join("") : "";
check(
  "Security::permissionsPolicy() equals the .htaccess value",
  phpPermissions.replace(/\s+/g, " ").trim() === htPermissions.replace(/\s+/g, " ").trim(),
  phpPermissions
);

check(
  "Bootstrap sends the CSP as the fallback for hosts without mod_headers",
  /Security::sendCsp\(\);/.test(bootstrap)
);
check(
  "Bootstrap still sends the baseline headers first",
  bootstrap.indexOf("Security::sendHeaders();") < bootstrap.indexOf("Security::sendCsp();")
);
check("Security::sendHeaders() sends X-XSS-Protection: 0", /header\('X-XSS-Protection: 0'\)/.test(security));
check(
  "Security::sendHeaders() uses permissionsPolicy() (one source of truth)",
  /header\('Permissions-Policy: ' \. self::permissionsPolicy\(\)\)/.test(security)
);

// The kiosk policy is a subset of the site policy: whichever one wins, the
// kiosk keeps working, and the stricter rule always applies.
const kioskCspParts = phpLiterals(kioskController, "contentSecurityPolicy", '"');
check("DeviceKioskPageController::contentSecurityPolicy() still exists", Array.isArray(kioskCspParts));
const kioskCsp = kioskCspParts ? parseCsp(kioskCspParts.join("; ")) : {};
Object.entries(kioskCsp).forEach(([directive, tokens]) => {
  const siteTokens = siteCsp[directive] || siteCsp["default-src"] || [];
  const missing = tokens.filter((token) => !siteTokens.includes(token));
  check(
    "kiosk CSP " + directive + " is covered by the site-wide CSP",
    missing.length === 0,
    "missing from site policy: " + missing.join(", ")
  );
});
check(
  "the kiosk policy still refuses inline scripts (script-src 'self' only)",
  (kioskCsp["script-src"] || []).join(" ") === "'self'"
);
check(
  "the kiosk response still carries its own CSP header",
  /withHeader\('Content-Security-Policy', \$this->contentSecurityPolicy\(\)\)/.test(kioskController)
);
check(
  "the kiosk response is still noindex (X-Robots-Tag)",
  /X-Robots-Tag', 'noindex, nofollow, noarchive'/.test(kioskController)
);

/* =====================================================================
 * 3. robots.txt - landing open, application surface closed
 * =================================================================== */
section("3. robots.txt (longest-match resolver)");

const robots = read("robots.txt");
const rules = robotsRules(robots);

check("robots.txt addresses every crawler (User-agent: *)", /^User-agent:\s*\*$/m.test(robots));
check("robots.txt declares exactly one user-agent group", (robots.match(/^User-agent:/gim) || []).length === 1);
check("robots.txt contains the literal 'Allow: /$'", /^Allow:\s*\/\$/m.test(robots));
check("robots.txt opens /assets/ so Googlebot can render the page", /^Allow:\s*\/assets\/$/m.test(robots));
check(
  "every Allow/Disallow pattern starts with '/'",
  rules.every((rule) => rule.pattern.startsWith("/")),
  rules.filter((rule) => !rule.pattern.startsWith("/")).map((rule) => rule.pattern).join(", ")
);
check(
  "robots.txt documents the sub-directory installation case",
  /Sub-directory installation/i.test(robots)
);

// A Sitemap line that points at a missing file is a Search Console error.
const sitemapLine = /^Sitemap:\s*(\S+)/im.exec(robots);
if (sitemapLine) {
  const local = new URL(sitemapLine[1]).pathname;
  check(
    "the Sitemap URL exists in the package",
    fs.existsSync(path.join(ROOT, local.replace(/^\/attendance/, ""))),
    sitemapLine[1]
  );
} else {
  check("no Sitemap line points at a file that does not exist", true);
}

const mustAllow = [
  "/",
  "/assets/css/landing.css?v=1.2.1",
  "/assets/js/landing.js",
  "/assets/icons/icon-512.png",
  "/manifest.webmanifest",
  "/robots.txt",
];

const mustBlock = [
  "/login",
  "/logout",
  "/dashboard",
  "/account/password",
  "/manager/attendance",
  "/manager/employees",
  "/owner",
  "/owner/customers",
  "/owner/settings",
  "/device",
  "/device/offline",
  "/devices",
  "/devices/create",
  "/emergency/exit",
  "/api/v1/device/config",
  "/api/v1/health",
  "/health",
  "/install",
  "/install/",
  "/index.php",
  "/sw.js",
  "/config/config.php",
  "/storage/logs/app.log",
  "/uploads/brands/logo.png",
  "/vendor/autoload.php",
  "/docs/STAGE22.md",
  "/README.md",
  "/TASK_FOR_CODER.md",
  "/sql/attendance_install.sql",
  "/tests/e2e.sh",
  "/tools/build.php",
  "/views/landing.php",
  "/app/Bootstrap.php",
  "/routes/web.php",
  "/composer.json",
  "/composer.lock",
  "/build.sh",
];

mustAllow.forEach((url) =>
  check("crawler MAY fetch " + url, robotsAllowed(url, rules) === true, "blocked by robots.txt")
);
mustBlock.forEach((url) =>
  check("crawler may NOT fetch " + url, robotsAllowed(url, rules) === false, "allowed by robots.txt")
);

// The landing page must never be noindexed by the app itself.
check(
  "the kiosk layout keeps its own noindex meta (defence in depth)",
  /<meta name="robots" content="noindex, nofollow, noarchive">/.test(read("views/layouts/device.php"))
);

/* =====================================================================
 * 4. views/landing.php - SEO block
 * =================================================================== */
section("4. views/landing.php - SEO");

const landing = read("views/landing.php");
const head = landing.slice(0, landing.indexOf("</head>"));
const body = landing.slice(landing.indexOf("<body"));

const headTag = (selector) => new RegExp(selector).test(head);

check("<html lang=fa dir=rtl>", /<html lang="fa" dir="rtl">/.test(landing));
check("charset utf-8 is the first meta tag", /<meta charset="utf-8">/.test(head));
check(
  "viewport never disables zoom (a11y + SEO)",
  /<meta name="viewport" content="[^"]*">/.test(head) &&
    !/user-scalable=no|maximum-scale=1/i.test(head)
);
check("exactly one <title>", (landing.match(/<title>/g) || []).length === 1);

check("the <link rel=canonical> is published from $canonical", head.includes('<link rel="canonical" href="<?= $e($canonical) ?>">'));
check(
  "the canonical URL is absolute (Url::full, not Url::to)",
  /\\App\\Support\\Url::full\('\/'\)/.test(landing)
);
check(
  "the canonical can be overridden by the controller",
  /isset\(\$canonical\) && is_string\(\$canonical\) && \$canonical !== ''/.test(landing)
);
check("Url::full() no longer doubles the install folder", (() => {
  const url = read("app/Support/Url.php");
  const start = url.indexOf("public static function full(");
  const body = url.slice(start, url.indexOf("\n    }", start));
  return !/self::to\(/.test(body) && /self::base\(\)/.test(body);
})());

check("meta robots says index, follow", /<meta name="robots" content="index, follow[^"]*">/.test(head));
check("meta robots never says noindex", !/name="robots"[^>]*noindex/i.test(head));
check("meta description is rendered from one variable", /<meta name="description" content="<\?= \$e\(\$seoDescription\) \?>">/.test(head));

const descriptionMatch = /\$seoDescription = '([^']*)';/.exec(landing);
check("one shared $seoDescription literal exists", Boolean(descriptionMatch));
if (descriptionMatch) {
  const length = [...descriptionMatch[1]].length;
  check(
    "the description is 120-320 characters (Google shows ~155)",
    length >= 120 && length <= 320,
    length + " chars"
  );
}
check(
  "the same description feeds Open Graph, Twitter and JSON-LD",
  (landing.match(/\$seoDescription/g) || []).length >= 5,
  (landing.match(/\$seoDescription/g) || []).length + " uses"
);

[
  ["og:type", '<meta property="og:type" content="website">'],
  ["og:locale", '<meta property="og:locale" content="fa_IR">'],
  ["og:site_name", '<meta property="og:site_name" content="<?= $e($siteName) ?>">'],
  ["og:title", '<meta property="og:title" content="<?= $e($title) ?>">'],
  ["og:description", '<meta property="og:description" content="<?= $e($seoDescription) ?>">'],
  ["og:url", '<meta property="og:url" content="<?= $e($canonical) ?>">'],
  ["og:image", '<meta property="og:image" content="<?= $e($ogImage) ?>">'],
  ["og:image:alt", '<meta property="og:image:alt"'],
  ["twitter:card", '<meta name="twitter:card" content="summary_large_image">'],
  ["twitter:title", '<meta name="twitter:title" content="<?= $e($title) ?>">'],
  ["twitter:description", '<meta name="twitter:description" content="<?= $e($seoDescription) ?>">'],
  ["twitter:image", '<meta name="twitter:image" content="<?= $e($ogImage) ?>">'],
].forEach(([name, literal]) => check(name + " is published", head.includes(literal)));

check(
  "og:image is ABSOLUTE (a relative path is ignored by crawlers)",
  /\$ogImage = \\App\\Support\\Url::full\('\/assets\/icons\/icon-512\.png'\);/.test(landing)
);
check("the og:image file really ships in the package", fs.existsSync(path.join(ROOT, "assets/icons/icon-512.png")));

check("hreflang fa points at the canonical URL", head.includes('<link rel="alternate" hreflang="fa" href="<?= $e($canonical) ?>">'));
check("hreflang x-default points at the canonical URL", head.includes('<link rel="alternate" hreflang="x-default" href="<?= $e($canonical) ?>">'));

check("theme-color is published", /<meta name="theme-color" content="#[0-9a-fA-F]{6}">/.test(head));
check("favicon 192 is linked", head.includes(`<link rel="icon" type="image/png" sizes="192x192" href="<?= $e($asset('/icons/icon-192.png')) ?>">`));
check("apple-touch-icon is linked", /<link rel="apple-touch-icon"/.test(head));
check(
  "the stylesheet still goes through the versioned css() helper (?v=1.2.1)",
  head.includes(`<link rel="stylesheet" href="<?= $e($css('/css/landing.css')) ?>">`)
);

check("a schema.org JSON-LD block is rendered", head.includes('<script type="application/ld+json"><?= $jsonLd ?></script>'));
check("the JSON-LD block is conditional (a failed encode renders nothing)", landing.includes("<?php if ($jsonLd !== ''): ?>"));
check(
  "the JSON-LD is encoded with JSON_HEX_TAG (a value can never close the script)",
  /JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES \| JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT/.test(
    landing
  )
);
check("the JSON-LD graph describes the Organization, WebSite and SoftwareApplication", ["'Organization'", "'WebSite'", "'SoftwareApplication'"].every((type) => landing.includes(type)));
check("the JSON-LD publishes only real contact channels (array_filter)", /array_filter\(/.test(landing) && /if \(\$sameAs !== \[\]\)/.test(landing));
check("the JSON-LD invents no price, rating or review", !/'offers'|aggregateRating|review'/i.test(landing));

/* =====================================================================
 * 5. views/landing.php - accessibility contract
 * =================================================================== */
section("5. views/landing.php - ARIA / landmarks");

const tags = tokenize(landing);
const openTags = tags.filter((tag) => !tag.closing);

// 5a. no aria-* naming attribute on an element whose role forbids it.
const NAMING_ROLES = new Set([
  "img", "group", "region", "note", "list", "listitem", "navigation", "main",
  "banner", "contentinfo", "complementary", "form", "search", "dialog",
  "alert", "status", "toolbar", "tablist", "tab", "tabpanel", "radiogroup",
  "menu", "menubar", "menuitem", "tree", "treeitem", "grid", "row", "cell",
  "button", "link", "textbox", "combobox", "switch", "checkbox", "radio",
  "slider", "progressbar", "meter", "heading", "article", "figure", "table",
  "application", "document", "log", "marquee", "timer", "tooltip", "option",
]);
const NAMING_ELEMENTS = new Set([
  "a", "area", "link", "section", "nav", "main", "header", "footer", "aside",
  "form", "img", "svg", "figure", "table", "button", "input", "select",
  "textarea", "summary", "iframe", "video", "audio", "dialog", "h1", "h2",
  "h3", "h4", "h5", "h6", "output", "progress", "meter", "label", "fieldset",
  "details", "body", "html",
]);
const NEVER_NAMED = new Set([
  "ul", "ol", "li", "p", "time", "small", "strong", "b", "i", "em", "code",
  "pre", "br", "hr", "tbody", "thead", "tr", "td", "th", "caption", "col",
]);

const namedTags = openTags.filter(
  (tag) => "aria-label" in tag.attrs || "aria-labelledby" in tag.attrs
);
check("the page still names its landmarks (aria-label/aria-labelledby present)", namedTags.length >= 8, namedTags.length + " found");

namedTags.forEach((tag) => {
  const role = (tag.attrs.role || "").trim().toLowerCase();
  const label = tag.attrs["aria-label"] || tag.attrs["aria-labelledby"] || "";
  if (role !== "") {
    check(
      "<" + tag.name + " role=\"" + role + "\"> may carry an ARIA name",
      NAMING_ROLES.has(role),
      label
    );
    return;
  }
  check(
    "<" + tag.name + "> may carry an ARIA name without an explicit role",
    NAMING_ELEMENTS.has(tag.name),
    "aria-label=\"" + label + "\" on a generic element (axe: aria-prohibited-attr)"
  );
  check(
    "<" + tag.name + "> is not a list that lost its accessible name rule",
    !NEVER_NAMED.has(tag.name),
    "aria-label=\"" + label + "\""
  );
});

check(
  "no <ul>/<ol> in the page carries aria-label (the reported Google error)",
  openTags.filter((tag) => (tag.name === "ul" || tag.name === "ol") && "aria-label" in tag.attrs).length === 0
);
check(
  "no <div>/<span> carries aria-label without a role",
  openTags.filter(
    (tag) =>
      (tag.name === "div" || tag.name === "span") &&
      ("aria-label" in tag.attrs || "aria-labelledby" in tag.attrs) &&
      !(tag.attrs.role || "")
  ).length === 0
);

const byClass = (name) =>
  openTags.find((tag) => (tag.attrs.class || "").split(/\s+/).includes(name));

check(
  '.hero-visual is role="img" (a named graphic, its scene is aria-hidden)',
  (byClass("hero-visual") || { attrs: {} }).attrs.role === "img" &&
    Boolean((byClass("hero-visual") || { attrs: {} }).attrs["aria-label"])
);
check(
  '.contact-grid is role="group" (a named group of links)',
  (byClass("contact-grid") || { attrs: {} }).attrs.role === "group"
);
["hero-proof", "benefit-list", "condition-list"].forEach((listClass) => {
  const list = byClass(listClass);
  check(
    "ul." + listClass + ' keeps list semantics with an explicit role="list"',
    Boolean(list) && list.name === "ul" && list.attrs.role === "list",
    list ? list.raw : "not found"
  );
});
check(
  'aside.conditions-card is role="note" (no landmark inside the agency region)',
  (byClass("conditions-card") || { attrs: {} }).attrs.role === "note"
);
check(
  "the decorative hologram SVG is aria-hidden instead of a nested image",
  openTags.some((tag) => tag.name === "svg" && (tag.attrs.class || "").includes("face-svg") && tag.attrs["aria-hidden"] === "true")
);
check(
  "the brand links are named by their visible text (no aria-label mismatch)",
  openTags.filter((tag) => (tag.attrs.class || "").split(/\s+/).includes("brand") && "aria-label" in tag.attrs).length === 0
);

// 5b. an <aside> (complementary landmark) must be top level: axe reports
// landmark-complementary-is-top-level when it sits inside main/section/
// article/nav/aside. An explicit non-complementary role (role="note") takes
// the element out of the landmark set, which is exactly what the conditions
// card of the agency section uses.
const SECTIONING = new Set(["main", "section", "article", "nav", "aside"]);
const nestedLandmarks = [];
walk(tags, (tag, ancestors) => {
  if (tag.name !== "aside") return;
  const role = (tag.attrs.role || "").trim().toLowerCase();
  if (role !== "" && role !== "complementary") return;
  if (ancestors.some((open) => SECTIONING.has(open.name))) {
    nestedLandmarks.push(tag.raw.slice(0, 60));
  }
});
check(
  "no complementary landmark (aside) is nested inside another landmark",
  nestedLandmarks.length === 0,
  nestedLandmarks.join(" | ")
);
check("exactly one <main>", openTags.filter((tag) => tag.name === "main").length === 1);
check(
  "every <section> that is a landmark region is named (aria-label/labelledby)",
  openTags
    .filter((tag) => tag.name === "section")
    .every((tag) => Boolean(tag.attrs["aria-label"] || tag.attrs["aria-labelledby"]))
);
check(
  "no two landmarks share the same accessible name",
  (() => {
    const names = openTags
      .filter((tag) => SECTIONING.has(tag.name) || tag.name === "header" || tag.name === "footer")
      .map((tag) => tag.attrs["aria-label"] || tag.attrs["aria-labelledby"] || "")
      .filter(Boolean);
    return new Set(names).size === names.length;
  })()
);

// 5c. nothing focusable may hide inside an aria-hidden subtree
// (axe: aria-hidden-focus).
const FOCUSABLE = new Set(["a", "button", "input", "select", "textarea"]);
const focusableInsideHidden = [];
walk(tags, (tag, ancestors) => {
  const focusable =
    (FOCUSABLE.has(tag.name) && (tag.name !== "a" || "href" in tag.attrs)) ||
    ("tabindex" in tag.attrs && parseInt(tag.attrs.tabindex, 10) >= 0);
  if (!focusable) return;
  if (ancestors.some((open) => open.attrs["aria-hidden"] === "true")) {
    focusableInsideHidden.push(tag.raw.slice(0, 60));
  }
});
check(
  'no focusable element hides inside aria-hidden="true"',
  focusableInsideHidden.length === 0,
  focusableInsideHidden.join(" | ")
);
check(
  "the decorative background layers stay aria-hidden",
  ["bg-grid", "orb-cyan", "orb-violet"].every(
    (layer) => (byClass(layer) || { attrs: {} }).attrs["aria-hidden"] === "true"
  )
);

// 5d. every aria-labelledby / aria-describedby / fragment href resolves.
const ids = new Set(openTags.map((tag) => tag.attrs.id).filter(Boolean));
const dangling = [];
openTags.forEach((tag) => {
  ["aria-labelledby", "aria-describedby"].forEach((attribute) => {
    (tag.attrs[attribute] || "")
      .split(/\s+/)
      .filter(Boolean)
      .forEach((id) => {
        if (!ids.has(id)) dangling.push(attribute + "=" + id);
      });
  });
  const href = tag.attrs.href || "";
  if (href.startsWith("#") && href.length > 1 && !ids.has(href.slice(1))) {
    dangling.push("href=" + href);
  }
});
check("every ARIA id reference and in-page anchor exists", dangling.length === 0, dangling.join(", "));
check("the skip link targets the main landmark", /<a class="skip-link" href="#main">/.test(body) && ids.has("main"));

// 5e. document outline and text alternatives.
const headings = openTags.filter((tag) => /^h[1-6]$/.test(tag.name)).map((tag) => Number(tag.name[1]));
check("exactly one <h1>", headings.filter((level) => level === 1).length === 1, headings.join(","));
check(
  "the heading order never skips a level",
  headings.every((level, index) => index === 0 || level - headings[index - 1] <= 1),
  headings.join(",")
);
const images = openTags.filter((tag) => tag.name === "img");
check("the page renders images", images.length > 0);
check(
  "every <img> carries an alt attribute (empty for decorative marks)",
  images.every((tag) => "alt" in tag.attrs),
  images.filter((tag) => !("alt" in tag.attrs)).map((tag) => tag.raw.slice(0, 50)).join(" | ")
);
check(
  "no <img> is stretched (the STAGE 21 object-fit rule still applies to logos)",
  !/<img[^>]*style="[^"]*(object-fit:\s*(fill|cover|scale-down))/.test(landing)
);
check(
  'every target="_blank" link keeps rel="noopener noreferrer"',
  openTags
    .filter((tag) => tag.attrs.target === "_blank")
    .every((tag) => /noopener/.test(tag.attrs.rel || "")),
  openTags.filter((tag) => tag.attrs.target === "_blank" && !/noopener/.test(tag.attrs.rel || "")).length + " unsafe"
);
check("no javascript: URL is used as a link", !/href="javascript:/i.test(landing));

/* =====================================================================
 * 6. landing.css - tap targets, legible fonts, AA contrast
 * =================================================================== */
section("6. assets/css/landing.css - SEO/a11y metrics");

const css = read("assets/css/landing.css");

const mediaBlock = (maxWidth) => {
  const start = css.indexOf("@media (max-width: " + maxWidth + "px)");
  if (start === -1) return "";
  let depth = 0;
  for (let i = css.indexOf("{", start); i < css.length; i += 1) {
    if (css[i] === "{") depth += 1;
    else if (css[i] === "}") {
      depth -= 1;
      if (depth === 0) return css.slice(start, i + 1);
    }
  }
  return "";
};

const ruleIn = (block, selector) => {
  const start = block.indexOf(selector + " {");
  if (start === -1) return "";
  return block.slice(start, block.indexOf("}", start) + 1);
};

const phoneBlock = mediaBlock(760);
const smallPhoneBlock = mediaBlock(430);
const tabletBlock = mediaBlock(1080);

const navButton = ruleIn(phoneBlock, ".nav-actions .btn");
check(
  "the header buttons are 48px tall on a phone (tap target)",
  /min-height:\s*48px/.test(navButton),
  navButton.replace(/\s+/g, " ").trim()
);
check(
  "the 48px button still fits the 64px nav shell (8px padding)",
  /min-height:\s*64px/.test(ruleIn(phoneBlock, ".nav-shell")) &&
    /padding:\s*8px/.test(ruleIn(phoneBlock, ".nav-shell"))
);
const remOf = (rule) => {
  const match = /font-size:\s*([\d.]+)rem/.exec(rule);
  return match ? Number(match[1]) : null;
};
check(
  "phone button labels stay legible (>= 12px = 0.75rem)",
  remOf(navButton) !== null && remOf(navButton) >= 0.75,
  String(remOf(navButton))
);
const tinyButton = ruleIn(smallPhoneBlock, ".nav-actions .btn");
check(
  "small-phone button labels stay legible (>= 12px = 0.75rem)",
  remOf(tinyButton) !== null && remOf(tinyButton) >= 0.75,
  String(remOf(tinyButton))
);
check(
  "the desktop nav is hidden on a phone (no tiny tap targets)",
  /display:\s*none/.test(ruleIn(tabletBlock, ".main-nav"))
);

const footerLink = ruleIn(css, ".footer-links a");
check(
  "footer links are 48px tap targets",
  /min-height:\s*48px/.test(footerLink) && /display:\s*inline-flex/.test(footerLink),
  footerLink.replace(/\s+/g, " ").trim()
);

// WCAG AA for the three text colours of the dark theme, measured against the
// page background AND the translucent panel/footer that sit on top of it.
const PAGE_BG = "#040713";
const PANEL_BG = blend("#0d182d", 0.68, PAGE_BG);
const FOOTER_BG = blend("#030711", 0.62, "#050a16");
const textColours = {
  "--landing-text (#f2f7ff)": "#f2f7ff",
  "--landing-muted (#a9bad3)": "#a9bad3",
  "--landing-dim (#7286a5, .copyright)": "#7286a5",
  ".hero-proof li (#cbd8ed)": "#cbd8ed",
  ".benefit-list li (#dbe7f8)": "#dbe7f8",
};
Object.entries(textColours).forEach(([name, colour]) => {
  const worst = Math.min(
    contrast(colour, PAGE_BG),
    contrast(colour, PANEL_BG),
    contrast(colour, FOOTER_BG)
  );
  check(
    name + " keeps a WCAG AA contrast ratio >= 4.5:1",
    worst >= 4.5,
    worst.toFixed(2) + ":1"
  );
});
check(
  "the CSS variables the checks above rely on are still declared",
  ["--landing-text: #f2f7ff", "--landing-muted: #a9bad3", "--landing-dim: #7286a5"].every(
    (declaration) => css.includes(declaration)
  )
);

/* =====================================================================
 * Summary
 * =================================================================== */
console.log(
  "\n" +
    "=".repeat(62) +
    "\nSTAGE 22 checks passed: " +
    passed +
    ", failed: " +
    failures.length
);
if (failures.length > 0) {
  console.log("\nFAILURES:");
  failures.forEach((failure) => console.log("  ✗ " + failure));
  process.exit(1);
}
console.log("✓ security headers (A+), robots.txt, SEO meta and ARIA contract all hold");
process.exit(0);
