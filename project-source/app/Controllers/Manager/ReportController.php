<?php
/**
 * SARMAD Attendance - manager panel: reports (stage 7).
 *
 * The nine report types of stage 3 are still here, plus the stage 7 additions
 * (advanced analysis, live "who is inside", payroll preview) and a REAL .xlsx
 * export. Every number comes from App\Services\ReportService, so the screen and
 * the spreadsheet can never disagree.
 *
 * The export is feature gated twice: the route middleware requires
 * excel_reports for the export endpoint AND the controller checks it again, so
 * a disabled feature can not be reached by URL, by POST or by the API.
 */

declare(strict_types=1);

namespace App\Controllers\Manager;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\ExcelExporter;
use App\Services\FeatureService;
use App\Services\ReportService;
use App\Support\Capability;
use App\Support\Input;

final class ReportController
{
    /** Report types of the panel. Order matters: it drives the tab strip. */
    public const TYPES = [
        'daily'        => 'گزارش روزانه',
        'monthly'      => 'گزارش ماهانه',
        'per_employee' => 'خلاصه پرسنل',
        'employee'     => 'کارنامه پرسنل',
        'late'         => 'تأخیر',
        'early_leave'  => 'تعجیل در خروج',
        'overtime'     => 'اضافه‌کاری',
        'absence'      => 'غیبت',
        'leave'        => 'مرخصی‌ها',
        'branch'       => 'شعب',
        'inside'       => 'حاضرین در مجموعه',
        'payroll'      => 'پیش‌نمایش حقوق',
    ];

    /** Types that need the branches feature enabled. */
    public const BRANCH_TYPES = ['branch'];

    /** Types that need payroll enabled. */
    public const PAYROLL_TYPES = ['payroll'];

    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('reports', $customerId);

        $filters = $this->filters($request, $customerId);
        $data    = (new ReportService())->build($customerId, $filters['type'], $filters);

        // Employee cards: only this customer, always.
        $employeeCard = null;

        if ($filters['type'] === 'employee' && $filters['employee_id'] > 0) {
            $employee = Employee::findInCustomer($filters['employee_id'], $customerId);
            $employeeCard = $employee === null ? null : [
                'id'   => (int) $employee['id'],
                'name' => Employee::fullName($employee),
                'code' => (string) ($employee['employee_code'] ?? ''),
            ];
        }

        return Response::html(View::render('manager/reports/index', [
            'title'            => 'گزارش‌ها',
            'types'            => self::TYPES,
            'filters'          => $filters,
            'data'             => $data,
            'employeeCard'     => $employeeCard,
            'employees'        => Employee::forCustomer($customerId, 500),
            'branches'         => \feature_enabled($customerId, 'branches') ? Branch::forCustomer($customerId) : [],
            'shifts'           => \feature_enabled($customerId, 'shifts') ? Shift::forCustomer($customerId) : [],
            'branchesEnabled'  => \feature_enabled($customerId, 'branches'),
            'payrollEnabled'   => \feature_enabled($customerId, 'payroll'),
            'advancedEnabled'  => \feature_enabled($customerId, 'advanced_reports'),
            'excelEnabled'     => \feature_enabled($customerId, 'excel_reports'),
            'customer'         => Customer::find($customerId),
            'error'            => Session::getFlash('error'),
            'success'          => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    /**
     * GET /manager/reports/export - the real .xlsx download.
     */
    public function export(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        // Route middleware already enforces it; this second check keeps the
        // guarantee even if the route table changes.
        FeatureService::requireFeature('reports', $customerId);
        FeatureService::requireFeature('excel_reports', $customerId);

        if (($denied = Capability::check($request, 'report.export')) !== null) {
            return $denied;
        }

        $filters = $this->filters($request, $customerId);
        $filters['type'] = Input::choice((string) $filters['type'], ReportService::TYPES, 'daily');

        if (in_array($filters['type'], self::PAYROLL_TYPES, true)) {
            FeatureService::requireFeature('payroll', $customerId);
        }

        if (in_array($filters['type'], self::BRANCH_TYPES, true)) {
            FeatureService::requireFeature('branches', $customerId);
        }

        $customer = Customer::find($customerId);
        $employee = $filters['employee_id'] > 0 ? Employee::findInCustomer((int) $filters['employee_id'], $customerId) : null;
        $branch   = $filters['branch_id'] > 0 ? Branch::findInCustomer((int) $filters['branch_id'], $customerId) : null;

        $exporter = new ExcelExporter();
        $built    = $exporter->build($customerId, (string) $filters['type'], $filters, [
            'customer_name' => (string) ($customer['name'] ?? ''),
            'employee_name' => $employee === null ? '' : Employee::fullName($employee),
            'branch_name'   => is_array($branch) ? (string) ($branch['name'] ?? '') : '',
            'shift_name'    => '',
        ]);

        $file = $exporter->toFile($built);

        AuditLog::record('reports.export.xlsx', 'success', 'خروجی اکسل گزارش گرفته شد.', [
            'type'      => (string) $filters['type'],
            'rows'      => $file['rows'],
            'bytes'     => $file['bytes'],
            'from'      => $filters['date_from'],
            'to'        => $filters['date_to'],
            'employee'  => $filters['employee_id'],
            'branch'    => $filters['branch_id'],
        ], 'reports', 'report', 0, $customerId);

        return Response::make((string) file_get_contents($file['path']), 200)
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('Content-Length', (string) $file['bytes'])
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(Request $request, int $customerId): array
    {
        $branchEnabled = \feature_enabled($customerId, 'branches');
        $shiftEnabled  = \feature_enabled($customerId, 'shifts');

        $type = Input::choice((string) $request->input('type', ''), self::TYPES, 'daily');

        if (in_array($type, self::PAYROLL_TYPES, true) && !\feature_enabled($customerId, 'payroll')) {
            $type = 'daily';
        }

        if (in_array($type, self::BRANCH_TYPES, true) && !$branchEnabled) {
            $type = 'daily';
        }

        $from = Input::date((string) $request->input('date_from', '')) ?? substr(date('Y-m-d'), 0, 8) . '01';
        $to   = Input::date((string) $request->input('date_to', '')) ?? date('Y-m-d');

        if ($to < $from) {
            $to = $from;
        }

        return [
            'type'        => $type,
            'date_from'   => $from,
            'date_to'     => $to,
            'employee_id' => (int) $request->int('employee_id', 0),
            'branch_id'   => $branchEnabled ? (int) $request->int('branch_id', 0) : 0,
            'shift_id'    => $shiftEnabled ? (int) $request->int('shift_id', 0) : 0,
            'status'      => Input::choice((string) $request->input('status', ''), array_keys(\App\Models\AttendanceRecord::STATUSES), ''),
            'origin'      => Input::choice((string) $request->input('origin', ''), ['online' => 'online', 'offline' => 'offline', 'manual' => 'manual'], ''),
            'page'        => max(1, (int) $request->int('page', 1)),
        ];
    }
}
