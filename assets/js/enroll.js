/* =====================================================================
   SARMAD Attendance - face enrolment wizard (stage 5)
   ---------------------------------------------------------------------
   Runs ONLY in the manager panel, on the manager's own browser:

     camera -> local detection -> local quality gates -> local 128-d descriptor
     -> POST the descriptor (+ one optional small snapshot) -> MySQL

   Rules kept here:
     * three samples, indices 1/2/3, nothing else - the database enforces it
       too (UNIQUE employee_id + sample_index, CHECK sample_index IN (1,2,3)),
     * a sample is saved ONLY when the engine's quality gates accept it; every
       refusal shows the engine's own Persian message,
     * the camera and the detection timer are released the moment the overlay
       closes (no leaked MediaStream, no background loop),
     * thresholds come from the server (`faceConfig` = config/config.php),
     * no embedding is ever rendered back into the page,
     * liveness detection is NOT implemented - by project decision.
   ===================================================================== */

(function () {
    'use strict';

    var root = document.getElementById('face-wizard');

    if (!root) {
        return;
    }

    var engine = window.SarmadFace || null;

    if (!engine) {
        return;
    }

    var config = {};

    try {
        config = JSON.parse(root.getAttribute('data-config') || '{}');
    } catch (error) {
        config = {};
    }

    engine.configure(config);

    var el = {
        status: document.getElementById('fw-status'),
        overlay: document.getElementById('fw-overlay'),
        title: document.getElementById('fw-title'),
        close: document.getElementById('fw-close'),
        video: document.getElementById('fw-video'),
        oval: document.getElementById('fw-oval'),
        box: document.getElementById('fw-box'),
        ovalLabel: document.getElementById('fw-oval-label'),
        state: document.getElementById('fw-state'),
        stateText: document.getElementById('fw-state-text'),
        flash: document.getElementById('fw-flash'),
        metrics: document.getElementById('fw-metrics'),
        mRatio: document.getElementById('fw-m-ratio'),
        mBlur: document.getElementById('fw-m-blur'),
        mBright: document.getElementById('fw-m-bright'),
        mCenter: document.getElementById('fw-m-center'),
        mYaw: document.getElementById('fw-m-yaw'),
        capture: document.getElementById('fw-capture'),
        note: document.getElementById('fw-note'),
        slots: Array.prototype.slice.call(document.querySelectorAll('[data-slot]'))
    };

    var required = parseInt(root.getAttribute('data-required') || '3', 10) || 3;
    var modelVersion = root.getAttribute('data-model') || '';
    var endpoint = root.getAttribute('data-endpoint') || '';
    var csrf = root.getAttribute('data-csrf') || '';

    var state = {
        index: 1,
        stream: null,
        timer: null,
        busy: false,
        detecting: false,
        stable: 0,
        lastCount: 0,
        ready: false,
        lastQuality: null,
        saved: {},
        modelLoading: null
    };

    try {
        (JSON.parse(root.getAttribute('data-samples') || '[]') || []).forEach(function (sample) {
            state.saved[parseInt(sample.sample_index, 10)] = sample;
        });
    } catch (error) {
        state.saved = {};
    }

    /* -----------------------------------------------------------------
     * Small helpers
     * ----------------------------------------------------------------*/
    function text(node, value) {
        if (node) {
            node.textContent = value;
        }
    }

    function setStatus(value) {
        text(el.status, value);
    }

    function setStateBox(name, message) {
        if (!el.state) {
            return;
        }

        el.state.setAttribute('data-state', name);
        text(el.stateText, message);
    }

    function showBox(node) {
        if (node) {
            node.hidden = false;
        }
    }

    function hideBox(node) {
        if (node) {
            node.hidden = true;
        }
    }

    function flag(node, on, className) {
        if (node) {
            node.classList.toggle(className, !!on);
        }
    }

    function refillCount() {
        return Object.keys(state.saved).length;
    }

    function refreshSlots() {
        el.slots.forEach(function (slot) {
            var index = parseInt(slot.getAttribute('data-slot'), 10);
            var sample = state.saved[index] || null;

            slot.classList.toggle('is-filled', sample !== null);

            var badge = slot.querySelector('.badge');

            if (badge) {
                badge.className = 'badge ' + (sample ? 'badge-ok' : 'badge-muted');
                text(badge, sample ? 'ثبت شده' : 'خالی');
            }

            var meta = slot.querySelector('.face-meta');

            if (meta) {
                var quality = meta.querySelector('[data-quality]');

                if (!quality) {
                    quality = document.createElement('span');
                    quality.setAttribute('data-quality', '1');
                    meta.insertBefore(quality, meta.firstChild);
                }

                text(quality, 'کیفیت: ' + (sample && sample.quality !== null && sample.quality !== undefined
                    ? Number(sample.quality).toFixed(2)
                    : '—'));
            }

            var button = slot.querySelector('[data-capture]');

            if (button) {
                button.textContent = sample ? 'ثبت مجدد این نمونه' : 'ثبت این نمونه';
                button.className = 'btn ' + (sample ? 'btn-ghost' : 'btn-primary') + ' btn-sm btn-block';
            }
        });

        var count = refillCount();

        setStatus(count + ' از ' + required + ' نمونه');

        return count;
    }

    /* -----------------------------------------------------------------
     * Model loading (once per page)
     * ----------------------------------------------------------------*/
    function ensureModel() {
        if (engine.isReady()) {
            state.ready = true;

            return Promise.resolve(true);
        }

        if (state.modelLoading) {
            return state.modelLoading;
        }

        setStateBox('loading', 'در حال بارگذاری موتور تشخیص چهره…');

        state.modelLoading = engine.loadModels().then(function (report) {
            state.ready = !!report.ok;
            state.modelLoading = null;

            if (!report.ok) {
                setStateBox('error', 'بارگذاری مدل تشخیص چهره ناموفق بود: ' + (report.detail || report.code || ''));

                return false;
            }

            return true;
        });

        return state.modelLoading;
    }

    /* -----------------------------------------------------------------
     * Camera + live guidance
     * ----------------------------------------------------------------*/
    function openCamera(index) {
        state.index = index;
        state.busy = false;
        state.stable = 0;
        text(el.title, 'ثبت نمونه ' + index);
        showBox(el.overlay);
        hideBox(el.metrics);
        el.capture.disabled = true;
        setStateBox('loading', 'در حال آماده‌سازی دوربین…');

        var promise = state.stream
            ? Promise.resolve(state.stream)
            : navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false
            });

        promise.then(function (stream) {
            state.stream = stream;

            if (el.video) {
                el.video.srcObject = stream;
                var playing = el.video.play();

                if (playing && playing.catch) {
                    playing.catch(function () { /* autoplay guard */ });
                }
            }

            return ensureModel();
        }).then(function (ok) {
            if (!ok) {
                return;
            }

            setStateBox('searching', 'چهره خود را داخل محدوده قرار دهید');
            startLoop();
        })['catch'](function (error) {
            var name = String(error && error.name ? error.name : error);

            if (name === 'NotAllowedError' || name === 'SecurityError') {
                setStateBox('error', 'دسترسی دوربین فعال نیست. لطفاً اجازه استفاده از دوربین را فعال کنید.');

                return;
            }

            if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                setStateBox('error', 'دوربین دستگاه در دسترس نیست.');

                return;
            }

            if (name === 'NotReadableError' || name === 'AbortError') {
                setStateBox('error', 'دوربین در اختیار برنامه دیگری است؛ لطفاً آن را ببندید و دوباره تلاش کنید.');

                return;
            }

            setStateBox('error', 'خطای دوربین: ' + name);
        });
    }

    function startLoop() {
        stopLoop();

        var interval = parseInt(config.detect_interval_ms, 10) || 350;

        state.timer = window.setInterval(function () {
            /* never overlap classifier runs; never paint while a capture or a
               save is in flight (the "locked" message must not overwrite the
               "saving" message) */
            if (state.detecting || state.busy || !state.stream || !el.video
                || el.video.readyState < 2 || !el.video.videoWidth) {
                return;
            }

            if (el.overlay.hidden) {
                return;
            }

            var size = config.detector_input_size;

            state.detecting = true;

            engine.detectFaces(el.video, { inputSize: size }).then(function (faces) {
                state.detecting = false;
                state.lastCount = faces.length;
                paint(faces);

                if (faces.length === 0) {
                    state.stable = 0;
                    setStateBox('searching', 'چهره‌ای شناسایی نشد. لطفاً روبه‌روی دوربین قرار بگیرید.');

                    return;
                }

                if (faces.length > 1) {
                    state.stable = 0;
                    setStateBox('multiple', 'لطفاً فقط یک نفر مقابل دوربین قرار بگیرد.');

                    return;
                }

                state.stable++;

                if (state.stable < 2) {
                    setStateBox('searching', 'چهره خود را داخل محدوده قرار دهید');

                    return;
                }

                setStateBox('locked', 'چهره در محدوده قرار گرفت — دکمه «ثبت نمونه» را بزنید');
                el.capture.disabled = false;
            })['catch'](function () { state.detecting = false; });
        }, interval);
    }

    function stopLoop() {
        if (state.timer) {
            window.clearInterval(state.timer);
            state.timer = null;
        }

        state.stable = 0;
    }

    /** Draw the detected face on top of the preview (UI only). */
    function paint(faces) {
        flag(el.oval, faces.length === 1 && state.stable >= 1, 'is-locked');
        flag(el.oval, faces.length > 1, 'is-multiple');
        flag(el.oval, faces.length === 1, 'is-found');

        if (faces.length !== 1 || !faces[0].box || !el.box) {
            return;
        }

        var frame = faces[0].frame || { width: el.video.videoWidth || 1, height: el.video.videoHeight || 1 };
        var box = faces[0].box;
        var left = (box.x / frame.width) * 100;
        var width = (box.width / frame.width) * 100;

        /* the preview is mirrored for the front camera */
        left = 100 - left - width;

        el.box.style.setProperty('--fw-face-left', left.toFixed(2) + '%');
        el.box.style.setProperty('--fw-face-width', width.toFixed(2) + '%');
        el.box.style.opacity = '1';
    }

    function closeOverlay() {
        stopLoop();
        hideBox(el.overlay);

        if (state.stream) {
            state.stream.getTracks().forEach(function (track) {
                try {
                    track.stop();
                } catch (error) { /* already stopped */ }
            });
        }

        state.stream = null;

        if (el.video) {
            el.video.srcObject = null;
        }

        /* free the WebGL buffers of the last capture as well */
        if (engine.dispose) {
            engine.dispose();
        }
    }

    /* -----------------------------------------------------------------
     * Capture + save
     * ----------------------------------------------------------------*/
    function capture() {
        if (state.busy || el.capture.disabled) {
            return;
        }

        state.busy = true;
        state.detecting = false;
        el.capture.disabled = true;
        setStateBox('capturing', 'در حال بررسی کیفیت و محاسبه بردار چهره…');

        /* On an economic tablet the descriptor can take a few seconds; say so
           instead of leaving the manager staring at a frozen button. */
        var slowHint = window.setTimeout(function () {
            if (state.busy) {
                setStateBox('capturing', 'پردازش چهره کمی زمان می‌برد… لطفاً دستگاه را ثابت نگه دارید.');
            }
        }, 6000);

        engine.captureSample(el.video).then(function (sample) {
            window.clearTimeout(slowHint);

            if (!sample.ok) {
                /* Nothing is saved on a refused sample. */
                state.busy = false;
                state.stable = 0;
                el.capture.disabled = false;
                setStateBox('refused', engine.messageFor(sample.code));

                return;
            }

            showMetrics(sample.quality);
            flash();
            save(sample);
        })['catch'](function (error) {
            window.clearTimeout(slowHint);
            state.busy = false;
            el.capture.disabled = false;

            var message = String(error && error.message ? error.message : error);

            if (message.indexOf('engine_timeout') === 0) {
                setStateBox('error', 'پردازش چهره روی این دستگاه پاسخ نداد. لطفاً صفحه را دوباره باز کنید و دوباره تلاش کنید.');

                return;
            }

            setStateBox('error', 'خطا در پردازش تصویر: ' + message);
        });
    }

    function showMetrics(quality) {
        if (!quality) {
            return;
        }

        showBox(el.metrics);
        text(el.mRatio, Number(quality.face_ratio).toFixed(3));
        text(el.mBlur, Number(quality.blur).toFixed(1));
        text(el.mBright, Number(quality.brightness).toFixed(1));
        text(el.mCenter, Number(quality.center_offset).toFixed(3));
        text(el.mYaw, Number(quality.yaw_ratio).toFixed(3));
        state.lastQuality = quality;
    }

    function flash() {
        if (!el.flash) {
            return;
        }

        el.flash.classList.add('is-on');
        window.setTimeout(function () {
            el.flash.classList.remove('is-on');
        }, 220);
    }

    function save(sample) {
        var payload = {
            _token: csrf,
            sample_index: state.index,
            embedding: sample.embedding,
            model_version: modelVersion,
            engine: config.engine || 'face-api.js',
            detector: config.detector || 'tiny_face_detector',
            metric: config.metric || 'euclidean',
            quality: null,
            request_id: 'enr-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8)
        };

        if (sample.quality && typeof sample.quality.score === 'number') {
            payload.quality = Math.max(0, Math.min(1, sample.quality.score));
        }

        /* One small snapshot per sample, only when the project is configured to
           keep it (max px from the same config), and it is NEVER used for
           matching - the descriptor is. */
        if (config.store_image && sample.snapshot) {
            payload.image = sample.snapshot;
        }

        setStateBox('saving', 'در حال ذخیره نمونه ' + state.index + '…');

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                return { status: response.status, body: body };
            })['catch'](function () {
                return { status: response.status, body: { success: false, message: 'پاسخ نامعتبر از سرور.' } };
            });
        }).then(function (result) {
            state.busy = false;

            if (!result.body || !result.body.success) {
                el.capture.disabled = false;
                setStateBox('refused', (result.body && result.body.message) || 'ذخیره نمونه انجام نشد.');

                return;
            }

            var data = result.body.data || {};

            state.saved[state.index] = {
                sample_index: state.index,
                quality: payload.quality,
                model_version: data.model_version || modelVersion
            };

            var count = refreshSlots();

            if (count >= required) {
                setStateBox('done', 'سه نمونه کامل شد. ثبت حضور با چهره برای این کارمند فعال است.');
            } else {
                setStateBox('done', 'نمونه ' + state.index + ' ذخیره شد. ' + count + ' از ' + required + ' نمونه ثبت شده است.');
            }

            window.setTimeout(function () {
                closeOverlay();

                if (count < required) {
                    var next = nextEmptyIndex();

                    if (next !== null) {
                        openCamera(next);
                    }
                } else {
                    window.location.reload();
                }
            }, 1200);
        })['catch'](function () {
            state.busy = false;
            el.capture.disabled = false;
            setStateBox('error', 'ارتباط با سرور برقرار نشد؛ نمونه ذخیره نشد.');
        });
    }

    function nextEmptyIndex() {
        for (var index = 1; index <= required; index++) {
            if (!state.saved[index]) {
                return index;
            }
        }

        return null;
    }

    /* -----------------------------------------------------------------
     * Wiring
     * ----------------------------------------------------------------*/
    document.querySelectorAll('[data-capture]').forEach(function (button) {
        button.addEventListener('click', function () {
            openCamera(parseInt(button.getAttribute('data-capture'), 10) || 1);
        });
    });

    if (el.capture) {
        el.capture.addEventListener('click', capture);
    }

    if (el.close) {
        el.close.addEventListener('click', function () {
            closeOverlay();
        });
    }

    document.addEventListener('visibilitychange', function () {
        /* A kiosk or a tab that goes to the background must not keep the camera
           running: on Android this is the difference between a warm and a hot
           tablet. */
        if (document.hidden && state.stream) {
            closeOverlay();
        }
    });

    window.addEventListener('pagehide', closeOverlay);

    refreshSlots();
    ensureModel();
})();
