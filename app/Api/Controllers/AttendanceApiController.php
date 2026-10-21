<?php
/**
 * SARMAD Attendance - MANAGER API for attendance (stage 6).
 *
 * Three read endpoints over the rows the engine really wrote:
 *
 *   GET /api/v1/manager/attendance                  filtered list + totals
 *   GET /api/v1/manager/attendance/current          who is inside right now
 *   GET /api/v1/manager/employees/{id}/attendance   one employee's history
 *
 * Tenant safety: the customer id comes from TenantContext (resolved by the
 * `tenant:auto` middleware from the bearer token), NEVER from a query
 * parameter. A manager of customer A therefore cannot read a single row of
 * customer B, not even by guessing employee or record ids: every query is
 * scoped, and an id that belongs to another tenant answers 404 rather than
 * leaking that it exists.
 */

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\TenantContext;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceEngine;
use App\Support\Jalali;

final class AttendanceApiController
{
    /**
     * GET /api/v1/manager/attendance
     *
     * Query: date_from, date_to, employee_id, branch_id, status, shift_id,
     *        origin (online|offline|manual), flag, inside=1, q, page, per_page
     */
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $filters = [
            'date_from'   => $this->date($request->query('date_from', '')),
            'date_to'     => $this->date($request->query('date_to', '')),
            'employee_id' => $request->int('employee_id'),
            'branch_id'   => $request->int('branch_id'),
            'shift_id'    => $request->int('shift_id'),
            'status'      => strtolower(trim((string) $request->query('status', ''))),
            'origin'      => strtolower(trim((string) $request->query('origin', ''))),
            'flag'        => strtolower(trim((string) $request->query('flag', ''))),
            'q'           => trim((string) $request->query('q', '')),
            'inside'      => $request->query('inside', '') === '1',
        ];

        $page    = max(1, $request->int('page', 1));
        $perPage = max(5, min(100, $request->int('per_page', 25)));

        $result = AttendanceRecord::paginate($customerId, $filters, $page, $perPage);

        $rows = array_map(fn (array $row): array => $this->row($row), $result['rows']);

        return ApiResponse::success([
            'rows'       => $rows,
            'totals'     => $result['totals'],
            'filters'    => $filters,
        ], [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['pages'],
            'customer_id' => $customerId,
        ]);
    }

    /**
     * GET /api/v1/manager/attendance/current
     *
     * Everyone whose entry has no exit yet (branch scoped when the branches
     * feature is on and a branch was asked for).
     */
    public function current(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        $branchId   = $request->int('branch_id');

        $engine  = new AttendanceEngine();
        $inside  = $engine->currentlyInside($customerId, $branchId);
        $summary = $engine->daySummary($customerId, date('Y-m-d'), $branchId);

        $rows = array_map(static fn (array $row): array => [
            'record_id'       => (int) $row['id'],
            'employee_id'     => (int) $row['employee_id'],
            'full_name'       => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            'employee_code'   => (string) ($row['employee_code'] ?? ''),
            'branch'          => $row['branch_name'] === null ? null : (string) $row['branch_name'],
            'device'          => $row['device_name'] === null ? null : (string) $row['device_name'],
            'has_photo'       => trim((string) ($row['photo_path'] ?? '')) !== '',
            'attendance_date' => (string) $row['attendance_date'],
            'entry_at'        => (string) $row['entry_at'],
            'exit_at'         => null,
            'late_minutes'    => (int) $row['late_minutes'],
            'inside_minutes'  => max(0, (int) $row['inside_minutes']),
            'status'          => (string) $row['status'],
            'status_label'    => AttendanceCalculator::statusLabel((string) $row['status']),
            'flags'           => array_values(array_filter(explode(',', (string) $row['flags']))),
            'source'          => (string) $row['source'],
            'is_offline'      => (string) $row['source'] === 'device_offline',
        ], $inside);

        return ApiResponse::success([
            'inside'  => $rows,
            'summary' => $summary,
            'date'    => Jalali::date(date('Y-m-d')),
        ], [
            'count'       => count($rows),
            'customer_id' => $customerId,
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /api/v1/manager/employees/{id}/attendance
     *
     * The last N sessions of ONE employee of THIS customer (the employee
     * profile page uses exactly the same model).
     */
    public function employee(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        // The router hands route parameters through the request, never as
        // positional arguments (see Router::invoke()).
        $employeeId = (int) $request->param('id', '0');

        $employee = Employee::findInCustomer($employeeId, $customerId);

        if ($employee === null) {
            // Also the answer for an employee of ANOTHER customer: the id is
            // never confirmed across tenants.
            return ApiResponse::notFound('این پرسنل در این مجموعه یافت نشد.');
        }

        $limit = max(1, min(100, $request->int('limit', 30)));

        $rows = AttendanceRecord::recentForEmployee($employeeId, $customerId, $limit);

        $history = array_map(fn (array $row): array => $this->row($row) + [
            'shift_name' => $row['shift_name'] === null ? null : (string) $row['shift_name'],
        ], $rows);

        return ApiResponse::success([
            'employee' => [
                'id'        => $employeeId,
                'full_name' => Employee::fullName($employee),
                'code'      => (string) ($employee['employee_code'] ?? ''),
                'status'    => (string) ($employee['status'] ?? ''),
            ],
            'records'  => $history,
        ], [
            'count'       => count($history),
            'limit'       => $limit,
            'customer_id' => $customerId,
        ]);
    }

    /* ================================================================= */

    /**
     * The JSON shape of one record. Everything is derived from real columns -
     * Jalali strings are added for display, never instead of the ISO date.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function row(array $row): array
    {
        $flags = array_values(array_filter(explode(',', (string) ($row['flags'] ?? ''))));

        return [
            'record_id'         => (int) $row['id'],
            'employee_id'       => (int) $row['employee_id'],
            'uuid'              => (string) $row['uuid'],
            'attendance_date'   => (string) $row['attendance_date'],
            'attendance_date_jalali' => Jalali::date((string) $row['attendance_date']),
            'entry_at'          => $row['entry_at'],
            'exit_at'           => $row['exit_at'],
            'work_minutes'      => (int) $row['work_minutes'],
            'late_minutes'      => (int) $row['late_minutes'],
            'early_leave_minutes' => (int) $row['early_leave_minutes'],
            'overtime_minutes'  => (int) $row['overtime_minutes'],
            'status'            => (string) $row['status'],
            'status_label'      => AttendanceCalculator::statusLabel((string) $row['status']),
            'flags'             => $flags,
            'flag_labels'       => array_map([AttendanceCalculator::class, 'flagLabel'], $flags),
            'source'            => (string) $row['source'],
            'is_offline'        => (string) $row['source'] === 'device_offline',
            'is_open'           => !empty($row['open_key']),
            'session_index'     => isset($row['session_index']) ? (int) $row['session_index'] : null,
            'device_event_at'   => $row['device_event_at'] ?? null,
            'device_exit_at'    => $row['device_exit_at'] ?? null,
            'server_received_at' => $row['server_received_at'] ?? null,
            'server_exit_received_at' => $row['server_exit_received_at'] ?? null,
            'calc_version'      => $row['calc_version'] ?? null,
        ];
    }

    private function date($value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
