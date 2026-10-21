<?php
/**
 * SARMAD Attendance - owner dashboard aggregates.
 *
 * Every number on the owner overview comes from a real SQL query against
 * attendance_* tables. There is no placeholder and no mock value; if a
 * table is empty the counter is genuinely zero.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Subscription;

final class OwnerDashboardService
{
    /**
     * @return array<string,mixed>
     */
    public function overview(int $offlineAfter = 300): array
    {
        $counters     = Customer::platformCounters();
        $subscription = Subscription::counts();

        return [
            'counters'     => $counters,
            'subscription' => $subscription,
            'recent'       => [
                'customers'      => Customer::withCounters(Customer::listAll('', 5, 0)),
                'manager_logins' => Manager::latestLogins(6),
                'devices_online' => Device::latestOnline(6, $offlineAfter),
                'activity'       => AuditLog::latestForOwner(10),
            ],
            'totals' => [
                'subscriptions_active'   => $subscription['active'] + $subscription['trial'],
                'subscriptions_expired'  => $subscription['expired'],
                'subscriptions_expiring' => $subscription['expiring_7'],
                'features_enabled'       => $this->enabledFeatureTotal(),
                'devices_online'         => $counters['devices_online'],
                'devices_offline'        => max(0, $counters['devices_active'] - $counters['devices_online']),
            ],
            'environment' => [
                'php'        => PHP_VERSION,
                'database'   => Database::serverVersion(),
                'tables'     => count(Database::projectTables()),
                'prefix'     => Database::prefix(),
                'offline_after' => $offlineAfter,
            ],
        ];
    }

    /**
     * How many feature flags are currently switched on (all customers).
     */
    public function enabledFeatureTotal(): int
    {
        if (!Database::tableExists('feature_flags')) {
            return 0;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . Database::table('feature_flags') . '` WHERE `is_enabled` = 1'
        );
    }

    /**
     * Employees overview page payload.
     *
     * @return array<string,mixed>
     */
    public function employeesOverview(): array
    {
        return [
            'status'     => Employee::statusCounts(0),
            'customers'  => Employee::perCustomer(50),
        ];
    }

    /**
     * Subscription page payload.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function subscriptionOverview(array $filters, int $page, int $perPage): array
    {
        $listing = Customer::paginateForOwner($filters, $page, $perPage);

        return [
            'listing' => $listing,
            'counts'  => Subscription::counts(),
        ];
    }
}
