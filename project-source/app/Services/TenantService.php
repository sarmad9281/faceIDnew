<?php
/**
 * SARMAD Attendance - tenant scope resolution.
 *
 * Resolves which customer a request operates on and keeps the answer in
 * TenantContext so that every query in the request is scoped identically.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Core\Session;
use App\Core\TenantContext;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Role;

final class TenantService
{
    /**
     * Resolve the scope for owners. Managers/devices are pinned to their
     * own customer and cannot switch.
     *
     * @return array{ok:bool, message:string, customer:array<string,mixed>|null}
     */
    public function resolveForOwner(Request $request): array
    {
        $user = Session::user();

        if ($user === null || (string) $user['role'] !== Role::OWNER) {
            return ['ok' => false, 'message' => 'دسترسی فقط برای مالک سامانه مجاز است.', 'customer' => null];
        }

        $uuid = (string) $request->input('customer', '');

        if ($uuid === '') {
            TenantContext::set(0, 'owner');

            return ['ok' => true, 'message' => 'محدوده کل سامانه.', 'customer' => null];
        }

        $customer = Customer::findByUuid($uuid);

        if ($customer === null) {
            AuditLog::denied('Owner requested an unknown customer scope', ['customer' => $uuid]);

            return ['ok' => false, 'message' => 'مشتری یافت نشد.', 'customer' => null];
        }

        TenantContext::set((int) $customer['id'], 'owner');

        return ['ok' => true, 'message' => 'محدوده مشتری فعال شد.', 'customer' => $customer];
    }

    /**
     * The customer id every tenant bound query must use. Throws for
     * owners without a scope - callers must handle it explicitly instead
     * of accidentally reading all tenants.
     */
    public static function requireCustomerId(): int
    {
        return TenantContext::requireCustomerId();
    }

    public static function currentCustomerId(): ?int
    {
        return TenantContext::customerId();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function currentCustomer(): ?array
    {
        $id = TenantContext::customerId();

        return $id === null ? null : Customer::find($id);
    }
}
