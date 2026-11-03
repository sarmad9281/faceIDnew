<?php
declare(strict_types=1);
// Standalone: php tests/device_permanent_token_test.php (no database).
namespace App\Core {
    class Security {
        public static function randomToken(int $n): string { return str_repeat('x', $n); }
        public static function hashToken(string $s): string { return hash('sha256', $s); }
    }
    class Logger {
        public static function channel(string $name): self { return new self(); }
        public function warning(string $message, array $context): void {}
    }
    class Request { public static function ip(): string { return '127.0.0.1'; } }
}
namespace App\Models {
    abstract class Model {
        public static array $row = [];
        public static function updateById(int $id, array $values): int { self::$row = array_merge(self::$row, $values); return 1; }
        public static function findBy(string $key, $value): ?array { return (self::$row[$key] ?? null) === $value ? self::$row : null; }
    }
    class Customer { public static bool $active = true; public static function isActive(int $id): bool { return self::$active; } }
    class DeviceLog { public static function record(...$args): void {} }
}
namespace {
    require __DIR__ . '/../app/Models/Device.php';
    require __DIR__ . '/../app/Services/DeviceService.php';
    use App\Models\Device;
    use App\Models\Model;
    use App\Models\Customer;
    use App\Services\DeviceService;
    function check(bool $ok, string $label): void { if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); } }
    Model::$row = ['id' => 7, 'customer_id' => 1, 'status' => Device::STATUS_ACTIVE];
    $token = Device::issueToken(7, 365);
    check($token['expires_at'] === null && Model::$row['token_expires_at'] === null, 'issued token is permanent');
    $service = new DeviceService();
    check($service->authenticateTokenDetailed($token['token'])['ok'], 'new token authenticates');
    Model::$row['token_expires_at'] = '2001-01-01 00:00:00';
    check($service->authenticateTokenDetailed($token['token'])['ok'], 'legacy expiry does not unpair');
    Model::$row['status'] = Device::STATUS_REVOKED;
    check($service->authenticateTokenDetailed($token['token'])['code'] === 'DEVICE_REVOKED', 'revocation enforced');
    Model::$row['status'] = Device::STATUS_DISABLED;
    check($service->authenticateTokenDetailed($token['token'])['code'] === 'DEVICE_INACTIVE', 'inactive enforced');
    Model::$row['status'] = Device::STATUS_ACTIVE;
    Customer::$active = false;
    check($service->authenticateTokenDetailed($token['token'])['code'] === 'CUSTOMER_DISABLED', 'customer suspension enforced');
    Customer::$active = true;
    check($service->authenticateTokenDetailed('att_wrong')['code'] === 'DEVICE_UNAUTHORIZED', 'rotated or unknown token refused');
    echo "Permanent device tokens: 7/7 checks passed\n";
}
