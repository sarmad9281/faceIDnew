<?php
/**
 * SARMAD Attendance - stage 23 payroll exports.
 *
 * Three official files come out of a payroll period, all built from the SAME
 * numbers the panel already shows (a finalized period is read from the frozen
 * attendance_payroll_items rows, an open one is calculated live - never a
 * second formula):
 *
 *   1) THE SOCIAL SECURITY DISKETTE ("دیسکت لیست حق بیمه تأمین اجتماعی")
 *      DSKKAR00 = one control row per workshop/month (project 51)
 *      DSKWOR00 = one row per insured person (project 52)
 *      Both are written twice: as a dBASE III .dbf (what the List Disk program
 *      imports) and as the officially allowed comma separated TEXT variant of
 *      the very same layout, so a workshop without a floppy drive can upload
 *      the text file. Column order and widths follow the SSO specification
 *      exactly - see DSKKAR_FIELDS / DSKWOR_FIELDS below and docs/STAGE23.md.
 *
 *   2) THE BANK PAYMENT FILE: one row per employee with the IBAN and the net
 *      amount in rials, as .xlsx or as a UTF-8-BOM .csv that every Iranian
 *      corporate banking portal accepts.
 *
 * Numbers are ALWAYS Latin digits and ALWAYS whole rials: a profile kept in
 * Toman (IRT) is multiplied by ten, so the diskette never carries a "1000"
 * that the SSO system would read as one thousand rials.
 *
 * Privacy: the IBAN, the national code and the identity document data are read
 * from this customer's rows only (every query is scoped by customer_id) and are
 * never written to a log. Audit entries record the file name and the row count,
 * not the content.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AuditLog;
use App\Models\CustomerSetting;
use App\Support\Dbf;
use App\Support\Encoding;
use App\Support\Jalali;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class PayrollExportService
{
    public const CODE_OK              = 'EXPORT_OK';
    public const CODE_PERIOD_NOT_FOUND = 'EXPORT_PERIOD_NOT_FOUND';
    public const CODE_NO_ROWS         = 'EXPORT_NO_ROWS';
    public const CODE_NO_WORKSHOP     = 'EXPORT_NO_WORKSHOP';
    public const CODE_NO_ZIP          = 'EXPORT_NO_ZIP';
    public const CODE_NO_EXCEL        = 'EXPORT_NO_EXCEL';

    /** File names the SSO List Disk program looks for. */
    public const KAR_BASENAME = 'DSKKAR00';
    public const WOR_BASENAME = 'DSKWOR00';

    /**
     * DSK_KIND - نوع لیست. 1 = the normal monthly list, which is what a
     * payroll period produces; 2/3 exist for the SSO program and stay
     * selectable in the workshop settings.
     */
    public const LIST_KINDS = [
        1 => 'ماهانه',
        2 => 'دوره‌ای',
        3 => 'تغییرات',
    ];

    /**
     * DSKKAR00.DBF - پایگاه اطلاعات دیسکت ماهانه کارگاه (project 51).
     * One single row per workshop and month: the control totals.
     *
     * @var array<int,array{name:string,type:string,length:int}>
     */
    public const DSKKAR_FIELDS = [
        ['name' => 'DSK_ID',     'type' => 'C', 'length' => 10], // شماره کارگاه
        ['name' => 'DSK_NAME',   'type' => 'C', 'length' => 30], // نام کارگاه
        ['name' => 'DSK_FARM',   'type' => 'C', 'length' => 30], // نام کارفرما
        ['name' => 'DSK_ADRS',   'type' => 'C', 'length' => 40], // نشانی
        ['name' => 'DSK_KIND',   'type' => 'N', 'length' => 1],  // نوع لیست
        ['name' => 'DSK_YY',     'type' => 'N', 'length' => 2],  // سال (۲ رقمی)
        ['name' => 'DSK_MM',     'type' => 'N', 'length' => 2],  // ماه
        ['name' => 'DSK_LISTNO', 'type' => 'C', 'length' => 12], // شماره لیست
        ['name' => 'DSK_DISC',   'type' => 'C', 'length' => 30], // شرح لیست
        ['name' => 'DSK_NUM',    'type' => 'N', 'length' => 5],  // تعداد نفرات
        ['name' => 'DSK_TDD',    'type' => 'N', 'length' => 6],  // جمع روزهای کارکرد
        ['name' => 'DSK_TROOZ',  'type' => 'N', 'length' => 12], // جمع دستمزد روزانه
        ['name' => 'DSK_TMAH',   'type' => 'N', 'length' => 12], // جمع دستمزد ماهانه
        ['name' => 'DSK_TMAZ',   'type' => 'N', 'length' => 12], // جمع مزایای مشمول
        ['name' => 'DSK_TMASH',  'type' => 'N', 'length' => 12], // جمع ماخذ
        ['name' => 'DSK_TTOTL',  'type' => 'N', 'length' => 12], // مشمول و غیرمشمول
        ['name' => 'DSK_TBIME',  'type' => 'N', 'length' => 12], // سهم بیمه‌شده
        ['name' => 'DSK_TKOSO',  'type' => 'N', 'length' => 12], // سهم کارفرما
        ['name' => 'DSK_BIC',    'type' => 'N', 'length' => 12], // بیمه بیکاری
        ['name' => 'DSK_RATE',   'type' => 'N', 'length' => 5],  // نرخ حق بیمه
        ['name' => 'DSK_PRATE',  'type' => 'N', 'length' => 2],  // نرخ پورسانتاژ
        ['name' => 'DSK_BIMH',   'type' => 'N', 'length' => 12], // حق بیمه مشاغل
        ['name' => 'MON_PYM',    'type' => 'C', 'length' => 3],  // ردیف پیمان
    ];

    /**
     * DSKWOR00.DBF - ریز کارکرد ماهانه کارگاه (project 52).
     * One row per insured person, never two rows for the same person.
     *
     * @var array<int,array{name:string,type:string,length:int}>
     */
    public const DSKWOR_FIELDS = [
        ['name' => 'DSW_ID',     'type' => 'C', 'length' => 10], // شماره کارگاه
        ['name' => 'DSW_YY',     'type' => 'N', 'length' => 2],  // سال
        ['name' => 'DSW_MM',     'type' => 'N', 'length' => 2],  // ماه
        ['name' => 'DSW_LISTNO', 'type' => 'C', 'length' => 12], // شماره لیست
        ['name' => 'DSW_ID1',    'type' => 'C', 'length' => 8],  // شماره بیمه‌شده
        ['name' => 'DSW_FNAME',  'type' => 'C', 'length' => 50], // نام
        ['name' => 'DSW_LNAME',  'type' => 'C', 'length' => 50], // نام خانوادگی
        ['name' => 'DSW_DNAME',  'type' => 'C', 'length' => 50], // نام پدر
        ['name' => 'DSW_IDNO',   'type' => 'C', 'length' => 15], // شماره شناسنامه
        ['name' => 'DSW_IDPLC',  'type' => 'C', 'length' => 30], // محل صدور
        ['name' => 'DSW_IDATE',  'type' => 'C', 'length' => 8],  // تاریخ صدور
        ['name' => 'DSW_BDATE',  'type' => 'C', 'length' => 8],  // تاریخ تولد
        ['name' => 'DSW_SEX',    'type' => 'C', 'length' => 3],  // جنسیت
        ['name' => 'DSW_NAT',    'type' => 'C', 'length' => 10], // ملیت
        ['name' => 'DSW_OCP',    'type' => 'C', 'length' => 50], // شرح شغل
        ['name' => 'DSW_SDATE',  'type' => 'C', 'length' => 8],  // شروع بکار
        ['name' => 'DSW_EDATE',  'type' => 'C', 'length' => 8],  // ترک کار
        ['name' => 'DSW_DD',     'type' => 'N', 'length' => 2],  // روزهای کارکرد
        ['name' => 'DSW_ROOZ',   'type' => 'N', 'length' => 12], // دستمزد روزانه
        ['name' => 'DSW_MAH',    'type' => 'N', 'length' => 12], // دستمزد ماهانه
        ['name' => 'DSW_MAZ',    'type' => 'N', 'length' => 12], // مزایای مشمول
        ['name' => 'DSW_MASH',   'type' => 'N', 'length' => 12], // ماخذ
        ['name' => 'DSW_TOTL',   'type' => 'N', 'length' => 12], // مشمول و غیرمشمول
        ['name' => 'DSW_BIME',   'type' => 'N', 'length' => 12], // سهم بیمه‌شده
        ['name' => 'DSW_PRATE',  'type' => 'N', 'length' => 2],  // نرخ پورسانتاژ
        ['name' => 'DSW_JOB',    'type' => 'C', 'length' => 6],  // کد شغل
        ['name' => 'PER_NATCOD', 'type' => 'C', 'length' => 10], // کد ملی
    ];

    /**
     * The bank payment file. The IBAN is a text column on purpose: Excel would
     * otherwise turn "IR06..." into a number or into scientific notation and
     * the bank portal would reject the file.
     *
     * @var array<int,array{key:string,label:string,type:string,width:int}>
     */
    public const BANK_COLUMNS = [
        ['key' => 'row',           'label' => 'ردیف',                'type' => 'int',    'width' => 7],
        ['key' => 'employee_name', 'label' => 'نام و نام خانوادگی',  'type' => 'string', 'width' => 30],
        ['key' => 'employee_code', 'label' => 'کد پرسنلی',           'type' => 'string', 'width' => 14],
        ['key' => 'national_id',   'label' => 'کد ملی',              'type' => 'string', 'width' => 14],
        ['key' => 'bank_name',     'label' => 'نام بانک',            'type' => 'string', 'width' => 20],
        ['key' => 'iban',          'label' => 'شماره شبا',           'type' => 'string', 'width' => 30],
        ['key' => 'account_number', 'label' => 'شماره حساب',         'type' => 'string', 'width' => 22],
        ['key' => 'net_amount',    'label' => 'مبلغ خالص (ریال)',    'type' => 'int',    'width' => 20],
        ['key' => 'note',          'label' => 'شرح',                 'type' => 'string', 'width' => 34],
    ];

    /**
     * Default contribution rates, in percent of the "ماخذ" (DSW_MASH).
     * The SSO guide states 27% (employer 20% + insured 7%) with the 3%
     * unemployment insurance ("بیمه بیکاری") added on the employer side, so a
     * full private-sector list is 30%. Every value is editable per customer in
     * the payroll panel, and DSK_RATE always reports the sum that was used.
     */
    public const DEFAULT_EMPLOYEE_RATE     = 7.0;
    public const DEFAULT_EMPLOYER_RATE     = 20.0;
    public const DEFAULT_UNEMPLOYMENT_RATE = 3.0;

    private PayrollService $payroll;

    public function __construct(?PayrollService $payroll = null)
    {
        $this->payroll = $payroll ?? new PayrollService();
    }

    /* ==================================================================
     * Workshop settings
     * ==================================================================*/

    /**
     * The insurance / bank settings of one customer, with the defaults taken
     * from the customer profile. Nothing is invented: an empty workshop code
     * stays empty and the exporter then refuses to build a diskette, because a
     * list without a workshop number is rejected by the SSO program.
     *
     * @return array<string,mixed>
     */
    public function settings(int $customerId): array
    {
        $settings = CustomerSetting::all($customerId);

        $customer = Database::selectOne(
            'SELECT `name`, `company_name`, `address`, `contact_phone` FROM `' . Database::table('customers') . '`'
            . ' WHERE `id` = ?',
            [$customerId]
        ) ?? [];

        $workshopName = $this->settingText($settings, 'insurance_workshop_name');
        $employerName = $this->settingText($settings, 'insurance_employer_name');
        $address      = $this->settingText($settings, 'insurance_workshop_address');

        if ($workshopName === '') {
            $workshopName = $this->firstText($customer['company_name'] ?? '', $customer['name'] ?? '');
        }

        if ($employerName === '') {
            $employerName = $workshopName;
        }

        if ($address === '') {
            $address = (string) ($customer['address'] ?? '');
        }

        $employeeRate     = $this->settingNumber($settings, 'insurance_employee_rate', self::DEFAULT_EMPLOYEE_RATE);
        $employerRate     = $this->settingNumber($settings, 'insurance_employer_rate', self::DEFAULT_EMPLOYER_RATE);
        $unemploymentRate = $this->settingNumber($settings, 'insurance_unemployment_rate', self::DEFAULT_UNEMPLOYMENT_RATE);

        $listKind = (int) $this->settingNumber($settings, 'insurance_list_kind', 1);
        $listKind = isset(self::LIST_KINDS[$listKind]) ? $listKind : 1;

        $listNo = $this->settingText($settings, 'insurance_list_no');

        if ($listNo === '') {
            // The SSO list number is unique per workshop/month; a sequential
            // number derived from the Jalali period is a sane default that the
            // manager can overwrite.
            $listNo = '';
        }

        return [
            'workshop_code'    => $this->settingText($settings, 'insurance_workshop_code'),
            'workshop_name'    => $workshopName,
            'workshop_address' => $address,
            'employer_name'    => $employerName,
            'list_kind'        => $listKind,
            'list_kind_label'  => self::LIST_KINDS[$listKind],
            'list_no'          => $listNo,
            'contract_row'     => $this->orDefault($this->settingText($settings, 'insurance_contract_row'), '000'),
            'employee_rate'    => $employeeRate,
            'employer_rate'    => $employerRate,
            'unemployment_rate' => $unemploymentRate,
            // DSK_RATE is the sum of both shares INCLUDING unemployment, which
            // is how the SSO program validates the list (7 + 20 + 3 = 30).
            'total_rate'       => $employeeRate + $employerRate + $unemploymentRate,
            'max_mash'         => $this->settingNumber($settings, 'insurance_max_mash', 0),
            'period_rate'      => $this->settingNumber($settings, 'insurance_period_rate', 0),
            'description'      => $this->settingText($settings, 'insurance_description'),
            'bank_name'        => $this->settingText($settings, 'bank_name'),
            'bank_note'        => $this->settingText($settings, 'bank_payment_note'),
        ];
    }

    /* ==================================================================
     * Insurance rows
     * ==================================================================*/

    /**
     * Builds the DSKWOR00 rows (one per person) and the DSKKAR00 control row
     * (exactly one) for a payroll period.
     *
     * @return array<string,mixed>
     */
    public function insuranceRows(int $customerId, int $periodId): array
    {
        $period = $this->payroll->period($customerId, $periodId);

        if ($period === null) {
            return $this->fail(self::CODE_PERIOD_NOT_FOUND, 'دوره حقوق یافت نشد.');
        }

        $settings = $this->settings($customerId);
        $warnings = [];

        if (trim($settings['workshop_code']) === '') {
            return $this->fail(
                self::CODE_NO_WORKSHOP,
                'شماره کارگاه تأمین اجتماعی ثبت نشده است؛ ابتدا در بخش «اطلاعات بیمه و بانک» آن را وارد کنید.',
                ['period' => $period, 'settings' => $settings]
            );
        }

        [$payrollRows, $finalized] = $this->payrollRowsFor($customerId, $period);

        if ($payrollRows === []) {
            return $this->fail(
                self::CODE_NO_ROWS,
                'برای این دوره هیچ پرسنلی با پروفایل حقوقی وجود ندارد.',
                ['period' => $period, 'settings' => $settings]
            );
        }

        $profiles = $this->profilesByEmployee($customerId);

        $from = (string) $period['start_date'];
        $to   = (string) $period['end_date'];

        [$jy, $jm, $jd] = $this->jalaliOf($from);
        $monthLength = $this->jalaliMonthLength($jy, $jm, $from);

        if ($this->jalaliOf($to)[1] !== $jm || $this->jalaliOf($to)[0] !== $jy) {
            $warnings[] = 'این دوره بیشتر از یک ماه شمسی را پوشش می‌دهد؛ لیست حق بیمه باید برای هر ماه جداگانه ارسال شود.';
        }

        $listNo = trim($settings['list_no']) !== ''
            ? $settings['list_no']
            : sprintf('%02d%02d', $jy % 100, $jm);

        $rows = [];
        $control = [
            'count' => 0, 'dd' => 0, 'rooz' => 0, 'mah' => 0, 'maz' => 0,
            'mash' => 0, 'totl' => 0, 'bime' => 0, 'kosor' => 0, 'bic' => 0,
        ];

        foreach ($payrollRows as $item) {
            $employeeId = (int) ($item['employee_id'] ?? 0);
            $profile    = $profiles[$employeeId] ?? [];

            $currency   = strtoupper((string) ($item['currency'] ?? 'IRR'));
            $payType    = (string) ($item['pay_type'] ?? 'monthly');

            $present = (int) ($item['present_days'] ?? 0);
            $leave   = (int) ($item['leave_days'] ?? 0);
            $holiday = (int) ($item['holiday_days'] ?? 0);

            /* DSW_DD - روزهای کارکرد. The SSO guide counts the paid days of the
               month, so approved leave and holidays are part of it, and the
               value can never exceed the length of the Jalali month (a period
               that overlaps the next month would otherwise produce 45 days). */
            $dd = max(0, min($monthLength, $present + $leave + $holiday));

            $dailyRate = $this->toRials($this->dailyRateFor($customerId, $item, $profile, $finalized), $currency);

            /* DSW_MAH - دستمزد ماهانه. A monthly salary is written as is (the
               SSO program does not require a daily rate for حقوق‌بگیر); a daily
               or hourly worker is paid days x daily rate. */
            $mah = $payType === 'monthly'
                ? $this->toRials($item['base_amount'] ?? 0, $currency)
                : (int) round($dd * $dailyRate);

            $maz  = $this->toRials($item['benefits'] ?? 0, $currency);
            $mash = max(0, $mah + $maz);

            if ($settings['max_mash'] > 0) {
                $mash = min($mash, (int) round($settings['max_mash']));
            }

            $bime  = (int) round($mash * $settings['employee_rate'] / 100);
            $kosor = (int) round($mash * $settings['employer_rate'] / 100);
            $bic   = (int) round($mash * $settings['unemployment_rate'] / 100);

            $occupation = $this->firstText(
                (string) ($profile['job_title'] ?? ''),
                (string) ($item['position'] ?? ''),
                (string) ($profile['position'] ?? '')
            );

            $rows[] = [
                'DSW_ID'     => $settings['workshop_code'],
                'DSW_YY'     => $jy % 100,
                'DSW_MM'     => $jm,
                'DSW_LISTNO' => $listNo,
                'DSW_ID1'    => $this->digits((string) ($profile['insurance_no'] ?? '')),
                'DSW_FNAME'  => (string) ($item['first_name'] ?? ''),
                'DSW_LNAME'  => (string) ($item['last_name'] ?? ''),
                'DSW_DNAME'  => (string) ($profile['father_name'] ?? ''),
                'DSW_IDNO'   => $this->digits($this->firstText((string) ($profile['id_number'] ?? ''), (string) ($item['national_id'] ?? ''))),
                'DSW_IDPLC'  => (string) ($profile['id_place'] ?? ''),
                'DSW_IDATE'  => $this->compactDate($profile['id_issue_date'] ?? null),
                'DSW_BDATE'  => $this->compactDate($profile['birth_date'] ?? null),
                'DSW_SEX'    => $this->sexLabel($profile['gender'] ?? null),
                'DSW_NAT'    => $this->orDefault((string) ($profile['nationality'] ?? ''), 'ایرانی'),
                'DSW_OCP'    => $occupation,
                'DSW_SDATE'  => $this->compactDate($profile['hire_date'] ?? ($item['hire_date'] ?? null)),
                'DSW_EDATE'  => $this->compactDate($profile['insurance_end_date'] ?? null),
                'DSW_DD'     => $dd,
                'DSW_ROOZ'   => $payType === 'monthly' ? 0 : (int) round($dailyRate),
                'DSW_MAH'    => $mah,
                'DSW_MAZ'    => $maz,
                'DSW_MASH'   => $mash,
                'DSW_TOTL'   => $mash, // مشمول و غیرمشمول: this panel has no non-insured pay element
                'DSW_BIME'   => $bime,
                'DSW_PRATE'  => (int) round($settings['period_rate']),
                'DSW_JOB'    => $this->digits((string) ($profile['insurance_job_code'] ?? '')),
                'PER_NATCOD' => $this->digits((string) ($item['national_id'] ?? '')),
                // Not part of the SSO layout - kept for the on-screen preview.
                '_employee_id'   => $employeeId,
                '_employee_name' => trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? '')),
                '_employee_code' => (string) ($item['employee_code'] ?? ''),
                '_pay_type'      => $payType,
                '_present_days'  => $present,
                '_kosor'         => $kosor,
                '_bic'           => $bic,
                '_warnings'      => $this->rowWarnings($profile, $item),
            ];

            $control['count']++;
            $control['dd']    += $dd;
            $control['rooz']  += (int) ($rows[count($rows) - 1]['DSW_ROOZ']);
            $control['mah']   += $mah;
            $control['maz']   += $maz;
            $control['mash']  += $mash;
            $control['totl']  += $mash;
            $control['bime']  += $bime;
            $control['kosor'] += $kosor;
            $control['bic']   += $bic;
        }

        $kar = [[
            'DSK_ID'     => $settings['workshop_code'],
            'DSK_NAME'   => $settings['workshop_name'],
            'DSK_FARM'   => $settings['employer_name'],
            'DSK_ADRS'   => $settings['workshop_address'],
            'DSK_KIND'   => $settings['list_kind'],
            'DSK_YY'     => $jy % 100,
            'DSK_MM'     => $jm,
            'DSK_LISTNO' => $listNo,
            'DSK_DISC'   => $this->orDefault($settings['description'], 'لیست حق بیمه ' . Jalali::monthName($jm) . ' ' . $jy),
            'DSK_NUM'    => $control['count'],
            'DSK_TDD'    => $control['dd'],
            'DSK_TROOZ'  => $control['rooz'],
            'DSK_TMAH'   => $control['mah'],
            'DSK_TMAZ'   => $control['maz'],
            'DSK_TMASH'  => $control['mash'],
            'DSK_TTOTL'  => $control['totl'],
            'DSK_TBIME'  => $control['bime'],
            'DSK_TKOSO'  => $control['kosor'],
            'DSK_BIC'    => $control['bic'],
            'DSK_RATE'   => (int) round($settings['total_rate']),
            'DSK_PRATE'  => (int) round($settings['period_rate']),
            'DSK_BIMH'   => 0, // حق بیمه مشاغل (۴٪) - only for the jobs listed by the SSO
            'MON_PYM'    => $settings['contract_row'],
        ]];

        $missing = $this->missingInsuranceFields($rows);

        if ($missing !== []) {
            $warnings[] = 'اطلاعات بیمه ' . count($missing) . ' نفر کامل نیست: '
                . implode('، ', array_slice($missing, 0, 6))
                . (count($missing) > 6 ? ' و ' . (count($missing) - 6) . ' نفر دیگر' : '')
                . ' - فیلدهای خالی در دیسکت، خالی ارسال می‌شوند.';
        }

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => 'لیست حق بیمه آماده است (' . $control['count'] . ' نفر).',
            'data'    => [
                'period'     => $period,
                'settings'   => $settings,
                'finalized'  => $finalized,
                'jalali'     => ['year' => $jy, 'month' => $jm, 'day' => $jd, 'month_length' => $monthLength, 'label' => Jalali::monthName($jm) . ' ' . $jy],
                'list_no'    => $listNo,
                'wor'        => $rows,
                'kar'        => $kar,
                'control'    => $control,
                'warnings'   => $warnings,
                'missing'    => $missing,
            ],
        ];
    }

    /**
     * Renders the four diskette files (DSKKAR00/DSKWOR00 as .txt and .dbf).
     *
     * @return array<string,mixed>
     */
    public function buildDiskette(int $customerId, int $periodId, string $encoding = 'windows-1256'): array
    {
        $result = $this->insuranceRows($customerId, $periodId);

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        $data     = $result['data'];
        $encoding = strtolower(trim($encoding)) === 'utf-8' ? 'utf-8' : 'windows-1256';

        $files = [
            self::KAR_BASENAME . '.txt' => $this->renderText(self::DSKKAR_FIELDS, $data['kar'], $encoding),
            self::WOR_BASENAME . '.txt' => $this->renderText(self::DSKWOR_FIELDS, $data['wor'], $encoding),
            self::KAR_BASENAME . '.dbf' => Dbf::build(self::DSKKAR_FIELDS, $data['kar'], $encoding),
            self::WOR_BASENAME . '.dbf' => Dbf::build(self::DSKWOR_FIELDS, $data['wor'], $encoding),
        ];

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => 'فایل دیسکت بیمه تولید شد.',
            'data'    => $data + [
                'encoding' => $encoding,
                'files'    => $files,
                'bytes'    => array_map('strlen', $files),
            ],
        ];
    }

    /**
     * Packs the four diskette files into one ZIP so a manager can download a
     * single file that matches what the SSO program expects on the diskette.
     *
     * @return array<string,mixed>
     */
    public function buildDisketteZip(int $customerId, int $periodId, string $encoding = 'windows-1256'): array
    {
        if (!class_exists('ZipArchive')) {
            return $this->fail(self::CODE_NO_ZIP, 'امکان ساخت فایل ZIP روی این سرور وجود ندارد؛ فایل‌های txt و dbf را جداگانه دریافت کنید.');
        }

        $result = $this->buildDiskette($customerId, $periodId, $encoding);

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        $zip = new \ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'sarmad-dsk');

        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return $this->fail(self::CODE_NO_ZIP, 'ساخت فایل ZIP ناموفق بود.');
        }

        foreach ($result['data']['files'] as $name => $body) {
            $zip->addFromString((string) $name, (string) $body);
        }

        // A short readme keeps the encoding choice with the files themselves.
        $zip->addFromString('README.txt', Encoding::toWindows1256(
            "لیست حق بیمه تأمین اجتماعی - سامانه حضور و غیاب سرمدا\r\n"
            . "دوره: " . $result['data']['period']['start_date'] . ' تا ' . $result['data']['period']['end_date'] . "\r\n"
            . "کارگاه: " . $result['data']['settings']['workshop_code'] . ' - ' . $result['data']['settings']['workshop_name'] . "\r\n"
            . "تعداد نفرات: " . $result['data']['control']['count'] . "\r\n"
            . "کدگذاری متن و DBF: " . ($result['data']['encoding'] === 'utf-8' ? 'UTF-8' : 'WINDOWS-1256') . "\r\n"
            . "فایل‌ها: DSKKAR00 (کنترلی کارگاه) و DSKWOR00 (ریز کارکرد) به صورت .dbf و .txt\r\n"
        ));

        $zip->close();

        $body = (string) file_get_contents($tmp);
        @unlink($tmp);

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => 'فایل ZIP دیسکت بیمه تولید شد.',
            'data'    => $result['data'] + ['body' => $body, 'bytes' => strlen($body)],
        ];
    }

    /**
     * The TEXT variant of the SSO layout: comma separated, one record per line,
     * CRLF line endings, no header row (the importer reads the columns by
     * position, exactly like the .dbf field order).
     *
     * @param array<int,array{name:string,type:string,length:int}> $fields
     * @param array<int,array<string,mixed>>                       $rows
     */
    public function renderText(array $fields, array $rows, string $encoding = 'windows-1256'): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($fields as $field) {
                $cells[] = $this->renderTextCell($row[$field['name']] ?? '', $field, $encoding);
            }

            $lines[] = implode(',', $cells);
        }

        return implode("\r\n", $lines) . ($lines === [] ? '' : "\r\n");
    }

    /**
     * @param array{name:string,type:string,length:int} $field
     * @param mixed                                     $value
     */
    private function renderTextCell($value, array $field, string $encoding): string
    {
        if ($field['type'] === Dbf::TYPE_NUMBER) {
            if ($value === null || $value === '') {
                return '';
            }

            return (string) (int) round((float) $value);
        }

        $text = Encoding::toLatinDigits(Encoding::sanitize((string) ($value ?? '')));

        // A comma is the record delimiter, so it can never appear inside a
        // field - it becomes an Arabic semicolon, which reads the same.
        $text = str_replace([',', '،'], '؛', $text);

        if (strlen($text) > $field['length']) {
            $text = $encoding === 'utf-8'
                ? (function_exists('mb_substr') ? mb_substr($text, 0, $field['length'], 'UTF-8') : substr($text, 0, $field['length']))
                : Encoding::fromWindows1256(substr(Encoding::toWindows1256($text), 0, $field['length']));
        }

        return $encoding === 'utf-8' ? $text : Encoding::toWindows1256($text);
    }

    /* ==================================================================
     * Bank payment file
     * ==================================================================*/

    /**
     * @return array<string,mixed>
     */
    public function bankRows(int $customerId, int $periodId): array
    {
        $period = $this->payroll->period($customerId, $periodId);

        if ($period === null) {
            return $this->fail(self::CODE_PERIOD_NOT_FOUND, 'دوره حقوق یافت نشد.');
        }

        [$payrollRows, $finalized] = $this->payrollRowsFor($customerId, $period);

        if ($payrollRows === []) {
            return $this->fail(self::CODE_NO_ROWS, 'برای این دوره هیچ پرسنلی با پروفایل حقوقی وجود ندارد.');
        }

        $profiles = $this->profilesByEmployee($customerId);
        $settings = $this->settings($customerId);

        $rows     = [];
        $warnings = [];
        $index    = 0;
        $total    = 0;

        foreach ($payrollRows as $item) {
            $employeeId = (int) ($item['employee_id'] ?? 0);
            $profile    = $profiles[$employeeId] ?? [];

            $iban = $this->normaliseIban((string) ($profile['iban'] ?? ''));
            $net  = (int) round($this->toRials($item['net_amount'] ?? 0, (string) ($item['currency'] ?? 'IRR')));

            if ($iban === '') {
                $warnings[] = trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
            }

            $index++;
            $total += $net;

            $rows[] = [
                'row'            => $index,
                'employee_id'    => $employeeId,
                'employee_name'  => trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? '')),
                'employee_code'  => (string) ($item['employee_code'] ?? ''),
                'national_id'    => $this->digits((string) ($item['national_id'] ?? '')),
                'bank_name'      => $this->orDefault((string) ($profile['bank_name'] ?? ''), $settings['bank_name']),
                'iban'           => $iban,
                'account_number' => $this->digits((string) ($profile['account_number'] ?? '')),
                'net_amount'     => $net,
                'currency'       => (string) ($item['currency'] ?? 'IRR'),
                'pay_type'       => (string) ($item['pay_type'] ?? 'monthly'),
                'note'           => $this->orDefault($settings['bank_note'], 'حقوق ' . Jalali::monthLabel((string) $period['start_date'])),
            ];
        }

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => 'فایل پرداخت بانک آماده است (' . count($rows) . ' ردیف).',
            'data'    => [
                'period'         => $period,
                'finalized'      => $finalized,
                'settings'       => $settings,
                'rows'           => $rows,
                'total'          => $total,
                'count'          => count($rows),
                'missing_iban'   => $warnings,
                'warnings'       => $warnings === [] ? [] : [
                    'شماره شبای ' . count($warnings) . ' نفر ثبت نشده است: '
                    . implode('، ', array_slice($warnings, 0, 6))
                    . (count($warnings) > 6 ? ' و ' . (count($warnings) - 6) . ' نفر دیگر' : ''),
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildBankCsv(int $customerId, int $periodId): array
    {
        $result = $this->bankRows($customerId, $periodId);

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return $this->fail(self::CODE_NO_ROWS, 'ساخت فایل CSV ناموفق بود.');
        }

        // The BOM is what makes Excel on a Persian Windows show the headers
        // correctly instead of mojibake.
        fwrite($handle, "\xEF\xBB\xBF");

        $labels = [];

        foreach (self::BANK_COLUMNS as $column) {
            $labels[] = $column['label'];
        }

        fputcsv($handle, $labels);

        foreach ($result['data']['rows'] as $row) {
            $line = [];

            foreach (self::BANK_COLUMNS as $column) {
                $line[] = $row[$column['key']] ?? '';
            }

            fputcsv($handle, $line);
        }

        $line = ['', 'مجموع', '', '', '', '', '', (int) $result['data']['total'], ''];
        fputcsv($handle, $line);

        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => 'فایل CSV پرداخت بانک تولید شد.',
            'data'    => $result['data'] + ['body' => $body, 'bytes' => strlen($body)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildBankXlsx(int $customerId, int $periodId): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return $this->fail(self::CODE_NO_EXCEL, 'کتابخانه Excel روی این سرور نصب نیست؛ فایل CSV را دریافت کنید.');
        }

        $result = $this->bankRows($customerId, $periodId);

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        $rows    = $result['data']['rows'];
        $period  = $result['data']['period'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('پرداخت بانک');

        $sheet->setCellValue('A1', 'فایل پرداخت حقوق - ' . (string) ($period['title'] ?? ''));
        $sheet->setCellValue('A2', Jalali::date((string) $period['start_date']) . ' تا ' . Jalali::date((string) $period['end_date']));
        $sheet->setCellValue('A3', 'مبلغ‌ها به ریال است. شماره شبا به صورت متن نوشته شده تا صفرهای ابتدای آن حذف نشود.');

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headerRow = 5;

        foreach (self::BANK_COLUMNS as $i => $column) {
            $letter = $this->columnLetter($i + 1);
            $sheet->setCellValue($letter . $headerRow, $column['label']);
            $sheet->getColumnDimension($letter)->setWidth((float) $column['width']);
        }

        $sheet->getStyle('A' . $headerRow . ':' . $this->columnLetter(count(self::BANK_COLUMNS)) . $headerRow)
            ->getFont()->setBold(true);

        $r = $headerRow;

        foreach ($rows as $row) {
            $r++;

            foreach (self::BANK_COLUMNS as $i => $column) {
                $letter = $this->columnLetter($i + 1);
                $cell   = $letter . $r;

                if ($column['type'] === 'int') {
                    $sheet->setCellValueExplicit($cell, (int) ($row[$column['key']] ?? 0), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0');
                    continue;
                }

                // Explicit string: keeps a leading zero of an account number and
                // never turns "IR06..." into a formula or a number.
                $sheet->setCellValueExplicit($cell, (string) ($row[$column['key']] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        $totalRow = $r + 2;
        $sheet->setCellValue('B' . $totalRow, 'مجموع');
        $sheet->setCellValueExplicit('H' . $totalRow, (int) $result['data']['total'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->getStyle('B' . $totalRow . ':H' . $totalRow)->getFont()->setBold(true);
        $sheet->getStyle('H' . $totalRow)->getNumberFormat()->setFormatCode('#,##0');

        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $path   = tempnam(sys_get_temp_dir(), 'sarmad-bank');

        if ($path === false) {
            throw new RuntimeException('ساخت فایل موقت ناموفق بود.');
        }

        $writer->save($path);
        $body = (string) file_get_contents($path);
        @unlink($path);
        $spreadsheet->disconnectWorksheets();

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => 'فایل Excel پرداخت بانک تولید شد.',
            'data'    => $result['data'] + ['body' => $body, 'bytes' => strlen($body)],
        ];
    }

    /* ==================================================================
     * Sources
     * ==================================================================*/

    /**
     * The payroll numbers of a period: the FROZEN rows of a finalized period,
     * the live calculation of an open one. Both are joined with the employee
     * identity columns the exports need.
     *
     * @return array{0:array<int,array<string,mixed>>,1:bool}
     */
    private function payrollRowsFor(int $customerId, array $period): array
    {
        $finalized = (string) ($period['status'] ?? '') === 'finalized';
        $from      = (string) $period['start_date'];
        $to        = (string) $period['end_date'];

        if ($finalized) {
            $rows = Database::select(
                'SELECT i.*, e.`first_name`, e.`last_name`, e.`employee_code`, e.`national_id`, e.`position`, e.`hire_date`'
                . ' FROM `' . Database::table('payroll_items') . '` i'
                . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = i.`employee_id`'
                . ' WHERE i.`customer_id` = ? AND i.`period_id` = ?'
                . ' ORDER BY e.`last_name` ASC, e.`first_name` ASC',
                [$customerId, (int) $period['id']]
            );

            return [$rows, true];
        }

        $rows = $this->payroll->previewRows($customerId, ['date_from' => $from, 'date_to' => $to]);

        // previewRows() returns the calculation only; the identity columns come
        // from the employees table so both branches return the same shape.
        $employees = [];

        foreach (Database::select(
            'SELECT `id`, `first_name`, `last_name`, `employee_code`, `national_id`, `position`, `hire_date`'
            . ' FROM `' . Database::table('employees') . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL',
            [$customerId]
        ) as $employee) {
            $employees[(int) $employee['id']] = $employee;
        }

        $out = [];

        foreach ($rows as $row) {
            $employee = $employees[(int) ($row['employee_id'] ?? 0)] ?? [];

            $out[] = $row + [
                'first_name'    => $employee['first_name'] ?? '',
                'last_name'     => $employee['last_name'] ?? '',
                'employee_code' => $row['employee_code'] ?? ($employee['employee_code'] ?? ''),
                'national_id'   => $employee['national_id'] ?? null,
                'position'      => $employee['position'] ?? null,
                'hire_date'     => $employee['hire_date'] ?? null,
            ];
        }

        return [$out, false];
    }

    /**
     * Every payroll profile of the customer, keyed by employee id. `SELECT p.*`
     * is deliberate: the stage 23 identity columns only exist after a migration
     * run, and reading them by name would break an installation that has not
     * migrated yet.
     *
     * @return array<int,array<string,mixed>>
     */
    private function profilesByEmployee(int $customerId): array
    {
        $out = [];

        foreach (Database::select(
            'SELECT p.*, e.`hire_date`, e.`national_id` AS employee_national_id, e.`position`'
            . ' FROM `' . Database::table('payroll_profiles') . '` p'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = p.`employee_id`'
            . ' WHERE p.`customer_id` = ? AND p.`deleted_at` IS NULL',
            [$customerId]
        ) as $profile) {
            $out[(int) $profile['employee_id']] = $profile;
        }

        return $out;
    }

    /**
     * The daily wage of one person, in the currency of the profile.
     *
     * A live period carries `daily_rate` from PayrollCalculator. A finalized
     * period stores the amounts but not the rate, so the rate is derived from
     * the CONTRACT (the profile) with the frozen day/minute totals - the rate is
     * contract data, the amount stays the frozen one.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $profile
     */
    private function dailyRateFor(int $customerId, array $item, array $profile, bool $finalized): float
    {
        if (!$finalized && isset($item['daily_rate'])) {
            return (float) $item['daily_rate'];
        }

        if (isset($item['daily_rate'])) {
            return (float) $item['daily_rate'];
        }

        if ($profile === []) {
            return 0.0;
        }

        $totals = [
            'work_minutes'        => (int) ($item['work_minutes'] ?? 0),
            'work_days'           => (int) ($item['work_days'] ?? 0),
            'overtime_minutes'    => (int) ($item['overtime_minutes'] ?? 0),
            'late_minutes'        => (int) ($item['late_minutes'] ?? 0),
            'early_leave_minutes' => (int) ($item['early_leave_minutes'] ?? 0),
            'absence_days'        => (int) ($item['absence_days'] ?? 0),
            'present_days'        => (int) ($item['present_days'] ?? 0),
            'leave_days'          => (int) ($item['leave_days'] ?? 0),
            'holiday_days'        => (int) ($item['holiday_days'] ?? 0),
        ];

        $calc = PayrollCalculator::forEmployee($profile, $totals);

        return (float) ($calc['daily_rate'] ?? 0.0);
    }

    /* ==================================================================
     * Value helpers
     * ==================================================================*/

    /**
     * Everything the SSO or the bank receives is whole rials. A profile kept in
     * Toman (IRT) is multiplied by ten; IRR is used as is.
     *
     * @param mixed $amount
     */
    public function toRials($amount, string $currency = 'IRR'): float
    {
        $value = is_string($amount) ? (float) str_replace(',', '', trim($amount)) : (float) $amount;

        if (!is_finite($value)) {
            return 0.0;
        }

        return strtoupper(trim($currency)) === 'IRT' ? $value * 10 : $value;
    }

    /**
     * A Jalali date as the 8 digit string the diskette wants: "14040714".
     *
     * @param mixed $value
     */
    private function compactDate($value): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '' || strpos($text, '0000-00-00') === 0) {
            return '';
        }

        $jalali = Jalali::date($text);

        if ($jalali === '—') {
            return '';
        }

        return Encoding::toLatinDigits(str_replace(['/', '-', ' '], '', $jalali));
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function jalaliOf(string $gregorian): array
    {
        $parts = explode('-', substr(trim($gregorian), 0, 10));

        if (count($parts) !== 3) {
            return [0, 0, 0];
        }

        return Jalali::fromGregorian((int) $parts[0], (int) $parts[1], (int) $parts[2]);
    }

    /**
     * The length of a Jalali month, derived by walking the Gregorian calendar
     * with the project's own Jalali converter - so the leap-year rule of
     * اسفند is exactly the one the rest of the panel uses and no second
     * algorithm is introduced here.
     */
    public function jalaliMonthLength(int $jy, int $jm, string $gregorianInMonth): int
    {
        if ($jy <= 0 || $jm < 1 || $jm > 12) {
            return 30;
        }

        $limit = $jm <= 6 ? 31 : ($jm <= 11 ? 30 : 30);

        try {
            [$cy, $cm, $cd] = $this->jalaliOf($gregorianInMonth);

            if ($cy !== $jy || $cm !== $jm) {
                return $limit;
            }

            $day = new DateTimeImmutable(substr($gregorianInMonth, 0, 10) . ' 12:00:00', new DateTimeZone('UTC'));
            $day = $day->modify('-' . max(0, $cd - 1) . ' days');

            $count = 0;

            while ($count < 40) {
                [$wy, $wm] = $this->jalaliOf($day->format('Y-m-d'));

                if ($wy !== $jy || $wm !== $jm) {
                    break;
                }

                $count++;
                $day = $day->modify('+1 day');
            }

            return $count > 0 ? min($count, $limit) : $limit;
        } catch (\Throwable $e) {
            return $limit;
        }
    }

    /**
     * An IBAN with the spaces removed and every letter upper case. Iranian IBANs
     * start with "IR" and are 26 characters long; a value that does not look
     * like one is still exported (the bank portal reports the problem) but it is
     * never silently "fixed".
     */
    public function normaliseIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? '');

        return substr($iban, 0, 34);
    }

    /**
     * Digits only - a national code or an insurance number is never allowed to
     * carry a Persian digit, a space or a dash on the way to the SSO.
     *
     * @param mixed $value
     */
    private function digits($value): string
    {
        return preg_replace('/\D/', '', Encoding::toLatinDigits((string) ($value ?? ''))) ?? '';
    }

    /**
     * @param mixed $value
     */
    private function sexLabel($value): string
    {
        return strtolower((string) $value) === 'female' ? 'زن' : (strtolower((string) $value) === 'male' ? 'مرد' : '');
    }

    private function firstText(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = Encoding::sanitize($candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function orDefault(string $value, string $default): string
    {
        $value = Encoding::sanitize($value);

        return $value === '' ? $default : $value;
    }

    /**
     * @param array<string,string> $settings
     */
    private function settingText(array $settings, string $key): string
    {
        return Encoding::sanitize((string) ($settings[$key] ?? ''));
    }

    /**
     * @param array<string,string> $settings
     */
    private function settingNumber(array $settings, string $key, float $default): float
    {
        $raw = trim((string) ($settings[$key] ?? ''));

        if ($raw === '') {
            return $default;
        }

        $value = (float) str_replace(['،', ','], '', Encoding::toLatinDigits($raw));

        return is_finite($value) ? $value : $default;
    }

    /**
     * Which people are still missing a mandatory SSO field. Used for the
     * on-screen warning, never to invent a value.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function missingInsuranceFields(array $rows): array
    {
        $missing = [];

        foreach ($rows as $row) {
            $gaps = [];

            if ($this->digits($row['DSW_ID1'] ?? '') === '') {
                $gaps[] = 'شماره بیمه';
            }

            if ($this->digits($row['PER_NATCOD'] ?? '') === '') {
                $gaps[] = 'کد ملی';
            }

            if (($row['DSW_DNAME'] ?? '') === '') {
                $gaps[] = 'نام پدر';
            }

            if (($row['DSW_BDATE'] ?? '') === '') {
                $gaps[] = 'تاریخ تولد';
            }

            if (($row['DSW_SDATE'] ?? '') === '') {
                $gaps[] = 'تاریخ شروع بکار';
            }

            if (($row['DSW_SEX'] ?? '') === '') {
                $gaps[] = 'جنسیت';
            }

            if ($gaps !== []) {
                $missing[] = ($row['_employee_name'] ?? '') . ' (' . implode('، ', $gaps) . ')';
            }
        }

        return $missing;
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $item
     * @return array<int,string>
     */
    private function rowWarnings(array $profile, array $item): array
    {
        $warnings = [];

        if ((float) ($item['base_amount'] ?? 0) <= 0 && (float) ($item['net_amount'] ?? 0) <= 0) {
            $warnings[] = 'مبلغی برای این پرسنل محاسبه نشده است.';
        }

        if ($profile === []) {
            $warnings[] = 'پروفایل حقوقی یافت نشد؛ اطلاعات بیمه خالی است.';
        }

        return $warnings;
    }

    private function columnLetter(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index   = intdiv($index, 26);
        }

        return $letters;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function fail(string $code, string $message, array $data = []): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'data' => $data];
    }

    /**
     * Writes the export to the audit log. The CONTENT is never logged - only
     * the file, the period and the number of rows.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $meta
     */
    public function audit(string $action, array $actor, int $customerId, int $periodId, array $meta = []): void
    {
        AuditLog::record(
            $action,
            'success',
            (string) ($meta['message'] ?? 'فایل خروجی حقوق تولید شد.'),
            $meta + ['actor' => $actor],
            'payroll',
            'payroll_period',
            $periodId,
            $customerId
        );
    }
}
