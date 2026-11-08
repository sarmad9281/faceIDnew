/* =====================================================================
   SARMAD Attendance - Kiosk controller (stage 4)
   ---------------------------------------------------------------------
   What this file really does (nothing here is a mock):
     - activation with a one-time code            (POST /device/activate)
     - branding + settings from the server        (GET  /device/config)
     - heartbeat every 60s, last-seen + online    (POST /device/heartbeat)
     - non-sensitive local settings               (POST /device/settings)
     - camera capture + full UI state machine     (getUserMedia)
     - double-submit protection (client + request_id)
     - offline detection, offline banner, queue architecture
     - PWA registration (service worker + install prompt)
     - kiosk hardening (fullscreen, wake lock, idle cursor, gesture-only settings)

   Stage 5 - REAL local face recognition:
     - the face engine (face-api.js, assets/vendor) runs IN THIS BROWSER:
       camera -> local detect -> local 128-d descriptor -> local match,
     - the descriptor gallery is fetched from /device/face-data (scoped on the
       server to THIS device's customer/branch) and cached in IndexedDB so the
       kiosk still recognises faces while the internet is down,
     - NO camera frame is ever uploaded: only the outcome travels to
       /device/recognition-result and then the attendance contract,
     - thresholds come from the server face config - one central place
       (config/config.php -> Config::get('face.…')), never hardcoded here.

   What this file deliberately does NOT do:
     - liveness detection is NOT implemented at all: no liveness code exists
     - attendance calculation (late / overtime / absence): that belongs to the
       server engine (App\Services\AttendanceEngine) and is never recomputed
       here. The kiosk shows the answer the server gave, nothing else.
     - it never invents a punch: a face that was not matched is not queued and
       not displayed as a success.

   Offline: a recognised punch that can not reach the server is stored by
   assets/js/device-queue.js (queue id, local event id, device id, local created
   at, event type, employee id, local recognition result) and replayed through
   POST /api/v1/device/attendance/sync, where the server re-validates it.
   ===================================================================== */

(function () {
    'use strict';

    var root = document.getElementById('kiosk');

    if (!root) {
        return;
    }

    /* -----------------------------------------------------------------
     * Bootstrap data comes from data-* attributes (no inline script, so the
     * page can run under a strict Content-Security-Policy).
     * ----------------------------------------------------------------*/
    var SETUP = {
        api: root.getAttribute('data-api') || '/api/v1/device',
        base: root.getAttribute('data-base') || '/',
        sw: root.getAttribute('data-sw') || 'sw.js',
        manifest: root.getAttribute('data-manifest') || 'manifest.webmanifest',
        heartbeat: parseInt(root.getAttribute('data-heartbeat') || '60', 10) || 60,
        version: root.getAttribute('data-version') || '1.0.0',
        storageKey: 'sarmad.device.credential',
        queueKey: 'sarmad.device.queue'
    };

    var el = {
        boot: document.getElementById('kiosk-boot'),
        activation: document.getElementById('kiosk-activation'),
        home: document.getElementById('kiosk-home'),
        camera: document.getElementById('kiosk-camera'),
        success: document.getElementById('kiosk-success'),
        error: document.getElementById('kiosk-error'),
        blocked: document.getElementById('kiosk-blocked'),
        codeInput: document.getElementById('activation-code'),
        codeSubmit: document.getElementById('activation-submit'),
        activationError: document.getElementById('activation-error'),
        business: document.getElementById('k-business'),
        branch: document.getElementById('k-branch'),
        branchWrap: document.getElementById('k-branch-wrap'),
        logo: document.getElementById('k-logo'),
        logoFallback: document.getElementById('k-logo-fallback'),
        deviceName: document.getElementById('k-device-name'),
        clockTime: document.getElementById('k-clock-time'),
        clockSeconds: document.getElementById('k-clock-seconds'),
        clockDate: document.getElementById('k-clock-date'),
        clockGreg: document.getElementById('k-clock-greg'),
        clockWrap: document.getElementById('k-clock'),
        statusDot: document.getElementById('k-status-dot'),
        statusText: document.getElementById('k-status-text'),
        queueCount: document.getElementById('k-queue-count'),
        lastSeen: document.getElementById('k-last-seen'),
        entryBtn: document.getElementById('k-action-entry'),
        exitBtn: document.getElementById('k-action-exit'),
        cameraVideo: document.getElementById('k-camera-video'),
        cameraWrap: document.getElementById('k-camera-wrap'),
        faceFrame: document.getElementById('k-face-frame'),
        cameraHint: document.getElementById('k-camera-hint'),
        cameraTitle: document.getElementById('k-camera-title'),
        chipPreview: document.getElementById('k-chip-preview'),
        chipLoading: document.getElementById('k-chip-loading'),
        chipRecognizing: document.getElementById('k-chip-recognizing'),
        cameraCancel: document.getElementById('k-camera-cancel'),
        cameraRetry: document.getElementById('k-camera-retry'),
        successBody: document.getElementById('k-success-body'),
        successTitle: document.getElementById('k-success-title'),
        successMessage: document.getElementById('k-success-message'),
        errorKind: document.getElementById('k-error-kind'),
        errorTitle: document.getElementById('k-error-title'),
        errorMessage: document.getElementById('k-error-message'),
        errorClose: document.getElementById('k-error-close'),
        blockedTitle: document.getElementById('k-blocked-title'),
        blockedMessage: document.getElementById('k-blocked-message'),
        blockedRetry: document.getElementById('k-blocked-retry'),
        drawer: document.getElementById('k-drawer'),
        scrim: document.getElementById('k-scrim'),
        drawerClose: document.getElementById('k-drawer-close'),
        setSound: document.getElementById('set-success-sound'),
        setSeconds: document.getElementById('set-success-seconds'),
        setClock: document.getElementById('set-show-clock'),
        setCameraFacing: document.getElementById('set-camera-facing'),
        setOfflineBanner: document.getElementById('set-offline-banner'),
        cameraTest: document.getElementById('k-camera-test'),
        cameraTestVideo: document.getElementById('k-camera-test-video'),
        cameraTestResult: document.getElementById('k-camera-test-result'),
        infoDevice: document.getElementById('k-info-device'),
        infoCustomer: document.getElementById('k-info-customer'),
        infoBranch: document.getElementById('k-info-branch'),
        infoVersion: document.getElementById('k-info-version'),
        infoStatus: document.getElementById('k-info-status'),
        infoSeen: document.getElementById('k-info-seen'),
        infoFace: document.getElementById('k-info-face'),
        forget: document.getElementById('k-forget'),
        install: document.getElementById('k-install'),
        settingsButton: document.getElementById('k-settings-open')
    };

    var state = {
        token: null,
        config: null,
        settings: null,
        features: { attendance: false, branches: false },
        status: 'booting',       // booting | active | pending | blocked | unactivated
        online: navigator.onLine !== false,
        busy: false,
        action: null,            // entry | exit
        cameraStream: null,
        cameraFacing: 'user',
        cameraTestStream: null,
        heartbeatTimer: null,
        clockTimer: null,
        lastSeen: null,
        wakeLock: null,
        idleTimer: null,
        successTimer: null,
        installEvent: null,
        lastRequestId: null,
        blockedCode: null,
        /* stage 5 - local face recognition */
        faceConfig: null,
        faceGallery: null,        // cached descriptor dataset of THIS customer
        faceTimer: null,
        faceBusy: false,
        faceStable: 0,            // consecutive frames with exactly one face
        faceDetecting: false,     // one classifier run at a time
        faceReady: false,
        faceSyncVersion: -1
    };

    /* -----------------------------------------------------------------
     * Local face recognition helpers (stage 5)
     * ----------------------------------------------------------------*/
    function faceApi() {
        return window.SarmadFace || null;
    }

    function faceStore() {
        return window.SarmadFaceStore || null;
    }

    function deviceIdFromCredential() {
        var stored = credentials.load();

        return stored && stored.device_id ? parseInt(stored.device_id, 10) : 0;
    }

    /**
     * Download / refresh the descriptor gallery of THIS device's customer.
     * The server scopes it by the device token (tenant + branch), so the kiosk
     * can never hold another customer's faces.
     */
    function syncFaceData(force) {
        if (!state.token) {
            return Promise.resolve(false);
        }

        var url = '/face-data' + (force ? '?refresh=1' : '');

        return api(url, { method: 'GET' }).then(function (payload) {
            if (!payload.success) {
                return false;
            }

            if (payload.data && payload.data.changed === false) {
                state.faceSyncVersion = parseInt(payload.data.version, 10) || 0;

                return true;
            }

            var dataset = payload.data && payload.data.employees ? payload.data : null;

            if (!dataset) {
                return false;
            }

            var store = faceStore();

            if (!store) {
                state.faceGallery = dataset;

                return true;
            }

            return store.write(deviceIdFromCredential(), dataset).then(function () {
                state.faceGallery = dataset;
                state.faceSyncVersion = parseInt(dataset.version, 10) || 0;

                return true;
            });
        });
    }

    function currentGallery() {
        if (state.faceGallery && state.faceGallery.employees && state.faceGallery.employees.length) {
            return state.faceGallery;
        }

        var store = faceStore();
        var cached = store ? store.cached(deviceIdFromCredential()) : null;

        if (cached && cached.employees && cached.employees.length) {
            state.faceGallery = cached;

            return cached;
        }

        return null;
    }

    /** Load the model ONCE per page load (a tablet must not reload it per attempt). */
    function ensureEngine() {
        var engine = faceApi();

        if (!engine) {
            return Promise.resolve({
                ok: false,
                code: 'FACE_ENGINE_MISSING',
                message: 'فایل‌های موتور تشخیص چهره روی این دستگاه بارگذاری نشد.'
            });
        }

        if (engine.isReady()) {
            return Promise.resolve({ ok: true, code: 'OK', message: '' });
        }

        setCameraState('loading', 'در حال بارگذاری مدل تشخیص چهره…');

        return engine.loadModels().then(function (report) {
            state.faceReady = !!report.ok;

            if (!report.ok) {
                reportCameraError('FACE_MODEL_LOAD_FAILED', report.detail || report.code || 'model load failed');
            }

            return report;
        });
    }

    /**
     * Remove the local descriptors of this device (revoked / inactivated /
     * forgotten / reactivation). The biometric cache is never kept "just in
     * case" - a device that lost its token must lose the faces with it.
     */
    function wipeFaceData() {
        state.faceGallery = null;
        state.faceSyncVersion = -1;

        var store = faceStore();

        if (store) {
            store.clearAll();
        }
    }

    /* -----------------------------------------------------------------
     * Small helpers
     * ----------------------------------------------------------------*/
    function $(id) {
        return document.getElementById(id);
    }

    function show(node) {
        if (node) {
            node.classList.add('is-open');
        }
    }

    function hide(node) {
        if (node) {
            node.classList.remove('is-open');
        }
    }

    function only(node) {
        [el.activation, el.camera, el.success, el.error, el.blocked].forEach(function (item) {
            if (item && item !== node) {
                hide(item);
            }
        });

        if (node) {
            show(node);
        }
    }

    function text(node, value) {
        if (node) {
            node.textContent = value === null || value === undefined ? '' : String(value);
        }
    }

    function flag(node, on, className) {
        if (!node) {
            return;
        }

        node.classList.toggle(className || 'is-on', !!on);
    }

    function pad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function nowIso() {
        return new Date().toISOString();
    }

    function requestId() {
        var id;

        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            id = window.crypto.randomUUID();
        } else if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            id = Array.prototype.map.call(bytes, function (b) {
                return ('0' + b.toString(16)).slice(-2);
            }).join('');
        } else {
            id = 'fallback-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        }

        return 'req-' + id;
    }

    /* -----------------------------------------------------------------
     * Credential storage
     *
     * The device token is NOT a user password and never reaches the screen:
     * it is written to localStorage under a kiosk-namespaced key and is
     * revoked from the owner panel. The page is same-origin only and runs
     * under a strict CSP with no third-party scripts, which is what keeps it
     * out of reach of injected code. See docs/VERIFICATION-REPORT-STAGE-4.md.
     * ----------------------------------------------------------------*/
    var credentials = {
        save: function (token, meta) {
            try {
                window.localStorage.setItem(SETUP.storageKey, JSON.stringify({
                    token: token,
                    device_uid: meta && meta.device_uid ? meta.device_uid : '',
                    /* the device id keys the local face cache: a re-activated
                       device must never read another activation's dataset */
                    device_id: meta && meta.id ? parseInt(meta.id, 10) : 0,
                    saved_at: nowIso()
                }));
            } catch (error) {
                /* private mode / quota: the kiosk simply asks for activation again */
            }

            state.token = token;
        },
        load: function () {
            try {
                var raw = window.localStorage.getItem(SETUP.storageKey);

                if (!raw) {
                    return null;
                }

                var parsed = JSON.parse(raw);

                return parsed && typeof parsed.token === 'string' && parsed.token.length > 8 ? parsed : null;
            } catch (error) {
                return null;
            }
        },
        clear: function () {
            try {
                window.localStorage.removeItem(SETUP.storageKey);
            } catch (error) {
                /* ignore */
            }

            state.token = null;
        }
    };

    /* -----------------------------------------------------------------
     * Offline queue (stage 6/7 - real sync)
     *
     * A punch that was recognised on the device while the connection was down
     * is stored with its full identity (queue id, local event id, device id,
     * local created at, event type, employee id, local recognition result) and
     * replayed later through POST /api/v1/device/attendance/sync, where the
     * server re-validates every record. The logic lives in device-queue.js so
     * the very same code is covered by tests/device_queue_test.js.
     *
     * No image, no descriptor and no token copy is ever stored in the queue.
     * ----------------------------------------------------------------*/
    var queue = window.SarmadDeviceQueue.create({
        read: function () {
            try {
                var raw = window.localStorage.getItem(SETUP.queueKey);
                var parsed = raw ? JSON.parse(raw) : [];

                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        },
        write: function (items) {
            try {
                window.localStorage.setItem(SETUP.queueKey, JSON.stringify(items));
            } catch (error) {
                /* storage full or blocked: the punch stays in memory only */
            }

            renderQueue();
        }
    }, { max: window.SarmadDeviceQueue.MAX_ITEMS });

    /* -----------------------------------------------------------------
     * Sync of the offline queue
     * ----------------------------------------------------------------*/
    function syncQueue(reason) {
        var items = queue.read();

        if (!items.length || !state.token) {
            return { sent: 0, pending: items.length, accepted: 0, duplicate: 0, refused: 0, online: state.online };
        }

        return api('/attendance/sync', { body: { events: queue.buildBatch(window.SarmadDeviceQueue.DEFAULT_BATCH) } })
            .then(function (payload) {
                var data = payload && payload.data ? payload.data : {};

                if (payload && payload.code === 'NETWORK_ERROR') {
                    setOnline(false);

                    return { sent: 0, pending: items.length, accepted: 0, duplicate: 0, refused: 0, online: false };
                }

                var result = queue.applyResults(data.records || []);

                setOnline(true);
                text(el.queueCount, result.kept.length > 0 ? 'در صف ارسال: ' + result.kept.length : '');

                if (result.accepted > 0 || result.duplicate > 0 || result.refused.length > 0) {
                    var parts = [];

                    if (result.accepted > 0) {
                        parts.push(result.accepted + ' رویداد ثبت شد');
                    }

                    if (result.duplicate > 0) {
                        parts.push(result.duplicate + ' رویداد تکراری بود');
                    }

                    if (result.refused.length > 0) {
                        parts.push(result.refused.length + ' رویداد پذیرفته نشد و در صف ماند');
                    }

                    showNotice('هم‌گام‌سازی صف آفلاین', parts.join(' • '), result.refused.length > 0 ? 9000 : 5000);
                }

                return {
                    sent: result.accepted + result.duplicate,
                    pending: result.kept.length,
                    accepted: result.accepted,
                    duplicate: result.duplicate,
                    refused: result.refused.length,
                    online: true,
                    reason: reason || 'auto'
                };
            });
    }

    function renderQueue() {
        var count = queue.count();
        text(el.queueCount, count > 0 ? 'در صف ارسال: ' + count : '');
    }

    /* -----------------------------------------------------------------
     * API access
     * ----------------------------------------------------------------*/
    function api(path, options) {
        options = options || {};

        var headers = options.headers || {};
        headers['Accept'] = 'application/json';

        if (options.body) {
            headers['Content-Type'] = 'application/json';
        }

        if (state.token) {
            headers['Authorization'] = 'Bearer ' + state.token;
        }

        return fetch(SETUP.api + path, {
            method: options.method || (options.body ? 'POST' : 'GET'),
            headers: headers,
            body: options.body ? JSON.stringify(options.body) : undefined,
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, code: 'INVALID_RESPONSE', message: 'پاسخ سرور قابل خواندن نبود.' };
            }).then(function (payload) {
                payload.__status = response.status;

                return payload;
            });
        }).catch(function () {
            return { success: false, code: 'NETWORK_ERROR', message: 'ارتباط با سرور برقرار نشد.', __status: 0 };
        });
    }

    /* -----------------------------------------------------------------
     * Connection status
     * ----------------------------------------------------------------*/
    function setOnline(online) {
        state.online = !!online;

        root.classList.toggle('is-offline', !state.online);
        flag(el.statusDot, state.online, 'is-online');

        if (!state.online) {
            el.statusDot.classList.remove('is-online');
            el.statusDot.classList.add('is-offline');
        } else {
            el.statusDot.classList.remove('is-offline');
        }

        text(el.statusText, state.online ? 'متصل به سرور' : 'بدون اتصال');

        if (el.statusDot) {
            el.statusDot.classList.toggle('is-syncing', state.busy);
        }
    }

    window.addEventListener('online', function () {
        setOnline(true);
        pushHeartbeat();
    });

    window.addEventListener('offline', function () {
        setOnline(false);
    });

    /* -----------------------------------------------------------------
     * Clock (Gregorian + Jalali, no external library)
     * ----------------------------------------------------------------*/
    function jalaliParts(date) {
        var gy = date.getFullYear();
        var gm = date.getMonth() + 1;
        var gd = date.getDate();
        var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var jy = gy <= 1600 ? 0 : 979;
        gy -= gy <= 1600 ? 621 : 1600;

        var gy2 = gm > 2 ? gy + 1 : gy;
        var days = (365 * gy) + parseInt((gy2 + 3) / 4, 10) - parseInt((gy2 + 99) / 100, 10)
            + parseInt((gy2 + 399) / 400, 10) - 80 + gd + g_d_m[gm - 1];

        jy += 33 * parseInt(days / 12053, 10);
        days %= 12053;
        jy += 4 * parseInt(days / 1461, 10);
        days %= 1461;

        if (days > 365) {
            jy += parseInt((days - 1) / 365, 10);
            days = (days - 1) % 365;
        }

        var jm = days < 186 ? 1 + parseInt(days / 31, 10) : 7 + parseInt((days - 186) / 30, 10);
        var jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);

        return { year: jy, month: jm, day: jd };
    }

    var JA_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var FA_WEEKDAYS = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];

    function renderClock() {
        var now = new Date();
        var jalali = jalaliParts(now);

        text(el.clockTime, pad(now.getHours()) + ':' + pad(now.getMinutes()));
        text(el.clockSeconds, ':' + pad(now.getSeconds()));

        var jalaliNode = $('k-clock-jalali');

        if (jalaliNode) {
            jalaliNode.textContent = jalali.day + ' ' + JA_MONTHS[jalali.month - 1] + ' ' + jalali.year;
        }

        text(el.clockGreg, now.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }));

        var weekdayNode = $('k-clock-weekday');

        if (weekdayNode) {
            weekdayNode.textContent = FA_WEEKDAYS[now.getDay()];
        }
    }

    function startClock() {
        renderClock();

        if (state.clockTimer) {
            window.clearInterval(state.clockTimer);
        }

        state.clockTimer = window.setInterval(renderClock, 1000);
    }

    /* -----------------------------------------------------------------
     * Screens
     * ----------------------------------------------------------------*/
    function hideBoot() {
        if (el.boot) {
            el.boot.classList.add('is-hidden');
        }
    }

    function showActivation(message, code) {
        state.status = 'unactivated';
        hideBoot();
        only(el.activation);
        text(el.activationError, message || '');
        flag(el.activationError, !!message, 'is-open');

        if (el.codeInput) {
            el.codeInput.value = '';
            el.codeInput.focus();
        }

        root.setAttribute('data-state', 'unactivated:' + (code || ''));
    }

    function showBlocked(code, message) {
        state.status = 'blocked';
        state.blockedCode = code;
        hideBoot();
        only(el.blocked);
        text(el.blockedTitle, code === 'DEVICE_REVOKED' ? 'این دستگاه لغو شده است' : 'این دستگاه فعال نیست');
        text(el.blockedMessage, message || 'برای فعال‌سازی با مسئول مجموعه تماس بگیرید.');
        root.setAttribute('data-state', 'blocked:' + code);
    }

    function showHome() {
        state.status = 'active';
        hideBoot();
        only(null);
        root.setAttribute('data-state', 'home');
        root.setAttribute('data-attendance', state.features.attendance ? 'on' : 'off');

        setActionEnabled(state.features.attendance);
    }

    function setActionEnabled(enabled) {
        [el.entryBtn, el.exitBtn].forEach(function (button) {
            if (!button) {
                return;
            }

            button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
            button.disabled = !enabled;
        });
    }

    function showCamera(action) {
        state.action = action;
        only(el.camera);
        root.setAttribute('data-state', 'camera:' + action);
        text(el.cameraTitle, action === 'entry' ? 'ثبت ورود' : 'ثبت خروج');
        setCameraState('preview');
        state.faceBusy = false;
        state.faceStable = 0;

        /* Model + gallery first, then the live loop: the kiosk must never show
           "recognising" while it has nothing to recognise with. */
        ensureEngine().then(function (report) {
            if (!report.ok) {
                showError('engine', 'موتور تشخیص چهره آماده نیست', report.message || 'بارگذاری مدل تشخیص چهره ناموفق بود.', 6000);
                closeCamera();

                return;
            }

            return syncFaceData(false).then(function () {
                startFaceLoop();
            });
        });
    }

    function setCameraState(name, message) {
        root.setAttribute('data-camera-state', name);

        flag(el.cameraWrap, name === 'recognizing', 'is-recognizing');
        flag(el.chipPreview, name === 'preview');
        flag(el.chipLoading, name === 'loading');
        flag(el.chipRecognizing, name === 'recognizing');
        flag(el.cameraRetry, name === 'captured');
        flag(el.faceFrame, name === 'recognizing' || name === 'captured', 'is-locked');
        flag(el.faceFrame, name === 'guidance', 'is-warning');

        if (message) {
            text(el.cameraHint, message);
        }
    }

    var HINTS = {
        preview: 'دوربین در حال آماده‌سازی است…',
        guidance: 'چهره خود را داخل کادر قرار دهید',
        loading: 'در حال پردازش تصویر…',
        recognizing: 'چهره در حال بررسی است…',
        captured: 'تصویر ثبت شد'
    };

    /* -----------------------------------------------------------------
     * Result screens
     * ----------------------------------------------------------------*/
    function showError(kind, title, message, autoClose) {
        only(el.error);
        root.setAttribute('data-state', 'error:' + kind);
        text(el.errorKind, kind);
        text(el.errorTitle, title);
        text(el.errorMessage, message);
        playTone(false);

        if (autoClose) {
            window.setTimeout(function () {
                if (root.getAttribute('data-state') === 'error:' + kind) {
                    closeCamera();
                    showHome();
                }
            }, autoClose);
        }
    }

    /**
     * A neutral, auto closing notice (queue sync result, sync in progress...).
     * It never pretends a punch was recorded: it only reports what happened.
     */
    function showNotice(title, message, autoClose) {
        only(el.error);
        root.setAttribute('data-state', 'info');
        text(el.errorKind, 'info');
        text(el.errorTitle, title);
        text(el.errorMessage, message);

        if (autoClose) {
            window.setTimeout(function () {
                if (root.getAttribute('data-state') === 'info') {
                    closeCamera();
                    showHome();
                }
            }, autoClose);
        }
    }

    function closeCamera() {
        stopFaceLoop();
        stopStream(state.cameraStream);
        state.cameraStream = null;
        state.faceBusy = false;
        state.faceStable = 0;
        hide(el.camera);
    }

    function showSuccess(payload) {
        var employee = payload.employee || {};
        var seconds = (state.settings && state.settings.success_display_seconds) || 5;

        only(el.success);
        root.setAttribute('data-state', 'success');

        var name = employee.full_name || '';
        var photo = employee.photo_url || '';

        if (el.successBody) {
            el.successBody.innerHTML = '';

            var wrap = document.createElement('div');
            wrap.className = 'k-employee';

            var avatar;

            if (photo) {
                avatar = document.createElement('img');
                avatar.className = 'k-employee-photo';
                avatar.alt = '';
                avatar.src = photo;
            } else {
                avatar = document.createElement('div');
                avatar.className = 'k-employee-photo';
                avatar.textContent = (name || '؟').trim().charAt(0);
            }

            var info = document.createElement('div');
            var nameNode = document.createElement('div');
            nameNode.className = 'k-employee-name';
            nameNode.textContent = name;

            var meta = document.createElement('div');
            meta.className = 'k-employee-meta';
            meta.textContent = [employee.employee_code, employee.position].filter(Boolean).join(' — ');

            info.appendChild(nameNode);
            info.appendChild(meta);
            wrap.appendChild(avatar);
            wrap.appendChild(info);
            el.successBody.appendChild(wrap);

            if (payload.facts) {
                var facts = document.createElement('div');
                facts.className = 'k-result-facts';

                payload.facts.forEach(function (fact) {
                    var box = document.createElement('div');
                    box.className = 'k-fact';
                    var label = document.createElement('span');
                    label.textContent = fact.label;
                    var value = document.createElement('strong');
                    value.textContent = fact.value;
                    box.appendChild(label);
                    box.appendChild(value);
                    facts.appendChild(box);
                });

                el.successBody.appendChild(facts);
            }
        }

        text(el.successTitle, payload.title || 'ورود با موفقیت ثبت شد');
        text(el.successMessage, payload.message || '');
        playTone(true);

        window.clearTimeout(state.successTimer);
        state.successTimer = window.setTimeout(function () {
            if (root.getAttribute('data-state') === 'success') {
                showHome();
            }
        }, Math.max(2, seconds) * 1000);
    }

    /* -----------------------------------------------------------------
     * Sound (WebAudio, no asset needed - works offline)
     * ----------------------------------------------------------------*/
    function playTone(success) {
        if (!state.settings || !state.settings.success_sound) {
            return;
        }

        try {
            var Ctor = window.AudioContext || window.webkitAudioContext;

            if (!Ctor) {
                return;
            }

            var ctx = new Ctor();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.value = success ? 880 : 320;
            gain.gain.value = 0.06;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();

            if (success) {
                osc.frequency.setValueAtTime(1180, ctx.currentTime + 0.12);
            }

            osc.stop(ctx.currentTime + 0.24);

            window.setTimeout(function () {
                ctx.close && ctx.close();
            }, 500);
        } catch (error) {
            /* sound is a nicety, never a failure */
        }
    }

    /* -----------------------------------------------------------------
     * Activation
     * ----------------------------------------------------------------*/
    function normaliseCode(value) {
        var clean = String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');

        if (clean.length > 6) {
            return clean.slice(0, 6) + '-' + clean.slice(6, 10);
        }

        return clean;
    }

    function activate() {
        var raw = el.codeInput ? el.codeInput.value : '';
        var code = normaliseCode(raw);

        if (code.replace('-', '').length < 10) {
            text(el.activationError, 'کد فعال‌سازی ۱۰ رقم/حرف است.');
            flag(el.activationError, true, 'is-open');

            return;
        }

        if (el.codeSubmit) {
            el.codeSubmit.disabled = true;
        }

        text(el.activationError, 'در حال فعال‌سازی…');
        flag(el.activationError, true, 'is-open');

        api('/activate', {
            body: {
                code: code,
                platform: navigator.platform || '',
                app_version: SETUP.version,
                device_uid: ''
            }
        }).then(function (payload) {
            if (el.codeSubmit) {
                el.codeSubmit.disabled = false;
            }

            if (!payload.success) {
                text(el.activationError, payload.message || 'فعال‌سازی ناموفق بود.');
                flag(el.activationError, true, 'is-open');

                return;
            }

            credentials.save(payload.data.token, payload.data.device);
            wipeFaceData();
            applyConfig(payload.data.config);
            only(null);
            showHome();
            startHeartbeat();
            pushHeartbeat();
        });
    }

    /* -----------------------------------------------------------------
     * Config / branding
     * ----------------------------------------------------------------*/
    function applyConfig(config) {
        if (!config) {
            return;
        }

        state.config = config;
        state.settings = config.settings || {};
        state.features = config.features || state.features;
        state.lastSeen = config.device ? config.device.last_seen_at : null;

        var customer = config.customer || {};
        var branch = config.branch || null;

        text(el.business, customer.name || 'SARMAD Attendance');
        text(el.deviceName, (config.device && config.device.name) || '');

        if (el.logo && customer.logo_url) {
            el.logo.src = customer.logo_url;
            el.logo.hidden = false;

            if (el.logoFallback) {
                el.logoFallback.hidden = true;
            }
        } else {
            if (el.logoFallback) {
                el.logoFallback.hidden = false;
                el.logoFallback.textContent = initials(customer.name || 'SA');
            }

            if (el.logo) {
                el.logo.hidden = true;
            }
        }

        if (branch && branch.name) {
            text(el.branch, branch.name);
            el.branchWrap && (el.branchWrap.hidden = false);
        } else if (el.branchWrap) {
            el.branchWrap.hidden = true;
        }

        applySettings(state.settings);
        applyFaceConfig(config.face || null);
        updateInfoPanel();
        setOnline(state.online);
        renderQueue();
    }

    /**
     * The face block of GET /device/config is the ONLY place this client takes
     * the engine settings and thresholds from: they originate in
     * config/config.php (Config::get('face.…')) and are served to the device.
     */
    function applyFaceConfig(face) {
        if (!face) {
            return;
        }

        state.faceConfig = face;

        var engine = faceApi();

        if (engine) {
            engine.configure(face);
        }

        if (face.version !== undefined && face.version !== null) {
            state.faceSyncVersion = parseInt(face.version, 10) || 0;
        }

        state.faceReady = !!(engine && engine.isReady());

        /* A cached gallery is what keeps the kiosk recognising faces while the
           internet is down. */
        var store = faceStore();

        if (store) {
            state.faceGallery = store.cached(deviceIdFromCredential());
        }
    }

    /** Engine + gallery status for the settings drawer (never the vectors). */
    function faceStatusLine() {
        var engine = faceApi();
        var report = engine ? engine.status() : null;
        var gallery = currentGallery();

        if (!report) {
            return 'موتور چهره: بارگذاری نشد';
        }

        return [
            'موتور: ' + report.engine + ' ' + (report.loaded ? '(آماده)' : '(بارگذاری‌نشده)'),
            'بک‌اند: ' + (report.backend || '—'),
            'آستانه: ' + report.metric + ' @ ' + report.match_threshold,
            'کارمند هم‌گام‌شده: ' + (gallery && gallery.employees ? gallery.employees.length : 0),
            'نسخه داده چهره: ' + (state.faceSyncVersion >= 0 ? state.faceSyncVersion : '—'),
            'liveness: پیاده‌سازی نشده'
        ].join(' | ');
    }

    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/).slice(0, 2);

        return parts.map(function (part) {
            return part.charAt(0);
        }).join('') || 'SA';
    }

    function applySettings(settings) {
        if (!settings) {
            return;
        }

        state.settings = settings;
        state.cameraFacing = settings.camera_facing || 'user';

        if (el.setSound) {
            el.setSound.checked = !!settings.success_sound;
        }

        if (el.setClock) {
            el.setClock.checked = !!settings.show_clock;
        }

        if (el.setOfflineBanner) {
            el.setOfflineBanner.checked = !!settings.offline_banner;
        }

        if (el.setCameraFacing) {
            el.setCameraFacing.value = state.cameraFacing;
        }

        if (el.setSeconds) {
            el.setSeconds.value = settings.success_display_seconds || 5;
        }

        if (el.clockWrap) {
            el.clockWrap.classList.toggle('hide-clock', !settings.show_clock);
        }

        text(el.infoStatus, settings.show_clock === false ? 'ساعت مخفی' : '');
    }

    function updateInfoPanel() {
        var device = (state.config && state.config.device) || {};
        var customer = (state.config && state.config.customer) || {};
        var branch = state.config && state.config.branch ? state.config.branch.name : '—';

        text(el.infoDevice, (device.name || '—') + (device.device_uid ? ' (' + device.device_uid + ')' : ''));
        text(el.infoCustomer, customer.name || '—');
        text(el.infoBranch, branch || '—');
        text(el.infoVersion, SETUP.version);
        text(el.infoStatus, device.status || '—');
        text(el.infoSeen, state.lastSeen || '—');
        text(el.infoFace, faceStatusLine());
    }

    function loadConfig() {
        return api('/config', { method: 'GET' }).then(function (payload) {
            if (payload.success) {
                applyConfig(payload.data);
                showHome();
                startHeartbeat();

                /* Warm the engine and pull the gallery in the background so the
                   first employee to walk up does not wait for a 13 MB model. */
                ensureEngine().then(function (report) {
                    if (report.ok) {
                        return syncFaceData(false);
                    }

                    return false;
                });

                return true;
            }

            var code = payload.code || payload.__status;

            if (code === 'DEVICE_REVOKED' || code === 'DEVICE_INACTIVE' || code === 'CUSTOMER_DISABLED' || code === 'DEVICE_UNAUTHORIZED') {
                if (code === 'DEVICE_UNAUTHORIZED') {
                    credentials.clear();
                    wipeFaceData();
                    showActivation('اعتبار این دستگاه باطل شده است. کد فعال‌سازی جدید را وارد کنید.', code);

                    return false;
                }

                wipeFaceData();

                showBlocked(code, payload.message);

                return false;
            }

            showActivation(payload.message || 'ارتباط با سرور برقرار نشد.', code);

            return false;
        });
    }

    /* -----------------------------------------------------------------
     * Heartbeat
     * ----------------------------------------------------------------*/
    function pushHeartbeat() {
        if (!state.token) {
            return;
        }

        api('/heartbeat', {
            body: {
                /* the offset is measured from the previous answer, never invented:
                   the server stores it so an offline punch can be judged against
                   the real clock of this device */
                clock_offset: state.clockOffsetSeconds || 0,
                app_version: SETUP.version
            }
        }).then(function (payload) {
            if (payload.success) {
                setOnline(true);

                if (payload.data && payload.data.server_time) {
                    var serverNow = Date.parse(payload.data.server_time);

                    if (!isNaN(serverNow)) {
                        state.clockOffsetSeconds = Math.round((Date.now() - serverNow) / 1000);
                    }
                }
                state.lastSeen = payload.data.device ? payload.data.device.last_seen_at : state.lastSeen;

                if (payload.data.pending_settings) {
                    applySettings(payload.data.pending_settings);
                }

                checkFaceVersion(payload);

                if (state.config && state.config.device) {
                    state.config.device.last_seen_at = state.lastSeen;
                }

                updateInfoPanel();

                // The link is back: replay whatever was recognised while it was
                // down. Throttled, so a 10 second heartbeat can not flood the
                // server with sync calls.
                if (queue.count() > 0) {
                    var now = Date.now();

                    if (!state.lastSyncAt || now - state.lastSyncAt > 30000) {
                        state.lastSyncAt = now;
                        syncQueue('heartbeat');
                    }
                }

                return;
            }

            setOnline(payload.code !== 'NETWORK_ERROR' ? true : false);

            if (payload.code === 'DEVICE_REVOKED' || payload.code === 'DEVICE_INACTIVE' || payload.code === 'CUSTOMER_DISABLED') {
                stopHeartbeat();
                wipeFaceData();
                showBlocked(payload.code, payload.message);
            } else if (payload.code === 'DEVICE_UNAUTHORIZED') {
                stopHeartbeat();
                credentials.clear();
                wipeFaceData();
                showActivation('اعتبار این دستگاه باطل شده است. کد فعال‌سازی جدید را وارد کنید.', payload.code);
            }
        });
    }

    /**
     * Face data version check.
     *
     * The server answers `face.changed` in every heartbeat, so a reset, a new
     * enrolment or a deactivated employee reaches the device within one
     * heartbeat - and only then does the kiosk re-download its scoped gallery.
     */
    function checkFaceVersion(payload) {
        var face = payload && payload.data ? payload.data.face : null;

        if (!face || !state.token) {
            return;
        }

        if (face.changed) {
            syncFaceData(true);
        } else if (face.version !== undefined && face.version !== null) {
            state.faceSyncVersion = parseInt(face.version, 10) || 0;
        }
    }

    function startHeartbeat() {
        stopHeartbeat();

        var seconds = Math.max(20, SETUP.heartbeat);

        state.heartbeatTimer = window.setInterval(pushHeartbeat, seconds * 1000);
    }

    function stopHeartbeat() {
        if (state.heartbeatTimer) {
            window.clearInterval(state.heartbeatTimer);
            state.heartbeatTimer = null;
        }
    }

    /* -----------------------------------------------------------------
     * Camera
     * ----------------------------------------------------------------*/
    function stopStream(stream) {
        if (stream && stream.getTracks) {
            stream.getTracks().forEach(function (track) {
                track.stop();
            });
        }
    }

    function openCamera(action) {
        if (state.busy) {
            return;
        }

        showCamera(action);
        setCameraState('loading', HINTS.loading);

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            reportCameraError('CAMERA_UNSUPPORTED', 'این مرورگر از دوربین پشتیبانی نمی‌کند.');
            showError('camera', 'دوربین در دسترس نیست', 'مرورگر این دستگاه از دسترسی به دوربین پشتیبانی نمی‌کند. لطفاً از مرورگر به‌روز (Chrome/Edge/Safari) استفاده کنید.', 6000);

            return;
        }

        navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: state.cameraFacing === 'environment' ? 'environment' : 'user',
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        }).then(function (stream) {
            state.cameraStream = stream;

            if (el.cameraVideo) {
                el.cameraVideo.srcObject = stream;
                el.cameraVideo.classList.toggle('is-rear', state.cameraFacing === 'environment');
                var playing = el.cameraVideo.play();

                if (playing && playing.catch) {
                    playing.catch(function () { /* autoplay guard */ });
                }
            }

            setCameraState('guidance', HINTS.guidance);

            /* The loop is already running from showCamera() and picks the
               stream up as soon as the first frames arrive. */
            startFaceLoop();
        }).catch(function (error) {
            var name = String(error && error.name ? error.name : error);

            if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
                reportCameraError('CAMERA_PERMISSION_DENIED', name);
                showError('camera', 'دسترسی به دوربین ممکن نشد', 'دسترسی دوربین فعال نیست. لطفاً اجازه استفاده از دوربین را فعال کنید.', 7000);

                return;
            }

            if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
                reportCameraError('CAMERA_NOT_FOUND', name);
                showError('camera', 'دوربین پیدا نشد', 'دوربین دستگاه در دسترس نیست.', 7000);

                return;
            }

            if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
                reportCameraError('CAMERA_BUSY', name);
                showError('camera', 'دوربین در اختیار برنامه دیگری است', 'دوربین دستگاه اکنون توسط برنامه دیگری استفاده می‌شود. لطفاً آن برنامه را ببندید و دوباره تلاش کنید.', 7000);

                return;
            }

            reportCameraError('CAMERA_UNAVAILABLE', name);
            showError('camera', 'دسترسی به دوربین ممکن نشد', 'دوربین دستگاه در دسترس نیست.', 7000);
        });
    }

    function reportCameraError(code, message) {
        if (!state.token) {
            return;
        }

        api('/error', { body: { code: code, message: message.slice(0, 200) } });
    }

    /**
     * The live detection loop.
     *
     * Runs on a cadence (never on every frame) and only starts a recognition
     * attempt when exactly ONE face has been stable for two frames - that is
     * what keeps an economic tablet responsive and stops the camera from
     * "recognising" a passer-by mid-step.
     */
    function startFaceLoop() {
        stopFaceLoop();

        var engine = faceApi();

        if (!engine || !el.cameraVideo) {
            return;
        }

        var interval = (state.faceConfig && parseInt(state.faceConfig.detect_interval_ms, 10)) || 350;

        state.faceStable = 0;

        state.faceTimer = window.setInterval(function () {
            /* One detection at a time: the loop must never queue up work on an
               economic device (that is how a kiosk starts dropping frames). */
            if (state.faceDetecting || state.faceBusy || !state.cameraStream || !el.cameraVideo) {
                return;
            }

            if (root.getAttribute('data-state') !== 'camera:' + state.action) {
                return;
            }

            if (el.cameraVideo.readyState < 2 || !el.cameraVideo.videoWidth) {
                return;
            }

            var size = state.faceConfig && state.faceConfig.detector_input_size;

            state.faceDetecting = true;

            engine.detectFaces(el.cameraVideo, { inputSize: size }).then(function (faces) {
                state.faceDetecting = false;
                var count = faces.length;

                drawFaceHint(count, faces);

                if (count === 0) {
                    state.faceStable = 0;
                    setCameraState('guidance', 'چهره خود را داخل محدوده قرار دهید');

                    return;
                }

                if (count > 1) {
                    state.faceStable = 0;
                    setCameraState('guidance', 'لطفاً فقط یک نفر مقابل دوربین قرار بگیرد.');

                    return;
                }

                state.faceStable++;
                setCameraState('guidance', 'چهره خود را داخل محدوده قرار دهید');

                if (state.faceStable >= 2) {
                    runRecognition(state.action);
                }
            })['catch'](function () {
                /* a dropped frame is not worth an error screen */
                state.faceDetecting = false;
            });
        }, interval);
    }

    function stopFaceLoop() {
        if (state.faceTimer) {
            window.clearInterval(state.faceTimer);
            state.faceTimer = null;
        }

        state.faceDetecting = false;
        state.faceStable = 0;
    }

    /** Paint the live guidance around the face frame (pure UI). */
    function drawFaceHint(count, faces) {
        if (!el.faceFrame) {
            return;
        }

        el.faceFrame.classList.toggle('is-locked', count === 1);
        el.faceFrame.classList.toggle('is-multiple', count > 1);

        if (count === 1 && faces && faces.length) {
            var video = el.cameraVideo;
            var frame = faces[0].frame || { width: (video && video.videoWidth) || 1, height: (video && video.videoHeight) || 1 };
            var box = faces[0].box;
            var left = (box.x / frame.width) * 100;
            var width = (box.width / frame.width) * 100;

            if (state.cameraFacing !== 'environment') {
                left = 100 - left - width;
            }

            el.faceFrame.style.setProperty('--k-face-left', left.toFixed(2) + '%');
            el.faceFrame.style.setProperty('--k-face-width', width.toFixed(2) + '%');
        }
    }

    /**
     * ONE recognition attempt: local descriptor -> local match -> outcome.
     * The image never leaves the device.
     */
    function runRecognition(action) {
        if (state.faceBusy) {
            return;
        }

        var engine = faceApi();

        if (!engine || !engine.isReady()) {
            return;
        }

        var gallery = currentGallery();

        if (!gallery || !gallery.employees || gallery.employees.length === 0) {
            state.faceBusy = true;
            showError(
                'no_gallery',
                'چهره‌ای روی این دستگاه هم‌گام نشده است',
                'برای هیچ کارمندی سه نمونه چهره ثبت نشده است یا داده چهره هنوز دریافت نشده است. لطفاً با مسئول مجموعه تماس بگیرید.',
                7000
            );
            closeCamera();

            return;
        }

        state.faceBusy = true;
        state.action = action;
        setCameraState('recognizing', HINTS.recognizing);

        var requestId = requestId();
        state.lastRequestId = requestId;

        engine.recognize(el.cameraVideo).then(function (capture) {
            if (!capture.ok) {
                state.faceBusy = false;
                state.faceStable = 0;
                reportOutcome(action, requestId, capture.code, null, capture.message);

                return;
            }

            var match = engine.match(capture.embedding, gallery.employees);

            if (match.decision === 'matched') {
                reportOutcome(action, requestId, 'FACE_MATCHED', {
                    employee_id: match.best.employee_id,
                    sample_index: match.best.sample_index,
                    distance: match.best.distance,
                    confidence: match.best.similarity
                }, '');

                return;
            }

            state.faceBusy = false;
            state.faceStable = 0;

            if (match.decision === 'ambiguous') {
                reportOutcome(action, requestId, 'FACE_MATCH_AMBIGUOUS', {
                    distance: match.best ? match.best.distance : null,
                    confidence: match.best ? match.best.similarity : null
                }, '');

                return;
            }

            reportOutcome(action, requestId, 'UNKNOWN_FACE', {
                distance: match.best ? match.best.distance : null,
                confidence: match.best ? match.best.similarity : null
            }, '');
        })['catch'](function (error) {
            state.faceBusy = false;
            reportCameraError('RECOGNITION_FAILED', String(error && error.message ? error.message : error));
            showError('engine', 'خطا در تشخیص چهره', 'پردازش تصویر روی این دستگاه انجام نشد. لطفاً دوباره تلاش کنید.', 6000);
            closeCamera();
        });
    }

    /**
     * Tell the server what happened locally. The server verifies the tenant,
     * the branch, the employee status and the 3/3 sample requirement, and only
     * accepts a descriptor-level result (never an image).
     */
    function reportOutcome(action, requestId, outcome, extra, localMessage) {
        var payload = {
            request_id: requestId,
            outcome: outcome === 'FACE_MATCHED' ? 'matched'
                : (outcome === 'FACE_MATCH_AMBIGUOUS' ? 'ambiguous' : String(outcome).toLowerCase()),
            action: action,
            model_version: (state.faceConfig && state.faceConfig.model_version) || '',
            occurred_at: nowIso()
        };

        if (extra && extra.employee_id) {
            payload.employee_id = extra.employee_id;
        }

        if (extra && typeof extra.distance === 'number') {
            payload.distance = Math.round(extra.distance * 10000) / 10000;
        }

        if (extra && typeof extra.confidence === 'number') {
            payload.confidence = Math.round(extra.confidence * 10000) / 10000;
        }

        if (extra && extra.sample_index) {
            payload.sample_index = extra.sample_index;
        }

        if (!state.online) {
            /* Offline: only a real match may enter the queue - a face that was
               not matched is never turned into a punch. */
            enqueueOffline(action, requestId, outcome, extra);
            setOnline(false);
            finishRecognition(action, outcome, extra, 'offline', localMessage);

            return;
        }

        api('/recognition-result', { body: payload }).then(function (response) {
            if (response.code === 'NETWORK_ERROR') {
                enqueueOffline(action, requestId, outcome, extra);
                setOnline(false);
                finishRecognition(action, outcome, extra, 'offline', localMessage);

                return;
            }

            finishRecognition(action, outcome, extra, 'online', localMessage, response);
        });
    }

    function localEventId() {
        var id;

        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            id = window.crypto.randomUUID();
        } else {
            id = 'local-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e9).toString(36);
        }

        return String(id).replace(/[^A-Za-z0-9._-]/g, '').slice(0, 64);
    }

    /**
     * Store a locally recognised punch for the next sync. The server will
     * re-validate everything (tenant, employee, branch, duplicates) and may
     * still refuse it - the screen only says «در صف ارسال ثبت شد».
     */
    function enqueueOffline(action, requestId, outcome, extra) {
        if (outcome !== 'FACE_MATCHED' || !extra || !extra.employee_id) {
            return null;
        }

        var stored = queue.push({
            local_event_id: localEventId(),
            device_id: deviceIdFromCredential(),
            event_type: action,
            employee_id: extra.employee_id,
            local_created_at: nowIso(),
            request_id: requestId,
            recognition: {
                outcome: outcome,
                distance: extra.distance,
                confidence: extra.confidence,
                sample_index: extra.sample_index,
                model_version: (state.faceConfig && state.faceConfig.model_version) || ''
            }
        });

        if (stored) {
            text(el.queueCount, 'در صف ارسال: ' + queue.count());
            showNotice('ثبت آفلاین',
                'ارتباط با سرور برقرار نیست؛ ورود/خروج شما در صف ذخیره شد و پس از برقراری ارتباط به‌صورت خودکار ثبت می‌شود.',
                6000);
        }

        return stored;
    }

    /**
     * Screen decision for every documented outcome. Nothing is faked: a face
     * that was not matched never reaches the success path.
     */
    function finishRecognition(action, outcome, extra, mode, localMessage, response) {
        var code = (response && response.code) || outcome;

        if (code === 'FACE_MATCHED' && response && response.data && response.data.employee) {
            submitAttendance(action, response.data);

            return;
        }

        var looks = {
            no_face: ['no_face', 'چهره‌ای شناسایی نشد', 'چهره‌ای شناسایی نشد. لطفاً روبه‌روی دوربین قرار بگیرید.'],
            multiple_faces: ['multiple_faces', 'چند چهره در کادر است', 'لطفاً فقط یک نفر مقابل دوربین قرار بگیرد.'],
            unknown_face: ['unknown_face', 'چهره ناشناس', 'چهره شما در سیستم ثبت نشده است. لطفاً با مسئول مجموعه تماس بگیرید.'],
            ambiguous: ['ambiguous', 'شناسایی با اطمینان کافی انجام نشد', 'شناسایی چهره با اطمینان کافی انجام نشد. لطفاً دوباره تلاش کنید.']
        };

        if (code === 'DEVICE_REVOKED' || code === 'DEVICE_INACTIVE' || code === 'CUSTOMER_DISABLED' || code === 'DEVICE_UNAUTHORIZED') {
            stopHeartbeat();
            wipeFaceData();
            closeCamera();
            showBlocked(code, response && response.message);

            return;
        }

        if (code === 'EMPLOYEE_INACTIVE') {
            showError('inactive', 'کارمند غیرفعال', (response && response.message) || 'این کارمند غیرفعال است. لطفاً با مسئول مجموعه تماس بگیرید.', 7000);
            closeCamera();

            return;
        }

        if (code === 'EMPLOYEE_FACE_INCOMPLETE') {
            showError('face_incomplete', 'ثبت چهره این کارمند کامل نیست', (response && response.message) || 'برای این کارمند سه نمونه چهره ثبت نشده است؛ ثبت حضور ممکن نیست.', 7000);
            closeCamera();

            return;
        }

        if (code === 'BRANCH_MISMATCH' || code === 'TENANT_MISMATCH') {
            showError('branch', 'شعبه نامعتبر', (response && response.message) || 'این کارمند به شعبه دیگری تعلق دارد.', 7000);
            closeCamera();

            return;
        }

        if (code === 'FEATURE_DISABLED') {
            showError('feature', 'سرویس حضور و غیاب غیرفعال است', (response && response.message) || 'این سرویس برای مجموعه شما فعال نیست.', 6000);
            closeCamera();

            return;
        }

        if (code === 'DUPLICATE_REQUEST') {
            /* a repeated attempt within the guard window: silently resume */
            state.faceBusy = false;
            state.faceStable = 0;
            setCameraState('guidance', 'چهره خود را داخل محدوده قرار دهید');

            return;
        }

        /* Local quality refusals: show the engine's own Persian message and
           keep the camera running so the person can simply adjust. */
        var engineApi = faceApi();
        var isQuality = engineApi && engineApi.CODES && engineApi.CODES[code];

        if (isQuality) {
            showError('quality', 'کیفیت تصویر برای شناسایی کافی نیست',
                (engineApi.messageFor(code) || localMessage || 'کیفیت تصویر کافی نیست.'), 4500);
            state.faceBusy = false;
            state.faceStable = 0;

            return;
        }

        var key = String(code).toLowerCase();
        var pair = looks[key] || ['unknown_face', 'چهره ناشناس', 'چهره شما در سیستم ثبت نشده است. لطفاً با مسئول مجموعه تماس بگیرید.'];
        var message = (response && response.message) || pair[2];

        if (mode === 'offline') {
            message += ' (حالت آفلاین)';
        }

        showError(pair[0], pair[1], message, 6000);

        if (code === 'NO_FACE' || code === 'MULTIPLE_FACES') {
            /* keep looking - it is usually a positioning problem */
            state.faceBusy = false;
            state.faceStable = 0;

            return;
        }

        closeCamera();
    }

    /* -----------------------------------------------------------------
     * Attendance submission (contract)
     * ----------------------------------------------------------------*/
    function submitAttendance(action, data) {
        var employee = (data && data.employee) || {};

        if (!employee.id) {
            showError('unknown', 'نتیجه نامعتبر', 'موتور تشخیص چهره نتیجه کاملی برنگرداند.', 5000);
            closeCamera();

            return;
        }

        // The server issued this one time proof for THIS device, customer,
        // employee and action; it is bound to the recognition that just
        // happened on the device and is consumed by the call below. A bare
        // employee id is worthless to the engine.
        if (!data.proof) {
            showError('proof', 'ثبت حضور انجام نشد',
                'اثر یک‌بارمصرف این شناسایی دریافت نشد. لطفاً دوباره تلاش کنید.', 6000);
            closeCamera();

            return;
        }

        api('/attendance', {
            body: {
                request_id: requestId(),
                action: action,
                proof: data.proof,
                recognition_request_id: data.recognition_request_id || state.lastRequestId,
                distance: data.distance,
                confidence: data.confidence
            }
        }).then(function (payload) {
            var now = new Date();
            var operation = action === 'entry' ? 'ورود' : 'خروج';
            var time = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

            closeCamera();

            if (payload.success) {
                showSuccess({
                    title: operation + ' با موفقیت ثبت شد',
                    message: payload.message || '',
                    employee: employee,
                    facts: [
                        { label: 'نوع عملیات', value: operation },
                        { label: 'ساعت', value: time },
                        { label: 'وضعیت', value: 'ثبت شد' }
                    ]
                });

                return;
            }

            if (payload.code === 'NETWORK_ERROR') {
                /* The recognition result is already on the device: store the
                   punch with its LOCAL recognition result (employee id,
                   distance, confidence, model version) so the next sync can
                   replay it. The one-shot server proof is deliberately NOT
                   queued: it expires within seconds and a stale proof would be
                   refused on reconnect, while a proof-less queued event is
                   re-validated strictly by the server (own customer, active
                   employee, complete 3/3 dataset, clamped event time). */
                var stored = queue.push({
                    local_event_id: localEventId(),
                    device_id: deviceIdFromCredential(),
                    event_type: action,
                    employee_id: employee.id,
                    local_created_at: nowIso(),
                    request_id: requestId(),
                    recognition: {
                        outcome: 'FACE_MATCHED',
                        distance: data.distance,
                        confidence: data.confidence,
                        sample_index: data.sample_index,
                        model_version: (state.faceConfig && state.faceConfig.model_version) || ''
                    }
                });

                setOnline(false);

                if (stored) {
                    text(el.queueCount, 'در صف ارسال: ' + queue.count());
                }

                showNotice('ثبت آفلاین',
                    'ارتباط با سرور قطع شد؛ این ' + operation + ' در صف ذخیره شد و در اولین هم‌گام‌سازی ثبت می‌شود.',
                    6000);

                return;
            }

            if (payload.code === 'ENTRY_ALREADY_OPEN') {
                showError('already_open', 'ورود قبلاً ثبت شده است',
                    payload.message || 'ورود شما قبلاً ثبت شده است.', 6000);

                return;
            }

            if (payload.code === 'EXIT_ALREADY_RECORDED') {
                showError('already_closed', 'خروج قبلاً ثبت شده است',
                    payload.message || 'خروج شما قبلاً ثبت شده است.', 6000);

                return;
            }

            if (payload.code === 'NO_OPEN_ENTRY') {
                showError('no_open', 'ورود فعالی یافت نشد',
                    payload.message || 'برای شما ورود فعالی ثبت نشده است.', 6000);

                return;
            }

            if (payload.code === 'DUPLICATE_REQUEST') {
                /* the same request reached the server twice: the first answer
                   already did the job, so this is not an error */
                showSuccess({
                    title: operation + ' قبلاً ثبت شده بود',
                    message: payload.message || '',
                    employee: employee,
                    facts: [
                        { label: 'نوع عملیات', value: operation },
                        { label: 'ساعت', value: time },
                        { label: 'وضعیت', value: 'تکراری' }
                    ]
                });

                return;
            }

            if (payload.code === 'RATE_LIMITED') {
                showError('rate', 'تعداد درخواست‌ها زیاد است',
                    payload.message || 'لطفاً چند لحظه بعد دوباره تلاش کنید.', 6000);

                return;
            }

            /* Every other engine refusal (INVALID_RECOGNITION, ATTENDANCE_REJECTED,
               EMPLOYEE_INACTIVE, BRANCH_MISMATCH, OFFLINE_EVENT_INVALID, ...) is
               shown with the server's own Persian message - never invented. */
            showError('attendance', 'ثبت حضور انجام نشد',
                payload.message || 'درخواست پذیرفته نشد.', 6000);
        });
    }

    /* -----------------------------------------------------------------
     * Settings drawer (non-sensitive only)
     * ----------------------------------------------------------------*/
    function openDrawer() {
        el.scrim && el.scrim.classList.add('is-open');
        el.drawer && el.drawer.classList.add('is-open');
        updateInfoPanel();
    }

    function closeDrawer() {
        el.scrim && el.scrim.classList.remove('is-open');
        el.drawer && el.drawer.classList.remove('is-open');

        if (state.cameraTestStream) {
            stopStream(state.cameraTestStream);
            state.cameraTestStream = null;
            text(el.cameraTestResult, '');
        }
    }

    function saveSetting(key, value) {
        if (!state.token) {
            return;
        }

        var body = {};
        body[key] = typeof value === 'boolean' ? (value ? '1' : '0') : String(value);

        api('/settings', { body: { settings: body } }).then(function (payload) {
            if (payload.success) {
                applySettings(payload.data.settings);
            }
        });
    }

    function runCameraTest() {
        text(el.cameraTestResult, 'در حال آزمایش دوربین…');

        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: { facingMode: state.cameraFacing }, audio: false })
                .then(function (stream) {
                    state.cameraTestStream = stream;

                    if (el.cameraTestVideo) {
                        el.cameraTestVideo.srcObject = stream;
                        el.cameraTestVideo.play().catch(function () { /* ignore */ });
                    }

                    text(el.cameraTestResult, 'دوربین سالم است — تصویر زنده در حال پخش است.');
                })
                .catch(function () {
                    text(el.cameraTestResult, 'دسترسی به دوربین ممکن نشد. مجوز مرورگر را بررسی کنید.');
                });

            return;
        }

        text(el.cameraTestResult, 'این مرورگر از دوربین پشتیبانی نمی‌کند.');
    }

    function forgetDevice() {
        var sure = window.confirm('اعتبار محلی این دستگاه حذف شود؟ برای استفاده مجدد باید مالک یا مدیر، کد فعال‌سازی جدید بسازد.');

        if (!sure) {
            return;
        }

        api('/forget', { body: {} }).then(function () {
            credentials.clear();
            wipeFaceData();
            stopHeartbeat();
            closeDrawer();
            showActivation('اعتبار این دستگاه حذف شد. کد فعال‌سازی جدید را وارد کنید.', 'forget');
        });
    }

    /* -----------------------------------------------------------------
     * Kiosk hardening
     * ----------------------------------------------------------------*/
    function requestFullscreen() {
        var element = document.documentElement;

        if (!document.fullscreenElement && element.requestFullscreen) {
            element.requestFullscreen().catch(function () { /* user gesture may be required */ });
        }
    }

    function requestWakeLock() {
        if (!('wakeLock' in navigator) || state.wakeLock) {
            return;
        }

        navigator.wakeLock.request('screen').then(function (lock) {
            state.wakeLock = lock;
            lock.addEventListener && lock.addEventListener('release', function () {
                state.wakeLock = null;
            });
        }).catch(function () { /* not supported / not allowed */ });
    }

    function armFirstGesture() {
        var handler = function () {
            requestFullscreen();
            requestWakeLock();
            document.removeEventListener('pointerdown', handler);
        };

        document.addEventListener('pointerdown', handler, { once: true });
    }

    function armIdleCursor() {
        var reset = function () {
            root.classList.remove('idle-hidden-cursor');
            window.clearTimeout(state.idleTimer);
            state.idleTimer = window.setTimeout(function () {
                root.classList.add('idle-hidden-cursor');
            }, 25000);
        };

        document.addEventListener('pointermove', reset);
        document.addEventListener('touchstart', reset);
        reset();
    }

    function armSettingsGesture() {
        var pressTimer = null;
        var taps = [];
        var logo = el.logoFallback || el.logo;

        if (logo) {
            logo.addEventListener('pointerdown', function () {
                window.clearTimeout(pressTimer);
                pressTimer = window.setTimeout(openDrawer, 1500);
            });

            ['pointerup', 'pointerleave'].forEach(function (event) {
                logo.addEventListener(event, function () {
                    window.clearTimeout(pressTimer);
                });
            });
        }

        // Five quick taps on the header also open the drawer (hardware-less
        // kiosks). It only opens the NON-SENSITIVE drawer; everything security
        // relevant stays behind the owner panel.
        document.addEventListener('pointerdown', function (event) {
            var target = event.target;

            if (!target || !target.closest || !target.closest('.k-top')) {
                return;
            }

            var stamp = Date.now();
            taps = taps.filter(function (value) {
                return stamp - value < 2500;
            });
            taps.push(stamp);

            if (taps.length >= 5) {
                taps = [];
                openDrawer();
            }
        });
    }

    function armInstallPrompt() {
        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            state.installEvent = event;

            if (el.install) {
                el.install.hidden = false;
            }
        });

        window.addEventListener('appinstalled', function () {
            state.installEvent = null;

            if (el.install) {
                el.install.hidden = true;
            }
        });
    }

    function armServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        navigator.serviceWorker.register(SETUP.base + SETUP.sw, { scope: SETUP.base }).catch(function () {
            /* offline shell simply stays unavailable */
        });
    }

    /* -----------------------------------------------------------------
     * Wiring
     * ----------------------------------------------------------------*/
    function bind() {
        [el.entryBtn, el.exitBtn].forEach(function (button) {
            if (!button) {
                return;
            }

            button.addEventListener('click', function () {
                var action = button.getAttribute('data-action') || 'entry';

                if (state.busy) {
                    return;
                }

                // Client side double-tap guard: the backend is still the
                // authority (request_id + UNIQUE(device_id, request_key)).
                button.classList.add('is-pressed');
                window.setTimeout(function () {
                    button.classList.remove('is-pressed');
                }, 160);

                openCamera(action);
            });
        });

        if (el.cameraCancel) {
            el.cameraCancel.addEventListener('click', function () {
                state.busy = false;
                closeCamera();
                showHome();
            });
        }

        if (el.cameraRetry) {
            el.cameraRetry.addEventListener('click', function () {
                state.busy = false;
                state.faceBusy = false;
                state.faceStable = 0;
                setCameraState('guidance', HINTS.guidance);
                startFaceLoop();
            });
        }

        if (el.errorClose) {
            el.errorClose.addEventListener('click', function () {
                closeCamera();
                showHome();
            });
        }

        if (el.success) {
            el.success.addEventListener('click', function () {
                showHome();
            });
        }

        if (el.blockedRetry) {
            el.blockedRetry.addEventListener('click', function () {
                hideBoot();
                only(null);
                loadConfig();
            });
        }

        if (el.codeSubmit) {
            el.codeSubmit.addEventListener('click', activate);
        }

        if (el.codeInput) {
            el.codeInput.addEventListener('input', function () {
                el.codeInput.value = normaliseCode(el.codeInput.value);
            });

            el.codeInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    activate();
                }
            });
        }

        document.querySelectorAll('[data-key]').forEach(function (key) {
            key.addEventListener('click', function () {
                if (!el.codeInput) {
                    return;
                }

                var value = key.getAttribute('data-key');

                if (value === 'clear') {
                    el.codeInput.value = '';
                } else if (value === 'back') {
                    var clean = el.codeInput.value.replace('-', '');
                    el.codeInput.value = normaliseCode(clean.slice(0, -1));
                } else {
                    el.codeInput.value = normaliseCode(el.codeInput.value.replace('-', '') + value);
                }
            });
        });

        if (el.drawerClose) {
            el.drawerClose.addEventListener('click', closeDrawer);
        }

        if (el.scrim) {
            el.scrim.addEventListener('click', closeDrawer);
        }

        if (el.settingsButton) {
            el.settingsButton.addEventListener('click', openDrawer);
        }

        if (el.setSound) {
            el.setSound.addEventListener('change', function () {
                saveSetting('success_sound', el.setSound.checked);
            });
        }

        if (el.setClock) {
            el.setClock.addEventListener('change', function () {
                saveSetting('show_clock', el.setClock.checked);
            });
        }

        if (el.setOfflineBanner) {
            el.setOfflineBanner.addEventListener('change', function () {
                saveSetting('offline_banner', el.setOfflineBanner.checked);
            });
        }

        if (el.setSeconds) {
            el.setSeconds.addEventListener('change', function () {
                saveSetting('success_display_seconds', el.setSeconds.value);
            });
        }

        if (el.setCameraFacing) {
            el.setCameraFacing.addEventListener('change', function () {
                state.cameraFacing = el.setCameraFacing.value;
                saveSetting('camera_facing', el.setCameraFacing.value);
            });
        }

        if (el.cameraTest) {
            el.cameraTest.addEventListener('click', runCameraTest);
        }

        if (el.forget) {
            el.forget.addEventListener('click', forgetDevice);
        }

        if (el.install) {
            el.install.addEventListener('click', function () {
                if (!state.installEvent) {
                    return;
                }

                state.installEvent.prompt();
                state.installEvent = null;
                el.install.hidden = true;
            });
        }

        document.addEventListener('contextmenu', function (event) {
            event.preventDefault();
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && state.status === 'active') {
                pushHeartbeat();
                requestWakeLock();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (el.drawer && el.drawer.classList.contains('is-open')) {
                    closeDrawer();
                } else if (root.getAttribute('data-state') && root.getAttribute('data-state').indexOf('camera') === 0) {
                    state.busy = false;
                    closeCamera();
                    showHome();
                }
            }
        });
    }

    /* -----------------------------------------------------------------
     * Boot
     * ----------------------------------------------------------------*/
    function bootOfflineShell() {
        // The cached /device/offline page: only connection status + retry.
        var status = document.getElementById('k-offline-status');
        var retry = document.getElementById('k-offline-retry');
        var apply = function () {
            if (status) {
                status.textContent = navigator.onLine === false ? 'آفلاین' : 'در حال بررسی…';
            }
        };

        apply();
        window.addEventListener('online', apply);
        window.addEventListener('offline', apply);

        if (retry) {
            retry.addEventListener('click', function () {
                window.location.href = SETUP.base + 'device';
            });
        }
    }

    function boot() {
        if (root.getAttribute('data-offline-shell') === '1') {
            bootOfflineShell();

            return;
        }

        setOnline(navigator.onLine !== false);
        startClock();
        armFirstGesture();
        armIdleCursor();
        armSettingsGesture();
        armInstallPrompt();
        armServiceWorker();
        bind();
        renderQueue();

        var stored = credentials.load();

        if (!stored) {
            showActivation('', 'none');

            return;
        }

        state.token = stored.token;
        loadConfig();
    }

    boot();

    // Exposed for the automated UI tests (tests/e2e_stage4.sh): they assert the
    // state machine and the honest "engine not connected" behaviour without a
    // real browser.
    window.SARMAD_KIOSK_STATE = state;
    window.SARMAD_KIOSK_HELPERS = {
        normaliseCode: normaliseCode,
        jalaliParts: jalaliParts,
        queue: queue,
        api: api
    };
})();
