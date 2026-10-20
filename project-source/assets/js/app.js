/* SARMAD Attendance - minimal front-end helpers (no external libraries) */
(function () {
    'use strict';

    // Attach the CSRF token to fetch() calls automatically.
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';

    if (token && typeof window.fetch === 'function') {
        var nativeFetch = window.fetch.bind(window);

        window.fetch = function (input, init) {
            init = init || {};
            var method = (init.method || 'GET').toUpperCase();

            if (['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(method) !== -1) {
                var headers = new Headers(init.headers || {});
                headers.set('X-CSRF-Token', token);
                init.headers = headers;
            }

            return nativeFetch(input, init);
        };
    }

    // Disable double submits for a moment (button smashing protection).
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var button = form.querySelector('button[type="submit"]');

        if (button) {
            window.setTimeout(function () { button.disabled = true; }, 0);
            window.setTimeout(function () { button.disabled = false; }, 4000);
        }
    });

    // Copy-to-clipboard for one-time secrets (device tokens).
    document.querySelectorAll('.token-box').forEach(function (box) {
        box.style.cursor = 'pointer';
        box.title = 'برای کپی کلیک کنید';

        box.addEventListener('click', function () {
            var text = box.textContent || '';

            if (navigator.clipboard) {
                navigator.clipboard.writeText(text);
                box.title = 'کپی شد';
            }
        });
    });
})();
