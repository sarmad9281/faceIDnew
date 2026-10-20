<?php
/**
 * SARMAD Attendance - manager panel: employees (full CRUD, stage 3).
 *
 * Every single query in this controller is scoped to the customer that was
 * resolved from the session (TenantContext). An employee id coming from the
 * URL or a form is only a hint: Employee::findInCustomer() decides whether
 * the row exists for THIS tenant, and a foreign id is treated exactly like a
 * missing one (and audited as a denied access attempt).
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
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeFace;
use App\Models\EmployeeShift;
use App\Models\Leave;
use App\Models\Shift;
use App\Models\FaceEmbedding;
use App\Services\FaceDatasetService;
use App\Services\FeatureService;
use App\Services\UploadService;
use App\Support\Capability;
use App\Support\Input;
use App\Support\Jalali;

final class EmployeeController
{
    private const STATUSES = ['active' => 'فعال', 'suspended' => 'غیرفعال', 'terminated' => 'خاتمه همکاری'];

    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        $filters = [
            'q'           => Input::text($request->input('q', ''), 60),
            'branch_id'   => (int) $request->int('branch_id', 0),
            'status'      => Input::choice($request->input('status', ''), self::STATUSES),
            'shift_id'    => (int) $request->int('shift_id', 0),
            'face'        => Input::choice($request->input('face', ''), ['none' => '1', 'partial' => '1', 'complete' => '1']),
            'customer_id' => $customerId,
        ];

        $listing = Employee::paginate($filters, $request->page(), $request->perPage(20));

        if ($filters['shift_id'] > 0 || $filters['face'] !== '') {
            $listing = $this->applyLocalFilters($listing, $filters, $customerId);
        }

        $branchesEnabled = \feature_enabled($customerId, 'branches');
        $shiftsEnabled   = \feature_enabled($customerId, 'shifts');

        return Response::html(View::render('manager/employees/index', [
            'title'           => 'پرسنل',
            'listing'         => $listing,
            'filters'         => $filters,
            'branches'        => $branchesEnabled ? Branch::forCustomer($customerId) : [],
            'shifts'          => $shiftsEnabled ? Shift::forCustomer($customerId) : [],
            'shiftMap'        => EmployeeShift::activeMapForCustomer($customerId),
            'faceCounts'      => EmployeeFace::countsForCustomer($customerId),
            'counts'          => Employee::statusCounts($customerId),
            'branchesEnabled' => $branchesEnabled,
            'shiftsEnabled'   => $shiftsEnabled,
            'customer'        => Customer::find($customerId),
            'statuses'        => self::STATUSES,
            'error'           => Session::getFlash('error'),
            'success'         => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function create(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        return Response::html(View::render('manager/employees/form', $this->formLocals($customerId, null), 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'employee.manage')) !== null) {
            return $denied;
        }

        $data = $this->validate($request, $customerId);

        if (is_string($data)) {
            Session::flash('error', $data);

            return Redirect::response('/manager/employees/create');
        }

        if ((int) $data['branch_id'] <= 0 && $this->branchRequired($customerId)) {
            Session::flash('error', 'انتخاب شعبه الزامی است.');

            return Redirect::response('/manager/employees/create');
        }

        $limit = $this->employeeLimit($customerId);

        if ($limit > 0 && Employee::totalCount($customerId) >= $limit) {
            Session::flash('error', 'سقف تعداد پرسنل این اشتراک (' . $limit . ' نفر) تکمیل است.');

            return Redirect::response('/manager/employees');
        }

        $code = $this->uniqueCode($customerId, $data['employee_code']);

        $employeeId = Employee::createEmployee([
            'customer_id'   => $customerId,
            'branch_id'     => $data['branch_id'] > 0 ? $data['branch_id'] : null,
            'employee_code' => $code,
            'first_name'    => $data['first_name'],
            'last_name'     => $data['last_name'],
            'national_id'   => $data['national_id'] !== '' ? $data['national_id'] : null,
            'mobile'        => $data['mobile'] !== '' ? $data['mobile'] : null,
            'email'         => $data['email'] !== '' ? $data['email'] : null,
            'position'      => $data['position'] !== '' ? $data['position'] : null,
            'department'    => $data['department'] !== '' ? $data['department'] : null,
            'employment_type' => $data['employment_type'],
            'hire_date'     => $data['hire_date'],
            'status'        => $data['status'],
            'notes'         => $data['notes'] !== '' ? $data['notes'] : null,
            'face_enrolled' => 0,
            'face_status'   => 'not_enrolled',
            'created_by'    => (int) (Session::userId() ?? 0),
        ]);

        $employee = Employee::findInCustomer($employeeId, $customerId) ?? [];

        $this->storePhoto($request, $customerId, $employeeId, (string) ($employee['uuid'] ?? ''));
        $this->assignShift($data['shift_id'], $customerId, $employeeId);

        AuditLog::record('employee.create', 'success', 'پرسنل «' . Employee::fullName($data) . '» ایجاد شد.', [
            'employee_id' => $employeeId,
            'employee_code' => $code,
            'branch_id'   => $data['branch_id'] > 0 ? $data['branch_id'] : null,
            'shift_id'    => $data['shift_id'] > 0 ? $data['shift_id'] : null,
        ], 'app', 'employee', $employeeId, $customerId);

        Session::flash('success', 'پرسنل «' . Employee::fullName($data) . '» ثبت شد. حالا می‌توانید نمونه‌های چهره را ثبت کنید.');

        return Redirect::response('/manager/employees/' . $employeeId);
    }

    public function edit(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        return Response::html(View::render('manager/employees/form', $this->formLocals($customerId, $employee), 'layouts/app'));
    }

    public function update(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'employee.manage')) !== null) {
            return $denied;
        }

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        $employeeId = (int) $employee['id'];
        $data       = $this->validate($request, $customerId, $employeeId);

        if (is_string($data)) {
            Session::flash('error', $data);

            return Redirect::response('/manager/employees/' . $employeeId . '/edit');
        }

        Employee::updateById($employeeId, [
            'branch_id'     => $data['branch_id'] > 0 ? $data['branch_id'] : null,
            'first_name'    => $data['first_name'],
            'last_name'     => $data['last_name'],
            'national_id'   => $data['national_id'] !== '' ? $data['national_id'] : null,
            'mobile'        => $data['mobile'] !== '' ? $data['mobile'] : null,
            'email'         => $data['email'] !== '' ? $data['email'] : null,
            'position'      => $data['position'] !== '' ? $data['position'] : null,
            'department'    => $data['department'] !== '' ? $data['department'] : null,
            'employment_type' => $data['employment_type'],
            'hire_date'     => $data['hire_date'],
            'status'        => $data['status'],
            'notes'         => $data['notes'] !== '' ? $data['notes'] : null,
        ]);

        $this->storePhoto($request, $customerId, $employeeId, (string) $employee['uuid']);
        $this->assignShift($data['shift_id'], $customerId, $employeeId);

        AuditLog::record('employee.update', 'success', 'اطلاعات پرسنل «' . Employee::fullName($data) . '» ویرایش شد.', [
            'employee_id' => $employeeId,
            'status'      => $data['status'],
        ], 'app', 'employee', $employeeId, $customerId);

        Session::flash('success', 'اطلاعات پرسنل به‌روزرسانی شد.');

        return Redirect::response('/manager/employees/' . $employeeId);
    }

    /**
     * Enable / disable an employee.
     *
     * A disabled employee can not create new attendance (the future capture
     * engine refuses him through Employee::canAttend()), while every earlier
     * record stays untouched and keeps appearing in the reports.
     */
    public function toggle(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'employee.manage')) !== null) {
            return $denied;
        }

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        $active = Input::bool($request->input('active', '0')) === 1;

        Employee::updateById((int) $employee['id'], ['status' => $active ? 'active' : 'suspended']);

        AuditLog::record($active ? 'employee.enable' : 'employee.disable', 'success',
            'پرسنل «' . Employee::fullName($employee) . '» ' . ($active ? 'فعال' : 'غیرفعال') . ' شد.',
            ['employee_id' => (int) $employee['id'], 'status' => $active ? 'active' : 'suspended'],
            'app',
            'employee',
            (int) $employee['id'],
            $customerId
        );

        Session::flash('success', 'وضعیت پرسنل به‌روزرسانی شد.' . ($active ? '' : ' (سوابق قبلی حفظ شد و در گزارش‌ها باقی می‌ماند)'));

        return Redirect::response('/manager/employees/' . (int) $employee['id']);
    }

    /**
     * Employee profile: identity, photo, position, shift, branch, status,
     * face enrolment status + the registered samples and the recent
     * attendance history (real rows from attendance_records).
     */
    public function show(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        $employeeId = (int) $employee['id'];

        return Response::html(View::render('manager/employees/profile', [
            'title'          => 'پرونده پرسنل',
            'employee'       => Employee::profileInCustomer($employeeId, $customerId),
            'shift'          => EmployeeShift::currentFor($employeeId, $customerId),
            'faceSlots'      => EmployeeFace::slots($employeeId, $customerId),
            'faceCount'      => EmployeeFace::enrolledCount($employeeId, $customerId),
            // Stage 5: the badge that decides whether attendance may happen at
            // all is the EMBEDDING state (0/3 .. 3/3), not the image count.
            'faceState'      => (new FaceDatasetService())->stateForEmployee($employeeId, $customerId),
            'faceConfig'     => FaceDatasetService::clientConfig(),
            'records'        => AttendanceRecord::recentForEmployee($employeeId, $customerId, 10),
            'leaves'         => Leave::forEmployee($employeeId, $customerId, 5),
            'statuses'       => self::STATUSES,
            'customer'       => Customer::find($customerId),
            'branchesEnabled' => \feature_enabled($customerId, 'branches'),
            'shiftsEnabled'  => \feature_enabled($customerId, 'shifts'),
            'faceEnabled'    => true,
            'error'          => Session::getFlash('error'),
            'success'        => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    /**
     * Register / replace ONE of the three face samples (image storage only -
     * no recognition engine and no liveness detection in this project).
     */
    public function faceStore(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'employee.manage')) !== null) {
            return $denied;
        }

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        $employeeId = (int) $employee['id'];
        $index      = (int) $request->int('sample_index', 0);

        if ($index < 1 || $index > 3) {
            Session::flash('error', 'شماره نمونه چهره باید ۱، ۲ یا ۳ باشد.');

            return Redirect::response('/manager/employees/' . $employeeId);
        }

        if (!$request->hasFile('sample')) {
            Session::flash('error', 'تصویر نمونه چهره انتخاب نشده است.');

            return Redirect::response('/manager/employees/' . $employeeId);
        }

        $customer = Customer::find($customerId);
        $file     = $request->file('sample');

        $stored = UploadService::storeImage(
            is_array($file) ? $file : [],
            (string) ($customer['uuid'] ?? 'customer-' . $customerId),
            UploadService::OWNER_EMPLOYEE,
            (string) $employee['uuid'],
            'face'
        );

        if (!$stored['ok']) {
            Session::flash('error', $stored['message']);

            return Redirect::response('/manager/employees/' . $employeeId);
        }

        $previous = EmployeeFace::sample($employeeId, $index, $customerId);

        EmployeeFace::saveSample([
            'customer_id'  => $customerId,
            'employee_id'  => $employeeId,
            'sample_index' => $index,
            'label'        => EmployeeFace::slotLabel($index),
            'file_path'    => $stored['relative'],
            'mime_type'    => 'image',
            'file_size'    => (int) ($file['size'] ?? 0),
            'status'       => EmployeeFace::STATUS_ENROLLED,
            'created_by'   => (int) (Session::userId() ?? 0),
        ]);

        // Replacing a sample must not leave the previous picture on disk.
        if ($previous !== null && (string) $previous['file_path'] !== $stored['relative']) {
            UploadService::delete((string) $previous['file_path']);
        }

        $status = EmployeeFace::syncEmployeeFlags($employeeId, $customerId);

        AuditLog::record('employee.face.store', 'success',
            'نمونه چهره ' . $index . ' برای «' . Employee::fullName($employee) . '» ثبت شد.',
            ['employee_id' => $employeeId, 'sample_index' => $index, 'face_status' => $status, 'file' => $stored['relative']],
            'app',
            'employee',
            $employeeId,
            $customerId
        );

        $count = EmployeeFace::enrolledCount($employeeId, $customerId);

        Session::flash('success', 'نمونه چهره ' . $index . ' ثبت شد. (' . $count . ' از ۳ نمونه)');

        return Redirect::response('/manager/employees/' . $employeeId);
    }

    public function faceDelete(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'employee.manage')) !== null) {
            return $denied;
        }

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        $employeeId = (int) $employee['id'];
        $index      = (int) $request->int('sample_index', 0);

        $removed = EmployeeFace::removeSample($employeeId, $index, $customerId);

        if ($removed === null) {
            Session::flash('error', 'نمونه چهره یافت نشد.');

            return Redirect::response('/manager/employees/' . $employeeId);
        }

        UploadService::delete((string) $removed['file_path']);
        $status = EmployeeFace::syncEmployeeFlags($employeeId, $customerId);

        AuditLog::record('employee.face.delete', 'success',
            'نمونه چهره ' . $index . ' «' . Employee::fullName($employee) . '» حذف شد.',
            ['employee_id' => $employeeId, 'sample_index' => $index, 'face_status' => $status],
            'app',
            'employee',
            $employeeId,
            $customerId
        );

        Session::flash('success', 'نمونه چهره حذف شد.');

        return Redirect::response('/manager/employees/' . $employeeId);
    }

    /* =================================================================
     * Internals
     * ===============================================================*/

    /**
     * Tenant safe employee lookup for a write/detail action.
     *
     * @return array<string,mixed>|Response
     */
    private function load(Request $request, int $customerId, string $fallback)
    {
        $id = $request->int('employee_id', 0);

        if ($id <= 0) {
            $id = (int) $request->param('id', '0');
        }

        $employee = Employee::findInCustomer($id, $customerId);

        if ($employee !== null) {
            return $employee;
        }

        AuditLog::denied('تلاش برای دسترسی به پرسنل خارج از محدوده مشتری', [
            'requested_employee_id' => $id,
            'customer_id'           => $customerId,
        ]);

        Session::flash('error', 'پرسنل یافت نشد.');

        return Redirect::response($fallback);
    }

    /**
     * @return array<string,mixed>|string validated data, or the error message
     */
    private function validate(Request $request, int $customerId, int $exceptId = 0)
    {
        $first = Input::text($request->input('first_name', ''), 80);
        $last  = Input::text($request->input('last_name', ''), 80);

        if ($first === '' || $last === '') {
            return 'نام و نام خانوادگی الزامی است.';
        }

        $mobile = Input::mobile($request->input('mobile', ''));

        if ($mobile !== '' && !Input::isMobile($mobile)) {
            return 'شماره موبایل معتبر نیست (نمونه: 09123456789).';
        }

        $email = Input::text($request->input('email', ''), 120);

        if (!Input::isEmail($email)) {
            return 'ایمیل معتبر نیست.';
        }

        $hireDate = Input::date($request->input('hire_date', ''));

        if ($hireDate === null && trim((string) $request->input('hire_date', '')) !== '') {
            return 'تاریخ استخدام معتبر نیست.';
        }

        // Branch: only when the feature is on for THIS customer, and only a
        // branch of this customer is ever accepted.
        $branchId        = 0;
        $branchesEnabled = \feature_enabled($customerId, 'branches');

        if ($branchesEnabled) {
            $candidate = (int) $request->int('branch_id', 0);

            if ($candidate > 0) {
                if (Branch::findInCustomer($candidate, $customerId) === null) {
                    AuditLog::denied('تلاش برای انتساب پرسنل به شعبه‌ای خارج از مشتری', [
                        'branch_id'   => $candidate,
                        'customer_id' => $customerId,
                    ]);

                    return 'شعبه انتخاب شده معتبر نیست.';
                }

                $branchId = $candidate;
            }
        }

        // Shift: same rule.
        $shiftId        = 0;
        $shiftsEnabled  = \feature_enabled($customerId, 'shifts');

        if ($shiftsEnabled) {
            $candidate = (int) $request->int('shift_id', 0);

            if ($candidate > 0) {
                if (Shift::findInCustomer($candidate, $customerId) === null) {
                    AuditLog::denied('تلاش برای انتساب شیفت خارج از مشتری', [
                        'shift_id'    => $candidate,
                        'customer_id' => $customerId,
                    ]);

                    return 'شیفت انتخاب شده معتبر نیست.';
                }

                $shiftId = $candidate;
            }
        }

        $code = Input::text($request->input('employee_code', ''), 40);

        if ($code !== '' && Employee::codeTaken($customerId, $code, $exceptId)) {
            return 'این کد پرسنلی قبلاً استفاده شده است.';
        }

        return [
            'first_name'      => $first,
            'last_name'       => $last,
            'mobile'          => $mobile,
            'email'           => $email,
            'national_id'     => Input::text($request->input('national_id', ''), 20),
            'position'        => Input::text($request->input('position', ''), 80),
            'department'      => Input::text($request->input('department', ''), 80),
            'employment_type' => Input::choice($request->input('employment_type', ''), ['full_time', 'part_time', 'contractor', 'intern'], 'full_time'),
            'status'          => Input::choice($request->input('status', ''), self::STATUSES, 'active'),
            'hire_date'       => $hireDate ?? date('Y-m-d'),
            'notes'           => Input::multiline($request->input('notes', ''), 500),
            'employee_code'   => $code,
            'branch_id'       => $branchId,
            'shift_id'        => $shiftId,
        ];
    }

    private function storePhoto(Request $request, int $customerId, int $employeeId, string $employeeUuid): void
    {
        if (!$request->hasFile('photo')) {
            return;
        }

        $customer = Customer::find($customerId);
        $file     = $request->file('photo');

        $stored = UploadService::storeImage(
            is_array($file) ? $file : [],
            (string) ($customer['uuid'] ?? 'customer-' . $customerId),
            UploadService::OWNER_EMPLOYEE,
            $employeeUuid,
            'photo'
        );

        if (!$stored['ok']) {
            Session::flash('error', $stored['message']);

            return;
        }

        $employee = Employee::findInCustomer($employeeId, $customerId);

        if ($employee !== null && !empty($employee['photo_path'])) {
            UploadService::delete((string) $employee['photo_path']);
        }

        Employee::updateById($employeeId, ['photo_path' => $stored['relative']]);

        AuditLog::record('employee.photo', 'success', 'تصویر پرسنل به‌روزرسانی شد.', [
            'employee_id' => $employeeId,
            'file'        => $stored['relative'],
        ], 'app', 'employee', $employeeId, $customerId);
    }

    private function assignShift(int $shiftId, int $customerId, int $employeeId): void
    {
        if ($shiftId <= 0) {
            return;
        }

        EmployeeShift::assign($customerId, $employeeId, $shiftId, (int) (Session::userId() ?? 0));
    }

    private function uniqueCode(int $customerId, string $requested): string
    {
        if ($requested !== '') {
            return $requested;
        }

        $next = Employee::totalCount($customerId) + 1;

        do {
            $code = sprintf('EMP-%04d', $next++);
        } while (Employee::codeTaken($customerId, $code));

        return $code;
    }

    /**
     * @return array<string,mixed>
     */
    private function formLocals(int $customerId, ?array $employee): array
    {
        $branchesEnabled = \feature_enabled($customerId, 'branches');
        $shiftsEnabled   = \feature_enabled($customerId, 'shifts');
        $currentShift    = $employee !== null ? EmployeeShift::currentFor((int) $employee['id'], $customerId) : null;

        return [
            'title'           => $employee === null ? 'افزودن پرسنل' : 'ویرایش پرسنل',
            'employee'        => $employee,
            'branches'        => $branchesEnabled ? Branch::forCustomer($customerId) : [],
            'shifts'          => $shiftsEnabled ? Shift::forCustomer($customerId, true) : [],
            'currentShift'    => $currentShift,
            'statuses'        => self::STATUSES,
            'branchesEnabled' => $branchesEnabled,
            'shiftsEnabled'   => $shiftsEnabled,
            'customer'        => Customer::find($customerId),
            'error'           => Session::getFlash('error'),
            'success'         => Session::getFlash('success'),
        ];
    }

    /**
     * Keeps the "branch is mandatory" rule in one place: it only applies when
     * the branches feature is enabled AND the customer actually has branches.
     */
    private function branchRequired(int $customerId): bool
    {
        if (!\feature_enabled($customerId, 'branches')) {
            return false;
        }

        return Branch::forCustomer($customerId) !== [];
    }

    private function employeeLimit(int $customerId): int
    {
        $customer = Customer::find($customerId);

        return (int) ($customer['max_employees'] ?? 0);
    }

    /**
     * Shift / face filters that the SQL builder does not know about are
     * applied on the already tenant scoped page of rows.
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function applyLocalFilters(array $listing, array $filters, int $customerId): array
    {
        $shiftMap   = EmployeeShift::activeMapForCustomer($customerId);
        $faceCounts = EmployeeFace::countsForCustomer($customerId);

        $rows = array_values(array_filter($listing['rows'], static function (array $row) use ($filters, $shiftMap, $faceCounts): bool {
            $id = (int) $row['id'];

            if (($filters['shift_id'] ?? 0) > 0 && (int) ($shiftMap[$id]['shift_id'] ?? 0) !== (int) $filters['shift_id']) {
                return false;
            }

            $face = (string) ($filters['face'] ?? '');

            if ($face !== '') {
                $count = (int) ($faceCounts[$id] ?? 0);

                if ($face === 'none' && $count !== 0) {
                    return false;
                }

                if ($face === 'partial' && ($count === 0 || $count >= 3)) {
                    return false;
                }

                if ($face === 'complete' && $count < 3) {
                    return false;
                }
            }

            return true;
        }));

        $listing['rows'] = $rows;

        return $listing;
    }

    /* =================================================================
     * Stage 5 - face registration wizard (local enrolment)
     * ===============================================================*/

    /**
     * GET /manager/employees/{id}/faces
     *
     * The three sample wizard. Detection, quality checks and the embedding
     * all run in the browser (no image is sent to PHP for processing); only
     * the resulting descriptor - and optionally a small snapshot - is stored.
     */
    public function facesPage(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        $employeeId = (int) $employee['id'];
        $service    = new FaceDatasetService();

        return Response::html(View::render('manager/employees/faces', [
            'title'       => 'ثبت چهره پرسنل',
            'employee'    => Employee::profileInCustomer($employeeId, $customerId),
            'faceState'   => $service->stateForEmployee($employeeId, $customerId),
            'faceConfig'  => $service->clientConfig(),
            'slots'       => EmployeeFace::slots($employeeId, $customerId),
            'samples'     => array_map(
                static fn (array $sample): array => [
                    'sample_index' => (int) $sample['sample_index'],
                    'quality'      => $sample['quality'] === null ? null : (float) $sample['quality'],
                    'model_version' => (string) $sample['model_version'],
                ],
                FaceEmbedding::descriptorsFor($employeeId, $customerId)
            ),
            'customer'    => Customer::find($customerId),
            'error'       => Session::getFlash('error'),
            'success'     => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    /**
     * POST /manager/employees/{id}/faces/embedding  (JSON)
     *
     * Body: { sample_index, embedding: [128 floats], model_version, detector,
     *         quality?, image? (data URL), request_id? }
     *
     * A descriptor that does not fit the employee's earlier samples is
     * refused, so nobody can enrol a different person into a profile.
     */
    public function faceEmbedding(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'employee.manage')) !== null) {
            return $denied;
        }

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        $actorId = (int) (Session::userId() ?? 0);

        $result = (new FaceDatasetService())->enrollSample($employee, [
            'sample_index'  => $request->int('sample_index', 0),
            'embedding'     => $request->payload('embedding', []),
            'model_version' => (string) $request->payload('model_version', ''),
            'engine'        => (string) $request->payload('engine', 'face-api.js'),
            'detector'      => (string) $request->payload('detector', ''),
            'metric'        => (string) $request->payload('metric', 'euclidean'),
            'quality'       => $request->payload('quality', null),
            'image'         => $request->payload('image', null),
            'request_id'    => (string) $request->payload('request_id', ''),
        ], $actorId, 'manager');

        if (!$result['ok']) {
            return Response::json([
                'success' => false,
                'code'    => $result['code'],
                'message' => $result['message'],
                'error'   => $result['data'],
            ], 422);
        }

        return Response::json([
            'success' => true,
            'code'    => 'OK',
            'message' => $result['message'],
            'data'    => $result['data'],
        ]);
    }

    /**
     * POST /manager/employees/{id}/faces/reset
     *
     * Deletes the descriptors (and the snapshots) of this employee and bumps
     * the customer face data version, so every device drops its cached
     * gallery and the employee has to be enrolled again.
     */
    public function faceReset(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        FeatureService::requireFeature('employees', $customerId);

        if (($denied = Capability::check($request, 'employee.manage')) !== null) {
            return $denied;
        }

        $employee = $this->load($request, $customerId, '/manager/employees');

        if (!is_array($employee)) {
            return $employee;
        }

        $actorId = (int) (Session::userId() ?? 0);

        $result = (new FaceDatasetService())->resetForEmployee($employee, $actorId, 'manager');

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Redirect::response('/manager/employees/' . (int) $employee['id'] . '/faces');
    }
}
