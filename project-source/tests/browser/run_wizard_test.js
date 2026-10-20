/* =====================================================================
   SARMAD Attendance - REAL browser test of the face enrolment wizard
   ---------------------------------------------------------------------
   What this test does (nothing is mocked):

     1) launches Chromium with a FAKE CAMERA that plays a real Y4M video file
        of a person (tests/browser/README-stagetest.md explains how to build it),
     2) logs into the manager panel over HTTP like a human would,
     3) opens /manager/employees/{id}/faces,
     4) runs the actual three-sample wizard: detect -> quality gates ->
        128-d descriptor calculated in the browser -> POST to the server,
     5) reports what the wizard really did (accepted / refused + code).

   The sample is saved only if the browser really computed a descriptor; the
   assertions on the database are made by the shell driver, not here.

   Usage:
     node tests/browser/run_wizard_test.js <base-url> <employee-id> <y4m> [--json out.json]
   ===================================================================== */

'use strict';

const fs = require('fs');
const puppeteer = require('/tmp/s5/node_modules/puppeteer-core');

const base = (process.argv[2] || 'http://127.0.0.1:8080').replace(/\/$/, '');
const employeeId = parseInt(process.argv[3] || '1', 10);
const videoFile = process.argv[4] || '/tmp/s5/cam_b.y4m';
const jsonIndex = process.argv.indexOf('--json');
const jsonOut = jsonIndex !== -1 ? process.argv[jsonIndex + 1] : null;

const report = {
    base: base,
    employee_id: employeeId,
    video: videoFile,
    samples: [],
    slots_after: null,
    wizard_status: null,
    page_errors: [],
    console_errors: [],
    ok: false
};

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/chromium',
        headless: 'new',
        /* the software GL in the test environment can hold the main thread for
           a while during a descriptor computation - never let the driver kill
           the call before the real work is done */
        protocolTimeout: 900000,
        args: [
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--use-gl=angle',
            '--use-angle=swiftshader',
            '--enable-unsafe-swiftshader',
            '--use-fake-ui-for-media-stream',
            '--use-fake-device-for-media-stream',
            '--use-file-for-fake-video-capture=' + videoFile,
            '--window-size=1280,900'
        ]
    });

    const page = await browser.newPage();

    /* The CI machine has no real GPU (SwiftShader wedges some TF.js shader
       compiles); the CPU backend is measurably as fast here. Production code
       is unchanged - this only tells the engine which backend to prefer. */
    await page.evaluateOnNewDocument(() => {
        window.SARMAD_FACE_BACKEND = 'cpu';
    });

    page.on('pageerror', (error) => report.page_errors.push(String(error.message || error)));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            report.console_errors.push(message.text().slice(0, 200));
        }
    });

    page.on('dialog', async (dialog) => {
        await dialog.dismiss();
    });

    try {
        // ---- 1. login -------------------------------------------------
        await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
        await page.type('input[name="identifier"]', 'manager');
        await page.type('input[name="password"]', 'Face#Pass2026');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.click('button[type="submit"]')
        ]);

        report.logged_in_url = page.url();

        // ---- 2. open the wizard --------------------------------------
        const wizardUrl = base + '/manager/employees/' + employeeId + '/faces';
        const response = await page.goto(wizardUrl, { waitUntil: 'domcontentloaded' });

        report.wizard_http = response ? response.status() : 0;

        await page.waitForSelector('[data-capture]', { timeout: 20000 });

        report.config = await page.$eval('#face-wizard', (node) => JSON.parse(node.getAttribute('data-config') || '{}'));

        // ---- 3. three samples, one at a time --------------------------
        for (let index = 1; index <= 3; index++) {
            const sample = await captureOne(page, index);

            report.samples.push(sample);

            if (!sample.saved) {
                break;
            }

            await delay(1500);
        }

        report.slots_after = await page.$$eval('[data-slot]', (nodes) => nodes.map((node) => ({
            index: node.getAttribute('data-slot'),
            filled: node.classList.contains('is-filled')
        })));

        report.wizard_status = await page.$eval('#fw-status', (node) => node.textContent.trim());
        report.ok = report.samples.length === 3 && report.samples.every((sample) => sample.saved);
    } catch (error) {
        report.fatal = String(error && error.stack ? error.stack : error);
    }

    await browser.close();

    if (jsonOut) {
        fs.writeFileSync(jsonOut, JSON.stringify(report, null, 2));
    }

    console.log(JSON.stringify(report, null, 2));

    process.exit(report.ok ? 0 : 1);
})();

/**
 * Click "record sample N", wait for the engine to lock onto the face, press
 * capture and read what the wizard really did.
 */
async function captureOne(page, index) {
    const result = { index: index, saved: false, code: '', state: '', message: '' };

    await page.click('[data-capture="' + index + '"]');
    await page.waitForSelector('#fw-overlay:not([hidden])', { timeout: 10000 });

    // wait for the capture button to become enabled (engine loaded + face stable)
    const deadline = Date.now() + 120000;
    let enabled = false;

    while (Date.now() < deadline) {
        enabled = await page.$eval('#fw-capture', (node) => !node.disabled);

        if (enabled) {
            break;
        }

        await delay(500);
    }

    result.face_locked = enabled;

    if (!enabled) {
        result.state = await readState(page);

        return result;
    }

    await page.click('#fw-capture');

    // The sample is saved when the slot for this index becomes filled.
    const saveDeadline = Date.now() + 60000;

    while (Date.now() < saveDeadline) {
        const filled = await page.$$eval('[data-slot]', (nodes, wanted) => {
            return nodes.some((node) => node.getAttribute('data-slot') === String(wanted)
                && node.classList.contains('is-filled'));
        }, index);

        const state = await readState(page);

        result.state = state.name;
        result.message = state.text;

        if (filled) {
            result.saved = true;

            break;
        }

        if (state.name === 'refused' || state.name === 'error') {
            break;
        }

        await delay(500);
    }

    if (!result.saved) {
        result.metrics = await page.evaluate(() => {
            const read = (id) => {
                const node = document.getElementById(id);

                return node ? node.textContent.trim() : null;
            };

            return {
                ratio: read('fw-m-ratio'),
                blur: read('fw-m-blur'),
                brightness: read('fw-m-bright'),
                center: read('fw-m-center'),
                yaw: read('fw-m-yaw')
            };
        });
    }

    result.store_image_sent = await page.evaluate(() => {
        const root = document.getElementById('face-wizard');

        return root ? !!JSON.parse(root.getAttribute('data-config') || '{}').store_image : false;
    });

    return result;
}

async function readState(page) {
    return page.evaluate(() => {
        const node = document.getElementById('fw-state');

        return {
            name: node ? node.getAttribute('data-state') : '',
            text: node ? node.textContent.trim() : ''
        };
    });
}
