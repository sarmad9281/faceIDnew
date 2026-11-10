<?php
/**
 * SARMAD Attendance - the "معادل شمسی" hint under a Gregorian date input.
 *
 * STAGE 24. Every `<input type="date">` of the manager panel posts a real
 * Gregorian DATE, because that is what the database stores - but the person
 * filling the form thinks in the Solar Hijri calendar. The employee form already
 * showed that equivalence next to "تاریخ استخدام"; this helper turns it into ONE
 * markup shape so all 29 date fields of the panel can carry it, and so
 * `assets/js/manager.js` can keep it in sync the instant the picker changes,
 * with no round trip. JavaScript off changes nothing: the label is rendered
 * here, in PHP, so it is correct before any script runs.
 *
 * This class emits HTML on purpose - it is a VIEW helper. `Jalali` stays the
 * pure converter that services and exporters call, while the escaping, the class
 * names and the `data-jalali-hint` hook the script looks for live here.
 *
 * Two rules this file follows that are easy to break:
 *
 *  - Only classes that ALREADY exist in assets/css/manager.css are used
 *    (`field-hint`, `num`). Colour, size and spacing are inline, so the hint is
 *    readable without touching a stylesheet - and therefore without bumping
 *    Url::CSS_VERSION, which would throw away every cached panel stylesheet for
 *    one line of text. `.field-hint` alone would use `--m-faint` (#6b779b),
 *    about 4.2:1 on the panel: below AA at this size. `--m-dim` (#9aa6c8), the
 *    colour `.muted` already uses, is about 7.7:1 - AAA. Inline also beats the
 *    `.field > span` rule (specificity 0,1,1) that would otherwise style the
 *    hint as if it were a field LABEL and hang a 6px margin under it.
 *  - An empty hint is `display:none` in the style attribute AND `hidden`. The
 *    attribute alone is not enough: `[hidden]{display:none}` comes from the user
 *    agent sheet and loses to any inline `display`, so the script toggles both.
 */

declare(strict_types=1);

namespace App\Support;

final class JalaliHint
{
    /**
     * The wording in front of the date. assets/js/manager.js rebuilds the very
     * same string when the picker changes, so the two must not drift apart -
     * tests/stage24_jalali_date_hint_test.js compares this constant with the
     * literal inside the script, character by character.
     */
    public const PREFIX = 'معادل شمسی: ';

    /**
     * "2025-10-06" -> "1404/07/14".
     *
     * Anything that is not a date - empty, "0000-00-00", or a stray string that
     * arrived through a query parameter - returns "", so the hint is hidden
     * instead of echoing garbage back. The raw value is NEVER part of the
     * output: the conversion goes through Jalali::date() and its result is
     * accepted only when it is exactly four digits, slash, two digits, slash,
     * two digits. Jalali::date() is also idempotent, so a value that is already
     * Jalali comes back unchanged rather than being converted twice.
     *
     * @param mixed $value
     */
    public static function label($value): string
    {
        $converted = Jalali::date($value);

        if (preg_match('#^\d{4}/\d{2}/\d{2}$#', $converted) !== 1) {
            return '';
        }

        return $converted;
    }

    /**
     * The <span> that follows the date input.
     *
     * It is rendered ALWAYS - empty and hidden when there is no date yet - so
     * the script has a stable element to fill in the moment somebody picks a
     * date, and so every date field has the same DOM shape.
     *
     * Placement matters: the span has to stay the input's next element sibling
     * (or at least the first `data-jalali-hint` before the next form control).
     * That is how manager.js pairs a field with its hint without an id - and ids
     * are not an option here because names repeat on one page ("date_from" is
     * used by both the filter bar and the recalculate form on the attendance
     * screen).
     *
     * `aria-hidden` keeps the hint out of the input's accessible NAME: these
     * spans sit inside a wrapping `<label>`, and a label contributes its whole
     * text content to the name of the control it wraps, so without it a field
     * would announce itself as "از تاریخ معادل شمسی: 1404/07/14" - and change
     * that announcement every time the date changes. The date itself is already
     * on the input, so nothing is lost; the hint stays a visual convenience.
     *
     * @param mixed $value
     * @param bool  $compact true inside the tight inline forms of a table row (a
     *                       holiday, a leave): the date alone, without the
     *                       wording in front of it, so the row does not grow by
     *                       two labels. The wording moves to a title attribute.
     */
    public static function render($value, bool $compact = false): string
    {
        $label = self::label($value);

        $attributes = ' class="field-hint num" data-jalali-hint'
            // the compact rows drop the wording to stay narrow, so it moves to a
            // tooltip - derived from PREFIX (minus its colon and space) so the two
            // can never drift apart
            . ($compact ? ' data-jalali-compact="1" title="' . self::escape(rtrim(self::PREFIX, ": \t\n\r")) . '"' : '')
            . ' aria-hidden="true"';

        if ($label === '') {
            return '<span' . $attributes . ' style="' . self::style('none') . '" hidden></span>';
        }

        $text = $compact ? $label : self::PREFIX . $label;

        return '<span' . $attributes . ' style="' . self::style('block') . '">' . self::escape($text) . '</span>';
    }

    private static function style(string $display): string
    {
        return 'display:' . $display . ';margin:3px 0 0;font-size:.78rem;line-height:1.6;'
            . 'color:var(--m-dim,#9aa6c8);white-space:nowrap';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
