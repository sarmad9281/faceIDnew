<?php
declare(strict_types=1);
// Isolated transport/settings tests; no real Telegram, database, or credentials.
namespace App\Core {
    class Config { public static array $values = []; public static function get($key, $default = null) { return self::$values[$key] ?? $default; } }
    class Database {
        public static array $row = [];
        public static function table($table) { return 'attendance_' . $table; }
        public static function selectOne($sql, $params) { return self::$row ?: null; }
        public static function update($table, $data, $where) { self::$row = array_merge(self::$row, $data); return 1; }
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
    $ans = $service->send('123456:abcdefghijklmnopqrst', '-123456', 'hello');
    check($ans['ok'] && str_starts_with($seen[0], 'https://global.example.com/path/bot'), 'global gateway');
    check($seen[2] === 'http://proxy.example.com:8080', 'global proxy');
    $service->send('123456:abcdefghijklmnopqrst', '-123456', 'hello', ['api_base_url'=>'https://tenant.example.com', 'proxy_url'=>'socks5://proxy.example.com:1080']);
    check(str_starts_with($seen[0], 'https://tenant.example.com/bot') && str_starts_with($seen[2], 'socks5://'), 'tenant precedence');
    foreach (['http://example.com', 'https://127.0.0.1', 'https://localhost', 'https://user:pass@example.com', 'https://example.com/?q=x'] as $bad) {
        check($service->send('x', 'x', 'x', ['api_base_url'=>$bad])['error_code'] === 'TELEGRAM_TRANSPORT_INVALID', 'invalid gateway ' . $bad);
    }
    check($service->send('x', 'x', 'x', ['proxy_url'=>'http://127.0.0.1:8080'])['error_code'] === 'TELEGRAM_TRANSPORT_INVALID', 'private proxy');
    Database::$row = ['id'=>1,'customer_id'=>1,'bot_token_cipher'=>'123456:abcdefghijklmnopqrst','chat_id'=>'-123456','api_base_url'=>'https://tenant.example.com','proxy_url'=>''];
    $service->setTransport(function($url, $body, $proxy) { return ['ok'=>false,'status'=>0,'body'=>'','error'=>'Connection timed out']; });
    $res = $service->testSend(1);
    check(!$res['ok'] && str_contains($res['message'], 'Connection timed out') && str_contains($res['message'], 'پروکسی'), 'diagnostic');
    $service->setTransport(function($url, $body, $proxy) { return ['ok'=>false,'status'=>401,'body'=>'{"ok":false,"error_code":401,"description":"Unauthorized"}']; });
    $res = $service->testSend(1);
    check($res['code'] === 'TELEGRAM_401' && str_contains($res['message'], 'Unauthorized'), 'Telegram API error');
    check(!str_contains($service->redact('bad 123456:abcdefghijklmnopqrst'), 'abcdefghijklmnopqrst'), 'redaction');
    check(!$service->saveSettings([], 1, ['api_base_url'=>'http://localhost'])['ok'], 'save validation');
    $saved = $service->saveSettings([], 1, ['api_base_url'=>'https://gateway.example.com', 'proxy_url'=>'socks5://proxy.example.com:1080']);
    check($saved['ok'] && Database::$row['api_base_url'] === 'https://gateway.example.com', 'customer settings saved');
    $service->saveSettings([], 1, ['chat_id'=>'-123456']);
    check(Database::$row['proxy_url'] === 'socks5://proxy.example.com:1080', 'legacy save preserves transport');
    $service->saveSettings([], 1, ['proxy_url'=>'']);
    check(Database::$row['proxy_url'] === null, 'empty setting restores global fallback');
    unset(Database::$row['api_base_url'], Database::$row['proxy_url']);
    check($service->saveSettings([], 1, ['api_base_url'=>'https://gateway.example.com'])['code'] === 'MIGRATION_REQUIRED', 'old schema requires migration');
    check($service->saveSettings([], 1, ['api_base_url'=>'', 'proxy_url'=>''])['ok'], 'old schema supports old settings');
    echo "Telegram isolated gateway/proxy/diagnostics tests passed\n";
}
