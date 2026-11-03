<?php
declare(strict_types=1);
// Isolated transport/settings tests; no real Telegram, database, or credentials.
namespace App\Core {
    class Config { public static array $values = []; public static function get($key, $default = null) { return self::$values[$key] ?? $default; } }
    class Database {
        public static array $row = [];
        public static array $queue = [];
        public static function table($table) { return 'attendance_' . $table; }
        public static function selectOne($sql, $params) { return self::$row ?: null; }
        public static function select($sql, $params) { return self::$queue; }
        public static function update($table, $data, $where) { if ($table === 'attendance_telegram_settings') self::$row = array_merge(self::$row, $data); return 1; }
        public static function insert($table, $data) { return 1; }
    }
    class Security { public static function decrypt($token) { return $token; } public static function encrypt($token) { return $token; } public static function uuid4() { return 'uuid'; } }
    class Logger { public static function channel($name) { return new self(); } public function warning(...$args) {} public function error(...$args) {} }
}
namespace App\Models { class AuditLog { public static function record(...$args) {} } }
namespace App\Services { class FeatureService { public static function allows(...$args) { return true; } } }
namespace {
    require __DIR__ . '/../app/Services/TelegramService.php';
    use App\Services\TelegramService;
    use App\Core\Config;
    use App\Core\Database;
    function check($cond, $message) { if (!$cond) throw new \RuntimeException($message); }
    $service = new TelegramService();
    $seen = [];
    $service->setTransport(function($url, $body, $proxy) use (&$seen) {
        $seen = [$url, $body, $proxy];
        return ['ok'=>true, 'status'=>200, 'body'=>'{"ok":true,"result":{"message_id":42}}'];
    });
    Config::$values = ['telegram.api_base_url'=>'https://global.example.com/path', 'telegram.proxy'=>'http://proxy.example.com:8080'];
    check($service->hasSharedGateway(), 'custom gateway is configured');
    $ans = $service->send('123456:abcdefghijklmnopqrst', '-123456', 'hello');
    check($ans['ok'] && str_starts_with($seen[0], 'https://global.example.com/path/bot'), 'global gateway');
    check($seen[2] === 'http://proxy.example.com:8080', 'global proxy');
    $service->send('123456:abcdefghijklmnopqrst', '-123456', 'hello', ['api_base_url'=>'https://tenant.example.com', 'proxy_url'=>'socks5://proxy.example.com:1080']);
    check(str_starts_with($seen[0], 'https://global.example.com/path/bot') && $seen[2] === 'http://proxy.example.com:8080', 'legacy tenant overrides ignored');
    Config::$values = [];
    check(!$service->hasSharedGateway(), 'no badge when unconfigured');
    $service->send('x', 'x', 'x', ['api_base_url'=>'https://tenant.example.com', 'proxy_url'=>'socks5://proxy.example.com:1080']);
    check(str_starts_with($seen[0], 'https://api.telegram.org/bot') && $seen[2] === '', 'unconfigured fallback ignores tenant');
    Config::$values = ['telegram.api_base_url'=>'https://api.telegram.org/'];
    check(!$service->hasSharedGateway(), 'official API is not a custom gateway');
    Config::$values = ['telegram.api_base_url'=>'https://api.telegram.org:443/custom'];
    check(!$service->hasSharedGateway(), 'official host is not an external gateway');
    foreach (['http://example.com', 'https://127.0.0.1', 'https://localhost', 'https://user:pass@example.com', 'https://example.com/?q=x'] as $bad) {
        Config::$values = ['telegram.api_base_url'=>$bad];
        check(!$service->hasSharedGateway(), 'invalid gateway no badge');
        check($service->send('x', 'x', 'x')['error_code'] === 'TELEGRAM_TRANSPORT_INVALID', 'invalid global gateway');
    }
    Config::$values = ['telegram.api_base_url'=>'https://global.example.com/path', 'telegram.proxy'=>'http://127.0.0.1:8080'];
    check($service->send('x', 'x', 'x')['error_code'] === 'TELEGRAM_TRANSPORT_INVALID', 'private global proxy');
    Config::$values = ['telegram.api_base_url'=>'https://global.example.com/path'];
    foreach ([1, 2] as $customerId) {
        Database::$row = ['id'=>$customerId,'customer_id'=>$customerId,'bot_token_cipher'=>'123456:abcdefghijklmnopqrst','chat_id'=>'-123456','api_base_url'=>'https://tenant.example.com','proxy_url'=>'socks5://proxy.example.com:1080'];
        $res = $service->testSend($customerId);
        check($res['ok'] && str_starts_with($seen[0], 'https://global.example.com/path/bot') && $seen[2] === '', 'testSend uses shared gateway for each customer');
        Database::$queue = [['id'=>9,'customer_id'=>$customerId,'payload'=>'{"text":"hello"}', 'event'=>'attendance.entry','chat_id'=>'-123456','attempts'=>0,'max_attempts'=>3]];
        $result = $service->drain();
        check($result['sent'] === 1 && str_starts_with($seen[0], 'https://global.example.com/path/bot') && $seen[2] === '', 'worker uses shared gateway for each customer');
    }
    $service->setTransport(function($url, $body, $proxy) { return ['ok'=>false,'status'=>0,'body'=>'','error'=>'Connection timed out']; });
    $res = $service->testSend(1);
    check(!$res['ok'] && str_contains($res['message'], 'Connection timed out') && str_contains($res['message'], 'مدیر سامانه'), 'network diagnostic');
    $service->setTransport(function($url, $body, $proxy) { return ['ok'=>false,'status'=>401,'body'=>'{"ok":false,"error_code":401,"description":"Unauthorized"}']; });
    $res = $service->testSend(1);
    check($res['code'] === 'TELEGRAM_401' && str_contains($res['message'], 'Unauthorized'), 'Telegram API error');
    check(!str_contains($service->redact('bad 123456:abcdefghijklmnopqrst'), 'abcdefghijklmnopqrst'), 'redaction');
    $before = Database::$row;
    $saved = $service->saveSettings([], 1, ['api_base_url'=>'https://other.example.com', 'proxy_url'=>'http://localhost:8080', 'chat_id'=>'-123456']);
    check($saved['ok'] && Database::$row['api_base_url'] === $before['api_base_url'] && Database::$row['proxy_url'] === $before['proxy_url'], 'legacy transport input ignored and columns preserved');
    unset(Database::$row['api_base_url'], Database::$row['proxy_url']);
    check($service->saveSettings([], 1, ['api_base_url'=>'https://other.example.com'])['ok'], 'old schema needs no migration');
    echo "Telegram shared gateway, worker, compatibility and diagnostics tests passed\n";
}
