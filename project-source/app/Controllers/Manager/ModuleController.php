<?php
/**
 * SARMAD Attendance - manager panel: the feature gated report modules.
 *
 * Stage 3 kept these pages as an honest "not built yet" placeholder. Stage 7
 * turns two of them into real features:
 *
 *   /manager/reports/advanced  (feature advanced_reports)
 *       trend, employee comparison and branch comparison built from the real
 *       attendance rows - if the feature is off the route middleware answers
 *       403 and the page is unreachable, by URL or by POST.
 *
 *   /manager/reports/excel     (feature excel_reports)
 *       the export centre: every report type can be downloaded as a real
 *       .xlsx; the download endpoint itself is feature gated as well.
 *
 * The payroll / telegram placeholders are gone: those features now have their
 * own controllers (PayrollController, TelegramController).
 */

declare(strict_types=1);

namespace App\Controllers\Manager;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\View;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Services\FeatureService;
use App\Services\ReportService;
use App\Support\Input;

final class ModuleController
{
    public function advancedReports(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('advanced_reports', $customerId);

        $from = Input::date((string) $request->input('date_from', '')) ?? substr(date('Y-m-d'), 0, 8) . '01';
        $to   = Input::date((string) $request->input('date_to', '')) ?? date('Y-m-d');

        if ($to < $from) {
            $to = $from;
        }

        $filters = ['date_from' => $from, 'date_to' => $to];
        $service = new ReportService();

        $branchesEnabled = \feature_enabled($customerId, 'branches');

        return Response::html(View::render('manager/reports/advanced', [
            'title'           => 'گزارش‌های تحلیلی',
            'filters'         => $filters,
            'trend'           => $service->trend($customerId, $filters),
            'employees'       => $service->compareEmployees($customerId, $filters, 50),
            'branches'        => $branchesEnabled ? $service->compareBranches($customerId, $filters) : [],
            'branchesEnabled' => $branchesEnabled,
            'summary'         => $service->daily($customerId, $filters, 1, 10)['summary'],
            'customer'        => Customer::find($customerId),
            'excelEnabled'    => \feature_enabled($customerId, 'excel_reports'),
            'error'           => Session::getFlash('error'),
            'success'         => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    /**
     * The export centre. The actual file is produced by /manager/reports/export
     * (ReportController::export) which checks excel_reports again.
     */
    public function excelReports(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('excel_reports', $customerId);

        $from = Input::date((string) $request->input('date_from', '')) ?? substr(date('Y-m-d'), 0, 8) . '01';
        $to   = Input::date((string) $request->input('date_to', '')) ?? date('Y-m-d');

        $types = ReportService::TYPES;

        if (!\feature_enabled($customerId, 'payroll')) {
            unset($types['payroll']);
        }

        if (!\feature_enabled($customerId, 'branches')) {
            unset($types['branch']);
        }

        return Response::html(View::render('manager/reports/excel', [
            'title'    => 'خروجی اکسل',
            'types'    => $types,
            'filters'  => ['date_from' => $from, 'date_to' => $to],
            'employees' => Employee::forCustomer($customerId, 500),
            'branches' => \feature_enabled($customerId, 'branches') ? Branch::forCustomer($customerId) : [],
            'branchesEnabled' => \feature_enabled($customerId, 'branches'),
            'library'  => [
                'name'    => 'PhpSpreadsheet',
                'version' => $this->libraryVersion(),
                'path'    => 'vendor/phpoffice/phpspreadsheet',
                'format'  => 'xlsx (OOXML)',
            ],
            'customer' => Customer::find($customerId),
            'error'    => Session::getFlash('error'),
            'success'  => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    private function libraryVersion(): string
    {
        $composer = SARMAD_ROOT . '/vendor/composer/installed.json';

        if (!is_file($composer)) {
            return 'نصب نشده';
        }

        $data = json_decode((string) file_get_contents($composer), true);

        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];

        foreach ((array) $packages as $package) {
            if (is_array($package) && ($package['name'] ?? '') === 'phpoffice/phpspreadsheet') {
                return (string) ($package['version'] ?? 'unknown');
            }
        }

        return 'unknown';
    }
}
