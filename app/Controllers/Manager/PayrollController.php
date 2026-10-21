<?php
/**
 * SARMAD Attendance - manager panel: payroll (stage 7).
 *
 * The page keeps the rate contract of every employee (attendance_payroll_profiles,
 * extended in stage 7 with the deduction rates the calculator needs), shows a
 * LIVE preview of the selected period (App\Services\PayrollCalculator), and
 * freezes a period into attendance_payroll_periods / _items. A finalized period
 * is never changed silently: recalculating it writes attendance_payroll_revisions
 * with the reason and the acting user.
 *
 * The whole page is feature gated: route middleware + FeatureService::requireFeature
 * here, and the controller is only reachable with payroll enabled.
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
use App\Models\Customer;
use App\Models\CustomerSetting;
use App\Models\Employee;
use App\Models\PayrollProfile;
use App\Services\FeatureService;
use App\Services\PayrollCalculator;
use App\Services\PayrollService;
use App\Support\Capability;
use App\Support\Input;

final class PayrollController
{
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        $service = new PayrollService();

        $from = Input::date((string) $request->input('date_from', '')) ?? substr(date('Y-m-d'), 0, 8) . '01';
        $to   = Input::date((string) $request->input('date_to', '')) ?? date('Y-m-d');

        if ($to < $from) {
            $to = $from;
        }

        $employeeId = (int) $request->int('employee_id', 0);

        $preview = $service->preview($customerId, [
            'date_from'   => $from,
            'date_to'     => $to,
            'employee_id' => $employeeId,
        ]);

        $periodId = (int) $request->int('period_id', 0);
        $period   = $periodId > 0 ? $service->period($customerId, $periodId) : null;

        $items = $period === null ? [] : $service->items($customerId, $periodId);

        $totals = ['gross' => 0.0, 'net' => 0.0, 'deductions' => 0.0, 'overtime' => 0];

        foreach ($items === [] ? $preview : $items as $row) {
            $totals['gross'] += (float) $row['gross_amount'];
            $totals['net'] += (float) $row['net_amount'];
            $totals['deductions'] += (float) ($row['total_deductions'] ?? ((float) $row['late_deduction'] + (float) $row['early_leave_deduction'] + (float) $row['absence_deduction'] + (float) $row['fixed_deductions'] + (float) $row['other_deductions']));
            $totals['overtime'] += (int) $row['overtime_minutes'];
        }

        return Response::html(View::render('manager/payroll/index', [
            'title'      => 'حقوق و دستمزد',
            'rows'       => PayrollProfile::listingForCustomer($customerId),
            'types'      => PayrollProfile::TYPES,
            'typeCounts' => PayrollProfile::typeCounts($customerId),
            'employeeCount' => Employee::totalCount($customerId),
            'profiles'   => PayrollProfile::profileCount($customerId),
            'settings'   => CustomerSetting::all($customerId),
            'customer'   => Customer::find($customerId),
            'engine'     => [
                'from'       => $from,
                'to'         => $to,
                'employee_id' => $employeeId,
                'preview'    => $preview,
                'periods'    => $service->periods($customerId),
                'period'     => $period,
                'items'      => $items,
                'revisions'  => $period === null ? [] : $service->revisions($customerId, (int) $period['id']),
                'totals'     => $totals,
                'calc_version' => PayrollCalculator::VERSION,
                'pay_types'  => PayrollCalculator::PAY_TYPES,
                'absence_modes' => PayrollCalculator::ABSENCE_MODES,
                'rates'      => $this->rateColumns(),
            ],
            'error'      => Session::getFlash('error'),
            'success'    => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    /**
     * The stage 7 rate fields of a profile: field => [label, hint, step].
     *
     * @return array<string,array{label:string,hint:string}>
     */
    private function rateColumns(): array
    {
        return [
            'late_rate'           => ['label' => 'کسر هر دقیقه تأخیر', 'hint' => 'خالی = نرخ ساعتی ÷ ۶۰'],
            'early_leave_rate'    => ['label' => 'کسر هر دقیقه تعجیل خروج', 'hint' => 'خالی = نرخ ساعتی ÷ ۶۰'],
            'absence_mode'        => ['label' => 'روش کسر غیبت', 'hint' => 'روزانه / دقیقه‌ای / بدون کسر'],
            'absence_rate'        => ['label' => 'نرخ کسر غیبت', 'hint' => 'خالی = نرخ روزانه'],
            'overtime_multiplier' => ['label' => 'ضریب اضافه‌کاری', 'hint' => 'وقتی نرخ مستقیم وارد نشده باشد'],
            'work_days_per_month' => ['label' => 'روز کارکرد ماه', 'hint' => 'پیش‌فرض ۳۰'],
            'work_hours_per_day'  => ['label' => 'ساعت کار روز', 'hint' => 'پیش‌فرض ۸'],
            'fixed_deductions'    => ['label' => 'کسورات ثابت', 'hint' => 'بیمه، صندوق، اقساط'],
            'job_title'           => ['label' => 'عنوان شغلی', 'hint' => 'اختیاری'],
        ];
    }

    /**
     * POST /manager/payroll/period - create (or reuse) an open period.
     */
    public function period(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        if (($denied = Capability::check($request, 'payroll.manage')) !== null) {
            return $denied;
        }

        $from = Input::date((string) $request->input('date_from', '')) ?? '';
        $to   = Input::date((string) $request->input('date_to', '')) ?? '';

        $result = (new PayrollService())->createPeriod(
            $this->actor($request),
            $customerId,
            $from,
            $to,
            Input::text((string) $request->input('title', ''), 120)
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/payroll?period_id=' . (int) ($result['data']['period_id'] ?? 0)
            . '&date_from=' . urlencode($from) . '&date_to=' . urlencode($to));
    }

    /**
     * POST /manager/payroll/finalize - freeze the period.
     */
    public function finalize(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        if (($denied = Capability::check($request, 'payroll.manage')) !== null) {
            return $denied;
        }

        $periodId = (int) $request->int('period_id', 0);
        $result   = (new PayrollService())->finalize($this->actor($request), $customerId, $periodId);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/payroll?period_id=' . $periodId);
    }

    /**
     * POST /manager/payroll/revise - recalculate a finalized period, with a
     * reason, keeping the previous amounts as revisions.
     */
    public function revise(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        if (($denied = Capability::check($request, 'payroll.manage')) !== null) {
            return $denied;
        }

        $periodId = (int) $request->int('period_id', 0);

        $result = (new PayrollService())->revise(
            $this->actor($request),
            $customerId,
            $periodId,
            Input::text((string) $request->input('reason', ''), 500)
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/payroll?period_id=' . $periodId);
    }

    /**
     * The acting user + request context that is written to the audit trail and
     * to a payroll revision.
     *
     * @return array<string,mixed>
     */
    private function actor(Request $request): array
    {
        return [
            'id'    => (int) (Session::userId() ?? 0),
            'name'  => (string) (Session::get('user_name') ?? ''),
            'role'  => (string) (Session::role() ?? ''),
            'ip'    => \App\Core\Request::ip(),
        ];
    }

    /**
     * Insert / update the payroll profile of ONE employee of this customer.
     */
    public function save(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        if (($denied = Capability::check($request, 'payroll.manage')) !== null) {
            return $denied;
        }

        $employeeId = (int) $request->int('employee_id', 0);
        $employee   = Employee::findInCustomer($employeeId, $customerId);

        if ($employee === null) {
            AuditLog::denied('تلاش برای ثبت پروفایل حقوقی برای پرسنل خارج از مشتری', [
                'employee_id' => $employeeId,
                'customer_id' => $customerId,
            ]);

            Session::flash('error', 'پرسنل یافت نشد.');

            return Redirect::response('/manager/payroll');
        }

        // Stage 7: the whole profile (rates, absence mode, work days / hours,
        // benefits, fixed deductions) goes through PayrollService, which is the
        // single writer of attendance_payroll_profiles and audits the change.
        $input = [
            'pay_type'            => (string) $request->input('pay_type', 'monthly'),
            'base_amount'         => Input::money($request->input('base_amount', 0)),
            'hourly_rate'         => Input::money($request->input('hourly_rate', 0)),
            'overtime_rate'       => Input::money($request->input('overtime_rate', 0)),
            'deficit_rate'        => Input::money($request->input('deficit_rate', 0)),
            'penalty_amount'      => Input::money($request->input('penalty_amount', 0)),
            'benefits_amount'     => Input::money($request->input('benefits_amount', 0)),
            'fixed_deductions'    => Input::money($request->input('fixed_deductions', 0)),
            'late_rate'           => Input::money($request->input('late_rate', 0)),
            'early_leave_rate'    => Input::money($request->input('early_leave_rate', 0)),
            'absence_rate'        => Input::money($request->input('absence_rate', 0)),
            'absence_mode'        => (string) $request->input('absence_mode', 'per_day'),
            'overtime_multiplier' => (string) $request->input('overtime_multiplier', '1.5'),
            'work_days_per_month' => (string) $request->input('work_days_per_month', '30'),
            'work_hours_per_day'  => (string) $request->input('work_hours_per_day', '8'),
            'currency'            => (string) $request->input('currency', 'IRT'),
            'job_title'           => (string) $request->input('job_title', ''),
            'notes'               => (string) $request->input('notes', ''),
        ];

        $result = (new PayrollService())->saveProfile($this->actor($request), $customerId, $employeeId, $input);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['message']);

            return Redirect::response('/manager/payroll');
        }

        Session::flash('success', 'پروفایل حقوقی «' . Employee::fullName($employee) . '» ذخیره شد (نسخه محاسبه ' . PayrollCalculator::VERSION . ').');

        return Redirect::response('/manager/payroll');
    }

    /**
     * Customer level work rules used later by the payroll / attendance engine.
     */
    public function rules(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        if (($denied = Capability::check($request, 'payroll.manage')) !== null) {
            return $denied;
        }

        $changed = CustomerSetting::putMany($customerId, [
            'currency'             => Input::choice($request->input('currency', ''), ['IRT' => 'IRT', 'IRR' => 'IRR'], 'IRT'),
            'late_grace_minutes'   => (string) (Input::int($request->input('late_grace_minutes', 0), 0, 600) ?? 0),
            'early_grace_minutes'  => (string) (Input::int($request->input('early_grace_minutes', 0), 0, 600) ?? 0),
        ], (int) (Session::userId() ?? 0));

        AuditLog::record('payroll.rules.update', 'success', 'قواعد کارکرد و حقوق مشتری به‌روزرسانی شد.', [
            'changed_keys' => $changed,
        ], 'app', 'payroll', 0, $customerId);

        Session::flash('success', 'قواعد کارکرد ذخیره شد.');

        return Redirect::response('/manager/payroll');
    }
}
