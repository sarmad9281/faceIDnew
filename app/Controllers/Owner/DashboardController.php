<?php
/**
 * SARMAD Attendance - owner dashboard.
 *
 * Every widget is backed by a real query against attendance_* tables (see
 * OwnerDashboardService). Nothing on this page is a placeholder.
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Setting;
use App\Services\MigrationService;
use App\Services\OwnerDashboardService;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $offlineAfter = (int) Setting::get('device.offline_after', 300);
        $service      = new OwnerDashboardService();
        $migrations   = new MigrationService();

        return Response::html(View::render('owner/dashboard', [
            'title'        => 'داشبورد مالک سامانه',
            'data'         => $service->overview($offlineAfter),
            'pending'      => $migrations->pending(),
            'offlineAfter' => $offlineAfter,
            'active'       => 'dashboard',
        ], 'layouts/owner'));
    }

    /**
     * Employees overview (read-only in this stage: the employee records
     * themselves are managed by the customer manager panel in a later step).
     */
    public function employees(Request $request): Response
    {
        $service = new OwnerDashboardService();

        $filters = [
            'q'           => (string) $request->input('q', ''),
            'customer_id' => $request->int('customer_id', 0),
            'status'      => (string) $request->input('status', ''),
        ];

        $listing = \App\Models\Employee::paginate($filters, $request->page(), $request->perPage(20));

        return Response::html(View::render('owner/employees/index', [
            'title'     => 'نمای کلی پرسنل',
            'listing'   => $listing,
            'filters'   => $filters,
            'overview'  => $service->employeesOverview(),
            'customers' => \App\Models\Customer::listAll('', 500, 0),
            'active'    => 'employees',
        ], 'layouts/owner'));
    }
}
