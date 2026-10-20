<?php
/**
 * SARMAD Attendance - recognition proofs (stage 6).
 *
 * The device recognises faces LOCALLY; the backend must never accept a bare
 * employee_id from a client. Instead the device reports the local result once
 * (/device/recognition-result) and receives a proof: a short lived, single use
 * token that is bound to
 *
 *     device + customer + employee (+ branch, + action, + model version)
 *
 * The attendance call then presents that proof, and the proof is consumed
 * inside the same database transaction that writes the record. A stolen or
 * replayed employee id is therefore useless: without a matching, unconsumed,
 * unexpired proof the attendance is rejected with INVALID_RECOGNITION.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Security;

final class RecognitionProof extends Model
{
    protected static string $table = 'recognition_proofs';

    protected static array $fillable = [
        'uuid', 'customer_id', 'device_id', 'employee_id', 'branch_id', 'action',
        'request_id', 'confidence', 'distance', 'model_version', 'issued_at',
        'expires_at', 'consumed_at', 'consumed_record_id', 'ip',
    ];

    /** Lifetime of a proof in seconds (configurable, sane default). */
    public static function ttl(): int
    {
        $ttl = (int) Config::get('attendance.proof_ttl_seconds', 120);

        return max(30, min(600, $ttl));
    }

    /**
     * Issue a proof. Called right after a local match was verified.
     *
     * @param array<string,mixed> $device
     * @return array{uuid:string,expires_at:string,ttl:int}
     */
    public static function issue(array $device, array $employee, string $action, array $meta = []): array
    {
        $now  = date('Y-m-d H:i:s');
        $uuid = Security::uuid4();
        $ttl  = self::ttl();
        $expires = date('Y-m-d H:i:s', strtotime($now) + $ttl);

        Database::insert(self::tableName(), [
            'uuid'          => $uuid,
            'customer_id'   => (int) $device['customer_id'],
            'device_id'     => (int) $device['id'],
            'employee_id'   => (int) $employee['id'],
            'branch_id'     => isset($device['branch_id']) ? (int) $device['branch_id'] : null,
            'action'        => in_array($action, ['entry', 'exit'], true) ? $action : 'any',
            'request_id'    => (string) ($meta['request_id'] ?? ''),
            'confidence'    => isset($meta['confidence']) && is_numeric($meta['confidence']) ? (float) $meta['confidence'] : null,
            'distance'      => isset($meta['distance']) && is_numeric($meta['distance']) ? (float) $meta['distance'] : null,
            'model_version' => substr((string) ($meta['model_version'] ?? ''), 0, 60),
            'issued_at'     => $now,
            'expires_at'    => $expires,
            'ip'            => substr((string) ($meta['ip'] ?? ''), 0, 45),
        ]);

        return ['uuid' => $uuid, 'expires_at' => $expires, 'ttl' => $ttl];
    }

    /**
     * Consume a proof - atomically. Returns the proof row when THIS call is the
     * one that consumed it, null otherwise (already used / expired / forged /
     * belongs to another device, customer, employee or action).
     *
     * @return array<string,mixed>|null
     */
    public static function consume(
        string $uuid,
        int $deviceId,
        int $customerId,
        int $employeeId,
        string $action
    ): ?array {
        if ($uuid === '') {
            return null;
        }

        $statement = Database::run(
            'UPDATE `' . self::tableName() . '` SET `consumed_at` = ?'
            . ' WHERE `uuid` = ? AND `device_id` = ? AND `customer_id` = ? AND `employee_id` = ?'
            . ' AND `consumed_at` IS NULL AND `expires_at` > ?'
            . ' AND (`action` = ? OR `action` = ?)',
            [date('Y-m-d H:i:s'), $uuid, $deviceId, $customerId, $employeeId, date('Y-m-d H:i:s'), $action, 'any']
        );

        if ($statement->rowCount() !== 1) {
            return null;
        }

        return Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `uuid` = ? LIMIT 1',
            [$uuid]
        );
    }

    /** Attach the created record id to the proof (traceability). */
    public static function markConsumedRecord(string $uuid, int $recordId): void
    {
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `consumed_record_id` = ? WHERE `uuid` = ?',
            [$recordId, $uuid]
        );
    }

    /** Housekeeping - called by the daily job, never on the request path. */
    public static function prune(int $olderThanHours = 48): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(1, $olderThanHours) . ' hours'));

        return Database::run(
            'DELETE FROM `' . self::tableName() . '` WHERE `expires_at` < ?',
            [$cutoff]
        )->rowCount();
    }

    /** Diagnostics for the owner panel / tests. */
    public static function stats(int $customerId): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN `consumed_at` IS NULL AND `expires_at` > ? THEN 1 ELSE 0 END) AS open,'
            . ' SUM(CASE WHEN `consumed_at` IS NOT NULL THEN 1 ELSE 0 END) AS used'
            . ' FROM `' . self::tableName() . '` WHERE `customer_id` = ?',
            [date('Y-m-d H:i:s'), $customerId]
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'open'  => (int) ($row['open'] ?? 0),
            'used'  => (int) ($row['used'] ?? 0),
        ];
    }
}
