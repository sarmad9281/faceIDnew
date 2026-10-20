<?php
/**
 * SARMAD Attendance - manager panel: branches.
 *
 * This module only exists when the customer has the "branches" feature
 * enabled (route middleware 'feature:branches' + FeatureService check here).
 * It is a real, working CRUD on attendance_branches, scoped to the active
 * tenant - branches are what devices and employees attach to.
 */

declare(strict_types=1);

namespace App\Controllers\Manager;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Manager;
use App\Services\FeatureService;
use App\Support\Url;

final class BranchController
{
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        return Response::html(View::render('manager/branches/index', [
            'title'    => 'شعب و واحدها',
            'branches' => Branch::forCustomerDetailed($customerId),
            'customer' => \App\Models\Customer::find($customerId),
            'error'    => Session::getFlash('error'),
            'success'  => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();
        $actorId    = (int) (Session::userId() ?? 0);

        FeatureService::requireFeature('branches', $customerId);

        $validator = Validator::make($request->all(), [
            'name'            => 'required|string|max:150',
            'code'            => 'required|alpha_dash|max:40',
            'phone'           => 'string|max:25',
            'address'         => 'string|max:255',
            'city'            => 'string|max:80',
            'description'     => 'string|max:255',
            'geofence_radius' => 'integer|max:100000',
        ], [
            'name' => 'نام شعبه',
            'code' => 'کد شعبه',
        ]);

        if ($validator->fails()) {
            Session::flash('error', $validator->firstError());

            return Redirect::response('/manager/branches');
        }

        $data = $validator->validated();

        if (Branch::codeTaken($customerId, (string) $data['code'])) {
            Session::flash('error', 'این کد شعبه قبلاً ثبت شده است.');

            return Redirect::response('/manager/branches');
        }

        $manager = Manager::primaryFor($customerId);

        $branchId = Branch::createBranch([
            'customer_id'     => $customerId,
            'manager_id'      => $manager !== null ? (int) $manager['id'] : null,
            'name'            => (string) $data['name'],
            'code'            => (string) $data['code'],
            'phone'           => \App\Support\Input::mobile((string) ($data['phone'] ?? '')) ?: null,
            'address'         => $data['address'] ?? null,
            'city'            => $data['city'] ?? null,
            'notes'           => $data['description'] ?? null,
            'geofence_radius' => (int) ($data['geofence_radius'] ?? 150),
            'is_active'       => 1,
            'created_by'      => $actorId,
        ]);

        AuditLog::record('branch.create', 'success', 'شعبه «' . (string) $data['name'] . '» ایجاد شد.', [
            'branch_id'   => $branchId,
            'customer_id' => $customerId,
        ], 'app', 'branch', $branchId, $customerId);

        Session::flash('success', 'شعبه ایجاد شد.');

        return Redirect::response('/manager/branches');
    }

    public function update(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        FeatureService::requireFeature('branches', $customerId);

        // The branch id comes from the form, but ownership is decided by the
        // database: findInCustomer() fails for another tenant's branch.
        $branch = Branch::findInCustomer($request->int('branch_id', 0), $customerId);

        if ($branch === null) {
            AuditLog::denied('Attempt to edit a branch of another tenant', [
                'branch_id' => $request->int('branch_id', 0),
            ]);

            Session::flash('error', 'شعبه یافت نشد.');

            return Redirect::response('/manager/branches');
        }

        $validator = Validator::make($request->all(), [
            'name'            => 'required|string|max:150',
            'phone'           => 'string|max:25',
            'address'         => 'string|max:255',
            'city'            => 'string|max:80',
            'description'     => 'string|max:255',
            'geofence_radius' => 'integer|max:100000',
        ], ['name' => 'نام شعبه']);

        if ($validator->fails()) {
            Session::flash('error', $validator->firstError());

            return Redirect::response('/manager/branches');
        }

        $data = $validator->validated();

        Branch::updateById((int) $branch['id'], [
            'name'            => (string) $data['name'],
            'phone'           => \App\Support\Input::mobile((string) ($data['phone'] ?? '')) ?: null,
            'address'         => $data['address'] ?? $branch['address'],
            'city'            => $data['city'] ?? $branch['city'],
            'notes'           => $data['description'] ?? $branch['notes'],
            'geofence_radius' => (int) ($data['geofence_radius'] ?? $branch['geofence_radius']),
        ]);

        AuditLog::record('branch.update', 'success', 'شعبه «' . (string) $data['name'] . '» ویرایش شد.', [
            'branch_id'   => (int) $branch['id'],
            'customer_id' => $customerId,
        ], 'app', 'branch', (int) $branch['id'], $customerId);

        Session::flash('success', 'شعبه به‌روزرسانی شد.');

        return Redirect::response('/manager/branches');
    }

    public function toggle(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        FeatureService::requireFeature('branches', $customerId);

        $branch = Branch::findInCustomer($request->int('branch_id', 0), $customerId);

        if ($branch === null) {
            Session::flash('error', 'شعبه یافت نشد.');

            return Redirect::response('/manager/branches');
        }

        $active = (string) $request->input('active', '0') === '1';

        Branch::updateById((int) $branch['id'], ['is_active' => $active ? 1 : 0]);

        AuditLog::record('branch.update', 'success',
            'شعبه «' . (string) $branch['name'] . '» ' . ($active ? 'فعال' : 'غیرفعال') . ' شد.',
            ['branch_id' => (int) $branch['id'], 'is_active' => $active],
            'app',
            'branch',
            (int) $branch['id'],
            $customerId
        );

        Session::flash('success', 'وضعیت شعبه به‌روزرسانی شد.');

        return Redirect::response('/manager/branches');
    }

    public function destroy(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        FeatureService::requireFeature('branches', $customerId);

        $branch = Branch::findInCustomer($request->int('branch_id', 0), $customerId);

        if ($branch === null) {
            Session::flash('error', 'شعبه یافت نشد.');

            return Redirect::response('/manager/branches');
        }

        // Devices and employees referencing this branch keep working
        // (FK is ON DELETE SET NULL); the row is only soft deleted.
        Branch::deleteById((int) $branch['id'], 'manager_delete');

        AuditLog::record('branch.delete', 'success', 'شعبه «' . (string) $branch['name'] . '» حذف شد.', [
            'branch_id' => (int) $branch['id'],
        ], 'app', 'branch', (int) $branch['id'], $customerId);

        Session::flash('success', 'شعبه حذف شد.');

        return Redirect::response('/manager/branches');
    }
}
