'use strict';
// Structural assertions only. Real device viewport and browser chrome require
// a visual/browser test (see docs/OFFLINE-KIOSK.md).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const css = fs.readFileSync(path.join(__dirname, '../assets/css/device.css'), 'utf8');
function blockAt(marker) {
  const at = css.indexOf(marker); assert(at >= 0, marker);
  const begin = css.indexOf('{', at); let depth = 0;
  for (let i = begin; i < css.length; i++) {
    if (css[i] === '{') depth++;
    if (css[i] === '}' && --depth === 0) return css.slice(begin + 1, i);
  }
  throw Error('unbalanced ' + marker);
}
const mobile = blockAt('@media (max-width: 760px)');
assert.match(mobile, /\.k-top\s*\{[^}]*flex-direction:\s*row/);
assert.doesNotMatch(mobile, /\.k-top\s*\{[^}]*flex-direction:\s*column/);
assert.match(mobile, /\.k-actions\s*\{[^}]*grid-template-columns:\s*repeat\(2/);
assert.match(mobile, /\.k-clock\s*\{[^}]*min-height:\s*0/);
assert.match(mobile, /\.k-action\s*\{[^}]*min-height:\s*clamp\(80px/);
assert.match(mobile, /safe-area-inset-top/);
assert.match(mobile, /safe-area-inset-bottom/);
assert.match(blockAt('.k-shell'), /height:\s*100dvh/);
assert.match(blockAt('@media (max-height: 600px)'), /overflow-y:\s*auto/);
assert.match(blockAt('@media (max-height: 580px)'), /\.k-clock\s*\{[^}]*display:\s*none/);
console.log('Mobile viewport CSS structural checks passed (10 checks)');
