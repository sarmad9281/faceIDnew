<?php
/**
 * SARMAD Attendance - owner panel: subscriptions.
 *
 * Stage 2 has no online payment: the owner sets the plan, the status and
 * the dates, and every change is versioned in attendance_subscriptions
 * (history) + audited as CHANGE_SUBSCRIPTION.
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\SubscriptionService;

final class SubscriptionController
{
    public function index(Request $request): Response
    {
        $filters = [
            'q'            => (string) $request->input('q', ''),
            'subscription' => (string) $request->input('subscription', ''),
            'plan'         => (string) $request->input('plan', ''),
            'status'       => (string) $request->input('status', ''),
        ];

        $listing = Customer::paginateForOwner($filters, $request->page(), $request->perPage(20));
        $listing['rows'] = Customer::withCounters($listing['rows']);

        foreach ($listing['rows'] as $index => $row) {
            $listing['rows'][$index]['days_remaining'] = Subscription::daysRemaining([
                'expire_date' => $row['subscription_expire_date'] ?? null,
            ]);
        }

        return Response::html(View::render('owner/subscriptions/index', [
            'title'   => 'مدیریت اشتراک‌ها',
            'listing' => $listing,
            'filters' => $filters,
            'counts'  => Subscription::counts(),
            'plans'   => Subscription::planTitles(),
            'statuses'=> Subscription::statusTitles(),
            'active'  => 'subscriptions',
        ], 'layouts/owner'));
    }

    public function show(Request $request): Response
    {
        $customer = $this->customer($request);

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/subscriptions');
        }

        $customerId = (int) $customer['id'];

        return Response::html(View::render('owner/subscriptions/show', [
            'title'        => 'اشتراک مشتری: ' . (string) $customer['name'],
            'customer'     => $customer,
            'subscription' => Subscription::currentFor($customerId),
            'history'      => Subscription::historyFor($customerId, 30),
            'status'       => Subscription::effectiveStatus(Subscription::currentFor($customerId)),
            'daysLeft'     => Subscription::daysRemaining(Subscription::currentFor($customerId)),
            'plans'        => Subscription::planTitles(),
            'statuses'     => Subscription::statusTitles(),
            'active'       => 'subscriptions',
        ], 'layouts/owner'));
    }

    public function update(Request $request): Response
    {
        $customer = $this->customer($request);

        if ($customer === null) {
            Session::flash('error', 'مشتری یافت نشد.');

            return Redirect::response('/owner/subscriptions');
        }

        $customerId = (int) $customer['id'];

        try {
            $result = (new SubscriptionService())->update($customerId, [
                'plan_name'     => (string) $request->input('plan_name', 'trial'),
                'status'        => (string) $request->input('status', 'trial'),
                'start_date'    => (string) $request->input('start_date', ''),
                'expire_date'   => (string) $request->input('expire_date', ''),
                'price'         => (string) $request->input('price', ''),
                'max_employees' => $request->int('max_employees', 50),
                'max_devices'   => $request->int('max_devices', 5),
                'notes'         => (string) $request->input('notes', ''),
            ], (int) (Session::userId() ?? 0));
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());

            return Redirect::response('/owner/subscriptions/' . (string) $customer['uuid']);
        }

        $changes = [];

        foreach ($result['changed'] as $field => $pair) {
            $changes[] = $field . ': ' . (string) ($pair[0] ?? '-') . ' → ' . (string) $pair[1];
        }

        Session::flash('success', $result['message'] . ($changes === [] ? ' (بدون تغییر)' : ' | ' . implode(' ، ', $changes)));

        return Redirect::response('/owner/subscriptions/' . (string) $customer['uuid']);
    }

    /**
     * Expire every overdue subscription (maintenance action).
     */
    public function expireOverdue(Request $request): Response
    {
        $expired = (new SubscriptionService())->expireOverdue();

        if ($expired === []) {
            Session::flash('success', 'هیچ اشتراک منقضی‌شده‌ای یافت نشد.');

            return Redirect::response('/owner/subscriptions');
        }

        Session::flash('success', count($expired) . ' اشتراک منقضی شد و وضعیت آن‌ها به «منقضی» تغییر کرد.');

        return Redirect::response('/owner/subscriptions');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function customer(Request $request): ?array
    {
        $uuid = $request->param('uuid');

        if ($uuid !== '') {
            return Customer::findByUuid($uuid) ?? Customer::find((int) $uuid);
        }

        $id = $request->int('customer_id', 0);

        return $id > 0 ? Customer::find($id) : null;
    }
}
