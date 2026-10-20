<?php
/**
 * SARMAD Attendance - feature gate service.
 *
 * THE RULE
 * ---------------------------------------------------------------------
 * A feature switch must be enforced by the BACKEND. Hiding a menu item is
 * cosmetics; this service is what actually stops a request:
 *
 *   FeatureService::allows(int $customerId, string $key)  -> bool
 *   FeatureService::requireFeature(string $key)           -> throws
 *
 * It is used by:
 *   - App\Middleware\FeatureMiddleware ('feature:branches' on a route)
 *   - every owner/manager controller that renders a feature page
 *   - the API controllers (JSON 403, not an HTML error page)
 *
 * Unknown keys are denied, so a typo in a route definition can never open
 * a gate that does not exist.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Session;
use App\Core\TenantContext;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\FeatureFlag;
use App\Models\Subscription;
use RuntimeException;

final class FeatureService
{
    /**
     * Is the feature enabled for this customer?
     */
    public static function allows(int $customerId, string $featureKey): bool
    {
        $featureKey = trim($featureKey);

        if ($featureKey === '' || !Feature::existsByKey($featureKey)) {
            Logger::channel('security')->warning('Unknown feature requested', ['feature' => $featureKey]);

            return false;
        }

        return FeatureFlag::isEnabled($customerId, $featureKey);
    }

    /**
     * Same check, but for the customer of the running request.
     */
    public static function allowsCurrentTenant(string $featureKey): bool
    {
        $customerId = TenantContext::customerId();

        if ($customerId === null || $customerId <= 0) {
            return false;
        }

        return self::allows($customerId, $featureKey);
    }

    /**
     * Hard gate: throws when the feature is off. Call it at the top of a
     * controller action when the route middleware is not enough.
     */
    public static function requireFeature(string $featureKey, ?int $customerId = null): void
    {
        $customerId = $customerId ?? TenantContext::customerId();

        if ($customerId === null) {
            throw new RuntimeException('No tenant scope for feature check.');
        }

        if (!self::allows($customerId, $featureKey)) {
            throw new RuntimeException('Feature disabled: ' . $featureKey);
        }
    }

    /**
     * Full state of every feature for a customer (owner panel matrix).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stateFor(int $customerId): array
    {
        return FeatureFlag::stateFor($customerId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function catalogue(): array
    {
        return Feature::catalogue();
    }

    /**
     * Apply a set of toggles coming from the owner form.
     *
     * The payload is a map of feature_key => "1"/"0". Unknown keys are
     * ignored, and ONLY keys present in the payload are touched, so a form
     * that forgets a checkbox can never silently enable it.
     *
     * @param array<string,mixed> $payload
     * @return array{changed:array<int,array{before:bool,enabled:bool,key:string,title:string}>, unchanged:int}
     */
    public static function applyMatrix(int $customerId, array $payload, int $actorUserId = 0): array
    {
        $changed   = [];
        $unchanged = 0;

        foreach (Feature::catalogue() as $feature) {
            $key     = (string) $feature['feature_key'];
            $field   = 'features[' . $key . ']';
            $present = array_key_exists($field, $payload) || array_key_exists($key, $payload);

            $raw = $payload[$field] ?? $payload[$key] ?? null;

            if (!$present) {
                // Master switch off for optional features (explicit "no").
                $raw = '0';
            }

            $enabled = in_array((string) $raw, ['1', 'on', 'true', 'yes'], true);

            $result = FeatureFlag::set($customerId, $key, $enabled, $actorUserId);

            if ($result['changed']) {
                $changed[] = [
                    'key'     => $key,
                    'title'   => (string) $feature['title_fa'],
                    'before'  => $result['before'],
                    'enabled' => $enabled,
                ];
            } else {
                $unchanged++;
            }
        }

        return ['changed' => $changed, 'unchanged' => $unchanged];
    }

    public static function set(int $customerId, string $featureKey, bool $enabled, int $actorUserId = 0): array
    {
        return FeatureFlag::set($customerId, $featureKey, $enabled, $actorUserId);
    }

    public static function seedDefaults(int $customerId, int $actorUserId = 0): int
    {
        return FeatureFlag::seedDefaults($customerId, $actorUserId);
    }

    /**
     * Feature state for the customer of the running actor, with the
     * subscription state folded in (a disabled subscription switches
     * non-core features off even if the flag says otherwise - the flag is
     * never rewritten, only the evaluation changes).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stateForCurrentTenant(): array
    {
        $customerId = TenantContext::customerId();

        if ($customerId === null || $customerId <= 0) {
            return [];
        }

        $state    = self::stateFor($customerId);
        $customer = Customer::find($customerId);
        $status   = Subscription::effectiveStatus([
            'status'      => (string) ($customer['subscription_status'] ?? 'trial'),
            'expire_date' => $customer['subscription_expire_date'] ?? null,
        ]);

        $blocked = in_array($status, [Subscription::STATUS_EXPIRED, Subscription::STATUS_SUSPENDED, Subscription::STATUS_CANCELED], true);

        if (!$blocked) {
            return $state;
        }

        foreach ($state as $index => $feature) {
            if ($feature['is_core']) {
                continue;
            }

            $state[$index]['enabled']            = false;
            $state[$index]['blocked_by_subscription'] = true;
        }

        return $state;
    }

    /**
     * Navigation helper for the panel sidebars.
     *
     * @return array<string,bool>
     */
    public static function navFlags(): array
    {
        $customerId = TenantContext::customerId();

        if ($customerId === null || $customerId <= 0) {
            return [];
        }

        $flags = [];

        foreach (Feature::keys() as $key) {
            $flags[$key] = self::allows($customerId, $key);
        }

        return $flags;
    }

    public static function actorUserId(): int
    {
        return (int) (Session::userId() ?? 0);
    }
}
