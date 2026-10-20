<?php
/**
 * SARMAD Attendance - stage 7 payroll calculator.
 *
 * THE single place where a payroll number is produced. The manager panel, the
 * Excel export, the API and the finalizer all call this class; there is no
 * second formula hidden in a view or a controller.
 *
 * WHY A PURE FUNCTION
 * ---------------------------------------------------------------------
 * forEmployee() takes a rate profile and a set of minute/day totals and returns
 * a full breakdown. It touches no database, so a test can hand it exact
 * numbers and check the arithmetic without any fixture.
 *
 * INPUT  (profile - attendance_payroll_profiles, extended in stage 7)
 *   pay_type            monthly | daily | hourly
 *   base_amount         monthly salary / daily wage / (hourly: optional)
 *   hourly_rate         explicit hourly rate (wins over the derived one)
 *   overtime_rate       overtime rate PER HOUR
 *   overtime_multiplier used when overtime_rate is 0 (default 1.5)
 *   late_rate           deduction PER MINUTE (see lateRateComment)
 *   early_leave_rate    deduction PER MINUTE
 *   absence_mode        per_day | per_minute | none
 *   absence_rate        per day (per_day) or per minute (per_minute)
 *   benefits_amount     monthly benefits, added as is
 *   fixed_deductions    insurance / fund / loan, subtracted as is
 *   work_days_per_month default 30 - used to derive a daily rate from a salary
 *   work_hours_per_day  default 8 - used to derive an hourly rate
 *
 * OUTPUT
 *   compact base_amount, rates, overtime_amount, late_deduction,
 *   early_leave_deduction, absence_deduction, benefits, fixed_deductions,
 *   other_deductions, gross_amount, net_amount plus a `formula` block that
 *   documents in Persian how each number was produced (shown in the panel and
 *   stored with the frozen payslip).
 *
 * ROUNDING
 *   money is rounded to 2 decimals at the end of each step, minutes stay
 *   integers. Nothing is floored at zero: a period where deductions exceed the
 *   pay is reported as a negative net (the truth), never silently hidden.
 */

declare(strict_types=1);

namespace App\Services;

final class PayrollCalculator
{
    public const VERSION = '7.0.0';

    public const PAY_TYPES = [
        'monthly' => 'ماهانه',
        'daily'   => 'روزانه',
        'hourly'  => 'ساعتی',
    ];

    public const ABSENCE_MODES = [
        'per_day'    => 'روزانه (به ازای هر روز غیبت)',
        'per_minute' => 'دقیقه‌ای',
        'none'       => 'بدون کسر غیبت',
    ];

    /**
     * @param array<string,mixed> $profile
     * @param array<string,int|float> $totals
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function forEmployee(array $profile, array $totals, array $options = []): array
    {
        $payType = (string) ($profile['pay_type'] ?? 'monthly');
        $payType = isset(self::PAY_TYPES[$payType]) ? $payType : 'monthly';

        $baseAmount   = self::money($profile['base_amount'] ?? 0);
        $hourlyRate   = self::money($profile['hourly_rate'] ?? 0);
        $overtimeRate = self::money($profile['overtime_rate'] ?? 0);
        $multiplier   = self::num($profile['overtime_multiplier'] ?? 1.5, 1.5);
        $lateRate     = self::num($profile['late_rate'] ?? 0);
        $earlyRate    = self::num($profile['early_leave_rate'] ?? 0);
        $absenceMode  = (string) ($profile['absence_mode'] ?? 'per_day');
        $absenceRate  = self::money($profile['absence_rate'] ?? 0);
        $benefits     = self::money($profile['benefits_amount'] ?? 0);
        $fixed        = self::money($profile['fixed_deductions'] ?? 0);
        $other        = self::money($options['other_deductions'] ?? ($profile['penalty_amount'] ?? 0));
        $daysPerMonth = max(1.0, self::num($profile['work_days_per_month'] ?? 30, 30));
        $hoursPerDay  = max(1.0, self::num($profile['work_hours_per_day'] ?? 8, 8));

        $workMinutes  = (int) ($totals['work_minutes'] ?? 0);
        $workDays     = (int) ($totals['work_days'] ?? 0);
        $overtimeMin  = (int) ($totals['overtime_minutes'] ?? 0);
        $lateMin      = (int) ($totals['late_minutes'] ?? 0);
        $earlyMin     = (int) ($totals['early_leave_minutes'] ?? 0);
        $absenceDays  = (int) ($totals['absence_days'] ?? 0);

        /* --------------------------------------------------------------
         * 1. Effective rates - derived ONCE, so every formula below uses
         *    the same numbers.
         * --------------------------------------------------------------*/
        $monthlyBase = $payType === 'monthly' ? $baseAmount : 0.0;

        if ($payType === 'hourly') {
            $monthlyBase = $baseAmount > 0 ? $baseAmount : 0.0;
        }

        $effectiveHourly = $hourlyRate;

        if ($effectiveHourly <= 0) {
            if ($payType === 'hourly' && $baseAmount > 0) {
                $effectiveHourly = $baseAmount / ($daysPerMonth * $hoursPerDay);
            } elseif ($payType === 'daily' && $baseAmount > 0) {
                $effectiveHourly = $baseAmount / $hoursPerDay;
            } elseif ($payType === 'monthly' && $baseAmount > 0) {
                $effectiveHourly = $baseAmount / ($daysPerMonth * $hoursPerDay);
            }
        }

        $effectiveDaily = 0.0;

        if ($payType === 'daily' && $baseAmount > 0) {
            $effectiveDaily = $baseAmount;
        } elseif ($baseAmount > 0 && $payType !== 'daily') {
            $effectiveDaily = $baseAmount / $daysPerMonth;
        }

        if ($effectiveDaily <= 0 && $effectiveHourly > 0) {
            $effectiveDaily = $effectiveHourly * $hoursPerDay;
        }

        $effectiveOvertimeHour = $overtimeRate > 0
            ? $overtimeRate
            : ($effectiveHourly > 0 ? $effectiveHourly * $multiplier : 0.0);

        $effectiveLateMinute = $lateRate > 0
            ? $lateRate
            : ($effectiveHourly > 0 ? $effectiveHourly / 60 : 0.0);

        $effectiveEarlyMinute = $earlyRate > 0
            ? $earlyRate
            : ($effectiveHourly > 0 ? $effectiveHourly / 60 : 0.0);

        /* --------------------------------------------------------------
         * 2. Base pay
         * --------------------------------------------------------------*/
        // The engine counts GROSS presence minutes, so the minutes after the
        // shift end are inside work_minutes as well. For an hourly wage they
        // must be taken out of the regular hours, otherwise the same minute
        // would be paid twice: once at the normal rate and once as overtime.
        $regularMinutes = max(0, $workMinutes - $overtimeMin);

        $base = match ($payType) {
            'hourly' => round($effectiveHourly * ($regularMinutes / 60), 2),
            'daily'  => round($effectiveDaily * $workDays, 2),
            default  => $baseAmount,
        };

        /* --------------------------------------------------------------
         * 3. Overtime pay = minutes / 60 * hourly overtime rate
         * --------------------------------------------------------------*/
        $overtimeAmount = round(($overtimeMin / 60) * $effectiveOvertimeHour, 2);

        /* --------------------------------------------------------------
         * 4. Deductions
         * --------------------------------------------------------------*/
        $lateDeduction  = round($lateMin * $effectiveLateMinute, 2);
        $earlyDeduction = round($earlyMin * $effectiveEarlyMinute, 2);

        $absenceDeduction = 0.0;

        if ($absenceMode === 'per_day') {
            $perDay = $absenceRate > 0 ? $absenceRate : $effectiveDaily;
            $absenceDeduction = round($absenceDays * $perDay, 2);
        } elseif ($absenceMode === 'per_minute') {
            $perMinute = $absenceRate > 0 ? $absenceRate : ($effectiveHourly > 0 ? $effectiveHourly / 60 : 0.0);
            $absenceDeduction = round($absenceDays * $hoursPerDay * 60 * $perMinute, 2);
        }

        /* --------------------------------------------------------------
         * 5. Totals
         * --------------------------------------------------------------*/
        $gross = round($base + $benefits + $overtimeAmount, 2);
        $totalDeductions = round($lateDeduction + $earlyDeduction + $absenceDeduction + $fixed + $other, 2);
        $net = round($gross - $totalDeductions, 2);

        return [
            'pay_type'            => $payType,
            'pay_type_label'      => self::PAY_TYPES[$payType],
            'work_days'           => $workDays,
            'present_days'        => (int) ($totals['present_days'] ?? 0),
            'absence_days'        => $absenceDays,
            'leave_days'          => (int) ($totals['leave_days'] ?? 0),
            'holiday_days'        => (int) ($totals['holiday_days'] ?? 0),
            'work_minutes'        => $workMinutes,
            'overtime_minutes'    => $overtimeMin,
            'late_minutes'        => $lateMin,
            'early_leave_minutes' => $earlyMin,
            'base_amount'         => round($base, 2),
            'hourly_rate'         => round($effectiveHourly, 2),
            'overtime_rate'       => round($effectiveOvertimeHour, 2),
            'overtime_amount'     => $overtimeAmount,
            'late_rate'           => round($effectiveLateMinute, 4),
            'late_deduction'      => $lateDeduction,
            'early_leave_rate'    => round($effectiveEarlyMinute, 4),
            'early_leave_deduction' => $earlyDeduction,
            'absence_rate'        => round($absenceMode === 'per_day'
                ? ($absenceRate > 0 ? $absenceRate : $effectiveDaily)
                : ($absenceRate > 0 ? $absenceRate : $effectiveLateMinute), 2),
            'absence_deduction'   => $absenceDeduction,
            'benefits'            => $benefits,
            'fixed_deductions'    => $fixed,
            'other_deductions'    => $other,
            'total_deductions'    => $totalDeductions,
            'gross_amount'        => $gross,
            'net_amount'          => $net,
            'calc_version'        => self::VERSION,
            'formula'             => [
                'rate_note'  => $hourlyRate > 0
                    ? 'نرخ ساعتی از پروفایل حقوقی پرسنل گرفته شده است.'
                    : 'نرخ ساعتی از حقوق ماهانه ÷ (' . rtrim(rtrim(number_format($daysPerMonth, 2, '.', ''), '0'), '.') . ' روز × ' . rtrim(rtrim(number_format($hoursPerDay, 2, '.', ''), '0'), '.') . ' ساعت) محاسبه شده است.',
                'overtime'   => 'اضافه‌کاری = ' . $overtimeMin . ' دقیقه ÷ ۶۰ × نرخ ساعتی اضافه‌کاری ' . number_format($effectiveOvertimeHour, 2) . ($overtimeRate > 0 ? ' (نرخ مستقیم پروفایل)' : ' (نرخ ساعتی × ضریب ' . rtrim(rtrim(number_format($multiplier, 2, '.', ''), '0'), '.') . ')'),
                'late'       => 'کسر تأخیر = ' . $lateMin . ' دقیقه × نرخ دقیقه‌ای ' . number_format($effectiveLateMinute, 4) . ($lateRate > 0 ? ' (نرخ مستقیم پروفایل)' : ' (نرخ ساعتی ÷ ۶۰)'),
                'early'      => 'کسر تعجیل خروج = ' . $earlyMin . ' دقیقه × نرخ دقیقه‌ای ' . number_format($effectiveEarlyMinute, 4),
                'absence'    => $absenceMode === 'none'
                    ? 'کسر غیبت غیرفعال است.'
                    : 'کسر غیبت = ' . $absenceDays . ' روز × ' . number_format((float) ($absenceMode === 'per_day' ? ($absenceRate > 0 ? $absenceRate : $effectiveDaily) : ($absenceRate > 0 ? $absenceRate : $effectiveLateMinute)), 2),
                'base'       => $payType === 'hourly'
                    ? 'حق پایه = ' . $regularMinutes . ' دقیقه کار عادی ÷ ۶۰ × نرخ ساعتی ' . number_format($effectiveHourly, 2) . ' (دقایق اضافه‌کاری از کارکرد عادی کسر شده تا دو بار پرداخت نشود)'
                    : 'حق پایه = ' . number_format($base, 2),
                'net'        => 'خالص = حق پایه + مزایا + فوق‌العاده − (تأخیر + تعجیل + غیبت + کسورات ثابت + سایر)',
            ],
        ];
    }

    private static function money($value): float
    {
        return round(self::num($value), 2);
    }

    private static function num($value, float $default = 0.0): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }

        if (!is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }
}
