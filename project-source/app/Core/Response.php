<?php
/**
 * SARMAD Attendance - HTTP response helpers.
 */

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private int $status = 200;

    /** @var array<string,string> */
    private array $headers = [];

    private string $body = '';

    public static function make(string $body = '', int $status = 200): self
    {
        $response          = new self();
        $response->body    = $body;
        $response->status  = $status;

        return $response;
    }

    public static function html(string $body, int $status = 200): self
    {
        return self::make($body, $status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if (Config::isDebug()) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $payload = json_encode($data, $flags);

        if ($payload === false) {
            $payload = '{"success":false,"error":{"code":"encoding_error","message":"Response encoding failed"}}';
            $status  = 500;
        }

        return self::make($payload, $status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return self::make($body, $status)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return self::make('', $status)->withHeader('Location', $location);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }

            // Authenticated surfaces must never be cached by a proxy.
            if (!isset($this->headers['Cache-Control'])) {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');
            }
        }

        echo $this->body;
    }
}
