<?php
/**
 * SARMAD Attendance - dBASE III (.dbf) writer.
 *
 * The Social Security Organisation still accepts the monthly insurance list as
 * a dBASE III diskette file ("فایل دیسکت لیست حق بیمه", projects 51/52 of the
 * List Disk program). Those files are read by a Windows tool, so the layout has
 * to be byte exact:
 *
 *   32 byte header  +  32 byte field descriptor per column  +  0x0D
 *   one record per row: 0x20 (not deleted) + every field at its fixed width
 *   0x1A end of file
 *
 * Only the two column types the specification uses are supported - C(n) for
 * text (left aligned, padded with spaces) and N(n) for numbers (right aligned,
 * padded with spaces, no decimal point because decimals are 0). Anything else
 * would make the file unreadable for the importer.
 *
 * There is no reader here and no external dependency: the panel writes the
 * file, the SSO program reads it.
 */

declare(strict_types=1);

namespace App\Support;

final class Dbf
{
    public const TYPE_CHAR   = 'C';
    public const TYPE_NUMBER = 'N';

    /** dBASE III without a memo file - the version List Disk expects. */
    private const VERSION = 0x03;

    private const EOF_MARKER     = 0x1A;
    private const HEADER_END     = 0x0D;
    private const RECORD_VALID   = 0x20;

    /** Language driver: 0x00 = OEM/legacy codepage, which is what CP1256 uses. */
    private const LANGUAGE_ID = 0x00;

    /**
     * Builds a complete dBASE III file.
     *
     * @param array<int,array{name:string,type:string,length:int}> $fields column definition, in file order
     * @param array<int,array<string,mixed>>                       $rows   one associative row per record
     * @param string                                               $encoding 'windows-1256' (default) or 'utf-8'
     */
    public static function build(array $fields, array $rows, string $encoding = 'windows-1256'): string
    {
        $fields = self::normaliseFields($fields);

        $recordLength = 1; // the deletion flag

        foreach ($fields as $field) {
            $recordLength += $field['length'];
        }

        $headerLength = 32 + (32 * count($fields)) + 1;

        $now    = getdate();
        $header = chr(self::VERSION)
            . chr(max(0, min(255, $now['year'] - 1900)))
            . chr(max(1, min(12, $now['mon'])))
            . chr(max(1, min(31, $now['mday'])))
            . self::uint32(count($rows))
            . self::uint16($headerLength)
            . self::uint16($recordLength)
            . str_repeat("\x00", 16)       // reserved + MDX flag
            . chr(0x00)                     // table flags
            . chr(self::LANGUAGE_ID)        // language driver
            . "\x00\x00";                   // reserved

        foreach ($fields as $field) {
            $name = strtoupper(substr($field['name'], 0, 10)); // dBASE III: max 10 characters

            $header .= str_pad($name, 11, "\x00")
                . $field['type']
                . "\x00\x00\x00\x00"       // field data address (unused)
                . chr($field['length'])
                . chr(0)                   // decimal count
                . str_repeat("\x00", 14);  // reserved
        }

        $header .= chr(self::HEADER_END);

        $body = '';

        foreach ($rows as $row) {
            $body .= chr(self::RECORD_VALID);

            foreach ($fields as $field) {
                $body .= self::encodeValue($row[$field['name']] ?? '', $field, $encoding);
            }
        }

        return $header . $body . chr(self::EOF_MARKER);
    }

    /**
     * One record as bytes, without the deletion flag. Useful for the tests and
     * for a debugging dump.
     *
     * @param array<int,array{name:string,type:string,length:int}> $fields
     * @param array<string,mixed>                                  $row
     */
    public static function encodeRecord(array $fields, array $row, string $encoding = 'windows-1256'): string
    {
        $out = '';

        foreach (self::normaliseFields($fields) as $field) {
            $out .= self::encodeValue($row[$field['name']] ?? '', $field, $encoding);
        }

        return $out;
    }

    /**
     * @param array<int,array{name:string,type:string,length:int}> $fields
     * @return array<int,array{name:string,type:string,length:int}>
     */
    private static function normaliseFields(array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $type   = strtoupper(substr((string) ($field['type'] ?? self::TYPE_CHAR), 0, 1));
            $length = (int) ($field['length'] ?? 0);

            if ($type !== self::TYPE_CHAR && $type !== self::TYPE_NUMBER) {
                $type = self::TYPE_CHAR;
            }

            // dBASE III columns are at most 254 bytes wide; the exporter never
            // comes close, but a malformed definition must not corrupt the file.
            $length = max(1, min(254, $length));

            $out[] = [
                'name'   => (string) ($field['name'] ?? ''),
                'type'   => $type,
                'length' => $length,
            ];
        }

        return $out;
    }

    /**
     * @param array{name:string,type:string,length:int} $field
     * @param mixed                                     $value
     */
    private static function encodeValue($value, array $field, string $encoding): string
    {
        if ($field['type'] === self::TYPE_NUMBER) {
            return self::encodeNumber($value, $field['length']);
        }

        return self::encodeText($value, $field['length'], $encoding);
    }

    /**
     * A numeric column holds plain ASCII digits, right aligned, padded with
     * spaces on the left. An empty or zero-length value becomes all spaces,
     * which every dBASE reader treats as NULL (never 0 - the SSO program shows
     * a blank cell for a field that was not filled in).
     *
     * @param mixed $value
     */
    private static function encodeNumber($value, int $length): string
    {
        if ($value === null || $value === '') {
            return str_repeat(' ', $length);
        }

        $number = (int) round((float) $value);
        $text   = (string) $number;

        // Too wide for the column: the digit string is trimmed from the left so
        // the least significant digits survive instead of breaking the record
        // width. A 12 digit money column never overflows a rial payroll.
        if (strlen($text) > $length) {
            $text = substr($text, -$length);
        }

        return str_pad($text, $length, ' ', STR_PAD_LEFT);
    }

    /**
     * A character column is left aligned and padded with spaces. The byte width
     * is what counts, so the string is converted to the target codepage first
     * and then cut at exactly $length bytes.
     *
     * @param mixed $value
     */
    private static function encodeText($value, int $length, string $encoding): string
    {
        $text = Encoding::sanitize((string) ($value ?? ''));
        $text = Encoding::toLatinDigits($text);

        if (strtolower($encoding) === 'utf-8') {
            $bytes = $text;
        } else {
            $bytes = Encoding::toWindows1256($text);
        }

        if (strlen($bytes) > $length) {
            $bytes = substr($bytes, 0, $length);
        }

        return str_pad($bytes, $length, ' ', STR_PAD_RIGHT);
    }

    private static function uint16(int $value): string
    {
        return chr($value & 0xFF) . chr(($value >> 8) & 0xFF);
    }

    private static function uint32(int $value): string
    {
        return chr($value & 0xFF)
            . chr(($value >> 8) & 0xFF)
            . chr(($value >> 16) & 0xFF)
            . chr(($value >> 24) & 0xFF);
    }
}
