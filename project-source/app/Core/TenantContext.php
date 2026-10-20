<?php
/**
 * SARMAD Attendance - the tenant boundary of the running request.
 *
 * Every query against tenant owned tables must be filtered by the
 * customer id kept here. Models get it through Database::table() plus
 * an explicit "scope" closure, and the helpers below make the intent
 * obvious at the call site:
 *
 *   $scope = TenantContext::scope('customer_id');   // ['customer_id' => 12]
 *   Database::select('SELECT ... WHERE customer_id = :cid', $scope);
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class TenantContext
{
    private static ?int $customerId = null;
    private static string $actorType = 'guest';
    private static bool $resolved = false;
    private static int $impersonationId = 0;

    public static function set(int $customerId, string $actorType): void
    {
        self::$customerId = $customerId > 0 ? $customerId : null;
        self::$actorType  = $actorType;
        self::$resolved   = true;
    }

    public static function resolved(): bool
    {
        return self::$resolved;
    }

    /**
     * Emergency access marker: > 0 while the owner is inside a customer
     * panel. Controllers use it to add audit context.
     */
    public static function markImpersonation(int $id): void
    {
        self::$impersonationId = max(0, $id);
    }

    public static function impersonationId(): int
    {
        return self::$impersonationId;
    }

    public static function impersonating(): bool
    {
        return self::$impersonationId > 0;
    }

    public static function actorType(): string
    {
        return self::$actorType;
    }

    public static function isPlatform(): bool
    {
        return self::$actorType === 'owner' && self::$customerId === null;
    }

    /**
     * @return int|null null means "whole platform" (owner without scope)
     */
    public static function customerId(): ?int
    {
        return self::$customerId;
    }

    /**
     * Throws unless a single customer scope is active. Use it in every
     * manager/device facing action.
     */
    public static function requireCustomerId(): int
    {
        if (self::$customerId === null) {
            throw new RuntimeException('No tenant scope is active for this request.');
        }

        return self::$customerId;
    }

    /**
     * Build a parameter array that enforces the tenant filter.
     *
     * @return array<string,mixed>
     */
    public static function scope(string $column = 'customer_id'): array
    {
        $id = self::$customerId;

        if ($id === null) {
            throw new RuntimeException('Tenant scope is required but not resolved.');
        }

        Database::identifier($column);

        return [$column => $id];
    }

    /**
     * SQL fragment + bound parameter, ready to paste into a WHERE clause.
     *
     * @param array<string,mixed> $params bound params (by reference)
     */
    public static function whereFragment(string $column, array &$params, string $alias = ''): string
    {
        $id      = self::requireCustomerId();
        $column  = Database::identifier($column);
        $prefix  = $alias !== '' ? Database::identifier($alias) . '.' : '';
        $key     = ':tenant_' . $column . '_' . count($params);

        $params[$key] = $id;

        return $prefix . '`' . $column . '` = ' . $key;
    }

    /**
     * Assert that a row really belongs to the active tenant.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    public static function assertOwner(?array $row, string $column = 'customer_id'): array
    {
        if ($row === null) {
            throw new RuntimeException('Resource not found.');
        }

        $expected = self::requireCustomerId();
        $actual   = isset($row[$column]) ? (int) $row[$column] : 0;

        if ($actual !== $expected) {
            Logger::channel('security')->error('Cross tenant access blocked', [
                'expected_customer' => $expected,
                'actual_customer'   => $actual,
                'actor'             => Session::actorLabel(),
            ]);

            throw new RuntimeException('Cross tenant access blocked.');
        }

        return $row;
    }

    public static function reset(): void
    {
        self::$customerId      = null;
        self::$actorType       = 'guest';
        self::$resolved        = false;
        self::$impersonationId = 0;
    }
}
