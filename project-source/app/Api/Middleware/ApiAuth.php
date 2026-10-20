<?php
/**
 * SARMAD Attendance - authenticates API calls made by users (owner or
 * manager) through a bearer token stored in attendance_api_tokens.
 */

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\ApiResponse;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\TenantContext;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Middleware\MiddlewareInterface;

final class ApiAuth implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        $token = $request->bearerToken();

        if ($token === '') {
            return ApiResponse::unauthorized('توکن دسترسی ارسال نشده است.');
        }

        $row = ApiToken::findActiveByToken($token);

        if ($row === null) {
            Logger::channel('api')->warning('API token rejected', ['prefix' => substr($token, 0, 12)]);

            return ApiResponse::unauthorized('توکن دسترسی نامعتبر یا منقضی است.');
        }

        $user = User::find((int) $row['user_id']);

        if ($user === null || (int) $user['is_active'] !== 1 || !empty($user['deleted_at'])) {
            return ApiResponse::unauthorized('حساب کاربری این توکن غیرفعال است.');
        }

        // Capability check, if the route asks for one.
        $required = $parameters[0] ?? '';

        if ($required !== '' && !ApiToken::hasAbility($row, $required)) {
            AuditLog::denied('API token lacks the required ability', [
                'ability' => $required,
                'user_id' => (int) $user['id'],
            ]);

            return ApiResponse::forbidden('توکن شما مجوز لازم برای این عملیات را ندارد.');
        }

        $customerId = $user['customer_id'] !== null ? (int) $user['customer_id'] : null;

        if ($customerId !== null && !Customer::isActive($customerId)) {
            return ApiResponse::forbidden('حساب مشتری فعال نیست.');
        }

        TenantContext::set((int) ($customerId ?? 0), (string) $user['role']);

        \App\Core\Session::loginUser(User::sessionPayload($user));

        ApiToken::touch($token, Request::ip());

        AuditLog::record('api.auth.success', 'success', 'احراز هویت API موفق بود.', [
            'user_id'   => (int) $user['id'],
            'token_id'  => (int) $row['id'],
        ], 'api');

        return $next($request);
    }
}
