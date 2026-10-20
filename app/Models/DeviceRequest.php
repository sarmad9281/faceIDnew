<?php
/**
 * SARMAD Attendance - device request idempotency (double submit guard).
 *
 * The device UI already blocks a second tap, but the CLIENT can never be the
 * authority: a retried request, a flaky connection or a hostile script can
 * send the same frame twice. Every engine call therefore carries a
 * `request_id` and this table makes the DATABASE the referee:
 *
 *   UNIQUE (device_id, request_key)
 *
 * claim() inserts the key first; a duplicate key means "this exact request was
 * already handled" and the caller must answer with DUPLICATE_REQUEST instead of
 * acting again.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;

final class DeviceRequest extends Model
{
    protected static string $table = 'device_requests';

    /**
     * Try to reserve a request key.
     *
     * @return bool true when this request is the first one (caller may act),
     *              false when it is a duplicate (caller must refuse).
     */
    public static function claim(int $deviceId, int $customerId, string $endpoint, string $requestKey): bool
    {
        $key = substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $requestKey) ?? '', 0, 64);

        if ($key === '') {
            // Without a key we can not guarantee anything, so we refuse instead
            // of silently allowing a possible duplicate.
            return false;
        }

        // Fast path: an obvious repeat (the normal case: a retried tap) is
        // caught without provoking a duplicate-key error in the query log.
        if (self::findClaim($deviceId, $key) !== null) {
            return false;
        }

        try {
            self::create([
                'device_id'   => $deviceId,
                'customer_id' => $customerId,
                'endpoint'    => substr($endpoint, 0, 60),
                'request_key' => $key,
                'status'      => 'accepted',
                'code'        => '',
            ]);

            return true;
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Record the final outcome code of an already claimed request.
     */
    public static function finish(int $deviceId, string $requestKey, string $code, bool $accepted = true): void
    {
        $key = substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $requestKey) ?? '', 0, 64);

        if ($key === '') {
            return;
        }

        Database::run(
            'UPDATE `' . self::tableName() . '` SET `status` = ?, `code` = ?'
            . ' WHERE `device_id` = ? AND `request_key` = ?',
            [$accepted ? 'accepted' : 'rejected', substr($code, 0, 40), $deviceId, $key]
        );
    }

    public static function findClaim(int $deviceId, string $requestKey): ?array
    {
        $key = substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $requestKey) ?? '', 0, 64);

        if ($key === '') {
            return null;
        }

        return Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `device_id` = ? AND `request_key` = ? LIMIT 1',
            [$deviceId, $key]
        );
    }

    /**
     * How many engine calls this device sent in the last N seconds
     * (abuse/spam visibility for the owner panel).
     */
    public static function recentCount(int $deviceId, int $seconds = 60): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `device_id` = ? AND `created_at` >= (NOW() - INTERVAL ? SECOND)',
            [$deviceId, max(1, $seconds)]
        );
    }

    public static function duplicateCount(int $deviceId, int $minutes = 60): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `device_id` = ? AND `status` = ? AND `created_at` >= (NOW() - INTERVAL ? MINUTE)',
            [$deviceId, 'rejected', max(1, $minutes)]
        );
    }

    /**
     * Housekeeping: keys older than a day are meaningless (a kiosk retry never
     * takes that long) so the table stays small on a shared host.
     */
    public static function prune(int $hours = 24): int
    {
        return (int) Database::run(
            'DELETE FROM `' . self::tableName() . '` WHERE `created_at` < (NOW() - INTERVAL ? HOUR)',
            [max(1, $hours)]
        )->rowCount();
    }
}
