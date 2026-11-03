/* =====================================================================
   SARMAD Attendance - public landing interactions
   ---------------------------------------------------------------------
   Progressive enhancement only: lightweight counters and, on desktop
   devices with a fine pointer, an rAF-throttled 3D tilt. Touch devices
   never register the tilt listener, and counters render their final
   value immediately there so scrolling stays free of needless work.
   The page remains complete and readable without JS.
   ===================================================================== */

(function () {
    'use strict';

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var coarsePointer = window.matchMedia('(hover: none), (pointer: coarse)').matches;
    var lightweightMotion = reducedMotion || coarsePointer;
    var persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function toPersianNumber(value, decimals) {
        return Number(value).toFixed(decimals).replace(/[0-9]/g, function (digit) {
            return persianDigits[Number(digit)];
        });
    }

    function animateCounter(counter) {
        var target = Number(counter.getAttribute('data-target') || '0');
        var decimals = Number(counter.getAttribute('data-decimals') || '0');

        if (!isFinite(target)) {
            return;
        }

        if (lightweightMotion) {
            counter.textContent = toPersianNumber(target, decimals);
            return;
        }

        var duration = 1300;
        var startedAt = null;

        function frame(timestamp) {
            if (startedAt === null) {
                startedAt = timestamp;
            }

            var progress = Math.min((timestamp - startedAt) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);

            counter.textContent = toPersianNumber(target * eased, decimals);

            if (progress < 1) {
                window.requestAnimationFrame(frame);
            } else {
                counter.textContent = toPersianNumber(target, decimals);
            }
        }

        window.requestAnimationFrame(frame);
    }

    function initCounters() {
        var counters = Array.prototype.slice.call(document.querySelectorAll('.counter'));

        if (!counters.length) {
            return;
        }

        if (!('IntersectionObserver' in window) || lightweightMotion) {
            counters.forEach(animateCounter);
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.35 });

        counters.forEach(function (counter) {
            observer.observe(counter);
        });
    }

    function initHologramTilt() {
        var visual = document.getElementById('hero-visual');
        var scene = document.getElementById('holo-scene');
        var finePointer = window.matchMedia('(hover: hover) and (pointer: fine) and (min-width: 1024px)').matches;

        // Touch-first devices get the static, flat CSS presentation. No
        // pointermove listener is attached for them at all.
        if (!visual || !scene || !finePointer || coarsePointer || reducedMotion) {
            return;
        }

        var frameId = 0;
        var pendingPoint = null;

        function applyTilt() {
            frameId = 0;

            if (!pendingPoint) {
                return;
            }

            var rect = visual.getBoundingClientRect();
            var point = pendingPoint;
            pendingPoint = null;

            if (!rect.width || !rect.height) {
                return;
            }

            var x = ((point.clientX - rect.left) / rect.width) - 0.5;
            var y = ((point.clientY - rect.top) / rect.height) - 0.5;

            scene.style.setProperty('--ry', (x * 11).toFixed(2) + 'deg');
            scene.style.setProperty('--rx', (-y * 9).toFixed(2) + 'deg');
        }

        visual.addEventListener('pointermove', function (event) {
            if (event.pointerType && event.pointerType !== 'mouse' && event.pointerType !== 'pen') {
                return;
            }

            pendingPoint = {
                clientX: event.clientX,
                clientY: event.clientY
            };

            if (!frameId) {
                frameId = window.requestAnimationFrame(applyTilt);
            }
        }, { passive: true });

        visual.addEventListener('pointerleave', function () {
            pendingPoint = null;

            if (frameId) {
                window.cancelAnimationFrame(frameId);
                frameId = 0;
            }

            scene.style.setProperty('--ry', '0deg');
            scene.style.setProperty('--rx', '0deg');
        });
    }

    initCounters();
    initHologramTilt();
}());
