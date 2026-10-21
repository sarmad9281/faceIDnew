/* =====================================================================
   SARMAD Attendance - real enrolment through the real endpoint
   ---------------------------------------------------------------------
   The three-sample WIZARD is exercised by run_wizard_test.js (live camera).
   This script covers the same server contract from a real browser with
   CONTROLLED inputs, so the recognition scenarios can rely on exactly which
   person is enrolled for which employee:

     1) login as the manager (real session, real CSRF),
     2) load the wizard page so the shipped engine is present,
     3) for each input image: compute the 128-d descriptor IN THE BROWSER with
        the shipped model,
     4) POST it to the real endpoint (same payload the wizard sends).

   Nothing is mocked: the descriptor is produced by face-api.js here.

   Usage:
     node tests/browser/run_enroll_api_test.js <base-url> <employee-id> <img1> <img2> <img3> [--json out.json]
   ===================================================================== */

'use strict';

const fs = require('fs');
const puppeteer = require('/tmp/s5/node_modules/puppeteer-core');

const base = (process.argv[2] || 'http://127.0.0.1:8080').replace(/\/$/, '');
const employeeId = parseInt(process.argv[3] || '2', 10);
const images = [process.argv[4], process.argv[5], process.argv[6]];
const jsonIndex = process.argv.indexOf('--json');
const jsonOut = jsonIndex !== -1 ? process.argv[jsonIndex + 1] : null;

const report = { employee_id: employeeId, images: images, samples: [], ok: false };

(async () => {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/chromium',
        headless: 'new',
        protocolTimeout: 600000,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']
    });

    const page = await browser.newPage();

    await page.evaluateOnNewDocument(() => {
        window.SARMAD_FACE_BACKEND = 'cpu';
    });

    page.on('pageerror', (error) => report.page_error = String(error.message || error).slice(0, 300));

    try {
        await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
        await page.type('input[name="identifier"]', 'manager');
        await page.type('input[name="password"]', 'Face#Pass2026');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.click('button[type="submit"]')
        ]);

        await page.goto(base + '/manager/employees/' + employeeId + '/faces', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#face-wizard');

        const csrf = await page.$eval('#face-wizard', (node) => node.getAttribute('data-csrf'));
        const endpoint = await page.$eval('#face-wizard', (node) => node.getAttribute('data-endpoint'));
        const modelVersion = await page.$eval('#face-wizard', (node) => node.getAttribute('data-model'));

        const loaded = await page.evaluate(() => window.SarmadFace.loadModels());

        report.backend = await page.evaluate(() => window.SarmadFace.backend());
        report.model_load = loaded;

        for (let i = 0; i < images.length; i++) {
            // the fixtures are served from the test interception (same origin)
            const file = images[i];
            const index = i + 1;

            const sample = await page.evaluate(async (url, sampleIndex) => {
                const engine = window.SarmadFace;

                const image = await new Promise((resolve, reject) => {
                    const img = new Image();
                    img.onload = () => resolve(img);
                    img.onerror = () => reject(new Error('image load failed: ' + url));
                    img.src = url;
                });

                const captured = await engine.captureSample(image);

                if (!captured.ok) {
                    return { ok: false, code: captured.code, message: captured.message };
                }

                return {
                    ok: true,
                    embedding: captured.embedding,
                    quality: captured.quality,
                    snapshot: captured.snapshot || null,
                    dimensions: captured.embedding.length
                };
            }, file, index);

            if (!sample.ok) {
                report.samples.push({ index: index, local: sample });
                break;
            }

            const response = await page.evaluate(async (target, csrfToken, payload, sampleIndex, version, quality) => {
                const body = {
                    _token: csrfToken,
                    sample_index: sampleIndex,
                    embedding: payload,
                    model_version: version,
                    engine: 'face-api.js',
                    detector: 'tiny_face_detector',
                    metric: 'euclidean',
                    quality: quality,
                    request_id: 'enrapi-' + Date.now().toString(36) + '-' + sampleIndex
                };

                const result = await fetch(target, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(body)
                });

                return { status: result.status, body: await result.json() };
            }, endpoint, csrf, sample.embedding, index, modelVersion, sample.quality ? sample.quality.score : null);

            report.samples.push({
                index: index,
                source: images[i],
                dimensions: sample.dimensions,
                quality: sample.quality,
                http: response.status,
                success: !!response.body.success,
                code: response.body.code,
                message: response.body.message,
                data: response.body.data || null
            });

            if (!response.body.success) {
                break;
            }
        }

        report.ok = report.samples.length === 3 && report.samples.every((item) => item.success === true);
    } catch (error) {
        report.fatal = String(error && error.stack ? error.stack : error).slice(0, 400);
    }

    await browser.close();

    if (jsonOut) {
        fs.writeFileSync(jsonOut, JSON.stringify(report, null, 2));
    }

    console.log(JSON.stringify(report, null, 2));
    process.exit(report.ok ? 0 : 1);
})();
