<?php
/**
 * =====================================================================
 * SARMAD Attendance - security unit tests (no HTTP server required)
 * =====================================================================
 * Run:  php tests/security_tests.php
 *
 * Covers the invariants that keep the shared database safe:
 *   - SqlGuard refuses DROP / TRUNCATE / RENAME / GRANT and anything
 *     pointing at another project's tables
 *   - Database::table() can only ever produce attendance_* names
 *   - passwords are hashed (never stored as plain text)
 *   - AES-256-GCM encryption round trip
 *   - open redirect protection
 *   - validator rules
 */

declare(strict_types=1);

define('SARMAD_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }

    $file = SARMAD_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

use App\Core\Config;
use App\Core\Database;
use App\Core\Security;
use App\Core\SqlGuard;
use App\Core\Str;
use App\Core\Validator;
use App\Support\Url;

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;

    if ($condition) {
        $pass++;
        echo "  PASS  {$label}\n";

        return;
    }

    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

function group(string $title): void
{
    echo "\n=== {$title} ===\n";
}

/* ------------------------------------------------------------------ */
Config::hydrate([
    'app'      => ['key' => base64_encode(str_repeat('k', 32)), 'timezone' => 'Asia/Tehran', 'version' => '1.0.0'],
    'db'       => ['prefix' => 'attendance_', 'host' => 'localhost'],
    'security' => ['password_algo' => 'bcrypt', 'password_cost' => 10],
], SARMAD_ROOT);

Database::setPrefix('attendance_');

/* ================================================================== */
group('SqlGuard: foreign projects are untouchable');
/* ================================================================== */
$dangerous = [
    'DROP TABLE barber_appointments'                                  => true,
    'DROP TABLE cafe_orders'                                          => true,
    'TRUNCATE TABLE clinic_patients'                                  => true,
    'RENAME TABLE zibaei_reservations TO x'                           => true,
    'ALTER TABLE sarmadpf_posts ADD COLUMN x INT'                     => true,
    'INSERT INTO barber_appointments (id) VALUES (1)'                 => true,
    'UPDATE cafe_orders SET note = "x" WHERE id = 1'                  => true,
    'DELETE FROM clinic_patients WHERE id = 1'                        => true,
    'GRANT ALL ON *.* TO "x"@"%"'                                     => true,
    'CREATE TABLE foo (id INT)'                                       => true,
    'SELECT * FROM zibaei_reservations'                               => true,
    'DROP DATABASE sarmadfa_clinic'                                   => true,
    'ALTER DATABASE sarmadfa_clinic CHARACTER SET latin1'             => true,
    'SELECT 1 INTO OUTFILE "/tmp/x"'                                  => true,
    'LOAD DATA INFILE "/etc/passwd" INTO TABLE attendance_users'      => true,
    'insert into attendance_users (id) values (1)'                    => false,
    'UPDATE attendance_users SET name = "x" WHERE id = 1'             => false,
    'DELETE FROM attendance_users WHERE id = 1'                       => false,
    'ALTER TABLE attendance_users ADD COLUMN tmp INT'                 => false,
];

foreach ($dangerous as $sql => $shouldBeBlocked) {
    $violations = SqlGuard::inspect($sql, 'attendance_');
    check(
        ($shouldBeBlocked ? 'blocked' : 'allowed') . ': ' . $sql,
        $shouldBeBlocked ? $violations !== [] : $violations === [],
        implode(' | ', $violations)
    );
}

$installSql = (string) file_get_contents(SARMAD_ROOT . '/sql/attendance_install.sql');
check('official sql/attendance_install.sql passes the guard', SqlGuard::inspect($installSql, 'attendance_') === []);

$foreignMentions = 0;
foreach (SqlGuard::splitStatements($installSql) as $statement) {
    $clean = SqlGuard::stripComments($statement);
    foreach (Database::FOREIGN_PREFIXES as $prefix) {
        if (stripos($clean, $prefix) !== false) {
            $foreignMentions++;
        }
    }
}
check('install SQL never references another project (outside comments)', $foreignMentions === 0);

$createCount = 0;
foreach (SqlGuard::splitStatements($installSql) as $statement) {
    if (preg_match('/^create\s+table/i', trim(SqlGuard::stripComments($statement)))) {
        $createCount++;
    }
}
check('install SQL creates only prefixed tables (15 found)', $createCount === 15, (string) $createCount);

/* ================================================================== */
group('Database::table(): prefix enforcement');
/* ================================================================== */
check('short name gets the prefix', Database::table('users') === 'attendance_users');
check('already prefixed name stays intact', Database::table('attendance_users') === 'attendance_users');

foreach (Database::FOREIGN_PREFIXES as $foreign) {
    $blocked = false;

    try {
        Database::table($foreign . 'anything');
    } catch (Throwable $e) {
        $blocked = true;
    }

    check('refuses ' . $foreign . '*', $blocked);
}

$blockedInjection = false;

try {
    Database::table('users; DROP TABLE attendance_roles');
} catch (Throwable $e) {
    $blockedInjection = true;
}

check('refuses SQL fragments in a table name', $blockedInjection);

$blockedPrefix = false;

try {
    Database::assertPrefix('clinic_');
} catch (Throwable $e) {
    $blockedPrefix = true;
}

check('refuses a foreign prefix in config', $blockedPrefix);

/* ================================================================== */
group('Crypto & password hashing');
/* ================================================================== */
$hash = Security::hashPassword('Str0ng#Password');
check('hash never contains the plain password', strpos($hash, 'Str0ng#Password') === false);
check('hash is bcrypt ($2y$)', strpos($hash, '$2y$') === 0 || strpos($hash, '$argon2') === 0);
check('correct password verifies', Security::verifyPassword('Str0ng#Password', $hash) === true);
check('wrong password rejected', Security::verifyPassword('wrong-password', $hash) === false);
check('plain text stored in the column is rejected', Security::verifyPassword('plaintext', 'plaintext') === false);
check('empty hash rejected', Security::verifyPassword('x', '') === false);

$plain  = 'device token payload - نمونه';
$sealed = Security::encrypt($plain);
check('ciphertext differs from plaintext', $sealed !== $plain);
check('decryption returns the original', Security::decrypt($sealed) === $plain);

$tampered = base64_encode(substr((string) base64_decode($sealed, true), 0, -4) . 'AAAA');
$tamperRejected = false;

try {
    Security::decrypt($tampered);
} catch (Throwable $e) {
    $tamperRejected = true;
}

check('tampered ciphertext is rejected (GCM tag)', $tamperRejected);

check('device tokens are long and prefixed', strpos('att_' . Security::randomToken(32), 'att_') === 0 && strlen(Security::randomToken(32)) >= 32);
check('two random tokens differ', Security::randomToken(32) !== Security::randomToken(32));
check('uuid4 shape is valid', (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Security::uuid4()));

$weak = Security::passwordProblems('123');
check('short/weak password rejected', $weak !== []);
check('common password rejected', Security::passwordProblems('password123') !== []);
check('strong password accepted', Security::passwordProblems('Tr7#kLm9Qw2') === []);
check('mismatched confirmation rejected', Security::passwordProblems('Tr7#kLm9Qw2', 'different123') !== []);

// Regression: suggested passwords must never be rejected by the very policy
// that guards them (an unvalidated suggestion silently broke password resets).
$suggestedBad    = 0;
$suggestedShort  = 0;
$suggestedClasses = 0;

for ($i = 0; $i < 250; $i++) {
    $suggested = Security::suggestPassword(14);

    if (Security::passwordProblems($suggested) !== []) {
        $suggestedBad++;
    }

    if (strlen($suggested) !== 14) {
        $suggestedShort++;
    }

    if (!preg_match('/[a-z]/', $suggested) || !preg_match('/[A-Z]/', $suggested)
        || !preg_match('/\d/', $suggested) || !preg_match('/[^A-Za-z0-9]/', $suggested)) {
        $suggestedClasses++;
    }
}

check('250 suggested passwords all pass the password policy', $suggestedBad === 0, (string) $suggestedBad);
check('suggested passwords keep the requested length', $suggestedShort === 0, (string) $suggestedShort);
check('suggested passwords mix lower case, upper case, digits and symbols', $suggestedClasses === 0, (string) $suggestedClasses);
check('short suggestion is raised to the configured minimum', strlen(Security::suggestPassword(6)) >= 11);

/* ================================================================== */
group('Open redirect protection');
/* ================================================================== */
// Emulate a web request on the production host (CLI has no SCRIPT_NAME).
$_SERVER['HTTP_HOST']   = 'sarmadfallahzadeh.ir';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTPS']       = 'on';
check('internal path allowed', Url::safeRedirect('/devices') === '/devices');
check('external host blocked', Url::safeRedirect('https://evil.example/steal') === '/dashboard');
check('protocol relative blocked', Url::safeRedirect('//evil.example/x') === '/dashboard');
check('installer bounce blocked', Url::safeRedirect('/install/?step=2') === '/dashboard');
check('header injection blocked', Url::safeRedirect("/x\r\nSet-Cookie: a=b") === '/dashboard');
check('relative path rejected', Url::safeRedirect('devices') === '/dashboard');

/* ================================================================== */
group('Validator');
/* ================================================================== */
$v = Validator::make(['email' => 'not-an-email', 'name' => ''], [
    'email' => 'required|email',
    'name'  => 'required|string|max:5',
]);
check('invalid email + empty required field fail', $v->fails() === true);
check('two error messages produced', count($v->errorList()) === 2);

$v = Validator::make(['email' => 'User@Example.COM '], ['email' => 'required|email|max:190']);
check('valid email normalised to lower case', $v->validated()['email'] === 'user@example.com');

$v = Validator::make(['phone' => '1234567890123456789012345'], ['phone' => 'phone']);
check('absurdly long phone rejected', $v->fails() === true);

$v = Validator::make(['timezone' => 'Mars/Olympus'], ['timezone' => 'timezone']);
check('invalid timezone rejected', $v->fails() === true);

$v = Validator::make(['date_system' => 'jalali'], ['date_system' => 'in:jalali,gregorian']);
check('whitelist value accepted', $v->passes() === true);

/* ================================================================== */
group('Input helpers (Persian input support)');
/* ================================================================== */
check('persian digits converted', Str::toAsciiDigits('۰۹۱۲۳۴۵۶۷۸۹') === '09123456789');
check('arabic digits converted', Str::toAsciiDigits('٠٩١٢٣٤٥٦٧٨٩') === '09123456789');
check('mobile normalised', Str::normalizeMobile('۰۹۱۲ ۳۴۵ ۶۷۸۹') === '09123456789');
check('+98 mobile normalised', Str::normalizeMobile('+989123456789') === '09123456789');
check('slug is url safe', Str::slug('کلینیک نمونه') !== '' && strpos(Str::slug('a b/c'), ' ') === false);
check('email masked for logs', Str::maskEmail('manager@example.com') === 'ma***@example.com');

/* ------------------------------------------------------------------ */
echo "\n=====================================\n";
echo "UNIT RESULT: {$pass} passed, {$fail} failed\n";
echo "=====================================\n";

exit($fail === 0 ? 0 : 1);
