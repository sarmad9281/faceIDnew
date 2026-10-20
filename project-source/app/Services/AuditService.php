<?php
/**
 * SARMAD Attendance - canonical audit actions (stage 2).
 *
 * The audit table keeps two identifiers on purpose:
 *
 *   event   - the fine grained technical event used since stage 1
 *             ("customer.create", "auth.login.failed", ...)
 *   action  - the canonical, upper case business action of the owner
 *             panel contract (CREATE_CUSTOMER, LOGIN, RESET_DEVICE, ...)
 *
 * The owner log page filters on `action`, the technical log keeps every
 * detail of `event`, so nothing is lost and no existing row breaks.
 */

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;

final class AuditService
{
    public const LOGIN               = 'LOGIN';
    public const LOGOUT              = 'LOGOUT';
    public const LOGIN_FAILED        = 'LOGIN_FAILED';
    public const CREATE_CUSTOMER     = 'CREATE_CUSTOMER';
    public const UPDATE_CUSTOMER     = 'UPDATE_CUSTOMER';
    public const ENABLE_CUSTOMER     = 'ENABLE_CUSTOMER';
    public const DISABLE_CUSTOMER    = 'DISABLE_CUSTOMER';
    public const DELETE_CUSTOMER     = 'DELETE_CUSTOMER';
    public const CREATE_MANAGER      = 'CREATE_MANAGER';
    public const UPDATE_MANAGER      = 'UPDATE_MANAGER';
    public const DISABLE_MANAGER     = 'DISABLE_MANAGER';
    public const ENABLE_MANAGER      = 'ENABLE_MANAGER';
    public const RESET_MANAGER_PASSWORD = 'RESET_MANAGER_PASSWORD';
    public const CREATE_DEVICE       = 'CREATE_DEVICE';
    public const UPDATE_DEVICE       = 'UPDATE_DEVICE';
    public const ENABLE_DEVICE       = 'ENABLE_DEVICE';
    public const DISABLE_DEVICE      = 'DISABLE_DEVICE';
    public const RESET_DEVICE        = 'RESET_DEVICE';
    public const DELETE_DEVICE       = 'DELETE_DEVICE';
    public const CHANGE_FEATURE      = 'CHANGE_FEATURE';
    public const CHANGE_SUBSCRIPTION = 'CHANGE_SUBSCRIPTION';
    public const UPDATE_SETTINGS     = 'UPDATE_SETTINGS';
    public const UPLOAD_LOGO         = 'UPLOAD_LOGO';
    public const EMERGENCY_ACCESS    = 'EMERGENCY_ACCESS';
    public const EMERGENCY_ACCESS_END = 'EMERGENCY_ACCESS_END';
    public const ACCESS_DENIED       = 'ACCESS_DENIED';
    public const DEVICE_AUTH         = 'DEVICE_AUTH';
    public const DEVICE_REGISTER     = 'DEVICE_REGISTER';
    public const ISSUE_ACTIVATION_CODE = 'ISSUE_ACTIVATION_CODE';
    public const DEVICE_ACTIVATION   = 'DEVICE_ACTIVATION';
    public const ACTIVATION_FAILED   = 'ACTIVATION_FAILED';
    public const UPDATE_DEVICE_SETTINGS = 'UPDATE_DEVICE_SETTINGS';
    public const DEVICE_ERROR        = 'DEVICE_ERROR';
    /* stage 5 - face recognition */
    public const FACE_ENROLLED       = 'FACE_ENROLLED';
    public const FACE_REJECTED       = 'FACE_REJECTED';
    public const FACE_RESET          = 'FACE_RESET';
    public const FACE_DATA_SYNC      = 'FACE_DATA_SYNC';
    public const RECOGNITION_MATCHED  = 'RECOGNITION_MATCHED';
    public const RECOGNITION_UNKNOWN  = 'RECOGNITION_UNKNOWN';
    public const RECOGNITION_AMBIGUOUS = 'RECOGNITION_AMBIGUOUS';
    public const SCHEMA_MIGRATION    = 'SCHEMA_MIGRATION';
    public const CREATE_BRANCH       = 'CREATE_BRANCH';
    public const REVOKE_SESSIONS     = 'REVOKE_SESSIONS';
    public const MAINTENANCE         = 'MAINTENANCE';
    public const CREATE_API_TOKEN    = 'CREATE_API_TOKEN';
    public const REVOKE_API_TOKEN    = 'REVOKE_API_TOKEN';
    public const API_ACCESS          = 'API_ACCESS';
    public const UPDATE_BRANCH       = 'UPDATE_BRANCH';
    public const DELETE_BRANCH       = 'DELETE_BRANCH';
    /* stage 3 - manager panel */
    public const CREATE_EMPLOYEE      = 'CREATE_EMPLOYEE';
    public const UPDATE_EMPLOYEE      = 'UPDATE_EMPLOYEE';
    public const ENABLE_EMPLOYEE      = 'ENABLE_EMPLOYEE';
    public const DISABLE_EMPLOYEE     = 'DISABLE_EMPLOYEE';
    public const REGISTER_FACE_SAMPLE = 'REGISTER_FACE_SAMPLE';
    public const DELETE_FACE_SAMPLE   = 'DELETE_FACE_SAMPLE';
    public const CREATE_SHIFT         = 'CREATE_SHIFT';
    public const UPDATE_SHIFT         = 'UPDATE_SHIFT';
    public const DELETE_SHIFT         = 'DELETE_SHIFT';
    public const ASSIGN_SHIFT         = 'ASSIGN_SHIFT';
    public const CREATE_LEAVE         = 'CREATE_LEAVE';
    public const UPDATE_LEAVE         = 'UPDATE_LEAVE';
    public const DECIDE_LEAVE         = 'DECIDE_LEAVE';
    public const DELETE_LEAVE         = 'DELETE_LEAVE';
    public const CREATE_HOLIDAY       = 'CREATE_HOLIDAY';
    public const UPDATE_HOLIDAY       = 'UPDATE_HOLIDAY';
    public const DELETE_HOLIDAY       = 'DELETE_HOLIDAY';
    public const UPDATE_PAYROLL       = 'UPDATE_PAYROLL';
    public const UPDATE_TELEGRAM      = 'UPDATE_TELEGRAM';

    /**
     * event (technical) => [action (canonical), Persian title]
     *
     * @var array<string,array{0:string,1:string}>
     */
    private const MAP = [
        'auth.login.success'        => [self::LOGIN, 'ورود موفق'],
        'auth.login.failed'         => [self::LOGIN_FAILED, 'ورود ناموفق'],
        'auth.logout'               => [self::LOGOUT, 'خروج از سامانه'],
        'customer.create'           => [self::CREATE_CUSTOMER, 'ایجاد مشتری'],
        'customer.update'           => [self::UPDATE_CUSTOMER, 'ویرایش مشتری'],
        'customer.enable'           => [self::ENABLE_CUSTOMER, 'فعال‌سازی مشتری'],
        'customer.disable'          => [self::DISABLE_CUSTOMER, 'غیرفعال‌سازی مشتری'],
        'customer.delete'           => [self::DELETE_CUSTOMER, 'حذف مشتری'],
        'customer.logo'             => [self::UPLOAD_LOGO, 'بارگذاری لوگو'],
        'manager.create'            => [self::CREATE_MANAGER, 'ایجاد مدیر مشتری'],
        'manager.update'            => [self::UPDATE_MANAGER, 'ویرایش مدیر مشتری'],
        'manager.enable'            => [self::ENABLE_MANAGER, 'فعال‌سازی مدیر'],
        'manager.disable'           => [self::DISABLE_MANAGER, 'غیرفعال‌سازی مدیر'],
        'manager.password.reset'    => [self::RESET_MANAGER_PASSWORD, 'تغییر رمز مدیر'],
        'user.create'               => [self::CREATE_MANAGER, 'ایجاد کاربر'],
        'device.register'           => [self::CREATE_DEVICE, 'ثبت دستگاه'],
        'device.update'             => [self::UPDATE_DEVICE, 'ویرایش دستگاه'],
        'device.activate'           => [self::ENABLE_DEVICE, 'فعال‌سازی دستگاه'],
        'device.disable'            => [self::DISABLE_DEVICE, 'غیرفعال‌سازی دستگاه'],
        'device.reset'              => [self::RESET_DEVICE, 'ریست دستگاه'],
        'device.token.reset'        => [self::RESET_DEVICE, 'ریست توکن دستگاه'],
        'device.revoke'             => [self::DELETE_DEVICE, 'لغو ثبت دستگاه'],
        'device.delete'             => [self::DELETE_DEVICE, 'حذف دستگاه'],
        'device.auth.success'       => [self::DEVICE_AUTH, 'احراز هویت دستگاه'],
        'device.auth.failed'        => [self::DEVICE_AUTH, 'احراز هویت ناموفق دستگاه'],
        'device.activation.code_issued' => [self::ISSUE_ACTIVATION_CODE, 'صدور کد فعال‌سازی دستگاه'],
        'device.activation.success' => [self::DEVICE_ACTIVATION, 'فعال‌سازی دستگاه با کد'],
        'device.activation.failed'  => [self::ACTIVATION_FAILED, 'فعال‌سازی ناموفق دستگاه'],
        'device.settings.update'    => [self::UPDATE_DEVICE_SETTINGS, 'تنظیمات دستگاه'],
        'device.connection.error'   => [self::DEVICE_ERROR, 'خطای ارتباط دستگاه'],
        'device.recognition.error'  => [self::DEVICE_ERROR, 'خطای تشخیص چهره'],
        /* stage 5 - face recognition (device + manager panel) */
        'employee.face.enrolled'    => [self::FACE_ENROLLED, 'ثبت نمونه چهره'],
        'employee.face.rejected'    => [self::FACE_REJECTED, 'رد نمونه چهره'],
        'employee.face.reset'       => [self::FACE_RESET, 'بازنشانی ثبت چهره'],
        'device.face.sync'          => [self::FACE_DATA_SYNC, 'هم‌گام‌سازی داده چهره دستگاه'],
        'device.recognition.matched' => [self::RECOGNITION_MATCHED, 'شناسایی موفق چهره'],
        'device.recognition.unknown' => [self::RECOGNITION_UNKNOWN, 'چهره ناشناس'],
        'device.recognition.ambiguous' => [self::RECOGNITION_AMBIGUOUS, 'شناسایی مبهم چهره'],
        'feature.update'            => [self::CHANGE_FEATURE, 'تغییر امکانات مشتری'],
        'subscription.update'       => [self::CHANGE_SUBSCRIPTION, 'تغییر اشتراک'],
        'settings.update'           => [self::UPDATE_SETTINGS, 'تغییر تنظیمات'],
        'impersonation.start'       => [self::EMERGENCY_ACCESS, 'دسترسی اضطراری'],
        'impersonation.end'         => [self::EMERGENCY_ACCESS_END, 'پایان دسترسی اضطراری'],
        'access.denied'             => [self::ACCESS_DENIED, 'دسترسی رد شد'],
        'branch.create'             => [self::CREATE_BRANCH, 'ایجاد شعبه'],
        'branch.update'             => [self::UPDATE_BRANCH, 'ویرایش شعبه'],
        'branch.delete'             => [self::DELETE_BRANCH, 'حذف شعبه'],
        'schema.migrate'            => [self::SCHEMA_MIGRATION, 'به‌روزرسانی ساختار دیتابیس'],
        'subscription.change'       => [self::CHANGE_SUBSCRIPTION, 'تغییر اشتراک'],
        'customer.sessions_revoked' => [self::REVOKE_SESSIONS, 'پایان دادن به نشست‌های مشتری'],
        'settings.maintenance'      => [self::MAINTENANCE, 'عملیات نگهداری سامانه'],
        'api.token.issue'           => [self::CREATE_API_TOKEN, 'صدور توکن API'],
        'api.token.issued'          => [self::CREATE_API_TOKEN, 'صدور توکن API'],
        'api.token.revoke'          => [self::REVOKE_API_TOKEN, 'ابطال توکن API'],
        'api.token.revoked'         => [self::REVOKE_API_TOKEN, 'ابطال توکن API'],
        'api.auth.success'          => [self::API_ACCESS, 'دسترسی API'],
        // stage 3 - manager panel
        'employee.create'           => [self::CREATE_EMPLOYEE, 'ایجاد پرسنل'],
        'employee.update'           => [self::UPDATE_EMPLOYEE, 'ویرایش پرسنل'],
        'employee.photo'            => [self::UPDATE_EMPLOYEE, 'ویرایش تصویر پرسنل'],
        'employee.enable'           => [self::ENABLE_EMPLOYEE, 'فعال‌سازی پرسنل'],
        'employee.disable'          => [self::DISABLE_EMPLOYEE, 'غیرفعال‌سازی پرسنل'],
        'employee.face.store'       => [self::REGISTER_FACE_SAMPLE, 'ثبت نمونه چهره'],
        'employee.face.delete'      => [self::DELETE_FACE_SAMPLE, 'حذف نمونه چهره'],
        'shift.create'              => [self::CREATE_SHIFT, 'ایجاد شیفت'],
        'shift.update'              => [self::UPDATE_SHIFT, 'ویرایش شیفت'],
        'shift.toggle'              => [self::UPDATE_SHIFT, 'تغییر وضعیت شیفت'],
        'shift.delete'              => [self::DELETE_SHIFT, 'حذف شیفت'],
        'shift.assign'              => [self::ASSIGN_SHIFT, 'اختصاص شیفت به پرسنل'],
        'shift.unassign'            => [self::ASSIGN_SHIFT, 'برداشتن شیفت از پرسنل'],
        'leave.create'              => [self::CREATE_LEAVE, 'ثبت مرخصی'],
        'leave.update'              => [self::UPDATE_LEAVE, 'ویرایش مرخصی'],
        'leave.decide'              => [self::DECIDE_LEAVE, 'تصمیم مرخصی'],
        'leave.delete'              => [self::DELETE_LEAVE, 'حذف مرخصی'],
        'holiday.create'            => [self::CREATE_HOLIDAY, 'ثبت تعطیلی'],
        'holiday.update'            => [self::UPDATE_HOLIDAY, 'ویرایش تعطیلی'],
        'holiday.toggle'            => [self::UPDATE_HOLIDAY, 'تغییر وضعیت تعطیلی'],
        'holiday.delete'            => [self::DELETE_HOLIDAY, 'حذف تعطیلی'],
        'payroll.profile.save'      => [self::UPDATE_PAYROLL, 'ثبت اطلاعات حقوقی پرسنل'],
        'payroll.rules.update'      => [self::UPDATE_PAYROLL, 'تغییر قواعد کارکرد و حقوق'],
        'telegram.settings.update'  => [self::UPDATE_TELEGRAM, 'تغییر تنظیمات تلگرام'],
        'customer.profile.update'   => [self::UPDATE_CUSTOMER, 'ویرایش اطلاعات کسب‌وکار'],
    ];

    /**
     * @return array<string,string> action => Persian title
     */
    public static function actions(): array
    {
        $list = [];

        foreach (self::MAP as $entry) {
            $list[$entry[0]] = $entry[1];
        }

        // Stable, alphabetical-by-action output.
        ksort($list);

        return $list;
    }

    public static function titleFor(string $action): string
    {
        $actions = self::actions();

        return $actions[$action] ?? $action;
    }

    public static function actionFor(string $event): string
    {
        if (isset(self::MAP[$event])) {
            return self::MAP[$event][0];
        }

        // Unmapped events still get a Canonical form: "customer.archive" ->
        // CUSTOMER_ARCHIVE, so future code never needs a schema change.
        $upper = strtoupper(str_replace(['.', '-'], '_', $event));

        return substr(preg_replace('/[^A-Z0-9_]/', '', $upper) ?? 'UNKNOWN', 0, 60);
    }

    /**
     * Write an audit row using the canonical action vocabulary.
     *
     * @param array<string,mixed> $meta
     */
    public static function log(
        string $event,
        string $result,
        string $description,
        array $meta = [],
        string $channel = 'app',
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $customerId = null
    ): void {
        AuditLog::record($event, $result, $description, $meta, $channel, $entityType, $entityId, $customerId);
    }
}
