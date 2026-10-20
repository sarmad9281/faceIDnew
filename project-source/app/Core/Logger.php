<?php
/**
 * SARMAD Attendance - logging.
 *
 * Two destinations:
 *   1. files  -> storage/logs/YYYY-MM-DD.log (rotated daily, never web
 *                accessible thanks to storage/.htaccess + root rules)
 *   2. audit  -> attendance_audit_logs table (structured, queryable)
 *
 * Channels: app, auth, security, database, device, api, installer
 */

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public const LEVELS = ['debug' => 10, 'info' => 20, 'notice' => 25, 'warning' => 30, 'error' => 40, 'critical' => 50];

    private string $channel;

    private function __construct(string $channel)
    {
        $this->channel = $channel;
    }

    public static function channel(string $channel): self
    {
        return new self($channel);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function critical(string $message, array $context = []): void
    {
        $this->write('critical', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function write(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }

        $threshold = self::LEVELS[strtolower((string) Config::get('log.level', 'info'))] ?? 20;

        if (self::LEVELS[$level] < $threshold) {
            return;
        }

        if ((bool) Config::get('log.file', true)) {
            $this->writeFile($level, $message, $context);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function writeFile(string $level, string $message, array $context): void
    {
        $directory = Config::loaded() ? Config::path('storage/logs') : dirname(__DIR__, 2) . '/storage/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $line = sprintf(
            "[%s] %s.%s: %s %s%s",
            date('Y-m-d H:i:s'),
            $this->channel,
            strtoupper($level),
            $message,
            $context === [] ? '' : json_encode(self::redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );

        @file_put_contents($directory . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);

        // Keep the folder tidy on long running installs.
        if (random_int(1, 200) === 1) {
            self::prune($directory);
        }
    }

    public static function prune(string $directory): void
    {
        $days = (int) (Config::loaded() ? Config::get('log.retention_days', 60) : 60);
        $cut  = time() - ($days * 86400);

        foreach ((array) glob($directory . '/*.log') as $file) {
            if (is_file($file) && filemtime($file) < $cut) {
                @unlink($file);
            }
        }
    }

    /**
     * Never write secrets into a log file.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function redact(array $context): array
    {
        $sensitive = ['password', 'password_confirmation', 'password_repeat', 'token', 'api_token',
            'device_token', 'secret', 'key', 'app_key', 'authorization', 'cookie', 'csrf', '_token',
            'current_password', 'new_password'];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redact($value);
                continue;
            }

            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $context[$key] = '[redacted]';
            }
        }

        return $context;
    }
}
