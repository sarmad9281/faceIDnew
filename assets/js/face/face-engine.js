/* =====================================================================
   SARMAD Attendance - LOCAL face recognition engine (stage 5)
   ---------------------------------------------------------------------
   What this file REALLY does (and what it does not):

     + loads a real, shipped face recognition model in the browser
       (face-api.js 0.22.2 on TensorFlow.js 1.7.4 - the pairing the model
       library is built against; weights inside this project, no CDN,
       no server side processing),
     + detects faces and produces a 128 dimension descriptor (embedding),
     + applies real quality gates before a sample is accepted
       (exactly one face, size, centering, sharpness, brightness, yaw),
     + matches a descriptor against the gallery of the device's own customer
       using euclidean distance + an ambiguity margin.

     - Liveness / anti-spoofing: NOT implemented. There is no model for it
       and no code path for it - by project decision.
     - Nothing here talks to a server: the caller decides what to send
       (only descriptors + the outcome of the match, never a camera frame).

   Everything tunable is handed in from the server (config/config.php ->
   /api/v1/device/config or the enrolment page), so no threshold is hard
   coded in more than one place.
   ===================================================================== */

(function (window, document) {
    'use strict';

    var VERSION = '1.0.0';

    /* Codes - the same vocabulary the API and the UI use. */
    var CODES = {
        OK: 'OK',
        NO_FACE: 'NO_FACE',
        MULTIPLE_FACES: 'MULTIPLE_FACES',
        UNKNOWN_FACE: 'UNKNOWN_FACE',
        AMBIGUOUS: 'FACE_MATCH_AMBIGUOUS',
        FACE_TOO_SMALL: 'FACE_TOO_SMALL',
        FACE_OFF_CENTER: 'FACE_OFF_CENTER',
        FACE_BLURRY: 'FACE_BLURRY',
        FACE_TOO_DARK: 'FACE_TOO_DARK',
        FACE_TOO_BRIGHT: 'FACE_TOO_BRIGHT',
        FACE_YAW_TOO_LARGE: 'FACE_YAW_TOO_LARGE',
        FACE_TOO_CLOSE: 'FACE_TOO_CLOSE',
        MODEL_LOAD_FAILED: 'FACE_MODEL_LOAD_FAILED',
        ENGINE_BUSY: 'FACE_ENGINE_BUSY_OR_UNSUPPORTED',
        CAMERA_PERMISSION_DENIED: 'CAMERA_PERMISSION_DENIED',
        CAMERA_NOT_FOUND: 'CAMERA_NOT_FOUND',
        CAMERA_IN_USE: 'CAMERA_IN_USE',
        CAMERA_UNKNOWN_ERROR: 'CAMERA_ERROR'
    };

    var MESSAGES = {
        NO_FACE: 'چهره‌ای شناسایی نشد. لطفاً روبه‌روی دوربین قرار بگیرید.',
        MULTIPLE_FACES: 'لطفاً فقط یک نفر مقابل دوربین قرار بگیرد.',
        UNKNOWN_FACE: 'چهره شما در سیستم ثبت نشده است. لطفاً با مسئول مجموعه تماس بگیرید.',
        AMBIGUOUS: 'شناسایی چهره با اطمینان کافی انجام نشد. لطفاً دوباره تلاش کنید.',
        FACE_TOO_SMALL: 'چهره شما کوچک‌تر از حد لازم است؛ کمی به دوربین نزدیک‌تر شوید.',
        FACE_OFF_CENTER: 'چهره شما در محدوده مجاز نیست؛ لطفاً داخل کادر قرار بگیرید.',
        FACE_BLURRY: 'تصویر واضح نیست؛ لطفاً ثابت بمانید و نور کافی داشته باشید.',
        FACE_TOO_DARK: 'نور محیط کم است؛ لطفاً رو به نور بایستید.',
        FACE_TOO_BRIGHT: 'نور زیاد است؛ لطفاً کمی از نور فاصله بگیرید.',
        FACE_YAW_TOO_LARGE: 'لطفاً مستقیم به دوربین نگاه کنید.',
        FACE_TOO_CLOSE: 'چهره شما بیش از حد به دوربین نزدیک است؛ کمی فاصله بگیرید.',
        MODEL_LOAD_FAILED: 'بارگذاری مدل تشخیص چهره ناموفق بود.',
        ENGINE_BUSY: 'موتور تشخیص چهره در این مرورگر قابل اجرا نیست.',
        CAMERA_PERMISSION_DENIED: 'دسترسی دوربین فعال نیست. لطفاً اجازه استفاده از دوربین را فعال کنید.',
        CAMERA_NOT_FOUND: 'دوربین دستگاه در دسترس نیست.',
        CAMERA_IN_USE: 'دوربین در حال استفاده توسط برنامه دیگری است. لطفاً آن را ببندید و دوباره تلاش کنید.',
        CAMERA_UNKNOWN_ERROR: 'راه‌اندازی دوربین ناموفق بود.'
    };

    /* Model + quality defaults. The server always overrides these with the
       values of config/config.php before anything runs. */
    var DEFAULTS = {
        model_path: 'assets/models/face-api-1.0.0',
        model_version: 'unknown',
        detector: 'tiny_face_detector',
        force_backend: '',
        metric: 'euclidean',
        dimensions: 128,
        samples_required: 3,
        match_threshold: 0.55,
        ambiguity_margin: 0.06,
        enrollment_consistency: 0.65,
        detect_interval_ms: 350,
        detector_input_size: 320,
        recognition_input_size: 416,
        min_face_ratio: 0.16,
        min_face_pixels: 80,
        max_center_offset: 0.34,
        blur_threshold: 45.0,
        min_brightness: 35.0,
        max_brightness: 225.0,
        max_yaw_ratio: 0.34,
        max_face_ratio: 0.92,
        liveness: 'not_used'
    };

    var config = shallow(DEFAULTS);
    var state = {
        loaded: false,
        loading: null,
        backend: '',
        detectorKind: '',
        lastError: '',
        detectedOn: ''
    };

    function shallow(source) {
        var out = {};

        for (var key in source) {
            if (Object.prototype.hasOwnProperty.call(source, key)) {
                out[key] = source[key];
            }
        }

        return out;
    }

    function messageFor(code) {
        return MESSAGES[code] || '';
    }

    function result(ok, code, extra) {
        var payload = extra || {};
        payload.ok = ok;
        payload.code = code;
        payload.message = payload.message || messageFor(code);

        return payload;
    }

    /**
     * How long a single engine operation may take before it is treated as a
     * hung backend. A tablet with a broken/software WebGL driver can wedge a
     * shader compile and never settle the promise; without this guard the
     * kiosk would sit on "recognising" forever.
     */
    var OP_TIMEOUT_MS = 8000;
    var cpuFallbackUsed = false;
    var webglRetried = false;

    function withTimeout(promise, label) {
        return new Promise(function (resolve, reject) {
            var settled = false;
            var timer = window.setTimeout(function () {
                if (!settled) {
                    settled = true;
                    reject(new Error('engine_timeout:' + label));
                }
            }, OP_TIMEOUT_MS);

            promise.then(function (value) {
                if (!settled) {
                    settled = true;
                    window.clearTimeout(timer);
                    resolve(value);
                }
            }, function (error) {
                if (!settled) {
                    settled = true;
                    window.clearTimeout(timer);
                    reject(error);
                }
            });
        });
    }

    /**
     * Run an engine operation; if the WebGL backend fails or hangs, fall back
     * to the CPU backend ONCE (models reloaded on the new backend) and repeat.
     * This is a real field requirement: cheap tablets ship with WebGL drivers
     * that fail on some tensor shapes, and recognition must still work.
     */
    function withBackendFallback(run, label) {
        return withTimeout(run(), label)['catch'](function (firstError) {
            if (!window.tf || !window.tf.setBackend) {
                throw firstError;
            }

            /* A wedged WebGL program sometimes recovers on a plain second
               attempt; only then pay the (expensive) CPU switch. */
            if (!webglRetried) {
                webglRetried = true;

                return new Promise(function (resolve) {
                    window.setTimeout(resolve, 250);
                }).then(function () {
                    return withTimeout(run(), label + ':retry');
                });
            }

            if (cpuFallbackUsed) {
                throw firstError;
            }

            cpuFallbackUsed = true;

            return window.tf.setBackend('cpu').then(function () {
                return window.tf.ready ? window.tf.ready() : true;
            }).then(function () {
                // the nets must be (re)bound to the CPU backend
                state.loaded = false;
                state.loading = null;

                return loadModels();
            }).then(function () {
                return withTimeout(run(), label + ':cpu');
            });
        });
    }

    function hasTf() {
        return typeof window.tf !== 'undefined' && typeof window.faceapi !== 'undefined';
    }

    /* -----------------------------------------------------------------
     * Configuration
     * ----------------------------------------------------------------*/

    function configure(values) {
        if (!values || typeof values !== 'object') {
            return config;
        }

        for (var key in values) {
            if (!Object.prototype.hasOwnProperty.call(values, key)) {
                continue;
            }

            var value = values[key];

            if (value === null || typeof value === 'undefined') {
                continue;
            }

            if (typeof DEFAULTS[key] === 'number') {
                value = parseFloat(value);

                if (isNaN(value)) {
                    continue;
                }
            }

            config[key] = value;
        }

        return config;
    }

    function settings() {
        return shallow(config);
    }

    /* -----------------------------------------------------------------
     * Model loading (once per page, kept in memory)
     * ----------------------------------------------------------------*/

    /**
     * @param {function(string,number)=} onProgress
     * @returns {Promise<{ok:boolean,code:string,message:string,backend:string,detector:string,model_version:string}>}
     */
    function loadModels(onProgress) {
        if (state.loaded) {
            return Promise.resolve(loadReport());
        }

        if (state.loading) {
            return state.loading;
        }

        var progress = onProgress || function () {};

        state.loading = new Promise(function (resolve) {
            if (!hasTf()) {
                state.lastError = 'libraries_missing';
                resolve(result(false, CODES.MODEL_LOAD_FAILED, { backend: '', detector: '', model_version: config.model_version }));
                state.loading = null;

                return;
            }

            var api = window.faceapi;

            // Prefer WebGL (real GPU acceleration on tablets/phones), fall
            // back to the pure JS CPU backend so recognition still works on
            // devices without WebGL.
            var backend = 'cpu';

            try {
                /* Test/lab override: `window.SARMAD_FACE_BACKEND = 'cpu'` (or
                   face.force_backend in the config) is used by the automated
                   browser tests on machines whose only "GPU" is a software
                   rasteriser, and by devices with a known broken driver. */
                var forced = config.force_backend || window.SARMAD_FACE_BACKEND || '';

                if (forced && window.tf.setBackend) {
                    window.tf.setBackend(forced);
                }

                if (window.tf.getBackend) {
                    var current = window.tf.getBackend();

                    if (current) {
                        backend = current;
                    } else if (window.tf.setBackend('webgl')) {
                        backend = 'webgl';
                    }
                }

                if (window.tf.ready) {
                    window.tf.ready = function () { return Promise.resolve(); };
                }
            } catch (error) {
                backend = 'cpu';
            }

            var path = config.model_path;
            var detectorKind = config.detector === 'ssd' || config.detector === 'ssd_mobilenetv1'
                ? 'ssd_mobilenetv1'
                : 'tiny_face_detector';

            var promises = [
                api.nets.faceLandmark68Net.loadFromUri(path),
                api.nets.faceRecognitionNet.loadFromUri(path)
            ];

            if (detectorKind === 'ssd_mobilenetv1') {
                promises.push(api.nets.ssdMobilenetv1.loadFromUri(path));
            } else {
                promises.push(api.nets.tinyFaceDetector.loadFromUri(path));
            }

            progress('models', 0);

            Promise.all(promises).then(function () {
                state.loaded = true;
                state.backend = backend;
                state.detectorKind = detectorKind;
                state.lastError = '';
                state.loading = null;

                progress('models', 1);

                resolve(loadReport());
            })['catch'](function (error) {
                state.loaded = false;
                state.lastError = String(error && error.message ? error.message : error);
                state.loading = null;

                resolve(result(false, CODES.MODEL_LOAD_FAILED, {
                    backend: backend,
                    detector: detectorKind,
                    model_version: config.model_version,
                    detail: state.lastError
                }));
            });
        });

        return state.loading;
    }

    function loadReport() {
        return result(true, CODES.OK, {
            backend: state.backend,
            detector: state.detectorKind,
            model_version: config.model_version,
            dimensions: config.dimensions,
            metric: config.metric
        });
    }

    /**
     * Public engine status - used by the kiosk to show something honest about
     * what is (and is not) running.
     */
    function status() {
        return {
            engine: 'face-api.js',
            engine_version: config.model_version,
            version: VERSION,
            loaded: state.loaded,
            backend: state.backend,
            detector: state.detectorKind,
            dimensions: config.dimensions,
            metric: config.metric,
            match_threshold: config.match_threshold,
            ambiguity_margin: config.ambiguity_margin,
            liveness: 'not_used',
            error: state.lastError
        };
    }

    function isReady() {
        return state.loaded;
    }

    /**
     * The tiny face detector accepts a FIXED set of input sizes; anything else
     * makes the library throw ("TinyYolov2Options - expected inputSize ...").
     * Every size used in this engine goes through here first.
     */
    var TINY_INPUT_SIZES = [128, 160, 224, 320, 416, 512, 608];

    function nearestInputSize(value) {
        var size = parseInt(value, 10) || 320;
        var best = TINY_INPUT_SIZES[0];

        for (var i = 0; i < TINY_INPUT_SIZES.length; i++) {
            if (Math.abs(TINY_INPUT_SIZES[i] - size) < Math.abs(best - size)) {
                best = TINY_INPUT_SIZES[i];
            }
        }

        return best;
    }

    /** The next bigger supported size (the retry step), capped at 608. */
    function nextInputSize(value) {
        var current = nearestInputSize(value);

        for (var i = 0; i < TINY_INPUT_SIZES.length; i++) {
            if (TINY_INPUT_SIZES[i] > current) {
                return TINY_INPUT_SIZES[i];
            }
        }

        return current;
    }

    /**
     * Input sizes to try, in order, for one detection pass.
     *
     * The tiny detector is scale sensitive: a face that fills the frame is
     * found at 320 but missed at 416, and a small face is the other way round.
     * Trying the requested size first (keeps the common path fast) and then one
     * bigger plus the 320 fallback is what makes the kiosk reliable in the
     * field without a heavier model.
     */
    function sizeLadder(requested) {
        var first = nearestInputSize(requested || config.detector_input_size);
        var ladder = [first];
        var bigger = nextInputSize(first);

        if (bigger !== first) {
            ladder.push(bigger);
        }

        if (ladder.indexOf(320) === -1) {
            ladder.push(320);
        }

        return ladder;
    }

    function detectorOptions(inputSize) {
        var size = nearestInputSize(inputSize);

        if (state.detectorKind === 'ssd_mobilenetv1') {
            return new window.faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 });
        }

        return new window.faceapi.TinyFaceDetectorOptions({ inputSize: size, scoreThreshold: 0.5 });
    }

    /* -----------------------------------------------------------------
     * Frame helpers
     * ----------------------------------------------------------------*/

    var scratch = null;

    /**
     * Draw a video/image into a smaller canvas before detection: the model
     * resizes internally anyway, so feeding it a 480px wide frame instead of
     * a 1280px one saves a lot of CPU on a cheap tablet.
     *
     * @returns {HTMLCanvasElement}
     */
    function frameCanvas(input, maxWidth) {
        var width = input.videoWidth || input.naturalWidth || input.width || 0;
        var height = input.videoHeight || input.naturalHeight || input.height || 0;

        if (!width || !height) {
            return null;
        }

        var limit = maxWidth || 480;
        var scale = width > limit ? limit / width : 1;
        var targetW = Math.max(1, Math.round(width * scale));
        var targetH = Math.max(1, Math.round(height * scale));

        if (!scratch) {
            scratch = document.createElement('canvas');
        }

        scratch.width = targetW;
        scratch.height = targetH;

        var ctx = scratch.getContext('2d');
        ctx.drawImage(input, 0, 0, targetW, targetH);

        return scratch;
    }

    /**
     * When a video/image was drawn into a smaller canvas, coordinates coming
     * back from the model live in the SMALL canvas. This factor lets a caller
     * map them back onto the original element.
     *
     * @returns {number} canvasWidth / originalWidth (1 when nothing was scaled)
     */
    function boxScaleFactor(input, canvas) {
        var width = input && (input.videoWidth || input.naturalWidth || input.width) || 0;

        if (!width || !canvas || !canvas.width) {
            return 1;
        }

        return canvas.width / width;
    }

    /**
     * Detect faces WITHOUT landmarks (the cheap loop used while searching).
     *
     * @returns {Promise<Array<{box:{x:number,y:number,width:number,height:number},score:number}>>}
     */
    function detectFaces(input, options) {
        if (!state.loaded) {
            return Promise.reject(result(false, CODES.MODEL_LOAD_FAILED, { message: MESSAGES.MODEL_LOAD_FAILED }));
        }

        var canvas = input instanceof HTMLCanvasElement ? input : frameCanvas(input, 480);

        if (!canvas) {
            return Promise.reject(result(false, CODES.NO_FACE));
        }

        var opts = options || {};
        var size = opts.inputSize || config.detector_input_size;

        var ladder = sizeLadder(size);
        var attempt = 0;

        var step = function () {
            var current = ladder[Math.min(attempt, ladder.length - 1)];

            return withBackendFallback(function () {
                return window.faceapi.detectAllFaces(canvas, detectorOptions(current));
            }, 'detectAll').then(function (detections) {
                if (detections && detections.length > 0) {
                    return detections;
                }

                attempt++;

                if (attempt < ladder.length) {
                    return step();
                }

                return detections;
            });
        };

        return step().then(function (detections) {
            return detections.map(function (item) {
                var box = item.box || item;

                return {
                    score: typeof item.score === 'number' ? item.score : 0,
                    box: {
                        x: box.x,
                        y: box.y,
                        width: box.width,
                        height: box.height
                    },
                    // The canvas these coordinates belong to. A caller that
                    // wants to crop/measure MUST use this buffer, not a
                    // differently sized one.
                    canvas: canvas,
                    frame: {
                        width: canvas.width,
                        height: canvas.height
                    },
                    scale: boxScaleFactor(input, canvas)
                };
            });
        });
    }

    /**
     * Detect ONE face with landmarks + descriptor.
     * Used for enrolment samples and for the recognition snapshot.
     *
     * @returns {Promise<{box:object,score:number,landmarks:object,descriptor:Float32Array}>}
     */
    function detectSingleWithDescriptor(input, inputSize, guarded) {
        if (!state.loaded) {
            return Promise.reject(result(false, CODES.MODEL_LOAD_FAILED, { message: MESSAGES.MODEL_LOAD_FAILED }));
        }

        var canvas = input instanceof HTMLCanvasElement ? input : frameCanvas(input, 640);

        if (!canvas) {
            return Promise.reject(result(false, CODES.NO_FACE));
        }

        var size = inputSize || config.recognition_input_size;

        // The landmark + descriptor pipeline is a face-api promise CHAIN, so the
        // whole chain is what gets the backend fallback - not just its head.
        var run = function () {
            return window.faceapi
                .detectSingleFace(canvas, detectorOptions(size))
                .withFaceLandmarks()
                .withFaceDescriptor();
        };

        var pipeline = guarded ? withBackendFallback(run, 'detectSingle') : run();

        return pipeline.then(function (detection) {
            if (!detection) {
                return null;
            }

            var box = detection.detection.box || detection.detection;

            return {
                box: { x: box.x, y: box.y, width: box.width, height: box.height },
                score: detection.detection.score || 0,
                landmarks: detection.landmarks,
                descriptor: detection.descriptor,
                canvas: canvas,
                frame: { width: canvas.width, height: canvas.height },
                scale: boxScaleFactor(input, canvas)
            };
        });
    }

    /**
     * Detection with ONE retry at a larger input size.
     *
     * Why: the tiny detector answers at a fixed input resolution, so a face
     * that fills the frame (person standing right in front of the tablet) can
     * be missed while a smaller one is found. Trying the next supported size
     * before declaring NO_FACE is cheap insurance on a kiosk.
     */
    function detectAllWithRetry(canvas) {
        var ladder = sizeLadder(config.recognition_input_size || 416);
        var attempt = 0;

        var step = function () {
            var current = ladder[Math.min(attempt, ladder.length - 1)];

            return window.faceapi.detectAllFaces(canvas, detectorOptions(current)).then(function (detections) {
                if (detections && detections.length > 0) {
                    return detections;
                }

                attempt++;

                if (attempt < ladder.length) {
                    return step();
                }

                return detections;
            });
        };

        return step();
    }

    function detectSingleWithRetry(canvas) {
        var ladder = sizeLadder(config.recognition_input_size || 416);
        var attempt = 0;

        var step = function () {
            var current = ladder[Math.min(attempt, ladder.length - 1)];

            return detectSingleWithDescriptor(canvas, current, true).then(function (detection) {
                if (detection) {
                    return detection;
                }

                attempt++;

                if (attempt < ladder.length) {
                    return step();
                }

                return detection;
            });
        };

        return step();
    }

    /**
     * Variance of the Laplacian - the standard cheap "is this image sharp"
     * measure. A blurry face has a low response variance.
     */
    function varianceOfLaplacian(gray, width, height) {
        if (!gray || width < 3 || height < 3) {
            return 0;
        }

        var values = [];
        var sum = 0;

        for (var y = 1; y < height - 1; y++) {
            for (var x = 1; x < width - 1; x++) {
                var i = y * width + x;
                var lap = -gray[i - width - 1] - gray[i - width] - gray[i - width + 1]
                    - gray[i - 1] + 8 * gray[i] - gray[i + 1]
                    - gray[i + width - 1] - gray[i + width] - gray[i + width + 1];

                values.push(lap);
                sum += lap;
            }
        }

        if (values.length === 0) {
            return 0;
        }

        var mean = sum / values.length;
        var variance = 0;

        for (var k = 0; k < values.length; k++) {
            var d = values[k] - mean;
            variance += d * d;
        }

        return variance / values.length;
    }

    /**
     * One comparable 0..1 quality score for a sample. It is shown to the
     * manager and stored with the sample; it is NOT used for matching.
     */
    function qualityScore(m) {
        var sizePart = Math.max(0, Math.min(1, (m.face_ratio - config.min_face_ratio) / 0.34));
        var sharpPart = Math.max(0, Math.min(1, m.blur / (config.blur_threshold * 4)));
        var lightPart = 1 - Math.min(1, Math.abs(m.brightness - 135) / 120);
        var centerPart = 1 - Math.min(1, m.center_offset / Math.max(0.0001, config.max_center_offset));
        var yawPart = 1 - Math.min(1, m.yaw_ratio / Math.max(0.0001, config.max_yaw_ratio));

        var score = 0.24 * sizePart + 0.26 * sharpPart + 0.18 * lightPart + 0.18 * centerPart + 0.14 * yawPart;

        return Math.round(Math.max(0, Math.min(1, score)) * 1e6) / 1e6;
    }

    /**
     * The quality gate of one detected face: size, position, sharpness,
     * brightness and yaw. Every refusal carries the Persian message the
     * manager (or the person at the kiosk) has to see.
     */
    function measureQuality(detection) {
        var metrics = {
            face_ratio: 0,
            face_pixels: 0,
            center_offset: 1,
            blur: 0,
            brightness: 0,
            yaw_ratio: 0,
            score: 0
        };

        if (!detection || !detection.box || !detection.frame) {
            return { ok: false, code: CODES.NO_FACE, metrics: metrics };
        }

        var box = detection.box;
        var frame = detection.frame;

        var size = Math.min(box.width, box.height);
        metrics.face_pixels = Math.round(size);
        metrics.face_ratio = frame.width > 0 ? size / frame.width : 0;

        // How far the face centre is from the frame centre (0 centred, 1 edge).
        var cx = (box.x + box.width / 2) / frame.width;
        var cy = (box.y + box.height / 2) / frame.height;
        var dx = cx - 0.5;
        var dy = cy - 0.5;
        metrics.center_offset = Math.min(1, Math.sqrt((dx * dx + dy * dy) * 2));

        // Yaw estimate from the 68 landmarks: nose tip vs the eye midpoint,
        // normalised by the eye distance. A face looking away is unusable.
        if (detection.landmarks) {
            try {
                var left = detection.landmarks.getLeftEye();
                var right = detection.landmarks.getRightEye();
                var nose = detection.landmarks.getNose();

                var eyeMidX = (left[0].x + right[0].x) / 2;
                var eyeDist = Math.abs(right[0].x - left[0].x);
                var noseTip = nose[Math.floor(nose.length / 2)];

                if (eyeDist > 1) {
                    metrics.yaw_ratio = Math.abs(noseTip.x - eyeMidX) / eyeDist;
                }
            } catch (error) {
                metrics.yaw_ratio = 0;
            }
        }

        // Sharpness + brightness are measured on the face crop of the SAME
        // canvas the detector saw, so the numbers are comparable everywhere.
        // (Never read a differently sized buffer: the box would point at the
        // wrong pixels.)
        var canvas = detection.canvas || scratch;

        if (canvas && typeof canvas.getContext === 'function') {
            var ctx = canvas.getContext('2d');
            var sx = Math.max(0, Math.floor(box.x));
            var sy = Math.max(0, Math.floor(box.y));
            var sw = Math.max(1, Math.floor(box.width));
            var sh = Math.max(1, Math.floor(box.height));
            var gw = Math.min(sw, canvas.width - sx);
            var gh = Math.min(sh, canvas.height - sy);
            var crop = ctx.getImageData(sx, sy, gw, gh).data;

            var gray = [];
            var sum = 0;

            for (var i = 0; i < crop.length; i += 4) {
                var lum = 0.299 * crop[i] + 0.587 * crop[i + 1] + 0.114 * crop[i + 2];
                gray.push(lum);
                sum += lum;
            }

            if (gray.length > 0) {
                metrics.brightness = sum / gray.length;
                metrics.blur = varianceOfLaplacian(gray, gw, gh);
            }
        }

        metrics.score = qualityScore(metrics);

        var code = CODES.OK;

        if (metrics.face_pixels < config.min_face_pixels || metrics.face_ratio < config.min_face_ratio) {
            code = CODES.FACE_TOO_SMALL;
        } else if (metrics.center_offset > config.max_center_offset) {
            code = CODES.FACE_OFF_CENTER;
        } else if (metrics.blur < config.blur_threshold) {
            code = CODES.FACE_BLURRY;
        } else if (metrics.brightness < config.min_brightness) {
            code = CODES.FACE_TOO_DARK;
        } else if (metrics.brightness > config.max_brightness) {
            code = CODES.FACE_TOO_BRIGHT;
        } else if (metrics.yaw_ratio > config.max_yaw_ratio) {
            code = CODES.FACE_YAW_TOO_LARGE;
        } else if (metrics.face_ratio > config.max_face_ratio) {
            code = CODES.FACE_TOO_CLOSE;
        }

        return { ok: code === CODES.OK, code: code, metrics: metrics };
    }

    /**
     * One enrolment sample: exactly one face, gates passed, 128-d descriptor
     * plus a small snapshot for the manager's record.
     */
    function captureSample(input) {
        var canvas = input instanceof HTMLCanvasElement ? input : frameCanvas(input, 640);

        if (!canvas) {
            return Promise.resolve(result(false, CODES.NO_FACE));
        }

        return detectAllWithRetry(canvas).then(function (detections) {
            if (!detections || detections.length === 0) {
                return result(false, CODES.NO_FACE);
            }

            if (detections.length > 1) {
                return result(false, CODES.MULTIPLE_FACES, { faces: detections.length });
            }

            return detectSingleWithDescriptor(canvas, config.recognition_input_size, true).then(function (detection) {
                if (!detection) {
                    return result(false, CODES.NO_FACE);
                }

                var quality = measureQuality(detection);

                if (!quality.ok) {
                    return result(false, quality.code, {
                        quality: quality.metrics,
                        box: detection.box
                    });
                }

                return result(true, CODES.OK, {
                    embedding: descriptorToArray(detection.descriptor),
                    quality: quality.metrics,
                    box: detection.box,
                    snapshot: snapshotDataUrl(canvas, detection.box)
                });
            });
        });
    }

    /**
     * Quality gate + descriptor for an already detected single face - used by
     * the kiosk after the cheap detection loop found exactly one face.
     */
    function recognize(input) {
        var canvas = input instanceof HTMLCanvasElement ? input : frameCanvas(input, 640);

        if (!canvas) {
            return Promise.resolve(result(false, CODES.NO_FACE));
        }

        return detectSingleWithRetry(canvas).then(function (detection) {
            if (!detection) {
                return result(false, CODES.NO_FACE);
            }

            var quality = measureQuality(detection);
            var embedding = descriptorToArray(detection.descriptor);

            return result(true, CODES.OK, {
                embedding: embedding,
                quality: quality.metrics,
                box: detection.box,
                quality_ok: quality.ok,
                quality_code: quality.code,
                // Even a lower quality frame may still be matched (the user is
                // standing at a kiosk, not an enrolment desk) - the caller
                // decides with a retry budget.
                snapshot: snapshotDataUrl(canvas, detection.box)
            });
        });
    }

    function descriptorToArray(descriptor) {
        var out = [];

        for (var i = 0; i < descriptor.length; i++) {
            out.push(Math.round(descriptor[i] * 1e6) / 1e6);
        }

        return out;
    }

    /** Small JPEG (data URL) of the face crop - only used for enrolment. */
    function snapshotDataUrl(canvas, box) {
        try {
            var maxPx = 320;
            var side = Math.ceil(Math.max(box.width, box.height) * 1.35);
            var size = Math.min(maxPx, Math.max(64, side));
            var out = document.createElement('canvas');
            out.width = size;
            out.height = size;

            var ctx = out.getContext('2d');
            var cx = box.x + box.width / 2;
            var cy = box.y + box.height / 2;

            ctx.drawImage(canvas, cx - side / 2, cy - side / 2, side, side, 0, 0, size, size);

            return out.toDataURL('image/jpeg', 0.82);
        } catch (error) {
            return null;
        }
    }

    /* -----------------------------------------------------------------
     * Matching (euclidean distance on 128-d descriptors)
     * ----------------------------------------------------------------*/

    function euclidean(a, b) {
        var sum = 0;
        var n = Math.min(a.length, b.length);

        for (var i = 0; i < n; i++) {
            var d = a[i] - b[i];
            sum += d * d;
        }

        return Math.sqrt(sum);
    }

    function cosine(a, b) {
        var dot = 0;
        var na = 0;
        var nb = 0;
        var n = Math.min(a.length, b.length);

        for (var i = 0; i < n; i++) {
            dot += a[i] * b[i];
            na += a[i] * a[i];
            nb += b[i] * b[i];
        }

        if (na === 0 || nb === 0) {
            return 0;
        }

        return dot / (Math.sqrt(na) * Math.sqrt(nb));
    }

    /**
     * Compare one descriptor against the gallery (employees with 3 samples).
     *
     * @param {Array<number>} embedding
     * @param {Array<{employee_id:number,samples:Array<{sample_index:number,embedding:Array<number>}>}>} gallery
     * @returns {{decision:string,code:string,best:object|null,second:object|null,threshold:number,ambiguity_margin:number}}
     */
    function match(embedding, gallery) {
        var threshold = config.match_threshold;
        var margin = config.ambiguity_margin;
        var results = [];

        if (!embedding || !embedding.length || !gallery || !gallery.length) {
            return {
                decision: 'unknown',
                code: CODES.UNKNOWN_FACE,
                best: null,
                second: null,
                threshold: threshold,
                ambiguity_margin: margin
            };
        }

        for (var i = 0; i < gallery.length; i++) {
            var employee = gallery[i];
            var samples = employee.samples || [];
            var bestForEmployee = null;

            for (var s = 0; s < samples.length; s++) {
                var distance = euclidean(embedding, samples[s].embedding);

                if (bestForEmployee === null || distance < bestForEmployee.distance) {
                    bestForEmployee = {
                        employee_id: parseInt(employee.employee_id, 10),
                        sample_index: parseInt(samples[s].sample_index, 10),
                        distance: distance,
                        similarity: cosine(embedding, samples[s].embedding)
                    };
                }
            }

            if (bestForEmployee !== null) {
                results.push(bestForEmployee);
            }
        }

        results.sort(function (a, b) {
            return a.distance - b.distance;
        });

        var best = results.length > 0 ? results[0] : null;
        var second = null;

        // The runner up must be a DIFFERENT employee, otherwise two samples of
        // the same person would count as an ambiguity.
        for (var r = 1; r < results.length; r++) {
            if (results[r].employee_id !== best.employee_id) {
                second = results[r];
                break;
            }
        }

        if (best === null || best.distance > threshold) {
            return {
                decision: 'unknown',
                code: CODES.UNKNOWN_FACE,
                best: best,
                second: second,
                threshold: threshold,
                ambiguity_margin: margin
            };
        }

        if (second !== null && Math.abs(second.distance - best.distance) < margin) {
            return {
                decision: 'ambiguous',
                code: CODES.AMBIGUOUS,
                best: best,
                second: second,
                threshold: threshold,
                ambiguity_margin: margin
            };
        }

        return {
            decision: 'matched',
            code: 'FACE_MATCHED',
            best: best,
            second: second,
            threshold: threshold,
            ambiguity_margin: margin
        };
    }

    /* -----------------------------------------------------------------
     * Camera helpers (permission / availability errors are mapped to the
     * exact Persian messages the project mandates)
     * ----------------------------------------------------------------*/

    function cameraError(error) {
        var name = (error && error.name) || '';

        if (name === 'NotAllowedError' || name === 'SecurityError') {
            return result(false, CODES.CAMERA_PERMISSION_DENIED);
        }

        if (name === 'NotFoundError' || name === 'OverconstrainedError' || name === 'DevicesNotFoundError') {
            return result(false, CODES.CAMERA_NOT_FOUND);
        }

        if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
            return result(false, CODES.CAMERA_IN_USE);
        }

        return result(false, CODES.CAMERA_UNKNOWN_ERROR, { detail: name });
    }

    /**
     * @returns {Promise<{ok:boolean,code:string,message:string,stream?:MediaStream}>}
     */
    function openCamera(video, constraints) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.resolve(result(false, CODES.CAMERA_NOT_FOUND));
        }

        var options = constraints || {
            audio: false,
            video: {
                facingMode: 'user',
                width: { ideal: 640 },
                height: { ideal: 480 },
                frameRate: { ideal: 20, max: 30 }
            }
        };

        return navigator.mediaDevices.getUserMedia(options).then(function (stream) {
            if (video) {
                video.srcObject = stream;

                try {
                    video.setAttribute('playsinline', 'playsinline');
                    video.muted = true;
                } catch (error) {
                    /* older WebViews */
                }

                return video.play().then(function () {
                    return result(true, CODES.OK, { stream: stream });
                })['catch'](function () {
                    // Autoplay refusal is not fatal: the frame is still there.
                    return result(true, CODES.OK, { stream: stream });
                });
            }

            return result(true, CODES.OK, { stream: stream });
        })['catch'](function (error) {
            return cameraError(error);
        });
    }

    function closeCamera(stream) {
        if (!stream || !stream.getTracks) {
            return;
        }

        stream.getTracks().forEach(function (track) {
            try {
                track.stop();
            } catch (error) {
                /* ignore */
            }
        });
    }

    /** Free the model + scratch canvas (used when the kiosk leaves a flow). */
    function dispose() {
        scratch = null;

        if (window.tf && typeof window.tf.disposeVariables === 'function') {
            // The models have to stay loaded: reloading costs seconds on a
            // tablet. Only transient tensors are gone by design.
        }
    }

    window.SarmadFace = {
        CODES: CODES,
        DEFAULTS: DEFAULTS,
        configure: configure,
        settings: settings,
        status: status,
        backend: function () {
            return window.tf && window.tf.getBackend ? window.tf.getBackend() : '';
        },
        isReady: isReady,
        loadModels: loadModels,
        detectFaces: detectFaces,
        detectSingleWithDescriptor: detectSingleWithDescriptor,
        measureQuality: measureQuality,
        captureSample: captureSample,
        recognize: recognize,
        match: match,
        euclidean: euclidean,
        cosine: cosine,
        openCamera: openCamera,
        closeCamera: closeCamera,
        frameCanvas: frameCanvas,
        inputSizes: function () { return TINY_INPUT_SIZES.slice(); },
        messageFor: messageFor,
        dispose: dispose
    };
})(window, document);
