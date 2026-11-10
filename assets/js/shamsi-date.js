/* STAGE 25 - shamsi-date.js: the Solar Hijri (Jalali) date picker.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Until now every date field of the panel was an <input type="date">, so the
 * browser showed its own Gregorian calendar, stored a Gregorian number in the
 * box ("11/11/2026") and the person filling the form had to convert the date in
 * their head before they could read it back. That is the wrong calendar for this
 * product: the payroll period, the leave request and the holiday list are all
 * Jalali.
 *
 * So the box now holds the JALALI date itself - 1405/08/20 - and clicking it
 * opens a Jalali calendar. <input type="date"> is gone from the panel entirely,
 * which also means the native picker can never appear by accident.
 *
 * The database did NOT change: every column is still a real Gregorian DATE, so
 * reports, BETWEEN ranges and the MySQL date functions keep working. Two things
 * carry the conversion instead:
 *
 *   - App\Support\Input::date() accepts the Jalali string a field posts and
 *     stores the Gregorian equivalent (app/Support/Input.php).
 *   - App\Support\Jalali::input() renders a stored Gregorian DATE back as the
 *     Jalali string a field shows (app/Support/Jalali.php).
 *
 * This script is the third leg: the calendar the user actually clicks.
 *
 * THE ARITHMETIC IS NOT A SECOND OPINION
 * --------------------------------------
 * toJalali() / toGregorian() / monthLength() below are line by line ports of
 * Jalali::fromGregorian(), Jalali::toGregorian() and Jalali::monthLength(). Same
 * 1600 epoch, same 12053 / 1461 / 366 / 365 steps, same 31-then-30 month table,
 * same 33-year cycle on the way back. intdiv() is Math.trunc(), because PHP
 * intdiv() truncates toward zero and Math.floor() would not for a negative
 * dividend. tests/stage25_shamsi_date_test.js runs this port against the
 * platform's own Persian calendar (Intl, ca-persian) for 20000 consecutive days
 * in both directions, and against the PHP source it was copied from.
 *
 * WORKS WITHOUT JAVASCRIPT
 * ------------------------
 * PHP writes the value, the name and the Gregorian mirror into the markup, so a
 * form submitted with scripting off still posts the right Jalali string and
 * Input::date() still converts it. What JavaScript adds is the popup calendar,
 * the typed-value normalising and the live data-gregorian mirror - convenience,
 * never correctness.
 *
 * Conventions kept from the rest of assets/js: ES5 (var, function, no arrow
 * functions, no template literals) so an old kiosk browser can run it, single
 * quotes, one IIFE, and no build step.
 */
(function () {
    'use strict';

    /* ================================================================ calendar
     * Pure integer arithmetic. No Date object is used for the conversion itself,
     * because Date is local time and a calendar must not depend on the timezone
     * the server or the browser happens to be set to.
     * ============================================================== */

    /* PHP intdiv() truncates toward zero; Math.floor() would not for negatives. */
    function intdiv(dividend, divisor) { return Math.trunc(dividend / divisor); }

    /* Port of Jalali::fromGregorian() - Gregorian to [jy, jm, jd]. */
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

    /* Port of Jalali::toGregorian() - Jalali to [gy, gm, gd].
       The epoch constant has to be the SAME one the PHP uses: it is not a number
       to retype from memory or from another library, and two days of difference
       silently moves every stored date. tests/stage25_shamsi_date_test.js checks
       this line against app/Support/Jalali.php and against the platform's own
       Persian calendar for 20000 consecutive days. */
    function toGregorian(jy, jm, jd) {
        jy += 1595;

        var days = -355668 + (365 * jy) + intdiv(jy, 33) * 8 + intdiv((jy % 33) + 3, 4) + jd
            + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);

        var gy = 400 * intdiv(days, 146097);
        days %= 146097;

        if (days > 36524) {
            days--;
            gy += 100 * intdiv(days, 36524);
            days %= 36524;

            if (days >= 365) {
                days++;
            }
        }

        gy += 4 * intdiv(days, 1461);
        days %= 1461;

        if (days > 365) {
            gy += intdiv(days - 1, 365);
            days = (days - 1) % 365;
        }

        var gd = days + 1;

        var february = ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0) ? 29 : 28;
        var lengths = [0, 31, february, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        var gm = 1;

        while (gm <= 12 && gd > lengths[gm]) {
            gd -= lengths[gm];
            gm++;
        }

        return [gy, gm, gd];
    }

    /* Esfand is 29 or 30 days. It is decided by walking ONE day past 29 Esfand
       and asking toJalali() where it landed - the same trick the PHP uses, so
       the two cannot drift apart. */
    function monthLength(jy, jm) {
        if (jm < 1 || jm > 12) { return 0; }
        if (jm <= 6) { return 31; }
        if (jm <= 11) { return 30; }

        var gregorian = toGregorian(jy, 12, 29);
        var next = nextGregorianDay(gregorian[0], gregorian[1], gregorian[2]);
        var jalali = toJalali(next[0], next[1], next[2]);

        return (jalali[0] === jy && jalali[1] === 12 && jalali[2] === 30) ? 30 : 29;
    }

    /* Port of Jalali::nextGregorianDay() - integer only, so there is no 2038
       ceiling the way mktime() would have on a 32-bit build. */
    function nextGregorianDay(gy, gm, gd) {
        var february = ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0) ? 29 : 28;
        var lengths = [0, 31, february, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        gd++;

        if (gd > lengths[gm]) {
            gd = 1;
            gm++;

            if (gm > 12) {
                gm = 1;
                gy++;
            }
        }

        return [gy, gm, gd];
    }

    function isJalaliDate(jy, jm, jd) {
        return jm >= 1 && jm <= 12 && jd >= 1 && jd <= monthLength(jy, jm);
    }

    /* ================================================================ strings
     * ============================================================== */

    var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    /* The Iranian week starts on Saturday. */
    var WEEKDAY_SHORT = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    var WEEKDAY_LONG = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    function pad(value, width) {
        var text = String(value);
        while (text.length < width) { text = '0' + text; }
        return text;
    }

    /* The one shape a field holds, and the shape Jalali::input() writes server
       side: "1405/08/20". Latin digits on purpose - sprintf('%04d/%02d/%02d')
       in Jalali::date() produces them, and a value PHP rendered must be byte
       identical to a value this script renders or the field would flicker. */
    function format(jy, jm, jd) {
        return pad(jy, 4) + '/' + pad(jm, 2) + '/' + pad(jd, 2);
    }

    function formatGregorian(gy, gm, gd) {
        return pad(gy, 4) + '-' + pad(gm, 2) + '-' + pad(gd, 2);
    }

    /* Persian (۰-۹) and Arabic-Indic (٠-٩) digits, folded the way
       Encoding::toLatinDigits() does server side, so a pasted value behaves the
       same in the browser as it does in the controller. */
    function toLatinDigits(text) {
        return String(text).replace(/[\u06F0-\u06F9]/g, function (c) {
            return String(c.charCodeAt(0) - 0x06F0);
        }).replace(/[\u0660-\u0669]/g, function (c) {
            return String(c.charCodeAt(0) - 0x0660);
        });
    }

    /* "1405/08/20", "1405-8-2" and "۱۴۰۵/۰۸/۲۰" all parse; an impossible date
       like "1405/07/31" (Mehr has 30 days) does not. Returns [jy, jm, jd] or
       null. A Gregorian year (1900 and above) is refused here for the same
       reason Input::date() refuses it: this field is Jalali, and silently
       reading 2026 as a Jalali year would store a date 600 years out. */
    function parse(text) {
        var value = toLatinDigits(String(text === null || text === undefined ? '' : text)).trim();
        var match = /^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/.exec(value);

        if (!match) { return null; }

        var jy = parseInt(match[1], 10);
        var jm = parseInt(match[2], 10);
        var jd = parseInt(match[3], 10);

        if (jy < 1000 || jy >= 1900 || !isJalaliDate(jy, jm, jd)) { return null; }

        return [jy, jm, jd];
    }

    /* "1405/08/20" -> "2026-11-11", or '' when the field is empty or holds
       something that is not a Jalali date. This is what goes into
       data-gregorian, so the stored form of a value is always inspectable in
       the DOM without a round trip. */
    function gregorianOf(text) {
        var jalali = parse(text);

        if (!jalali) { return ''; }

        return formatGregorian(toGregorian(jalali[0], jalali[1], jalali[2])[0],
            toGregorian(jalali[0], jalali[1], jalali[2])[1],
            toGregorian(jalali[0], jalali[1], jalali[2])[2]);
    }

    /* Column of a Jalali date in a Saturday-first grid: 0 for Saturday .. 6 for
       Friday. Date.UTC is used, not new Date(y, m, d), so the weekday cannot
       shift with the browser's timezone. */
    function weekdayColumn(jy, jm, jd) {
        var gregorian = toGregorian(jy, jm, jd);
        var utcDay = new Date(Date.UTC(gregorian[0], gregorian[1] - 1, gregorian[2])).getUTCDay();

        /* getUTCDay(): 0 Sunday .. 6 Saturday. Saturday has to land in column 0. */
        return (utcDay + 1) % 7;
    }

    function todayJalali() {
        var now = new Date();
        return toJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    }

    /* ================================================================ styling
     * No new CSS classes and no Url::CSS_VERSION bump: the popup is styled
     * inline, from the custom properties the two panels already define. That
     * keeps one picker correct in both themes (manager.css --m-*, owner.css
     * --o-*) without shipping a third stylesheet, and every colour has a literal
     * fallback so an unloaded variable degrades to a readable value instead of
     * transparent-on-transparent.
     * ============================================================== */

    function cssVar(name, fallback) {
        var value = '';

        try {
            value = (window.getComputedStyle(document.body).getPropertyValue(name) || '').trim();
        } catch (e) {
            value = '';
        }

        return value === '' ? fallback : value;
    }

    function palette() {
        var owner = document.body && document.body.className.indexOf('o-body') !== -1;

        if (owner) {
            return {
                surface: cssVar('--o-panel-2', '#16233a'),
                line: cssVar('--o-line', '#22304a'),
                text: cssVar('--o-text', '#e8eefb'),
                muted: cssVar('--o-muted', '#8fa3c0'),
                accent: cssVar('--o-brand', '#4f7cff'),
                radius: cssVar('--o-radius-sm', '10px'),
                font: 'inherit'
            };
        }

        return {
            surface: cssVar('--m-surface-2', 'rgba(29,36,62,.97)'),
            line: cssVar('--m-line-strong', 'rgba(255,255,255,.16)'),
            text: cssVar('--m-text', '#e8ecf8'),
            muted: cssVar('--m-dim', '#9aa6c8'),
            accent: cssVar('--m-primary', '#4f7cff'),
            radius: cssVar('--m-radius-sm', '12px'),
            font: 'inherit'
        };
    }

    /* The manager surface token is translucent (rgba(29,36,62,.72)); a popup
       floating over a page needs to be opaque or the text behind it shows
       through. Same colour, alpha pushed up. */
    function opaque(colour) {
        var match = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/.exec(String(colour).trim());

        if (!match) { return colour; }

        return 'rgb(' + match[1] + ',' + match[2] + ',' + match[3] + ')';
    }

    /* ================================================================ element
     * ============================================================== */

    function element(tag, styles, attributes) {
        var node = document.createElement(tag);

        if (styles) {
            for (var name in styles) {
                if (Object.prototype.hasOwnProperty.call(styles, name)) {
                    node.style[name] = styles[name];
                }
            }
        }

        if (attributes) {
            for (var key in attributes) {
                if (Object.prototype.hasOwnProperty.call(attributes, key)) {
                    var value = attributes[key];

                    if (value === null || value === undefined || value === false) { continue; }

                    node.setAttribute(key, value === true ? '' : String(value));
                }
            }
        }

        return node;
    }

    /* ================================================================ popup
     * One popup for the whole page, moved to whichever field opened it. A popup
     * per field would mean 35 copies of the same DOM on the attendance page.
     * ============================================================== */

    var popup = null;
    var openInput = null;
    var viewYear = 0;
    var viewMonth = 0;
    var cursor = null;          /* [jy, jm, jd] the arrow keys move */
    var restoringFocus = false; /* set while focus is given back programmatically */

    /* Give the field the focus back WITHOUT reopening the calendar.
       The field opens the popup on focus, which is what makes a click or a Tab
       into it work; but closing the popup after a choice also focuses the field,
       and without this guard that focus event reopens the calendar the user has
       just finished with. Both listeners fire synchronously from focus(), so a
       plain flag around the call is enough. */
    function returnFocus(input) {
        restoringFocus = true;

        try {
            input.focus();
        } catch (e) {
            /* Nothing depends on the focus returning. */
        }

        restoringFocus = false;
    }

    function buildPopup() {
        var colours = palette();

        var box = element('div', {
            position: 'fixed',
            zIndex: '9000',
            boxSizing: 'border-box',
            width: '292px',
            padding: '10px',
            direction: 'rtl',
            textAlign: 'center',
            fontFamily: colours.font,
            fontSize: '13px',
            lineHeight: '1.5',
            color: colours.text,
            background: opaque(colours.surface),
            border: '1px solid ' + colours.line,
            borderRadius: colours.radius,
            boxShadow: '0 18px 40px rgba(3,7,18,.55)',
            display: 'none'
        }, {
            'data-shamsi-popup': '1',
            'role': 'dialog',
            'aria-modal': 'false',
            'aria-label': 'انتخاب تاریخ شمسی',
            'tabindex': '-1'
        });

        popup = box;
        document.body.appendChild(box);

        /* Clicking inside must not be treated as "clicked outside" by the
           document listener that closes the popup. */
        box.addEventListener('mousedown', function (event) { event.stopPropagation(); });

        return box;
    }

    function header(colours) {
        var row = element('div', {
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '6px',
            marginBottom: '8px'
        });

        var buttonStyle = {
            boxSizing: 'border-box',
            width: '30px',
            height: '30px',
            padding: '0',
            cursor: 'pointer',
            fontSize: '15px',
            lineHeight: '1',
            color: colours.text,
            background: 'transparent',
            border: '1px solid ' + colours.line,
            borderRadius: colours.radius
        };

        /* The month buttons: "previous" is the month that comes EARLIER in the
           year, which on an RTL row is the one on the right, so the glyphs point
           the way the reading direction does. Both carry an aria-label because a
           chevron alone says nothing to a screen reader. */
        var previous = element('button', buttonStyle, {
            'type': 'button',
            'data-shamsi-prev': '1',
            'aria-label': 'ماه قبل',
            'title': 'ماه قبل'
        });
        previous.textContent = '\u203A';

        var next = element('button', buttonStyle, {
            'type': 'button',
            'data-shamsi-next': '1',
            'aria-label': 'ماه بعد',
            'title': 'ماه بعد'
        });
        next.textContent = '\u2039';

        var title = element('div', {
            flex: '1',
            minWidth: '0',
            fontWeight: '600',
            fontSize: '13.5px',
            color: colours.text
        }, { 'data-shamsi-title': '1' });

        row.appendChild(previous);
        row.appendChild(title);
        row.appendChild(next);

        previous.addEventListener('click', function () { shiftMonth(-1); });
        next.addEventListener('click', function () { shiftMonth(1); });

        return row;
    }

    function grid(colours, selected) {
        var table = element('div', { 'data-shamsi-grid': '1' });

        var weekdayRow = element('div', {
            display: 'grid',
            gridTemplateColumns: 'repeat(7, 1fr)',
            gap: '2px',
            marginBottom: '4px'
        });

        for (var w = 0; w < 7; w++) {
            var cell = element('div', {
                padding: '2px 0',
                fontSize: '11.5px',
                color: colours.muted,
                userSelect: 'none'
            }, { 'aria-hidden': 'true', 'title': WEEKDAY_LONG[w] });
            cell.textContent = WEEKDAY_SHORT[w];
            weekdayRow.appendChild(cell);
        }

        table.appendChild(weekdayRow);

        var length = monthLength(viewYear, viewMonth);
        var firstColumn = weekdayColumn(viewYear, viewMonth, 1);

        var days = element('div', {
            display: 'grid',
            gridTemplateColumns: 'repeat(7, 1fr)',
            gap: '2px'
        }, { 'role': 'grid', 'aria-label': MONTHS[viewMonth - 1] + ' ' + viewYear });

        for (var blank = 0; blank < firstColumn; blank++) {
            days.appendChild(element('div', { minHeight: '30px' }, { 'aria-hidden': 'true' }));
        }

        for (var day = 1; day <= length; day++) {
            var isSelected = selected !== null
                && selected[0] === viewYear && selected[1] === viewMonth && selected[2] === day;
            var isCursor = cursor !== null
                && cursor[0] === viewYear && cursor[1] === viewMonth && cursor[2] === day;
            var isFriday = weekdayColumn(viewYear, viewMonth, day) === 6;

            var button = element('button', {
                boxSizing: 'border-box',
                height: '30px',
                padding: '0',
                cursor: 'pointer',
                fontSize: '12.5px',
                fontVariantNumeric: 'tabular-nums',
                color: isSelected ? '#ffffff' : (isFriday ? colours.muted : colours.text),
                background: isSelected ? colours.accent : 'transparent',
                border: '1px solid ' + (isCursor && !isSelected ? colours.accent : 'transparent'),
                borderRadius: colours.radius
            }, {
                'type': 'button',
                'data-shamsi-day': String(day),
                'aria-label': day + ' ' + MONTHS[viewMonth - 1] + ' ' + viewYear,
                'aria-selected': isSelected ? 'true' : 'false',
                'role': 'gridcell'
            });

            button.textContent = String(day);

            /* Captured per iteration, so every cell knows its own day. */
            (function (theDay) {
                button.addEventListener('click', function () {
                    commit(viewYear, viewMonth, theDay);
                });
            }(day));

            days.appendChild(button);
        }

        table.appendChild(days);

        return table;
    }

    function footer(colours, hasValue) {
        var row = element('div', {
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '6px',
            marginTop: '8px',
            paddingTop: '8px',
            borderTop: '1px solid ' + colours.line
        });

        var linkStyle = {
            padding: '4px 8px',
            cursor: 'pointer',
            fontSize: '12px',
            color: colours.muted,
            background: 'transparent',
            border: '1px solid transparent',
            borderRadius: colours.radius
        };

        var today = element('button', linkStyle, {
            'type': 'button',
            'data-shamsi-today': '1'
        });
        today.textContent = 'امروز';

        /* "Clear" only appears when there is something to clear; a filter that is
           already empty has no reason to offer it. */
        var clear = element('button', linkStyle, {
            'type': 'button',
            'data-shamsi-clear': '1',
            'style': hasValue ? null : 'display:none'
        });
        clear.textContent = 'پاک کردن';

        /* The Gregorian equivalent, shown as quiet text so a date can still be
           cross-checked against a bank statement or a device log without ever
           being the thing the user has to type or read back. This is the one
           place a Gregorian number appears, and it is read-only. */
        var mirror = element('span', {
            flex: '1',
            minWidth: '0',
            fontSize: '11px',
            direction: 'ltr',
            textAlign: 'center',
            color: colours.muted,
            fontVariantNumeric: 'tabular-nums'
        }, { 'data-shamsi-mirror': '1' });

        row.appendChild(today);
        row.appendChild(mirror);
        row.appendChild(clear);

        today.addEventListener('click', function () {
            var jalali = todayJalali();
            commit(jalali[0], jalali[1], jalali[2]);
        });

        clear.addEventListener('click', function () { commit(null, null, null); });

        return row;
    }

    function render() {
        if (!popup || !openInput) { return; }

        var colours = palette();
        var selected = parse(openInput.value);

        popup.textContent = '';
        popup.appendChild(header(colours));
        popup.appendChild(grid(colours, selected));
        popup.appendChild(footer(colours, selected !== null));

        var title = popup.querySelector('[data-shamsi-title]');
        if (title) { title.textContent = MONTHS[viewMonth - 1] + ' ' + viewYear; }

        var mirror = popup.querySelector('[data-shamsi-mirror]');
        if (mirror) {
            mirror.textContent = selected ? gregorianOf(openInput.value) : '';
        }

        place();
    }

    /* Under the field, or above it when there is no room. position:fixed plus
       getBoundingClientRect() means the popup never depends on the ancestor's
       overflow, transform or stacking context - a filter row inside a scrolling
       card would otherwise clip it. */
    function place() {
        if (!popup || !openInput) { return; }

        var box = openInput.getBoundingClientRect();
        var width = popup.offsetWidth || 292;
        var height = popup.offsetHeight || 300;
        var margin = 6;

        var left = box.right - width;

        if (left < margin) { left = margin; }
        if (left + width > window.innerWidth - margin) {
            left = Math.max(margin, window.innerWidth - width - margin);
        }

        var top = box.bottom + margin;

        if (top + height > window.innerHeight - margin) {
            var above = box.top - height - margin;
            top = above > margin ? above : Math.max(margin, window.innerHeight - height - margin);
        }

        popup.style.left = Math.round(left) + 'px';
        popup.style.top = Math.round(top) + 'px';
        popup.style.display = 'block';
    }

    function shiftMonth(step) {
        viewMonth += step;

        while (viewMonth < 1) { viewMonth += 12; viewYear--; }
        while (viewMonth > 12) { viewMonth -= 12; viewYear++; }

        cursor = null;
        render();
    }

    function open(input) {
        var selected = parse(input.value);
        var jalali = selected || todayJalali();

        if (openInput === input && popup && popup.style.display === 'block') {
            /* Already open on this field. Follow the value when it moved - a
               script, a "copy the range" button or a second pass through the
               same test can change it - but otherwise keep the month the user
               navigated to, so clicking the field again does not throw away the
               page they were on. */
            if (selected && (selected[0] !== viewYear || selected[1] !== viewMonth)) {
                viewYear = selected[0];
                viewMonth = selected[1];
                cursor = [selected[0], selected[1], selected[2]];
            }

            render();

            return;
        }

        close();

        openInput = input;

        if (!popup) { buildPopup(); }

        viewYear = jalali[0];
        viewMonth = jalali[1];
        cursor = selected ? [selected[0], selected[1], selected[2]] : null;

        input.setAttribute('aria-expanded', 'true');
        render();

        /* Focus goes into the popup so Tab and the arrow keys work on the days,
           and comes back to the field when it closes. */
        try { popup.focus(); } catch (e) { /* older browsers: the popup still works by mouse */ }
    }

    function close() {
        if (popup) { popup.style.display = 'none'; }

        if (openInput) {
            openInput.setAttribute('aria-expanded', 'false');
            openInput = null;
        }

        cursor = null;
    }

    function isOpen() {
        return popup !== null && popup.style.display === 'block' && openInput !== null;
    }

    /* Write a chosen date into the field. commit(null, null, null) clears it.
       Both the visible Jalali value and the data-gregorian mirror are updated,
       and the standard input/change events are dispatched so anything else on
       the page that listens to the field - a live total, a range check - behaves
       exactly as it did with the native picker. */
    function commit(jy, jm, jd) {
        var input = openInput;

        if (!input) { return; }

        if (jy === null) {
            input.value = '';
            input.setAttribute('data-gregorian', '');
        } else {
            input.value = format(jy, jm, jd);
            input.setAttribute('data-gregorian', formatGregorian(toGregorian(jy, jm, jd)[0],
                toGregorian(jy, jm, jd)[1], toGregorian(jy, jm, jd)[2]));
        }

        dispatch(input, 'input');
        dispatch(input, 'change');

        close();

        returnFocus(input);
    }

    function dispatch(input, type) {
        var event;

        try {
            event = new Event(type, { bubbles: true });
        } catch (e) {
            event = document.createEvent('Event');
            event.initEvent(type, true, false);
        }

        input.dispatchEvent(event);
    }

    /* Arrow keys move one day, one week, one month or one year; Home/End jump to
       the start of the week or the month; Enter and Space choose; Escape closes
       without choosing. The cursor is clamped to the month length, so pressing
       "down" on 30 Mehr does not invent a 31st. */
    function moveCursor(dx, dy) {
        if (!openInput) { return; }

        var base = cursor || parse(openInput.value) || [viewYear, viewMonth, 1];
        var gregorian = toGregorian(base[0], base[1], base[2]);
        var shifted = new Date(Date.UTC(gregorian[0], gregorian[1] - 1, gregorian[2] + dx + (dy * 7)));

        var jalali = toJalali(shifted.getUTCFullYear(), shifted.getUTCMonth() + 1, shifted.getUTCDate());

        cursor = jalali;
        viewYear = jalali[0];
        viewMonth = jalali[1];
        render();
    }

    function onKeyDown(event) {
        if (!isOpen()) { return; }

        var key = event.key;

        if (key === 'Escape' || key === 'Esc') {
            event.preventDefault();
            var input = openInput;
            close();
            if (input) { returnFocus(input); }
            return;
        }

        if (key === 'ArrowLeft') { event.preventDefault(); moveCursor(1, 0); return; }   /* RTL: left is later */
        if (key === 'ArrowRight') { event.preventDefault(); moveCursor(-1, 0); return; }
        if (key === 'ArrowUp') { event.preventDefault(); moveCursor(0, -1); return; }
        if (key === 'ArrowDown') { event.preventDefault(); moveCursor(0, 1); return; }

        if (key === 'PageUp') { event.preventDefault(); shiftMonth(-1); return; }
        if (key === 'PageDown') { event.preventDefault(); shiftMonth(1); return; }

        if (key === 'Home' && cursor) {
            event.preventDefault();
            cursor = [cursor[0], cursor[1], 1];
            render();
            return;
        }

        if (key === 'End' && cursor) {
            event.preventDefault();
            cursor = [cursor[0], cursor[1], monthLength(cursor[0], cursor[1])];
            render();
            return;
        }

        if ((key === 'Enter' || key === ' ' || key === 'Spacebar') && cursor) {
            event.preventDefault();
            commit(cursor[0], cursor[1], cursor[2]);
        }
    }

    /* ================================================================ upgrade
     * Every field PHP rendered as a Jalali date box carries data-shamsi. The
     * attribute, not the tag name, is what selects them, so a view can opt in
     * without repeating any of this wiring - and a grep for the attribute finds
     * every date field in the panel.
     * ============================================================== */

    function sync(input) {
        input.setAttribute('data-gregorian', gregorianOf(input.value));
    }

    /* A typed value is normalised on the way out: "1405-8-2" becomes
       "1405/08/02" and "۱۴۰۵/۰۸/۲۰" becomes its Latin-digit form, so whatever
       reaches Input::date() has one shape. Something that is not a Jalali date
       at all is dropped back to the last good value rather than posted raw -
       a silently wrong date on a payroll run is worse than an empty field. */
    function normalise(input) {
        var text = String(input.value || '').trim();

        if (text === '') {
            input.value = '';
            sync(input);
            return;
        }

        var jalali = parse(text);

        if (jalali) {
            input.value = format(jalali[0], jalali[1], jalali[2]);
        } else {
            input.value = input.getAttribute('data-last-valid') || '';
        }

        sync(input);
        input.setAttribute('data-last-valid', input.value);
    }

    function upgrade(input) {
        if (input.getAttribute('data-shamsi-ready') === '1') { return; }
        input.setAttribute('data-shamsi-ready', '1');

        /* Read-only for the pointer, editable for the keyboard: the popup is the
           intended way in, but a user who knows the date should still be able to
           type it. type="text" + inputmode="numeric" brings up a numeric keypad
           on a phone without inviting the browser's own calendar. */
        if (!input.hasAttribute('inputmode')) { input.setAttribute('inputmode', 'numeric'); }
        if (!input.hasAttribute('autocomplete')) { input.setAttribute('autocomplete', 'off'); }
        if (!input.hasAttribute('spellcheck')) { input.setAttribute('spellcheck', 'false'); }
        if (!input.hasAttribute('aria-haspopup')) { input.setAttribute('aria-haspopup', 'dialog'); }
        if (!input.hasAttribute('aria-expanded')) { input.setAttribute('aria-expanded', 'false'); }
        /* The placeholder states the SHAPE, not a date: a real looking sample
           date in an empty field reads like a value somebody forgot to clear.
           Latin digits, because that is what format() writes and what
           Jalali::input() renders server side. */
        if (!input.hasAttribute('placeholder')) { input.setAttribute('placeholder', 'YYYY/MM/DD'); }

        /* The Jalali string reads left to right even inside an RTL form, and the
           inline style is what makes that hold: a CSS rule could set direction
           on the field and would beat the dir attribute. Alignment is left to
           the field's own class (.ltr / .o-ltr), so a date box lines up with the
           text inputs next to it instead of inventing a third alignment. */
        input.style.direction = 'ltr';
        input.style.fontVariantNumeric = 'tabular-nums';

        input.setAttribute('data-last-valid', input.value || '');
        sync(input);

        input.addEventListener('focus', function () {
            /* A focus this script caused itself (closing the popup after a choice,
               or Escape) must not reopen the calendar. */
            if (restoringFocus) { return; }

            open(input);
        });
        input.addEventListener('click', function () { open(input); });
        input.addEventListener('keydown', function (event) {
            /* Space and Enter open the popup when the field is empty; otherwise
               they belong to the text being typed. */
            if (isOpen() || event.key === 'ArrowDown') {
                event.preventDefault();
                open(input);
                return;
            }

            if ((event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar')) {
                event.preventDefault();
                open(input);
            }
        });
        input.addEventListener('blur', function () { normalise(input); });
        input.addEventListener('change', function () { sync(input); });
        input.addEventListener('input', function () { sync(input); });
    }

    function upgradeAll(root) {
        var scope = root || document;
        var fields = scope.querySelectorAll('[data-shamsi]');

        for (var i = 0; i < fields.length; i++) {
            if (fields[i].tagName && fields[i].tagName.toLowerCase() === 'input') {
                upgrade(fields[i]);
            }
        }
    }

    /* ================================================================ wiring
     * ============================================================== */

    document.addEventListener('mousedown', function (event) {
        if (!isOpen()) { return; }

        var target = event.target;

        if (target === openInput) { return; }
        if (popup && popup.contains(target)) { return; }

        close();
    });

    document.addEventListener('keydown', onKeyDown);

    /* A fixed popup does not follow a scrolling page, so it closes instead of
       floating over the wrong field. Same for a resize. */
    window.addEventListener('scroll', function () { if (isOpen()) { close(); } }, true);
    window.addEventListener('resize', function () { if (isOpen()) { place(); } });

    /* Rows added later - the holiday form's inline edit row, a cloned filter -
       are upgraded when they appear. MutationObserver where it exists, and
       nothing where it does not: the fields PHP rendered are already wired. */
    if (window.MutationObserver) {
        var observer = new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var added = records[i].addedNodes;

                for (var j = 0; j < added.length; j++) {
                    var node = added[j];

                    if (!node || node.nodeType !== 1) { continue; }

                    if (node.hasAttribute && node.hasAttribute('data-shamsi')) { upgrade(node); }
                    if (node.querySelectorAll) { upgradeAll(node); }
                }
            }
        });

        var start = function () {
            observer.observe(document.body, { childList: true, subtree: true });
        };

        if (document.body) { start(); } else { document.addEventListener('DOMContentLoaded', start); }
    }

    /* Boot as soon as there is something to boot on. A deferred script runs
       after the parser has finished, so document.body is already there and the
       fields can be wired immediately; the DOMContentLoaded listener is the
       fallback for a page that manages to run this earlier, and upgrade() is
       idempotent (data-shamsi-ready), so running both is harmless. Keying on
       readyState alone is not enough: it stays "loading" in a DOM that has
       finished parsing but never ran the lifecycle, which is how a test
       environment - and any embedder - would silently get no picker at all. */
    function boot() {
        upgradeAll(document);
    }

    if (document.body) {
        boot();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    }

    /* Exported for the other panel scripts and for the test suite. The calendar
       functions are the same ones the popup uses, so a test that drives them is
       testing the shipped code and not a copy of it. */
    window.ShamsiDate = {
        VERSION: '1.0.0',
        toJalali: toJalali,
        toGregorian: toGregorian,
        monthLength: monthLength,
        isJalaliDate: isJalaliDate,
        weekdayColumn: weekdayColumn,
        parse: parse,
        format: format,
        formatGregorian: formatGregorian,
        gregorianOf: gregorianOf,
        toLatinDigits: toLatinDigits,
        today: todayJalali,
        months: MONTHS,
        weekdays: WEEKDAY_SHORT,
        upgrade: upgrade,
        upgradeAll: upgradeAll,
        open: open,
        close: close,
        isOpen: isOpen,
        commit: commit,
        popup: function () { return popup; }
    };
}());
