<?php
/**
 * SARMAD Attendance - stage 7 payroll service (database side).
 *
 * Responsibilities
 *   - keep attendance_payroll_profiles (rates) for each employee,
 *   - aggregate the attendance facts of a period for one employee or all of
 *     them (attendance_records + attendance_records.status='on_leave'),
 *   - run App\Services\PayrollCalculator over those facts (preview),
 *   - freeze the result into attendance_payroll_periods / _items,
 *   - write attendance_payroll_revisions whenever a finalized period changes.
 *
 * Everything is scoped by customer_id: a manager of customer A can not read or
 * write a profile, a period or an item of customer B, and every query carries
 * `deleted_at IS NULL`.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Models\AuditLog;
use RuntimeException;

final class PayrollService
{
    public const CODE_OK = 'PAYROLL_OK';
    public const CODE_PERIOD_FINALIZED = 'PAYROLL_PERIOD_FINALIZED';
    public const CODE_PERIOD_NOT_FOUND = 'PAYROLL_PERIOD_NOT_FOUND';
    public const CODE_NO_PROFILE = 'PAYROLL_PROFILE_REQUIRED';
    public const CODE_BAD_RANGE = 'PAYROLL_BAD_RANGE';

    /* ==================================================================
     * Profiles
     * ==================================================================*/

    /**
     * @return array<int,array<string,mixed>>
     */
    public function profiles(int $customerId): array
    {
        return Database::select(
            'SELECT p.*, e.`first_name`, e.`last_name`, e.`employee_code`, e.`status` AS employee_status'
            . ' FROM `' . Database::table('payroll_profiles') . '` p'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = p.`employee_id`'
            . ' WHERE p.`customer_id` = ? AND p.`deleted_at` IS NULL AND e.`deleted_at` IS NULL'
            . ' ORDER BY e.`last_name` ASC, e.`first_name` ASC',
            [$customerId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function profileFor(int $customerId, int $employeeId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `' . Database::table('payroll_profiles') . '`'
            . ' WHERE `customer_id` = ? AND `employee_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$customerId, $employeeId]
        );
    }

    /**
     * Create or update the rate profile of one employee.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $input
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>}
     */
    public function saveProfile(array $actor, int $customerId, int $employeeId, array $input): array
    {
        $employee = Database::selectOne(
            'SELECT `id`, `first_name`, `last_name` FROM `' . Database::table('employees') . '`'
            . ' WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL',
            [$employeeId, $customerId]
        );

        if ($employee === null) {
            return ['ok' => false, 'code' => 'EMPLOYEE_NOT_FOUND', 'message' => 'پرسنل در این مجموعه یافت نشد.', 'data' => []];
        }

        $payType = (string) ($input['pay_type'] ?? 'monthly');
        $payType = isset(PayrollCalculator::PAY_TYPES[$payType]) ? $payType : 'monthly';

        $absenceMode = (string) ($input['absence_mode'] ?? 'per_day');
        $absenceMode = isset(PayrollCalculator::ABSENCE_MODES[$absenceMode]) ? $absenceMode : 'per_day';

        $currency = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($input['currency'] ?? 'IRR')), 0, 8));
        $currency = $currency === '' ? 'IRR' : $currency;

        $data = [
            'customer_id'          => $customerId,
            'employee_id'          => $employeeId,
            'pay_type'             => $payType,
            'base_amount'          => $this->money($input['base_amount'] ?? 0),
            'hourly_rate'          => $this->money($input['hourly_rate'] ?? 0),
            'overtime_rate'        => $this->money($input['overtime_rate'] ?? 0),
            'overtime_multiplier'  => $this->number($input['overtime_multiplier'] ?? 1.5, 1.5),
            'late_rate'            => $this->number($input['late_rate'] ?? 0),
            'early_leave_rate'     => $this->number($input['early_leave_rate'] ?? 0),
            'deficit_rate'         => $this->number($input['deficit_rate'] ?? 0),
            'absence_mode'         => $absenceMode,
            'absence_rate'         => $this->money($input['absence_rate'] ?? 0),
            'benefits_amount'      => $this->money($input['benefits_amount'] ?? 0),
            'fixed_deductions'     => $this->money($input['fixed_deductions'] ?? 0),
            'penalty_amount'       => $this->money($input['penalty_amount'] ?? 0),
            'work_days_per_month'  => $this->number($input['work_days_per_month'] ?? 30, 30),
            'work_hours_per_day'   => $this->number($input['work_hours_per_day'] ?? 8, 8),
            'currency'             => $currency,
            'job_title'            => substr((string) ($input['job_title'] ?? ''), 0, 120),
            'is_active'            => 1,
            'notes'                => substr((string) ($input['notes'] ?? ''), 0, 255),
            'calc_version'         => PayrollCalculator::VERSION,
        ];

        $existing = $this->profileFor($customerId, $employeeId);

        if ($existing === null) {
            $data['uuid']       = Security::uuid4();
            $data['created_by'] = (int) ($actor['id'] ?? 0) ?: null;
            $id = Database::insert(Database::table('payroll_profiles'), $data);
        } else {
            $id = (int) $existing['id'];
            Database::update(Database::table('payroll_profiles'), $data, ['id' => $id, 'customer_id' => $customerId]);
        }

        AuditLog::record(
            $existing === null ? 'payroll.profile.created' : 'payroll.profile.updated',
            'success',
            'پروفایل حقوقی پرسنل «' . trim($employee['first_name'] . ' ' . $employee['last_name']) . '» ذخیره شد.',
            [
                'employee_id' => $employeeId,
                'pay_type'    => $payType,
                'before'      => $existing === null ? null : [
                    'base_amount' => $existing['base_amount'],
                    'pay_type'    => $existing['pay_type'],
                ],
                'after'       => ['base_amount' => $data['base_amount'], 'pay_type' => $payType],
                'actor'       => $actor,
            ],
            'payroll',
            'payroll_profile',
            $id,
            $customerId
        );

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => 'پروفایل حقوقی ذخیره شد.',
            'data'    => ['profile_id' => $id, 'employee_id' => $employeeId],
        ];
    }

    /* ==================================================================
     * Attendance facts of a period
     * ==================================================================*/

    /**
     * @return array<string,int|float|string>
     */
    public function totalsFor(int $customerId, int $employeeId, string $from, string $to): array
    {
        $row = Database::selectOne(
            'SELECT'
            . ' COUNT(DISTINCT r.`attendance_date`) AS work_days,'
            . ' COUNT(DISTINCT CASE WHEN r.`entry_at` IS NOT NULL THEN r.`attendance_date` END) AS present_days,'
            . ' SUM(CASE WHEN r.`status` = \'absent\' THEN 1 ELSE 0 END) AS absence_days,'
            . ' SUM(CASE WHEN r.`status` = \'on_leave\' THEN 1 ELSE 0 END) AS leave_days,'
            . ' SUM(CASE WHEN r.`status` = \'holiday\' THEN 1 ELSE 0 END) AS holiday_days,'
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(r.`overtime_minutes`), 0) AS overtime_minutes,'
            . ' COALESCE(SUM(r.`late_minutes`), 0) AS late_minutes,'
            . ' COALESCE(SUM(r.`early_leave_minutes`), 0) AS early_leave_minutes'
            . ' FROM `' . Database::table('records') . '` r'
            . ' WHERE r.`customer_id` = ? AND r.`employee_id` = ?'
            . ' AND r.`deleted_at` IS NULL AND r.`voided_at` IS NULL'
            . ' AND r.`attendance_date` BETWEEN ? AND ?',
            [$customerId, $employeeId, $from, $to]
        ) ?? [];

        return [
            'work_days'           => (int) ($row['work_days'] ?? 0),
            'present_days'        => (int) ($row['present_days'] ?? 0),
            'absence_days'        => (int) ($row['absence_days'] ?? 0),
            'leave_days'          => (int) ($row['leave_days'] ?? 0),
            'holiday_days'        => (int) ($row['holiday_days'] ?? 0),
            'work_minutes'        => (int) ($row['work_minutes'] ?? 0),
            'overtime_minutes'    => (int) ($row['overtime_minutes'] ?? 0),
            'late_minutes'        => (int) ($row['late_minutes'] ?? 0),
            'early_leave_minutes' => (int) ($row['early_leave_minutes'] ?? 0),
        ];
    }

    /**
     * Preview for one employee or the whole customer. Never writes anything.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function preview(int $customerId, array $filters): array
    {
        $from = (string) ($filters['date_from'] ?? date('Y-m-01'));
        $to   = (string) ($filters['date_to'] ?? date('Y-m-d'));
        $employeeId = (int) ($filters['employee_id'] ?? 0);

        $where = ['p.`customer_id` = :tenant', 'p.`deleted_at` IS NULL', 'e.`deleted_at` IS NULL', "e.`status` = 'active'"];
        $params = [':tenant' => $customerId];

        if ($employeeId > 0) {
            $where[] = 'p.`employee_id` = :employee';
            $params[':employee'] = $employeeId;
        }

        $profiles = Database::select(
            'SELECT p.*, e.`first_name`, e.`last_name`, e.`employee_code`'
            . ' FROM `' . Database::table('payroll_profiles') . '` p'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = p.`employee_id`'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.`last_name` ASC, e.`first_name` ASC',
            $params
        );

        $items = [];

        foreach ($profiles as $profile) {
            $totals  = $this->totalsFor($customerId, (int) $profile['employee_id'], $from, $to);
            $calc    = PayrollCalculator::forEmployee($profile, $totals);

            $items[] = $calc + [
                'employee_id'   => (int) $profile['employee_id'],
                'employee_name' => trim($profile['first_name'] . ' ' . $profile['last_name']),
                'employee_code' => (string) $profile['employee_code'],
                'profile_id'    => (int) $profile['id'],
                'currency'      => (string) $profile['currency'],
                'period'        => ['from' => $from, 'to' => $to],
            ];
        }

        return $items;
    }

    /**
     * Flat rows for the report/Excel layer.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function previewRows(int $customerId, array $filters): array
    {
        return $this->preview($customerId, $filters);
    }

    /* ==================================================================
     * Periods
     * ==================================================================*/

    /**
     * @return array<int,array<string,mixed>>
     */
    public function periods(int $customerId, int $limit = 100): array
    {
        return Database::select(
            'SELECT p.*, u.`name` AS finalizer_name'
            . ' FROM `' . Database::table('payroll_periods') . '` p'
            . ' LEFT JOIN `' . Database::table('users') . '` u ON u.`id` = p.`finalized_by`'
            . ' WHERE p.`customer_id` = ? AND p.`deleted_at` IS NULL'
            . ' ORDER BY p.`start_date` DESC, p.`id` DESC LIMIT ' . max(1, min(500, $limit)),
            [$customerId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function period(int $customerId, int $periodId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `' . Database::table('payroll_periods') . '`'
            . ' WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$periodId, $customerId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function items(int $customerId, int $periodId): array
    {
        return Database::select(
            'SELECT i.*, e.`first_name`, e.`last_name`, e.`employee_code`'
            . ' FROM `' . Database::table('payroll_items') . '` i'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = i.`employee_id`'
            . ' WHERE i.`customer_id` = ? AND i.`period_id` = ?'
            . ' ORDER BY e.`last_name` ASC, e.`first_name` ASC',
            [$customerId, $periodId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function revisions(int $customerId, int $periodId): array
    {
        return Database::select(
            'SELECT * FROM `' . Database::table('payroll_revisions') . '`'
            . ' WHERE `customer_id` = ? AND `period_id` = ? ORDER BY `id` DESC LIMIT 200',
            [$customerId, $periodId]
        );
    }

    /**
     * Create (or reuse) an OPEN period for a range.
     *
     * @param array<string,mixed> $actor
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>}
     */
    public function createPeriod(array $actor, int $customerId, string $from, string $to, string $title = ''): array
    {
        if ($from === '' || $to === '' || $from > $to) {
            return ['ok' => false, 'code' => self::CODE_BAD_RANGE, 'message' => 'بازه تاریخ نامعتبر است.', 'data' => []];
        }

        $existing = Database::selectOne(
            'SELECT * FROM `' . Database::table('payroll_periods') . '`'
            . ' WHERE `customer_id` = ? AND `start_date` = ? AND `end_date` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$customerId, $from, $to]
        );

        if ($existing !== null) {
            return [
                'ok'      => true,
                'code'    => self::CODE_OK,
                'message' => 'این بازه از قبل وجود دارد.',
                'data'    => ['period_id' => (int) $existing['id'], 'status' => (string) $existing['status'], 'reused' => true],
            ];
        }

        $periodId = Database::insert(Database::table('payroll_periods'), [
            'uuid'        => Security::uuid4(),
            'customer_id' => $customerId,
            'title'       => $title !== '' ? substr($title, 0, 120) : 'دوره ' . $from . ' تا ' . $to,
            'start_date'  => $from,
            'end_date'    => $to,
            'status'      => 'open',
            'currency'    => 'IRR',
            'created_by'  => (int) ($actor['id'] ?? 0) ?: null,
        ]);

        AuditLog::record('payroll.period.created', 'success', 'دوره حقوق ایجاد شد.', [
            'period_id' => $periodId,
            'from'      => $from,
            'to'        => $to,
            'actor'     => $actor,
        ], 'payroll', 'payroll_period', $periodId, $customerId);

        return ['ok' => true, 'code' => self::CODE_OK, 'message' => 'دوره حقوق ایجاد شد.', 'data' => ['period_id' => $periodId, 'status' => 'open']];
    }

    /**
     * Freeze a period: calculate every employee, write the items, mark FINALIZED.
     *
     * @param array<string,mixed> $actor
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>}
     */
    public function finalize(array $actor, int $customerId, int $periodId): array
    {
        $period = $this->period($customerId, $periodId);

        if ($period === null) {
            return ['ok' => false, 'code' => self::CODE_PERIOD_NOT_FOUND, 'message' => 'دوره حقوق یافت نشد.', 'data' => []];
        }

        if ((string) $period['status'] === 'finalized') {
            return [
                'ok'      => false,
                'code'    => self::CODE_PERIOD_FINALIZED,
                'message' => 'این دوره قبلاً قطعی شده است؛ برای تغییر، از «بازمحاسبه با ثبت Revision» استفاده کنید.',
                'data'    => ['period_id' => $periodId, 'status' => 'finalized'],
            ];
        }

        $items = $this->preview($customerId, [
            'date_from' => (string) $period['start_date'],
            'date_to'   => (string) $period['end_date'],
        ]);

        if ($items === []) {
            return [
                'ok'      => false,
                'code'    => self::CODE_NO_PROFILE,
                'message' => 'برای هیچ پرسنلی پروفایل حقوقی ثبت نشده است؛ ابتدا پروفایل بسازید.',
                'data'    => [],
            ];
        }

        $now = date('Y-m-d H:i:s');

        Database::transaction(function () use ($customerId, $periodId, $items, $actor, $now): void {
            foreach ($items as $item) {
                $this->writeItem($customerId, $periodId, $item, $now);
            }

            Database::update(Database::table('payroll_periods'), [
                'status'       => 'finalized',
                'employee_count' => count($items),
                'total_gross'  => round(array_sum(array_column($items, 'gross_amount')), 2),
                'total_net'    => round(array_sum(array_column($items, 'net_amount')), 2),
                'total_overtime_minutes' => (int) array_sum(array_column($items, 'overtime_minutes')),
                'total_late_minutes'     => (int) array_sum(array_column($items, 'late_minutes')),
                'total_absence_days'     => (int) array_sum(array_column($items, 'absence_days')),
                'finalized_by' => (int) ($actor['id'] ?? 0) ?: null,
                'finalized_at' => $now,
            ], ['id' => $periodId, 'customer_id' => $customerId]);
        });

        AuditLog::record('payroll.period.finalized', 'success', 'دوره حقوق قطعی شد.', [
            'period_id'      => $periodId,
            'employees'      => count($items),
            'total_net'      => round(array_sum(array_column($items, 'net_amount')), 2),
            'actor'          => $actor,
        ], 'payroll', 'payroll_period', $periodId, $customerId);

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => 'دوره حقوق قطعی شد (' . count($items) . ' پرسنل).',
            'data'    => [
                'period_id'  => $periodId,
                'employees'  => count($items),
                'total_net'  => round(array_sum(array_column($items, 'net_amount')), 2),
            ],
        ];
    }

    /**
     * Recalculate a FINALIZED period: the old amounts are archived as
     * revisions, then the new amounts are written.
     *
     * @param array<string,mixed> $actor
     * @return array{ok:bool,code:string,message:string,data:array<string,mixed>}
     */
    public function revise(array $actor, int $customerId, int $periodId, string $reason): array
    {
        $period = $this->period($customerId, $periodId);

        if ($period === null) {
            return ['ok' => false, 'code' => self::CODE_PERIOD_NOT_FOUND, 'message' => 'دوره حقوق یافت نشد.', 'data' => []];
        }

        if ((string) $period['status'] !== 'finalized') {
            return [
                'ok'      => false,
                'code'    => self::CODE_OK,
                'message' => 'این دوره قطعی نشده است؛ محاسبه مجدد پیش‌نمایش کافی است.',
                'data'    => ['period_id' => $periodId, 'status' => (string) $period['status']],
            ];
        }

        if (trim($reason) === '') {
            return ['ok' => false, 'code' => 'PAYROLL_REASON_REQUIRED', 'message' => 'برای ثبت Revision، دلیل الزامی است.', 'data' => []];
        }

        $items = $this->preview($customerId, [
            'date_from' => (string) $period['start_date'],
            'date_to'   => (string) $period['end_date'],
        ]);

        $existing = [];

        foreach ($this->items($customerId, $periodId) as $row) {
            $existing[(int) $row['employee_id']] = $row;
        }

        $now      = date('Y-m-d H:i:s');
        $revisions = 0;
        $changed   = 0;

        Database::transaction(function () use ($customerId, $periodId, $items, $existing, $actor, $now, $reason, &$revisions, &$changed): void {
            foreach ($items as $item) {
                $employeeId = (int) $item['employee_id'];
                $before     = $existing[$employeeId] ?? null;

                if ($before !== null && (float) $before['net_amount'] !== (float) $item['net_amount']) {
                    Database::insert(Database::table('payroll_revisions'), [
                        'uuid'        => Security::uuid4(),
                        'customer_id' => $customerId,
                        'period_id'   => $periodId,
                        'item_id'     => (int) $before['id'],
                        'employee_id' => $employeeId,
                        'action'      => 'recalculate',
                        'old_values'  => json_encode([
                            'base_amount'    => $before['base_amount'],
                            'overtime_amount' => $before['overtime_amount'],
                            'deductions'     => round((float) $before['late_deduction'] + (float) $before['early_leave_deduction'] + (float) $before['absence_deduction'] + (float) $before['fixed_deductions'] + (float) $before['other_deductions'], 2),
                            'net_amount'     => $before['net_amount'],
                            'summary'        => [
                                'work_minutes'     => (int) $before['work_minutes'],
                                'overtime_minutes' => (int) $before['overtime_minutes'],
                                'late_minutes'     => (int) $before['late_minutes'],
                                'absence_days'     => (int) $before['absence_days'],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                        'new_values'  => json_encode([
                            'base_amount'    => $item['base_amount'],
                            'overtime_amount' => $item['overtime_amount'],
                            'deductions'     => $item['total_deductions'],
                            'net_amount'     => $item['net_amount'],
                            'summary'        => [
                                'work_minutes'     => $item['work_minutes'],
                                'overtime_minutes' => $item['overtime_minutes'],
                                'late_minutes'     => $item['late_minutes'],
                                'absence_days'     => $item['absence_days'],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                        'reason'      => substr($reason, 0, 500),
                        'actor_id'    => (int) ($actor['id'] ?? 0) ?: null,
                        'actor_label' => (string) ($actor['name'] ?? ''),
                        'ip'          => (string) ($actor['ip'] ?? ''),
                    ]);

                    $revisions++;
                    $changed++;
                }

                $this->writeItem($customerId, $periodId, $item, $now);
            }

            Database::update(Database::table('payroll_periods'), [
                'employee_count' => count($items),
                'total_gross'    => round(array_sum(array_column($items, 'gross_amount')), 2),
                'total_net'      => round(array_sum(array_column($items, 'net_amount')), 2),
                'total_overtime_minutes' => (int) array_sum(array_column($items, 'overtime_minutes')),
                'total_late_minutes'     => (int) array_sum(array_column($items, 'late_minutes')),
                'total_absence_days'     => (int) array_sum(array_column($items, 'absence_days')),
            ], ['id' => $periodId, 'customer_id' => $customerId]);
        });

        AuditLog::record('payroll.period.revised', 'success', 'دوره قطعی با ثبت Revision بازمحاسبه شد.', [
            'period_id' => $periodId,
            'reason'    => $reason,
            'revisions' => $revisions,
            'changed'   => $changed,
            'actor'     => $actor,
        ], 'payroll', 'payroll_period', $periodId, $customerId);

        return [
            'ok'      => true,
            'code'    => self::CODE_OK,
            'message' => $revisions > 0
                ? 'بازمحاسبه انجام شد و ' . $revisions . ' مورد به عنوان Revision ثبت شد.'
                : 'بازمحاسبه انجام شد؛ تغییری نسبت به نسخه قطعی‌شده وجود نداشت.',
            'data'    => ['period_id' => $periodId, 'revisions' => $revisions],
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function writeItem(int $customerId, int $periodId, array $item, string $now): void
    {
        $data = [
            'customer_id'   => $customerId,
            'period_id'     => $periodId,
            'employee_id'   => (int) $item['employee_id'],
            'pay_type'      => (string) $item['pay_type'],
            'currency'      => (string) ($item['currency'] ?? 'IRR'),
            'work_days'     => (int) $item['work_days'],
            'present_days'  => (int) $item['present_days'],
            'absence_days'  => (int) $item['absence_days'],
            'leave_days'    => (int) $item['leave_days'],
            'holiday_days'  => (int) $item['holiday_days'],
            'work_minutes'  => (int) $item['work_minutes'],
            'overtime_minutes' => (int) $item['overtime_minutes'],
            'late_minutes'     => (int) $item['late_minutes'],
            'early_leave_minutes' => (int) $item['early_leave_minutes'],
            'base_amount'      => $item['base_amount'],
            'hourly_rate'      => $item['hourly_rate'],
            'overtime_rate'    => $item['overtime_rate'],
            'overtime_amount'  => $item['overtime_amount'],
            'late_rate'        => $item['late_rate'],
            'late_deduction'   => $item['late_deduction'],
            'early_leave_rate' => $item['early_leave_rate'],
            'early_leave_deduction' => $item['early_leave_deduction'],
            'absence_rate'     => $item['absence_rate'],
            'absence_deduction' => $item['absence_deduction'],
            'benefits'         => $item['benefits'],
            'fixed_deductions' => $item['fixed_deductions'],
            'other_deductions' => $item['other_deductions'],
            'gross_amount'     => $item['gross_amount'],
            'net_amount'       => $item['net_amount'],
            'calc_version'     => PayrollCalculator::VERSION,
            'inputs'           => json_encode([
                'formula' => $item['formula'],
                'period'  => $item['period'],
            ], JSON_UNESCAPED_UNICODE),
            'calc_at'          => $now,
        ];

        $existing = Database::selectOne(
            'SELECT `id` FROM `' . Database::table('payroll_items') . '`'
            . ' WHERE `period_id` = ? AND `employee_id` = ? LIMIT 1',
            [$periodId, (int) $item['employee_id']]
        );

        if ($existing === null) {
            $data['uuid'] = Security::uuid4();
            Database::insert(Database::table('payroll_items'), $data);

            return;
        }

        Database::update(Database::table('payroll_items'), $data, ['id' => (int) $existing['id'], 'customer_id' => $customerId]);
    }

    private function money($value): float
    {
        return round($this->number($value), 2);
    }

    private function number($value, float $default = 0.0): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }
}
