<?php
/**
 * SARMAD Attendance - subscriptions (attendance_subscriptions).
 *
 * Design notes
 * ---------------------------------------------------------------------
 * * The CURRENT subscription of a customer is stored twice on purpose:
 *     - attendance_customers  (plan_name / subscription_status /
 *       subscription_start_date / subscription_expire_date) -> the fast
 *       "current state" columns that listings join on,
 *     - attendance_subscriptions -> the full history of every change
 *       (who changed what, when, price, notes).
 *   Both are always written in the same code path (SubscriptionService)
 *   so they can never drift apart.
 * * plan_name is a free string for now; a future stage can introduce a
 *   attendance_plans table without touching this one.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class Subscription extends Model
{
    protected static string $table = 'subscriptions';

    protected static array $fillable = [
        'uuid', 'customer_id', 'plan_name', 'status', 'start_date', 'expire_date',
        'price', 'currency', 'max_employees', 'max_devices', 'notes', 'created_by', 'is_current',
    ];

    public const STATUS_TRIAL     = 'trial';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELED  = 'canceled';

    /**
     * @return array<int,string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_SUSPENDED, self::STATUS_CANCELED];
    }

    /**
     * @return array<int,string>
     */
    public static function plans(): array
    {
        return ['trial', 'basic', 'standard', 'pro', 'enterprise'];
    }

    /**
     * @return array<string,string>
     */
    public static function statusTitles(): array
    {
        return [
            self::STATUS_TRIAL     => 'آزمایشی',
            self::STATUS_ACTIVE    => 'فعال',
            self::STATUS_EXPIRED   => 'منقضی',
            self::STATUS_SUSPENDED => 'معلق',
            self::STATUS_CANCELED  => 'لغو شده',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function planTitles(): array
    {
        return [
            'trial'      => 'آزمایشی',
            'basic'      => 'پایه',
            'standard'   => 'استاندارد',
            'pro'        => 'حرفه‌ای',
            'enterprise' => 'سازمانی',
        ];
    }

    /* =================================================================
     * Reading
     * ===============================================================*/

    /**
     * @return array<string,mixed>|null
     */
    public static function currentFor(int $customerId): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `customer_id` = ?'
            . ' ORDER BY `is_current` DESC, `id` DESC LIMIT 1',
            [$customerId]
        );

        return $row === null ? null : self::cast($row);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function historyFor(int $customerId, int $limit = 20): array
    {
        $rows = Database::select(
            'SELECT s.*, u.`name` AS actor_name FROM `' . self::tableName() . '` s'
            . ' LEFT JOIN `' . Database::table('users') . '` u ON u.`id` = s.`created_by`'
            . ' WHERE s.`customer_id` = ? ORDER BY s.`id` DESC LIMIT ' . max(1, $limit),
            [$customerId]
        );

        return array_map([self::class, 'cast'], $rows);
    }

    /**
     * Effective status, taking the expire date into account. A row that
     * says "active" but expired yesterday is reported as "expired" -
     * without silently rewriting the database row.
     *
     * @param array<string,mixed>|null $subscription
     */
    public static function effectiveStatus(?array $subscription): string
    {
        if ($subscription === null) {
            return self::STATUS_TRIAL;
        }

        $status = (string) ($subscription['status'] ?? self::STATUS_TRIAL);
        $expire = $subscription['expire_date'] ?? null;

        if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_TRIAL], true)
            && is_string($expire) && $expire !== ''
            && strtotime($expire . ' 23:59:59') < time()
        ) {
            return self::STATUS_EXPIRED;
        }

        return $status;
    }

    /**
     * Whole days until the subscription expires (null = no end date).
     *
     * @param array<string,mixed>|null $subscription
     */
    public static function daysRemaining(?array $subscription): ?int
    {
        $expire = $subscription['expire_date'] ?? null;

        if (!is_string($expire) || $expire === '') {
            return null;
        }

        $seconds = strtotime($expire . ' 23:59:59');

        if ($seconds === false) {
            return null;
        }

        return (int) ceil(($seconds - time()) / 86400);
    }

    /**
     * Counters for the owner dashboard / subscription list.
     *
     * @return array<string,int>
     */
    public static function counts(): array
    {
        $total = (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('customers') . '` WHERE `deleted_at` IS NULL');

        $rows = Database::select(
            'SELECT * FROM `' . Database::table('customers') . '` WHERE `deleted_at` IS NULL'
        );

        $counts = [
            'total'       => $total,
            'active'      => 0,
            'trial'       => 0,
            'expired'     => 0,
            'suspended'   => 0,
            'expiring_7'  => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['subscription_status'] ?? self::STATUS_TRIAL);
            $expire = $row['subscription_expire_date'] ?? null;

            if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_TRIAL], true)
                && is_string($expire) && $expire !== '' && strtotime($expire . ' 23:59:59') < time()
            ) {
                $status = self::STATUS_EXPIRED;
            }

            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            if (is_string($expire) && $expire !== '') {
                $days = (int) ceil((strtotime($expire . ' 23:59:59') - time()) / 86400);

                if ($days >= 0 && $days <= 7) {
                    $counts['expiring_7']++;
                }
            }
        }

        return $counts;
    }

    /* =================================================================
     * Writing (always through SubscriptionService)
     * ===============================================================*/

    /**
     * @param array<string,mixed> $data
     */
    public static function record(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? Security::uuid4();

        // Only one current row per customer.
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `is_current` = 0 WHERE `customer_id` = ?',
            [(int) $data['customer_id']]
        );

        $data['is_current'] = 1;

        return self::create($data);
    }

    /**
     * Sync the denormalised columns on attendance_customers.
     *
     * @param array<string,mixed> $subscription
     */
    public static function syncCustomerColumns(int $customerId, array $subscription): void
    {
        // Accept both shapes: a full attendance_subscriptions row
        // (status / start_date / expire_date) and the "customer columns"
        // shape produced by SubscriptionService (subscription_status /
        // subscription_start_date / subscription_expire_date).
        $plan = (string) ($subscription['plan_name'] ?? 'trial');

        $data = [
            'plan_name'                => $plan,
            'subscription_plan'        => $plan,
            'subscription_status'      => (string) ($subscription['subscription_status']
                ?? $subscription['status']
                ?? self::STATUS_TRIAL),
            'subscription_start_date'  => $subscription['subscription_start_date']
                ?? $subscription['start_date']
                ?? null,
            'subscription_expire_date' => $subscription['subscription_expire_date']
                ?? $subscription['expire_date']
                ?? null,
        ];

        // Only touch the limits when they are actually part of the change.
        if (array_key_exists('max_employees', $subscription)) {
            $data['max_employees'] = max(1, (int) $subscription['max_employees']);
        }

        if (array_key_exists('max_devices', $subscription)) {
            $data['max_devices'] = max(1, (int) $subscription['max_devices']);
        }

        Database::update(Database::table('customers'), $data, ['id' => $customerId]);
    }
}
