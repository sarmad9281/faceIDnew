<?php
/**
 * SARMAD Attendance - manager panel: shifts (stage 3).
 *
 * Real CRUD on attendance_shifts plus the employee assignment. The assignment
 * is stored in attendance_employee_shifts with weekday = 127 (every day) in
 * the base version - the table already supports per-weekday rows and several
 * shifts per employee, which is what the next project stages will switch on.
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
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Shift;
use App\Services\FeatureService;
use App\Support\Capability;
use App\Support\Input;

final class ShiftController
{
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('shifts', $customerId);

        $shifts     = Shift::detailed($customerId);
        $shiftMap   = EmployeeShift::activeMapForCustomer($customerId);
        $employees  = Employee::forCustomer($customerId, 500);
        $assignedIds = array_map('intval', array_keys($shiftMap));

        return Response::html(View::render('manager/shifts/index', [
            'title'        => 'شیفت‌ها',
            'shifts'       => $shifts,
            'employees'    => $employees,
            'shiftMap'     => $shiftMap,
            'unassigned'   => array_values(array_filter($employees, static fn (array $e): bool => !in_array((int) $e['id'], $assignedIds, true))),
            'assignedCount' => count($assignedIds),
            'customer'     => Customer::find($customerId),
            'error'        => Session::getFlash('error'),
            'success'      => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('shifts', $customerId);

        if (($denied = Capability::check($request, 'shift.manage')) !== null) {
            return $denied;
        }

        $data = $this->validate($request);

        if (is_string($data)) {
            Session::flash('error', $data);

            return Redirect::response('/manager/shifts');
        }

        if ($data['code'] !== '' && Shift::codeTaken($customerId, $data['code'])) {
            Session::flash('error', 'این کد شیفت قبلاً ثبت شده است.');

            return Redirect::response('/manager/shifts');
        }

        $shiftId = Shift::createShift([
            'customer_id'               => $customerId,
            'name'                      => $data['name'],
            'code'                      => $data['code'] !== '' ? $data['code'] : null,
            'start_time'                => $data['start_time'],
            'end_time'                  => $data['end_time'],
            'crosses_midnight'          => Shift::crossesMidnight($data['start_time'], $data['end_time']),
            'late_grace_minutes'        => $data['late_grace_minutes'],
            'early_leave_grace_minutes' => $data['early_leave_grace_minutes'],
            'break_minutes'             => $data['break_minutes'],
            'weekly_pattern'            => 'all',
            'is_active'                 => $data['is_active'],
            'notes'                     => $data['notes'] !== '' ? $data['notes'] : null,
            'created_by'                => (int) (Session::userId() ?? 0),
        ]);

        AuditLog::record('shift.create', 'success', 'شیفت «' . $data['name'] . '» ایجاد شد.', [
            'shift_id'   => $shiftId,
            'start_time' => $data['start_time'],
            'end_time'   => $data['end_time'],
        ], 'app', 'shift', $shiftId, $customerId);

        Session::flash('success', 'شیفت ایجاد شد.');

        return Redirect::response('/manager/shifts');
    }

    public function update(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('shifts', $customerId);

        if (($denied = Capability::check($request, 'shift.manage')) !== null) {
            return $denied;
        }

        $shift = $this->load($request, $customerId);

        if (!is_array($shift)) {
            return $shift;
        }

        $data = $this->validate($request);

        if (is_string($data)) {
            Session::flash('error', $data);

            return Redirect::response('/manager/shifts');
        }

        Shift::updateById((int) $shift['id'], [
            'name'                      => $data['name'],
            'code'                      => $data['code'] !== '' ? $data['code'] : null,
            'start_time'                => $data['start_time'],
            'end_time'                  => $data['end_time'],
            'crosses_midnight'          => Shift::crossesMidnight($data['start_time'], $data['end_time']),
            'late_grace_minutes'        => $data['late_grace_minutes'],
            'early_leave_grace_minutes' => $data['early_leave_grace_minutes'],
            'break_minutes'             => $data['break_minutes'],
            'is_active'                 => $data['is_active'],
            'notes'                     => $data['notes'] !== '' ? $data['notes'] : null,
        ]);

        AuditLog::record('shift.update', 'success', 'شیفت «' . $data['name'] . '» ویرایش شد.', [
            'shift_id' => (int) $shift['id'],
        ], 'app', 'shift', (int) $shift['id'], $customerId);

        Session::flash('success', 'شیفت به‌روزرسانی شد.');

        return Redirect::response('/manager/shifts');
    }

    public function toggle(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('shifts', $customerId);

        if (($denied = Capability::check($request, 'shift.manage')) !== null) {
            return $denied;
        }

        $shift = $this->load($request, $customerId);

        if (!is_array($shift)) {
            return $shift;
        }

        $active = Input::bool($request->input('active', '0')) === 1;

        Shift::updateById((int) $shift['id'], ['is_active' => $active ? 1 : 0]);

        AuditLog::record('shift.toggle', 'success',
            'شیفت «' . (string) $shift['name'] . '» ' . ($active ? 'فعال' : 'غیرفعال') . ' شد.',
            ['shift_id' => (int) $shift['id'], 'is_active' => $active],
            'app', 'shift', (int) $shift['id'], $customerId);

        Session::flash('success', 'وضعیت شیفت به‌روزرسانی شد.');

        return Redirect::response('/manager/shifts');
    }

    /**
     * Assign one shift to one employee (or detach with shift_id = 0).
     */
    public function assign(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('shifts', $customerId);

        if (($denied = Capability::check($request, 'shift.manage')) !== null) {
            return $denied;
        }

        $actorId    = (int) (Session::userId() ?? 0);
        $employeeId = (int) $request->int('employee_id', 0);
        $shiftId    = (int) $request->int('shift_id', 0);

        // Both ids must belong to the active customer - otherwise nothing happens.
        $employee = Employee::findInCustomer($employeeId, $customerId);

        if ($employee === null) {
            AuditLog::denied('تلاش برای انتساب شیفت به پرسنل خارج از مشتری', [
                'employee_id' => $employeeId,
                'customer_id' => $customerId,
            ]);

            Session::flash('error', 'پرسنل یافت نشد.');

            return Redirect::response('/manager/shifts');
        }

        if ($shiftId === 0) {
            EmployeeShift::clearFor($employeeId, $customerId);

            AuditLog::record('shift.unassign', 'success',
                'شیفت از «' . Employee::fullName($employee) . '» برداشته شد.',
                ['employee_id' => $employeeId], 'app', 'employee', $employeeId, $customerId);

            Session::flash('success', 'شیفت این پرسنل برداشته شد.');

            return Redirect::response('/manager/shifts');
        }

        $shift = Shift::findInCustomer($shiftId, $customerId);

        if ($shift === null) {
            AuditLog::denied('تلاش برای انتساب شیفت خارج از مشتری', [
                'shift_id'    => $shiftId,
                'customer_id' => $customerId,
            ]);

            Session::flash('error', 'شیفت یافت نشد.');

            return Redirect::response('/manager/shifts');
        }

        EmployeeShift::assign($customerId, $employeeId, $shiftId, $actorId);

        AuditLog::record('shift.assign', 'success',
            'شیفت «' . (string) $shift['name'] . '» به «' . Employee::fullName($employee) . '» اختصاص یافت.',
            ['employee_id' => $employeeId, 'shift_id' => $shiftId],
            'app', 'employee', $employeeId, $customerId);

        Session::flash('success', 'شیفت اختصاص یافت.');

        return Redirect::response('/manager/shifts');
    }

    public function destroy(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('shifts', $customerId);

        if (($denied = Capability::check($request, 'shift.manage')) !== null) {
            return $denied;
        }

        $shift = $this->load($request, $customerId);

        if (!is_array($shift)) {
            return $shift;
        }

        $shiftId = (int) $shift['id'];

        // Assignments are closed first, attendance history keeps its shift_id.
        \App\Core\Database::run(
            'UPDATE `' . \App\Core\Database::table('employee_shifts') . '` SET `is_active` = 0 WHERE `shift_id` = ? AND `customer_id` = ?',
            [$shiftId, $customerId]
        );
        \App\Core\Database::run(
            'UPDATE `' . \App\Core\Database::table('records') . '` SET `shift_id` = NULL WHERE `shift_id` = ? AND `customer_id` = ?',
            [$shiftId, $customerId]
        );

        Shift::deleteById($shiftId, 'manager_delete');

        AuditLog::record('shift.delete', 'success', 'شیفت «' . (string) $shift['name'] . '» حذف شد.', [
            'shift_id' => $shiftId,
        ], 'app', 'shift', $shiftId, $customerId);

        Session::flash('success', 'شیفت حذف شد.');

        return Redirect::response('/manager/shifts');
    }

    /* ================================================================= */

    /**
     * @return array<string,mixed>|Response
     */
    private function load(Request $request, int $customerId)
    {
        $id = $request->int('shift_id', 0);

        if ($id <= 0) {
            $id = (int) $request->param('id', '0');
        }

        $shift = Shift::findInCustomer($id, $customerId);

        if ($shift !== null) {
            return $shift;
        }

        AuditLog::denied('تلاش برای دسترسی به شیفت خارج از محدوده مشتری', [
            'shift_id'    => $id,
            'customer_id' => $customerId,
        ]);

        Session::flash('error', 'شیفت یافت نشد.');

        return Redirect::response('/manager/shifts');
    }

    /**
     * @return array<string,mixed>|string
     */
    private function validate(Request $request)
    {
        $name  = Input::text($request->input('name', ''), 120);
        $start = Input::time($request->input('start_time', ''));
        $end   = Input::time($request->input('end_time', ''));

        if ($name === '') {
            return 'نام شیفت الزامی است.';
        }

        if ($start === null || $end === null) {
            return 'ساعت شروع و پایان باید به شکل HH:MM باشد.';
        }

        if ($start === $end) {
            return 'ساعت شروع و پایان نمی‌توانند یکسان باشند.';
        }

        $late  = Input::int($request->input('late_grace_minutes', 0), 0, 600);
        $early = Input::int($request->input('early_leave_grace_minutes', 0), 0, 600);
        $break = Input::int($request->input('break_minutes', 0), 0, 600);

        if ($late === null || $early === null || $break === null) {
            return 'مقادیر مرخصی مجاز و استراحت باید عددی بین ۰ تا ۶۰۰ دقیقه باشند.';
        }

        $span = Shift::timeMinutes($end) <= Shift::timeMinutes($start)
            ? (Shift::timeMinutes($end) + 1440) - Shift::timeMinutes($start)
            : Shift::timeMinutes($end) - Shift::timeMinutes($start);

        if ($break >= $span) {
            return 'مدت استراحت نمی‌تواند بیشتر از طول شیفت باشد.';
        }

        return [
            'name'                      => $name,
            'code'                      => Input::text($request->input('code', ''), 40),
            'start_time'                => $start,
            'end_time'                  => $end,
            'late_grace_minutes'        => $late,
            'early_leave_grace_minutes' => $early,
            'break_minutes'             => $break,
            'is_active'                 => Input::bool($request->input('is_active', '0')),
            'notes'                     => Input::multiline($request->input('notes', ''), 255),
        ];
    }
}
