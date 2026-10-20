<?php
/**
 * SARMAD Attendance - authorisation helper (role + capability checks).
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Models\Role;

final class PermissionService
{
    /**
     * Does the current actor hold this capability?
     */
    public static function can(string $capability): bool
    {
        $role = Session::role();

        if ($role === null) {
            return false;
        }

        if (Session::isDevice()) {
            return in_array($capability, ['attendance.punch', 'attendance.sync', 'device.heartbeat'], true);
        }

        return Role::roleHasCapability($role, $capability);
    }

    /**
     * @param array<int,string> $capabilities
     */
    public static function canAny(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (self::can($capability)) {
                return true;
            }
        }

        return false;
    }

    public static function isOwner(): bool
    {
        return Session::role() === Role::OWNER;
    }

    public static function isManager(): bool
    {
        return in_array(Session::role(), [Role::MANAGER, Role::SUPERVISOR], true);
    }

    public static function isDevice(): bool
    {
        return Session::isDevice();
    }

    /**
     * Capabilities of the running actor (used by the UI to hide links -
     * never as the only protection, the backend checks as well).
     *
     * @return array<int,string>
     */
    public static function capabilities(): array
    {
        $role = Session::role();

        return $role === null ? [] : Role::capabilitiesOf($role);
    }
}
