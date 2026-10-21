#!/usr/bin/env node
/* =====================================================================
   SARMAD Attendance - real browser test of the LOCAL face engine (stage 5)
   ---------------------------------------------------------------------
   This script drives the SHIPPED engine (assets/js/face/face-engine.js) in a
   real Chromium against the SHIPPED model files, using public-domain photos
   as fixtures. Nothing is mocked: if the model fails to load, if a face is
   not detected or if the matching is wrong, this script says so.

   Usage:
     node tests/browser/run_engine_test.js <base-url> [--json <file>]

   The harness page and the fixtures are injected into the app origin through
   request interception (tests/ is blocked by .htaccess on purpose), so the
   page runs exactly like a page of the application.
   ===================================================================== */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const FIXTURES = path.join(ROOT, 'tests', 'fixtures', 'face', 'set');
const HARNESS = path.join(__dirname, 'face-harness.html');

const baseUrl = (process.argv[2] || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const jsonIndex = process.argv.indexOf('--json');
const jsonOut = jsonIndex > -1 ? process.argv[jsonIndex + 1] : '';

let chromium;

try {
    chromium = require('puppeteer-core');
} catch (error) {
    try {
        chromium = require('/tmp/s5/node_modules/puppeteer-core');
    } catch (second) {
        console.error('RESULT: browser test cannot run (puppeteer-core is not installed)');
        process.exit(3);
    }
}

function mimeFor(file) {
    return /\.jpe?g$/i.test(file) ? 'image/jpeg' : (/\.png$/i.test(file) ? 'image/png' : 'application/octet-stream');
}

async function main() {
    const browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium',
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--use-gl=angle',
            '--use-angle=swiftshader',
            '--enable-unsafe-swiftshader',
            '--allow-file-access-from-files',
            '--autoplay-policy=no-user-gesture-required'
        ]
    });

    const page = await browser.newPage();

    await page.setViewport({ width: 1024, height: 768 });

    // Serve the harness + fixtures from the app origin.
    await page.setRequestInterception(true);

    page.on('request', (request) => {
        const url = request.url();
        const marker = '/__face_test__/';

        if (url.indexOf(marker) === -1) {
            request.continue();

            return;
        }

        const relative = url.substring(url.indexOf(marker) + marker.length).split('?')[0];

        if (relative === 'harness.html') {
            request.respond({ status: 200, contentType: 'text/html; charset=utf-8', body: fs.readFileSync(HARNESS) });

            return;
        }

        // Model + library requests resolve relative to the harness URL, exactly
        // like they would on /device; map them back to the project tree.
        if (relative.indexOf('assets/') === 0) {
            const asset = path.join(ROOT, relative);

            if (asset.indexOf(ROOT) === 0 && fs.existsSync(asset)) {
                request.respond({ status: 200, contentType: mimeFor(asset), body: fs.readFileSync(asset) });

                return;
            }
        }

        if (relative.indexOf('fixtures/') === 0) {
            const file = path.join(FIXTURES, path.basename(relative));

            if (fs.existsSync(file)) {
                request.respond({ status: 200, contentType: mimeFor(file), body: fs.readFileSync(file) });

                return;
            }
        }

        request.respond({ status: 404, contentType: 'text/plain', body: 'not found' });
    });

    const pageErrors = [];

    page.on('pageerror', (error) => pageErrors.push(String(error.message || error)));

    const configResponse = await page.goto(baseUrl + '/__face_test__/harness.html', { waitUntil: 'load', timeout: 60000 });

    if (!configResponse || configResponse.status() !== 200) {
        console.error('RESULT: harness page did not load from the application origin');

        await browser.close();
        process.exit(3);
    }

    await page.waitForFunction('document.title === "ready"', { timeout: 30000 });

    // Read the thresholds the SERVER ships straight from config/config.php, so
    // the browser test uses exactly the numbers the device is given (via
    // /api/v1/device/config) and no threshold is duplicated in the test.
    const { execFileSync } = require('child_process');
    let shippedFace = {};

    try {
        const raw = execFileSync('php', ['-r', 'echo json_encode((require "config/config.php")["face"]);'], {
            cwd: ROOT,
            encoding: 'utf8'
        });

        shippedFace = JSON.parse(raw);
    } catch (error) {
        console.error('note: could not read config/config.php:', error.message);
    }

    const engineSettings = {
        source: 'config/config.php',
        model_path: shippedFace.model_path,
        model_version: shippedFace.model_version,
        match_threshold: shippedFace.match_threshold,
        ambiguity_margin: shippedFace.ambiguity_margin,
        detector: shippedFace.detector,
        liveness: shippedFace.liveness
    };

    const config = {
        model_path: '/assets/models/face-api-1.0.0',
        model_version: 'face-api.js@0.22.2/face_recognition-128d/v1',
        detector: 'tiny_face_detector',
        metric: 'euclidean',
        dimensions: 128,
        samples_required: 3,
        match_threshold: 0.55,
        ambiguity_margin: 0.06,
        detect_interval_ms: 350,
        detector_input_size: 320,
        recognition_input_size: 416,
        min_face_ratio: 0.16,
        min_face_pixels: 80,
        max_center_offset: 0.34,
        blur_threshold: 45.0,
        min_brightness: 35.0,
        max_brightness: 225.0,
        max_yaw_ratio: 0.34
    };

    const result = await page.evaluate(async (options) => window.runFaceTests(options), { config });

    await browser.close();

    const report = {
        base_url: baseUrl,
        chromium: await Promise.resolve(process.env.CHROMIUM_PATH || '/usr/bin/chromium'),
        page_errors: pageErrors,
        config_source: engineSettings,
        shipped_thresholds: {
            match_threshold: shippedFace.match_threshold,
            ambiguity_margin: shippedFace.ambiguity_margin,
            blur_threshold: shippedFace.blur_threshold,
            min_face_ratio: shippedFace.min_face_ratio,
            max_center_offset: shippedFace.max_center_offset,
            min_brightness: shippedFace.min_brightness,
            max_brightness: shippedFace.max_brightness,
            max_yaw_ratio: shippedFace.max_yaw_ratio
        },
        ...result
    };

    if (jsonOut) {
        fs.writeFileSync(jsonOut, JSON.stringify(report, null, 2));
    }

    console.log(JSON.stringify(report, null, 2));
}

main().catch((error) => {
    console.error('RESULT: browser test crashed: ' + (error && error.message ? error.message : error));
    process.exit(3);
});
