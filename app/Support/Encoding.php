<?php
/**
 * SARMAD Attendance - Persian text encoding helper for exported flat files.
 *
 * The Social Security Organisation ("سازمان تأمین اجتماعی") diskette layout is
 * an 8-bit format: every field of DSKKAR00 / DSKWOR00 is either a numeric
 * column (always Latin digits) or a single-byte character column. Iranian
 * Windows programs read those bytes as WINDOWS-1256, so a Persian name has to
 * leave this application as CP1256 bytes and not as UTF-8.
 *
 * iconv/mbstring are not guaranteed to exist on every shared host this panel
 * is deployed on, so the conversion table lives here and the result does not
 * depend on the PHP build. Everything that can not be represented (a Latin
 * accented letter that CP1256 lacks, an emoji, a Chinese character) becomes
 * '?' - exactly what iconv TRANSLIT would do for an unmappable codepoint - so
 * a field never grows, never breaks the fixed-width layout and never contains
 * a byte that a Windows editor would show as a replacement character.
 *
 * Digits are normalised to Latin as well: the diskette specification states
 * that all numeric information is written with Latin digits, and CP1256 has no
 * slot for the Arabic-Indic / extended Arabic-Indic digits at all.
 */

declare(strict_types=1);

namespace App\Support;

final class Encoding
{
    /**
     * Codepoints of the CP1256 upper half, in byte order: entry i is the
     * character stored in byte 0x80 + i. Generated from the Windows-1256
     * table, so the exporter never has to guess a byte.
     */
    private const UPPER_HALF =
        '20ac,067e,201a,0192,201e,2026,2020,2021,02c6,2030,' .
        '0679,2039,0152,0686,0698,0688,06af,2018,2019,201c,' .
        '201d,2022,2013,2014,06a9,2122,0691,203a,0153,200c,' .
        '200d,06ba,00a0,060c,00a2,00a3,00a4,00a5,00a6,00a7,' .
        '00a8,00a9,06be,00ab,00ac,00ad,00ae,00af,00b0,00b1,' .
        '00b2,00b3,00b4,00b5,00b6,00b7,00b8,00b9,061b,00bb,' .
        '00bc,00bd,00be,061f,06c1,0621,0622,0623,0624,0625,' .
        '0626,0627,0628,0629,062a,062b,062c,062d,062e,062f,' .
        '0630,0631,0632,0633,0634,0635,0636,00d7,0637,0638,' .
        '0639,063a,0640,0641,0642,0643,00e0,0644,00e2,0645,' .
        '0646,0647,0648,00e7,00e8,00e9,00ea,00eb,0649,064a,' .
        '00ee,00ef,064b,064c,064d,064e,00f4,064f,0650,00f7,' .
        '0651,00f9,0652,00fb,00fc,200e,200f,06d2,';

    /** Codepoint => single byte for the whole 0x00-0xFF range. @var array<int,int> */
    private static $table = [];

    /**
     * Persian "ی" (U+06CC) has no CP1256 byte - Iranian Windows software writes
     * it as the Arabic form "ي" (U+064A) instead. Same for the Arabic "ک"
     * variants that are not part of the table. Without this a name would come
     * out as "????" in the SSO reader.
     *
     * @var array<int,int>
     */
    private const FOLD = [
        0x06CC => 0x064A, // ی -> ي
        0x06D5 => 0x0647, // ە -> ه
        0x06C0 => 0x0629, // ة -> ة
        0x0643 => 0x06A9, // ك -> ک (the table has ک at 0x98, and Persian text uses ک)
        // The Arabic-Indic and extended Arabic-Indic digits have no CP1256 byte
        // at all, so they fold to the Latin digits the SSO layout demands
        // instead of turning a national code into "??????????".
        0x0660 => 0x0030, 0x0661 => 0x0031, 0x0662 => 0x0032, 0x0663 => 0x0033,
        0x0664 => 0x0034, 0x0665 => 0x0035, 0x0666 => 0x0036, 0x0667 => 0x0037,
        0x0668 => 0x0038, 0x0669 => 0x0039,
        0x06F0 => 0x0030, 0x06F1 => 0x0031, 0x06F2 => 0x0032, 0x06F3 => 0x0033,
        0x06F4 => 0x0034, 0x06F5 => 0x0035, 0x06F6 => 0x0036, 0x06F7 => 0x0037,
        0x06F8 => 0x0038, 0x06F9 => 0x0039,
    ];

    /**
     * Codepoints that must never reach a fixed-width export file because they
     * either carry no visible glyph or would break a line-oriented reader.
     * Zero width non-joiner is kept: CP1256 has a byte for it (0x9D).
     *
     * @var array<int,bool>
     */
    private const DROP = [
        0x0000 => true, 0x000A => true, 0x000D => true, 0x0009 => true,
        0x200B => true, 0x200E => true, 0x200F => true, 0xFEFF => true,
    ];

    /**
     * Converts a UTF-8 string to WINDOWS-1256 bytes.
     *
     * Unmappable codepoints become '?'. The result is always exactly as long
     * as the number of (kept) characters, which is what a fixed-width dBASE
     * column needs.
     */
    public static function toWindows1256(string $text): string
    {
        $table = self::table();
        $out   = '';
        $len   = strlen($text);
        $i     = 0;

        while ($i < $len) {
            $byte = ord($text[$i]);

            if ($byte < 0x80) {
                // ASCII passes through unchanged - that keeps every Latin
                // digit and letter of a bank account or a national code.
                $out .= ($byte >= 0x20 || $byte === 0x20) ? $text[$i] : ' ';
                $i++;
                continue;
            }

            [$codepoint, $size] = self::decodeUtf8($text, $i, $len);
            $i += $size;

            if ($codepoint === null) {
                $out .= '?';
                continue;
            }

            if (isset(self::DROP[$codepoint])) {
                continue;
            }

            if (isset(self::FOLD[$codepoint])) {
                $codepoint = self::FOLD[$codepoint];
            }

            $out .= isset($table[$codepoint]) ? chr($table[$codepoint]) : '?';
        }

        return $out;
    }

    /**
     * Converts WINDOWS-1256 bytes back to UTF-8. Used by the exporter tests and
     * by anything that has to read a returned diskette file again.
     */
    public static function fromWindows1256(string $bytes): string
    {
        $table = self::table();
        $byByte = [];

        foreach ($table as $codepoint => $byte) {
            $byByte[$byte] = $codepoint; // first writer wins, matching Windows
        }

        $out = '';

        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $byte = ord($bytes[$i]);

            if ($byte < 0x80) {
                $out .= $bytes[$i];
                continue;
            }

            $out .= isset($byByte[$byte]) ? self::encodeUtf8($byByte[$byte]) : '?';
        }

        return $out;
    }

    /**
     * Encodes a codepoint as UTF-8 without relying on mbstring.
     */
    public static function encodeUtf8(int $codepoint): string
    {
        if ($codepoint < 0x80) {
            return chr($codepoint);
        }

        if ($codepoint < 0x800) {
            return chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint < 0x10000) {
            return chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        return chr(0xF0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3F))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }

    /**
     * Prepares free text for an export field: control characters and line
     * breaks are removed (they would destroy a comma separated record), runs of
     * spaces are collapsed and the string is trimmed.
     */
    public static function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Replaces the Arabic-Indic and extended Arabic-Indic digits with Latin
     * ones. The SSO layout demands Latin digits everywhere.
     */
    public static function toLatinDigits(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /**
     * True when the host can also convert with iconv (only used to decide
     * whether an alternative codepage could be offered).
     */
    public static function hasIconv(): bool
    {
        return function_exists('iconv');
    }

    /**
     * @return array<int,int>
     */
    private static function table(): array
    {
        if (self::$table !== []) {
            return self::$table;
        }

        $table = [];

        for ($byte = 0; $byte < 0x80; $byte++) {
            $table[$byte] = $byte; // ASCII is identical in both encodings
        }

        foreach (explode(',', rtrim(self::UPPER_HALF, ',')) as $i => $hex) {
            $codepoint = (int) hexdec($hex);

            if (!isset($table[$codepoint])) {
                $table[$codepoint] = 0x80 + $i;
            }
        }

        self::$table = $table;

        return $table;
    }

    /**
     * @return array{0:int|null,1:int} [codepoint, bytes consumed]
     */
    private static function decodeUtf8(string $text, int $i, int $len): array
    {
        $byte = ord($text[$i]);

        if ($byte >= 0xC0 && $byte <= 0xDF && $i + 1 < $len) {
            $size = 2;
            $cp   = $byte & 0x1F;
        } elseif ($byte >= 0xE0 && $byte <= 0xEF && $i + 2 < $len) {
            $size = 3;
            $cp   = $byte & 0x0F;
        } elseif ($byte >= 0xF0 && $byte <= 0xF7 && $i + 3 < $len) {
            $size = 4;
            $cp   = $byte & 0x07;
        } else {
            return [null, 1]; // truncated sequence
        }

        for ($k = 1; $k < $size; $k++) {
            $next = ord($text[$i + $k]);

            if (($next & 0xC0) !== 0x80) {
                return [null, $k];
            }

            $cp = ($cp << 6) | ($next & 0x3F);
        }

        return [$cp, $size];
    }
}
