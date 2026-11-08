<?php
/**
 * SARMAD Attendance - the attendance ENGINE (stage 6).
 *
 * This is the write side of attendance: entry, exit, the offline batch sync,
 * manual corrections, recalculation and the day-closing absence pass. Every
 * rule the project promised lives HERE, in the backend - the kiosk UI, the
 * manager panel and the API are only callers.
 *
 * Guarantees implemented in this file
 * -----------------------------------
 *  * the SERVER owns the clock: `entry_at`/`exit_at` are generated here, a
 *    timestamp sent by a client is never trusted as the record's time (for
 *    offline events the device time is stored SEPARATELY, validated and
 *    clamped, and the record is marked offline_synced);
 *  * a bare employee_id is worthless: a `recognition_proof` (short lived,
 *    single use, bound to device+customer+employee+action) must be presented
 *    and is consumed inside the same transaction as the write
 *    (INVALID_RECOGNITION otherwise);
 *  * duplicate protection on three levels: a pre-check inside the transaction,
 *    a row lock (SELECT ... FOR UPDATE) on the employee, and database unique
 *    keys (open_key / idempotency_key / device+local_event_id);
 *  * multi-session days: one employee may have several entry/exit pairs per
 *    day - nothing here assumes a single pair, and the day totals are sums;
 *  * tenant isolation: the customer comes from the device token, the employee
 *    is looked up inside that customer, the branch is validated;
 *  * soft delete / void - no attendance row is ever hard deleted;
 *  * every important change writes an audit row with old and new values and a
 *    durable attendance event row (the seam the next stage drains).
 *
 * Deliberately NOT in this file: liveness detection (not implemented by
 * project decision), Telegram sending (next stage), payroll calculation
 * (next stage - this engine only supplies the numbers).
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Security;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\DeviceLog;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\FaceEmbedding;
use App\Models\RecognitionProof;
use App\Models\Shift;
use DateTimeImmutable;
use PDOException;
use Throwable;

final class AttendanceEngine
{
    public const CODE_RECORDED = 'ATTENDANCE_RECORDED';
    public const CODE_ENTRY_OPEN = 'ENTRY_ALREADY_OPEN';
    public const CODE_NO_OPEN_ENTRY = 'NO_OPEN_ENTRY';
    public const CODE_EXIT_RECORDED = 'EXIT_ALREADY_RECORDED';
    public const CODE_INVALID_RECOGNITION = 'INVALID_RECOGNITION';
    public const CODE_FACE_NOT_REGISTERED = 'FACE_NOT_REGISTERED';
    public const CODE_EMPLOYEE_NOT_FOUND = 'EMPLOYEE_NOT_FOUND';
    public const CODE_EMPLOYEE_INACTIVE = 'EMPLOYEE_INACTIVE';
    public const CODE_INVALID_BRANCH = 'INVALID_BRANCH';
    public const CODE_DEVICE_INACTIVE = 'DEVICE_INACTIVE';
    public const CODE_DUPLICATE = 'DUPLICATE_REQUEST';
    public const CODE_RATE_LIMITED = 'RATE_LIMITED';
    public const CODE_OFFLINE_INVALID = 'OFFLINE_EVENT_INVALID';
    public const CODE_REJECTED = 'ATTENDANCE_REJECTED';
    public const CODE_NO_SHIFT = 'NO_SHIFT_FOR_DAY';
    public const CODE_NOT_FOUND = 'RECORD_NOT_FOUND';

    private FaceDatasetService $faces;

    public function __construct(?FaceDatasetService $faces = null)
    {
        $this->faces = $faces ?? new FaceDatasetService();
    }

    /* =================================================================
     * Device API - entry / exit
     * ===============================================================*/

    /**
     * Record an ENTRY for the employee the device just recognised.
     *
     * @param array<string,mixed> $device  device row of the authenticated token
     * @param array<string,mixed> $payload request payload
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>,http:int}
     */
    public function recordEntry(array $device, array $payload): array
    {
        $result = $this->record($device, $payload, 'entry');
        $this->logDeviceAttempt($device, 'entry', $result, $payload);

        return $result;
    }

    /**
     * An event that the device RECOGNISED AND QUEUED while it had no
     * connection, pushed later by /attendance/sync.
     *
     * There can be no server issued proof for it (the proof lives minutes and
     * is created by the server), so the tie is different and stricter:
     *   - the authenticated DEVICE is the one that queued it,
     *   - the payload must carry the local RECOGNITION RESULT (employee id +
     *     distance), which is re-validated here against the threshold,
     *   - the employee must belong to this customer, be active and have a
     *     complete 3/3 face dataset,
     *   - the occurrence time is validated and clamped against the server
     *     clock (a device can not write tomorrow's attendance),
     *   - the row is marked device_offline + offline_synced and shows up as
     *     such in every report.
     */
    public function recordOfflineEvent(array $device, array $payload, string $action): array
    {
        $payload = $payload + ['offline' => true];
        $result  = $this->record($device, $payload, $action, true);

        // A queued event is an attempt of this device exactly like an online
        // punch: it must show up on the device page (device_logs) with its
        // outcome, so the offline history is complete too.
        $this->logDeviceAttempt($device, $action, $result, $payload);

        return $result;
    }

    /**
     * Record an EXIT - the open session of that employee is closed.
     */
    public function recordExit(array $device, array $payload): array
    {
        $result = $this->record($device, $payload, 'exit');
        $this->logDeviceAttempt($device, 'exit', $result, $payload);

        return $result;
    }

    /**
     * The one code path both actions use. `$action` is 'entry' or 'exit' and
     * is decided by the ROUTE, never by a client field.
     */
    private function record(array $device, array $payload, string $action, bool $offline = false): array
    {
        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];
        $branchId   = isset($device['branch_id']) ? (int) $device['branch_id'] : 0;

        // ---- 0. shape of the request ---------------------------------
        $requestId = trim((string) ($payload['request_id'] ?? ''));

        if (preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $requestId) !== 1) {
            return $this->fail('INVALID_REQUEST_ID', 'شناسه درخواست (request_id) لازم است.', 422);
        }

        $proofUuid = trim((string) ($payload['proof'] ?? $payload['recognition_proof'] ?? ''));

        if ($proofUuid === '' && !$offline) {
            $this->denied($customerId, $deviceId, 'attendance.proof.missing', ['request_id' => $requestId, 'action' => $action]);

            return $this->fail(self::CODE_INVALID_RECOGNITION,
                'شناسایی معتبر برای این درخواست ثبت نشده است. لطفاً دوباره مقابل دوربین قرار بگیرید.', 409);
        }

        $idempotency = $this->idempotencyKey($deviceId, $payload, $requestId);

        $rate = $this->rateCheck($customerId, $deviceId, (int) ($payload['employee_id'] ?? 0), $action);

        if (!$rate['ok']) {
            return $this->fail(self::CODE_RATE_LIMITED, 'تعداد درخواست‌های این دستگاه بیش از حد مجاز است. لطفاً کمی صبر کنید.', 429, $rate['data']);
        }

        try {
            return $this->runInTransaction($device, $payload, $action, $proofUuid, $idempotency, $requestId, $offline);
        } catch (PDOException $error) {
            return $this->mapDuplicate($error, $action);
        } catch (Throwable $error) {
            Logger::channel('attendance')->error('Attendance failed', [
                'device_id' => $deviceId,
                'action'    => $action,
                'message'   => $error->getMessage(),
            ]);

            return $this->fail(self::CODE_REJECTED, 'ثبت حضور انجام نشد. لطفاً دوباره تلاش کنید.', 500);
        }
    }

    /**
     * Everything that must be atomic: validation, proof consumption, the
     * duplicate check, the write, the audit trail and the event row.
     *
     * @return array<string,mixed>
     */
    private function runInTransaction(array $device, array $payload, string $action, string $proofUuid, string $idempotency, string $requestId, bool $offline = false): array
    {
        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];
        $branchId   = isset($device['branch_id']) ? (int) $device['branch_id'] : 0;

        return Database::transaction(function () use ($device, $payload, $action, $proofUuid, $idempotency, $requestId, $deviceId, $customerId, $branchId, $offline) {
            // ---- 1. idempotency: has this exact request already produced a row?
            $existing = AttendanceRecord::byIdempotency($customerId, $idempotency);

            if ($existing !== null) {
                return $this->ok($action === 'entry' ? self::CODE_RECORDED : self::CODE_RECORDED,
                    $action === 'entry' ? 'ورود شما قبلاً ثبت شده است.' : 'خروج شما قبلاً ثبت شده است.',
                    $this->resultData($existing, $action, true));
            }

            // ---- 2. the device must still be the same active device
            $fresh = Database::selectOne(
                'SELECT * FROM `' . Database::table('devices') . '` WHERE `id` = ? LIMIT 1 FOR UPDATE',
                [$deviceId]
            );

            if ($fresh === null || (string) $fresh['status'] !== 'active' || (int) $fresh['customer_id'] !== $customerId) {
                return $this->fail(self::CODE_DEVICE_INACTIVE, 'این دستگاه فعال نیست.', 403);
            }

            // ---- 3. where does the employee id come from? ------------------
            $proof = null;

            if ($offline) {
                // Queued event: no server proof exists by definition. The local
                // RECOGNITION RESULT travels with the queue item and is
                // validated here - a queue item without a match distance is
                // refused, so a bare employee_id is still not enough.
                $employeeId = (int) ($payload['employee_id'] ?? 0);

                if ($employeeId <= 0) {
                    return $this->fail(self::CODE_OFFLINE_INVALID, 'رویداد آفلاین شناسه پرسنل ندارد.', 422);
                }

                $distance = $payload['distance'] ?? null;
                $threshold = FaceDatasetService::threshold();

                if (!is_numeric($distance)) {
                    $this->denied($customerId, $deviceId, 'attendance.offline.no_result', ['employee_id' => $employeeId]);

                    return $this->fail(self::CODE_OFFLINE_INVALID,
                        'رویداد آفلاین، نتیجه شناسایی محلی (فاصله) را همراه ندارد.', 422);
                }

                if ((float) $distance > $threshold) {
                    $this->denied($customerId, $deviceId, 'attendance.offline.weak_match', [
                        'employee_id' => $employeeId,
                        'distance'    => round((float) $distance, 4),
                    ]);

                    return $this->fail(self::CODE_INVALID_RECOGNITION,
                        'نتیجه شناسایی محلی این رویداد در محدوده مجاز نیست.', 422);
                }
            } else {
                $proof = Database::selectOne(
                    'SELECT * FROM `' . Database::table('recognition_proofs') . '`'
                    . ' WHERE `uuid` = ? AND `device_id` = ? AND `customer_id` = ? LIMIT 1',
                    [$proofUuid, $deviceId, $customerId]
                );

                if ($proof === null) {
                    $this->denied($customerId, $deviceId, 'attendance.proof.unknown', ['proof' => substr($proofUuid, 0, 8)]);

                    return $this->fail(self::CODE_INVALID_RECOGNITION,
                        'شناسایی این درخواست معتبر نیست. لطفاً دوباره مقابل دوربین قرار بگیرید.', 409);
                }

                $employeeId = (int) $proof['employee_id'];

                // A client may send employee_id, but it can never OVERRIDE the
                // one the proof was issued for - only confirm it.
                $claimed = (int) ($payload['employee_id'] ?? 0);

                if ($claimed > 0 && $claimed !== $employeeId) {
                    $this->denied($customerId, $deviceId, 'attendance.employee.spoof', [
                        'claimed' => $claimed,
                        'proof'   => $employeeId,
                    ]);

                    return $this->fail(self::CODE_INVALID_RECOGNITION,
                        'شناسه پرسنل با نتیجه شناسایی هم‌خوانی ندارد.', 409);
                }
            }

            $employee = Database::selectOne(
                'SELECT * FROM `' . Database::table('employees') . '`'
                . ' WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1 FOR UPDATE',
                [$employeeId, $customerId]
            );

            if ($employee === null) {
                return $this->fail(self::CODE_EMPLOYEE_NOT_FOUND, 'این پرسنل متعلق به این مجموعه نیست.', 404);
            }

            if (!Employee::canAttend($employee)) {
                $this->denied($customerId, $deviceId, 'attendance.employee.inactive', ['employee_id' => $employeeId]);

                return $this->fail(self::CODE_EMPLOYEE_INACTIVE, 'این پرسنل غیرفعال است و ثبت حضور انجام نمی‌شود.', 403);
            }

            // ---- 4. branch rule (only when the branches feature is on)
            $employeeBranch = isset($employee['branch_id']) ? (int) $employee['branch_id'] : 0;

            if ($branchId > 0 && $employeeBranch > 0 && $branchId !== $employeeBranch) {
                $this->denied($customerId, $deviceId, 'attendance.branch.mismatch', [
                    'device_branch'   => $branchId,
                    'employee_branch' => $employeeBranch,
                ]);

                return $this->fail(self::CODE_INVALID_BRANCH, 'شعبه این پرسنل با شعبه دستگاه هم‌خوانی ندارد.', 409);
            }

            // ---- 5. the face registration must still be complete (3/3)
            $faceState = $this->faces->stateForEmployee($employeeId, $customerId);

            if ((int) $faceState['count'] !== FaceEmbedding::SAMPLES
                || (string) $faceState['model_version'] !== FaceDatasetService::modelVersion()) {
                return $this->fail(self::CODE_FACE_NOT_REGISTERED,
                    'ثبت چهره این کارمند کامل نیست (' . (int) $faceState['count'] . '/3)؛ ثبت حضور ممکن نیست.', 403, [
                        'samples'  => (int) $faceState['count'],
                        'required' => FaceEmbedding::SAMPLES,
                    ]);
            }

            // ---- 6. consume the proof (single use, device bound, action bound)
            $consumed = $offline ? [] : RecognitionProof::consume($proofUuid, $deviceId, $customerId, $employeeId, $action);

            if ($consumed === null) {
                $this->denied($customerId, $deviceId, 'attendance.proof.replayed', ['proof' => substr($proofUuid, 0, 8), 'action' => $action]);

                return $this->fail(self::CODE_INVALID_RECOGNITION,
                    'این شناسایی قبلاً استفاده شده است. لطفاً دوباره مقابل دوربین قرار بگیرید.', 409);
            }

            // ---- 7. the event time: server clock (or validated device clock)
            $time = $this->resolveEventTime($payload, $action);

            if (!$time['ok']) {
                return $this->fail($time['code'], $time['message'], $time['http']);
            }

            $now       = $time['server_time'];
            $eventAt   = $time['event_at'];
            $date      = $time['date'];
            $offline   = $time['offline'];
            $skew      = $time['skew'];

            // ---- 8. the action itself
            if ($action === 'entry') {
                return $this->insertEntry($device, $employee, $now, $eventAt, $date, $payload, $idempotency, $proofUuid, $proof, $offline, $skew, $requestId);
            }

            return $this->closeSession($device, $employee, $now, $eventAt, $payload, $idempotency, $proofUuid, $proof, $offline, $skew, $requestId);
        });
    }

    /**
     * ENTRY: refuse when a session of this employee is still open, otherwise
     * open a new session (session_index = count of that day + 1).
     *
     * @return array<string,mixed>
     */
    private function insertEntry(
        array $device,
        array $employee,
        string $now,
        string $eventAt,
        string $date,
        array $payload,
        string $idempotency,
        string $proofUuid,
        ?array $proof,
        bool $offline,
        bool $skew,
        string $requestId
    ): array {
        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];
        $employeeId = (int) $employee['id'];
        $branchId   = isset($device['branch_id']) ? (int) $device['branch_id'] : 0;

        $open = AttendanceRecord::openSessionFor($customerId, $employeeId);

        if ($open !== null) {
            $this->log('attendance.entry.duplicate', $customerId, $deviceId, $employeeId, [
                'request_id' => $requestId,
                'code'       => self::CODE_ENTRY_OPEN,
            ]);

            return $this->fail(self::CODE_ENTRY_OPEN, 'ورود شما قبلاً ثبت شده است.', 409, [
                'record_id' => (int) $open['id'],
                'entry_at'  => (string) $open['entry_at'],
                'employee'  => $this->employeeBrief($employee),
            ]);
        }

        $shift    = ShiftResolver::resolve($employeeId, $customerId, $date);
        $context  = $this->dayContext($customerId, $employeeId, $date, $branchId);
        $calc     = AttendanceCalculator::calculateSession(['entry_at' => $eventAt, 'exit_at' => null], $shift, $context, $date);
        $sessions = AttendanceRecord::sessionsOn($customerId, $employeeId, $date);

        $flags = $calc['flags'];

        if ($offline) {
            $flags[] = AttendanceCalculator::FLAG_OFFLINE;
        }

        if ($skew) {
            $flags[] = AttendanceCalculator::FLAG_CLOCK_SKEW;
        }

        // An open session is «ناقص» - unless the day itself is a holiday or an
        // approved leave day, which keeps its own status (the incomplete flag
        // is still set, so nothing is hidden).
        $openStatus = in_array((string) $calc['status'],
            [AttendanceCalculator::STATUS_HOLIDAY, AttendanceCalculator::STATUS_ON_LEAVE], true)
            ? (string) $calc['status']
            : AttendanceCalculator::STATUS_INCOMPLETE;

        $source = $offline ? 'device_offline' : 'device';
        $sessionIndex = AttendanceRecord::nextSessionIndex($customerId, $employeeId, $date);

        $recordId = Database::insert(Database::table('attendance_records'), [
            'uuid'             => Security::uuid4(),
            'customer_id'      => $customerId,
            'employee_id'      => $employeeId,
            'branch_id'        => $branchId > 0 ? $branchId : null,
            'device_id'        => $deviceId,
            'shift_id'         => $shift !== null ? (int) $shift['id'] : null,
            'attendance_date'  => $date,
            'entry_at'         => $eventAt,
            'exit_at'          => null,
            'work_minutes'     => 0,
            'late_minutes'     => (int) $calc['late_minutes'],
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'status'           => $openStatus,
            'flags'            => $this->flagsToString($flags),
            'open_key'         => $employeeId,
            'session_index'    => $sessionIndex,
            'idempotency_key'  => $idempotency,
            'local_event_id'   => $this->localEventId($payload),
            'device_event_at'  => $offline ? $eventAt : null,
            'server_received_at' => $now,
            'recognition_uuid' => $proofUuid,
            'shift_snapshot'   => $shift === null ? null : json_encode(ShiftResolver::snapshot($shift), JSON_UNESCAPED_UNICODE),
            'calc_version'     => AttendanceCalculator::CALC_VERSION,
            'last_event'       => 'entry',
            'source'           => $source,
            'match_score'      => $offline
                ? (isset($payload['distance']) && is_numeric($payload['distance']) ? round(1 - (float) $payload['distance'], 4) : null)
                : (isset($proof['confidence']) ? $proof['confidence'] : null),
            'meta'             => json_encode([
                'request_id'    => $requestId,
                'session_index' => $sessionIndex,
                'sessions_before' => count($sessions),
                'offline'       => $offline,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if (!$offline) {
            RecognitionProof::markConsumedRecord($proofUuid, $recordId);
        }

        $record = AttendanceRecord::find($recordId) ?? [];

        AttendanceEvent::fire($customerId, AttendanceEvent::ENTRY_CREATED, [
            'record_id'   => $recordId,
            'employee_id' => $employeeId,
            'entry_at'    => $eventAt,
            'late_minutes' => (int) $calc['late_minutes'],
            'status'      => $openStatus,
            'session_index' => $sessionIndex,
            'source'      => $source,
        ], $employeeId, $deviceId, $recordId, 'info');

        if ((int) $calc['late_minutes'] > 0) {
            AttendanceEvent::fire($customerId, AttendanceEvent::LATE_DETECTED, [
                'record_id'    => $recordId,
                'employee_id'  => $employeeId,
                'late_minutes' => (int) $calc['late_minutes'],
            ], $employeeId, $deviceId, $recordId, 'warning');
        }

        if ($offline) {
            AttendanceEvent::fire($customerId, AttendanceEvent::OFFLINE_SYNCED, [
                'record_id' => $recordId,
                'entry_at'  => $eventAt,
            ], $employeeId, $deviceId, $recordId, 'info');
        }

        AuditLog::record('attendance.entry.created', 'success',
            'ورود «' . Employee::fullName($employee) . '» ثبت شد.',
            [
                'record_id'    => $recordId,
                'employee_id'  => $employeeId,
                'device_id'    => $deviceId,
                'entry_at'     => $eventAt,
                'late_minutes' => (int) $calc['late_minutes'],
                'source'       => $source,
            ],
            'attendance', 'attendance_record', $recordId, $customerId);

        return $this->ok(self::CODE_RECORDED, 'ورود با موفقیت ثبت شد.', $this->resultData($record, 'entry', false) + [
            'proof' => $proofUuid,
        ]);
    }

    /**
     * EXIT: close the OLDEST open session of that employee.
     *
     * @return array<string,mixed>
     */
    private function closeSession(
        array $device,
        array $employee,
        string $now,
        string $eventAt,
        array $payload,
        string $idempotency,
        string $proofUuid,
        ?array $proof,
        bool $offline,
        bool $skew,
        string $requestId
    ): array {
        $deviceId   = (int) $device['id'];
        $customerId = (int) $device['customer_id'];
        $employeeId = (int) $employee['id'];

        $open = AttendanceRecord::openSessionFor($customerId, $employeeId, true);

        if ($open === null) {
            $this->log('attendance.exit.without_entry', $customerId, $deviceId, $employeeId, [
                'request_id' => $requestId,
            ]);

            return $this->fail(self::CODE_NO_OPEN_ENTRY, 'برای شما ورود فعالی ثبت نشده است.', 409, [
                'employee' => $this->employeeBrief($employee),
            ]);
        }

        if (strtotime($eventAt) < strtotime((string) $open['entry_at'])) {
            // A clock that goes backwards must not create a negative session.
            $this->log('attendance.exit.before_entry', $customerId, $deviceId, $employeeId, [
                'entry_at' => (string) $open['entry_at'],
                'exit_at'  => $eventAt,
            ]);

            return $this->fail(self::CODE_REJECTED,
                'زمان ثبت‌شده از زمان ورود عقب‌تر است. لطفاً دوباره تلاش کنید.', 422);
        }

        $date  = (string) $open['attendance_date'];
        $shift = ShiftResolver::fromSnapshot(
            $open['shift_snapshot'] !== null ? json_decode((string) $open['shift_snapshot'], true) : null
        ) ?? ShiftResolver::resolve($employeeId, $customerId, $date);

        $context = $this->dayContext($customerId, $employeeId, $date, (int) ($open['branch_id'] ?? 0));

        $sessions = AttendanceRecord::sessionsOn($customerId, $employeeId, $date);
        $others   = array_values(array_filter($sessions, static fn (array $row): bool => (int) $row['id'] !== (int) $open['id']));

        $closed    = ['entry_at' => (string) $open['entry_at'], 'exit_at' => $eventAt];
        $dayTotal  = AttendanceCalculator::aggregateDay(array_merge($others, [$closed]), $shift, $context, $date);

        $flags = $dayTotal['flags'];

        if ($offline) {
            $flags[] = AttendanceCalculator::FLAG_OFFLINE;
        }

        if ($skew) {
            $flags[] = AttendanceCalculator::FLAG_CLOCK_SKEW;
        }

        Database::run(
            'UPDATE `' . Database::table('attendance_records') . '`'
            . ' SET `exit_at` = ?, `work_minutes` = ?, `late_minutes` = ?, `early_leave_minutes` = ?,'
            . ' `overtime_minutes` = ?, `status` = ?, `flags` = ?, `open_key` = NULL,'
            . ' `idempotency_key` = ?, `local_exit_event_id` = ?, `device_exit_at` = ?, `server_exit_received_at` = ?,'
            . ' `recognition_uuid` = ?, `shift_snapshot` = ?, `calc_version` = ?, `last_event` = ?'
            . ' WHERE `id` = ? AND `customer_id` = ? AND `open_key` IS NOT NULL',
            [
                $eventAt,
                (int) $dayTotal['work_minutes'],
                (int) $dayTotal['late_minutes'],
                (int) $dayTotal['early_leave_minutes'],
                (int) $dayTotal['overtime_minutes'],
                (string) $dayTotal['status'],
                $this->flagsToString($flags),
                $idempotency,
                $this->localEventId($payload),
                $offline ? $eventAt : null,
                $now,
                $proofUuid,
                $shift === null ? null : json_encode(ShiftResolver::snapshot($shift), JSON_UNESCAPED_UNICODE),
                AttendanceCalculator::CALC_VERSION,
                'exit',
                (int) $open['id'],
                $customerId,
            ]
        );

        if (!$offline) {
            RecognitionProof::markConsumedRecord($proofUuid, (int) $open['id']);
        }

        $record = AttendanceRecord::find((int) $open['id']) ?? [];

        AttendanceEvent::fire($customerId, AttendanceEvent::EXIT_CREATED, [
            'record_id'    => (int) $open['id'],
            'employee_id'  => $employeeId,
            'exit_at'      => $eventAt,
            'work_minutes' => (int) $dayTotal['work_minutes'],
            'status'       => (string) $dayTotal['status'],
        ], $employeeId, $deviceId, (int) $open['id'], 'info');

        if ((int) $dayTotal['overtime_minutes'] > 0) {
            AttendanceEvent::fire($customerId, AttendanceEvent::OVERTIME_DETECTED, [
                'record_id'        => (int) $open['id'],
                'employee_id'      => $employeeId,
                'overtime_minutes' => (int) $dayTotal['overtime_minutes'],
            ], $employeeId, $deviceId, (int) $open['id'], 'info');
        }

        AuditLog::record('attendance.exit.created', 'success',
            'خروج «' . Employee::fullName($employee) . '» ثبت شد.',
            [
                'record_id'    => (int) $open['id'],
                'employee_id'  => $employeeId,
                'device_id'    => $deviceId,
                'exit_at'      => $eventAt,
                'work_minutes' => (int) $dayTotal['work_minutes'],
                'status'       => (string) $dayTotal['status'],
            ],
            'attendance', 'attendance_record', (int) $open['id'], $customerId);

        return $this->ok(self::CODE_RECORDED, 'خروج با موفقیت ثبت شد.', $this->resultData($record, 'exit', false) + [
            'proof' => $proofUuid,
        ]);
    }

    /* =================================================================
     * Offline batch sync
     * ===============================================================*/

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload ['events' => [ ... ]]
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>,http:int}
     */
    public function syncBatch(array $device, array $payload): array
    {
        $events = $payload['events'] ?? null;

        if (!is_array($events) || $events === []) {
            return $this->fail(self::CODE_OFFLINE_INVALID, 'هیچ رویداد آفلاینی برای هم‌گام‌سازی ارسال نشده است.', 422);
        }

        $limit = max(1, min(100, (int) Config::get('attendance.sync_batch_limit', 50)));

        if (count($events) > $limit) {
            return $this->fail(self::CODE_OFFLINE_INVALID,
                'تعداد رویدادهای این دسته بیش از حد مجاز است (حداکثر ' . $limit . ').', 422);
        }

        $results = [
            'accepted'  => 0,
            'duplicate' => 0,
            'rejected'  => 0,
            'invalid'   => 0,
            'records'   => [],
        ];

        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                $results['invalid']++;
                $results['records'][] = ['index' => $index, 'status' => 'INVALID', 'code' => self::CODE_OFFLINE_INVALID, 'message' => 'ساختار رویداد نامعتبر است.'];

                continue;
            }

            $type = strtolower(trim((string) ($event['type'] ?? $event['action'] ?? '')));

            if (!in_array($type, ['entry', 'exit'], true)) {
                $results['invalid']++;
                $results['records'][] = [
                    'index' => $index,
                    'local_event_id' => (string) ($event['local_event_id'] ?? ''),
                    'status' => 'INVALID',
                    'code'   => self::CODE_OFFLINE_INVALID,
                    'message' => 'نوع رویداد باید entry یا exit باشد.',
                ];

                continue;
            }

            $localId = trim((string) ($event['local_event_id'] ?? ''));

            if (preg_match('/^[A-Za-z0-9._\-]{6,64}$/', $localId) !== 1) {
                $results['invalid']++;
                $results['records'][] = ['index' => $index, 'status' => 'INVALID', 'code' => self::CODE_OFFLINE_INVALID, 'message' => 'شناسه محلی رویداد نامعتبر است.'];

                continue;
            }

            $derivedKey = $this->idempotencyKey((int) $device['id'], ['local_event_id' => $localId], 'offline');

            // Replay of the same queue item -> DUPLICATE, never a second row.
            // Checked on the local id of both the opening and the closing
            // event, and on the derived idempotency key - three independent
            // guards, all backed by a UNIQUE index in the database.
            if (AttendanceRecord::byDeviceLocalEvent((int) $device['id'], $localId) !== null
                || AttendanceRecord::byIdempotency((int) $device['customer_id'], $derivedKey) !== null) {
                $results['duplicate']++;
                $results['records'][] = [
                    'index' => $index,
                    'local_event_id' => $localId,
                    'status' => 'DUPLICATE',
                    'code'   => self::CODE_DUPLICATE,
                    'message' => 'این رویداد قبلاً هم‌گام شده است.',
                ];

                continue;
            }

            $eventPayload = [
                'request_id'      => 'offline-' . substr($localId, 0, 40),
                'proof'           => (string) ($event['proof'] ?? ''),
                'employee_id'     => (int) ($event['employee_id'] ?? 0),
                'distance'        => $event['distance'] ?? null,
                'confidence'      => $event['confidence'] ?? null,
                'model_version'   => (string) ($event['model_version'] ?? ''),
                'local_event_id'  => $localId,
                'offline'         => true,
                'occurred_at'     => (string) ($event['occurred_at'] ?? ''),
                'idempotency_key' => $derivedKey,
            ];

            // A queued event can not carry a server proof (the proof only
            // exists for seconds and is issued by the server); it carries the
            // local recognition result instead. If a proof IS present it is
            // used, which makes both paths equally strict.
            $outcome = $eventPayload['proof'] !== ''
                ? ($type === 'entry'
                    ? $this->recordEntry($device, $eventPayload)
                    : $this->recordExit($device, $eventPayload))
                : $this->recordOfflineEvent($device, $eventPayload, $type);

            $duplicate = $outcome['ok'] && !empty($outcome['data']['duplicate']);
            $status    = $outcome['ok'] ? ($duplicate ? 'DUPLICATE' : 'ACCEPTED') : 'REJECTED';

            if ($duplicate) {
                $results['duplicate']++;
            } elseif ($outcome['ok']) {
                $results['accepted']++;
            } else {
                $results['rejected']++;
            }

            $results['records'][] = [
                'index'          => $index,
                'local_event_id' => $localId,
                'type'           => $type,
                'status'         => $status,
                'code'           => $outcome['code'],
                'message'        => $outcome['message'],
                'record_id'      => $outcome['data']['record_id'] ?? null,
                'attendance_date' => $outcome['data']['attendance_date'] ?? null,
            ];
        }

        return $this->ok('OFFLINE_SYNC_DONE', 'هم‌گام‌سازی رویدادهای آفلاین انجام شد.', $results);
    }

    /* =================================================================
     * Manager side: manual correction, void, recalculate, absence
     * ===============================================================*/

    /**
     * Manual entry/exit pair created or corrected by a manager. Always audited
     * with the old and the new value and a mandatory reason.
     *
     * @param array<string,mixed> $actor    ['id'=>int,'role'=>string]
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function manualCreate(array $actor, int $customerId, array $input): array
    {
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $date       = (string) ($input['attendance_date'] ?? '');
        $reason     = trim((string) ($input['reason'] ?? ''));
        $entryAt    = AttendanceCalculator::normalizeDateTime($input['entry_at'] ?? null);
        $exitAt     = AttendanceCalculator::normalizeDateTime($input['exit_at'] ?? null);

        if ($employeeId <= 0 || $date === '' || $reason === '') {
            return $this->fail(self::CODE_REJECTED, 'پرسنل، تاریخ و دلیل اصلاح الزامی است.', 422);
        }

        if ($entryAt === null) {
            return $this->fail(self::CODE_REJECTED, 'زمان ورود الزامی است.', 422);
        }

        if ($exitAt !== null && strtotime($exitAt) <= strtotime($entryAt)) {
            return $this->fail(self::CODE_REJECTED, 'زمان خروج باید بعد از زمان ورود باشد.', 422);
        }

        $employee = Employee::findInCustomer($employeeId, $customerId);

        if ($employee === null) {
            return $this->fail(self::CODE_EMPLOYEE_NOT_FOUND, 'این پرسنل متعلق به این مجموعه نیست.', 404);
        }

        $shift   = ShiftResolver::resolve($employeeId, $customerId, $date);
        $context = $this->dayContext($customerId, $employeeId, $date, 0) + ['manual' => true];
        $calc    = AttendanceCalculator::calculateSession(['entry_at' => $entryAt, 'exit_at' => $exitAt], $shift, $context, $date);
        $sessions = AttendanceRecord::sessionsOn($customerId, $employeeId, $date);

        if ($exitAt === null && AttendanceRecord::openSessionFor($customerId, $employeeId) !== null) {
            return $this->fail(self::CODE_ENTRY_OPEN, 'برای این پرسنل از قبل یک ورود باز وجود دارد.', 409);
        }

        try {
            $recordId = Database::insert(Database::table('attendance_records'), [
                'uuid'            => Security::uuid4(),
                'customer_id'     => $customerId,
                'employee_id'     => $employeeId,
                'branch_id'       => isset($employee['branch_id']) ? (int) $employee['branch_id'] : null,
                'device_id'       => null,
                'shift_id'        => $shift !== null ? (int) $shift['id'] : null,
                'attendance_date' => $date,
                'entry_at'        => $entryAt,
                'exit_at'         => $exitAt,
                'work_minutes'    => (int) $calc['work_minutes'],
                'late_minutes'    => (int) $calc['late_minutes'],
                'early_leave_minutes' => (int) $calc['early_leave_minutes'],
                'overtime_minutes' => (int) $calc['overtime_minutes'],
                'status'          => $exitAt === null ? AttendanceCalculator::STATUS_INCOMPLETE : (string) $calc['status'],
                'flags'           => $this->flagsToString(array_merge($calc['flags'], [AttendanceCalculator::FLAG_MANUAL])),
                'open_key'        => $exitAt === null ? $employeeId : null,
                'session_index'   => count($sessions) + 1,
                'server_received_at' => date('Y-m-d H:i:s'),
                'shift_snapshot'  => $shift === null ? null : json_encode(ShiftResolver::snapshot($shift), JSON_UNESCAPED_UNICODE),
                'calc_version'    => AttendanceCalculator::CALC_VERSION,
                'last_event'      => $exitAt === null ? 'entry' : 'exit',
                'modified_by'     => (int) ($actor['id'] ?? 0),
                'modify_reason'   => $reason,
                'source'          => 'manual',
            ]);
        } catch (PDOException $error) {
            if (strpos($error->getMessage(), 'uniq_records_open') !== false) {
                return $this->fail(self::CODE_ENTRY_OPEN, 'برای این پرسنل از قبل یک ورود باز وجود دارد.', 409);
            }

            throw $error;
        }

        $record = AttendanceRecord::find($recordId) ?? [];

        AttendanceEvent::fire($customerId, AttendanceEvent::MANUAL_CORRECTED, [
            'record_id' => $recordId,
            'action'    => 'create',
            'reason'    => $reason,
        ], $employeeId, 0, $recordId, 'warning');

        AuditLog::record('attendance.manual.created', 'success',
            'ثبت دستی حضور برای «' . Employee::fullName($employee) . '».',
            [
                'record_id' => $recordId,
                'new'       => ['entry_at' => $entryAt, 'exit_at' => $exitAt, 'status' => (string) $calc['status']],
                'reason'    => $reason,
                'actor'     => $actor,
            ],
            'attendance', 'attendance_record', $recordId, $customerId);

        return $this->ok(self::CODE_RECORDED, 'ثبت دستی حضور انجام شد.', $this->resultData($record, 'manual', false));
    }

    /**
     * Correct an existing record (times / status). Old and new values are both
     * written to the audit log; the record keeps its uuid and its history.
     *
     * @return array<string,mixed>
     */
    public function correctRecord(array $actor, int $customerId, int $recordId, array $input): array
    {
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($reason === '') {
            return $this->fail(self::CODE_REJECTED, 'دلیل اصلاح الزامی است.', 422);
        }

        $record = AttendanceRecord::findInCustomer($recordId, $customerId);

        if ($record === null) {
            return $this->fail(self::CODE_NOT_FOUND, 'رکورد حضور یافت نشد.', 404);
        }

        $before = [
            'entry_at' => $record['entry_at'],
            'exit_at'  => $record['exit_at'],
            'status'   => (string) $record['status'],
        ];

        $entryAt = array_key_exists('entry_at', $input)
            ? AttendanceCalculator::normalizeDateTime($input['entry_at'])
            : AttendanceCalculator::normalizeDateTime($record['entry_at']);

        $exitAt = array_key_exists('exit_at', $input)
            ? AttendanceCalculator::normalizeDateTime($input['exit_at'])
            : AttendanceCalculator::normalizeDateTime($record['exit_at']);

        if ($entryAt === null) {
            return $this->fail(self::CODE_REJECTED, 'زمان ورود نامعتبر است.', 422);
        }

        if ($exitAt !== null && strtotime($exitAt) <= strtotime($entryAt)) {
            return $this->fail(self::CODE_REJECTED, 'زمان خروج باید بعد از زمان ورود باشد.', 422);
        }

        $employeeId = (int) $record['employee_id'];
        $date       = (string) $record['attendance_date'];

        $shift   = ShiftResolver::fromSnapshot(
            $record['shift_snapshot'] !== null ? json_decode((string) $record['shift_snapshot'], true) : null
        ) ?? ShiftResolver::resolve($employeeId, $customerId, $date);

        $context = $this->dayContext($customerId, $employeeId, $date, (int) ($record['branch_id'] ?? 0)) + ['manual' => true];

        $sessions = AttendanceRecord::sessionsOn($customerId, $employeeId, $date);
        $others   = array_values(array_filter($sessions, static fn (array $row): bool => (int) $row['id'] !== $recordId));

        $day = AttendanceCalculator::aggregateDay(
            array_merge($others, [['entry_at' => $entryAt, 'exit_at' => $exitAt]]),
            $shift,
            $context,
            $date
        );

        Database::run(
            'UPDATE `' . Database::table('attendance_records') . '`'
            . ' SET `entry_at` = ?, `exit_at` = ?, `work_minutes` = ?, `late_minutes` = ?,'
            . ' `early_leave_minutes` = ?, `overtime_minutes` = ?, `status` = ?, `flags` = ?,'
            . ' `open_key` = ?, `last_event` = ?, `modified_by` = ?, `modify_reason` = ?,'
            . ' `calc_version` = ?, `recalculated_at` = ?'
            . ' WHERE `id` = ? AND `customer_id` = ?',
            [
                $entryAt,
                $exitAt,
                (int) $day['work_minutes'],
                (int) $day['late_minutes'],
                (int) $day['early_leave_minutes'],
                (int) $day['overtime_minutes'],
                (string) $day['status'],
                $this->flagsToString(array_merge($day['flags'], [AttendanceCalculator::FLAG_MANUAL])),
                $exitAt === null ? $employeeId : null,
                $exitAt === null ? 'entry' : 'exit',
                (int) ($actor['id'] ?? 0),
                $reason,
                AttendanceCalculator::CALC_VERSION,
                date('Y-m-d H:i:s'),
                $recordId,
                $customerId,
            ]
        );

        $after = ['entry_at' => $entryAt, 'exit_at' => $exitAt, 'status' => (string) $day['status']];

        AttendanceEvent::fire($customerId, AttendanceEvent::MANUAL_CORRECTED, [
            'record_id' => $recordId,
            'action'    => 'correct',
            'before'    => $before,
            'after'     => $after,
            'reason'    => $reason,
        ], $employeeId, (int) ($record['device_id'] ?? 0), $recordId, 'warning');

        AuditLog::record('attendance.record.corrected', 'success',
            'اصلاح دستی رکورد حضور #' . $recordId . '.',
            ['before' => $before, 'after' => $after, 'reason' => $reason, 'actor' => $actor],
            'attendance', 'attendance_record', $recordId, $customerId);

        return $this->ok(self::CODE_RECORDED, 'رکورد حضور اصلاح شد.', $this->resultData(AttendanceRecord::find($recordId) ?? [], 'manual', false));
    }

    /**
     * Void a record. The row STAYS in the database (soft delete + void
     * markers) and drops out of every report and calculation.
     *
     * @return array<string,mixed>
     */
    public function voidRecord(array $actor, int $customerId, int $recordId, string $reason): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            return $this->fail(self::CODE_REJECTED, 'دلیل حذف/ابطال الزامی است.', 422);
        }

        $record = AttendanceRecord::findInCustomer($recordId, $customerId);

        if ($record === null) {
            return $this->fail(self::CODE_NOT_FOUND, 'رکورد حضور یافت نشد.', 404);
        }

        $now = date('Y-m-d H:i:s');

        Database::run(
            'UPDATE `' . Database::table('attendance_records') . '`'
            . ' SET `voided_at` = ?, `voided_by` = ?, `void_reason` = ?, `deleted_at` = ?,'
            . ' `deleted_by` = ?, `delete_reason` = ?, `open_key` = NULL'
            . ' WHERE `id` = ? AND `customer_id` = ?',
            [$now, (int) ($actor['id'] ?? 0), $reason, $now, (int) ($actor['id'] ?? 0), $reason, $recordId, $customerId]
        );

        AttendanceEvent::fire($customerId, AttendanceEvent::RECORD_VOIDED, [
            'record_id' => $recordId,
            'reason'    => $reason,
        ], (int) $record['employee_id'], (int) ($record['device_id'] ?? 0), $recordId, 'warning');

        AuditLog::record('attendance.record.voided', 'success',
            'ابطال رکورد حضور #' . $recordId . '.',
            [
                'before' => ['entry_at' => $record['entry_at'], 'exit_at' => $record['exit_at']],
                'reason' => $reason,
                'actor'  => $actor,
            ],
            'attendance', 'attendance_record', $recordId, $customerId);

        return $this->ok(self::CODE_RECORDED, 'رکورد حضور ابطال شد.', ['record_id' => $recordId]);
    }

    /**
     * Recalculate a range. Used after a shift change or when the rules were
     * updated. `$useCurrentShift` switches from the stored snapshot to the
     * shift that is valid NOW (that is the "shift changed" case).
     *
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>
     */
    public function recalculate(int $customerId, string $from, string $to, int $employeeId = 0, bool $useCurrentShift = false, ?array $actor = null): array
    {
        $from = AttendanceCalculator::normalizeDateTime($from) ?? '';
        $to   = AttendanceCalculator::normalizeDateTime($to) ?? '';

        if ($from === '' || $to === '' || strtotime($from) > strtotime($to)) {
            return $this->fail(self::CODE_REJECTED, 'بازه تاریخ نامعتبر است.', 422);
        }

        // Only rows that carry a SESSION are recalculated. The rows the day
        // close writes (absence / approved leave) have no entry and no exit:
        // their status is the answer of that pass and must not be overwritten
        // by the session calculator.
        $sql = 'SELECT * FROM `' . Database::table('attendance_records') . '`'
            . ' WHERE `customer_id` = ? AND `deleted_at` IS NULL AND `voided_at` IS NULL'
            . ' AND `entry_at` IS NOT NULL'
            . ' AND `attendance_date` BETWEEN ? AND ?';

        $params = [$customerId, substr($from, 0, 10), substr($to, 0, 10)];

        if ($employeeId > 0) {
            $sql .= ' AND `employee_id` = ?';
            $params[] = $employeeId;
        }

        $records = Database::select($sql . ' ORDER BY `employee_id` ASC, `entry_at` ASC', $params);

        $touched = 0;
        $groups  = [];

        foreach ($records as $record) {
            $groups[(int) $record['employee_id'] . '|' . (string) $record['attendance_date']][] = $record;
        }

        foreach ($groups as $key => $rows) {
            [$groupEmployee, $date] = explode('|', $key, 2);

            $shift = $useCurrentShift
                ? ShiftResolver::resolve((int) $groupEmployee, $customerId, $date)
                : (ShiftResolver::fromSnapshot(
                    $rows[0]['shift_snapshot'] !== null ? json_decode((string) $rows[0]['shift_snapshot'], true) : null
                ) ?? ShiftResolver::resolve((int) $groupEmployee, $customerId, $date));

            $context = $this->dayContext($customerId, (int) $groupEmployee, $date, 0);

            // per session (so an open session keeps its incomplete state)
            foreach ($rows as $row) {
                $calc = AttendanceCalculator::calculateSession(
                    ['entry_at' => $row['entry_at'], 'exit_at' => $row['exit_at']],
                    $shift,
                    $context + (!empty($row['modify_reason']) ? ['manual' => true] : []),
                    $date
                );

                $flags = $calc['flags'];

                if ((string) $row['source'] === 'device_offline') {
                    $flags[] = AttendanceCalculator::FLAG_OFFLINE;
                }

                if (!empty($row['modify_reason'])) {
                    $flags[] = AttendanceCalculator::FLAG_MANUAL;
                }

                if ($row['exit_at'] === null) {
                    $calc['status'] = AttendanceCalculator::STATUS_INCOMPLETE;
                    $calc['work_minutes'] = 0;
                }

                Database::run(
                    'UPDATE `' . Database::table('attendance_records') . '`'
                    . ' SET `work_minutes` = ?, `late_minutes` = ?, `early_leave_minutes` = ?,'
                    . ' `overtime_minutes` = ?, `status` = ?, `flags` = ?, `shift_id` = ?,'
                    . ' `shift_snapshot` = ?, `calc_version` = ?, `recalculated_at` = ?'
                    . ' WHERE `id` = ? AND `customer_id` = ?',
                    [
                        (int) $calc['work_minutes'],
                        (int) $calc['late_minutes'],
                        (int) $calc['early_leave_minutes'],
                        (int) $calc['overtime_minutes'],
                        $row['exit_at'] === null ? AttendanceCalculator::STATUS_INCOMPLETE : (string) $calc['status'],
                        $this->flagsToString(array_values(array_unique($flags))),
                        $shift !== null ? (int) $shift['id'] : null,
                        $shift === null ? null : json_encode(ShiftResolver::snapshot($shift), JSON_UNESCAPED_UNICODE),
                        AttendanceCalculator::CALC_VERSION,
                        date('Y-m-d H:i:s'),
                        (int) $row['id'],
                        $customerId,
                    ]
                );

                $touched++;
            }
        }

        Logger::channel('attendance')->info('Recalculation finished', [
            'customer_id' => $customerId,
            'from'        => $from,
            'to'          => $to,
            'records'     => $touched,
            'shift_mode'  => $useCurrentShift ? 'current' : 'snapshot',
        ]);

        AuditLog::record('attendance.recalculated', 'success',
            'بازمحاسبه حضور در بازه ' . $from . ' تا ' . $to . '.',
            ['records' => $touched, 'use_current_shift' => $useCurrentShift, 'actor' => $actor],
            'attendance', 'customer', $customerId, $customerId);

        return $this->ok('ATTENDANCE_RECALCULATED', 'بازمحاسبه انجام شد.', [
            'records'     => $touched,
            'from'        => $from,
            'to'          => $to,
            'shift_mode'  => $useCurrentShift ? 'current' : 'snapshot',
        ]);
    }

    /**
     * Close a day: the ABSENCE pass.
     *
     * Absence is NOT evaluated while an employee clocks in and out - it is a
     * scheduled calculation over a finished day. For every active employee who
     * has a shift on that date and no session at all:
     *   - a holiday     -> no absence row (the day is a holiday),
     *   - approved leave -> no absence row (statusForMissingDay gives on_leave),
     *   - otherwise     -> an ABSENT record is created (source 'system').
     *
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>
     */
    public function closeDay(int $customerId, string $date, int $branchId = 0, ?array $actor = null): array
    {
        $date = substr((string) (AttendanceCalculator::normalizeDateTime($date) ?? ''), 0, 10);

        if ($date === '') {
            return $this->fail(self::CODE_REJECTED, 'تاریخ نامعتبر است.', 422);
        }

        $holiday = ShiftResolver::isHoliday($customerId, $date, $branchId);

        $sql = 'SELECT * FROM `' . Database::table('employees') . '`'
            . ' WHERE `customer_id` = ? AND `deleted_at` IS NULL AND `status` = ?';

        $params = [$customerId, 'active'];

        if ($branchId > 0) {
            $sql .= ' AND (`branch_id` IS NULL OR `branch_id` = ?)';
            $params[] = $branchId;
        }

        $employees = Database::select($sql, $params);

        $created       = 0;
        $absentCreated = 0;
        $leaveCreated  = 0;
        $skipped       = ['holiday' => 0, 'no_shift' => 0, 'has_sessions' => 0];

        foreach ($employees as $employee) {
            $employeeId = (int) $employee['id'];

            $sessions = AttendanceRecord::sessionsOn($customerId, $employeeId, $date);

            if ($sessions !== []) {
                $skipped['has_sessions']++;

                continue;
            }

            $shift = ShiftResolver::resolve($employeeId, $customerId, $date);

            if ($shift === null) {
                $skipped['no_shift']++;

                continue;
            }

            $onLeave = ShiftResolver::onLeave($employeeId, $customerId, $date);

            // ONE place decides what a day without any session means.
            $status = AttendanceCalculator::statusForMissingDay([
                'holiday' => $holiday,
                'leave'   => $onLeave,
            ]);

            if ($status === AttendanceCalculator::STATUS_HOLIDAY) {
                // A holiday is not an absence and gets no per employee row.
                $skipped['holiday']++;

                continue;
            }

            // Both absence and approved full-day leave are persisted, so the
            // panel and payroll see a real row («مرخصی» is never «غایب»).
            $existing = Database::selectOne(
                'SELECT `id` FROM `' . Database::table('attendance_records') . '`'
                . ' WHERE `customer_id` = ? AND `employee_id` = ? AND `attendance_date` = ?'
                . ' AND `status` IN (?, ?) LIMIT 1',
                [$customerId, $employeeId, $date, AttendanceCalculator::STATUS_ABSENT, AttendanceCalculator::STATUS_ON_LEAVE]
            );

            if ($existing !== null) {
                continue;
            }

            $onLeaveCreated = $status === AttendanceCalculator::STATUS_ON_LEAVE;

            $flags = $onLeaveCreated
                ? [AttendanceCalculator::FLAG_ON_LEAVE, AttendanceCalculator::FLAG_INCOMPLETE]
                : [AttendanceCalculator::FLAG_INCOMPLETE];

            if ($onLeaveCreated) {
                $leaveCreated++;
            } else {
                $absentCreated++;
            }

            $recordId = Database::insert(Database::table('attendance_records'), [
                'uuid'            => Security::uuid4(),
                'customer_id'     => $customerId,
                'employee_id'     => $employeeId,
                'branch_id'       => isset($employee['branch_id']) ? (int) $employee['branch_id'] : null,
                'shift_id'        => (int) $shift['id'],
                'attendance_date' => $date,
                'entry_at'        => null,
                'exit_at'         => null,
                'work_minutes'    => 0,
                'late_minutes'    => 0,
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'status'          => $status,
                'flags'           => $this->flagsToString($flags),
                'shift_snapshot'  => json_encode(ShiftResolver::snapshot($shift), JSON_UNESCAPED_UNICODE),
                'calc_version'    => AttendanceCalculator::CALC_VERSION,
                'last_event'      => 'absence',
                'source'          => 'system',
                'server_received_at' => date('Y-m-d H:i:s'),
            ]);

            $created++;

            AttendanceEvent::fire($customerId,
                $onLeaveCreated ? AttendanceEvent::LEAVE_RECORDED : AttendanceEvent::ABSENCE_DETECTED,
                [
                    'record_id'   => $recordId,
                    'date'        => $date,
                    'employee_id' => $employeeId,
                ], $employeeId, 0, $recordId, 'warning');
        }

        AttendanceEvent::fire($customerId, AttendanceEvent::DAY_RECALCULATED, [
            'date'      => $date,
            'holiday'   => $holiday,
            'absent'    => $created,
            'skipped'   => $skipped,
        ], 0, 0, 0, 'info');

        AuditLog::record('attendance.day.closed', 'success',
            'بستن روز ' . $date . ' و محاسبه غیبت‌ها.',
            ['absent_created' => $created, 'skipped' => $skipped, 'actor' => $actor],
            'attendance', 'customer', $customerId, $customerId);

        // Recalculate the sessions that DO exist on that date, so their
        // numbers reflect the (possibly changed) shift rules of the day.
        $this->recalculate($customerId, $date, $date, 0, false, $actor);

        return $this->ok('ATTENDANCE_DAY_CLOSED', 'بستن روز انجام شد.', [
            'date'            => $date,
            'holiday'         => $holiday,
            'absent'          => $absentCreated,
            'on_leave'        => $leaveCreated,
            'records_written' => $created,
            'skipped'         => $skipped,
        ]);
    }

    /* =================================================================
     * Reads used by the panel / manager API
     * ===============================================================*/

    /**
     * Everyone who is currently inside (entry without exit), old first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function currentlyInside(int $customerId, int $branchId = 0, int $limit = 100): array
    {
        $sql = 'SELECT r.`id`, r.`uuid`, r.`employee_id`, r.`branch_id`, r.`device_id`, r.`attendance_date`,'
            . ' r.`entry_at`, r.`late_minutes`, r.`status`, r.`flags`, r.`source`,'
            . ' e.`first_name`, e.`last_name`, e.`employee_code`, e.`photo_path`,'
            . ' b.`name` AS branch_name, d.`name` AS device_name,'
            . ' TIMESTAMPDIFF(MINUTE, r.`entry_at`, NOW()) AS inside_minutes'
            . ' FROM `' . Database::table('attendance_records') . '` r'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = r.`employee_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`'
            . ' LEFT JOIN `' . Database::table('devices') . '` d ON d.`id` = r.`device_id`'
            . ' WHERE r.`customer_id` = ? AND r.`open_key` IS NOT NULL'
            . ' AND r.`deleted_at` IS NULL AND r.`voided_at` IS NULL';

        $params = [$customerId];

        if ($branchId > 0) {
            $sql .= ' AND r.`branch_id` = ?';
            $params[] = $branchId;
        }

        $sql .= ' ORDER BY r.`entry_at` ASC LIMIT ' . max(1, min(500, $limit));

        return Database::select($sql, $params);
    }

    /** Dashboard numbers of one date - real rows, never estimates. */
    public function daySummary(int $customerId, string $date, int $branchId = 0): array
    {
        $sql = 'SELECT'
            . ' COUNT(*) AS records,'
            . ' COUNT(DISTINCT `employee_id`) AS employees,'
            . ' SUM(CASE WHEN `open_key` IS NOT NULL THEN 1 ELSE 0 END) AS currently_inside,'
            . ' SUM(CASE WHEN `exit_at` IS NOT NULL THEN 1 ELSE 0 END) AS completed,'
            . ' SUM(CASE WHEN `late_minutes` > 0 THEN 1 ELSE 0 END) AS late,'
            . ' SUM(CASE WHEN `early_leave_minutes` > 0 THEN 1 ELSE 0 END) AS early_leave,'
            . ' SUM(CASE WHEN `overtime_minutes` > 0 THEN 1 ELSE 0 END) AS overtime,'
            . ' SUM(CASE WHEN `status` = ? THEN 1 ELSE 0 END) AS absent,'
            . ' SUM(CASE WHEN `status` = ? THEN 1 ELSE 0 END) AS on_leave,'
            . ' COALESCE(SUM(`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(`overtime_minutes`), 0) AS overtime_minutes'
            . ' FROM `' . Database::table('attendance_records') . '`'
            . ' WHERE `customer_id` = ? AND `attendance_date` = ? AND `deleted_at` IS NULL AND `voided_at` IS NULL';

        $params = [AttendanceCalculator::STATUS_ABSENT, AttendanceCalculator::STATUS_ON_LEAVE, $customerId, $date];

        if ($branchId > 0) {
            $sql .= ' AND `branch_id` = ?';
            $params[] = $branchId;
        }

        $row = Database::selectOne($sql, $params) ?? [];

        return [
            'records'         => (int) ($row['records'] ?? 0),
            'employees'       => (int) ($row['employees'] ?? 0),
            'currently_inside' => (int) ($row['currently_inside'] ?? 0),
            'completed'       => (int) ($row['completed'] ?? 0),
            'late'            => (int) ($row['late'] ?? 0),
            'early_leave'     => (int) ($row['early_leave'] ?? 0),
            'overtime'        => (int) ($row['overtime'] ?? 0),
            'absent'          => (int) ($row['absent'] ?? 0),
            'on_leave'        => (int) ($row['on_leave'] ?? 0),
            'work_minutes'    => (int) ($row['work_minutes'] ?? 0),
            'overtime_minutes' => (int) ($row['overtime_minutes'] ?? 0),
        ];
    }

    /** Payroll inputs of one period - numbers only, no salary calculation. */
    public function payrollInputs(int $customerId, string $from, string $to, int $employeeId = 0): array
    {
        $sql = 'SELECT `employee_id`,'
            . ' COALESCE(SUM(`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(`overtime_minutes`), 0) AS overtime_minutes,'
            . ' COALESCE(SUM(`late_minutes`), 0) AS late_minutes,'
            . ' COALESCE(SUM(`early_leave_minutes`), 0) AS early_leave_minutes,'
            . ' SUM(CASE WHEN `status` = ? THEN 1 ELSE 0 END) AS absence_days,'
            . ' SUM(CASE WHEN `status` = ? THEN 1 ELSE 0 END) AS leave_days'
            . ' FROM `' . Database::table('attendance_records') . '`'
            . ' WHERE `customer_id` = ? AND `attendance_date` BETWEEN ? AND ?'
            . ' AND `deleted_at` IS NULL AND `voided_at` IS NULL';

        $params = [
            AttendanceCalculator::STATUS_ABSENT,
            AttendanceCalculator::STATUS_ON_LEAVE,
            $customerId,
            substr($from, 0, 10),
            substr($to, 0, 10),
        ];

        if ($employeeId > 0) {
            $sql .= ' AND `employee_id` = ?';
            $params[] = $employeeId;
        }

        $rows = Database::select($sql . ' GROUP BY `employee_id` ORDER BY `employee_id` ASC', $params);

        return array_map(static fn (array $row): array => [
            'employee_id'         => (int) $row['employee_id'],
            'work_minutes'        => (int) $row['work_minutes'],
            'overtime_minutes'    => (int) $row['overtime_minutes'],
            'late_minutes'        => (int) $row['late_minutes'],
            'early_leave_minutes' => (int) $row['early_leave_minutes'],
            'absence_days'        => (int) $row['absence_days'],
            'leave_days'          => (int) $row['leave_days'],
        ], $rows);
    }

    /* =================================================================
     * Internals
     * ===============================================================*/

    /**
     * The server clock is the source of truth. For an offline event the device
     * time is validated and clamped; it never becomes the server timestamp.
     *
     * @return array{ok:bool,code:string,message:string,http:int,server_time:string,event_at:string,date:string,offline:bool,skew:bool}
     */
    private function resolveEventTime(array $payload, string $action): array
    {
        $now   = date('Y-m-d H:i:s');
        $tz    = new DateTimeImmutable('now');
        $today = $tz->format('Y-m-d');

        $offline = (bool) ($payload['offline'] ?? false);

        if (!$offline) {
            return [
                'ok'          => true,
                'code'        => '',
                'message'     => '',
                'http'        => 200,
                'server_time' => $now,
                'event_at'    => $now,
                'date'        => $today,
                'offline'     => false,
                'skew'        => false,
            ];
        }

        $occurred = AttendanceCalculator::normalizeDateTime($payload['occurred_at'] ?? null);

        if ($occurred === null) {
            return [
                'ok'      => false,
                'code'    => self::CODE_OFFLINE_INVALID,
                'message' => 'زمان رخداد این رویداد آفلاین مشخص نیست.',
                'http'    => 422,
                'server_time' => $now, 'event_at' => $now, 'date' => $today,
                'offline' => true, 'skew' => false,
            ];
        }

        $maxAgeHours = max(1, (int) Config::get('attendance.offline_max_age_hours', 72));
        $tolerance   = max(0, (int) Config::get('attendance.clock_skew_tolerance_seconds', 300));

        $eventTime = strtotime($occurred);
        $serverTime = strtotime($now);

        if ($eventTime < $serverTime - ($maxAgeHours * 3600)) {
            return [
                'ok'      => false,
                'code'    => self::CODE_OFFLINE_INVALID,
                'message' => 'این رویداد آفلاین قدیمی‌تر از حد مجاز است و قابل هم‌گام‌سازی نیست.',
                'http'    => 422,
                'server_time' => $now, 'event_at' => $now, 'date' => $today,
                'offline' => true, 'skew' => true,
            ];
        }

        $skew = false;

        // A device clock in the future is clamped to the server clock: nobody
        // writes tomorrow's attendance today.
        if ($eventTime > $serverTime + $tolerance) {
            $occurred = $now;
            $skew     = true;
        }

        return [
            'ok'          => true,
            'code'        => '',
            'message'     => '',
            'http'        => 200,
            'server_time' => $now,
            'event_at'    => $occurred,
            'date'        => substr($occurred, 0, 10),
            'offline'     => true,
            'skew'        => $skew,
        ];
    }

    /**
     * @return array{ok:bool,data:array<string,mixed>}
     */
    private function rateCheck(int $customerId, int $deviceId, int $employeeId, string $action): array
    {
        $perDevice   = max(1, (int) Config::get('attendance.rate_limit_device_per_minute', 60));
        $perEmployee = max(1, (int) Config::get('attendance.rate_limit_employee_per_minute', 8));

        $window = 60;

        foreach ([
            ['key' => 'dev' . $deviceId, 'limit' => $perDevice],
            ['key' => 'dev' . $deviceId . '-emp' . $employeeId, 'limit' => $perEmployee],
        ] as $bucket) {
            if ($employeeId <= 0 && strpos($bucket['key'], 'emp') !== false) {
                continue;
            }

            $state = $this->bumpCounter($bucket['key'], $window);

            if ($state['hits'] > $bucket['limit']) {
                Logger::channel('security')->warning('Attendance rate limited', [
                    'device_id'   => $deviceId,
                    'customer_id' => $customerId,
                    'employee_id' => $employeeId,
                    'bucket'      => $bucket['key'],
                    'hits'        => $state['hits'],
                ]);

                return [
                    'ok'   => false,
                    'data' => [
                        'retry_after' => max(1, $window - (time() - $state['start'])),
                        'limit'       => $bucket['limit'],
                    ],
                ];
            }
        }

        return ['ok' => true, 'data' => []];
    }

    /** @return array{start:int,hits:int} */
    private function bumpCounter(string $key, int $window): array
    {
        $file  = Config::path('storage/cache/attendance_rate_' . sha1($key) . '.json');
        $state = ['start' => time(), 'hits' => 0];

        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);

            if (is_array($decoded) && isset($decoded['start'], $decoded['hits'])) {
                $state = ['start' => (int) $decoded['start'], 'hits' => (int) $decoded['hits']];
            }
        }

        if ((time() - $state['start']) > $window) {
            $state = ['start' => time(), 'hits' => 0];
        }

        $state['hits']++;

        @file_put_contents($file, (string) json_encode($state), LOCK_EX);

        return $state;
    }

    /**
     * Idempotency key: the client may send one, otherwise it is derived from
     * the device and the request id (offline: from the local event id).
     */
    private function idempotencyKey(int $deviceId, array $payload, string $requestId): string
    {
        $given = trim((string) ($payload['idempotency_key'] ?? ''));

        if ($given !== '' && preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $given) === 1) {
            return substr($given, 0, 64);
        }

        $local = trim((string) ($payload['local_event_id'] ?? ''));

        if ($local !== '') {
            return substr('dev' . $deviceId . ':ev:' . $local, 0, 64);
        }

        return substr('dev' . $deviceId . ':req:' . $requestId, 0, 64);
    }

    private function localEventId(array $payload): ?string
    {
        $local = trim((string) ($payload['local_event_id'] ?? ''));

        return $local === '' ? null : substr($local, 0, 64);
    }

    /**
     * Holiday / leave context of one day.
     *
     * @return array<string,mixed>
     */
    private function dayContext(int $customerId, int $employeeId, string $date, int $branchId): array
    {
        return [
            'holiday' => ShiftResolver::isHoliday($customerId, $date, $branchId),
            'leave'   => ShiftResolver::onLeave($employeeId, $customerId, $date),
        ];
    }

    /**
     * @param array<int,string> $flags
     */
    private function flagsToString(array $flags): string
    {
        $flags = array_values(array_unique(array_filter(array_map('strval', $flags))));

        return substr(implode(',', $flags), 0, 120);
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function resultData(array $record, string $event, bool $duplicate): array
    {
        if ($record === []) {
            return ['event' => strtoupper($event), 'duplicate' => $duplicate];
        }

        $flags = array_values(array_filter(explode(',', (string) ($record['flags'] ?? ''))));

        return [
            'record_id'       => (int) $record['id'],
            'uuid'            => (string) $record['uuid'],
            'employee_id'     => (int) $record['employee_id'],
            'event'           => strtoupper($event),
            'attendance_date' => (string) $record['attendance_date'],
            'entry_at'        => $record['entry_at'],
            'exit_at'         => $record['exit_at'],
            'work_minutes'    => (int) $record['work_minutes'],
            'late_minutes'    => (int) $record['late_minutes'],
            'early_leave_minutes' => (int) $record['early_leave_minutes'],
            'overtime_minutes' => (int) $record['overtime_minutes'],
            'status'          => (string) $record['status'],
            'status_label'    => AttendanceCalculator::statusLabel((string) $record['status']),
            'flags'           => $flags,
            'flag_labels'     => array_map([AttendanceCalculator::class, 'flagLabel'], $flags),
            'session_index'   => (int) $record['session_index'],
            'source'          => (string) $record['source'],
            'server_time'     => date('Y-m-d H:i:s'),
            'duplicate'       => $duplicate,
        ];
    }

    /**
     * @param array<string,mixed> $employee
     * @return array<string,mixed>
     */
    private function employeeBrief(array $employee): array
    {
        return [
            'id'        => (int) $employee['id'],
            'full_name' => Employee::fullName($employee),
            'code'      => (string) ($employee['employee_code'] ?? ''),
            'has_photo' => trim((string) ($employee['photo_path'] ?? '')) !== '',
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>,http:int}
     */
    /**
     * The device trail: every attendance attempt of a device - accepted or
     * refused - is visible on the device page. The attendance fact itself lives
     * in attendance_records / attendance_events / the audit trail; this row is
     * what the operator sees for "what did this kiosk try to do".
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $payload
     */
    private function logDeviceAttempt(array $device, string $action, array $result, array $payload): void
    {
        try {
            $data = (array) ($result['data'] ?? []);

            DeviceLog::record(
                'device.attendance.submitted',
                empty($result['ok']) ? 'warning' : 'info',
                (string) ($result['message'] ?? ''),
                (int) ($device['id'] ?? 0),
                (int) ($device['customer_id'] ?? 0),
                [
                    'action'      => $action,
                    'outcome'     => empty($result['ok']) ? 'rejected' : ($data['duplicate'] ?? false ? 'duplicate' : 'recorded'),
                    'code'        => (string) ($result['code'] ?? ''),
                    'record_id'   => $data['record_id'] ?? null,
                    'employee_id' => $data['employee_id'] ?? ($payload['employee_id'] ?? null),
                    'source'      => (string) ($data['source'] ?? ($payload['offline'] ?? false ? 'device_offline' : 'device')),
                    'request_id'  => (string) ($payload['request_id'] ?? ''),
                    // When it happened: the device-stated time for a queued
                    // event and the server stamp of the write for an online
                    // one, so every trail row carries a timestamp.
                    'occurred_at' => (string) ($payload['occurred_at'] ?? ''),
                    'server_time' => (string) ($data['server_time'] ?? date('Y-m-d H:i:s')),
                ]
            );
        } catch (\Throwable $error) {
            // A log row must never break an attendance write.
            Logger::channel('attendance')->warning('Device log for an attendance attempt failed', [
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function ok(string $code, string $message, array $data = []): array
    {
        return ['ok' => true, 'code' => $code, 'message' => $message, 'data' => $data, 'http' => 200];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>,http:int}
     */
    private function fail(string $code, string $message, int $http = 422, array $data = []): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'data' => $data, 'http' => $http];
    }

    /** A unique violation that reached the driver: map it to the right code. */
    private function mapDuplicate(PDOException $error, string $action): array
    {
        $message = $error->getMessage();

        if (strpos($message, 'uniq_records_open') !== false) {
            return $this->fail(self::CODE_ENTRY_OPEN, 'ورود شما قبلاً ثبت شده است.', 409);
        }

        if (strpos($message, 'uniq_records_idempotency') !== false || strpos($message, 'uniq_records_device_event') !== false) {
            return $this->fail(self::CODE_DUPLICATE, 'این درخواست قبلاً پردازش شده است.', 409);
        }

        Logger::channel('attendance')->error('Attendance write rejected by the database', [
            'action' => $action,
            'error'  => $message,
        ]);

        return $this->fail(self::CODE_REJECTED, 'ثبت حضور انجام نشد.', 409);
    }

    private function denied(int $customerId, int $deviceId, string $event, array $meta = []): void
    {
        Logger::channel('security')->warning('Attendance attempt refused', $meta + [
            'customer_id' => $customerId,
            'device_id'   => $deviceId,
            'event'       => $event,
        ]);

        AuditLog::record($event, 'denied', 'تلاش نامعتبر برای ثبت حضور.', $meta + [
            'device_id' => $deviceId,
        ], 'security', 'device', $deviceId, $customerId);
    }

    private function log(string $event, int $customerId, int $deviceId, int $employeeId, array $meta = []): void
    {
        Logger::channel('attendance')->info($event, $meta + [
            'customer_id' => $customerId,
            'device_id'   => $deviceId,
            'employee_id' => $employeeId,
        ]);
    }
}
