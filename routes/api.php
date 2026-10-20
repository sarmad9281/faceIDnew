<?php
/**
 * SARMAD Attendance - API routes (versioned).
 *
 * Every endpoint:
 *   - answers JSON only (ApiMiddleware)
 *   - declares its authentication (api.auth = user token, device.token = device)
 *   - is tenant scoped (TenantContext is always resolved before the action)
 *   - is rate limited
 */

declare(strict_types=1);

use App\Api\Controllers\ApiAuthController;
use App\Api\Controllers\AttendanceApiController;
use App\Api\Controllers\ReportApiController;
use App\Api\Controllers\DeviceApiController;
use App\Api\Controllers\DeviceKioskController;
use App\Api\Controllers\HealthController;
use App\Api\Controllers\OwnerApiController;
use App\Core\Router;

/** @var Router $router */
return static function (Router $router): void {

    /* -----------------------------------------------------------------
     * Public
     * ----------------------------------------------------------------*/
    $router->get('/api/v1/health', [HealthController::class, 'index'], ['api'], 'api.health');

    $router->post('/api/v1/auth/token', [ApiAuthController::class, 'token'],
        ['api', 'throttle:30,5'], 'api.auth.token');

    /* -----------------------------------------------------------------
     * Device lifecycle
     * ----------------------------------------------------------------*/
    // A device exchanges its token for a short lived session token.
    $router->post('/api/v1/device/login', [DeviceApiController::class, 'login'],
        ['api', 'throttle:60,5'], 'api.device.login');

    // Authenticated device endpoints.
    $router->get('/api/v1/device/me', [DeviceApiController::class, 'me'],
        ['api', 'device.token', 'throttle:120,1'], 'api.device.me');

    // Stage 4: the kiosk controller keeps the same contract (200, last_seen_at,
    // session or device token accepted, 401 for anything invalid) and adds the
    // device event log, the config version and the pending settings.
    $router->post('/api/v1/device/heartbeat', [DeviceKioskController::class, 'heartbeat'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.heartbeat');

    /* -----------------------------------------------------------------
     * Stage 4 - kiosk device API (PWA)
     * ----------------------------------------------------------------*/

    // One-time activation code -> device token. Public by design (the code IS
    // the credential) and rate limited, because it is the only unauthenticated
    // device endpoint.
    $router->post('/api/v1/device/activate', [DeviceKioskController::class, 'activate'],
        ['api', 'throttle:10,5'], 'api.device.activate');

    // Everything below requires a valid device token; the tenant is taken from
    // the token, never from the request.
    $router->get('/api/v1/device/config', [DeviceKioskController::class, 'config'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.config');

    $router->get('/api/v1/device/status', [DeviceKioskController::class, 'status'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.status');

    $router->get('/api/v1/device/settings', [DeviceKioskController::class, 'settings'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.settings.read');

    $router->post('/api/v1/device/settings', [DeviceKioskController::class, 'settings'],
        ['api', 'device.token', 'throttle:60,5'], 'api.device.settings.write');

    $router->get('/api/v1/device/engine', [DeviceKioskController::class, 'engine'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.engine');

    // Contracts for stage 5: validated here, engine answers later.
    $router->post('/api/v1/device/recognition', [DeviceKioskController::class, 'recognition'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.recognition');

    $router->post('/api/v1/device/attendance', [DeviceKioskController::class, 'attendance'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.attendance');

    /* Stage 6 - the real attendance engine. The device brings the one time
     * proof of the LOCAL face match it just performed (issued by
     * /recognition-result, valid seconds, single use) plus an idempotency key.
     * All validation, the duplicate check, the calculation and the write
     * happen server side, inside one transaction. */
    $router->post('/api/v1/device/attendance/entry', [DeviceKioskController::class, 'attendanceEntry'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.attendance.entry');

    $router->post('/api/v1/device/attendance/exit', [DeviceKioskController::class, 'attendanceExit'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.attendance.exit');

    // Offline queue: a BATCH of events, each answered ACCEPTED / DUPLICATE /
    // REJECTED / INVALID so the kiosk knows what it may drop.
    $router->post('/api/v1/device/attendance/sync', [DeviceKioskController::class, 'attendanceSync'],
        ['api', 'device.token', 'throttle:60,1'], 'api.device.attendance.sync');

    /* Stage 5 - face recognition. The gallery the device may hold, the result
     * of the LOCAL match it performed, and the employee photo for the success
     * screen. Recognition itself never happens on the server. */
    $router->get('/api/v1/device/face-data', [DeviceKioskController::class, 'faceData'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.face_data');

    $router->post('/api/v1/device/recognition-result', [DeviceKioskController::class, 'recognitionResult'],
        ['api', 'device.token', 'throttle:240,1'], 'api.device.recognition_result');

    $router->get('/api/v1/device/employees/{id}/photo', [DeviceKioskController::class, 'employeePhoto'],
        ['api', 'device.token', 'throttle:120,1'], 'api.device.employee_photo');

    $router->post('/api/v1/device/error', [DeviceKioskController::class, 'reportError'],
        ['api', 'device.token', 'throttle:60,1'], 'api.device.error');

    $router->post('/api/v1/device/forget', [DeviceKioskController::class, 'forget'],
        ['api', 'device.token', 'throttle:10,5'], 'api.device.forget');

    /* -----------------------------------------------------------------
     * Device management (owner / manager token)
     * ----------------------------------------------------------------*/
    $router->get('/api/v1/me', [ApiAuthController::class, 'me'],
        ['api', 'api.auth', 'throttle:120,1'], 'api.me');

    $router->post('/api/v1/auth/logout', [ApiAuthController::class, 'logout'],
        ['api', 'api.auth'], 'api.auth.logout');

    $router->get('/api/v1/devices', [DeviceApiController::class, 'index'],
        ['api', 'api.auth:devices.manage', 'tenant:auto', 'throttle:120,1'], 'api.devices.index');

    $router->post('/api/v1/devices', [DeviceApiController::class, 'register'],
        ['api', 'api.auth:devices.manage', 'tenant:auto', 'throttle:30,5'], 'api.devices.store');

    $router->post('/api/v1/devices/activate', [DeviceApiController::class, 'activate'],
        ['api', 'api.auth:devices.manage', 'tenant:auto', 'throttle:30,5'], 'api.devices.activate');

    $router->post('/api/v1/devices/rotate-token', [DeviceApiController::class, 'rotateToken'],
        ['api', 'api.auth:devices.manage', 'tenant:auto', 'throttle:30,5'], 'api.devices.rotate');

    $router->post('/api/v1/devices/revoke', [DeviceApiController::class, 'revoke'],
        ['api', 'api.auth:devices.manage', 'tenant:auto', 'throttle:30,5'], 'api.devices.revoke');

    $router->get('/api/v1/devices/status', [DeviceApiController::class, 'status'],
        ['api', 'api.auth:devices.manage', 'tenant:auto', 'throttle:120,1'], 'api.devices.status');

    /* -----------------------------------------------------------------
     * MANAGER API - stage 6 (attendance)
     *
     * Read models over the REAL rows the engine wrote. The tenant comes from
     * the bearer token (tenant:auto), never from a parameter, so manager A can
     * never see the attendance of customer B.
     * ----------------------------------------------------------------*/
    $router->get('/api/v1/manager/attendance', [AttendanceApiController::class, 'index'],
        ['api', 'api.auth:attendance.view', 'tenant:auto', 'throttle:240,1'], 'api.manager.attendance');

    $router->get('/api/v1/manager/attendance/current', [AttendanceApiController::class, 'current'],
        ['api', 'api.auth:attendance.view', 'tenant:auto', 'throttle:240,1'], 'api.manager.attendance.current');

    $router->get('/api/v1/manager/employees/{id}/attendance', [AttendanceApiController::class, 'employee'],
        ['api', 'api.auth:attendance.view', 'tenant:auto', 'throttle:240,1'], 'api.manager.employees.attendance');

    /* -----------------------------------------------------------------
     * Reports / Excel / Payroll / Telegram API - stage 7
     *
     * The middleware only checks the bearer token + the role capability;
     * the FEATURE check happens in the controller (per customer), exactly
     * like the panel, so a disabled feature is refused on both channels.
     * ----------------------------------------------------------------*/
    $router->get('/api/v1/manager/reports', [ReportApiController::class, 'index'],
        ['api', 'api.auth:report.view', 'tenant:auto', 'throttle:240,1'], 'api.manager.reports');

    $router->get('/api/v1/manager/reports/export', [ReportApiController::class, 'export'],
        ['api', 'api.auth:report.export', 'tenant:auto', 'throttle:30,5'], 'api.manager.reports.export');

    $router->get('/api/v1/manager/payroll', [ReportApiController::class, 'payroll'],
        ['api', 'api.auth:payroll.view', 'tenant:auto', 'throttle:120,1'], 'api.manager.payroll');

    $router->post('/api/v1/manager/payroll/finalize', [ReportApiController::class, 'finalize'],
        ['api', 'api.auth:payroll.manage', 'tenant:auto', 'throttle:30,5'], 'api.manager.payroll.finalize');

    $router->post('/api/v1/manager/payroll/revise', [ReportApiController::class, 'revise'],
        ['api', 'api.auth:payroll.manage', 'tenant:auto', 'throttle:30,5'], 'api.manager.payroll.revise');

    $router->get('/api/v1/manager/telegram', [ReportApiController::class, 'telegram'],
        ['api', 'api.auth:telegram.view', 'tenant:auto', 'throttle:120,1'], 'api.manager.telegram');

    $router->post('/api/v1/manager/telegram/daily', [ReportApiController::class, 'telegramDaily'],
        ['api', 'api.auth:telegram.manage', 'tenant:auto', 'throttle:30,5'], 'api.manager.telegram.daily');

    $router->post('/api/v1/manager/telegram/drain', [ReportApiController::class, 'telegramDrain'],
        ['api', 'api.auth:telegram.manage', 'tenant:auto', 'throttle:60,5'], 'api.manager.telegram.drain');

    /* -----------------------------------------------------------------
     * OWNER (platform) API - stage 2
     *
     * Every route is: api -> bearer token -> owner role -> audit.
     * A manager token is rejected by 'api.owner' before the controller
     * runs, and every mutation writes the same audit action the web panel
     * writes, so the audit trail is identical for both channels.
     * ----------------------------------------------------------------*/
    $router->get('/api/v1/owner/overview', [OwnerApiController::class, 'overview'],
        ['api', 'api.auth', 'api.owner', 'throttle:240,1'], 'api.owner.overview');

    $router->get('/api/v1/owner/customers', [OwnerApiController::class, 'customers'],
        ['api', 'api.auth', 'api.owner', 'throttle:240,1'], 'api.owner.customers');
    $router->post('/api/v1/owner/customers', [OwnerApiController::class, 'createCustomer'],
        ['api', 'api.auth', 'api.owner', 'throttle:60,5'], 'api.owner.customers.store');
    $router->get('/api/v1/owner/customers/{uuid}', [OwnerApiController::class, 'customer'],
        ['api', 'api.auth', 'api.owner', 'throttle:240,1'], 'api.owner.customers.show');
    $router->add('PATCH', '/api/v1/owner/customers/{uuid}', [OwnerApiController::class, 'updateCustomer'],
        ['api', 'api.auth', 'api.owner', 'throttle:60,5'], 'api.owner.customers.update');
    $router->post('/api/v1/owner/customers/{uuid}/status', [OwnerApiController::class, 'customerStatus'],
        ['api', 'api.auth', 'api.owner', 'throttle:60,5'], 'api.owner.customers.status');
    $router->get('/api/v1/owner/customers/{uuid}/features', [OwnerApiController::class, 'features'],
        ['api', 'api.auth', 'api.owner', 'throttle:240,1'], 'api.owner.features');
    $router->add('PATCH', '/api/v1/owner/customers/{uuid}/features', [OwnerApiController::class, 'updateFeatures'],
        ['api', 'api.auth', 'api.owner', 'throttle:60,5'], 'api.owner.features.update');
    $router->get('/api/v1/owner/customers/{uuid}/subscription', [OwnerApiController::class, 'subscription'],
        ['api', 'api.auth', 'api.owner', 'throttle:240,1'], 'api.owner.subscription');
    $router->add('PATCH', '/api/v1/owner/customers/{uuid}/subscription', [OwnerApiController::class, 'updateSubscription'],
        ['api', 'api.auth', 'api.owner', 'throttle:60,5'], 'api.owner.subscription.update');

    $router->get('/api/v1/owner/managers', [OwnerApiController::class, 'managers'],
        ['api', 'api.auth', 'api.owner', 'throttle:240,1'], 'api.owner.managers');
    $router->post('/api/v1/owner/managers', [OwnerApiController::class, 'createManager'],
        ['api', 'api.auth', 'api.owner', 'throttle:60,5'], 'api.owner.managers.store');
    $router->add('PATCH', '/api/v1/owner/managers/{id}', [OwnerApiController::class, 'updateManager'],
        ['api', 'api.auth', 'api.owner', 'throttle:60,5'], 'api.owner.managers.update');
    $router->post('/api/v1/owner/managers/{id}/password', [OwnerApiController::class, 'managerPassword'],
        ['api', 'api.auth', 'api.owner', 'throttle:30,5'], 'api.owner.managers.password');

    $router->get('/api/v1/owner/devices', [OwnerApiController::class, 'devices'],
        ['api', 'api.auth', 'api.owner', 'throttle:240,1'], 'api.owner.devices');
    $router->post('/api/v1/owner/devices/{id}/reset', [OwnerApiController::class, 'deviceReset'],
        ['api', 'api.auth', 'api.owner', 'throttle:30,5'], 'api.owner.devices.reset');
    $router->post('/api/v1/owner/devices/{id}/status', [OwnerApiController::class, 'deviceStatus'],
        ['api', 'api.auth', 'api.owner', 'throttle:60,5'], 'api.owner.devices.status');

    $router->get('/api/v1/owner/logs', [OwnerApiController::class, 'logs'],
        ['api', 'api.auth', 'api.owner', 'throttle:240,1'], 'api.owner.logs');
};
