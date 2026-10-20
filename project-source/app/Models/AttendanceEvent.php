<?php
/**
 * SARMAD Attendance - attendance events (stage 6, integration seam).
 *
 * Every attendance fact is written as a durable event row in the SAME
 * transaction as the record itself:
 *
 *     attendance.entry.created      attendance.exit.created
 *     attendance.late.detected      attendance.overtime.detected
 *     attendance.absence.detected   attendance.manual.corrected
 *     attendance.record.voided      attendance.day.recalculated
 *
 * THIS STAGE SENDS NOTHING. There is no Telegram call, no queue worker and no
 * outbound HTTP anywhere in the project - the next stage drains this table.
 * Because the row is written with the record, a notification can never be
 * "lost" once that stage exists.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Security;

final class AttendanceEvent extends Model
{
    protected static string $table = 'events';

    public const ENTRY_CREATED = 'attendance.entry.created';
    public const EXIT_CREATED = 'attendance.exit.created';
    public const LATE_DETECTED = 'attendance.late.detected';
    public const OVERTIME_DETECTED = 'attendance.overtime.detected';
    public const ABSENCE_DETECTED = 'attendance.absence.detected';
    public const MANUAL_CORRECTED = 'attendance.manual.corrected';
    public const RECORD_VOIDED = 'attendance.record.voided';
    public const DAY_RECALCULATED = 'attendance.day.recalculated';
    public const OFFLINE_SYNCED = 'attendance.offline.synced';
    public const LEAVE_RECORDED = 'attendance.leave.recorded';

    protected static array $fillable = [
        'uuid', 'customer_id', 'employee_id', 'device_id', 'record_id', 'event',
        'level', 'payload', 'status', 'attempts', 'available_at', 'sent_at', 'last_error',
    ];

    /**
     * Record one event. Called from inside the attendance transaction, so it
     * must not swallow errors: a failure here rolls the attendance back.
     *
     * @param array<string,mixed> $payload
     */
    public static function fire(
        int $customerId,
        string $event,
        array $payload = [],
        int $employeeId = 0,
        int $deviceId = 0,
        int $recordId = 0,
        string $level = 'info'
    ): int {
        if ($customerId <= 0) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');

        return Database::insert(self::tableName(), [
            'uuid'         => Security::uuid4(),
            'customer_id'  => $customerId,
            'employee_id'  => $employeeId > 0 ? $employeeId : null,
            'device_id'    => $deviceId > 0 ? $deviceId : null,
            'record_id'    => $recordId > 0 ? $recordId : null,
            'event'        => substr($event, 0, 60),
            'level'        => in_array($level, ['info', 'warning', 'critical'], true) ? $level : 'info',
            'payload'      => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status'       => 'pending',
            'attempts'     => 0,
            'available_at' => $now,
        ]);
    }

    /**
     * List the events of one customer - used by the owner/manager panel to
     * show that the seam is alive, and by the tests.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recentForCustomer(int $customerId, int $limit = 25, ?string $event = null): array
    {
        $sql    = 'SELECT * FROM `' . self::tableName() . '` WHERE `customer_id` = ?';
        $params = [$customerId];

        if ($event !== null && $event !== '') {
            $sql .= ' AND `event` = ?';
            $params[] = $event;
        }

        $sql .= ' ORDER BY `id` DESC LIMIT ' . max(1, min(200, $limit));

        return Database::select($sql, $params);
    }

    public static function countForCustomer(int $customerId, ?string $event = null): int
    {
        $sql    = 'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ?';
        $params = [$customerId];

        if ($event !== null && $event !== '') {
            $sql .= ' AND `event` = ?';
            $params[] = $event;
        }

        return (int) Database::scalar($sql, $params);
    }

    /** The dispatch window a later stage will use (nothing calls it yet). */
    public static function pendingBatch(int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM `' . self::tableName() . '`'
            . " WHERE `status` = 'pending' AND `available_at` <= ?"
            . ' ORDER BY `id` ASC LIMIT ' . max(1, min(200, $limit)),
            [date('Y-m-d H:i:s')]
        );
    }

    /** Is event delivery configured at all? (never true in this stage) */
    public static function dispatchEnabled(): bool
    {
        return (bool) Config::get('attendance.events_dispatch', false);
    }
}
