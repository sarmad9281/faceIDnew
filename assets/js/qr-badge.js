/**
 * =====================================================================
 * SARMAD Attendance - printable employee QR badge (stage 20)
 * =====================================================================
 *
 * The manager profile page carries the badge string in a data attribute; this
 * file turns it into a QR symbol IN THE BROWSER (inline SVG, no external
 * request, no image file).
 *
 * WHY IT IS RENDERED CLIENT SIDE
 * ---------------------------------------------------------------------
 *   * no image is generated or stored on the host: the symbol exists only in
 *     the DOM of the page the manager is looking at, and it is printed from
 *     there,
 *   * SVG prints crisply at any size (a PNG badge would print blurry),
 *   * the badge itself is a short text (SARMADQ1...) - the QR is only a
 *     convenient carrier for it.
 *
 * The module is dependency free outside the browser (window.qrcode) and is
 * also loaded by the Node test suite to check the "no file, no canvas upload"
 * contract.
 */
(function (root, factory) {
    'use strict';

    var api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.SarmadQrBadge = api;
    }
}(typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : null), function () {
    'use strict';

    /* window in a browser, globalThis in Node (the test suite drives this
       module too). The UMD argument above is only used to EXPORT it. */
    var GLOBAL = typeof globalThis !== 'undefined'
        ? globalThis
        : (typeof self !== 'undefined' ? self : (typeof window !== 'undefined' ? window : {}));

    /** Errors corrected in the symbol (M is the sweet spot for a badge). */
    var ECC = 'M';

    /**
     * Render one badge as an inline SVG string.
     *
     * @param {function} generator  window.qrcode (qrcode-generator)
     * @param {string}   payload    the badge string
     * @param {{cellSize?:number, margin?:number}} options
     * @returns {string} SVG markup
     */
    function svg(generator, payload, options) {
        var opts = options || {};
        var value = String(payload || '').trim();

        if (typeof generator !== 'function' || value === '') {
            return '';
        }

        var code = generator(0, ECC);
        code.addData(value);
        code.make();

        return code.createSvgTag({
            cellSize: opts.cellSize || 6,
            margin: opts.margin || 2,
            scalable: true
        });
    }

    /**
     * Paint every `[data-qr-badge]` node of the page.
     *
     * @param {Document} doc
     * @param {function} generator
     * @returns {number} how many badges were rendered
     */
    function renderAll(doc, generator) {
        if (!doc || typeof doc.querySelectorAll !== 'function') {
            return 0;
        }

        var nodes = doc.querySelectorAll('[data-qr-badge]');
        var painted = 0;
        var i;

        for (i = 0; i < nodes.length; i += 1) {
            var node = nodes[i];
            var payload = node.getAttribute('data-qr-badge') || '';

            var markup = svg(generator, payload, {
                cellSize: parseInt(node.getAttribute('data-cell-size'), 10) || 6,
                margin: 2
            });

            if (markup === '') {
                continue;
            }

            node.innerHTML = markup;
            node.setAttribute('data-qr-ready', '1');
            painted += 1;
        }

        return painted;
    }

    /**
     * Browser bootstrap: wait for the DOM, then paint.
     *
     * @param {Document} doc
     * @param {object} scope   window-like object holding `qrcode`
     */
    function boot(doc, scope) {
        if (!doc || !scope || typeof scope.qrcode !== 'function') {
            return 0;
        }

        var painted = renderAll(doc, scope.qrcode);

        // The print button lives next to the card; wiring it here keeps the
        // page free of inline script (the panel owns the CSP).
        var buttons = doc.querySelectorAll('[data-qr-print]');

        for (var i = 0; i < buttons.length; i += 1) {
            buttons[i].addEventListener('click', function (event) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }

                if (typeof scope.print === 'function') {
                    scope.print();
                }
            });
        }

        return painted;
    }

    if (GLOBAL && typeof GLOBAL.document === 'object' && GLOBAL.document !== null && GLOBAL.qrcode) {
        var doc = GLOBAL.document;

        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', function () {
                boot(doc, GLOBAL);
            });
        } else {
            boot(doc, GLOBAL);
        }
    }

    return {
        ECC: ECC,
        svg: svg,
        renderAll: renderAll,
        boot: boot
    };
}));
