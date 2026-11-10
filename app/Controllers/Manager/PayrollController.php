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
use App\Services\PayrollExportService;
use App\Services\PayrollService;
use App\Support\Capability;
use App\Support\Input;
use App\Support\Jalali;

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

        /* Stage 23: the SSO insurance list of the SELECTED period. It is built
           only when a period is open on the page - for a finalized period from
           the frozen rows, for an open one from the live calculation that the
           page already shows - so the preview and the downloaded diskette are
           always the same numbers. */
        $exporter  = new PayrollExportService();
        $insurance = $period === null
            ? [
                'ok'      => false,
                'code'    => PayrollExportService::CODE_PERIOD_NOT_FOUND,
                'message' => 'برای پیش‌نمایش لیست حق بیمه، ابتدا یک دوره حقوق را انتخاب کنید.',
                'data'    => [],
            ]
            : $exporter->insuranceRows($customerId, $periodId);

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
            /* Stage 23: insurance diskette + bank payment file. */
            'insurance'         => $insurance,
            'insuranceSettings' => $exporter->settings($customerId),
            'listKinds'         => PayrollExportService::LIST_KINDS,
            'bankColumns'       => PayrollExportService::BANK_COLUMNS,
            'identityColumns'   => $this->identityColumns(),
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
     * The stage 23 identity / bank fields of a profile: field => [label, hint,
     * kind]. They are rendered inside the payroll profile form, because they
     * belong to the payroll contract of one person (the SSO diskette and the
     * bank file read them) and not to the personnel record.
     *
     * @return array<string,array{label:string,hint:string,kind:string,options?:array<string,string>}>
     */
    private function identityColumns(): array
    {
        return [
            'insurance_no'       => ['label' => 'شماره بیمه‌شده', 'hint' => 'DSW_ID1 - فقط رقم', 'kind' => 'digits'],
            'insurance_job_code' => ['label' => 'کد شغل بیمه', 'hint' => 'DSW_JOB - فقط رقم', 'kind' => 'digits'],
            'father_name'        => ['label' => 'نام پدر', 'hint' => 'DSW_DNAME', 'kind' => 'text'],
            'birth_date'         => ['label' => 'تاریخ تولد', 'hint' => 'DSW_BDATE', 'kind' => 'date'],
            'id_number'          => ['label' => 'شماره شناسنامه', 'hint' => 'خالی = کد ملی پرسنل', 'kind' => 'digits'],
            'id_place'           => ['label' => 'محل صدور', 'hint' => 'DSW_IDPLC', 'kind' => 'text'],
            'id_issue_date'      => ['label' => 'تاریخ صدور شناسنامه', 'hint' => 'DSW_IDATE', 'kind' => 'date'],
            'gender'             => ['label' => 'جنسیت', 'hint' => 'DSW_SEX', 'kind' => 'choice', 'options' => ['male' => 'مرد', 'female' => 'زن']],
            'nationality'        => ['label' => 'ملیت', 'hint' => 'خالی = ایرانی', 'kind' => 'text'],
            'insurance_end_date' => ['label' => 'تاریخ ترک کار', 'hint' => 'DSW_EDATE - فقط در ماه خروج', 'kind' => 'date'],
            'bank_name'          => ['label' => 'نام بانک', 'hint' => 'خالی = بانک پیش‌فرض مجموعه', 'kind' => 'text'],
            'iban'               => ['label' => 'شماره شبا (IBAN)', 'hint' => 'IR… - ۲۶ نویسه', 'kind' => 'iban'],
            'account_number'     => ['label' => 'شماره حساب', 'hint' => 'اختیاری', 'kind' => 'digits'],
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
            /* Stage 23: the identity data of the SSO insurance diskette and the
               bank account of the payment file. They belong to the payroll
               contract of this person, not to the personnel record, and
               PayrollService drops them when the database has not been migrated
               yet - a profile save can never fail because of them. */
            'insurance_no'        => (string) $request->input('insurance_no', ''),
            'insurance_job_code'  => (string) $request->input('insurance_job_code', ''),
            'father_name'         => (string) $request->input('father_name', ''),
            'birth_date'          => (string) $request->input('birth_date', ''),
            'id_number'           => (string) $request->input('id_number', ''),
            'id_place'            => (string) $request->input('id_place', ''),
            'id_issue_date'       => (string) $request->input('id_issue_date', ''),
            'gender'              => (string) $request->input('gender', ''),
            'nationality'         => (string) $request->input('nationality', ''),
            'iban'                => (string) $request->input('iban', ''),
            'bank_name'           => (string) $request->input('bank_name', ''),
            'account_number'      => (string) $request->input('account_number', ''),
            'insurance_end_date'  => (string) $request->input('insurance_end_date', ''),
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

    /**
     * POST /manager/payroll/insurance - the workshop data of the SSO insurance
     * list (workshop code, employer, address, list kind/number, contribution
     * rates) plus the defaults of the bank payment file.
     *
     * Every value is a customer setting, so it survives a payroll period and is
     * reused for the next month. The rates are percentages of the ماخذ
     * (DSW_MASH) and are shown on the export page, so nobody has to guess which
     * rate produced a number.
     */
    public function insurance(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        if (($denied = Capability::check($request, 'payroll.manage')) !== null) {
            return $denied;
        }

        $rate = static function ($value, float $default): string {
            $number = Input::money($value);

            if ($number < 0 || $number > 100) {
                $number = $default;
            }

            return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        };

        $changed = CustomerSetting::putMany($customerId, [
            // DSK_ID / DSK_NAME / DSK_FARM / DSK_ADRS
            'insurance_workshop_code'    => preg_replace('/\D/', '', (string) $request->input('insurance_workshop_code', '')) ?? '',
            'insurance_workshop_name'    => Input::text((string) $request->input('insurance_workshop_name', ''), 60),
            'insurance_employer_name'    => Input::text((string) $request->input('insurance_employer_name', ''), 60),
            'insurance_workshop_address' => Input::text((string) $request->input('insurance_workshop_address', ''), 120),
            // DSK_KIND: 1 monthly, 2 periodic, 3 changes
            'insurance_list_kind'        => (string) Input::choice($request->input('insurance_list_kind', '1'), \App\Services\PayrollExportService::LIST_KINDS, '1'),
            'insurance_list_no'          => Input::text((string) $request->input('insurance_list_no', ''), 12),
            'insurance_contract_row'     => Input::text((string) $request->input('insurance_contract_row', '000'), 3),
            'insurance_description'      => Input::text((string) $request->input('insurance_description', ''), 60),
            // DSW_BIME (7%), DSK_TKOSO (20%), DSK_BIC (3%) - DSK_RATE is the sum
            'insurance_employee_rate'     => $rate($request->input('insurance_employee_rate', ''), \App\Services\PayrollExportService::DEFAULT_EMPLOYEE_RATE),
            'insurance_employer_rate'     => $rate($request->input('insurance_employer_rate', ''), \App\Services\PayrollExportService::DEFAULT_EMPLOYER_RATE),
            'insurance_unemployment_rate' => $rate($request->input('insurance_unemployment_rate', ''), \App\Services\PayrollExportService::DEFAULT_UNEMPLOYMENT_RATE),
            // The yearly ceiling of the insured wage; 0 = no clamp
            'insurance_max_mash'         => (string) Input::money($request->input('insurance_max_mash', 0)),
            'insurance_period_rate'      => (string) Input::money($request->input('insurance_period_rate', 0)),
            // Bank payment file
            'bank_name'                  => Input::text((string) $request->input('bank_name', ''), 60),
            'bank_payment_note'          => Input::text((string) $request->input('bank_payment_note', ''), 60),
        ], (int) (Session::userId() ?? 0));

        AuditLog::record('payroll.insurance.update', 'success', 'اطلاعات بیمه و بانک مجموعه به‌روزرسانی شد.', [
            'changed_keys' => $changed,
        ], 'app', 'payroll', 0, $customerId);

        Session::flash('success', $changed === []
            ? 'اطلاعات بیمه و بانک تغییری نکرد.'
            : 'اطلاعات بیمه و بانک ذخیره شد (' . count($changed) . ' مورد).');

        return Redirect::response('/manager/payroll?period_id=' . (int) $request->int('period_id', 0) . '#insurance');
    }

    /* ==================================================================
     * Stage 23 downloads
     *
     * All three actions follow the report export pattern: feature gate,
     * capability check, build the bytes in a service, audit the file (never its
     * content) and stream it with a download disposition and no-store caching.
     * ==================================================================*/

    /**
     * GET /manager/payroll/diskette - the SSO insurance diskette.
     *
     * `file=zip` returns all four files (DSKKAR00/DSKWOR00 as .dbf and .txt),
     * `file=kar|wor` returns one TEXT file, `file=kar-dbf|wor-dbf` one dBASE
     * file. `encoding=windows-1256` (default) or `utf-8`.
     */
    public function diskette(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        if (($denied = Capability::check($request, 'payroll.view')) !== null) {
            return $denied;
        }

        $periodId = (int) $request->int('period_id', 0);
        $exporter = new PayrollExportService();
        $encoding = strtolower((string) $request->input('encoding', 'windows-1256')) === 'utf-8' ? 'utf-8' : 'windows-1256';
        $which    = strtolower((string) $request->input('file', 'zip'));

        if ($which !== 'zip') {
            $result = $exporter->buildDiskette($customerId, $periodId, $encoding);

            if (!($result['ok'] ?? false)) {
                return $this->exportFailed($request, $result, $periodId);
            }

            $map = [
                'kar'     => PayrollExportService::KAR_BASENAME . '.txt',
                'wor'     => PayrollExportService::WOR_BASENAME . '.txt',
                'kar-dbf' => PayrollExportService::KAR_BASENAME . '.dbf',
                'wor-dbf' => PayrollExportService::WOR_BASENAME . '.dbf',
            ];

            if (!isset($map[$which])) {
                return $this->exportFailed($request, [
                    'ok'      => false,
                    'code'    => PayrollExportService::CODE_NO_ROWS,
                    'message' => 'نوع فایل نامعتبر است (zip، kar، wor، kar-dbf یا wor-dbf).',
                ], $periodId);
            }

            $name  = $map[$which];
            $body  = (string) $result['data']['files'][$name];
            $isTxt = substr($name, -4) === '.txt';

            $exporter->audit('payroll.diskette.download', $this->actor($request), $customerId, $periodId, [
                'message'  => 'فایل دیسکت بیمه «' . $name . '» دریافت شد.',
                'file'     => $name,
                'encoding' => $encoding,
                'rows'     => (int) $result['data']['control']['count'],
                'bytes'    => strlen($body),
            ]);

            // The TEXT variant is served with the codepage it was written in, so
            // a browser that opens it straight from the download shows Persian
            // instead of mojibake; the dBASE file is binary.
            return $this->download($body, $name, $isTxt
                ? 'text/plain; charset=' . ($encoding === 'utf-8' ? 'UTF-8' : 'windows-1256')
                : 'application/x-dbf');
        }

        $result = $exporter->buildDisketteZip($customerId, $periodId, $encoding);

        if (!($result['ok'] ?? false)) {
            return $this->exportFailed($request, $result, $periodId);
        }

        $filename = $this->disketteFilename($result['data']);

        $exporter->audit('payroll.diskette.download', $this->actor($request), $customerId, $periodId, [
            'message'  => 'فایل دیسکت بیمه (ZIP) دریافت شد.',
            'file'     => $filename,
            'encoding' => $encoding,
            'rows'     => (int) $result['data']['control']['count'],
            'bytes'    => (int) $result['data']['bytes'],
        ]);

        return $this->download((string) $result['data']['body'], $filename, 'application/zip');
    }

    /**
     * GET /manager/payroll/bank-file - the net salary payment file.
     *
     * `format=xlsx` (default) or `csv`. The IBAN is a text cell in both formats.
     */
    public function bankFile(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('payroll', $customerId);

        if (($denied = Capability::check($request, 'payroll.view')) !== null) {
            return $denied;
        }

        $periodId = (int) $request->int('period_id', 0);
        $exporter = new PayrollExportService();
        $csv      = strtolower((string) $request->input('format', 'xlsx')) === 'csv';

        $result = $csv
            ? $exporter->buildBankCsv($customerId, $periodId)
            : $exporter->buildBankXlsx($customerId, $periodId);

        if (!($result['ok'] ?? false)) {
            return $this->exportFailed($request, $result, $periodId);
        }

        $filename = 'bank-payments-' . $this->periodStamp($result['data']['period']) . ($csv ? '.csv' : '.xlsx');
        $body     = (string) $result['data']['body'];

        $exporter->audit('payroll.bank.download', $this->actor($request), $customerId, $periodId, [
            'message' => 'فایل پرداخت بانک (' . ($csv ? 'CSV' : 'Excel') . ') دریافت شد.',
            'file'    => $filename,
            'rows'    => (int) $result['data']['count'],
            'total'   => (int) $result['data']['total'],
            'bytes'   => strlen($body),
        ]);

        return $this->download($body, $filename, $csv ? 'text/csv; charset=UTF-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /* ==================================================================
     * Download plumbing
     * ==================================================================*/

    private function download(string $body, string $filename, string $contentType): Response
    {
        return Response::make($body, 200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * An export that could not be built is reported on the payroll page, not as
     * a broken download: the manager has to see WHICH field is missing.
     *
     * @param array<string,mixed> $result
     */
    private function exportFailed(Request $request, array $result, int $periodId): Response
    {
        $message = (string) ($result['message'] ?? 'ساخت فایل ناموفق بود.');

        AuditLog::record('payroll.export.failed', 'warning', $message, [
            'code'      => (string) ($result['code'] ?? ''),
            'period_id' => $periodId,
        ], 'payroll', 'payroll_period', $periodId, TenantContext::requireCustomerId());

        Session::flash('error', $message);

        return Redirect::response('/manager/payroll?period_id=' . $periodId . '#insurance');
    }

    private function disketteFilename(array $data): string
    {
        return 'tamin-diskette-' . $this->periodStamp($data['period'] ?? []) . '.zip';
    }

    /**
     * A file name stamp of a period: the Jalali year and month when the period
     * starts inside one month (the normal case for an insurance list), the
     * Gregorian range otherwise. Never a character a browser would escape.
     *
     * @param array<string,mixed> $period
     */
    private function periodStamp(array $period): string
    {
        $from = (string) ($period['start_date'] ?? '');
        $to   = (string) ($period['end_date'] ?? '');

        if ($from === '') {
            return date('Ymd');
        }

        $jy     = 0;
        $jm     = 0;
        $jalali = Jalali::date($from);

        if (preg_match('#^(\d{4})/(\d{2})/(\d{2})$#', $jalali, $match) === 1) {
            $jy = (int) $match[1];
            $jm = (int) $match[2];
        }

        if ($jy > 0 && $to !== '' && substr(Jalali::date($to), 0, 7) === substr($jalali, 0, 7)) {
            return sprintf('%04d-%02d', $jy, $jm);
        }

        return str_replace('-', '', $from) . '-' . str_replace('-', '', $to);
    }
}
