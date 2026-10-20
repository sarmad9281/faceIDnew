<?php
/**
 * SARMAD Attendance - manager panel: customer holidays (stage 3).
 *
 * The manager defines the calendar of his own customer (optionally per
 * branch). These rows are the ones the later attendance / payroll engine will
 * skip, so they are stored as real dates in attendance_holidays.
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
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Holiday;
use App\Services\FeatureService;
use App\Support\Capability;
use App\Support\Input;

final class HolidayController
{
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        $filters = [
            'q'         => Input::text($request->input('q', ''), 60),
            'year'      => preg_match('/^\d{4}$/', (string) $request->input('year', '')) ? (string) $request->input('year', '') : '',
            'status'    => Input::choice($request->input('status', ''), ['active' => '1', 'inactive' => '1']),
            'branch_id' => (int) $request->int('branch_id', 0),
        ];

        $branchesEnabled = \feature_enabled($customerId, 'branches');

        return Response::html(View::render('manager/holidays/index', [
            'title'           => 'تعطیلات',
            'holidays'        => Holiday::forCustomer($customerId, $filters, 200),
            'filters'         => $filters,
            'branches'        => $branchesEnabled ? Branch::forCustomer($customerId) : [],
            'branchesEnabled' => $branchesEnabled,
            'upcoming'        => Holiday::upcoming($customerId, 5),
            'customer'        => Customer::find($customerId),
            'error'           => Session::getFlash('error'),
            'success'         => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'holiday.manage')) !== null) {
            return $denied;
        }

        $data = $this->validate($request, $customerId);

        if (is_string($data)) {
            Session::flash('error', $data);

            return Redirect::response('/manager/holidays');
        }

        $holidayId = Holiday::createHoliday([
            'customer_id'         => $customerId,
            'branch_id'           => $data['branch_id'] > 0 ? $data['branch_id'] : null,
            'title'               => $data['title'],
            'holiday_date'        => $data['holiday_date'],
            'description'         => $data['description'] !== '' ? $data['description'] : null,
            'is_recurring_yearly' => $data['is_recurring_yearly'],
            'is_active'           => $data['is_active'],
            'created_by'          => (int) (Session::userId() ?? 0),
        ]);

        AuditLog::record('holiday.create', 'success', 'تعطیلی «' . $data['title'] . '» ثبت شد.', [
            'holiday_id'   => $holidayId,
            'holiday_date' => $data['holiday_date'],
            'branch_id'    => $data['branch_id'] > 0 ? $data['branch_id'] : null,
        ], 'app', 'holiday', $holidayId, $customerId);

        Session::flash('success', 'تعطیلی ثبت شد.');

        return Redirect::response('/manager/holidays');
    }

    public function update(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'holiday.manage')) !== null) {
            return $denied;
        }

        $holiday = $this->load($request, $customerId);

        if (!is_array($holiday)) {
            return $holiday;
        }

        $data = $this->validate($request, $customerId, (int) $holiday['id']);

        if (is_string($data)) {
            Session::flash('error', $data);

            return Redirect::response('/manager/holidays');
        }

        Holiday::updateById((int) $holiday['id'], [
            'branch_id'           => $data['branch_id'] > 0 ? $data['branch_id'] : null,
            'title'               => $data['title'],
            'holiday_date'        => $data['holiday_date'],
            'description'         => $data['description'] !== '' ? $data['description'] : null,
            'is_recurring_yearly' => $data['is_recurring_yearly'],
            'is_active'           => $data['is_active'],
        ]);

        AuditLog::record('holiday.update', 'success', 'تعطیلی «' . $data['title'] . '» ویرایش شد.', [
            'holiday_id' => (int) $holiday['id'],
        ], 'app', 'holiday', (int) $holiday['id'], $customerId);

        Session::flash('success', 'تعطیلی به‌روزرسانی شد.');

        return Redirect::response('/manager/holidays');
    }

    public function toggle(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'holiday.manage')) !== null) {
            return $denied;
        }

        $holiday = $this->load($request, $customerId);

        if (!is_array($holiday)) {
            return $holiday;
        }

        $active = Input::bool($request->input('active', '0')) === 1;

        Holiday::updateById((int) $holiday['id'], ['is_active' => $active ? 1 : 0]);

        AuditLog::record('holiday.toggle', 'success',
            'تعطیلی «' . (string) $holiday['title'] . '» ' . ($active ? 'فعال' : 'غیرفعال') . ' شد.',
            ['holiday_id' => (int) $holiday['id'], 'is_active' => $active],
            'app', 'holiday', (int) $holiday['id'], $customerId);

        Session::flash('success', 'وضعیت تعطیلی به‌روزرسانی شد.');

        return Redirect::response('/manager/holidays');
    }

    public function destroy(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('attendance', $customerId);

        if (($denied = Capability::check($request, 'holiday.manage')) !== null) {
            return $denied;
        }

        $holiday = $this->load($request, $customerId);

        if (!is_array($holiday)) {
            return $holiday;
        }

        Holiday::deleteById((int) $holiday['id'], 'manager_delete');

        AuditLog::record('holiday.delete', 'success', 'تعطیلی «' . (string) $holiday['title'] . '» حذف شد.', [
            'holiday_id' => (int) $holiday['id'],
        ], 'app', 'holiday', (int) $holiday['id'], $customerId);

        Session::flash('success', 'تعطیلی حذف شد.');

        return Redirect::response('/manager/holidays');
    }

    /* ================================================================= */

    /**
     * @return array<string,mixed>|Response
     */
    private function load(Request $request, int $customerId)
    {
        $id = (int) $request->int('holiday_id', 0);

        if ($id <= 0) {
            $id = (int) $request->param('id', '0');
        }

        $holiday = Holiday::findInCustomer($id, $customerId);

        if ($holiday !== null) {
            return $holiday;
        }

        AuditLog::denied('تلاش برای دسترسی به تعطیلی خارج از محدوده مشتری', [
            'holiday_id'  => $id,
            'customer_id' => $customerId,
        ]);

        Session::flash('error', 'تعطیلی یافت نشد.');

        return Redirect::response('/manager/holidays');
    }

    /**
     * @return array<string,mixed>|string
     */
    private function validate(Request $request, int $customerId, int $exceptId = 0)
    {
        $title = Input::text($request->input('title', ''), 150);
        $date  = Input::date($request->input('holiday_date', ''));

        if ($title === '') {
            return 'عنوان تعطیلی الزامی است.';
        }

        if ($date === null) {
            return 'تاریخ تعطیلی معتبر نیست.';
        }

        $branchId        = 0;
        $branchesEnabled = \feature_enabled($customerId, 'branches');

        if ($branchesEnabled) {
            $candidate = (int) $request->int('branch_id', 0);

            if ($candidate > 0) {
                if (Branch::findInCustomer($candidate, $customerId) === null) {
                    AuditLog::denied('تلاش برای ثبت تعطیلی برای شعبه خارج از مشتری', [
                        'branch_id'   => $candidate,
                        'customer_id' => $customerId,
                    ]);

                    return 'شعبه انتخاب شده معتبر نیست.';
                }

                $branchId = $candidate;
            }
        }

        if (Holiday::existsOn($customerId, $date, $branchId, $exceptId)) {
            return 'برای این تاریخ و شعبه قبلاً تعطیلی ثبت شده است.';
        }

        return [
            'title'               => $title,
            'holiday_date'        => $date,
            'description'         => Input::multiline($request->input('description', ''), 255),
            'branch_id'           => $branchId,
            'is_recurring_yearly' => Input::bool($request->input('is_recurring_yearly', '0')),
            'is_active'           => Input::bool($request->input('is_active', '1')),
        ];
    }
}
