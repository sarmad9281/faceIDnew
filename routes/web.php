<?php
/**
 * SARMAD Attendance - web routes.
 *
 * Middleware legend:
 *   auth             -> a logged in user session is required
 *   guest            -> only for visitors who are not logged in
 *   owner            -> platform level: a real, active owner account
 *                       ('owner:allow-impersonation' also works while the
 *                        owner is inside a customer panel)
 *   role:x,y         -> role based access control
 *   feature:x        -> backend feature gate (customer must have it enabled)
 *   tenant:x         -> backend tenant isolation ('single' = one customer,
 *                       'platform' = owner, 'auto' = decided by the role)
 *   csrf             -> CSRF token validation for state changing requests
 *   throttle:n,m     -> at most n requests per m minutes
 */

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\DeviceKioskPageController;
use App\Controllers\DeviceController;
use App\Controllers\Manager\ActivityController as ManagerActivityController;
use App\Controllers\Manager\AttendanceController as ManagerAttendanceController;
use App\Controllers\Manager\BranchController;
use App\Controllers\Manager\DeviceController as ManagerDeviceController;
use App\Controllers\Manager\EmployeeController as ManagerEmployeeController;
use App\Controllers\Manager\HolidayController as ManagerHolidayController;
use App\Controllers\Manager\LeaveController as ManagerLeaveController;
use App\Controllers\Manager\ModuleController as ManagerModuleController;
use App\Controllers\Manager\PayrollController as ManagerPayrollController;
use App\Controllers\Manager\ReportController;
use App\Controllers\Manager\SettingsController as ManagerSettingsController;
use App\Controllers\Manager\ShiftController as ManagerShiftController;
use App\Controllers\Manager\TelegramController as ManagerTelegramController;
use App\Controllers\Owner\CustomerController as OwnerCustomerController;
use App\Controllers\Owner\DashboardController as OwnerDashboardController;
use App\Controllers\Owner\DeviceController as OwnerDeviceController;
use App\Controllers\Owner\EmergencyController;
use App\Controllers\Owner\FeatureController;
use App\Controllers\Owner\LogController as OwnerLogController;
use App\Controllers\Owner\ManagerController as OwnerManagerController;
use App\Controllers\Owner\SettingsController;
use App\Controllers\Owner\SubscriptionController;
use App\Core\Router;

/** @var Router $router */
return static function (Router $router): void {

    /* -----------------------------------------------------------------
     * Public
     * ----------------------------------------------------------------*/
    $router->get('/health', [DashboardController::class, 'health'], [], 'health');

    /* -----------------------------------------------------------------
     * Authentication
     * ----------------------------------------------------------------*/
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest'], 'login');
    $router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf', 'throttle:30,5'], 'login.post');

    $router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf'], 'logout');

    /* -----------------------------------------------------------------
     * Authenticated area (any human role)
     * ----------------------------------------------------------------*/
    $router->get('/', [DashboardController::class, 'home'], ['auth'], 'home');

    $router->get('/dashboard', [DashboardController::class, 'manager'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single'], 'dashboard');

    $router->get('/account/password', [AuthController::class, 'showPassword'], ['auth'], 'account.password');
    $router->post('/account/password', [AuthController::class, 'updatePassword'],
        ['auth', 'csrf', 'throttle:10,10'], 'account.password.update');

    /* -----------------------------------------------------------------
     * Emergency access (owner inside a customer panel) - the exit route
     * must work WHILE impersonating, hence allow-impersonation.
     * ----------------------------------------------------------------*/
    $router->post('/emergency/exit', [EmergencyController::class, 'stop'],
        ['auth', 'owner:allow-impersonation', 'csrf'], 'emergency.exit');

    /* -----------------------------------------------------------------
     * OWNER PANEL (platform level)
     * ----------------------------------------------------------------*/
    $router->get('/owner', [OwnerDashboardController::class, 'index'],
        ['auth', 'owner'], 'owner.dashboard');
    $router->get('/owner/employees', [OwnerDashboardController::class, 'employees'],
        ['auth', 'owner'], 'owner.employees');

    // Customers
    $router->get('/owner/customers', [OwnerCustomerController::class, 'index'],
        ['auth', 'owner'], 'owner.customers');
    $router->get('/owner/customers/create', [OwnerCustomerController::class, 'create'],
        ['auth', 'owner'], 'owner.customers.create');
    $router->post('/owner/customers', [OwnerCustomerController::class, 'store'],
        ['auth', 'owner', 'csrf'], 'owner.customers.store');
    $router->get('/owner/customers/{uuid}', [OwnerCustomerController::class, 'show'],
        ['auth', 'owner'], 'owner.customers.show');
    $router->get('/owner/customers/{uuid}/edit', [OwnerCustomerController::class, 'edit'],
        ['auth', 'owner'], 'owner.customers.edit');
    $router->post('/owner/customers/{uuid}', [OwnerCustomerController::class, 'update'],
        ['auth', 'owner', 'csrf'], 'owner.customers.update');
    $router->post('/owner/customers/{uuid}/status', [OwnerCustomerController::class, 'status'],
        ['auth', 'owner', 'csrf'], 'owner.customers.status');
    $router->post('/owner/customers/{uuid}/logo', [OwnerCustomerController::class, 'logo'],
        ['auth', 'owner', 'csrf'], 'owner.customers.logo');

    // Features
    $router->get('/owner/features', [FeatureController::class, 'index'],
        ['auth', 'owner'], 'owner.features');
    $router->get('/owner/features/{uuid}', [FeatureController::class, 'show'],
        ['auth', 'owner'], 'owner.features.show');
    $router->post('/owner/features/{uuid}', [FeatureController::class, 'update'],
        ['auth', 'owner', 'csrf'], 'owner.features.update');
    $router->post('/owner/features/{uuid}/toggle', [FeatureController::class, 'toggle'],
        ['auth', 'owner', 'csrf'], 'owner.features.toggle');

    // Managers
    $router->get('/owner/managers', [OwnerManagerController::class, 'index'],
        ['auth', 'owner'], 'owner.managers');
    $router->get('/owner/managers/create', [OwnerManagerController::class, 'create'],
        ['auth', 'owner'], 'owner.managers.create');
    $router->post('/owner/managers', [OwnerManagerController::class, 'store'],
        ['auth', 'owner', 'csrf'], 'owner.managers.store');
    $router->get('/owner/managers/{id}/edit', [OwnerManagerController::class, 'edit'],
        ['auth', 'owner'], 'owner.managers.edit');
    $router->post('/owner/managers/{id}', [OwnerManagerController::class, 'update'],
        ['auth', 'owner', 'csrf'], 'owner.managers.update');
    $router->post('/owner/managers/{id}/toggle', [OwnerManagerController::class, 'toggle'],
        ['auth', 'owner', 'csrf'], 'owner.managers.toggle');
    $router->post('/owner/managers/{id}/reset-password', [OwnerManagerController::class, 'resetPassword'],
        ['auth', 'owner', 'csrf'], 'owner.managers.reset');

    // Devices (platform view)
    $router->get('/owner/devices', [OwnerDeviceController::class, 'index'],
        ['auth', 'owner'], 'owner.devices');
    $router->post('/owner/devices/status', [OwnerDeviceController::class, 'status'],
        ['auth', 'owner', 'csrf'], 'owner.devices.status');
    $router->post('/owner/devices/reset', [OwnerDeviceController::class, 'reset'],
        ['auth', 'owner', 'csrf'], 'owner.devices.reset');
    $router->post('/owner/devices/activation-code', [OwnerDeviceController::class, 'activationCode'],
        ['auth', 'owner', 'csrf'], 'owner.devices.activation_code');

    $router->post('/owner/devices/rotate-token', [OwnerDeviceController::class, 'rotateToken'],
        ['auth', 'owner', 'csrf'], 'owner.devices.rotate');
    $router->post('/owner/devices/revoke', [OwnerDeviceController::class, 'revoke'],
        ['auth', 'owner', 'csrf'], 'owner.devices.revoke');
    $router->post('/owner/devices/delete', [OwnerDeviceController::class, 'destroy'],
        ['auth', 'owner', 'csrf'], 'owner.devices.delete');

    // Subscriptions
    $router->get('/owner/subscriptions', [SubscriptionController::class, 'index'],
        ['auth', 'owner'], 'owner.subscriptions');
    $router->post('/owner/subscriptions/expire-overdue', [SubscriptionController::class, 'expireOverdue'],
        ['auth', 'owner', 'csrf'], 'owner.subscriptions.expire');
    $router->get('/owner/subscriptions/{uuid}', [SubscriptionController::class, 'show'],
        ['auth', 'owner'], 'owner.subscriptions.show');
    $router->post('/owner/subscriptions/{uuid}', [SubscriptionController::class, 'update'],
        ['auth', 'owner', 'csrf'], 'owner.subscriptions.update');

    // Logs
    $router->get('/owner/logs', [OwnerLogController::class, 'index'],
        ['auth', 'owner'], 'owner.logs');
    $router->get('/owner/logs/export', [OwnerLogController::class, 'export'],
        ['auth', 'owner', 'throttle:20,5'], 'owner.logs.export');

    // Settings + maintenance
    $router->get('/owner/settings', [SettingsController::class, 'index'],
        ['auth', 'owner'], 'owner.settings');
    $router->post('/owner/settings', [SettingsController::class, 'update'],
        ['auth', 'owner', 'csrf'], 'owner.settings.update');
    $router->post('/owner/settings/api-token', [OwnerSettingsController::class, 'issueToken'],
        ['auth', 'owner', 'csrf'], 'owner.settings.api-token');
    $router->post('/owner/settings/api-token/revoke', [OwnerSettingsController::class, 'revokeToken'],
        ['auth', 'owner', 'csrf'], 'owner.settings.api-token.revoke');
    $router->post('/owner/settings/migrate', [SettingsController::class, 'migrate'],
        ['auth', 'owner', 'csrf', 'throttle:10,5'], 'owner.settings.migrate');
    $router->post('/owner/settings/maintenance', [SettingsController::class, 'maintenance'],
        ['auth', 'owner', 'csrf', 'throttle:10,5'], 'owner.settings.maintenance');

    // Emergency access
    $router->get('/owner/emergency', [EmergencyController::class, 'index'],
        ['auth', 'owner'], 'owner.emergency');
    $router->get('/owner/customers/{uuid}/emergency', [EmergencyController::class, 'show'],
        ['auth', 'owner'], 'owner.emergency.show');
    $router->post('/owner/customers/{uuid}/emergency', [EmergencyController::class, 'start'],
        ['auth', 'owner', 'csrf'], 'owner.emergency.start');
    $router->get('/owner/customers/{uuid}/manager-identity', [EmergencyController::class, 'managerIdentity'],
        ['auth', 'owner'], 'owner.emergency.identity');

    /* -----------------------------------------------------------------
     * MANAGER PANEL (customer scoped, feature gated)
     *
     * Every route below is protected by three independent gates:
     *   1. role:manager,supervisor,owner  -> a device token can never enter
     *   2. tenant:single                  -> the customer comes from the session
     *   3. feature:<key>                  -> the owner must have enabled it
     * The controllers repeat the feature check (FeatureService::requireFeature)
     * and the capability check, so a route typo alone can not open a module.
     * ----------------------------------------------------------------*/

    /* --- Branches (feature: branches) --- */
    $router->get('/manager/branches', [BranchController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:branches'], 'manager.branches');
    $router->post('/manager/branches', [BranchController::class, 'store'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:branches', 'csrf'], 'manager.branches.store');
    $router->post('/manager/branches/update', [BranchController::class, 'update'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:branches', 'csrf'], 'manager.branches.update');
    $router->post('/manager/branches/toggle', [BranchController::class, 'toggle'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:branches', 'csrf'], 'manager.branches.toggle');
    $router->post('/manager/branches/delete', [BranchController::class, 'destroy'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:branches', 'csrf'], 'manager.branches.delete');

    /* --- Employees (feature: employees) --- */
    $router->get('/manager/employees', [ManagerEmployeeController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:employees'], 'manager.employees');
    $router->get('/manager/employees/create', [ManagerEmployeeController::class, 'create'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:employees'], 'manager.employees.create');
    $router->post('/manager/employees', [ManagerEmployeeController::class, 'store'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.employees.store');
    $router->post('/manager/employees/update', [ManagerEmployeeController::class, 'update'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.employees.update');
    $router->post('/manager/employees/toggle', [ManagerEmployeeController::class, 'toggle'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.employees.toggle');
    $router->post('/manager/employees/face', [ManagerEmployeeController::class, 'faceStore'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.employees.face.store');
    $router->post('/manager/employees/face/delete', [ManagerEmployeeController::class, 'faceDelete'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.employees.face.delete');

    /* Stage 5 - face recognition: the three-sample wizard runs LOCALLY in the
     * browser and posts descriptors (plus an optional small snapshot). */
    $router->get('/manager/employees/{id}/faces', [ManagerEmployeeController::class, 'facesPage'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees'], 'manager.employees.faces');
    $router->post('/manager/employees/{id}/faces/embedding', [ManagerEmployeeController::class, 'faceEmbedding'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.employees.faces.embedding');
    $router->post('/manager/employees/{id}/faces/reset', [ManagerEmployeeController::class, 'faceReset'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.employees.faces.reset');
    $router->get('/manager/employees/{id}/edit', [ManagerEmployeeController::class, 'edit'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:employees'], 'manager.employees.edit');
    $router->get('/manager/employees/{id}', [ManagerEmployeeController::class, 'show'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:employees'], 'manager.employees.show');

    /* --- Shifts (feature: shifts) --- */
    $router->get('/manager/shifts', [ManagerShiftController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:shifts'], 'manager.shifts');
    $router->post('/manager/shifts', [ManagerShiftController::class, 'store'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:shifts', 'csrf'], 'manager.shifts.store');
    $router->post('/manager/shifts/update', [ManagerShiftController::class, 'update'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:shifts', 'csrf'], 'manager.shifts.update');
    $router->post('/manager/shifts/toggle', [ManagerShiftController::class, 'toggle'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:shifts', 'csrf'], 'manager.shifts.toggle');
    $router->post('/manager/shifts/assign', [ManagerShiftController::class, 'assign'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:shifts', 'csrf'], 'manager.shifts.assign');
    $router->post('/manager/shifts/delete', [ManagerShiftController::class, 'destroy'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:shifts', 'csrf'], 'manager.shifts.delete');

    /* --- Attendance (feature: attendance) ---
       Stage 6: the list, the live «الان در مجموعه» feed and every audited
       write of the attendance engine (manual add/correct, void, recalculate,
       day close). Reading needs attendance.view, writing attendance.manage
       (supervisors are read only). */
    $router->get('/manager/attendance', [ManagerAttendanceController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:attendance'], 'manager.attendance');

    $router->get('/manager/attendance/live', [ManagerAttendanceController::class, 'live'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:attendance'], 'manager.attendance.live');

    $router->post('/manager/attendance/manual', [ManagerAttendanceController::class, 'manual'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:attendance', 'csrf'], 'manager.attendance.manual');

    $router->post('/manager/attendance/void', [ManagerAttendanceController::class, 'void'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:attendance', 'csrf'], 'manager.attendance.void');

    $router->post('/manager/attendance/recalculate', [ManagerAttendanceController::class, 'recalculate'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:attendance', 'csrf'], 'manager.attendance.recalculate');

    $router->post('/manager/attendance/close-day', [ManagerAttendanceController::class, 'closeDay'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:attendance', 'csrf'], 'manager.attendance.close_day');

    /* --- Leaves (feature: employees) --- */
    $router->get('/manager/leaves', [ManagerLeaveController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:employees'], 'manager.leaves');
    $router->post('/manager/leaves', [ManagerLeaveController::class, 'store'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.leaves.store');
    $router->post('/manager/leaves/update', [ManagerLeaveController::class, 'update'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.leaves.update');
    $router->post('/manager/leaves/decide', [ManagerLeaveController::class, 'decide'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.leaves.decide');
    $router->post('/manager/leaves/delete', [ManagerLeaveController::class, 'destroy'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:employees', 'csrf'], 'manager.leaves.delete');

    /* --- Holidays (feature: attendance) --- */
    $router->get('/manager/holidays', [ManagerHolidayController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:attendance'], 'manager.holidays');
    $router->post('/manager/holidays', [ManagerHolidayController::class, 'store'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:attendance', 'csrf'], 'manager.holidays.store');
    $router->post('/manager/holidays/update', [ManagerHolidayController::class, 'update'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:attendance', 'csrf'], 'manager.holidays.update');
    $router->post('/manager/holidays/toggle', [ManagerHolidayController::class, 'toggle'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:attendance', 'csrf'], 'manager.holidays.toggle');
    $router->post('/manager/holidays/delete', [ManagerHolidayController::class, 'destroy'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:attendance', 'csrf'], 'manager.holidays.delete');

    /* --- Reports (feature: reports) --- */
    $router->get('/manager/reports', [ReportController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:reports'], 'manager.reports');

    /* Real .xlsx download: feature gate reports + excel_reports, checked again
       inside the controller. A manager can only ever export its own customer
       (tenant:single + every query carries customer_id). */
    $router->get('/manager/reports/export', [ReportController::class, 'export'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:reports', 'feature:excel_reports'], 'manager.reports.export');

    /* --- Devices of the customer (limited: view + initial registration) ---
       Devices are not an optional feature of this catalogue, so the page is
       always available - but it is strictly read + register: reset / rotate /
       revoke stay owner only (see the three denied routes below). */
    $router->get('/manager/devices', [ManagerDeviceController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single'], 'manager.devices');
    $router->post('/manager/devices', [ManagerDeviceController::class, 'store'],
        ['auth', 'role:manager,owner', 'tenant:single', 'csrf'], 'manager.devices.store');
    $router->post('/manager/devices/activate', [ManagerDeviceController::class, 'activate'],
        ['auth', 'role:manager,owner', 'tenant:single', 'csrf'], 'manager.devices.activate');
    /* Owner only operations: reachable paths, always refused for a manager. */
    $router->post('/manager/devices/reset', [ManagerDeviceController::class, 'reset'],
        ['auth', 'role:manager,owner', 'tenant:single', 'csrf'], 'manager.devices.reset');
    $router->post('/manager/devices/rotate', [ManagerDeviceController::class, 'rotate'],
        ['auth', 'role:manager,owner', 'tenant:single', 'csrf'], 'manager.devices.rotate');
    $router->post('/manager/devices/revoke', [ManagerDeviceController::class, 'revoke'],
        ['auth', 'role:manager,owner', 'tenant:single', 'csrf'], 'manager.devices.revoke');

    /* --- Payroll (feature: payroll) --- */
    $router->get('/manager/payroll', [ManagerPayrollController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:payroll'], 'manager.payroll');
    $router->post('/manager/payroll/profile', [ManagerPayrollController::class, 'save'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:payroll', 'csrf'], 'manager.payroll.profile');
    $router->post('/manager/payroll/rules', [ManagerPayrollController::class, 'rules'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:payroll', 'csrf'], 'manager.payroll.rules');
    /* Stage 7 engine: period -> finalize -> revise (revision keeps history). */
    $router->post('/manager/payroll/period', [ManagerPayrollController::class, 'period'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:payroll', 'csrf'], 'manager.payroll.period');
    $router->post('/manager/payroll/finalize', [ManagerPayrollController::class, 'finalize'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:payroll', 'csrf'], 'manager.payroll.finalize');
    $router->post('/manager/payroll/revise', [ManagerPayrollController::class, 'revise'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:payroll', 'csrf'], 'manager.payroll.revise');

    /* --- Telegram (feature: telegram) --- */
    $router->get('/manager/telegram', [ManagerTelegramController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:telegram'], 'manager.telegram');
    $router->post('/manager/telegram', [ManagerTelegramController::class, 'save'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:telegram', 'csrf'], 'manager.telegram.save');
    /* Real sends: a test message, the daily report, and a manual worker run. */
    $router->post('/manager/telegram/test', [ManagerTelegramController::class, 'test'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:telegram', 'csrf'], 'manager.telegram.test');
    $router->post('/manager/telegram/daily', [ManagerTelegramController::class, 'daily'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:telegram', 'csrf'], 'manager.telegram.daily');
    $router->post('/manager/telegram/drain', [ManagerTelegramController::class, 'drain'],
        ['auth', 'role:manager,owner', 'tenant:single', 'feature:telegram', 'csrf'], 'manager.telegram.drain');

    /* --- Customer settings / activity (own customer only) --- */
    $router->get('/manager/settings', [ManagerSettingsController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single'], 'manager.settings');
    $router->post('/manager/settings/update', [ManagerSettingsController::class, 'update'],
        ['auth', 'role:manager,owner', 'tenant:single', 'csrf'], 'manager.settings.update');
    $router->get('/manager/settings/features', [ManagerSettingsController::class, 'features'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single'], 'manager.settings.features');
    $router->get('/manager/activity', [ManagerActivityController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single'], 'manager.activity');

    /* Module routes for the features that are scheduled for a later stage.
       They exist now so the backend feature gate is real and testable; the
       controller only reports the module structure (no fake functionality). */
    $router->get('/manager/reports/advanced', [ManagerModuleController::class, 'advancedReports'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:advanced_reports'], 'manager.reports.advanced');
    $router->get('/manager/reports/excel', [ManagerModuleController::class, 'excelReports'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:single', 'feature:excel_reports'], 'manager.reports.excel');

    /* -----------------------------------------------------------------
     * Devices (customer scoped, stage 1 surface kept intact)
     * ----------------------------------------------------------------*/
    $router->get('/devices', [DeviceController::class, 'index'],
        ['auth', 'role:manager,supervisor,owner', 'tenant:auto'], 'devices.index');
    $router->post('/devices', [DeviceController::class, 'store'],
        ['auth', 'role:manager,owner', 'tenant:auto', 'csrf'], 'devices.store');
    $router->post('/devices/activate', [DeviceController::class, 'activate'],
        ['auth', 'role:manager,owner', 'tenant:auto', 'csrf'], 'devices.activate');
    /* Rotating / revoking a device token is an OWNER capability: a manager
       can never do it, not even through the stage 1 surface. */
    $router->post('/devices/rotate-token', [DeviceController::class, 'rotate'],
        ['auth', 'role:owner', 'tenant:auto', 'csrf'], 'devices.rotate');
    $router->post('/devices/revoke', [DeviceController::class, 'revoke'],
        ['auth', 'role:owner', 'tenant:auto', 'csrf'], 'devices.revoke');

    /* -----------------------------------------------------------------
     * Device surface (kiosk shell - camera / face arrive in stage 3)
     * ----------------------------------------------------------------*/
    /* -----------------------------------------------------------------
     * Stage 4 - attendance device (PWA / kiosk)
     *
     * Public by design: the page itself holds no data and no secret. It asks
     * the DEVICE API for its branding/config with the token stored on the
     * device, or shows the activation screen when it has none. Deliberately
     * outside every auth middleware, and with no link into the panels.
     * ----------------------------------------------------------------*/
    /* Legacy entry point: the old placeholder page is gone, this redirects
     * to the single canonical kiosk URL so no second device shell exists. */
    $router->get('/dashboard/device', [DashboardController::class, 'device'], [], 'dashboard.device');
    $router->get('/device', [DeviceKioskPageController::class, 'index'], [], 'device.kiosk');
    $router->get('/device/offline', [DeviceKioskPageController::class, 'offline'], [], 'device.offline');
};
