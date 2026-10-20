<?php
/**
 * SARMAD Attendance - standard JSON envelope for every API answer.
 *
 * Success:
 *   { "success": true, "code": "OK", "message": "...", "data": {...}, "meta": {...} }
 * Failure:
 *   { "success": false, "code": "...", "message": "...",
 *     "error": { "code": "...", "message": "...", "details": {...} } }
 *
 * The top level code/message pair is the contract the attendance device (and
 * every other client) reads first; the nested error object is kept for the
 * panels built in stages 1-3, so nothing that already worked changes shape.
 */

declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Core\Response;
use App\Core\Security;

final class ApiResponse
{
    public const CODE_VALIDATION    = 'validation_failed';
    public const CODE_UNAUTH        = 'unauthenticated';
    public const CODE_FORBIDDEN     = 'forbidden';
    public const CODE_NOT_FOUND     = 'not_found';
    public const CODE_RATE_LIMITED  = 'rate_limited';
    public const CODE_SERVER_ERROR  = 'server_error';

    /**
     * @param mixed $data
     * @param array<string,mixed> $meta
     */
    public static function success($data = null, array $meta = [], int $status = 200, string $code = 'OK', string $message = ''): Response
    {
        return Response::json([
            'success' => true,
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
            'meta'    => array_merge(['server_time' => date('c')], $meta),
        ], $status);
    }

    /**
     * Device (kiosk) success envelope: {"success":true,"code":"OK","message":"...","data":{}}
     *
     * @param mixed $data
     */
    public static function deviceSuccess($data = null, string $code = 'OK', string $message = ''): Response
    {
        return self::success($data, [], 200, $code, $message);
    }

    /**
     * Device (kiosk) failure envelope: {"success":false,"code":"DEVICE_INACTIVE","message":"..."}
     *
     * @param array<string,mixed> $details
     */
    public static function deviceError(string $code, string $message, array $details = [], int $status = 422): Response
    {
        // The kiosk shows `message` and switches behaviour on `code`, so the
        // top level pair is always present; the nested error object keeps the
        // stage 1-3 clients working.
        return self::error($code, $message, $details, $status);
    }

    /**
     * @param array<string,mixed> $details
     */
    public static function error(string $code, string $message, array $details = [], int $status = 400): Response
    {
        $payload = [
            'success' => false,
            'code'    => $code,
            'message' => $message,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

        if ($details !== []) {
            $payload['error']['details'] = self::cleanDetails($details);
        }

        return Response::json($payload, $status);
    }

    /**
     * @param array<string,array<int,string>> $errors
     */
    public static function validation(array $errors, string $message = 'اطلاعات ارسالی معتبر نیست.'): Response
    {
        return self::error(self::CODE_VALIDATION, $message, ['fields' => $errors], 422);
    }

    public static function unauthorized(string $message = 'احراز هویت ناموفق بود.'): Response
    {
        return self::error(self::CODE_UNAUTH, $message, [], 401);
    }

    public static function forbidden(string $message = 'دسترسی مجاز نیست.'): Response
    {
        return self::error(self::CODE_FORBIDDEN, $message, [], 403);
    }

    public static function notFound(string $message = 'موردی یافت نشد.'): Response
    {
        return self::error(self::CODE_NOT_FOUND, $message, [], 404);
    }

    public static function serverError(string $message = 'خطای داخلی سرور.'): Response
    {
        return self::error(self::CODE_SERVER_ERROR, $message, [], 500);
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private static function cleanDetails(array $details): array
    {
        if (Config::isDebug()) {
            return $details;
        }

        // Never leak stack traces / SQL to a client in production.
        unset($details['trace'], $details['exception'], $details['sql']);

        return $details;
    }
}
