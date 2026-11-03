<?php
declare(strict_types=1);
// Render the actual manager view without a web server or database.
function renderTelegramView(bool $gateway): string
{
    $engine = [
        'settings' => ['chat_id' => '', 'api_base_url' => 'https://legacy.example.test', 'proxy_url' => 'socks5://legacy.example.test:1080'],
        'usable' => false, 'hasToken' => false, 'tokenMasked' => '', 'queueStats' => ['pending'=>0,'sent'=>0,'failed'=>0],
        'eventPending'=>0, 'lastOk'=>null, 'lastError'=>null, 'dailyTime'=>'23:55', 'isActive'=>false,
        'notify'=>['entry'=>false,'exit'=>false,'late'=>false,'overtime'=>false,'daily'=>false],
        'queue'=>[], 'events'=>[], 'logs'=>[],
    ];
    $sharedGatewayConfigured = $gateway;
    $notifications = []; $chatId = '';
    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $url = static fn($p) => $p;
    $csrf = static fn() => '<input type="hidden" name="csrf" value="test">';
    ob_start();
    require __DIR__ . '/../views/manager/telegram/index.php';
    return (string) ob_get_clean();
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$enabled = renderTelegramView(true); $disabled = renderTelegramView(false);
check(str_contains($enabled, 'درگاه ارتباطی تلگرام: فعال (سرور خارجی / بدون فیلتر)'), 'configured badge');
check(str_contains($enabled, 'اتصال را با پیام آزمایشی بررسی کنید'), 'badge is not a live connectivity claim');
check(!str_contains($disabled, 'درگاه ارتباطی تلگرام: فعال'), 'no badge without configured shared gateway');
foreach ([$enabled, $disabled] as $html) {
    foreach (['name="api_base_url"', 'name="proxy_url"', 'legacy.example.test', 'SOCKS5'] as $hidden) check(!str_contains($html, $hidden), 'hidden legacy transport ' . $hidden);
    foreach (['name="bot_token"','name="telegram_chat_id"','name="is_active"','name="telegram_entry_alert"','name="telegram_exit_alert"','name="notify_late"','name="notify_overtime"','name="daily_report"','name="daily_report_time"'] as $field) check(str_contains($html, $field), 'expected field ' . $field);
    check(str_contains($html, 'لطفاً ابتدا توکن ربات و شناسه چت را ذخیره کنید'), 'save before testing notice');
    check(substr_count($html, 'name="csrf"') === 4, 'CSRF fields preserved');
}
echo "Telegram manager view configured/unconfigured rendering passed\n";
