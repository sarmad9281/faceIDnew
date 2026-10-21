<?php
/**
 * SARMAD Attendance - role capabilities (defence in depth).
 *
 * Routes and middleware are the first line of defence: a manager must never
 * even reach the owner panel. This map is the second line - controllers pass
 * through Capability::check() so that even if a route were misconfigured the
 * operation is refused, and the panel hides what the role can not do. Hiding
 * a button is never the security boundary.
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\Deny;

final class Capability
{
    /** Everything the manager panel may do inside its own customer. */
    private const MANAGER = [
        'manager.panel', 'dashboard.view', 'employee.view', 'employee.manage',
        'shift.view', 'shift.manage', 'attendance.view', 'attendance.manage', 'leave.view', 'leave.manage',
        'holiday.view', 'holiday.manage', 'branch.view', 'branch.manage',
        'report.view', 'report.export', 'payroll.view', 'payroll.manage', 'telegram.view', 'telegram.manage',
        'device.view', 'device.register', 'activity.view', 'customer.profile.edit',
    ];

    /** Read only role (supervisor). */
    private const SUPERVISOR = [
        'manager.panel', 'dashboard.view', 'employee.view', 'shift.view', 'attendance.view',
        'leave.view', 'holiday.view', 'branch.view', 'report.view', 'device.view',
    ];

    /** Owner-only: never granted to a manager, not even through a URL. */
    private const OWNER_ONLY = [
        'owner.panel', 'customer.manage', 'customer.create', 'customer.delete',
        'manager.create', 'manager.manage', 'subscription.manage', 'feature.manage',
        'device.reset', 'device.revoke', 'device.token.rotate', 'device.delete',
        'settings.owner', 'audit.owner', 'emergency.access',
    ];

    /**
     * @return array<int,string>
     */
    public static function forRole(?string $role): array
    {
        switch ((string) $role) {
            case 'owner':
                return array_merge(self::MANAGER, self::OWNER_ONLY);
            case 'supervisor':
                return self::SUPERVISOR;
            case 'manager':
                return self::MANAGER;
            default:
                return [];
        }
    }

    public static function allows(string $capability, ?string $role = null): bool
    {
        $role = $role ?? Session::role();

        if (in_array($capability, self::OWNER_ONLY, true) && (string) $role !== 'owner') {
            return false;
        }

        return in_array($capability, self::forRole($role), true);
    }

    public static function denied(string $capability, ?string $role = null): bool
    {
        return !self::allows($capability, $role);
    }

    /**
     * Hard stop for a controller method: returns the 403 response when the
     * active role may not perform the operation, null when it may.
     *
     * Usage:  if (($denied = Capability::check($request, 'device.reset')) !== null) { return $denied; }
     */
    public static function check(Request $request, string $capability, ?string $role = null): ?Response
    {
        if (self::allows($capability, $role)) {
            return null;
        }

        return Deny::access($request, 'شما مجاز به انجام این عملیات نیستید.', 403, [
            'capability' => $capability,
            'role'       => (string) ($role ?? Session::role()),
        ]);
    }
}
