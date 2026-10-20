<?php
/**
 * SARMAD Attendance - tenant isolation at the backend layer.
 *
 * Every authenticated request must resolve to exactly one customer.
 * The mode is declared per route:
 *
 *   'platform' : the actor is a SARMAD owner and may work across
 *                customers (still always one customer per operation).
 *   'single'   : the actor must belong to a customer; the customer id is
 *                bound into TenantContext and re-used by every model.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Models\AuditLog;
use App\Models\Customer;

final class TenantMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $parameters
     */
    public function handle(Request $request, callable $next, array $parameters = []): Response
    {
        $mode = strtolower($parameters[0] ?? 'single');

        if (Session::isDevice()) {
            $device = Session::device();
            $customerId = (int) ($device['customer_id'] ?? 0);

            if ($customerId <= 0) {
                return Deny::access($request, 'این دستگاه به هیچ مشتری‌ای متصل نیست.');
            }

            TenantContext::set($customerId, 'device');

            return $next($request);
        }

        $user = Session::user();

        if ($user === null) {
            return Deny::access($request, 'ابتدا وارد حساب کاربری خود شوید.', 401);
        }

        $role       = strtolower((string) ($user['role'] ?? ''));
        $customerId = isset($user['customer_id']) ? (int) $user['customer_id'] : 0;

        // Emergency access: the owner is pinned to the impersonated customer
        // for every query in this request (never to an id from the URL).
        $impersonation = Session::impersonation();

        if ($impersonation !== null) {
            $scopedId = (int) ($impersonation['customer_id'] ?? 0);

            if ($scopedId <= 0) {
                return Deny::access($request, 'محدوده دسترسی اضطراری معتبر نیست.');
            }

            TenantContext::set($scopedId, 'owner');
            TenantContext::markImpersonation((int) ($impersonation['id'] ?? 0));

            // Count write actions performed inside the customer panel.
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                \App\Services\ImpersonationService::countAction();
            }

            $scopedCustomer = Customer::find($scopedId);

            if ($scopedCustomer === null || (string) ($scopedCustomer['status'] ?? '') !== 'active') {
                return Deny::access($request, 'مشتری مرتبط با این دسترسی فعال نیست.');
            }

            return $next($request);
        }

        // "auto" means: owners work platform wide (with an optional explicit
        // scope), everybody else is pinned to their own customer.
        if ($mode === 'auto' && $role === 'owner') {
            $mode = 'platform';
        }

        if ($role === 'owner' && $mode === 'platform') {
            // Owners may pass ?customer=<uuid> to scope their view, but the
            // scope must always be an explicit, verified customer.
            $requested = (string) $request->input('customer', '');

            if ($requested !== '') {
                $customer = Customer::findByUuid($requested);

                if ($customer === null) {
                    AuditLog::denied('Attempt to open a non-existing customer scope', [
                        'customer' => $requested,
                    ]);

                    return Deny::access($request, 'مشتری درخواستی یافت نشد.');
                }

                TenantContext::set((int) $customer['id'], 'owner');
            } else {
                TenantContext::set(0, 'owner');
            }

            return $next($request);
        }

        if ($customerId <= 0) {
            return Deny::access($request, 'حساب کاربری شما به هیچ مشتری‌ای متصل نیست.');
        }

        if ($role === 'owner') {
            TenantContext::set($customerId, 'owner');
        } else {
            TenantContext::set($customerId, 'manager');
        }

        // Extra guard: the customer record must exist and be active
        // (attendance_customers uses a status enum, not an is_active flag).
        $customer = Customer::find($customerId);

        if ($customer === null || (string) ($customer['status'] ?? '') !== 'active') {
            AuditLog::denied('Inactive or missing customer scope', ['customer_id' => $customerId]);

            return Deny::access($request, 'مشتری مرتبط با حساب شما فعال نیست.');
        }

        return $next($request);
    }
}
