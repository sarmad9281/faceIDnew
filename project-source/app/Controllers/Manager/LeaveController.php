<?php
/**
 * SARMAD Attendance - manager panel: leaves (stage 3).
 *
 * Basic CRUD around attendance_leaves with the four base types. Every date is
 * a real DATE column, the Persian display is produced by App\Support\Jalali,
 * and both the employee and the leave row are resolved through the tenant.
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
use App\Models\Leave;
use App\Services\FeatureService;
use App\Support\Capability;
use App\Support\Input;

final class LeaveController
{
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        $filters = [
            'q'           => Input::text($request->input('q', ''), 60),
            'employee_id' => (int) $request->int('employee_id', 0),
            'leave_type'  => Input::choice($request->input('leave_type', ''), Leave::TYPES),
            'status'      => Input::choice($request->input('status', ''), Leave::STATUSES),
            'date_from'   => Input::date($request->input('date_from', '')) ?? '',
            'date_to'     => Input::date($request->input('date_to', '')) ?? '',
        ];

        return Response::html(View::render('manager/leaves/index', [
            'title'     => 'مرخصی‌ها',
            'listing'   => Leave::paginate($customerId, $filters, $request->page(), $request->perPage(20)),
            'filters'   => $filters,
            'employees' => Employee::forCustomer($customerId, 500),
            'types'     => Leave::TYPES,
            'statuses'  => Leave::STATUSES,
            'customer'  => Customer::find($customerId),
            'error'     => Session::getFlash('error'),
            'success'   => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'leave.manage')) !== null) {
            return $denied;
        }

        $data = $this->validate($request, $customerId);

        if (is_string($data)) {
            Session::flash('error', $data);

            return Redirect::response('/manager/leaves');
        }

        $employee = $data['employee'];

        $overlap = Leave::overlapping((int) $employee['id'], $customerId, $data['start_date'], $data['end_date']);

        if ($overlap !== null) {
            Session::flash('error', 'برای این پرسنل در بازه انتخابی یک مرخصی فعال ثبت شده است.');

            return Redirect::response('/manager/leaves');
        }

        $leaveId = Leave::createLeave([
            'customer_id' => $customerId,
            'employee_id' => (int) $employee['id'],
            'leave_type'  => $data['leave_type'],
            'start_date'  => $data['start_date'],
            'end_date'    => $data['end_date'],
            'days'        => Leave::days($data['start_date'], $data['end_date']),
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'status'      => $data['status'],
            'decided_by'  => $data['status'] === 'pending' ? null : (int) (Session::userId() ?? 0),
            'decided_at'  => $data['status'] === 'pending' ? null : date('Y-m-d H:i:s'),
            'created_by'  => (int) (Session::userId() ?? 0),
        ]);

        AuditLog::record('leave.create', 'success',
            'مرخصی ' . Leave::typeLabel($data['leave_type']) . ' برای «' . Employee::fullName($employee) . '» ثبت شد.',
            [
                'leave_id'    => $leaveId,
                'employee_id' => (int) $employee['id'],
                'start_date'  => $data['start_date'],
                'end_date'    => $data['end_date'],
                'status'      => $data['status'],
            ],
            'app',
            'leave',
            $leaveId,
            $customerId
        );

        Session::flash('success', 'مرخصی ثبت شد.');

        return Redirect::response('/manager/leaves');
    }

    public function update(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'leave.manage')) !== null) {
            return $denied;
        }

        $leave = $this->load($request, $customerId);

        if (!is_array($leave)) {
            return $leave;
        }

        $data = $this->validate($request, $customerId, (int) $leave['id']);

        if (is_string($data)) {
            Session::flash('error', $data);

            return Redirect::response('/manager/leaves');
        }

        Leave::updateById((int) $leave['id'], [
            'employee_id' => (int) $data['employee']['id'],
            'leave_type'  => $data['leave_type'],
            'start_date'  => $data['start_date'],
            'end_date'    => $data['end_date'],
            'days'        => Leave::days($data['start_date'], $data['end_date']),
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'status'      => $data['status'],
        ]);

        AuditLog::record('leave.update', 'success', 'مرخصی #' . (int) $leave['id'] . ' ویرایش شد.', [
            'leave_id'    => (int) $leave['id'],
            'employee_id' => (int) $data['employee']['id'],
            'status'      => $data['status'],
        ], 'app', 'leave', (int) $leave['id'], $customerId);

        Session::flash('success', 'مرخصی به‌روزرسانی شد.');

        return Redirect::response('/manager/leaves');
    }

    /**
     * Approve / reject / cancel.
     */
    public function decide(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'leave.manage')) !== null) {
            return $denied;
        }

        $leave = $this->load($request, $customerId);

        if (!is_array($leave)) {
            return $leave;
        }

        $status = Input::choice($request->input('status', ''), Leave::STATUSES);

        if ($status === '') {
            Session::flash('error', 'وضعیت انتخاب شده معتبر نیست.');

            return Redirect::response('/manager/leaves');
        }

        Leave::decide((int) $leave['id'], $customerId, $status, (int) (Session::userId() ?? 0), Input::multiline($request->input('note', ''), 255));

        AuditLog::record('leave.decide', 'success',
            'وضعیت مرخصی #' . (int) $leave['id'] . ' به «' . Leave::statusLabel($status) . '» تغییر کرد.',
            ['leave_id' => (int) $leave['id'], 'status' => $status],
            'app', 'leave', (int) $leave['id'], $customerId);

        Session::flash('success', 'وضعیت مرخصی به‌روزرسانی شد.');

        return Redirect::response('/manager/leaves');
    }

    public function destroy(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'leave.manage')) !== null) {
            return $denied;
        }

        $leave = $this->load($request, $customerId);

        if (!is_array($leave)) {
            return $leave;
        }

        Leave::deleteById((int) $leave['id'], 'manager_delete');

        AuditLog::record('leave.delete', 'success', 'مرخصی #' . (int) $leave['id'] . ' حذف شد.', [
            'leave_id' => (int) $leave['id'],
        ], 'app', 'leave', (int) $leave['id'], $customerId);

        Session::flash('success', 'مرخصی حذف شد.');

        return Redirect::response('/manager/leaves');
    }

    /* ================================================================= */

    /**
     * @return array<string,mixed>|Response
     */
    private function load(Request $request, int $customerId)
    {
        $id = (int) $request->int('leave_id', 0);

        if ($id <= 0) {
            $id = (int) $request->param('id', '0');
        }

        $leave = Leave::findInCustomer($id, $customerId);

        if ($leave !== null) {
            return $leave;
        }

        AuditLog::denied('تلاش برای دسترسی به مرخصی خارج از محدوده مشتری', [
            'leave_id'    => $id,
            'customer_id' => $customerId,
        ]);

        Session::flash('error', 'مرخصی یافت نشد.');

        return Redirect::response('/manager/leaves');
    }

    /**
     * @return array<string,mixed>|string
     */
    private function validate(Request $request, int $customerId, int $exceptId = 0)
    {
        $employeeId = (int) $request->int('employee_id', 0);
        $employee   = Employee::findInCustomer($employeeId, $customerId);

        if ($employee === null) {
            AuditLog::denied('تلاش برای ثبت مرخصی برای پرسنل خارج از مشتری', [
                'employee_id' => $employeeId,
                'customer_id' => $customerId,
            ]);

            return 'پرسنل انتخاب شده معتبر نیست.';
        }

        $start = Input::date($request->input('start_date', ''));
        $end   = Input::date($request->input('end_date', ''));

        if ($start === null || $end === null) {
            return 'تاریخ شروع و پایان مرخصی الزامی و باید معتبر باشند.';
        }

        if ($end < $start) {
            return 'تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.';
        }

        if ((strtotime($end) - strtotime($start)) / 86400 > 365) {
            return 'بازه مرخصی نمی‌تواند بیش از یک سال باشد.';
        }

        return [
            'employee'    => $employee,
            'leave_type'  => Input::choice($request->input('leave_type', ''), Leave::TYPES, 'annual'),
            'start_date'  => $start,
            'end_date'    => $end,
            'description' => Input::multiline($request->input('description', ''), 500),
            'status'      => Input::choice($request->input('status', ''), Leave::STATUSES, 'pending'),
        ];
    }
}
