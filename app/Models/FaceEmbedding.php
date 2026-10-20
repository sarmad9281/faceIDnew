<?php
/**
 * SARMAD Attendance - face embeddings (stage 5).
 *
 * One row per enrolled sample: employee_id + sample_index (1..3) is UNIQUE
 * and the database also carries a CHECK constraint, so "exactly three
 * samples per employee" is enforced by MySQL - not only by the UI.
 *
 * The vector itself is a JSON array of 128 floats produced by the LOCAL
 * browser model (face-api.js face_recognition). No camera image is needed
 * for matching, therefore none is stored here: `image_id` only points at
 * the optional enrolment snapshot in attendance_employee_faces.
 *
 * Embeddings are BIOMETRIC DATA:
 *   - they are never returned by a public endpoint,
 *   - only two callers may read them: the employee's own enrolment wizard
 *     (same tenant) and an authenticated device of the SAME customer,
 *   - a manager of another customer can never reach them (customer_id is
 *     part of every query here),
 *   - resetting a face registration really deletes the rows.
 */
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Security;
use InvalidArgumentException;

final class FaceEmbedding extends Model
{
    /** Exactly three samples - not one, not ten. */
    public const SAMPLES = 3;

    /** Descriptor size of the shipped model (face_recognition). */
    public const DIMENSIONS = 128;

    public const SAMPLE_MIN = 1;
    public const SAMPLE_MAX = 3;

    protected static string $table = 'face_embeddings';

    /** Columns that may be mass assigned (see Model::create / filter). */
    protected static array $fillable = [
        'uuid', 'customer_id', 'employee_id', 'sample_index', 'embedding', 'dimensions',
        'model_version', 'engine', 'detector', 'metric', 'quality', 'image_id', 'source',
        'created_by', 'revoked_at',
    ];

    /** The table has no deleted_at column: removals are real deletions. */
    protected static bool $softDeletes = false;

    /**
     * Live (not revoked) samples of one employee, ordered by sample_index.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function samplesFor(int $employeeId, int $customerId): array
    {
        return Database::select(
            'SELECT * FROM `' . self::tableName() . '`'
            . ' WHERE `employee_id` = ? AND `customer_id` = ? AND `revoked_at` IS NULL'
            . ' ORDER BY `sample_index` ASC',
            [$employeeId, $customerId]
        );
    }

    /**
     * Only the vectors, in sample order - the shape the client/device needs.
     *
     * @return array<int,array{sample_index:int,embedding:array<int,float>,model_version:string,quality:float|null}>
     */
    public static function descriptorsFor(int $employeeId, int $customerId): array
    {
        $out = [];

        foreach (self::samplesFor($employeeId, $customerId) as $row) {
            $vector = self::decode((string) $row['embedding']);

            if ($vector === []) {
                continue;
            }

            $out[] = [
                'sample_index'  => (int) $row['sample_index'],
                'embedding'     => $vector,
                'model_version' => (string) $row['model_version'],
                'quality'       => $row['quality'] === null ? null : (float) $row['quality'],
            ];
        }

        return $out;
    }

    public static function countFor(int $employeeId, int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `employee_id` = ? AND `customer_id` = ? AND `revoked_at` IS NULL',
            [$employeeId, $customerId]
        );
    }

    /**
     * employee_id => live sample count, for a whole customer.
     *
     * @return array<int,int>
     */
    public static function countsForCustomer(int $customerId): array
    {
        $rows = Database::select(
            'SELECT `employee_id`, COUNT(*) AS total FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `revoked_at` IS NULL GROUP BY `employee_id`',
            [$customerId]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['employee_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Create or replace one sample (employee_id + sample_index is unique).
     *
     * @param array<string,mixed> $data
     */
    public static function saveSample(array $data): int
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $customerId = (int) ($data['customer_id'] ?? 0);
        $index      = (int) ($data['sample_index'] ?? 0);
        $vector     = $data['embedding'] ?? [];

        if ($employeeId <= 0 || $customerId <= 0) {
            throw new InvalidArgumentException('face embedding: employee_id and customer_id are required');
        }

        if ($index < self::SAMPLE_MIN || $index > self::SAMPLE_MAX) {
            throw new InvalidArgumentException('face embedding: sample_index must be 1..3');
        }

        if (!is_array($vector)) {
            throw new InvalidArgumentException('face embedding: embedding must be an array');
        }

        $encoded = self::encode($vector);

        if ($encoded === '') {
            throw new InvalidArgumentException('face embedding: embedding must be '
                . self::DIMENSIONS . ' finite numbers');
        }

        $existing = Database::selectOne(
            'SELECT `id` FROM `' . self::tableName() . '` WHERE `employee_id` = ? AND `sample_index` = ?',
            [$employeeId, $index]
        );

        $payload = [
            'embedding'     => $encoded,
            'dimensions'    => self::DIMENSIONS,
            'model_version' => substr((string) ($data['model_version'] ?? 'unknown'), 0, 60),
            'engine'        => substr((string) ($data['engine'] ?? 'face-api.js'), 0, 30),
            'detector'      => substr((string) ($data['detector'] ?? 'unknown'), 0, 30),
            'metric'        => substr((string) ($data['metric'] ?? 'euclidean'), 0, 16),
            'quality'       => isset($data['quality']) && $data['quality'] !== null
                ? round((float) $data['quality'], 5)
                : null,
            'image_id'      => isset($data['image_id']) && (int) $data['image_id'] > 0
                ? (int) $data['image_id']
                : null,
            'source'        => in_array(($data['source'] ?? 'enrollment'), ['enrollment', 'reimport'], true)
                ? (string) $data['source']
                : 'enrollment',
            'created_by'    => (int) ($data['created_by'] ?? 0) ?: null,
            'revoked_at'    => null,
        ];

        if ($existing !== null) {
            Database::run(
                'UPDATE `' . self::tableName() . '` SET '
                . '`embedding` = ?, `dimensions` = ?, `model_version` = ?, `engine` = ?, `detector` = ?,'
                . ' `metric` = ?, `quality` = ?, `image_id` = ?, `source` = ?, `created_by` = ?, `revoked_at` = NULL'
                . ' WHERE `id` = ?',
                [
                    $payload['embedding'], $payload['dimensions'], $payload['model_version'], $payload['engine'],
                    $payload['detector'], $payload['metric'], $payload['quality'], $payload['image_id'],
                    $payload['source'], $payload['created_by'], (int) $existing['id'],
                ]
            );

            return (int) $existing['id'];
        }

        $payload['uuid']        = Security::uuid4();
        $payload['employee_id'] = $employeeId;
        $payload['customer_id'] = $customerId;
        $payload['sample_index'] = $index;

        return (int) self::create($payload);
    }

    /**
     * Delete every sample of one employee (face reset / re-enrolment).
     * A hard delete so a stale vector can never match again.
     */
    public static function deleteForEmployee(int $employeeId, int $customerId): int
    {
        $count = self::countFor($employeeId, $customerId);

        Database::run(
            'DELETE FROM `' . self::tableName() . '` WHERE `employee_id` = ? AND `customer_id` = ?',
            [$employeeId, $customerId]
        );

        Logger::channel('app')->info('Face embeddings removed', [
            'employee_id' => $employeeId,
            'customer_id' => $customerId,
            'removed'     => $count,
        ]);

        return $count;
    }

    /**
     * Mark rows as revoked instead of deleting (kept for the "invalidate"
     * semantic; deleteForEmployee() is used by the reset flow).
     */
    public static function revokeForEmployee(int $employeeId, int $customerId): int
    {
        return (int) Database::run(
            'UPDATE `' . self::tableName() . '` SET `revoked_at` = NOW()'
            . ' WHERE `employee_id` = ? AND `customer_id` = ? AND `revoked_at` IS NULL',
            [$employeeId, $customerId]
        )->rowCount();
    }

    /**
     * The dataset a device is allowed to know: full samples of ACTIVE
     * employees of ONE customer (optionally one branch). Nothing else.
     *
     * @param array<int,int> $employeeIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function datasetForEmployees(array $employeeIds, int $customerId, string $modelVersion = ''): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

        $sql = 'SELECT `employee_id`, `sample_index`, `embedding`, `model_version`, `quality`, `dimensions`'
            . ' FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `revoked_at` IS NULL AND `employee_id` IN (' . $placeholders . ')';

        $params = array_merge([$customerId], array_map('intval', $employeeIds));

        if ($modelVersion !== '') {
            $sql .= ' AND `model_version` = ?';
            $params[] = $modelVersion;
        }

        $sql .= ' ORDER BY `employee_id` ASC, `sample_index` ASC';

        $out = [];

        foreach (Database::select($sql, $params) as $row) {
            $vector = self::decode((string) $row['embedding']);

            if ($vector === []) {
                continue;
            }

            $out[(int) $row['employee_id']][] = [
                'sample_index'  => (int) $row['sample_index'],
                'embedding'     => $vector,
                'model_version' => (string) $row['model_version'],
                'quality'       => $row['quality'] === null ? null : (float) $row['quality'],
            ];
        }

        return $out;
    }

    /**
     * Validate + serialise a vector. Returns '' when the input is not a
     * usable descriptor, so callers can answer with a validation error.
     *
     * @param array<int,mixed> $vector
     */
    public static function encode(array $vector): string
    {
        if (count($vector) !== self::DIMENSIONS) {
            return '';
        }

        $clean = [];

        foreach ($vector as $value) {
            if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
                return '';
            }

            $number = (float) $value;

            // NaN / INF would poison every distance calculation.
            if (!is_finite($number) || abs($number) > 1000.0) {
                return '';
            }

            $clean[] = round($number, 6);
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
    }

    /**
     * @return array<int,float>
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || count($decoded) !== self::DIMENSIONS) {
            return [];
        }

        $out = [];

        foreach ($decoded as $value) {
            if (!is_numeric($value)) {
                return [];
            }

            $number = (float) $value;

            if (!is_finite($number)) {
                return [];
            }

            $out[] = $number;
        }

        return $out;
    }
}
