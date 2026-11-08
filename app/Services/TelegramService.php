<?php
/**
 * SARMAD Attendance - stage 7 Telegram service.
 *
 * WHAT IT DOES
 * ---------------------------------------------------------------------
 *   settings()      the per-customer configuration (bot token + chat + which
 *                   notifications are wanted),
 *   saveSettings()  stores the token ENCRYPTED (App\Core\Security::encrypt),
 *                   keeps only a 4 character hint for the panel,
 *   send()          the single HTTP call to api.telegram.org,
 *   enqueue()/drain() a durable outbox with retries and a FAILED state,
 *   logs()          the telegram log table.
 *
 * WHAT IT NEVER DOES
 * ---------------------------------------------------------------------
 *   - it never returns the token to a view, an API payload, a log line or an
 *     exception message (errors are redacted before they are stored),
 *   - it never touches attendance: the attendance write happens first, and a
 *     Telegram failure is only recorded in the queue/log tables,
 *   - it never calls telegram when the feature is disabled for the customer.
 *
 * The HTTP transport is injectable (setTransport) so the test suite can drive
 * a real queue/drain/retry cycle with a scripted transport instead of a live
 * bot. With no transport injected the real curl call is used.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Security;
use App\Models\AuditLog;
use Throwable;

final class TelegramService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const MAX_ATTEMPTS = 3;

    /** @var callable|null */
    private $transport = null;

    /**
     * Inject a scripted transport: function(string $url, array $body): array{
     *   ok:bool,status:int,body:mixed,error:string}
     *
     * @param callable|null $transport
     */
    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    /* ==================================================================
     * Settings
     * ==================================================================*/

    /**
     * @return array<string,mixed>
     */
    public function settings(int $customerId): array
    {
        $row = Database::selectOne(
            'SELECT * FROM `' . Database::table('telegram_settings') . '` WHERE `customer_id` = ? LIMIT 1',
            [$customerId]
        );

        if ($row !== null) {
            return $row;
        }

        return [
            'id'                => 0,
            'customer_id'       => $customerId,
            'bot_token_cipher'  => null,
            'bot_token_hint'    => null,
            'bot_username'      => null,
            'chat_id'           => null,
            'notify_entry'      => 1,
            'notify_exit'       => 1,
            'notify_late'       => 0,
            'notify_overtime'   => 0,
            'daily_report'      => 0,
            'daily_report_time' => '23:55',
            'is_active'         => 0,
            'last_ok_at'        => null,
            'last_error'        => null,
            'last_error_at'     => null,
        ];
    }

    /**
     * The masked token the panel may show: ************1234.
     *
     * @param array<string,mixed> $settings
     */
    public function maskToken(array $settings): string
    {
        $hint = (string) ($settings['bot_token_hint'] ?? '');

        return $hint === '' ? '' : '************' . $hint;
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $input
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>}
     */
    public function saveSettings(array $actor, int $customerId, array $input): array
    {
        $existing = $this->settings($customerId);

        $time = (string) ($input['daily_report_time'] ?? ($existing['daily_report_time'] ?? '23:55'));

        if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) !== 1) {
            $time = '23:55';
        }

        $data = [
            'customer_id'       => $customerId,
            'chat_id'           => $this->cleanChatId($input['chat_id'] ?? ($existing['chat_id'] ?? '')),
            'notify_entry'      => $this->flag($input['notify_entry'] ?? ($existing['notify_entry'] ?? 1)),
            'notify_exit'       => $this->flag($input['notify_exit'] ?? ($existing['notify_exit'] ?? 1)),
            'notify_late'       => $this->flag($input['notify_late'] ?? ($existing['notify_late'] ?? 0)),
            'notify_overtime'   => $this->flag($input['notify_overtime'] ?? ($existing['notify_overtime'] ?? 0)),
            'daily_report'      => $this->flag($input['daily_report'] ?? ($existing['daily_report'] ?? 0)),
            'daily_report_time' => $time,
            'is_active'         => $this->flag($input['is_active'] ?? ($existing['is_active'] ?? 0)),
        ];

        // The token is only replaced when a NEW value is typed; an empty field
        // means "keep the stored token", so the panel never has to echo it back.
        $token = trim((string) ($input['bot_token'] ?? ''));

        if ($token !== '') {
            if (preg_match('/^\d{6,}:[A-Za-z0-9_\-]{20,}$/', $token) !== 1) {
                return [
                    'ok'      => false,
                    'code'    => 'TELEGRAM_TOKEN_INVALID',
                    'message' => 'قالب Bot Token نامعتبر است (مثال: 123456789:AA....).',
                    'data'    => [],
                ];
            }

            $data['bot_token_cipher'] = Security::encrypt($token);
            $data['bot_token_hint']   = substr($token, -4);
            $data['bot_username']     = '';
        }

        if ((int) $data['is_active'] === 1) {
            $hasToken = $existing['bot_token_cipher'] !== null || isset($data['bot_token_cipher']);

            if (!$hasToken || $data['chat_id'] === '') {
                return [
                    'ok'      => false,
                    'code'    => 'TELEGRAM_NOT_CONFIGURED',
                    'message' => 'برای فعال‌سازی، Bot Token و Chat ID لازم است.',
                    'data'    => [],
                ];
            }
        }

        if ((int) ($existing['id'] ?? 0) > 0) {
            Database::update(Database::table('telegram_settings'), $data, ['id' => (int) $existing['id'], 'customer_id' => $customerId]);
            $id = (int) $existing['id'];
        } else {
            $data['uuid']       = Security::uuid4();
            $data['created_by'] = (int) ($actor['id'] ?? 0) ?: null;
            $id = Database::insert(Database::table('telegram_settings'), $data);
        }

        AuditLog::record('telegram.settings.saved', 'success', 'تنظیمات تلگرام ذخیره شد.', [
            'token_replaced' => isset($data['bot_token_cipher']),
            'chat_id'        => $data['chat_id'],
            'is_active'      => (int) $data['is_active'],
            'notify'         => [
                'entry'    => (int) $data['notify_entry'],
                'exit'     => (int) $data['notify_exit'],
                'late'     => (int) $data['notify_late'],
                'overtime' => (int) $data['notify_overtime'],
                'daily'    => (int) $data['daily_report'],
            ],
            'actor'          => $actor,
        ], 'telegram', 'telegram_settings', $id, $customerId);

        return [
            'ok'      => true,
            'code'    => 'TELEGRAM_SETTINGS_SAVED',
            'message' => 'تنظیمات تلگرام ذخیره شد.',
            'data'    => ['settings_id' => $id, 'token_hint' => $this->maskToken($data + $existing)],
        ];
    }

    /**
     * True when a notification may be sent at all for this customer.
     */
    public function usable(int $customerId): bool
    {
        if (!FeatureService::allows($customerId, 'telegram')) {
            return false;
        }

        $settings = $this->settings($customerId);

        return (int) ($settings['is_active'] ?? 0) === 1
            && trim((string) ($settings['chat_id'] ?? '')) !== ''
            && $this->token($settings) !== '';
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function token(array $settings): string
    {
        $cipher = (string) ($settings['bot_token_cipher'] ?? '');

        if ($cipher === '') {
            return '';
        }

        try {
            return Security::decrypt($cipher);
        } catch (Throwable $error) {
            Logger::channel('telegram')->error('Stored bot token could not be decrypted', [
                'customer_id' => (int) ($settings['customer_id'] ?? 0),
                'message'     => $this->redact($error->getMessage()),
            ]);

            return '';
        }
    }

    /* ==================================================================
     * Queue
     * ==================================================================*/

    /**
     * Queue a message. Duplicate dedupe_key for the same customer is ignored
     * (the unique index guarantees it even under a race).
     *
     * @param array<string,mixed> $payload
     * @return array{queued:bool,queue_id:int,duplicate:bool}
     */
    public function enqueue(int $customerId, string $event, string $dedupeKey, string $text, string $chatId = '', array $payload = [], bool $rearm = false): array
    {
        $duplicate = Database::selectOne(
            'SELECT `id` FROM `' . Database::table('telegram_queue') . '`'
            . ' WHERE `customer_id` = ? AND `dedupe_key` = ? LIMIT 1',
            [$customerId, substr($dedupeKey, 0, 120)]
        );

        if ($duplicate !== null) {
            // $rearm is set by an explicit "send now" action: a row that gave up
            // after MAX_ATTEMPTS is armed again instead of silently doing nothing.
            if ($rearm) {
                $current = Database::selectOne(
                    'SELECT `status` FROM `' . Database::table('telegram_queue') . '` WHERE `id` = ? LIMIT 1',
                    [(int) $duplicate['id']]
                );

                if ($current !== null && in_array((string) $current['status'], [self::STATUS_FAILED, 'failed_retry'], true)) {
                    Database::update(Database::table('telegram_queue'), [
                        'chat_id'      => $chatId === '' ? null : $chatId,
                        'payload'      => json_encode(['text' => $text] + $payload, JSON_UNESCAPED_UNICODE),
                        'status'       => self::STATUS_PENDING,
                        'attempts'     => 0,
                        'error_code'   => null,
                        'last_error'   => null,
                        'available_at' => date('Y-m-d H:i:s'),
                    ], ['id' => (int) $duplicate['id']]);

                    Logger::channel('telegram')->info('Queue row re-armed by an explicit send action', [
                        'customer_id' => $customerId,
                        'queue_id'    => (int) $duplicate['id'],
                        'dedupe_key'  => substr($dedupeKey, 0, 120),
                    ]);

                    return ['queued' => true, 'queue_id' => (int) $duplicate['id'], 'duplicate' => false, 'requeued' => true];
                }
            }

            return ['queued' => false, 'queue_id' => (int) $duplicate['id'], 'duplicate' => true];
        }

        try {
            $id = Database::insert(Database::table('telegram_queue'), [
                'uuid'         => Security::uuid4(),
                'customer_id'  => $customerId,
                'event'        => substr($event, 0, 60),
                'dedupe_key'   => substr($dedupeKey, 0, 120),
                'chat_id'      => $chatId === '' ? null : $chatId,
                'payload'      => json_encode(['text' => $text] + $payload, JSON_UNESCAPED_UNICODE),
                'status'       => self::STATUS_PENDING,
                'attempts'     => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'available_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            // Unique key lost the race - it is still exactly one message.
            return ['queued' => false, 'queue_id' => 0, 'duplicate' => true];
        }

        return ['queued' => true, 'queue_id' => $id, 'duplicate' => false];
    }

    /**
     * Drain the queue: returns a per-row report. Called by the worker (cron)
     * and by the "send now" button in the panel.
     *
     * @return array{processed:int,sent:int,failed:int,retried:int,rows:array<int,array<string,mixed>>}
     */
    public function drain(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $rows = Database::select(
            'SELECT * FROM `' . Database::table('telegram_queue') . '`'
            . ' WHERE `status` IN (?, ?) AND `available_at` <= ?'
            . ' ORDER BY `id` ASC LIMIT ' . $limit,
            [self::STATUS_PENDING, 'failed_retry', date('Y-m-d H:i:s')]
        );

        $report = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0, 'rows' => []];

        foreach ($rows as $row) {
            $report['processed']++;
            $result = $this->processRow($row);

            $report['rows'][] = $result;

            if ($result['status'] === self::STATUS_SENT) {
                $report['sent']++;
            } elseif ($result['status'] === self::STATUS_FAILED) {
                $report['failed']++;
            } else {
                $report['retried']++;
            }
        }

        return $report;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function processRow(array $row): array
    {
        $customerId = (int) $row['customer_id'];
        $queueId    = (int) $row['id'];

        Database::update(Database::table('telegram_queue'), ['status' => self::STATUS_PROCESSING], ['id' => $queueId]);

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $text    = is_array($payload) ? (string) ($payload['text'] ?? '') : '';

        // Feature can be switched off by the owner between queueing and sending.
        if (!FeatureService::allows($customerId, 'telegram')) {
            Database::update(Database::table('telegram_queue'), [
                'status'     => self::STATUS_FAILED,
                'error_code' => 'TELEGRAM_DISABLED',
                'last_error' => 'سرویس تلگرام برای این مجموعه غیرفعال شده است.',
            ], ['id' => $queueId]);

            $this->log($customerId, (string) $row['event'], (string) $row['chat_id'], 'skipped', $queueId, 0, 'TELEGRAM_DISABLED', 'سرویس تلگرام غیرفعال است.');

            return ['queue_id' => $queueId, 'status' => self::STATUS_FAILED, 'code' => 'TELEGRAM_DISABLED'];
        }

        $settings = $this->settings($customerId);
        $chatId   = trim((string) ($row['chat_id'] ?? '')) !== '' ? (string) $row['chat_id'] : (string) $settings['chat_id'];
        $token    = $this->token($settings);

        if ($text === '' || $token === '' || $chatId === '') {
            Database::update(Database::table('telegram_queue'), [
                'status'     => self::STATUS_FAILED,
                'error_code' => 'TELEGRAM_NOT_CONFIGURED',
                'last_error' => 'توکن یا شناسه گفتگو تنظیم نشده است.',
            ], ['id' => $queueId]);

            $this->log($customerId, (string) $row['event'], $chatId, 'failed', $queueId, (int) $row['attempts'], 'TELEGRAM_NOT_CONFIGURED', 'توکن یا Chat ID تنظیم نشده است.');

            return ['queue_id' => $queueId, 'status' => self::STATUS_FAILED, 'code' => 'TELEGRAM_NOT_CONFIGURED'];
        }

        $attempt = (int) $row['attempts'] + 1;
        $answer  = $this->send($token, $chatId, $text);

        if ($answer['ok']) {
            Database::update(Database::table('telegram_queue'), [
                'status'     => self::STATUS_SENT,
                'attempts'   => $attempt,
                'sent_at'    => date('Y-m-d H:i:s'),
                'message_id' => $answer['message_id'],
                'last_error' => null,
                'error_code' => null,
            ], ['id' => $queueId]);

            Database::update(Database::table('telegram_settings'), [
                'last_ok_at'  => date('Y-m-d H:i:s'),
                'last_error'  => null,
                'last_error_at' => null,
            ], ['customer_id' => $customerId]);

            $this->log($customerId, (string) $row['event'], $chatId, 'sent', $queueId, $attempt, null, null, $answer['message_id']);

            return ['queue_id' => $queueId, 'status' => self::STATUS_SENT, 'attempts' => $attempt, 'message_id' => $answer['message_id']];
        }

        $maxAttempts = max(1, (int) $row['max_attempts']);

        if ($attempt >= $maxAttempts) {
            Database::update(Database::table('telegram_queue'), [
                'status'     => self::STATUS_FAILED,
                'attempts'   => $attempt,
                'error_code' => $answer['error_code'],
                'last_error' => $answer['error'],
            ], ['id' => $queueId]);

            $this->log($customerId, (string) $row['event'], $chatId, 'failed', $queueId, $attempt, $answer['error_code'], $answer['error']);

            return ['queue_id' => $queueId, 'status' => self::STATUS_FAILED, 'attempts' => $attempt, 'code' => $answer['error_code']];
        }

        // Retry with a growing backoff (1, 4, 9 ... minutes), never a tight loop.
        $delay = $attempt * $attempt;

        Database::update(Database::table('telegram_queue'), [
            'status'       => self::STATUS_PENDING,
            'attempts'     => $attempt,
            'error_code'   => $answer['error_code'],
            'last_error'   => $answer['error'],
            'available_at' => date('Y-m-d H:i:s', time() + $delay * 60),
        ], ['id' => $queueId]);

        Database::update(Database::table('telegram_settings'), [
            'last_error'    => $answer['error'],
            'last_error_at' => date('Y-m-d H:i:s'),
        ], ['customer_id' => $customerId]);

        $this->log($customerId, (string) $row['event'], $chatId, 'failed', $queueId, $attempt, $answer['error_code'], $answer['error'] . ' (تلاش ' . $attempt . ' از ' . $maxAttempts . ')');

        return ['queue_id' => $queueId, 'status' => 'retry', 'attempts' => $attempt, 'code' => $answer['error_code']];
    }

    /* ==================================================================
     * Transport
     * ==================================================================*/

    /**
     * @return array{ok:bool,status:int,message_id:?string,error:?string,error_code:?string}
     */
    public function send(string $token, string $chatId, string $text): array
    {
        $url  = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $body = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($this->transport !== null) {
            $answer = ($this->transport)($url, $body);
        } else {
            $answer = $this->curlPost($url, $body);
        }

        $decoded = json_decode((string) ($answer['body'] ?? ''), true);

        if (!empty($answer['ok']) && is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            return [
                'ok'         => true,
                'status'     => (int) ($answer['status'] ?? 200),
                'message_id' => isset($decoded['result']['message_id']) ? (string) $decoded['result']['message_id'] : null,
                'error'      => null,
                'error_code' => null,
            ];
        }

        $description = '';

        if (is_array($decoded)) {
            $description = (string) ($decoded['description'] ?? '');
        }

        if ($description === '') {
            $description = (string) ($answer['error'] ?? 'ارتباط با تلگرام برقرار نشد.');
        }

        $code = 'TELEGRAM_HTTP_' . (int) ($answer['status'] ?? 0);

        if (is_array($decoded) && isset($decoded['error_code'])) {
            $code = 'TELEGRAM_' . (int) $decoded['error_code'];
        }

        return [
            'ok'         => false,
            'status'     => (int) ($answer['status'] ?? 0),
            'message_id' => null,
            'error'      => $this->redact(substr($description, 0, 200)),
            'error_code' => $code,
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok:bool,status:int,body:string,error:?string}
     */
    private function curlPost(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::channel('telegram')->warning('Telegram call failed', ['error' => $this->redact($error)]);

            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $this->redact($error)];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $response, 'error' => null];
    }

    /**
     * Remove anything that looks like a bot token from a message before it is
     * stored or logged.
     */
    public function redact(string $text): string
    {
        $redacted = preg_replace('/\d{6,}:[A-Za-z0-9_\-]{20,}/', '[REDACTED-BOT-TOKEN]', $text) ?? $text;

        return substr($redacted, 0, 200);
    }

    /* ==================================================================
     * Logs
     * ==================================================================*/

    private function log(int $customerId, string $event, string $chatId, string $status, int $queueId, int $attempts, ?string $code, ?string $message, ?string $messageId = null): void
    {
        try {
            Database::insert(Database::table('telegram_logs'), [
                'uuid'          => Security::uuid4(),
                'customer_id'   => $customerId,
                'event'         => substr($event, 0, 60),
                'chat_id'       => $chatId === '' ? null : $chatId,
                'status'        => $status,
                'message_id'    => $messageId,
                'error_code'    => $code === null ? null : substr($code, 0, 60),
                'error_message' => $message === null ? null : $this->redact($message),
                'queue_id'      => $queueId > 0 ? $queueId : null,
                'attempts'      => max(1, $attempts),
                'sent_at'       => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (Throwable $error) {
            Logger::channel('telegram')->error('Could not write the telegram log row', [
                'message' => $this->redact($error->getMessage()),
            ]);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function logs(int $customerId, int $limit = 100): array
    {
        return Database::select(
            'SELECT * FROM `' . Database::table('telegram_logs') . '`'
            . ' WHERE `customer_id` = ? ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit)),
            [$customerId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function queueRows(int $customerId, int $limit = 100): array
    {
        return Database::select(
            'SELECT * FROM `' . Database::table('telegram_queue') . '`'
            . ' WHERE `customer_id` = ? ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit)),
            [$customerId]
        );
    }

    /**
     * @return array{pending:int,processing:int,sent:int,failed:int}
     */
    public function queueStats(int $customerId): array
    {
        $rows = Database::select(
            'SELECT `status`, COUNT(*) AS total FROM `' . Database::table('telegram_queue') . '`'
            . ' WHERE `customer_id` = ? GROUP BY `status`',
            [$customerId]
        );

        $stats = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['total'];
        }

        return $stats;
    }

    /**
     * Send a test message through the real transport and report the outcome.
     *
     * @return array{ok:bool,code:string,message:string}
     */
    public function testSend(int $customerId, string $text = ''): array
    {
        $settings = $this->settings($customerId);

        if (!FeatureService::allows($customerId, 'telegram')) {
            return ['ok' => false, 'code' => 'FEATURE_DISABLED', 'message' => 'سرویس تلگرام برای این مجموعه فعال نیست.'];
        }

        $token  = $this->token($settings);
        $chatId = trim((string) ($settings['chat_id'] ?? ''));

        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'code' => 'TELEGRAM_NOT_CONFIGURED', 'message' => 'توکن یا Chat ID تنظیم نشده است.'];
        }

        $answer = $this->send($token, $chatId, $text !== '' ? $text : 'پیام آزمایشی SARMAD Attendance');

        if ($answer['ok']) {
            Database::update(Database::table('telegram_settings'), [
                'last_ok_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
            ], ['customer_id' => $customerId]);

            $this->log($customerId, 'telegram.test', $chatId, 'sent', 0, 1, null, null, $answer['message_id']);

            return ['ok' => true, 'code' => 'TELEGRAM_SENT', 'message' => 'پیام آزمایشی با موفقیت ارسال شد.'];
        }

        Database::update(Database::table('telegram_settings'), [
            'last_error'    => $answer['error'],
            'last_error_at' => date('Y-m-d H:i:s'),
        ], ['customer_id' => $customerId]);

        $this->log($customerId, 'telegram.test', $chatId, 'failed', 0, 1, $answer['error_code'], $answer['error']);

        return ['ok' => false, 'code' => (string) $answer['error_code'], 'message' => 'ارسال آزمایشی ناموفق بود: ' . $answer['error']];
    }

    private function cleanChatId($value): string
    {
        $value = trim((string) $value);

        return preg_replace('/[^0-9@A-Za-z_\-]/', '', $value) ?? '';
    }

    private function flag($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return in_array((string) $value, ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }

    /**
     * The worker entry point used by the cron: queue + drain.
     *
     * @return array<string,mixed>
     */
    public function runWorker(int $limit = 20): array
    {
        $dispatcher = new EventDispatcher();
        $dispatch   = $dispatcher->dispatch(200);
        $scheduler  = new TelegramScheduler();
        $reports    = $scheduler->runDue();
        $drain      = $this->drain($limit);

        return [
            'events'   => $dispatch,
            'reports'  => $reports,
            'queue'    => $drain,
            'ran_at'   => date('Y-m-d H:i:s'),
            'enabled'  => (bool) Config::get('attendance.telegram_worker', true),
        ];
    }
}
