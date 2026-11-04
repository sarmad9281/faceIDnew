/**
 * =====================================================================
 * SARMAD Attendance - kiosk QR badge reader (stage 20)
 * =====================================================================
 *
 * The fallback path for an employee whose FACE does not match (a mask, a
 * scarf, strong glasses, a beard, bad light): he holds his printed badge up
 * to the kiosk camera and the symbol is decoded ON THE DEVICE.
 *
 * Hard rules this module implements
 * ---------------------------------
 *   * ZERO bytes on the server disk. The decoded string is the only thing
 *     that ever leaves the browser. The frame is decoded in memory (an
 *     ImageData buffer of the <video>) and dropped immediately: this module
 *     never calls toDataURL, never touches canvas storage, never uploads a
 *     Blob, an image, a crop or a thumbnail. There is no code path here that
 *     can write a file anywhere.
 *   * OFFLINE FIRST. The device holds the SHA-256 DIGEST of every badge of its
 *     own customer (downloaded with the face dataset), so a badge can be
 *     matched with the server unreachable; the punch goes to the same offline
 *     queue as a face punch and is synced later.
 *   * The badge itself is never stored. `digest()` is one way; a digest in
 *     localStorage can not be turned back into a printable badge.
 *
 * The module is dependency free (except for an optional injected decoder) and
 * is loaded both by the kiosk page and by the Node test suite, so the code
 * under test is the code that runs on the device.
 *
 * @returns {object} QR badge API
 */
(function (root, factory) {
    'use strict';

    var api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.SarmadDeviceQr = api;
    }
}(typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : null), function () {
    'use strict';

    /* The global scope of the page (window in a browser, globalThis in Node).
       The UMD argument above is only used to EXPORT the module. */
    var GLOBAL = typeof globalThis !== 'undefined'
        ? globalThis
        : (typeof self !== 'undefined' ? self : (typeof window !== 'undefined' ? window : {}));

    var PREFIX = 'SARMADQ1';
    var MAX_PAYLOAD = 64;

    /* -----------------------------------------------------------------
     * SHA-256 (hex) - a small, dependency free implementation.
     *
     * `crypto.subtle` only exists in a secure context (https / localhost) and
     * a kiosk in a shop is very often served over plain http on the LAN. The
     * digest is a 47 character string, so a JS implementation costs nothing
     * and works everywhere - including old tablets.
     * ----------------------------------------------------------------*/
    var K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ];

    function utf8Bytes(text) {
        var bytes = [];
        var i;
        var code;

        for (i = 0; i < text.length; i += 1) {
            code = text.charCodeAt(i);

            if (code < 0x80) {
                bytes.push(code);
            } else if (code < 0x800) {
                bytes.push(0xc0 | (code >> 6), 0x80 | (code & 0x3f));
            } else if (code >= 0xd800 && code <= 0xdbff) {
                // surrogate pair
                var next = text.charCodeAt(i + 1);
                var point = 0x10000 + ((code - 0xd800) << 10) + (next - 0xdc00);

                bytes.push(
                    0xf0 | (point >> 18),
                    0x80 | ((point >> 12) & 0x3f),
                    0x80 | ((point >> 6) & 0x3f),
                    0x80 | (point & 0x3f)
                );
                i += 1;
            } else {
                bytes.push(0xe0 | (code >> 12), 0x80 | ((code >> 6) & 0x3f), 0x80 | (code & 0x3f));
            }
        }

        return bytes;
    }

    function rotr(value, bits) {
        return (value >>> bits) | (value << (32 - bits));
    }

    function sha256Hex(text) {
        var bytes = utf8Bytes(String(text));
        var bitLength = bytes.length * 8;
        var withPadding = bytes.slice();

        withPadding.push(0x80);

        while (withPadding.length % 64 !== 56) {
            withPadding.push(0x00);
        }

        // 64 bit length, big endian (the high word is always 0 here)
        withPadding.push(0, 0, 0, 0);
        withPadding.push(
            (bitLength >>> 24) & 0xff,
            (bitLength >>> 16) & 0xff,
            (bitLength >>> 8) & 0xff,
            bitLength & 0xff
        );

        var h = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
        var w = new Array(64);
        var offset;
        var i;

        for (offset = 0; offset < withPadding.length; offset += 64) {
            for (i = 0; i < 16; i += 1) {
                w[i] = (withPadding[offset + i * 4] << 24)
                    | (withPadding[offset + i * 4 + 1] << 16)
                    | (withPadding[offset + i * 4 + 2] << 8)
                    | withPadding[offset + i * 4 + 3];
            }

            for (i = 16; i < 64; i += 1) {
                var s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3);
                var s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10);

                w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
            }

            var a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], hh = h[7];

            for (i = 0; i < 64; i += 1) {
                var S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
                var ch = (e & f) ^ (~e & g);
                var temp1 = (hh + S1 + ch + K[i] + w[i]) | 0;
                var S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
                var maj = (a & b) ^ (a & c) ^ (b & c);
                var temp2 = (S0 + maj) | 0;

                hh = g;
                g = f;
                f = e;
                e = (d + temp1) | 0;
                d = c;
                c = b;
                b = a;
                a = (temp1 + temp2) | 0;
            }

            h[0] = (h[0] + a) | 0;
            h[1] = (h[1] + b) | 0;
            h[2] = (h[2] + c) | 0;
            h[3] = (h[3] + d) | 0;
            h[4] = (h[4] + e) | 0;
            h[5] = (h[5] + f) | 0;
            h[6] = (h[6] + g) | 0;
            h[7] = (h[7] + hh) | 0;
        }

        var out = '';
        var j;

        for (j = 0; j < 8; j += 1) {
            out += ('00000000' + (h[j] >>> 0).toString(16)).slice(-8);
        }

        return out;
    }

    /* -----------------------------------------------------------------
     * Badge helpers
     * ----------------------------------------------------------------*/

    function normalise(raw) {
        return String(raw === null || raw === undefined ? '' : raw)
            .replace(/\s+/g, '')
            .toUpperCase()
            .slice(0, MAX_PAYLOAD);
    }

    function looksLikeBadge(raw) {
        var value = normalise(raw);

        return value.length > PREFIX.length + 1 && value.indexOf(PREFIX + '.') === 0;
    }

    function digest(raw) {
        return sha256Hex(normalise(raw));
    }

    /**
     * Find the employee of a scanned badge in the digest list the device
     * downloaded. Nothing else is compared: the digest is the identity.
     *
     * @param {string} raw            what the camera decoded
     * @param {Array}  employees      [{ employee_id, qr_digest, full_name, ... }]
     * @returns {object|null}         { employee, digest } or null
     */
    function match(raw, employees) {
        if (!looksLikeBadge(raw) || !employees || !employees.length) {
            return null;
        }

        var hash = digest(raw);
        var i;

        for (i = 0; i < employees.length; i += 1) {
            var candidate = employees[i];
            var known = String((candidate && candidate.qr_digest) || '').toLowerCase();

            if (known.length === 64 && known === hash) {
                return { employee: candidate, digest: hash };
            }
        }

        return null;
    }

    /**
     * Decode one frame.
     *
     * @param {ImageData} imageData  an in-memory frame (never persisted)
     * @param {object}    options    { inversionAttempts }
     * @returns {string|null}        the decoded text, or null
     */
    function decodeFrame(imageData, options) {
        var decoder = (typeof jsQR === 'function') ? jsQR : (GLOBAL && typeof GLOBAL.jsQR === 'function' ? GLOBAL.jsQR : null);

        if (!decoder || !imageData || !imageData.data) {
            return null;
        }

        try {
            var result = decoder(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: (options && options.inversionAttempts) || 'dontInvert'
            });

            return result && result.data ? String(result.data) : null;
        } catch (error) {
            /* a dirty frame is not an error: the next one will do */
            return null;
        }
    }

    return {
        PREFIX: PREFIX,
        MAX_PAYLOAD: MAX_PAYLOAD,
        sha256: sha256Hex,
        normalise: normalise,
        looksLikeBadge: looksLikeBadge,
        digest: digest,
        match: match,
        decodeFrame: decodeFrame
    };
}));
