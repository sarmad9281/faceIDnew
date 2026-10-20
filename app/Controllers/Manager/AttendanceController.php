<?php
/**
 * SARMAD Attendance - manager panel: attendance (stage 6, REAL DATA).
 *
 * This page shows what the attendance engine actually wrote - the same rows a
 * device produced, with the same numbers the reports use. Nothing on this page
 * is estimated, sampled or faked: on an installation without devices the table
 * is honestly empty.
 *
 * What the manager can do here (every write is audited with a reason):
 *   - filter the list (date, range, employee, branch, status, shift, origin),
 *   - watch «الان در مجموعه» - the employees whose entry has no exit yet,
 *   - add a manual entry/exit, correct a record, void a record,
 *   - recalculate a range after a shift change,
 *   - close a day (the absence pass: absence / leave rows for that date).
 *
 * The numbers themselves are never computed here: the page calls
 * App\Services\AttendanceEngine and App\Services\AttendanceCalculator.
 */

declare(strict_types=1);

namespace App\Controllers\Manager;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\View;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceEngine;
use App\Services\FeatureService;
use App\Support\Capability;
use App\Support\Input;
use App\Support\Jalali;

final class AttendanceController
{
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'attendance.view')) !== null) {
            return $denied;
        }

        $today           = date('Y-m-d');
        $branchesEnabled = \feature_enabled($customerId, 'branches');

        $filters = [
            'date_from'   => Input::date($request->input('date_from', '')) ?? $today,
            'date_to'     => Input::date($request->input('date_to', '')) ?? $today,
            'employee_id' => (int) $request->int('employee_id', 0),
            'branch_id'   => $branchesEnabled ? (int) $request->int('branch_id', 0) : 0,
            'shift_id'    => (int) $request->int('shift_id', 0),
            'status'      => Input::choice($request->input('status', ''), AttendanceRecord::STATUSES),
            'origin'      => Input::choice($request->input('origin', ''), ['online' => '1', 'offline' => '1', 'manual' => '1']),
            'flag'        => Input::choice($request->input('flag', ''), AttendanceCalculator::flagLabels()),
            'late'        => Input::bool($request->input('late', '0')),
            'early'       => Input::bool($request->input('early', '0')),
            'overtime'    => Input::bool($request->input('overtime', '0')),
            'q'           => Input::text($request->input('q', ''), 60),
        ];

        $engine = new AttendanceEngine();

        return Response::html(View::render('manager/attendance/index', [
            'title'           => 'حضور و غیاب',
            'listing'         => AttendanceRecord::paginate($customerId, $filters, $request->page(), $request->perPage(25)),
            'filters'         => $filters,
            'employees'       => Employee::forCustomer($customerId, 500),
            'branches'        => $branchesEnabled ? Branch::forCustomer($customerId) : [],
            'branchesEnabled' => $branchesEnabled,
            'shifts'          => Shift::forCustomer($customerId),
            'statuses'        => AttendanceRecord::STATUSES,
            'origins'         => AttendanceRecord::SOURCES,
            'flags'           => AttendanceCalculator::flagLabels(),
            'inside'          => $engine->currentlyInside($customerId, $filters['branch_id']),
            'summary'         => $engine->daySummary($customerId, $today, $filters['branch_id']),
            'canManage'       => Capability::allows('attendance.manage'),
            'serverTime'      => date('Y-m-d H:i:s'),
            'jToday'          => Jalali::date($today),
            'customer'        => Customer::find($customerId),
            'error'           => Session::getFlash('error'),
            'success'         => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    /**
     * GET /manager/attendance/live - the JSON the «الان در مجموعه» card polls.
     * Read only, session authorised, tenant scoped: exactly the same data the
     * page (and the manager API) shows.
     */
    public function live(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (Capability::denied('attendance.view')) {
            return Response::json(['success' => false, 'code' => 'FORBIDDEN', 'message' => 'دسترسی مجاز نیست.'], 403);
        }

        $branchId = \feature_enabled($customerId, 'branches') ? (int) $request->int('branch_id', 0) : 0;
        $engine   = new AttendanceEngine();

        $inside = array_map(static fn (array $row): array => [
            'record_id'      => (int) $row['id'],
            'employee_id'    => (int) $row['employee_id'],
            'name'           => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            'entry_at'       => (string) $row['entry_at'],
            'entry_time'     => substr((string) $row['entry_at'], 11, 5),
            'inside_minutes' => max(0, (int) $row['inside_minutes']),
            'inside_label'   => AttendanceCalculator::minutesLabel((int) $row['inside_minutes'], false),
            'status'         => (string) $row['status'],
            'is_offline'     => (string) $row['source'] === 'device_offline',
        ], $engine->currentlyInside($customerId, $branchId));

        return Response::json([
            'success' => true,
            'code'    => 'ATTENDANCE_LIVE',
            'data'    => [
                'inside'  => $inside,
                'summary' => $engine->daySummary($customerId, date('Y-m-d'), $branchId),
            ],
            'meta'    => [
                'count'       => count($inside),
                'server_time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * POST /manager/attendance/manual
     *
     * Either a manual entry/exit pair (record_id = 0) or a correction of an
     * existing record. A reason is mandatory and the old + new values are
     * written to the audit trail.
     */
    public function manual(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'attendance.manage')) !== null) {
            return $denied;
        }

        $actor     = $this->actor($request);
        $engine    = new AttendanceEngine();
        $recordId  = (int) $request->int('record_id', 0);

        $input = [
            'employee_id'     => (int) $request->int('employee_id', 0),
            'attendance_date' => Input::date($request->input('attendance_date', date('Y-m-d'))) ?? date('Y-m-d'),
            'entry_at'        => $this->time($request->input('entry_at', ''), $request->input('attendance_date', date('Y-m-d'))),
            'exit_at'         => $this->time($request->input('exit_at', ''), $request->input('attendance_date', date('Y-m-d'))),
            'reason'          => Input::text($request->input('reason', ''), 200),
        ];

        $result = $recordId > 0
            ? $engine->correctRecord($actor, $customerId, $recordId, $input)
            : $engine->manualCreate($actor, $customerId, $input);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/attendance?' . http_build_query(array_filter([
            'date_from' => $input['attendance_date'],
            'date_to'   => $input['attendance_date'],
        ])));
    }

    /** POST /manager/attendance/void - soft delete, never a real delete. */
    public function void(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'attendance.manage')) !== null) {
            return $denied;
        }

        $result = (new AttendanceEngine())->voidRecord(
            $this->actor($request),
            $customerId,
            (int) $request->int('record_id', 0),
            Input::text($request->input('reason', ''), 200)
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/attendance');
    }

    /** POST /manager/attendance/recalculate - re-run the rules over a range. */
    public function recalculate(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'attendance.manage')) !== null) {
            return $denied;
        }

        $result = (new AttendanceEngine())->recalculate(
            $customerId,
            Input::date($request->input('date_from', '')) ?? '',
            Input::date($request->input('date_to', '')) ?? '',
            (int) $request->int('employee_id', 0),
            ((int) Input::bool((string) $request->int('use_current_shift', 0))) === 1,
            $this->actor($request)
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/attendance');
    }

    /** POST /manager/attendance/close-day - absence / leave pass of one date. */
    public function closeDay(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'attendance.manage')) !== null) {
            return $denied;
        }

        $date = Input::date($request->input('date', '')) ?? date('Y-m-d', strtotime('-1 day'));

        $result = (new AttendanceEngine())->closeDay(
            $customerId,
            $date,
            \feature_enabled($customerId, 'branches') ? (int) $request->int('branch_id', 0) : 0,
            $this->actor($request)
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/attendance?' . http_build_query(['date_from' => $date, 'date_to' => $date]));
    }

    /* ================================================================= */

    /**
     * "08:05" + "1405/07/14"-style day -> "YYYY-MM-DD 08:05:00".
     * Empty input stays empty (an open session has no exit time).
     */
    private function time($value, $date): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value) !== 1) {
            return null;
        }

        if (strlen($value) === 5) {
            $value .= ':00';
        }

        return (AttendanceCalculator::normalizeDateTime(substr((string) $date, 0, 10) . ' ' . $value));
    }

    /** @return array{id:int,role:string} */
    private function actor(Request $request): array
    {
        return [
            'id'   => (int) (Session::userId() ?? 0),
            'role' => (string) Session::role(),
        ];
    }
}
