<?php
/**
 * SARMAD Attendance - installation engine.
 *
 * ---------------------------------------------------------------------
 * SAFETY CONTRACT (shared database)
 * ---------------------------------------------------------------------
 *  1. Only statements that pass App\Core\SqlGuard are executed.
 *  2. Only tables named  attendance_*  are created or written.
 *  3. Foreign project tables are never read, written, renamed or dropped;
 *     they are only *listed* (information_schema) so the operator can see
 *     they were detected and deliberately left alone.
 *  4. The installer locks itself (storage/installed.lock + config flag).
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Security;
use App\Core\SqlGuard;
use App\Models\Customer;
use App\Models\Manager;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use PDO;
use RuntimeException;
use Throwable;

final class InstallerService
{
    /**
     * Bumped whenever the database/installer path changes, so the operator
     * (and support) can see WHICH code is really running on the host - a
     * stale upload or a cached opcode is ruled out in one look.
     */
    public const BUILD = '2026-10-21.1';

    public const REQUIRED_TABLES = [
        // stage 1
        'roles', 'customers', 'managers', 'users', 'branches', 'employees',
        'devices', 'device_sessions', 'settings', 'audit_logs', 'login_attempts',
        'api_tokens', 'api_request_logs', 'password_resets', 'schema_migrations',
        // stage 2 (owner panel)
        'features', 'feature_flags', 'subscriptions', 'owner_impersonations',
        // stage 3 (manager panel)
        'shifts', 'employee_shifts', 'employee_faces', 'records',
        'leaves', 'holidays', 'payroll_profiles', 'customer_settings',
        // stage 4 (device / kiosk / pwa)
        'device_activation_codes', 'device_settings', 'device_logs', 'device_requests',
    ];

    /**
     * Base schema file. Stage 2 (and later) files are applied afterwards by
     * App\Services\MigrationService, which records them in
     * attendance_schema_migrations so a re-run is a no-op.
     */
    public const SCHEMA_FILES = [
        'sql/attendance_install.sql',
    ];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? dirname(__DIR__, 2), '/');
    }

    /* =================================================================
     * 1) Pre-flight checks
     * ===============================================================*/

    /**
     * @return array<int,array{label:string, ok:bool, detail:string, fatal:bool}>
     */
    public function requirements(): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'نسخه PHP (حداقل 8.1)',
            'ok'    => PHP_VERSION_ID >= 80100,
            'detail' => PHP_VERSION,
            'fatal' => true,
        ];

        $checks[] = [
            'label'  => 'افزونه PDO MySQL',
            'ok'     => extension_loaded('pdo_mysql'),
            'detail' => extension_loaded('pdo_mysql') ? 'فعال' : 'غیرفعال',
            'fatal'  => true,
        ];

        $checks[] = [
            'label'  => 'افزونه mbstring (پشتیبانی فارسی)',
            'ok'     => extension_loaded('mbstring'),
            'detail' => extension_loaded('mbstring') ? 'فعال' : 'غیرفعال (اختیاری اما توصیه می‌شود)',
            'fatal'  => false,
        ];

        $checks[] = [
            'label'  => 'افزونه OpenSSL (رمزنگاری کلید برنامه)',
            'ok'     => extension_loaded('openssl'),
            'detail' => extension_loaded('openssl') ? 'فعال' : 'غیرفعال',
            'fatal'  => true,
        ];

        $checks[] = [
            'label'  => 'افزونه JSON',
            'ok'     => function_exists('json_encode') && function_exists('json_decode'),
            'detail' => function_exists('json_encode') ? 'فعال' : 'غیرفعال',
            'fatal'  => true,
        ];

        $checks[] = [
            'label'  => 'تابع password_hash (bcrypt)',
            'ok'     => function_exists('password_hash'),
            'detail' => function_exists('password_hash') ? 'فعال' : 'غیرفعال',
            'fatal'  => true,
        ];

        $checks[] = [
            'label'  => 'تابع random_bytes',
            'ok'     => function_exists('random_bytes'),
            'detail' => function_exists('random_bytes') ? 'فعال' : 'غیرفعال',
            'fatal'  => true,
        ];

        foreach (['config', 'storage', 'storage/logs', 'storage/tmp', 'storage/cache', 'uploads'] as $directory) {
            $path      = $this->root . '/' . $directory;
            $writable  = is_dir($path) && is_writable($path);

            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
                $writable = is_dir($path) && is_writable($path);
            }

            $checks[] = [
                'label'  => 'قابل نوشتن بودن ' . $directory . '/',
                'ok'     => $writable,
                'detail' => $writable ? 'قابل نوشتن' : 'غیرقابل نوشتن - دسترسی 755 لازم است',
                'fatal'  => in_array($directory, ['config', 'storage'], true),
            ];
        }

        $checks[] = [
            'label'  => 'نصب بودن مجدد (فایل قفل)',
            'ok'     => !is_file($this->root . '/storage/installed.lock'),
            'detail' => is_file($this->root . '/storage/installed.lock')
                ? 'سامانه قبلاً نصب شده است'
                : 'نصب نشده - آماده راه‌اندازی',
            'fatal'  => true,
        ];

        return $checks;
    }

    public function requirementsPassed(): bool
    {
        foreach ($this->requirements() as $check) {
            if ($check['fatal'] && !$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /* =================================================================
     * 2) Database verification
     * ===============================================================*/

    /**
     * Verify the credentials, verify the database exists and report the
     * tables of THIS project plus the foreign tables (read only).
     *
     * @param array<string,mixed> $credentials
     * @return array{ok:bool, message:string, server?:string, database?:string, project_tables?:array<int,string>, foreign_tables?:array<int,string>, missing?:array<int,string>}
     */
    public function verifyDatabase(array $credentials): array
    {
        $prefix = (string) ($credentials['prefix'] ?? 'attendance_');

        try {
            Database::assertPrefix($prefix);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $attempt = $this->attemptDatabase($credentials);

        if ($attempt['ok']) {
            return $attempt;
        }

        // Shared hosts are inconsistent about the meaning of "localhost":
        // the same account can be accepted over the unix socket (host
        // "localhost") and refused over TCP (host "127.0.0.1") - and the
        // other way round. Instead of making the operator guess which one
        // their panel created, both are probed with the SAME credentials and
        // the one that really works is used and stored.
        $alternate = self::alternateHost((string) ($credentials['host'] ?? ''));

        if ($alternate !== null) {
            $second = $this->attemptDatabase(array_merge($credentials, ['host' => $alternate]));

            if ($second['ok']) {
                $second['host']    = $alternate;
                $second['message'] = 'اتصال با هاست «' . (string) $credentials['host'] . '» برقرار نشد، '
                    . 'اما با «' . $alternate . '» موفق بود؛ همین مقدار در config/config.php ذخیره می‌شود.';

                Logger::channel('installer')->info('Installer used the alternate database host', [
                    'configured_host' => (string) $credentials['host'],
                    'working_host'    => $alternate,
                ]);

                return $second;
            }

            $attempt['message'] .= ' (هر دو حالت «localhost» و «127.0.0.1» با همین اطلاعات آزمایش شد و هیچ‌کدام پذیرفته نشد.)';
        }

        return $attempt;
    }

    /**
     * The two addresses that a local MySQL/MariaDB can be reached by: the
     * unix socket (localhost) and the loopback TCP interface (127.0.0.1).
     * Any other host value is left alone - a remote database is the
     * operator's decision and is never silently rewritten.
     */
    private static function alternateHost(string $host): ?string
    {
        $host = trim($host);

        if (strcasecmp($host, 'localhost') === 0) {
            return '127.0.0.1';
        }

        if ($host === '127.0.0.1') {
            return 'localhost';
        }

        return null;
    }

    /**
     * One full verification pass against one host.
     *
     * @param array<string,mixed> $credentials
     * @return array{ok:bool, message:string, server?:string, database?:string, project_tables?:array<int,string>, foreign_tables?:array<int,string>, missing?:array<int,string>, pdo?:bool, server_ok?:bool, stage?:string, code?:string}
     */
    private function attemptDatabase(array $credentials): array
    {
        $prefix = (string) ($credentials['prefix'] ?? 'attendance_');

        Database::setPrefix($prefix);

        /*
         * Step A - SERVER level probe (DSN without dbname).
         *
         * This separates two failures that a single "with dbname" connection
         * cannot tell apart on shared hosting:
         *   1. the account itself is refused (wrong user/password/host), and
         *   2. the account is fine but was never added (granted) to the one
         *      specific database this project must use (DirectAdmin creates
         *      users and databases separately until "Add User to Database").
         * Case 2 needs a completely different fix in the panel, so it gets
         * its own, unambiguous message below instead of a generic 1045 hint.
         */
        try {
            $serverPdo     = Database::connectServer(array_merge($credentials, ['prefix' => $prefix]));
            $serverVersion = (string) $serverPdo->query('SELECT VERSION()')->fetchColumn();
            $serverPdo     = null;
        } catch (Throwable $e) {
            $safe = $this->redact((string) $e->getMessage(), $credentials);
            $hint = $this->diagnoseServerRefusal($credentials, $e);

            Logger::channel('installer')->error('Installer could not connect to the database server', [
                'error' => $safe,
                'hint'  => $hint,
                'stage' => 'server',
            ]);

            return [
                'ok'        => false,
                'server_ok' => false,
                'stage'     => 'server',
                'message'   => 'اتصال به سرور دیتابیس برقرار نشد: ' . $safe . ' — ' . $hint
                    . $this->whitespaceWarning($credentials),
            ];
        }

        /*
         * Step B - DATABASE level connection (with dbname in DSN). The server
         * already accepted these exact credentials, so a refusal here can only
         * mean "no grant on this specific database" (1044/1045) or "this
         * database does not exist" (1049) - never a wrong password.
         */
        try {
            $pdo = Database::connect(array_merge($credentials, ['prefix' => $prefix]));
            Database::adopt($pdo);
        } catch (Throwable $e) {
            $safe = $this->redact((string) $e->getMessage(), $credentials);

            Logger::channel('installer')->error('Installer reached the server but not the database', [
                'error'    => $safe,
                'stage'    => 'database',
                'database' => (string) ($credentials['database'] ?? ''),
                'username' => (string) ($credentials['username'] ?? ''),
            ]);

            return [
                'ok'        => false,
                'server_ok' => true,
                'stage'     => 'database',
                'code'      => self::isUnknownDatabaseError($e) ? 'UNKNOWN_DATABASE' : 'DB_ACCESS_DENIED',
                'message'   => $this->databaseAccessMessage($credentials, $safe, $e),
            ];
        }

        $projectTables = Database::projectTables();
        $foreignTables = Database::foreignTables();
        $missing       = [];

        foreach (self::REQUIRED_TABLES as $short) {
            if (!in_array($prefix . $short, $projectTables, true)) {
                $missing[] = $prefix . $short;
            }
        }

        return [
            'ok'             => true,
            'server_ok'      => true,
            'message'        => 'اتصال به دیتابیس با موفقیت برقرار شد.',
            'server'         => $serverVersion,
            'host'           => (string) ($credentials['host'] ?? 'localhost'),
            'database'       => (string) $credentials['database'],
            'project_tables' => $projectTables,
            'foreign_tables' => $foreignTables,
            'missing'        => $missing,
            'pdo'            => $pdo instanceof PDO,
        ];
    }

    /** MySQL/MariaDB 1049 = the named database does not exist at all. */
    private static function isUnknownDatabaseError(Throwable $error): bool
    {
        $message = (string) $error->getMessage();

        return strpos($message, '1049') !== false || stripos($message, 'Unknown database') !== false;
    }

    /**
     * Message for "the server accepted the login, the database did not".
     *
     * On DirectAdmin a MySQL user and a MySQL database are two separate
     * objects: creating both is NOT enough, the user must also be added to
     * the database (MySQL Management -> Add User to Database) before any
     * "dbname=..." connection or CREATE TABLE can succeed. Because step A
     * already proved user/password/host are right, this message can say so
     * with certainty instead of sending the operator to reset a password
     * that was never wrong. The secret itself is never included.
     *
     * @param array<string,mixed> $credentials
     */
    private function databaseAccessMessage(array $credentials, string $safeError, Throwable $error): string
    {
        $database = (string) ($credentials['database'] ?? '');
        $username = (string) ($credentials['username'] ?? '');

        if (self::isUnknownDatabaseError($error)) {
            return 'اتصال به سرور MySQL/MariaDB با موفقیت برقرار شد، اما دیتابیسی با نام «' . $database . '» پیدا نشد'
                . ' (' . $safeError . '). '
                . 'در DirectAdmin ← MySQL Management مطمئن شوید دیتابیس «' . $database . '» ساخته شده و نام آن دقیقاً همین است، '
                . 'سپس این مرحله را دوباره اجرا کنید. هیچ جدولی ایجاد یا تغییر نکرده است.';
        }

        return 'اتصال به سرور MySQL/MariaDB با کاربر «' . $username . '» موفق بود، اما دسترسی به دیتابیس «' . $database . '» رد شد'
            . ' (' . $safeError . '). '
            . 'یعنی نام کاربری و رمز عبور در سطح سرور درست است، ولی این کاربر به همین دیتابیس خاص دسترسی ندارد. '
            . 'در DirectAdmin ← MySQL Management ← Add User to Database کاربر «' . $username . '» را به دیتابیس «' . $database . '» '
            . 'اضافه کنید (برای مرحلهٔ نصب، ALL PRIVILEGES لازم است تا جداول با پیشوند attendance_ ساخته شوند)، '
            . 'سپس همین مرحله را دوباره اجرا کنید. هیچ جدولی ایجاد یا تغییر نکرده است.';
    }

    /**
     * Turn a refused connection into an actionable hint WITHOUT repeating the
     * secret.
     *
     * MySQL answers "Access denied for user 'x'@'host' (using password: YES)"
     * when the account exists but the password does not match, and the same
     * error with "(using password: NO)" when the account itself is unknown or
     * has no grant on the database. That distinction is the whole difference
     * between "reset the password in the panel" and "create / link the user"
     * on shared hosting (DirectAdmin), so it is probed with an EMPTY password:
     * the real secret is never sent again and never printed anywhere.
     *
     * @param array<string,mixed> $credentials
     */
    private function diagnoseServerRefusal(array $credentials, Throwable $error): string
    {
        $message = (string) $error->getMessage();

        if (strpos($message, '1045') === false && stripos($message, 'Access denied') === false) {
            return 'اگر سرور دیتابیس روی همین ماشین است، هاست/پورت و فعال بودن سرویس MySQL را بررسی کنید.';
        }

        try {
            Database::connect([
                'host'     => (string) ($credentials['host'] ?? 'localhost'),
                'port'     => (int) ($credentials['port'] ?? 3306),
                'database' => (string) ($credentials['database'] ?? ''),
                'username' => (string) ($credentials['username'] ?? ''),
                'password' => '',
                'charset'  => 'utf8mb4',
                'prefix'   => (string) ($credentials['prefix'] ?? 'attendance_'),
            ]);

            // The server let this account in with NO password at all: the
            // account exists, so only the sent password can be the problem.
            return 'این حساب روی سرور بدون رمز پذیرفته می‌شود، پس مشکل از رمز ارسالی است؛ '
                . 'در DirectAdmin → MySQL Management رمز «' . (string) ($credentials['username'] ?? '') . '» را '
                . 'از نو تنظیم کنید و دقیقاً همان مقدار را اینجا وارد کنید.';
        } catch (Throwable $probe) {
            $probeMessage = (string) $probe->getMessage();

            if (strpos($probeMessage, '1045') !== false || stripos($probeMessage, 'Access denied') !== false) {
                // MySQL/MariaDB answers 1045 both for "no such account" and for
                // "wrong password", and an empty password does not tell the two
                // apart - so the hint names both fixes instead of guessing.
                return 'نام کاربری یا رمز عبور پذیرفته نشد' . $this->secretShape($credentials) . '. '
                    . 'پسوردی که به MySQL رسید همین است؛ روی هاست دقیقاً همین مقدار را بررسی کنید: '
                    . '(۱) DirectAdmin → MySQL Management → کاربر «' . (string) ($credentials['username'] ?? '')
                    . '» را پیدا کنید و رمز آن را با Change Password از نو تنظیم کنید؛ بعد در همین صفحه '
                    . 'تیک «استفاده از رمز ذخیره‌شده در config/config.php» را بردارید و رمزِ تازه‌تنظیم‌شده را '
                    . 'دستی وارد کنید (اگر رمز پنل با رمز داخل config یکی نیست، تیک باید برداشته باشد). '
                    . '(۲) در همان صفحه Add User to Database را بزنید و کاربر را به «'
                    . (string) ($credentials['database'] ?? '') . '» وصل کنید (بدون این کار جدول‌ها ساخته نمی‌شوند). '
                    . '(۳) نام کاربری را عیناً همان‌طور که پنل نشان می‌دهد تایپ کنید (حروف بزرگ/کوچک و _ مهم است). '
                    . '(۴) اگر رمز پنل فقط برای هاست «127.0.0.1» یا فقط «localhost» ساخته شده باشد، همین نصب‌کننده '
                    . 'هر دو را خودش آزمایش می‌کند؛ تلاش دومی هم رد شد، پس مقدار رمز/نام کاربری است. '
                    . 'برای گزارش دقیق بدون افشای رمز: php tools/db_check.php';
            }

            return 'هاست/پورت را بررسی کنید (مقدار پیشنهادی: localhost و 3306).';
        }
    }

    /**
     * A one-line description of the secret that was sent - never the secret.
     * The operator can compare LENGTH / first / last character with what the
     * panel shows and spot a typo or a truncated paste immediately.
     *
     * @param array<string,mixed> $credentials
     */
    private function secretShape(array $credentials): string
    {
        $secret = (string) ($credentials['password'] ?? '');

        if ($secret === '') {
            return ' (رمز عبور ارسال‌شده خالی بود)';
        }

        $shape = ' (رمز ارسالی: ' . strlen($secret) . ' کاراکتر'
            . '، شروع با «' . $secret[0] . '»، پایان با «' . substr($secret, -1) . '»'
            . '، شامل #: ' . (strpos($secret, '#') !== false ? 'بله' : 'خیر')
            . '، فاصله در ابتدا/انتها: ' . ($secret === trim($secret) ? 'ندارد' : 'دارد') . ')';

        return $shape;
    }

    /**
     * A password that carries invisible whitespace is the classic copy/paste
     * accident. It is never trimmed here (a secret is used byte for byte),
     * so the operator is warned instead of being silently "helped".
     *
     * @param array<string,mixed> $credentials
     */
    private function whitespaceWarning(array $credentials): string
    {
        $password = (string) ($credentials['password'] ?? '');

        if ($password !== '' && trim($password) !== $password) {
            return ' توجه: رمز عبور با فاصله یا خط جدید شروع/تمام می‌شود. اگر عمدی نیست، آن را بدون فاصله دوباره وارد کنید.';
        }

        return '';
    }

    /**
     * A secret must never reach a message, a log line or an HTML page - even
     * if a driver ever decides to echo the credentials back in its error.
     *
     * @param array<string,mixed> $credentials
     */
    private function redact(string $message, array $credentials): string
    {
        $secret = (string) ($credentials['password'] ?? '');

        if ($secret !== '') {
            $message = str_replace($secret, '***', $message);
        }

        // The project wraps driver errors once; do not stack the same prefix twice.
        return (string) preg_replace('/^Database connection failed:\s*/i', '', $message);
    }

    /* =================================================================
     * 3) Schema creation
     * ===============================================================*/

    /**
     * Run every schema file (stage 1 base + stage 2 upgrade) and then the
     * column level migrations. All of it is idempotent and only touches
     * attendance_* objects.
     *
     * @return array{ok:bool,message:string,executed:int,created:array<int,string>,errors:array<int,string>,foreign_untouched:array<int,string>,files:array<int,string>}
     */
    public function runAllSchema(?string $root = null): array
    {
        $root     = rtrim($root ?? $this->root, '/');
        $executed = 0;
        $created  = [];
        $errors   = [];
        $untouched = [];
        $files    = [];

        $before = Database::projectTables();

        foreach (self::SCHEMA_FILES as $file) {
            $result = $this->runSchema($root . '/' . $file);

            $executed += (int) $result['executed'];
            $errors    = array_merge($errors, $result['errors']);
            $untouched = array_merge($untouched, $result['foreign_untouched']);

            if (!$result['ok']) {
                return [
                    'ok' => false,
                    'message' => $result['message'],
                    'executed' => $executed,
                    'created' => array_values(array_diff(Database::projectTables(), $before)),
                    'errors' => $errors,
                    'foreign_untouched' => $untouched,
                    'files' => $files,
                ];
            }

            $files[] = $file;
        }

        // Column level additions (portable: information_schema first, then a
        // hard coded ALTER on an attendance_* table only).
        try {
            $migrations = new MigrationService($root);
            $pending    = $migrations->pending();
            $migrations->run();

            if ($pending !== []) {
                $executed += count($pending);
            }
        } catch (Throwable $e) {
            $errors[] = 'مهاجرت ساختار ناموفق بود: ' . $e->getMessage();
        }

        $after   = Database::projectTables();
        $created = array_values(array_diff($after, $before));

        $missing = [];

        foreach (self::REQUIRED_TABLES as $short) {
            if (!in_array(Database::prefix() . $short, $after, true)) {
                $missing[] = Database::prefix() . $short;
            }
        }

        if ($errors !== [] || $missing !== []) {
            return [
                'ok' => false,
                'message' => $missing === []
                    ? 'اجرای ساختار با خطا مواجه شد.'
                    : 'جداول الزامی ساخته نشدند: ' . implode(', ', $missing),
                'executed' => $executed,
                'created' => $created,
                'errors' => $errors,
                'foreign_untouched' => $untouched,
                'files' => $files,
            ];
        }

        return [
            'ok' => true,
            'message' => 'ساختار دیتابیس با موفقیت ایجاد شد.',
            'executed' => $executed,
            'created' => $created,
            'errors' => [],
            'foreign_untouched' => $untouched,
            'files' => $files,
        ];
    }

    /**
     * Execute sql/attendance_install.sql statement by statement.
     *
     * @return array{ok:bool, message:string, executed:int, created:array<int,string>, errors:array<int,string>, foreign_untouched:array<int,string>}
     */
    public function runSchema(string $sqlFile): array
    {
        if (!is_file($sqlFile)) {
            return ['ok' => false, 'message' => 'فایل SQL یافت نشد: ' . $sqlFile, 'executed' => 0, 'created' => [], 'errors' => [], 'foreign_untouched' => []];
        }

        $sql = (string) file_get_contents($sqlFile);

        if (trim($sql) === '') {
            return ['ok' => false, 'message' => 'فایل SQL خالی است.', 'executed' => 0, 'created' => [], 'errors' => [], 'foreign_untouched' => []];
        }

        $prefix = Database::prefix();
        $before = Database::projectTables();
        $foreignBefore = Database::foreignTables();

        $statements = SqlGuard::splitStatements($sql);
        $executed   = 0;
        $errors     = [];

        foreach ($statements as $statement) {
            $violations = SqlGuard::inspect($statement, $prefix);

            if ($violations !== []) {
                // A blocked statement is a hard stop: fail loudly instead of
                // silently skipping something unexpected.
                $errors[] = 'دستور ناایمن مسدود شد: ' . implode(' | ', $violations);

                return [
                    'ok'     => false,
                    'message' => 'به دلیل وجود دستور ناایمن، نصب متوقف شد.',
                    'executed' => $executed,
                    'created' => [],
                    'errors'  => $errors,
                    'foreign_untouched' => [],
                ];
            }

            try {
                Database::pdo()->exec($statement);
                $executed++;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage() . ' :: ' . substr(preg_replace('/\s+/', ' ', $statement) ?? $statement, 0, 120);
            }
        }

        $after         = Database::projectTables();
        $foreignAfter  = Database::foreignTables();
        $created       = array_values(array_diff($after, $before));

        // Hard proof that nothing foreign changed.
        $foreignChanged = array_merge(array_diff($foreignBefore, $foreignAfter), array_diff($foreignAfter, $foreignBefore));

        if ($foreignChanged !== []) {
            $errors[] = 'هشدار جدی: تغییر در جداول پروژه‌های دیگر شناسایی شد: ' . implode(', ', $foreignChanged);
        }

        // NOTE: this method runs ONE schema file. The complete table list is
        // verified once, in runAllSchema(), AFTER the stage upgrade files have
        // been applied - checking it here would reject a perfectly good stage 1
        // file for not containing the stage 2/3 tables.
        if ($errors !== [] || $foreignChanged !== []) {
            return [
                'ok'      => false,
                'message' => 'ایجاد جداول کامل نشد.',
                'executed' => $executed,
                'created' => $created,
                'errors'  => $errors,
                'foreign_untouched' => $foreignAfter,
            ];
        }

        return [
            'ok'      => true,
            'message' => 'جداول پروژه با موفقیت ایجاد/بررسی شدند.',
            'executed' => $executed,
            'created' => $created,
            'errors'  => [],
            'foreign_untouched' => $foreignAfter,
        ];
    }

    /* =================================================================
     * 4) Config file
     * ===============================================================*/

    /**
     * Raw content of an already shipped config/config.php, or null when the
     * file is absent/unreadable. Server side only: callers must never render
     * the password or the application key from this array.
     *
     * @return array<string,mixed>|null
     */
    public function storedConfig(): ?array
    {
        $file = $this->root . '/config/config.php';

        if (!is_file($file)) {
            return null;
        }

        $config = @include $file;

        return is_array($config) ? $config : null;
    }

    /** Whether the shipped app.key is a real 32-byte (base64) key. */
    public function storedAppKeyIsValid(): bool
    {
        $config = $this->storedConfig();
        $key    = (string) ($config['app']['key'] ?? '');

        if ($key === '') {
            return false;
        }

        $decoded = base64_decode($key, true);

        return $decoded !== false && strlen($decoded) >= 32;
    }

    /**
     * Database credentials of a PRE-CONFIGURED package (config/config.php
     * already filled in before upload), normalised for verifyDatabase().
     *
     * Returns null unless every field the installer needs is present - in
     * that case the wizard can verify the connection with a single confirm
     * click and never asks for the database password again. The project
     * prefix is not negotiable: a shipped config with any other prefix is
     * not treated as pre-configured.
     *
     * @return array{host:string,port:int,database:string,username:string,password:string,charset:string,prefix:string}|null
     */
    public function preconfiguredCredentials(): ?array
    {
        $config = $this->storedConfig();
        $db     = is_array($config['db'] ?? null) ? $config['db'] : [];

        $credentials = [
            'host'     => (string) ($db['host'] ?? ''),
            'port'     => (int) ($db['port'] ?? 3306),
            'database' => (string) ($db['database'] ?? ''),
            'username' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            'charset'  => (string) ($db['charset'] ?? 'utf8mb4'),
            'prefix'   => (string) ($db['prefix'] ?? ''),
        ];

        if ($credentials['host'] === '' || $credentials['database'] === ''
            || $credentials['username'] === '' || $credentials['password'] === ''
            || $credentials['prefix'] !== self::prefix()) {
            return null;
        }

        return $credentials;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool, message:string, path:string}
     */
    public function writeConfig(array $input): array
    {
        $sample = $this->root . '/config/config.sample.php';

        if (!is_file($sample)) {
            return ['ok' => false, 'message' => 'فایل config/config.sample.php یافت نشد.', 'path' => ''];
        }

        /** @var array<string,mixed> $config */
        $config = require $sample;

        $config['installed'] = true;

        $config['app']['name']        = (string) ($input['app_name'] ?? $config['app']['name']);
        $config['app']['env']         = (string) ($input['env'] ?? 'production');
        $config['app']['debug']       = ($config['app']['env'] === 'local');
        $config['app']['url']         = rtrim((string) ($input['app_url'] ?? ''), '/');
        $config['app']['timezone']    = (string) ($input['timezone'] ?? 'Asia/Tehran');

        // A pre-configured package already ships a random 32-byte app.key.
        // Keep it when it is valid (anything encrypted with it - and the key
        // the operator was given - stays valid); only generate a fresh key
        // when there is no usable one yet.
        $storedKey = (string) (($this->storedConfig() ?? [])['app']['key'] ?? '');
        $decoded   = $storedKey !== '' ? base64_decode($storedKey, true) : false;

        $config['app']['key'] = ($decoded !== false && strlen($decoded) >= 32)
            ? $storedKey
            : base64_encode(random_bytes(32));

        $config['db']['host']     = (string) ($input['db_host'] ?? 'localhost');
        $config['db']['port']     = (int) ($input['db_port'] ?? 3306);
        $config['db']['database'] = (string) ($input['db_database'] ?? '');
        $config['db']['username'] = (string) ($input['db_username'] ?? '');
        $config['db']['password'] = (string) ($input['db_password'] ?? '');
        $config['db']['prefix']   = (string) ($input['db_prefix'] ?? 'attendance_');

        $config['app']['force_https'] = !empty($input['force_https']);

        // Persist portable names instead of PHP constants so the file stays
        // valid across PHP versions (PASSWORD_BCRYPT changed type in 8.4).
        $config['security']['password_algo'] = (string) ($config['security']['password_algo'] ?? 'bcrypt');
        if (!in_array($config['security']['password_algo'], ['bcrypt', 'argon2i', 'argon2id'], true)) {
            $config['security']['password_algo'] = 'bcrypt';
        }
        $config['security']['password_cost'] = (int) ($config['security']['password_cost'] ?? 12);

        $contents = "<?php\n"
            . "/**\n"
            . " * SARMAD Attendance - generated configuration file.\n"
            . " *\n"
            . " * Created by the installer on " . date('Y-m-d H:i:s') . ".\n"
            . " * Keep this file private: it holds the database password and the\n"
            . " * application encryption key. Never share or commit it.\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "return " . var_export($config, true) . ";\n";

        $target = $this->root . '/config/config.php';
        $temp   = $target . '.tmp';

        if (@file_put_contents($temp, $contents, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'نوشتن فایل config.php ناموفق بود (دسترسی نوشتن را بررسی کنید).', 'path' => $target];
        }

        @chmod($temp, 0640);

        if (!@rename($temp, $target)) {
            @unlink($temp);

            return ['ok' => false, 'message' => 'جایگزینی فایل config.php ناموفق بود.', 'path' => $target];
        }

        @chmod($target, 0640);

        return ['ok' => true, 'message' => 'فایل config/config.php ایجاد شد.', 'path' => $target];
    }

    /* =================================================================
     * 5) Owner account
     * ===============================================================*/

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool, message:string, user_id?:int, username?:string}
     */
    public function createOwner(array $input): array
    {
        $name     = trim((string) ($input['owner_name'] ?? ''));
        $username = trim((string) ($input['owner_username'] ?? ''));
        $email    = trim((string) ($input['owner_email'] ?? ''));
        $password = (string) ($input['owner_password'] ?? '');
        $repeat   = (string) ($input['owner_password_confirmation'] ?? '');

        if ($name === '' || $username === '' || $password === '') {
            return ['ok' => false, 'message' => 'نام، نام کاربری و رمز عبور مالک الزامی است.'];
        }

        if (!preg_match('/^[a-zA-Z0-9_\.]{3,60}$/', $username)) {
            return ['ok' => false, 'message' => 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه و آندرلاین باشد (حداقل ۳ کاراکتر).'];
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'ایمیل مالک معتبر نیست.'];
        }

        $problems = Security::passwordProblems($password, $repeat);

        if ($problems !== []) {
            return ['ok' => false, 'message' => implode(' ', $problems)];
        }

        $roleId = Role::idFor(Role::OWNER);

        if ($roleId <= 0) {
            return ['ok' => false, 'message' => 'نقش owner در جدول نقش‌ها یافت نشد. مرحله ایجاد جداول ناقص است.'];
        }

        if (User::findByUsername($username) !== null) {
            return ['ok' => false, 'message' => 'این نام کاربری قبلاً ثبت شده است.'];
        }

        if ($email !== '' && User::findBy('email', strtolower($email)) !== null) {
            return ['ok' => false, 'message' => 'این ایمیل قبلاً ثبت شده است.'];
        }

        $userId = User::createUser([
            'uuid'        => Security::uuid4(),
            'role'        => Role::OWNER,
            'role_id'     => $roleId,
            'customer_id' => null,
            'name'        => $name,
            'username'    => $username,
            'email'       => $email !== '' ? $email : null,
            'mobile'      => (string) ($input['owner_mobile'] ?? '') ?: null,
            'password'    => $password,
            'is_active'   => 1,
            'api_enabled' => 1,
            'created_by'  => null,
        ]);

        return [
            'ok'       => true,
            'message'  => 'حساب مالک سامانه ایجاد شد.',
            'user_id'  => $userId,
            'username' => $username,
        ];
    }

    /* =================================================================
     * 6) Optional first customer
     * ===============================================================*/

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool, message:string, customer_id?:int, manager_username?:string}
     */
    public function createFirstCustomer(array $input): array
    {
        $customerName = trim((string) ($input['customer_name'] ?? ''));

        if ($customerName === '') {
            return ['ok' => true, 'message' => 'مشتری اولیه ایجاد نشد (اختیاری).'];
        }

        $customerId = Customer::createCustomer([
            'name'         => $customerName,
            'company_name' => (string) ($input['customer_company'] ?? '') ?: null,
            'timezone'     => (string) ($input['timezone'] ?? 'Asia/Tehran'),
            'date_system'  => (string) ($input['date_system'] ?? 'jalali'),
            'status'       => 'active',
        ]);

        // Stage 2: every customer gets its explicit feature matrix (base
        // features on, optional features off) and a trial subscription row.
        try {
            FeatureService::seedDefaults($customerId, (int) (Session::userId() ?? 0));
            Subscription::record([
                'customer_id' => $customerId,
                'plan_name'   => 'trial',
                'status'      => 'trial',
                'start_date'  => date('Y-m-d'),
                'expire_date' => date('Y-m-d', time() + (14 * 86400)),
                'notes'       => 'اشتراک آزمایشی هنگام نصب',
            ]);
        } catch (Throwable $e) {
            Logger::channel('installer')->warning('Feature/subscription seed failed', ['error' => $e->getMessage()]);
        }

        $managerEmail    = trim((string) ($input['manager_email'] ?? ''));
        $managerPassword = (string) ($input['manager_password'] ?? '');
        $managerName     = trim((string) ($input['manager_name'] ?? $customerName));

        if ($managerEmail === '' || $managerPassword === '') {
            return ['ok' => true, 'message' => 'مشتری اولیه ایجاد شد (بدون حساب مدیر).', 'customer_id' => $customerId];
        }

        if (filter_var($managerEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'ایمیل مدیر مشتری معتبر نیست.'];
        }

        $problems = Security::passwordProblems($managerPassword);

        if ($problems !== []) {
            return ['ok' => false, 'message' => 'رمز مدیر مشتری: ' . implode(' ', $problems)];
        }

        if (User::findBy('email', strtolower($managerEmail)) !== null) {
            return ['ok' => false, 'message' => 'این ایمیل قبلاً برای کاربر دیگری ثبت شده است.'];
        }

        $managerId = Manager::createManager([
            'customer_id' => $customerId,
            'name'        => $managerName !== '' ? $managerName : $customerName,
            'email'       => strtolower($managerEmail),
            'is_primary'  => 1,
        ]);

        $baseUsername = preg_replace('/[^a-z0-9_\.]/', '', strtolower(explode('@', $managerEmail)[0])) ?: 'manager';

        $userId = User::createUser([
            'role'        => Role::MANAGER,
            'role_id'     => Role::idFor(Role::MANAGER),
            'customer_id' => $customerId,
            'manager_id'  => $managerId,
            'name'        => $managerName !== '' ? $managerName : $customerName,
            'username'    => $baseUsername,
            'email'       => strtolower($managerEmail),
            'password'    => $managerPassword,
            'is_active'   => 1,
            'must_change_password' => 1,
        ]);

        Manager::linkUser($managerId, $userId);

        $created = User::find($userId);

        return [
            'ok'               => true,
            'message'          => 'مشتری اولیه و حساب مدیر آن ایجاد شد.',
            'customer_id'      => $customerId,
            'manager_username' => (string) ($created['username'] ?? $baseUsername),
        ];
    }

    /* =================================================================
     * 7) Settings + lock + audit
     * ===============================================================*/

    /**
     * @param array<string,mixed> $input
     */
    public function writeSettings(array $input, int $ownerId = 0): void
    {
        $settings = [
            'app.version'         => '1.0.0',
            'app.timezone'        => (string) ($input['timezone'] ?? 'Asia/Tehran'),
            'app.date_system'     => (string) ($input['date_system'] ?? 'jalali'),
            'app.name'            => (string) ($input['app_name'] ?? 'SARMAD Attendance'),
            'installer.installed_at' => date('Y-m-d H:i:s'),
            'installer.version'   => '1.0.0',
            'installer.locked'    => true,
            'security.max_login_attempts' => 5,
            'security.lockout_minutes'    => 15,
            'device.offline_after'        => 300,
            'attendance.allow_manual'     => false,
            'attendance.round_minutes'    => 1,
        ];

        foreach ($settings as $key => $value) {
            Setting::put($key, $value, Setting::SCOPE_GLOBAL, 0, 0, 0, $ownerId);
        }
    }

    /**
     * @return array{ok:bool, message:string, path:string}
     */
    public function lock(): array
    {
        $path = $this->root . '/storage/installed.lock';

        $payload = json_encode([
            'installed_at' => date('c'),
            'version'      => '1.0.0',
            'php'          => PHP_VERSION,
            'database'     => Config::get('db.database', ''),
            'prefix'       => Config::get('db.prefix', 'attendance_'),
            'fingerprint'  => hash('sha256', (string) Config::get('db.database', '') . (string) Config::get('db.username', '')),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($path, (string) $payload, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'ایجاد فایل قفل نصب ناموفق بود.', 'path' => $path];
        }

        @chmod($path, 0644);

        return ['ok' => true, 'message' => 'Installer قفل شد.', 'path' => $path];
    }

    public function isLocked(): bool
    {
        return is_file($this->root . '/storage/installed.lock');
    }

    /**
     * Write the opening audit entries straight through PDO (models need a
     * fully booted app, the installer runs before that).
     */
    public function writeAudit(string $event, string $description, array $meta = []): void
    {
        try {
            Database::insert('audit_logs', [
                'channel'     => 'installer',
                'event'       => $event,
                'level'       => 'info',
                'result'      => 'success',
                'actor_type'  => 'system',
                'actor_label' => 'installer',
                'description' => substr($description, 0, 500),
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'cli',
                'user_agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255),
                'meta'        => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            Logger::channel('installer')->error('Could not write installer audit entry', ['error' => $e->getMessage()]);
        }
    }

    /* =================================================================
     * 8) Self protection
     * ===============================================================*/

    /**
     * If the installer is requested on an already installed system, the
     * request must die with 404 (never a "reinstall" form).
     */
    public function guardInstalled(): void
    {
        if (!$this->isLocked() && !is_file($this->root . '/config/config.php')) {
            return;
        }

        if ($this->isLocked()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');

            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
                . '<title>404</title><style>body{font-family:Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;'
                . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
                . 'div{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:30px 34px}</style>'
                . '</head><body><div><h1>صفحه یافت نشد</h1>'
                . '<p>SARMAD Attendance قبلاً نصب شده است و نصب‌کننده غیرفعال شده است.</p>'
                . '<p>برای فعال‌سازی مجدد، ابتدا فایل <code>storage/installed.lock</code> را حذف کنید.</p>'
                . '</div></body></html>';

            exit;
        }
    }

    public function selfPath(): string
    {
        return $this->root . '/install';
    }

    /**
     * The prefix that must never change after installation.
     */
    public static function prefix(): string
    {
        return 'attendance_';
    }

    public function root(): string
    {
        return $this->root;
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Summary of what the installer is allowed / not allowed to touch.
     * Rendered on the first installer screen for full transparency.
     *
     * @return array{allowed:array<int,string>, forbidden:array<int,string>}
     */
    public static function scope(): array
    {
        return [
            'allowed' => [
                'ایجاد و تغییر جداول با پیشوند attendance_',
                'درج نقش‌ها، تنظیمات پیش‌فرض و حساب مالک',
            ],
            'forbidden' => [
                'خواندن، نوشتن، تغییر نام یا حذف هر جدول با پیشوندهای barber_ و cafe_',
                'هر جدول با پیشوند clinic_ و zibaei_ و sarmadpf_',
                'هر جدول بدون پیشوند attendance_',
                'حذف دیتابیس، ساخت کاربر MySQL، تغییر سطح دسترسی دیتابیس',
            ],
        ];
    }

    public static function emptyResult(): array
    {
        return ['ok' => false, 'message' => '', 'executed' => 0, 'created' => [], 'errors' => [], 'foreign_untouched' => []];
    }

    public static function fail(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }
}
