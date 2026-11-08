<?php
/**
 * SARMAD Attendance - minimal post-login surfaces.
 *
 * Stage 1 only proves: authentication, role protection and tenant
 * isolation. The complete manager / owner panels are built in the next
 * stages and will replace these views.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\View;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeFace;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Manager;
use App\Models\Shift;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\FeatureService;
use App\Support\Url;

final class DashboardController
{
    /**
     * Manager / supervisor dashboard - the working home of the customer panel.
     *
     * Every number on this page is a real COUNT over the tables of ONE
     * customer (the session tenant): employees, active employees, devices and
     * their status, branches, today's entries / exits / late arrivals /
     * absences, open shifts and the next holidays. Nothing is mocked, and on a
     * fresh installation the cards simply show zero / empty states.
     */
    public function manager(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        $today      = date('Y-m-d');

        $employeeCounts = Employee::statusCounts($customerId);
        $deviceCounts   = Device::statusCounts($customerId);
        $inventory      = (new DeviceService())->inventory($customerId);
        $todayKpis      = AttendanceRecord::kpis($customerId, $today);

        $branchesEnabled = \feature_enabled($customerId, 'branches');
        $shiftsEnabled   = \feature_enabled($customerId, 'shifts');

        $branches = $branchesEnabled ? Branch::forCustomerDetailed($customerId) : [];

        // "Absence" is only claimed when it can be proven: active employees
        // without a record today and without an approved leave today.
        $absentToday = AttendanceRecord::countStatus($customerId, $today, 'absent');
        $missingToday = Employee::withoutAttendanceOn($customerId, $today, 8);

        return Response::html(View::render('dashboard/manager', [
            'title'           => 'داشبورد مدیریت',
            'customer'        => Customer::find($customerId),
            'today'           => $today,
            'cards'           => [
                'employees'        => (int) $employeeCounts['total'],
                'employees_active' => (int) $employeeCounts['active'],
                'devices'          => (int) ($deviceCounts['total'] ?? 0),
                'devices_active'   => (int) ($deviceCounts['active'] ?? 0),
                'devices_pending'  => (int) ($deviceCounts['pending'] ?? 0),
                'branches'         => count($branches),
                'entries'          => (int) $todayKpis['entries'],
                'exits'            => (int) $todayKpis['exits'],
                'present'          => (int) $todayKpis['present'],
                'inside'           => (int) $todayKpis['currently_inside'],
                'completed'        => (int) $todayKpis['completed'],
                'late'             => (int) $todayKpis['late'],
                'early_leave'      => (int) $todayKpis['early_leave'],
                'overtime'         => (int) $todayKpis['overtime'],
                'absences'         => (int) $todayKpis['absent'],
                'on_leave'         => (int) $todayKpis['on_leave'],
                'work_minutes'     => (int) $todayKpis['work_minutes'],
            ],
            'todayKpis'       => $todayKpis,
            'absentToday'     => $absentToday,
            'missingToday'    => $missingToday,
            'inventory'       => $inventory,
            'branches'        => $branches,
            'branchRows'      => array_slice($branches, 0, 4),
            'devices'         => Device::forCustomer($customerId, '', 5),
            'shifts'          => $shiftsEnabled ? Shift::forCustomer($customerId, true) : [],
            'faceCounts'      => EmployeeFace::countsForCustomer($customerId),
            'faceEnrolled'    => count(array_filter(EmployeeFace::countsForCustomer($customerId))),
            'shiftAssignment' => EmployeeShift::activeCount($customerId),
            'upcomingHolidays' => Holiday::upcoming($customerId, 4),
            'pendingLeaves'   => Leave::statusCounts($customerId)['pending'] ?? 0,
            'recentAudit'     => AuditLog::recent($customerId, 8),
            'features'        => FeatureService::stateFor($customerId),
            'branchesEnabled' => $branchesEnabled,
            'shiftsEnabled'   => $shiftsEnabled,
            'onlineAfter'     => 300,
        ], 'layouts/app'));
    }

    /**
     * Owner overview - platform level counters plus a customer list.
     * Deliberately minimal in this stage.
     */
    public function owner(Request $request): Response
    {
        $search = (string) $request->input('q', '');

        $customers = Customer::listAll($search, 25, 0);

        $totals = [
            'customers' => Customer::totalCount(),
            'users'     => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('users') . '` WHERE `deleted_at` IS NULL'),
            'devices'   => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('devices') . '` WHERE `deleted_at` IS NULL'),
            'employees' => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('employees') . '` WHERE `deleted_at` IS NULL'),
        ];

        return Response::html(View::render('dashboard/owner', [
            'title'         => 'پنل مالک سامانه',
            'customers'     => $customers,
            'totals'        => $totals,
            'search'        => $search,
            'roleCounts'    => \App\Models\Role::userCounts(),
            'recentAudit'   => AuditLog::recent(null, 15),
            'error'         => Session::getFlash('error'),
            'success'       => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    /**
     * Placeholder for the future device kiosk surface. It only proves
     * that the "device" role is separated from the human roles; the
     * camera / face recognition UI belongs to a later stage.
     */
    /**
     * Stage 4: the device shell is the kiosk PWA served from /device.
     *
     * This method is kept only as a compatibility entry point for old
     * bookmarks: the former placeholder view (views/dashboard/device.php)
     * was a fake "device page" and is deleted on purpose, so nothing can
     * mistake it for the real kiosk.
     */
    public function device(Request $request): Response
    {
        return Response::redirect('/device');
    }

    public function health(Request $request): Response
    {
        $payload = [
            'status'     => 'ok',
            'app'        => 'SARMAD Attendance',
            'version'    => (string) \App\Core\Config::get('app.version', '1.0.0'),
            'php'        => PHP_VERSION,
            'database'   => Database::connected() ? 'connected' : 'disconnected',
            'tables'     => count(Database::projectTables()),
            'time'       => date('c'),
        ];

        return Response::json($payload);
    }

    public function home(Request $request): Response
    {
        if (Session::isDevice()) {
            return Response::redirect(Url::to('/device'));
        }

        return Response::redirect(Url::to('/dashboard'));
    }
}
