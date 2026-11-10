/* =============================================================================
   SARMAD Attendance - manager panel behaviour.
   Progressive enhancement only: every page works without this file.
   ========================================================================== */
(function () {
    'use strict';

    var body = document.body;

    /* ---------------------------------------------------------- sidebar -- */
    var burger = document.getElementById('m-burger');
    var backdrop = document.getElementById('m-backdrop');
    var sidebar = document.getElementById('m-sidebar');

    function closeNav() {
        body.classList.remove('nav-open');
        if (backdrop) { backdrop.hidden = true; }
        if (burger) { burger.setAttribute('aria-expanded', 'false'); }
    }

    if (burger && sidebar) {
        burger.addEventListener('click', function () {
            var open = body.classList.toggle('nav-open');
            if (backdrop) { backdrop.hidden = !open; }
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    if (backdrop) { backdrop.addEventListener('click', closeNav); }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeNav(); }
    });

    /* ------------------------------------------------- confirm on submit -- */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.getAttribute) { return; }
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    /* ------------------------------------------------------------- copy -- */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-copy]') : null;
        if (!trigger) { return; }
        var value = trigger.getAttribute('data-copy') || '';
        if (!value || !navigator.clipboard) { return; }
        event.preventDefault();
        navigator.clipboard.writeText(value).then(function () {
            var original = trigger.textContent;
            trigger.textContent = 'کپی شد';
            window.setTimeout(function () { trigger.textContent = original; }, 1600);
        });
    });

    /* ------------------------------------------------ auto submit filters -- */
    document.querySelectorAll('[data-autosubmit]').forEach(function (field) {
        field.addEventListener('change', function () {
            var form = field.closest('form');
            if (form) { form.submit(); }
        });
    });

    /* -------------------------------------------------- client side search -- */
    document.querySelectorAll('[data-table-filter]').forEach(function (input) {
        var table = document.querySelector(input.getAttribute('data-table-filter'));
        if (!table) { return; }
        input.addEventListener('input', function () {
            var needle = input.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.hidden = needle !== '' && row.textContent.toLowerCase().indexOf(needle) === -1;
            });
        });
    });

    /* -------------------------------------------------------- row toggling -- */
    document.querySelectorAll('[data-open-editor]').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            var id = trigger.getAttribute('data-open-editor');
            var target = document.getElementById(id);
            if (!target) { return; }
            event.preventDefault();
            var hidden = target.hasAttribute('hidden');
            if (hidden) { target.removeAttribute('hidden'); } else { target.setAttribute('hidden', 'hidden'); }
        });
    });

    /* --------------------------------------------- max length live counter -- */
    document.querySelectorAll('[data-counter]').forEach(function (field) {
        var output = document.querySelector(field.getAttribute('data-counter'));
        if (!output) { return; }
        var update = function () { output.textContent = String(field.value.length); };
        field.addEventListener('input', update);
        update();
    });

    /* --------------------------------------------------- jalali date hints -- */
    /* STAGE 24. Every <input type="date"> of the panel posts a Gregorian DATE -
       that is what the database stores - but the person filling the form reads
       the Solar Hijri calendar, so App\Support\JalaliHint renders
       "معادل شمسی: 1404/07/14" right after each of them. This section keeps that
       label true the moment the picker changes, without a round trip.

       toJalali() below is a line by line port of Jalali::fromGregorian() in
       app/Support/Jalali.php (same 1600 epoch, same 12053 / 1461 / 366 / 365
       steps, same 31-then-30 month table), so the browser and the server can
       never disagree about a date. tests/stage24_jalali_date_hint_test.js runs
       this port against the platform's own Persian calendar for 20000
       consecutive days, and against the PHP source it was copied from.

       Nothing here is required for the page to be correct: PHP already wrote the
       label, so with JavaScript off the hint simply stops following the picker. */

    var JALALI_PREFIX = 'معادل شمسی: ';

    /* PHP intdiv() truncates toward zero; Math.floor() would not for negatives. */
    function intdiv(dividend, divisor) { return Math.trunc(dividend / divisor); }

    function toJalali(gy, gm, gd) {
        var gregorianDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        var yearOffset = gy - 1600;
        var monthIndex = gm - 1;
        var dayIndex = gd - 1;

        var gDayNo = 365 * yearOffset
            + intdiv(yearOffset + 3, 4)
            - intdiv(yearOffset + 99, 100)
            + intdiv(yearOffset + 399, 400)
            + gregorianDays[monthIndex]
            + dayIndex;

        if (monthIndex > 1 && ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0)) {
            gDayNo++;
        }

        var jDayNo = gDayNo - 79;

        var jNp = intdiv(jDayNo, 12053);
        jDayNo %= 12053;

        var jy = 979 + 33 * jNp + 4 * intdiv(jDayNo, 1461);
        jDayNo %= 1461;

        if (jDayNo >= 366) {
            jy += intdiv(jDayNo - 1, 365);
            jDayNo = (jDayNo - 1) % 365;
        }

        var jm = 0;

        for (var i = 0; i < 11; i++) {
            var monthLength = i < 6 ? 31 : 30;

            if (jDayNo < monthLength) {
                jm = i + 1;
                break;
            }

            jDayNo -= monthLength;
        }

        if (jm === 0) {
            jm = 12;
        }

        return [jy, jm, jDayNo + 1];
    }

    function pad(value, width) {
        var text = String(value);
        while (text.length < width) { text = '0' + text; }
        return text;
    }

    /* "2025-10-06" -> "1404/07/14", and "" for anything that is not a date -
       the same contract as JalaliHint::label(). A date input only ever yields
       "" or "YYYY-MM-DD" (the DOM discards a value it cannot parse), so the
       strict pattern is enough and no untrusted string reaches the label. */
    function jalaliLabel(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value === null || value === undefined ? '' : value).trim());
        if (!match) { return ''; }

        var jalali = toJalali(parseInt(match[1], 10), parseInt(match[2], 10), parseInt(match[3], 10));

        return pad(jalali[0], 4) + '/' + pad(jalali[1], 2) + '/' + pad(jalali[2], 2);
    }

    /* The hint of one field: the first data-jalali-hint after the input, and
       never past the next form control (a holiday row and a leave row hold two
       date inputs side by side, so "the next span in the parent" would pair the
       first input with the second input's hint). */
    function hintFor(input) {
        var node = input.nextElementSibling;

        while (node) {
            if (node.hasAttribute && node.hasAttribute('data-jalali-hint')) { return node; }

            var tag = (node.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'select' || tag === 'textarea' || tag === 'button') { return null; }

            node = node.nextElementSibling;
        }

        return null;
    }

    document.querySelectorAll('input[type="date"]').forEach(function (input) {
        var hint = hintFor(input);
        if (!hint) { return; }

        var compact = hint.hasAttribute('data-jalali-compact');

        var update = function () {
            var label = jalaliLabel(input.value);

            if (label === '') {
                hint.textContent = '';
                /* both: [hidden]{display:none} from the user agent sheet loses to
                   the inline display the server wrote. */
                hint.hidden = true;
                hint.style.display = 'none';
                return;
            }

            hint.textContent = compact ? label : JALALI_PREFIX + label;
            hint.hidden = false;
            hint.style.display = 'block';
        };

        /* Deliberately NOT called once on load: PHP rendered the label from the
           stored value, and a script should not rewrite correct server output
           before anybody touched the field. It only follows the picker. */
        input.addEventListener('input', update);
        input.addEventListener('change', update);
    });
})();
