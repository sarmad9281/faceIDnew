<?php
/**
 * SARMAD Attendance - face samples (stage 3).
 *
 * STRUCTURE ONLY - this class stores the three enrolment pictures of an
 * employee (Face Sample 1 / 2 / 3) and answers "how many samples are
 * registered". There is deliberately no recognition engine, no template,
 * no camera capture and no liveness detection anywhere in this stage.
 *
 * The unique key (employee_id, sample_index) is what enforces "exactly
 * three slots": slot 1, 2 and 3 exist or do not, and re-uploading a slot
 * replaces the previous picture instead of adding a fourth one.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class EmployeeFace extends Model
{
    protected static string $table = 'employee_faces';

    /** Where the three slots live for the UI. */
    public const SLOT_LABELS = [
        1 => 'نمونه چهره ۱',
        2 => 'نمونه چهره ۲',
        3 => 'نمونه چهره ۳',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ENROLLED = 'enrolled';
    public const STATUS_REJECTED = 'rejected';

    protected static array $fillable = [
        'uuid', 'customer_id', 'employee_id', 'sample_index', 'label', 'file_path',
        'mime_type', 'file_size', 'image_hash', 'status', 'captured_at', 'created_by',
    ];

    public static function slotLabel(int $index): string
    {
        return self::SLOT_LABELS[$index] ?? ('نمونه چهره ' . $index);
    }

    /**
     * The three slots of one employee, always keyed 1,2,3 - missing slots are null.
     *
     * @return array<int,array<string,mixed>|null>
     */
    public static function slots(int $employeeId, int $customerId): array
    {
        $slots = [1 => null, 2 => null, 3 => null];

        foreach (self::rowsFor($employeeId, $customerId) as $row) {
            $index = (int) $row['sample_index'];

            if (isset($slots[$index])) {
                $slots[$index] = $row;
            }
        }

        return $slots;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function rowsFor(int $employeeId, int $customerId): array
    {
        if ($employeeId <= 0 || $customerId <= 0) {
            return [];
        }

        return Database::select(
            'SELECT * FROM `' . self::tableName() . '` WHERE `employee_id` = ? AND `customer_id` = ? ORDER BY `sample_index` ASC',
            [$employeeId, $customerId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function sample(int $employeeId, int $index, int $customerId): ?array
    {
        if ($employeeId <= 0 || $customerId <= 0 || $index < 1 || $index > 3) {
            return null;
        }

        return Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `employee_id` = ? AND `customer_id` = ? AND `sample_index` = ? LIMIT 1',
            [$employeeId, $customerId, $index]
        );
    }

    /**
     * Insert or replace one slot.
     *
     * @param array<string,mixed> $data customer_id, employee_id, sample_index,
     *                                  file_path, mime_type, file_size, image_hash,
     *                                  status, created_by, label
     */
    public static function saveSample(array $data): int
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $customerId = (int) ($data['customer_id'] ?? 0);
        $index      = (int) ($data['sample_index'] ?? 0);

        if ($employeeId <= 0 || $customerId <= 0 || $index < 1 || $index > 3) {
            return 0;
        }

        $existing = self::sample($employeeId, $index, $customerId);

        $payload = [
            'label'       => $data['label'] ?? self::slotLabel($index),
            'file_path'   => (string) ($data['file_path'] ?? ''),
            'mime_type'   => $data['mime_type'] ?? null,
            'file_size'   => (int) ($data['file_size'] ?? 0),
            'image_hash'  => $data['image_hash'] ?? null,
            'status'      => (string) ($data['status'] ?? self::STATUS_ENROLLED),
            'captured_at' => $data['captured_at'] ?? date('Y-m-d H:i:s'),
            'created_by'  => $data['created_by'] ?? null,
        ];

        if ($existing !== null) {
            self::updateById((int) $existing['id'], $payload);

            return (int) $existing['id'];
        }

        $payload['uuid']         = Security::uuid4();
        $payload['customer_id']  = $customerId;
        $payload['employee_id']  = $employeeId;
        $payload['sample_index'] = $index;

        return self::create($payload);
    }

    /**
     * Delete one slot (and hand the stored file back to the caller so it can
     * be removed from disk). Facial pictures are never kept "just in case".
     *
     * @return array<string,mixed>|null the removed row
     */
    public static function removeSample(int $employeeId, int $index, int $customerId): ?array
    {
        $row = self::sample($employeeId, $index, $customerId);

        if ($row === null) {
            return null;
        }

        Database::run('DELETE FROM `' . self::tableName() . '` WHERE `id` = ? AND `customer_id` = ?', [(int) $row['id'], $customerId]);

        return $row;
    }

    /** How many of the three slots are filled. */
    public static function enrolledCount(int $employeeId, int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `employee_id` = ? AND `customer_id` = ?',
            [$employeeId, $customerId]
        );
    }

    /**
     * employee_id => filled slot count for a whole customer (list badges).
     *
     * @return array<int,int>
     */
    public static function countsForCustomer(int $customerId): array
    {
        $rows = Database::select(
            'SELECT `employee_id`, COUNT(*) AS total FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? GROUP BY `employee_id`',
            [$customerId]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['employee_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Keeps employees.face_enrolled / employees.face_status in sync with the
     * real rows - the employee row is the fast read path, the face table is
     * the truth.
     */
    public static function syncEmployeeFlags(int $employeeId, int $customerId): string
    {
        $count  = self::enrolledCount($employeeId, $customerId);
        $status = $count <= 0 ? 'not_enrolled' : ($count >= 3 ? 'complete' : 'partial');

        Database::run(
            'UPDATE `' . Database::table('employees') . '` SET `face_enrolled` = ?, `face_status` = ? WHERE `id` = ? AND `customer_id` = ?',
            [$count > 0 ? 1 : 0, $status, $employeeId, $customerId]
        );

        return $status;
    }
}
