<?php
/**
 * SARMAD Attendance - the employee QR badge (stage 20).
 *
 * WHAT A BADGE IS
 * ---------------------------------------------------------------------
 * A badge is a short, printable string that identifies ONE employee of ONE
 * customer:
 *
 *     SARMADQ1.<customer base36>.<employee base36>.<20 hex signature>
 *
 * The signature is an HMAC-SHA256 of the customer/employee pair keyed with
 * the installation `app.key`, so a badge
 *
 *   * cannot be guessed: the customer and employee ids are public knowledge,
 *     the signature is not,
 *   * cannot be forged or edited: changing one digit of the id invalidates
 *     the MAC (compared with hash_equals),
 *   * cannot be transplanted between installations: another server has
 *     another app.key and therefore refuses the same string,
 *   * cannot cross tenants: an employee of customer A is refused on a device
 *     of customer B - even inside the same installation,
 *   * expires by itself when the employee is deactivated: the engine only
 *     accepts a badge for an ACTIVE employee of the device's own customer.
 *
 * WHY THE BADGE IS NOT STORED ANYWHERE
 * ---------------------------------------------------------------------
 * The badge is DERIVED, never persisted: `payload()` is a pure function of
 * (app.key, customer id, employee id). There is no `qr_token` column, no
 * badge file and no badge image - which is also why scanning one can never
 * write a byte to the disk: the kiosk decodes the symbol in memory and posts
 * a string.
 *
 * OFFLINE USE
 * ---------------------------------------------------------------------
 * The kiosk downloads a SHA-256 DIGEST of each badge (never the signature
 * itself, and never an image). Offline it hashes what the camera decoded and
 * looks the digest up in that list; the punch is queued and the server
 * re-derives the digest from the employee row before writing anything, so an
 * offline queue item cannot name an employee whose badge the device did not
 * actually see.
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

final class EmployeeQr
{
    /** Version tag of the payload format. A future format uses SARMADQ2. */
    public const PREFIX = 'SARMADQ1';

    /** Longest string accepted from a scanner (a QR of this format is < 64). */
    public const MAX_LENGTH = 64;

    /** Characters of the HMAC kept in the payload (80 bits). */
    private const SIGNATURE_LENGTH = 20;

    /**
     * The printed / displayed value of an employee badge.
     */
    public static function payload(int $customerId, int $employeeId): string
    {
        if ($customerId <= 0 || $employeeId <= 0) {
            throw new RuntimeException('EmployeeQr::payload needs a real customer and employee id.');
        }

        return self::PREFIX
            . '.' . self::encodeId($customerId)
            . '.' . self::encodeId($employeeId)
            . '.' . self::signature($customerId, $employeeId);
    }

    /**
     * Trim / upper-case whatever a scanner produced.
     *
     * A decoder may hand back stray whitespace or line breaks (cheap paper,
     * a folded badge); the canonical payload never contains either.
     */
    public static function normalise(string $raw): string
    {
        $raw = strtoupper(trim($raw));

        return (string) preg_replace('/\s+/', '', $raw);
    }

    /**
     * Does this string even claim to be a badge of this format?
     */
    public static function looksLikeBadge(string $raw): bool
    {
        $value = self::normalise($raw);

        return strlen($value) <= self::MAX_LENGTH
            && strncmp($value, self::PREFIX . '.', strlen(self::PREFIX) + 1) === 0;
    }

    /**
     * Verify a scanned badge and return the employee it belongs to.
     *
     * Returns null for EVERY failure (wrong prefix, bad shape, forged/edited
     * signature, another tenant) on purpose: the kiosk must not be able to
     * tell "wrong signature" from "unknown employee".
     *
     * @param int $expectCustomerId when > 0 the badge must belong to this tenant
     * @return array{customer_id:int,employee_id:int}|null
     */
    public static function verify(string $raw, int $expectCustomerId = 0): ?array
    {
        $value = self::normalise($raw);

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        $parts = explode('.', $value);

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $customerId = self::decodeId($parts[1]);
        $employeeId = self::decodeId($parts[2]);

        if ($customerId === null || $employeeId === null) {
            return null;
        }

        if ($expectCustomerId > 0 && $customerId !== $expectCustomerId) {
            return null;
        }

        $expected = self::signature($customerId, $employeeId);

        if (!hash_equals($expected, strtolower($parts[3]))) {
            return null;
        }

        return ['customer_id' => $customerId, 'employee_id' => $employeeId];
    }

    /**
     * SHA-256 of the canonical badge.
     *
     * This is the ONLY form in which a badge is ever stored or transported in
     * bulk (the kiosk's offline list, the sync payload). The signature itself
     * stays on the paper.
     */
    public static function digest(string $raw): string
    {
        return hash('sha256', self::normalise($raw));
    }

    /** Digest of the badge of one employee - what the kiosk downloads. */
    public static function digestFor(int $customerId, int $employeeId): string
    {
        return self::digest(self::payload($customerId, $employeeId));
    }

    /**
     * Does this digest belong to the badge of that employee?
     *
     * Used on the offline path where only the digest travels: the server
     * re-derives the badge from the employee row, so a device cannot make up
     * an employee id it did not scan.
     */
    public static function digestMatches(int $customerId, int $employeeId, string $digest): bool
    {
        $digest = strtolower(trim($digest));

        if (preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
            return false;
        }

        return hash_equals(self::digestFor($customerId, $employeeId), $digest);
    }

    /* =================================================================
     * Internals
     * ===============================================================*/

    /**
     * UPPER CASE base36: keeps the whole payload inside the QR "alphanumeric"
     * character set (0-9, A-Z and a few punctuation marks), which produces a
     * noticeably smaller - and therefore more readable - symbol than the byte
     * mode lower case would force.
     */
    private static function encodeId(int $id): string
    {
        return strtoupper(base_convert((string) $id, 10, 36));
    }

    private static function decodeId(string $encoded): ?int
    {
        $encoded = strtoupper(trim($encoded));

        if ($encoded === '' || strlen($encoded) > 13) {
            return null;
        }

        if (preg_match('/^[0-9A-Z]+$/', $encoded) !== 1) {
            return null;
        }

        $value = base_convert($encoded, 36, 10);

        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function signature(int $customerId, int $employeeId): string
    {
        $message = 'sarmad.qr.v1|' . $customerId . '|' . $employeeId;

        return substr(hash_hmac('sha256', $message, self::key()), 0, self::SIGNATURE_LENGTH);
    }

    /**
     * The installation key. A missing key is a hard failure: a badge signed
     * with a fallback constant would be forgeable by anyone who reads this
     * file.
     */
    private static function key(): string
    {
        try {
            return Config::appKey();
        } catch (RuntimeException $error) {
            throw new RuntimeException('Cannot sign an employee badge: the installation key is missing.', 0, $error);
        }
    }
}
