<?php
/**
 * SARMAD Attendance - face dataset service (stage 5).
 *
 * This service owns the *server* half of face recognition:
 *
 *   1. store the three descriptors a browser produced locally,
 *   2. keep employees.face_status / face_samples_count in sync (0/3 .. 3/3),
 *   3. hand an AUTHENTICATED DEVICE the descriptors of its own customer only
 *      (branch scoped when the deployment asks for it),
 *   4. version the dataset (customers.face_data_version) so every device
 *      notices a reset / new enrolment / disabled employee and re-syncs.
 *
 * It never sees a camera frame, never runs a model and never sends an
 * embedding to a public endpoint. Matching happens on the device.
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeviceLog;
use App\Models\Employee;
use App\Models\EmployeeFace;
use App\Models\FaceEmbedding;
use App\Models\RecognitionProof;
use App\Services\AuditService;
use App\Support\Url;

final class FaceDatasetService
{
    public const OK                    = 'OK';
    public const ERR_SAMPLE_INDEX      = 'FACE_SAMPLE_INDEX_INVALID';
    public const ERR_EMBEDDING         = 'FACE_EMBEDDING_INVALID';
    public const ERR_MODEL_VERSION     = 'FACE_MODEL_VERSION_INVALID';
    public const ERR_IMAGE             = 'FACE_IMAGE_INVALID';
    public const ERR_NOT_CONSISTENT     = 'FACE_ENROLLMENT_MISMATCH';
    public const ERR_EMPLOYEE          = 'EMPLOYEE_NOT_FOUND';

    /* =================================================================
     * Configuration accessors - ONE source of truth (config/config.php)
     * ===============================================================*/

    public static function config(): array
    {
        $face = Config::get('face', []);

        return is_array($face) ? $face : [];
    }

    public static function value(string $key, $default = null)
    {
        $value = Config::get('face.' . $key, $default);

        return $value === null ? $default : $value;
    }

    public static function modelVersion(): string
    {
        return (string) self::value('model_version', 'face-api.js@0.22.2/face_recognition-128d/v1');
    }

    public static function threshold(): float
    {
        return (float) self::value('match_threshold', 0.55);
    }

    public static function ambiguityMargin(): float
    {
        return (float) self::value('ambiguity_margin', 0.06);
    }

    /**
     * Everything the browser engine needs (device + enrolment wizard).
     * Values come from config/config.php only - never hard coded in JS.
     *
     * @return array<string,mixed>
     */
    public static function clientConfig(): array
    {
        $face = self::config();

        return [
            'engine'              => (string) ($face['engine'] ?? 'face-api.js'),
            'model_version'       => self::modelVersion(),
            // Web paths, not filesystem paths: the browser must load the
            // weights from THIS project (assets/models/...) whichever page
            // depth it is on. Never a CDN.
            'model_path'          => Url::to('/' . trim((string) ($face['model_path'] ?? 'assets/models/face-api-1.0.0'), '/')),
            'library_paths'       => array_values(array_map(
                static fn (string $path): string => Url::to('/' . trim($path, '/')),
                (array) ($face['library_paths'] ?? [])
            )),
            'detector'            => (string) ($face['detector'] ?? 'tiny_face_detector'),
            'metric'              => 'euclidean',
            'dimensions'          => FaceEmbedding::DIMENSIONS,
            'samples_required'    => (int) ($face['samples_required'] ?? FaceEmbedding::SAMPLES),
            'match_threshold'     => self::threshold(),
            'ambiguity_margin'    => self::ambiguityMargin(),
            'enrollment_consistency' => (float) ($face['enrollment_consistency'] ?? 0.65),
            'detect_interval_ms'  => (int) ($face['detect_interval_ms'] ?? 350),
            'detector_input_size' => (int) ($face['detector_input_size'] ?? 320),
            'recognition_input_size' => (int) ($face['recognition_input_size'] ?? 416),
            'min_face_ratio'      => (float) ($face['min_face_ratio'] ?? 0.16),
            'min_face_pixels'     => (int) ($face['min_face_pixels'] ?? 80),
            'max_center_offset'   => (float) ($face['max_center_offset'] ?? 0.34),
            'blur_threshold'      => (float) ($face['blur_threshold'] ?? 45.0),
            'min_brightness'      => (float) ($face['min_brightness'] ?? 35.0),
            'max_brightness'      => (float) ($face['max_brightness'] ?? 225.0),
            'max_yaw_ratio'       => (float) ($face['max_yaw_ratio'] ?? 0.34),
            'store_image'         => (bool) ($face['store_enrollment_image'] ?? true),
            'image_max_px'        => (int) ($face['enrollment_image_max_px'] ?? 320),
            'device_cache'        => (bool) ($face['device_cache'] ?? true),
            'liveness'            => 'not_used',
        ];
    }

    /* =================================================================
     * Versioning
     * ===============================================================*/

    public static function version(int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT `face_data_version` FROM `' . Database::table('customers') . '` WHERE `id` = ?',
            [$customerId]
        );
    }

    /**
     * Increase the customer's face data version. Called on EVERY change that
     * a device must notice: new sample, reset, employee created/disabled.
     */
    public static function bumpVersion(int $customerId, string $reason, int $actorId = 0, string $actorType = 'system'): int
    {
        if ($customerId <= 0) {
            return 0;
        }

        Database::run(
            'UPDATE `' . Database::table('customers') . '`'
            . ' SET `face_data_version` = `face_data_version` + 1 WHERE `id` = ?',
            [$customerId]
        );

        $version = self::version($customerId);

        Logger::channel('app')->info('Face dataset version bumped', [
            'customer_id' => $customerId,
            'version'     => $version,
            'reason'      => $reason,
            'actor_id'    => $actorId,
        ]);

        return $version;
    }

    /* =================================================================
     * Enrolment
     * ===============================================================*/

    /**
     * Store ONE of the three samples of an employee.
     *
     * @param array<string,mixed> $employee tenant-checked employee row
     * @param array<string,mixed> $input    sample_index, embedding, model_version, quality, image (data URL)
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>}
     */
    public function enrollSample(array $employee, array $input, int $actorId = 0, string $actorType = 'manager'): array
    {
        $employeeId = (int) ($employee['id'] ?? 0);
        $customerId = (int) ($employee['customer_id'] ?? 0);

        if ($employeeId <= 0 || $customerId <= 0) {
            return $this->fail(self::ERR_EMPLOYEE, 'کارمند یافت نشد.');
        }

        $index = (int) ($input['sample_index'] ?? 0);

        if ($index < FaceEmbedding::SAMPLE_MIN || $index > FaceEmbedding::SAMPLE_MAX) {
            return $this->fail(self::ERR_SAMPLE_INDEX, 'شماره نمونه چهره باید ۱، ۲ یا ۳ باشد.');
        }

        $vector = $input['embedding'] ?? null;

        if (!is_array($vector)) {
            return $this->fail(self::ERR_EMBEDDING, 'بردار چهره ارسال نشده است.');
        }

        $encoded = FaceEmbedding::encode($vector);

        if ($encoded === '') {
            return $this->fail(self::ERR_EMBEDDING, 'بردار چهره نامعتبر است (باید '
                . FaceEmbedding::DIMENSIONS . ' عدد معتبر باشد).');
        }

        $modelVersion = trim((string) ($input['model_version'] ?? ''));

        if ($modelVersion === '' || strlen($modelVersion) > 60) {
            return $this->fail(self::ERR_MODEL_VERSION, 'نسخه مدل چهره مشخص نیست.');
        }

        $quality = isset($input['quality']) && is_numeric($input['quality'])
            ? max(0.0, min(1.0, (float) $input['quality']))
            : null;

        $clean   = FaceEmbedding::decode($encoded);
        $stored  = FaceEmbedding::descriptorsFor($employeeId, $customerId);

        // A sample that does not belong to the same person as the earlier
        // samples of this employee is refused - this protects against
        // enrolling the wrong face into somebody's profile.
        $consistency = (float) self::value('enrollment_consistency', 0.65);
        $closest     = null;

        foreach ($stored as $sample) {
            if ((int) $sample['sample_index'] === $index) {
                continue;
            }

            $distance = $this->euclidean($clean, $sample['embedding']);

            if ($closest === null || $distance < $closest) {
                $closest = $distance;
            }
        }

        if ($closest !== null && $closest > $consistency) {
            AuditLog::record('employee.face.rejected', 'failure',
                'نمونه چهره «' . Employee::fullName($employee) . '» با نمونه‌های قبلی یکسان نبود.',
                ['employee_id' => $employeeId, 'sample_index' => $index, 'distance' => round($closest, 4)],
                'employee', 'employee', $employeeId, $customerId);

            return $this->fail(self::ERR_NOT_CONSISTENT,
                'این چهره با نمونه‌های قبلی همین کارمند هم‌خوانی ندارد؛ لطفاً مطمئن شوید چهره همان فرد است.',
                ['distance' => round((float) $closest, 4)]);
        }

        // Optional enrolment snapshot (never used for matching).
        $imageId = null;

        if (isset($input['image']) && is_string($input['image']) && $input['image'] !== '') {
            $stored = $this->storeEnrollmentImage($employee, $input['image'], $index, $actorId);

            if (!$stored['ok']) {
                return $this->fail(self::ERR_IMAGE, $stored['message']);
            }

            $imageId = $stored['image_id'];
        }

        $saved = FaceEmbedding::saveSample([
            'customer_id'   => $customerId,
            'employee_id'   => $employeeId,
            'sample_index'  => $index,
            'embedding'     => $clean,
            'model_version' => $modelVersion,
            'engine'        => (string) ($input['engine'] ?? 'face-api.js'),
            'detector'      => (string) ($input['detector'] ?? 'unknown'),
            'metric'        => (string) ($input['metric'] ?? 'euclidean'),
            'quality'       => $quality,
            'image_id'      => $imageId,
            'source'        => 'enrollment',
            'created_by'    => $actorId,
        ]);

        $status = $this->refreshEmployeeFaceState($employeeId, $customerId);
        $count  = FaceEmbedding::countFor($employeeId, $customerId);
        $version = self::bumpVersion($customerId, 'sample_enrolled', $actorId, $actorType);

        AuditLog::record('employee.face.enrolled', 'success',
            'نمونه چهره ' . $index . ' «' . Employee::fullName($employee) . '» ثبت شد (' . $count . '/3).',
            [
                'employee_id'   => $employeeId,
                'sample_index'  => $index,
                'samples'       => $count,
                'quality'       => $quality,
                'model_version' => $modelVersion,
                'face_version'  => $version,
            ],
            'employee', 'employee', $employeeId, $customerId);

        return [
            'ok'      => true,
            'code'    => self::OK,
            'message' => 'نمونه ' . $index . ' ثبت شد.',
            'data'    => [
                'employee_id'    => $employeeId,
                'sample_index'   => $index,
                'samples'        => $count,
                'required'       => FaceEmbedding::SAMPLES,
                'face_status'    => $status,
                'embedding_id'   => $saved,
                'face_version'   => $version,
                'has_image'      => $imageId !== null,
                'photo_url'      => $imageId !== null ? '/manager/employees/' . $employeeId . '/face-photo/' . $index : null,
            ],
        ];
    }

    /**
     * Reset the face registration of ONE employee: the old embeddings are
     * deleted (not just hidden) and the customer version changes so every
     * device drops its cached gallery and re-syncs.
     *
     * @param array<string,mixed> $employee
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>}
     */
    public function resetForEmployee(array $employee, int $actorId = 0, string $actorType = 'manager'): array
    {
        $employeeId = (int) ($employee['id'] ?? 0);
        $customerId = (int) ($employee['customer_id'] ?? 0);

        if ($employeeId <= 0 || $customerId <= 0) {
            return $this->fail(self::ERR_EMPLOYEE, 'کارمند یافت نشد.');
        }

        $removed = FaceEmbedding::deleteForEmployee($employeeId, $customerId);

        // Enrolment snapshots go away with the vectors: they are the same
        // biometric material and must not survive a reset.
        $images = $this->deleteEnrollmentImages($employeeId, $customerId);

        $status  = $this->refreshEmployeeFaceState($employeeId, $customerId);
        $version = self::bumpVersion($customerId, 'face_reset', $actorId, $actorType);

        AuditLog::record('employee.face.reset', 'success',
            'ثبت چهره «' . Employee::fullName($employee) . '» بازنشانی شد (' . $removed . ' نمونه حذف شد).',
            [
                'employee_id' => $employeeId,
                'removed'     => $removed,
                'images'      => $images,
                'face_version' => $version,
            ],
            'employee', 'employee', $employeeId, $customerId);

        return [
            'ok'      => true,
            'code'    => self::OK,
            'message' => 'ثبت چهره بازنشانی شد و باید سه نمونه جدید ثبت شود.',
            'data'    => [
                'employee_id'  => $employeeId,
                'removed'      => $removed,
                'images'       => $images,
                'samples'      => 0,
                'required'     => FaceEmbedding::SAMPLES,
                'face_status'  => $status,
                'face_version' => $version,
            ],
        ];
    }

    /**
     * employees.face_status is the fast read path shown everywhere:
     *   not_enrolled (0/3) | partial (1-2/3) | complete (3/3)
     * It is derived from the EMBEDDINGS, because only embeddings can be
     * matched - an image without a descriptor is not "enrolled".
     */
    public function refreshEmployeeFaceState(int $employeeId, int $customerId): string
    {
        $count  = FaceEmbedding::countFor($employeeId, $customerId);
        $status = $count <= 0 ? 'not_enrolled' : ($count >= FaceEmbedding::SAMPLES ? 'complete' : 'partial');

        $model = '';

        if ($count > 0) {
            $sample = Database::selectOne(
                'SELECT `model_version` FROM `' . Database::table('face_embeddings') . '`'
                . ' WHERE `employee_id` = ? AND `customer_id` = ? AND `revoked_at` IS NULL'
                . ' ORDER BY `updated_at` DESC LIMIT 1',
                [$employeeId, $customerId]
            );

            $model = $sample === null ? '' : (string) $sample['model_version'];
        }

        Database::run(
            'UPDATE `' . Database::table('employees') . '`'
            . ' SET `face_enrolled` = ?, `face_status` = ?, `face_samples_count` = ?, `face_model_version` = ?'
            . ' WHERE `id` = ? AND `customer_id` = ?',
            [$count > 0 ? 1 : 0, $status, $count, $model === '' ? null : $model, $employeeId, $customerId]
        );

        return $status;
    }

    /**
     * 0/3 .. 3/3 + the model version, from the embeddings table.
     *
     * @return array{count:int,required:int,status:string,label:string,model_version:string}
     */
    public function stateForEmployee(int $employeeId, int $customerId): array
    {
        $count = FaceEmbedding::countFor($employeeId, $customerId);
        $status = $count <= 0 ? 'not_enrolled' : ($count >= FaceEmbedding::SAMPLES ? 'complete' : 'partial');

        $model = (string) Database::scalar(
            'SELECT `model_version` FROM `' . Database::table('face_embeddings') . '`'
            . ' WHERE `employee_id` = ? AND `customer_id` = ? AND `revoked_at` IS NULL'
            . ' ORDER BY `updated_at` DESC LIMIT 1',
            [$employeeId, $customerId]
        );

        return [
            'count'         => $count,
            'required'      => FaceEmbedding::SAMPLES,
            'status'        => $status,
            'label'         => $count . ' / ' . FaceEmbedding::SAMPLES,
            'model_version' => $model,
        ];
    }

    /* =================================================================
     * Device sync (tenant + branch scoped)
     * ===============================================================*/

    /**
     * The gallery a device may hold. Contains ONLY:
     *   - employees of the device's own customer,
     *   - that are active and not deleted,
     *   - whose face registration is complete (3/3),
     *   - optionally limited to the device's branch,
     *   - with descriptors of the CURRENT model version.
     *
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function datasetForDevice(array $device): array
    {
        $customerId = (int) $device['customer_id'];
        $deviceId   = (int) $device['id'];
        $branchId   = isset($device['branch_id']) ? (int) $device['branch_id'] : 0;
        $version    = self::version($customerId);

        $branchScoped = (bool) self::value('sync_branch_scoped', true);
        $branchFeature = function_exists('feature_enabled') && \feature_enabled($customerId, 'branches');

        $sql = 'SELECT `id`, `uuid`, `first_name`, `last_name`, `employee_code`, `branch_id`,'
            . ' `face_status`, `face_samples_count`, `photo_path`'
            . ' FROM `' . Database::table('employees') . '`'
            . ' WHERE `customer_id` = ? AND `deleted_at` IS NULL AND `status` = ?'
            . ' AND `face_status` = ? AND `face_samples_count` = ?';

        $params = [$customerId, 'active', 'complete', FaceEmbedding::SAMPLES];

        if ($branchScoped && $branchFeature && $branchId > 0) {
            $sql .= ' AND `branch_id` = ?';
            $params[] = $branchId;
        }

        $sql .= ' ORDER BY `id` ASC';

        $rows = Database::select($sql, $params);

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        $dataset = FaceEmbedding::datasetForEmployees($ids, $customerId, self::modelVersion());

        $employees = [];

        foreach ($rows as $row) {
            $employeeId = (int) $row['id'];
            $samples    = $dataset[$employeeId] ?? [];

            // A device must never receive a half-enrolled employee.
            if (count($samples) !== FaceEmbedding::SAMPLES) {
                continue;
            }

            $employees[] = [
                'employee_id'   => $employeeId,
                'uuid'          => (string) $row['uuid'],
                'first_name'    => (string) $row['first_name'],
                'last_name'     => (string) $row['last_name'],
                'employee_code' => $row['employee_code'] === null ? null : (string) $row['employee_code'],
                'branch_id'     => $row['branch_id'] === null ? null : (int) $row['branch_id'],
                'has_photo'     => (string) ($row['photo_path'] ?? '') !== '' || $this->hasEnrollmentImage($employeeId, $customerId),
                'photo_url'     => '/api/v1/device/employees/' . $employeeId . '/photo',
                'samples'       => array_map(
                    static fn (array $sample): array => [
                        'sample_index' => (int) $sample['sample_index'],
                        'embedding'    => $sample['embedding'],
                    ],
                    $samples
                ),
            ];
        }

        return [
            'version'          => $version,
            'model_version'    => self::modelVersion(),
            'metric'           => 'euclidean',
            'dimensions'       => FaceEmbedding::DIMENSIONS,
            'samples_required' => FaceEmbedding::SAMPLES,
            'threshold'        => self::threshold(),
            'ambiguity_margin' => self::ambiguityMargin(),
            'branch_scoped'    => $branchScoped && $branchFeature && $branchId > 0,
            'branch_id'        => $branchId > 0 ? $branchId : null,
            'employees'        => $employees,
            'generated_at'     => date('c'),
        ];
    }

    /**
     * Cheap poll: has the dataset changed since the device last synced?
     *
     * @param array<string,mixed> $device
     */
    public function versionInfoForDevice(array $device): array
    {
        $customerId = (int) $device['customer_id'];
        $version    = self::version($customerId);
        $synced     = (int) ($device['face_synced_version'] ?? 0);

        return [
            'version'       => $version,
            'synced_version' => $synced,
            'model_version' => self::modelVersion(),
            'changed'       => $version !== $synced,
        ];
    }

    /**
     * The device acknowledged a sync up to $version.
     */
    public function markDeviceSynced(array $device, int $version, int $employeeCount): void
    {
        Database::run(
            'UPDATE `' . Database::table('devices') . '`'
            . ' SET `face_synced_version` = ?, `face_synced_at` = NOW() WHERE `id` = ?',
            [$version, (int) $device['id']]
        );

        DeviceLog::record('device.face.sync', 'info',
            'داده چهره هم‌گام شد (' . $employeeCount . ' کارمند، نسخه ' . $version . ').',
            (int) $device['id'], (int) $device['customer_id'],
            ['version' => $version, 'employees' => $employeeCount]);
    }

    /* =================================================================
     * Recognition result verification (server side of a LOCAL match)
     * ===============================================================*/

    /**
     * A device matched a face locally and reports WHICH employee it saw.
     * The server cannot re-run the model (no image is sent on purpose), so
     * it does what it CAN and MUST verify:
     *
     *   - the employee belongs to the device's customer (tenant isolation),
     *   - the employee is inside the device's branch scope,
     *   - the employee is active and not deleted,
     *   - the face registration is really complete (3/3) for the CURRENT model,
     *   - no PIN / password fallback exists: a claim without a passing local
     *     confidence is rejected here as well.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>}
     */
    public function verifyRecognition(array $device, array $payload): array
    {
        $customerId = (int) $device['customer_id'];
        $outcome    = (string) ($payload['outcome'] ?? '');

        $allowed = ['matched', 'no_face', 'multiple_faces', 'unknown_face', 'ambiguous'];

        if (!in_array($outcome, $allowed, true)) {
            return $this->fail('RECOGNITION_OUTCOME_INVALID', 'نتیجه شناسایی نامعتبر است.');
        }

        if ($outcome !== 'matched') {
            $messages = [
                'no_face'        => 'چهره‌ای شناسایی نشد. لطفاً روبه‌روی دوربین قرار بگیرید.',
                'multiple_faces' => 'لطفاً فقط یک نفر مقابل دوربین قرار بگیرد.',
                'unknown_face'   => 'چهره شما در سیستم ثبت نشده است. لطفاً با مسئول مجموعه تماس بگیرید.',
                'ambiguous'      => 'شناسایی چهره با اطمینان کافی انجام نشد. لطفاً دوباره تلاش کنید.',
            ];

            return [
                'ok'      => true,
                'code'    => strtoupper($outcome),
                'message' => $messages[$outcome],
                'data'    => [
                    'outcome'        => $outcome,
                    'matched'        => false,
                    'attendance_ready' => false,
                ],
            ];
        }

        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $confidence = isset($payload['confidence']) && is_numeric($payload['confidence'])
            ? (float) $payload['confidence']
            : null;
        $distance   = isset($payload['distance']) && is_numeric($payload['distance'])
            ? (float) $payload['distance']
            : null;

        if ($employeeId <= 0) {
            return $this->fail('EMPLOYEE_REQUIRED', 'شناسه کارمند ارسال نشده است.');
        }

        $employee = Employee::findInCustomer($employeeId, $customerId);

        if ($employee === null || (int) ($employee['customer_id'] ?? 0) !== $customerId) {
            return $this->fail(self::ERR_EMPLOYEE, 'این کارمند در این مجموعه ثبت نشده است.');
        }

        // Branch scope: a device of branch A cannot claim an employee of branch B.
        if (!Branch::deviceMayUseEmployee($device, $employee)) {
            return $this->fail('BRANCH_MISMATCH', 'این کارمند به شعبه دیگری تعلق دارد.');
        }

        if (empty($employee['deleted_at']) === false) {
            return $this->fail(self::ERR_EMPLOYEE, 'این کارمند حذف شده است.');
        }

        // Confidence must be a real number and inside the configured threshold.
        if ($distance !== null) {
            $threshold = self::threshold();

            if ($distance > $threshold) {
                return $this->fail('UNKNOWN_FACE',
                    'چهره شما در سیستم ثبت نشده است. لطفاً با مسئول مجموعه تماس بگیرید.',
                    ['distance' => round($distance, 4), 'threshold' => $threshold]);
            }
        }

        $state = $this->stateForEmployee($employeeId, $customerId);

        if ($state['count'] !== FaceEmbedding::SAMPLES || $state['model_version'] !== self::modelVersion()) {
            return $this->fail('EMPLOYEE_FACE_INCOMPLETE',
                'ثبت چهره این کارمند کامل نیست؛ لطفاً با مسئول مجموعه تماس بگیرید.',
                ['samples' => $state['count'], 'required' => FaceEmbedding::SAMPLES]);
        }

        // A disabled (suspended / terminated) employee is NOT recognisable:
        // the kiosk must never show a success screen for him and attendance
        // must not be reachable. Nothing about him is echoed back.
        if (!Employee::canAttend($employee)) {
            return $this->fail('EMPLOYEE_INACTIVE',
                'این کارمند غیرفعال است. لطفاً با مسئول مجموعه تماس بگیرید.',
                ['employee_id' => $employeeId]);
        }

        $attendanceReady = function_exists('feature_enabled')
            ? \feature_enabled($customerId, 'attendance')
            : true;

        // ---- stage 6: hand back a ONE TIME proof --------------------------
        // The recognised employee id on its own is worth nothing: the kiosk
        // spends this proof on the entry/exit call and the server consumes it
        // inside the same transaction that writes the record. A replayed,
        // expired or forged proof can never register attendance.
        $action = strtolower(trim((string) ($payload['action'] ?? '')));

        if (!in_array($action, ['entry', 'exit'], true)) {
            $action = 'any';
        }

        $proof = null;

        if ($attendanceReady) {
            $proof = RecognitionProof::issue($device, $employee, $action, [
                'request_id'    => (string) ($payload['request_id'] ?? ''),
                'confidence'    => $confidence,
                'distance'      => $distance,
                'model_version' => $state['model_version'],
                'ip'            => (string) \App\Core\Request::ip(),
            ]);
        }

        return [
            'ok'      => true,
            'code'    => 'FACE_MATCHED',
            'message' => 'چهره شناسایی شد.',
            'data'    => [
                'outcome'          => 'matched',
                'matched'          => true,
                'proof'            => $proof === null ? null : $proof['uuid'],
                'proof_expires_at' => $proof === null ? null : $proof['expires_at'],
                'proof_ttl'        => $proof === null ? null : $proof['ttl'],
                'action'           => $action,
                'employee'         => [
                    'id'         => $employeeId,
                    'uuid'       => (string) $employee['uuid'],
                    'first_name' => (string) $employee['first_name'],
                    'last_name'  => (string) $employee['last_name'],
                    'full_name'  => Employee::fullName($employee),
                    'photo_url'  => $this->hasPhoto($employee, $customerId)
                        ? '/api/v1/device/employees/' . $employeeId . '/photo'
                        : null,
                ],
                'confidence'       => $confidence,
                'distance'         => $distance === null ? null : round($distance, 4),
                'threshold'        => self::threshold(),
                'samples'          => $state['count'],
                'employee_active'  => true,
                'attendance_ready' => $attendanceReady,
                'model_version'    => $state['model_version'],
            ],
        ];
    }

    /* =================================================================
     * Images
     * ===============================================================*/

    /**
     * Optional enrolment snapshot. Stored OUTSIDE the web root of any
     * listing, only reachable through a tenant-checked endpoint, and never
     * used for matching.
     *
     * @param array<string,mixed> $employee
     * @return array{ok:bool,message:string,image_id:int|null}
     */
    private function storeEnrollmentImage(array $employee, string $dataUrl, int $index, int $actorId): array
    {
        if (!(bool) self::value('store_enrollment_image', true)) {
            return ['ok' => true, 'message' => 'ok', 'image_id' => null];
        }

        $customer = Customer::find((int) $employee['customer_id']);
        $uuid     = is_array($customer) ? (string) $customer['uuid'] : '';

        if ($uuid === '') {
            return ['ok' => false, 'message' => 'مشتری یافت نشد.', 'image_id' => null];
        }

        $stored = UploadService::storeDataUrlImage(
            $dataUrl,
            $uuid,
            UploadService::OWNER_EMPLOYEE,
            (string) $employee['uuid'],
            'face',
            (int) self::value('enrollment_image_max_px', 320)
        );

        if (!$stored['ok']) {
            return ['ok' => false, 'message' => $stored['message'], 'image_id' => null];
        }

        $file     = $stored['relative'];
        $absolute = Config::path('uploads/' . $file);
        $size     = is_file($absolute) ? (int) filesize($absolute) : 0;
        $hash     = is_file($absolute) ? (string) hash_file('sha256', $absolute) : null;

        $existing = EmployeeFace::sample((int) $employee['id'], $index, (int) $employee['customer_id']);

        if ($existing !== null && (string) $existing['file_path'] !== '') {
            UploadService::delete((string) $existing['file_path']);
        }

        $imageId = EmployeeFace::saveSample([
            'customer_id'  => (int) $employee['customer_id'],
            'employee_id'  => (int) $employee['id'],
            'sample_index' => $index,
            'label'        => EmployeeFace::slotLabel($index),
            'file_path'    => $file,
            'mime_type'    => $stored['mime'],
            'file_size'    => $size,
            'image_hash'   => $hash,
            'status'       => EmployeeFace::STATUS_ENROLLED,
            'captured_at'  => date('Y-m-d H:i:s'),
            'created_by'   => $actorId,
        ]);

        return ['ok' => true, 'message' => 'ok', 'image_id' => $imageId];
    }

    private function deleteEnrollmentImages(int $employeeId, int $customerId): int
    {
        $removed = 0;

        foreach (Database::select(
            'SELECT * FROM `' . Database::table('employee_faces') . '`'
            . ' WHERE `employee_id` = ? AND `customer_id` = ?',
            [$employeeId, $customerId]
        ) as $row) {
            $path = (string) $row['file_path'];

            if ($path !== '') {
                UploadService::delete($path);
            }

            Database::run(
                'DELETE FROM `' . Database::table('employee_faces') . '` WHERE `id` = ?',
                [(int) $row['id']]
            );

            $removed++;
        }

        return $removed;
    }

    private function hasEnrollmentImage(int $employeeId, int $customerId): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . Database::table('employee_faces') . '`'
            . ' WHERE `employee_id` = ? AND `customer_id` = ?',
            [$employeeId, $customerId]
        ) > 0;
    }

    private function hasPhoto(array $employee, int $customerId): bool
    {
        return (string) ($employee['photo_path'] ?? '') !== ''
            || $this->hasEnrollmentImage((int) $employee['id'], $customerId);
    }

    /**
     * The device facing photo: employee profile photo if set, otherwise the
     * latest enrolment snapshot. Tenant checked by the caller.
     *
     * @return array{path:string,mime:string}|null
     */
    public function photoForEmployee(int $employeeId, int $customerId): ?array
    {
        $employee = Employee::findInCustomer($employeeId, $customerId);

        if ($employee === null) {
            return null;
        }

        $photo = (string) ($employee['photo_path'] ?? '');

        if ($photo !== '') {
            $absolute = Config::path('uploads/' . $photo);

            if (is_file($absolute)) {
                return ['path' => $absolute, 'mime' => $this->mimeOf($absolute)];
            }
        }

        $row = Database::selectOne(
            'SELECT `file_path`, `mime_type` FROM `' . Database::table('employee_faces') . '`'
            . ' WHERE `employee_id` = ? AND `customer_id` = ? ORDER BY `sample_index` ASC LIMIT 1',
            [$employeeId, $customerId]
        );

        if ($row === null) {
            return null;
        }

        $absolute = Config::path('uploads/' . (string) $row['file_path']);

        if (!is_file($absolute)) {
            return null;
        }

        return [
            'path' => $absolute,
            'mime' => (string) ($row['mime_type'] ?: $this->mimeOf($absolute)),
        ];
    }

    private function mimeOf(string $absolute): string
    {
        if (function_exists('mime_content_type')) {
            $mime = (string) mime_content_type($absolute);

            if ($mime !== '') {
                return $mime;
            }
        }

        return 'image/jpeg';
    }

    /* =================================================================
     * Math (server side, used for enrolment consistency + tests)
     * ===============================================================*/

    /**
     * @param array<int,float> $a
     * @param array<int,float> $b
     */
    public function euclidean(array $a, array $b): float
    {
        $sum = 0.0;
        $n   = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $d = $a[$i] - $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /**
     * @param array<int,float> $a
     * @param array<int,float> $b
     */
    public function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na  = 0.0;
        $nb  = 0.0;
        $n   = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }

        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    private function fail(string $code, string $message, array $extra = []): array
    {
        return [
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
            'data'    => array_merge(['outcome' => 'error'], $extra),
        ];
    }
}
