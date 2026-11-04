#!/usr/bin/env node
/**
 * =====================================================================
 * SARMAD Attendance - kiosk QR badge test (REAL module, no mocks)
 * =====================================================================
 *
 *   node tests/device_qr_test.js
 *
 * The module under test is assets/js/device-qr.js - the very file the kiosk
 * page loads. What is proven here:
 *
 *   1. the SHA-256 of the digest is correct (checked against node:crypto on
 *      ascii, Persian text and the real badge shape), so a PHP digest and a
 *      browser digest of the same badge can never disagree,
 *   2. a scanned badge matches exactly one employee of the downloaded digest
 *      list, and nothing else (another employee's digest, a foreign string, an
 *      empty string),
 *   3. the decoder wrapper is fail-safe: without jsQR, or with a broken
 *      decoder, decoding answers null instead of throwing,
 *   4. ZERO BYTES: the badge path contains no export of a frame - no
 *      toDataURL, no toBlob, no FormData, no upload - and the offline queue
 *      item carries a digest, never a badge string or an image,
 *   5. the service worker precaches the reader (offline QR).
 */

'use strict';

const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const assert = require('node:assert/strict');

const ROOT = path.resolve(__dirname, '..');
const QR = require(path.join(ROOT, 'assets/js/device-qr.js'));
const queue = require(path.join(ROOT, 'assets/js/device-queue.js'));

let pass = 0;
const failures = [];

function check(label, condition, detail) {
  if (condition) {
    pass++;
    console.log(`  \x1b[32m✓\x1b[0m ${label}`);
    return;
  }

  failures.push(label + (detail ? ` — ${detail}` : ''));
  console.log(`  \x1b[31m✗ ${label}\x1b[0m${detail ? ` — ${detail}` : ''}`);
}

function eq(label, actual, expected) {
  check(label, actual === expected, `actual=${JSON.stringify(actual)} expected=${JSON.stringify(expected)}`);
}

function sha256(text) {
  return crypto.createHash('sha256').update(text, 'utf8').digest('hex');
}

function section(title) {
  console.log(`\n\x1b[1m== ${title} ==\x1b[0m`);
}

/* ------------------------------------------------------------------ */
section('A. SHA-256 digest (browser implementation == node:crypto)');

for (const sample of ['', 'a', 'abc', 'SARMADQ1.1.2.abcdef0123456789abcd', 'حضور و غیاب', '۰۱۲۳']) {
  eq(`sha256(${JSON.stringify(sample).slice(0, 26)})`, QR.sha256(sample), sha256(sample));
}

const long = 'x'.repeat(1000);
eq('sha256 of a 1000 char string', QR.sha256(long), sha256(long));

/* ------------------------------------------------------------------ */
section('B. Payload normalisation');

const PAYLOAD = 'SARMADQ1.7.16.57DAFA94208672964C9F';
const DIGEST = sha256(PAYLOAD);

eq('the prefix is the documented one', QR.PREFIX, 'SARMADQ1');
eq('normalise upper-cases', QR.normalise('sarmadq1.7.16.abc'), 'SARMADQ1.7.16.ABC');
eq('normalise strips whitespace and newlines', QR.normalise(' SARMADQ1.7.16.ABC\n'), 'SARMADQ1.7.16.ABC');
eq('normalise caps the length', QR.normalise('A'.repeat(500)).length, QR.MAX_PAYLOAD);
check('looksLikeBadge accepts a badge', QR.looksLikeBadge(PAYLOAD));
check('looksLikeBadge accepts a badge with noise', QR.looksLikeBadge(' ' + PAYLOAD.toLowerCase() + '\n'));
check('looksLikeBadge rejects a URL', !QR.looksLikeBadge('https://example.com/badge'));
check('looksLikeBadge rejects an empty string', !QR.looksLikeBadge(''));
eq('a digest is the sha256 of the normalised badge', QR.digest(' ' + PAYLOAD.toLowerCase() + ' '), DIGEST);

/* ------------------------------------------------------------------ */
section('C. Matching against the downloaded digest list');

const employees = [
  { employee_id: 16, full_name: 'SARMAD QR', employee_code: 'S20-QR', qr_digest: DIGEST },
  { employee_id: 17, full_name: 'SARMAD OTHER', employee_code: 'S20-OTHER', qr_digest: sha256('SARMADQ1.7.17.OTHER') },
];

const hit = QR.match(PAYLOAD, employees);
check('the badge matches', hit !== null);
eq('the matched employee is the right one', hit ? hit.employee.employee_id : 0, 16);
eq('the match returns the digest it used', hit ? hit.digest : '', DIGEST);

check('a badge of another employee does not match this one',
  QR.match('SARMADQ1.7.17.OTHER', employees).employee.employee_id === 17);
check('an unknown badge matches nothing', QR.match('SARMADQ1.7.99.NOTHING', employees) === null);
check('a non badge string matches nothing', QR.match('HELLO WORLD', employees) === null);
check('an empty list matches nothing', QR.match(PAYLOAD, []) === null);
check('a null list matches nothing', QR.match(PAYLOAD, null) === null);
check('a digest entry with the wrong shape is skipped',
  QR.match(PAYLOAD, [{ employee_id: 1, qr_digest: 'short' }]) === null);

/* ------------------------------------------------------------------ */
section('D. The decoder wrapper is fail-safe');

eq('without a decoder the frame is not decoded', QR.decodeFrame({ data: new Uint8ClampedArray(4), width: 1, height: 1 }), null);
eq('without a frame nothing happens', QR.decodeFrame(null), null);

globalThis.jsQR = () => ({ data: PAYLOAD });
eq('a decoder that returns the badge is used', QR.decodeFrame({ data: new Uint8ClampedArray(4), width: 1, height: 1 }), PAYLOAD);

globalThis.jsQR = () => { throw new Error('dirty frame'); };
eq('a throwing decoder answers null', QR.decodeFrame({ data: new Uint8ClampedArray(4), width: 1, height: 1 }), null);

globalThis.jsQR = () => null;
eq('a decoder that finds nothing answers null', QR.decodeFrame({ data: new Uint8ClampedArray(4), width: 1, height: 1 }), null);
delete globalThis.jsQR;

/* ------------------------------------------------------------------ */
section('E. Offline queue: digest only, no image, face unchanged');

const offlineItem = queue.item({
  local_event_id: 's20-local-1',
  device_id: 3,
  event_type: 'entry',
  employee_id: 16,
  local_created_at: '2026-11-04T08:00:00+03:30',
  request_id: 'req-1',
  credential: 'qr',
  qr_digest: DIGEST,
});

eq('the queue item keeps the credential', offlineItem.credential, 'qr');
eq('the queue item keeps the digest', offlineItem.qr_digest, DIGEST);
eq('the queue item has no face distance', offlineItem.recognition.distance, null);
eq('the local outcome is QR_MATCHED', offlineItem.recognition.outcome, 'QR_MATCHED');

const batch = queue.buildBatch([offlineItem], 10);
eq('the sync batch sends one item', batch.length, 1);
eq('the sync batch marks the credential', batch[0].credential, 'qr');
eq('the sync batch sends the digest', batch[0].qr_digest, DIGEST);
check('the sync batch sends no image field',
  !('image' in batch[0]) && !('photo' in batch[0]) && !('frame' in batch[0]));

const faceItem = queue.item({
  local_event_id: 's20-local-2',
  event_type: 'exit',
  employee_id: 17,
  request_id: 'req-2',
  recognition: { distance: 0.31, confidence: 0.91 },
});
const faceBatch = queue.buildBatch([faceItem], 10);
eq('a face item keeps the old contract (no credential field)', 'credential' in faceBatch[0], false);
eq('a face item keeps its distance', faceBatch[0].distance, 0.31);

/* ------------------------------------------------------------------ */
section('F. ZERO BYTES on the host (source contract)');

const deviceJs = fs.readFileSync(path.join(ROOT, 'assets/js/device.js'), 'utf8');
const qrJs = fs.readFileSync(path.join(ROOT, 'assets/js/device-qr.js'), 'utf8');

// Only real CALLS count - the comments of these files explain that they do
// not use these APIs, so a naive substring check would fail on the docs.
const FRAME_EXPORT = /\.toDataURL\s*\(|\.toBlob\s*\(|new\s+FileReader\s*\(|new\s+FormData\s*\(|new\s+Blob\s*\(/;

check('the kiosk script never exports a frame with toDataURL/toBlob', !FRAME_EXPORT.test(deviceJs));
check('the kiosk script never uploads a file', !/new\s+FormData\s*\(/.test(deviceJs));
check('the QR module never exports a frame either', !FRAME_EXPORT.test(qrJs));
check('the kiosk clears the pixels of the frame it decoded', deviceJs.includes('clearRect'));
check('the kiosk draws the frame from the live video only', deviceJs.includes('ctx.drawImage(el.cameraVideo'));

const qrSection = deviceJs.slice(deviceJs.indexOf('STAGE 20 - QR badge reader'), deviceJs.indexOf('function startFaceLoop'));

check('the badge path posts only the badge string', qrSection.includes("api('/attendance/qr'"));
check('the badge path sends no image payload',
  !/new\s+Blob\s*\(|new\s+FormData\s*\(|new\s+FileReader\s*\(|\bimage\s*:|\bphoto\s*:/i.test(qrSection));
check('the badge path plays the same voice as a face punch', qrSection.includes('state.action = action'));
check('the badge path falls back to the offline queue', qrSection.includes("credential: 'qr'"));

const swSource = fs.readFileSync(path.join(ROOT, 'sw.js'), 'utf8');
check('the service worker precaches jsQR', swSource.includes('./assets/vendor/jsQR.min.js'));
check('the service worker precaches the badge module', swSource.includes('./assets/js/device-qr.js'));

/* ------------------------------------------------------------------ */
console.log('\n' + '-'.repeat(64));
console.log(`passed: \x1b[32m${pass}\x1b[0m   failed: ${failures.length ? '\x1b[31m' + failures.length + '\x1b[0m' : '0'}`);

if (failures.length) {
  console.log('\nfailures:');
  failures.forEach((failure) => console.log('  - ' + failure));
  process.exit(1);
}

console.log('ALL QR BADGE TESTS PASSED');
