<?php
declare(strict_types=1);
// The real syncBatch path, with persistence and database constraint errors stubbed.
namespace App\Core {
    class Config { public static function get($k, $d = null) { return $d; } public static function path($p) { return '/tmp/faceid-stage16-' . basename($p); } }
    class Database {
        public static array $errors = [];
        public static function transaction($callback) { throw new \PDOException(array_shift(self::$errors)); }
    }
    class Logger { public static function channel($n) { return new self(); } public function warning(...$x) {} public function error(...$x) {} }
}
namespace App\Models {
    class AttendanceRecord { public static function byDeviceLocalEvent(...$args) { return null; } public static function byIdempotency(...$args) { return null; } }
    class DeviceLog { public static function record(...$args) {} }
}
namespace App\Services { class FaceDatasetService {} }
namespace {
    require __DIR__ . '/../app/Services/AttendanceEngine.php';
    $deviceId = random_int(100000, 999999);
    \App\Core\Database::$errors = ['uniq_records_open', 'uniq_records_device_event', 'another database error'];
    $engine = new \App\Services\AttendanceEngine();
    $events = [];
    foreach (['event-1', 'event-2', 'event-3'] as $id) $events[] = ['local_event_id'=>$id,'type'=>'entry','employee_id'=>5,'distance'=>0.2];
    $result = $engine->syncBatch(['id'=>$deviceId,'customer_id'=>1], ['events'=>$events]);
    $data = $result['data'];
    if ($data['duplicate'] !== 2 || $data['rejected'] !== 1 || array_column($data['records'], 'status') !== ['DUPLICATE','DUPLICATE','REJECTED']) throw new \RuntimeException('incorrect sync verdicts');
    foreach (['dev'.$deviceId, 'dev'.$deviceId.'-emp5'] as $key) @unlink(\App\Core\Config::path('storage/cache/attendance_rate_'.sha1($key).'.json'));
    echo "Offline sync duplicate/rejection verdicts passed\n";
}
