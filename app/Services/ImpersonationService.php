<?php
/**
 * SARMAD Attendance - emergency access (owner entering a customer panel).
 *
 * HOW IT WORKS
 * ---------------------------------------------------------------------
 * 1. Only a real owner may start it (the role is re-checked here, not only
 *    in the route middleware).
 * 2. A reason is mandatory; without a reason nothing happens.
 * 3. A row is opened in attendance_owner_impersonations and the session
 *    keeps the ORIGINAL role in `auth` plus an `impersonation` payload.
 *    The effective role becomes the customer manager, so the owner sees
 *    exactly what that customer sees - nothing more.
 * 4. Every page load while impersonating shows a permanent banner and the
 *    exit action closes the row (duration + reason + action counter).
 * 5. The owner can never see the manager's password: the password hash is
 *    never loaded into the session payload and no screen renders it.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Session;
use App\Models\Customer;
use App\Models\Impersonation;
use App\Models\Manager;
use App\Models\Role;
use App\Models\User;
use RuntimeException;

final class ImpersonationService
{
    public const SESSION_KEY = 'impersonation';

    /**
     * Is emergency access available at all (global setting)?
     */
    public static function enabled(): bool
    {
        $value = \App\Models\Setting::get('owner.impersonation_enabled', '1');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Start emergency access for one customer.
     *
     * @return array{ok:bool,message:string,impersonation_id:int|customer_id:int,manager?:array<string,mixed>}
     */
    public function start(int $customerId, string $reason, int $ownerUserId): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => 'دسترسی اضطراری در تنظیمات سامانه غیرفعال است.', 'impersonation_id' => 0];
        }

        $owner = User::find($ownerUserId);

        if ($owner === null || (string) $owner['role'] !== Role::OWNER) {
            Logger::channel('security')->warning('Impersonation attempted by a non owner account', [
                'user_id' => $ownerUserId,
            ]);

            return ['ok' => false, 'message' => 'فقط مالک سامانه می‌تواند از دسترسی اضطراری استفاده کند.', 'impersonation_id' => 0];
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            return ['ok' => false, 'message' => 'ثبت دلیل (حداقل ۵ کاراکتر) برای دسترسی اضطراری الزامی است.', 'impersonation_id' => 0];
        }

        $customer = Customer::find($customerId);

        if ($customer === null) {
            return ['ok' => false, 'message' => 'مشتری یافت نشد.', 'impersonation_id' => 0];
        }

        // Close any open session of this owner first (single active scope).
        $this->stop('superseded', false);

        $manager     = Manager::primaryFor($customerId);
        $managerUser = null;

        if ($manager !== null && !empty($manager['user_id'])) {
            $candidate = User::find((int) $manager['user_id']);

            if ($candidate !== null && (int) $candidate['is_active'] === 1) {
                $managerUser = $candidate;
                // Deliberately NOT User::sessionPayload(): that payload is
                // used for real logins. Only the identity is kept here.
            }
        }

        $id = Impersonation::open([
            'customer_id'     => $customerId,
            'owner_user_id'   => $ownerUserId,
            'manager_user_id' => $managerUser !== null ? (int) $managerUser['id'] : null,
            'reason'          => mb_substr($reason, 0, 255),
            'ip'              => \App\Core\Request::ip(),
            'user_agent'      => \App\Core\Request::userAgent(),
            'session_id'      => Session::id(),
        ]);

        Session::put(self::SESSION_KEY, [
            'id'              => $id,
            'customer_id'     => $customerId,
            'customer_name'   => (string) $customer['name'],
            'manager_user_id' => $managerUser !== null ? (int) $managerUser['id'] : null,
            'reason'          => mb_substr($reason, 0, 255),
            'started_at'      => date('Y-m-d H:i:s'),
        ]);

        AuditService::log(
            'impersonation.start',
            'success',
            'دسترسی اضطراری مالک به پنل مشتری «' . (string) $customer['name'] . '» آغاز شد. دلیل: ' . $reason,
            [
                'impersonation_id' => $id,
                'owner_user_id'    => $ownerUserId,
                'customer_id'      => $customerId,
                'manager_user_id'  => $managerUser !== null ? (int) $managerUser['id'] : null,
                'reason'           => $reason,
                'ip'               => \App\Core\Request::ip(),
            ],
            'security',
            'customer',
            $customerId,
            $customerId
        );

        return [
            'ok'                => true,
            'message'           => 'دسترسی اضطراری فعال شد؛ همه اقدامات شما ثبت می‌شود.',
            'impersonation_id'  => $id,
            'customer'          => $customer,
            'manager'           => $manager,
        ];
    }

    /**
     * End emergency access (or the session was superseded).
     */
    public function stop(string $reason = 'manual', bool $audit = true): bool
    {
        $payload = Session::get(self::SESSION_KEY);

        if (!is_array($payload) || empty($payload['id'])) {
            return false;
        }

        $id         = (int) $payload['id'];
        $customerId = (int) ($payload['customer_id'] ?? 0);

        Impersonation::close($id, substr($reason, 0, 120));

        Session::put(self::SESSION_KEY, null);
        unset($_SESSION['app_' . self::SESSION_KEY]);

        if ($audit) {
            AuditService::log(
                'impersonation.end',
                'success',
                'دسترسی اضطراری پایان یافت (' . $reason . ').',
                [
                    'impersonation_id' => $id,
                    'customer_id'      => $customerId,
                    'reason'           => $reason,
                ],
                'security',
                'customer',
                $customerId > 0 ? $customerId : null,
                $customerId > 0 ? $customerId : null
            );
        }

        return true;
    }

    /**
     * Current emergency access payload (or null).
     *
     * @return array<string,mixed>|null
     */
    public static function current(): ?array
    {
        $payload = Session::get(self::SESSION_KEY);

        return is_array($payload) && !empty($payload['id']) ? $payload : null;
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }

    public static function customerId(): ?int
    {
        $payload = self::current();

        return $payload === null ? null : (int) $payload['customer_id'];
    }

    /**
     * Count one action performed while impersonating (used for reporting).
     */
    public static function countAction(): void
    {
        $payload = self::current();

        if ($payload === null) {
            return;
        }

        try {
            Impersonation::touchAction((int) $payload['id']);
        } catch (\Throwable $e) {
            Logger::channel('security')->warning('Failed to count impersonated action', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Rows shown in the owner panel.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function history(int $limit = 50, int $offset = 0, ?int $customerId = null): array
    {
        return Impersonation::recent($limit, $offset, $customerId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function openSessions(): array
    {
        return Impersonation::openSessions();
    }

    public static function guardRealOwner(): void
    {
        $user = Session::user();

        if ($user === null || (string) $user['role'] !== Role::OWNER) {
            throw new RuntimeException('Owner role required.');
        }
    }
}
