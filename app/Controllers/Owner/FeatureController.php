<?php
/**
 * SARMAD Attendance - owner panel: per customer feature control.
 *
 * This page is the single place where the owner decides what a customer is
 * allowed to use. The switch is not cosmetic: App\Middleware\FeatureMiddleware
 * and FeatureService::allows() enforce the same value on the backend, so a
 * disabled feature is unreachable even by typing the URL directly.
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Customer;
use App\Models\Feature;
use App\Services\FeatureService;

final class FeatureController
{
    /**
     * Global matrix: every customer x every feature.
     */
    public function index(Request $request): Response
    {
        $features = Feature::catalogue();

        $customers = \App\Core\Database::select(
            'SELECT `id`, `uuid`, `name`, `status`, `subscription_status`'
            . ' FROM `' . \App\Core\Database::table('customers') . '` WHERE `deleted_at` IS NULL'
            . ' ORDER BY `id` DESC LIMIT 200'
        );

        $matrix = [];

        foreach ($customers as $customer) {
            $state = FeatureService::stateFor((int) $customer['id']);
            $row   = [];

            foreach ($state as $feature) {
                $row[(string) $feature['key']] = $feature;
            }

            $matrix[] = ['customer' => $customer, 'features' => $row];
        }

        return Response::html(View::render('owner/features/index', [
            'title'    => 'مدیریت امکانات مشتریان',
            'features' => $features,
            'matrix'   => $matrix,
            'active'   => 'features',
        ], 'layouts/owner'));
    }

    /**
     * Per customer feature page.
     */
    public function show(Request $request): Response
    {
        $customer = Customer::findByUuid($request->param('uuid')) ?? Customer::find((int) $request->param('uuid'));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        return Response::html(View::render('owner/features/show', [
            'title'    => 'امکانات مشتری: ' . (string) $customer['name'],
            'customer' => $customer,
            'features' => FeatureService::stateFor((int) $customer['id']),
            'active'   => 'features',
        ], 'layouts/owner'));
    }

    /**
     * Save the feature matrix of one customer.
     */
    public function update(Request $request): Response
    {
        $customer = Customer::findByUuid($request->param('uuid')) ?? Customer::find((int) $request->param('uuid'));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $customerId = (int) $customer['id'];
        $payload    = $request->all();
        $actorId    = (int) (Session::userId() ?? 0);

        $result = FeatureService::applyMatrix($customerId, $payload, $actorId);

        $changed = $result['changed'];

        if ($changed === []) {
            Session::flash('success', 'تغییر جدیدی ثبت نشد؛ تنظیمات امکانات بدون تغییر ماند.');

            return Redirect::response('/owner/customers/' . (string) $customer['uuid'] . '/features');
        }

        $parts = [];

        foreach ($changed as $item) {
            $parts[] = $item['title'] . ' → ' . ($item['enabled'] ? 'فعال' : 'غیرفعال');
        }

        \App\Models\AuditLog::record('feature.update', 'success',
            'امکانات مشتری «' . (string) $customer['name'] . '» تغییر کرد: ' . implode(' ، ', $parts),
            [
                'customer_id' => $customerId,
                'changed'     => $changed,
            ],
            'app',
            'customer',
            $customerId,
            $customerId
        );

        Session::flash('success', 'امکانات مشتری ذخیره شد (' . count($changed) . ' تغییر).');

        return Redirect::response('/owner/customers/' . (string) $customer['uuid'] . '/features');
    }

    /**
     * Single toggle endpoint (used by the matrix page and by API clients).
     */
    public function toggle(Request $request): Response
    {
        $customer = Customer::findByUuid($request->param('uuid')) ?? Customer::find((int) $request->param('uuid'));

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/customers');
        }

        $customerId = (int) $customer['id'];
        $key        = (string) $request->input('feature', '');
        $enabled    = (string) $request->input('enabled', '0') === '1';

        if (!Feature::existsByKey($key)) {
            Session::flash('error', 'امکان درخواستی شناخته نشد.');

            return Redirect::response('/owner/features/' . (string) $customer['uuid']);
        }

        $result = FeatureService::set($customerId, $key, $enabled, (int) (Session::userId() ?? 0));
        $title  = (string) (Feature::byKey($key)['title_fa'] ?? $key);

        if ($result['changed']) {
            \App\Models\AuditLog::record('feature.update', 'success',
                'امکان «' . $title . '» برای مشتری «' . (string) $customer['name'] . '» ' . ($enabled ? 'فعال' : 'غیرفعال') . ' شد.',
                ['customer_id' => $customerId, 'feature' => $key, 'enabled' => $enabled],
                'app',
                'customer',
                $customerId,
                $customerId
            );

            Session::flash('success', 'امکان «' . $title . '» ' . ($enabled ? 'فعال' : 'غیرفعال') . ' شد.');
        } else {
            Session::flash('success', 'این امکان از قبل ' . ($enabled ? 'فعال' : 'غیرفعال') . ' بود.');
        }

        return Redirect::response('/owner/features/' . (string) $customer['uuid']);
    }
}
