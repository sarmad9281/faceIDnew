<?php
/**
 * SARMAD Attendance - subscription management (stage 2, no online payment).
 *
 * Every change:
 *   1. writes a history row in attendance_subscriptions,
 *   2. syncs the current-state columns of attendance_customers,
 *   3. writes an audit entry (CHANGE_SUBSCRIPTION).
 *
 * All three steps happen in one transaction, so the two representations of
 * "the current plan" can never disagree.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Customer;
use App\Models\Subscription;
use RuntimeException;

final class SubscriptionService
{
    /**
     * @param array{plan_name?:string,status?:string,start_date?:string,expire_date?:string,price?:int|string,max_employees?:int,max_devices?:int,notes?:string} $data
     * @return array{ok:bool,message:string,subscription_id:int,changed:array<string,array{0:mixed,1:mixed}>}
     */
    public function update(int $customerId, array $data, int $actorUserId = 0): array
    {
        $customer = Customer::find($customerId);

        if ($customer === null) {
            throw new RuntimeException('Customer not found: ' . $customerId);
        }

        $plan   = $this->normalizePlan((string) ($data['plan_name'] ?? 'trial'));
        $status = $this->normalizeStatus((string) ($data['status'] ?? Subscription::STATUS_TRIAL));
        $start  = $this->normalizeDate($data['start_date'] ?? null);
        $expire = $this->normalizeDate($data['expire_date'] ?? null);

        if ($start !== null && $expire !== null && strtotime($expire) < strtotime($start)) {
            throw new RuntimeException('تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.');
        }

        $maxEmployees = max(1, (int) ($data['max_employees'] ?? (int) ($customer['max_employees'] ?? 50)));
        $maxDevices   = max(1, (int) ($data['max_devices'] ?? (int) ($customer['max_devices'] ?? 5)));
        $price        = $this->normalizePrice($data['price'] ?? null);

        $before = [
            'plan_name'                => (string) ($customer['plan_name'] ?? $customer['subscription_plan'] ?? 'trial'),
            'subscription_status'      => (string) ($customer['subscription_status'] ?? 'trial'),
            'subscription_start_date'  => $customer['subscription_start_date'] ?? null,
            'subscription_expire_date' => $customer['subscription_expire_date'] ?? null,
        ];

        $subscription = [
            'customer_id'   => $customerId,
            'plan_name'     => $plan,
            'status'        => $status,
            'start_date'    => $start,
            'expire_date'   => $expire,
            'price'         => $price,
            'currency'      => (string) ($data['currency'] ?? 'IRT'),
            'max_employees' => $maxEmployees,
            'max_devices'   => $maxDevices,
            'notes'         => isset($data['notes']) && $data['notes'] !== '' ? substr((string) $data['notes'], 0, 255) : null,
            'created_by'    => $actorUserId > 0 ? $actorUserId : null,
        ];

        $changed  = [];
        $after    = [
            'plan_name'                => $plan,
            'subscription_status'      => $status,
            'subscription_start_date'  => $start,
            'subscription_expire_date' => $expire,
        ];

        foreach ($after as $key => $value) {
            if ((string) ($before[$key] ?? '') !== (string) $value) {
                $changed[$key] = [$before[$key] ?? null, $value];
            }
        }

        $subscriptionId = Database::transaction(function () use ($customerId, $subscription, $after): int {
            $id = Subscription::record($subscription);
            Subscription::syncCustomerColumns($customerId, $after);

            return $id;
        });

        AuditService::log(
            'subscription.update',
            'success',
            'اشتراک مشتری به‌روزرسانی شد: پلن ' . $plan . ' / وضعیت ' . $status,
            [
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'before' => $before,
                'after'  => $after,
            ],
            'app',
            'customer',
            $customerId,
            $customerId
        );

        return [
            'ok'              => true,
            'message'         => 'اشتراک مشتری «' . (string) $customer['name'] . '» ذخیره شد.',
            'subscription_id' => $subscriptionId,
            'changed'         => $changed,
        ];
    }

    /**
     * Expire every subscription whose end date has passed. Returns how
     * many rows were touched. Never deletes anything.
     *
     * @return array<int,array{customer_id:int,name:string,expire_date:string}>
     */
    public function expireOverdue(): array
    {
        $rows = Database::select(
            'SELECT `id`, `name`, `subscription_expire_date` FROM `' . Database::table('customers') . '`'
            . " WHERE `deleted_at` IS NULL AND `subscription_status` IN ('active', 'trial')"
            . ' AND `subscription_expire_date` IS NOT NULL AND `subscription_expire_date` < CURDATE()'
        );

        $expired = [];

        foreach ($rows as $row) {
            Database::update(Database::table('customers'), ['subscription_status' => Subscription::STATUS_EXPIRED], ['id' => (int) $row['id']]);

            Database::run(
                'UPDATE `' . Database::table('subscriptions') . '` SET `status` = ? WHERE `customer_id` = ? AND `is_current` = 1',
                [Subscription::STATUS_EXPIRED, (int) $row['id']]
            );

            AuditService::log(
                'subscription.expired',
                'success',
                'اشتراک مشتری به دلیل رسیدن تاریخ پایان منقضی شد.',
                ['customer_id' => (int) $row['id'], 'expire_date' => (string) $row['subscription_expire_date']],
                'app',
                'customer',
                (int) $row['id'],
                (int) $row['id']
            );

            $expired[] = [
                'customer_id' => (int) $row['id'],
                'name'        => (string) $row['name'],
                'expire_date' => (string) $row['subscription_expire_date'],
            ];
        }

        return $expired;
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $customerId): array
    {
        $customer     = Customer::find($customerId);
        $subscription = Subscription::currentFor($customerId);

        return [
            'customer'       => $customer,
            'subscription'   => $subscription,
            'status'         => Subscription::effectiveStatus($subscription),
            'days_remaining' => Subscription::daysRemaining($subscription),
            'history'        => Subscription::historyFor($customerId, 20),
        ];
    }

    private function normalizePlan(string $plan): string
    {
        $plan = strtolower(trim($plan));

        if ($plan === '') {
            return 'trial';
        }

        return preg_match('/^[a-z0-9_\-]{1,40}$/', $plan) === 1 ? $plan : 'trial';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, Subscription::statuses(), true) ? $status : Subscription::STATUS_TRIAL;
    }

    /**
     * @param mixed $value
     */
    private function normalizeDate($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $check = \DateTime::createFromFormat('Y-m-d', $value);

        return ($check !== false && $check->format('Y-m-d') === $value) ? $value : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizePrice($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }
}
