<?php
/**
 * SARMAD Attendance - global helpers.
 *
 * feature_enabled($customerId, $key) is THE single central switch for the
 * optional modules of a customer. It is used in three places so that a
 * feature can never be reached by a hand crafted URL:
 *
 *   1. the route middleware 'feature:<key>'  (blocks the request)
 *   2. FeatureService::requireFeature()      (blocks controller / API work)
 *   3. the views                             (hides the menu entry)
 *
 * The helper always answers for ONE customer id which the caller must have
 * taken from the session / authorization context - never from a GET or POST
 * parameter.
 */

declare(strict_types=1);

use App\Services\FeatureService;

if (!function_exists('feature_enabled')) {
    function feature_enabled($customerId, string $key): bool
    {
        $customerId = (int) $customerId;

        if ($customerId <= 0 || $key === '') {
            return false;
        }

        return FeatureService::allows($customerId, $key);
    }
}

if (!function_exists('tenant_feature_enabled')) {
    /**
     * Same question for the customer resolved from the session.
     */
    function tenant_feature_enabled(string $key): bool
    {
        $customerId = \App\Core\TenantContext::customerId();

        return $customerId !== null && feature_enabled($customerId, $key);
    }
}
