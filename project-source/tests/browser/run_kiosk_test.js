/* =====================================================================
   SARMAD Attendance - REAL kiosk recognition test
   ---------------------------------------------------------------------
   This is the end-to-end proof that recognition happens ON THE DEVICE:

     * Chromium runs the real kiosk page (/device) with the real device token,
     * the camera is a real MediaStream (the test machine feeds a Y4M file to
       Chromium's camera device, so getUserMedia returns live video),
     * the real engine (face-api.js, shipped model) detects the face and
       computes the descriptor inside the browser,
     * the kiosk then reports only the OUTCOME to /api/v1/device/... - this
       script records exactly what the server answered and what the screen
       showed, plus how long it took.

   Usage:
     node tests/browser/run_kiosk_test.js <base-url> <device-token> <device-id> <scenario> <y4m-file> [--json out.json]

   Scenarios:
     known        - the person enrolled for an employee must be matched
     unknown      - a stranger must end in UNKNOWN_FACE (no attendance)
     multiple     - two people in frame must NEVER reach the server
     no_face      - an empty frame must stay in guidance
     reject       - a face whose employee is not eligible (server refusal)
   ===================================================================== */

'use strict';

const fs = require('fs');
const puppeteer = require('/tmp/s5/node_modules/puppeteer-core');

const base = (process.argv[2] || 'http://127.0.0.1:8080').replace(/\/$/, '');
const token = process.argv[3] || '';
const deviceId = parseInt(process.argv[4] || '1', 10);
const scenario = process.argv[5] || 'known';
const videoFile = process.argv[6] || '';
const jsonIndex = process.argv.indexOf('--json');
const jsonOut = jsonIndex !== -1 ? process.argv[jsonIndex + 1] : null;

const report = {
    scenario: scenario,
    video: videoFile,
    device_id: deviceId,
    screen: null,
    server_calls: [],
    face_data: null,
    gallery: null,
    timings: {},
    page_errors: [],
    ok: false
};

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/chromium',
        headless: 'new',
        protocolTimeout: 900000,
        args: [
            '--no-sandbox', '--disable-dev-shm-usage',
            '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader',
            '--use-fake-ui-for-media-stream',
            '--use-fake-device-for-media-stream',
            '--use-file-for-fake-video-capture=' + videoFile,
            '--window-size=1280,900'
        ]
    });

    const page = await browser.newPage();

    /* the test machine has no real GPU: tell the engine to use the CPU backend
       (the production default stays WebGL) */
    await page.evaluateOnNewDocument((deviceToken, id) => {
        window.SARMAD_FACE_BACKEND = 'cpu';
        window.localStorage.setItem('sarmad.device.credential', JSON.stringify({
            token: deviceToken,
            device_uid: 'kiosk-face-test-01',
            device_id: id,
            customer_id: 1,
            saved_at: new Date().toISOString()
        }));
    }, token, deviceId);

    page.on('pageerror', (error) => report.page_errors.push(String(error.message || error).slice(0, 200)));

    page.on('response', async (response) => {
        const url = response.url();

        if (url.indexOf('/api/v1/device/') === -1) {
            return;
        }

        const entry = { endpoint: url.split('/api/v1/device/')[1], status: response.status() };

        try {
            const body = await response.json();

            entry.code = body.code;
            entry.success = body.success;

            if (url.indexOf('face-data') !== -1 && body.data && body.data.employees) {
                entry.employees = body.data.employees.map((employee) => employee.employee_id);
                entry.samples = body.data.employees.map((employee) => employee.samples.length);
                entry.version = body.data.version;
            }

            if (url.indexOf('recognition-result') !== -1) {
                entry.outcome = body.data ? body.data.outcome : null;
                entry.employee_id = body.data && body.data.employee ? body.data.employee.id : null;
                entry.employee_name = body.data && body.data.employee ? body.data.employee.full_name : null;
                entry.confidence = body.data ? body.data.confidence : null;
                entry.distance = body.data ? body.data.distance : null;
            }

            if (url.indexOf('/attendance') !== -1) {
                entry.attendance = body.code;
            }
        } catch (error) {
            entry.unparsable = true;
        }

        report.server_calls.push(entry);
    });

    try {
        await page.goto(base + '/device', { waitUntil: 'domcontentloaded' });

        // wait for the kiosk to settle on the home screen with the gallery synced
        await page.waitForFunction(() => {
            const root = document.getElementById('kiosk');

            return root && root.getAttribute('data-state') === 'home';
        }, { timeout: 60000 });

        report.gallery = await page.evaluate(() => {
            const store = window.SarmadFaceStore;

            return store ? store.memory() : null;
        });

        const started = Date.now();

        await page.click('#k-action-entry');

        // the recognition attempt happens inside the camera screen
        await page.waitForFunction(() => {
            const root = document.getElementById('kiosk');
            const state = root ? root.getAttribute('data-state') : '';

            return state === 'success' || state.indexOf('error:') === 0;
        }, { timeout: 240000, polling: 500 });

        report.timings.click_to_result_ms = Date.now() - started;

        report.screen = await page.evaluate(() => {
            const root = document.getElementById('kiosk');
            const title = document.querySelector('#k-success-title');
            const message = document.querySelector('#k-success-message');
            const errorTitle = document.querySelector('#k-error-title');
            const errorMessage = document.querySelector('#k-error-message');
            const hint = document.querySelector('#k-camera-hint');
            const body = document.querySelector('#k-success-body');

            return {
                state: root ? root.getAttribute('data-state') : '',
                camera_state: root ? root.getAttribute('data-camera-state') : '',
                camera_hint: hint ? hint.textContent.trim() : '',
                success_title: title ? title.textContent.trim() : '',
                success_message: message ? message.textContent.trim() : '',
                error_title: errorTitle ? errorTitle.textContent.trim() : '',
                error_message: errorMessage ? errorMessage.textContent.trim() : '',
                success_text: body ? body.textContent.replace(/\s+/g, ' ').trim().slice(0, 300) : ''
            };
        });

        report.timings.total_ms = Date.now() - started;
    } catch (error) {
        report.fatal = String(error && error.message ? error.message : error).slice(0, 300);

        try {
            report.screen = await page.evaluate(() => {
                const root = document.getElementById('kiosk');

                return { state: root ? root.getAttribute('data-state') : '', camera_state: root ? root.getAttribute('data-camera-state') : '' };
            });
        } catch (inner) { /* page gone */ }
    }

    const recognitionCall = report.server_calls.find((call) => call.endpoint && call.endpoint.indexOf('recognition-result') === 0);
    const attendanceCall = report.server_calls.find((call) => call.endpoint && call.endpoint.indexOf('attendance') === 0);

    report.recognition = recognitionCall || null;
    report.attendance = attendanceCall || null;

    report.ok = evaluate(report, scenario);

    await browser.close();

    if (jsonOut) {
        fs.writeFileSync(jsonOut, JSON.stringify(report, null, 2));
    }

    console.log(JSON.stringify(report, null, 2));
    process.exit(report.ok ? 0 : 1);
})();

/** What each scenario must really produce. */
function evaluate(result, name) {
    const recognition = result.recognition || {};
    const screen = result.screen || {};
    const calls = result.server_calls || [];

    if (name === 'known') {
        return recognition.code === 'FACE_MATCHED'
            && screen.state === 'success'
            && (screen.success_text || '').length > 0;
    }

    if (name === 'unknown') {
        return recognition.code === 'UNKNOWN_FACE'
            && !calls.some((call) => call.endpoint && call.endpoint.indexOf('attendance') === 0)
            && screen.state === 'error:unknown_face';
    }

    if (name === 'multiple' || name === 'no_face') {
        /* the kiosk must not even try: no recognition-result call at all */
        return !recognition && calls.every((call) => (call.endpoint || '').indexOf('recognition-result') !== 0);
    }

    if (name === 'reject') {
        return (recognition.code || '').indexOf('EMPLOYEE_') === 0
            || recognition.code === 'BRANCH_MISMATCH';
    }

    return false;
}
