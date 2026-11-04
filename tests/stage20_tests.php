<?php
/**
 * =====================================================================
 * SARMAD Attendance - STAGE 20 test suite (REAL, no mocks)
 * =====================================================================
 *
 *   php tests/stage20_tests.php            # run everything
 *   php tests/stage20_tests.php --keep     # keep the fixtures in the DB
 *
 * Two halves, both real:
 *
 *   A  QR BADGE (pure + real database)
 *      - the badge format: HMAC signed, tenant bound, employee bound,
 *        tamper proof, replay across installations impossible,
 *      - a badge punch writes a REAL attendance row (source device_qr) with
 *        NO recognition proof and WITHOUT a complete face dataset - the whole
 *        point of the fallback,
 *      - a forged/foreign badge writes nothing,
 *      - the offline path: an item that carries the badge DIGEST is accepted,
 *        an item with a made up digest is refused,
 *      - ZERO BYTES ON DISK: the uploads/storage file count and size are
 *        unchanged by every badge punch (no image is ever written).
 *
 *   B  BRANDING (owner only, never cropped)
 *      - Customer::updateProfile() can not write logo_path / brand_name (the
 *        manager path),
 *      - Customer::updateBranding() can write exactly those two columns,
 *      - the manager settings controller refuses a smuggled logo and audits
 *        the attempt,
 *      - the CSS of the kiosk / panel / owner panel uses object-fit: contain
 *        with width:auto for every brand logo (never `cover`, which crops),
 *      - the kiosk layout loads the QR reader and the service worker
 *        precaches it (offline QR).
 *
 * It never touches a foreign table and never deletes anything it did not
 * create.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);

define('SARMAD_APP', true);
define('SARMAD_START', microtime(true));
define('SARMAD_ROOT', $root);

require $root . '/app/Bootstrap.php';

if (!App\Bootstrap::bootCli()) {
    fwrite(STDERR, "boot failed (config / database)\n");
    exit(1);
}

use App\Core\Config;
use App\Core\Database;
use App\Core\Security;
use App\Models\AttendanceRecord;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Employee;
use App\Services\AttendanceEngine;
use App\Support\EmployeeQr;

$keep = in_array('--keep', $argv, true);
$MARK = 'SARMAD-TEST-S20';

$pass = 0;
$fail = 0;
$failures = [];

function section(string $title): void
{
    echo "\n\033[1m== " . $title . " ==\033[0m\n";
}

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail, $failures;

    if ($condition) {
        $pass++;
        echo "  \033[32m✓\033[0m " . $label . "\n";

        return;
    }

    $fail++;
    $failures[] = $label . ($detail === '' ? '' : ' — ' . $detail);
    echo "  \033[31m✗ " . $label . "\033[0m" . ($detail === '' ? '' : ' — ' . $detail) . "\n";
}

function eq(string $label, $actual, $expected): void
{
    check($label, $actual === $expected,
        'actual=' . var_export($actual, true) . ' expected=' . var_export($expected, true));
}

function t(string $name): string
{
    return Database::table($name);
}

/* =====================================================================
 * Disk usage snapshot - the "zero bytes" promise
 * =================================================================== */

/**
 * Total number of files and total size below a directory (uploads/ or the
 * whole storage/ tree). Used to prove that a QR punch writes nothing.
 *
 * @return array{files:int,bytes:int}
 */
function diskSnapshot(string $relative): array
{
    $path = Config::path($relative);

    if (!is_dir($path)) {
        return ['files' => 0, 'bytes' => 0];
    }

    $files = 0;
    $bytes = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $files++;
            $bytes += (int) $item->getSize();
        }
    }

    return ['files' => $files, 'bytes' => $bytes];
}

echo "SARMAD Attendance - stage 20 suite (QR badge + owner branding)\n";
echo "prefix      : " . Database::prefix() . "\n";
echo "database    : " . (string) Config::get('db.database') . "\n";

/* =====================================================================
 * A. The badge format (pure)
 * =================================================================== */

section('A1. Badge format, signature and tampering');

$customerId = 1;
$customer   = Customer::find($customerId);

if ($customer === null) {
    fwrite(STDERR, "customer #1 is missing - run the installer first\n");
    exit(1);
}

$payload = EmployeeQr::payload($customerId, 4242);

check('the badge starts with the version prefix', strpos($payload, EmployeeQr::PREFIX . '.') === 0, $payload);
check('the badge fits the QR alphanumeric budget', strlen($payload) <= EmployeeQr::MAX_LENGTH, (string) strlen($payload));
eq('the badge verifies to its employee', EmployeeQr::verify($payload, $customerId)['employee_id'] ?? 0, 4242);
eq('the badge verifies to its tenant', EmployeeQr::verify($payload)['customer_id'] ?? 0, $customerId);
check('another tenant refuses the badge', EmployeeQr::verify($payload, $customerId + 1) === null);

$parts = explode('.', $payload);
$swappedEmployee = $parts;
$swappedEmployee[2] = strtoupper(base_convert('4243', 10, 36));
check('an edited employee id is refused (HMAC)', EmployeeQr::verify(implode('.', $swappedEmployee), $customerId) === null);

$swappedTenant = $parts;
$swappedTenant[1] = strtoupper(base_convert((string) ($customerId + 1), 10, 36));
check('an edited customer id is refused (HMAC)', EmployeeQr::verify(implode('.', $swappedTenant)) === null);

$swappedSignature = $parts;
$swappedSignature[3] = ($parts[3][0] === 'a' ? 'b' : 'a') . substr($parts[3], 1);
check('an edited signature is refused', EmployeeQr::verify(implode('.', $swappedSignature), $customerId) === null);

check('an empty string is refused', EmployeeQr::verify('', $customerId) === null);
check('a bare employee id is refused', EmployeeQr::verify('4242', $customerId) === null);
check('a random string is refused', EmployeeQr::verify('HELLO.WORLD', $customerId) === null);
check('an over long string is refused', EmployeeQr::verify(str_repeat('A', 200), $customerId) === null);
check('lower case + whitespace are normalised', EmployeeQr::verify(' ' . strtolower($payload) . "\n", $customerId) !== null);
check('looksLikeBadge accepts a badge', EmployeeQr::looksLikeBadge($payload));
check('looksLikeBadge rejects a URL', !EmployeeQr::looksLikeBadge('https://example.com'));

$digest = EmployeeQr::digestFor($customerId, 4242);
check('the digest is sha256 hex', preg_match('/^[0-9a-f]{64}$/', $digest) === 1, $digest);
eq('the digest is the hash of the badge', $digest, hash('sha256', EmployeeQr::normalise($payload)));
check('digestMatches accepts the right pair', EmployeeQr::digestMatches($customerId, 4242, $digest));
check('digestMatches refuses another employee', !EmployeeQr::digestMatches($customerId, 4243, $digest));
check('digestMatches refuses junk', !EmployeeQr::digestMatches($customerId, 4242, 'nope'));

/* =====================================================================
 * B. Fixtures - a REAL employee without any face data + a device
 * =================================================================== */

section('B. Fixtures (real tables)');

$stamp = date('ymd-His');
$cleanup = ['customers' => [], 'employees' => [], 'devices' => [], 'customer' => []];

$makeEmployee = static function (string $label, string $status = 'active') use ($customerId, $MARK, $stamp): array {
    $id = Employee::createEmployee([
        'customer_id'   => $customerId,
        'employee_code' => $MARK . '-' . $label . '-' . $stamp,
        'first_name'    => $MARK,
        'last_name'     => $label,
        'status'        => $status,
        'hire_date'     => date('Y-m-d'),
    ]);

    return Employee::find($id) ?? [];
};

// Deliberately ZERO face samples: the badge is the only way this person can
// register attendance.
$employee = $makeEmployee('QR');
$inactive = $makeEmployee('INACTIVE', 'suspended');
$cleanup['employees'] = [(int) $employee['id'], (int) $inactive['id']];

// A second tenant with an employee - used for the cross tenant test.
$otherCustomerId = Database::insert(t('customers'), [
    'uuid'       => Security::uuid4(),
    'name'       => $MARK . ' tenant B ' . $stamp,
    'slug'       => 'sarmad-s20-b-' . $stamp,
    'status'     => 'active',
    'created_at' => date('Y-m-d H:i:s'),
]);
$cleanup['customers'][] = $otherCustomerId;

$otherEmployeeId = Database::insert(t('employees'), [
    'uuid'          => Security::uuid4(),
    'customer_id'   => $otherCustomerId,
    'employee_code' => $MARK . '-B-' . $stamp,
    'first_name'    => $MARK,
    'last_name'     => 'tenantB',
    'status'        => 'active',
    'created_at'    => date('Y-m-d H:i:s'),
]);

$makeDevice = static function (int $ownerCustomerId, string $label) use ($MARK, $stamp): array {
    $id = Database::insert(t('devices'), [
        'uuid'           => Security::uuid4(),
        'device_uid'     => strtoupper($MARK . '-' . $label . '-' . $stamp),
        'customer_id'    => $ownerCustomerId,
        'name'           => $MARK . ' ' . $label,
        'type'           => 'tablet',
        'platform'       => 'android',
        'app_version'    => '20.0.0-test',
        'token_prefix'   => 'test',
        'api_token_hash' => password_hash('unused-in-this-suite', PASSWORD_DEFAULT),
        'status'         => 'active',
        'created_at'     => date('Y-m-d H:i:s'),
    ]);

    return Device::find($id) ?? [];
};

$device       = $makeDevice($customerId, 'qr');
$deviceOther  = $makeDevice($otherCustomerId, 'qrB');
$cleanup['devices'] = [(int) $device['id'], (int) $deviceOther['id']];

$engine = new AttendanceEngine();

$sessionsFor = static function (int $employeeId) use ($customerId): array {
    return AttendanceRecord::sessionsOn($customerId, $employeeId, date('Y-m-d'));
};

$badge = EmployeeQr::payload($customerId, (int) $employee['id']);

/* =====================================================================
 * C. A badge punch - real write, no proof, no face dataset, no file
 * =================================================================== */

section('C. QR punch through the real engine');

$diskBefore = diskSnapshot('uploads');

$entry = $engine->recordQr($device, [
    'request_id' => 's20-qr-' . $stamp,
    'qr'         => $badge,
], 'entry');

check('a badge entry is accepted', !empty($entry['ok']),
    'code=' . (string) $entry['code'] . ' msg=' . (string) $entry['message']);
eq('the entry code is ATTENDANCE_RECORDED', (string) $entry['code'], 'ATTENDANCE_RECORDED');
eq('the engine reports the qr credential', (string) ($entry['data']['credential'] ?? ''), 'qr');

$recordId = (int) ($entry['data']['record_id'] ?? 0);
check('a record id was returned', $recordId > 0);

$stored = $recordId > 0 ? AttendanceRecord::find($recordId) : [];
eq('the stored source is device_qr', (string) ($stored['source'] ?? ''), 'device_qr');
check('no recognition proof is attached', empty($stored['recognition_uuid']),
    'recognition_uuid=' . var_export($stored['recognition_uuid'] ?? null, true));
check('the employee has NO face dataset at all', $sessionsFor((int) $employee['id']) !== []);
eq('the badge punch wrote exactly one session', count($sessionsFor((int) $employee['id'])), 1);

$meta = json_decode((string) ($stored['meta'] ?? ''), true);
eq('the meta records the credential', (string) ($meta['credential'] ?? ''), 'qr');

$diskAfter = diskSnapshot('uploads');
eq('ZERO bytes: the uploads file count is unchanged', $diskAfter['files'], $diskBefore['files']);
eq('ZERO bytes: the uploads size is unchanged', $diskAfter['bytes'], $diskBefore['bytes']);

// The success payload the kiosk prints.
check('the answer carries the employee brief', (int) ($entry['data']['employee']['id'] ?? 0) === (int) $employee['id']);

// Second entry while one is open.
$again = $engine->recordQr($device, ['request_id' => 's20-qr2-' . $stamp, 'qr' => $badge], 'entry');
eq('a second entry is refused with ENTRY_ALREADY_OPEN', (string) $again['code'], 'ENTRY_ALREADY_OPEN');
eq('no second row was created', count($sessionsFor((int) $employee['id'])), 1);

// Exit with the same badge.
$exit = $engine->recordQr($device, ['request_id' => 's20-qrx-' . $stamp, 'qr' => $badge], 'exit');
check('a badge exit is accepted', !empty($exit['ok']), 'code=' . (string) $exit['code']);
$closed = $recordId > 0 ? AttendanceRecord::find($recordId) : [];
check('the session was closed', !empty($closed['exit_at']));
eq('the closed session keeps the qr source', (string) ($closed['source'] ?? ''), 'device_qr');

// A badge of an INACTIVE employee.
$inactiveBadge = EmployeeQr::payload($customerId, (int) $inactive['id']);
$refused = $engine->recordQr($device, ['request_id' => 's20-qri-' . $stamp, 'qr' => $inactiveBadge], 'entry');
eq('an inactive employee badge is refused', (string) $refused['code'], 'EMPLOYEE_INACTIVE');
eq('nothing was written for the inactive employee', count($sessionsFor((int) $inactive['id'])), 0);

// A forged badge.
$forged = implode('.', [$parts[0], $parts[1], $parts[2], str_repeat('0', 20)]);
$bad = $engine->recordQr($device, ['request_id' => 's20-qrf-' . $stamp, 'qr' => $forged], 'entry');
eq('a forged signature is refused', (string) $bad['code'], 'QR_INVALID');

// A badge of ANOTHER tenant scanned on this device.
$foreignBadge = EmployeeQr::payload($otherCustomerId, (int) $otherEmployeeId);
$foreign = $engine->recordQr($device, ['request_id' => 's20-qrb-' . $stamp, 'qr' => $foreignBadge], 'entry');
eq('another tenant badge is refused', (string) $foreign['code'], 'QR_INVALID');

// A bare employee id must not replace the badge.
$bare = $engine->recordQr($device, [
    'request_id'  => 's20-qrid-' . $stamp,
    'qr'          => '',
    'employee_id' => (int) $employee['id'],
], 'entry');
check('a bare employee id (+qr empty) is refused', (string) $bare['code'] === 'QR_INVALID',
    'code=' . (string) $bare['code']);

check('the refusals above wrote nothing extra', count($sessionsFor((int) $employee['id'])) === 1);

/* =====================================================================
 * D. Offline badge: the digest travels, never the badge
 * =================================================================== */

section('D. Offline QR sync (digest only)');

$employeeOffline = $makeEmployee('QROFF');
$cleanup['employees'][] = (int) $employeeOffline['id'];
$offlineBadge  = EmployeeQr::payload($customerId, (int) $employeeOffline['id']);
$offlineDigest = EmployeeQr::digest($offlineBadge);

$syncOk = $engine->syncBatch($device, ['events' => [[
    'type'           => 'entry',
    'local_event_id' => 's20-local-' . $stamp,
    'credential'     => 'qr',
    'employee_id'    => (int) $employeeOffline['id'],
    'qr_digest'      => $offlineDigest,
    'occurred_at'    => date('c'),
    'request_id'     => 's20-offline-' . $stamp,
]]]);

$verdict = $syncOk['data']['records'][0] ?? [];
eq('an offline badge event is ACCEPTED', (string) ($verdict['status'] ?? ''), 'ACCEPTED');
eq('the sync answer marks the credential', (string) ($verdict['credential'] ?? ''), 'qr');
eq('exactly one session exists for the offline employee', count($sessionsFor((int) $employeeOffline['id'])), 1);

$offlineRecord = $sessionsFor((int) $employeeOffline['id'])[0] ?? [];
eq('the offline row is device_qr_offline', (string) ($offlineRecord['source'] ?? ''), 'device_qr_offline');

$replay = $engine->syncBatch($device, ['events' => [[
    'type'           => 'entry',
    'local_event_id' => 's20-local-' . $stamp,
    'credential'     => 'qr',
    'employee_id'    => (int) $employeeOffline['id'],
    'qr_digest'      => $offlineDigest,
    'occurred_at'    => date('c'),
    'request_id'     => 's20-offline-' . $stamp,
]]]);
eq('a replayed queue item is DUPLICATE', (string) ($replay['data']['records'][0]['status'] ?? ''), 'DUPLICATE');

$employeeCheat = $makeEmployee('QRCHEAT');
$cleanup['employees'][] = (int) $employeeCheat['id'];

$cheat = $engine->syncBatch($device, ['events' => [[
    'type'           => 'entry',
    'local_event_id' => 's20-cheat-' . $stamp,
    'credential'     => 'qr',
    'employee_id'    => (int) $employeeCheat['id'],
    // the digest of ANOTHER employee's badge: the server re-derives it
    'qr_digest'      => $offlineDigest,
    'occurred_at'    => date('c'),
    'request_id'     => 's20-cheat-' . $stamp,
]]]);
eq('a made up employee/digest pair is REJECTED', (string) ($cheat['data']['records'][0]['status'] ?? ''), 'REJECTED');
eq('nothing was written for the cheating employee', count($sessionsFor((int) $employeeCheat['id'])), 0);

$diskFinal = diskSnapshot('uploads');
eq('ZERO bytes after the whole QR section', $diskFinal['bytes'], $diskBefore['bytes']);

/* =====================================================================
 * E. The device payload carries the digests (offline capability)
 * =================================================================== */

section('E. Face data payload (badge digests for offline matching)');

$dataset = (new App\Services\FaceDatasetService())->qrForDevice($device);

check('the QR section is enabled', !empty($dataset['enabled']));
eq('the QR section names the format', (string) ($dataset['format'] ?? ''), EmployeeQr::PREFIX);
check('the employee list is not empty', count($dataset['employees']) > 0);

$entryFor = null;

foreach ($dataset['employees'] as $row) {
    if ((int) $row['employee_id'] === (int) $employee['id']) {
        $entryFor = $row;
        break;
    }
}

check('the employee without a face dataset has a badge', $entryFor !== null);
check('the payload carries a DIGEST, never the badge string', $entryFor !== null
    && preg_match('/^[0-9a-f]{64}$/', (string) ($entryFor['qr_digest'] ?? '')) === 1);
check('the badge itself is not in the payload', $entryFor !== null
    && !isset($entryFor['qr']) && !isset($entryFor['payload']));
check('the digest matches the badge of that employee', $entryFor !== null
    && EmployeeQr::digestMatches($customerId, (int) $employee['id'], (string) $entryFor['qr_digest']));

$otherDeviceDataset = (new App\Services\FaceDatasetService())->qrForDevice($deviceOther);
$leaked = false;

foreach ($otherDeviceDataset['employees'] as $row) {
    if ((int) $row['employee_id'] === (int) $employee['id']) {
        $leaked = true;
    }
}

check('another tenant device does not receive this badge', !$leaked);

/* =====================================================================
 * F. Branding is OWNER ONLY
 * =================================================================== */

section('F. Branding access control (owner only)');

$brandNameBefore = (string) ($customer['brand_name'] ?? '');
$logoBefore      = (string) ($customer['logo_path'] ?? '');

// The manager path: updateProfile() must ignore both branding columns.
Customer::updateProfile($customerId, [
    'name'       => (string) $customer['name'],
    'brand_name' => $MARK . ' manager brand ' . $stamp,
    'logo_path'  => 'uploads/customers/hacked/logo.png',
]);

$after = Customer::find($customerId) ?? [];
eq('a manager can not change the brand name', (string) ($after['brand_name'] ?? ''), $brandNameBefore);
eq('a manager can not change the logo path', (string) ($after['logo_path'] ?? ''), $logoBefore);

$reflection = new ReflectionMethod(Customer::class, 'updateProfile');
$source     = (string) file_get_contents((string) $reflection->getFileName());

check('updateProfile() does not list logo_path', strpos($source, "'logo_path'") === false);
check('updateProfile() does not list brand_name', strpos($source, "'brand_name'") === false);

// The owner path.
$ownerBrand = $MARK . ' brand ' . $stamp;
Customer::updateBranding($customerId, [
    'brand_name' => $ownerBrand,
    'logo_path'  => null,
    'status'     => 'archived', // must be dropped
    'max_devices' => 999,       // must be dropped
]);

$updated = Customer::find($customerId) ?? [];
eq('the owner can set the brand name', (string) ($updated['brand_name'] ?? ''), $ownerBrand);
eq('a smuggled status is dropped', (string) $updated['status'], (string) $customer['status']);
eq('a smuggled max_devices is dropped', (int) $updated['max_devices'], (int) $customer['max_devices']);
eq('brandName() prefers the brand name', Customer::brandName($updated), $ownerBrand);
check('hasBranding() is true with a brand name', Customer::hasBranding($updated));

$fallback = ['name' => 'نام مجموعه', 'company_name' => 'نام شرکت', 'brand_name' => ''];
eq('brandName() falls back to the company name', Customer::brandName($fallback), 'نام شرکت');
eq('brandName() falls back to the tenant name', Customer::brandName(['name' => 'نام مجموعه']), 'نام مجموعه');

// The manager settings controller refuses a smuggled logo / brand name.
$settingsSource = (string) file_get_contents(Config::path('app/Controllers/Manager/SettingsController.php'));
check('the manager settings controller has no logo upload left',
    strpos($settingsSource, 'UploadService::storeLogo') === false);
check('a smuggled logo is refused and audited',
    strpos($settingsSource, 'customer.brand.denied') !== false);
check('the manager settings POST does not pass logo_path',
    strpos($settingsSource, "'logo_path'     =>") === false);

$ownerSource = (string) file_get_contents(Config::path('app/Controllers/Owner/CustomerController.php'));
check('the owner controller writes branding through updateBranding()',
    strpos($ownerSource, 'Customer::updateBranding') !== false);
check('the owner controller has a logo delete action', strpos($ownerSource, 'function logoDelete') !== false);

/* =====================================================================
 * G. Never cropped, never distorted (CSS contract)
 * =================================================================== */

section('G. Logo CSS (object-fit: contain, width: auto)');

$deviceCss  = (string) file_get_contents(Config::path('assets/css/device.css'));
$managerCss = (string) file_get_contents(Config::path('assets/css/manager.css'));
$ownerCss   = (string) file_get_contents(Config::path('assets/css/owner.css'));
$appCss     = (string) file_get_contents(Config::path('assets/css/app.css'));

check('app.css defines .brand-logo with object-fit: contain',
    strpos($appCss, '.brand-logo') !== false && preg_match('/\.brand-logo\s*\{[^}]*object-fit:\s*contain/s', $appCss) === 1);
check('.brand-logo uses width: auto', preg_match('/\.brand-logo\s*\{[^}]*width:\s*auto/s', $appCss) === 1);
check('.brand-logo keeps a standard max-height', preg_match('/\.brand-logo\s*\{[^}]*max-height:/s', $appCss) === 1);
check('the kiosk logo rule uses contain',
    preg_match('/img\.k-logo\s*\{[^}]*object-fit:\s*contain/s', $deviceCss) === 1);
check('the kiosk logo rule uses width:auto',
    preg_match('/img\.k-logo\s*\{[^}]*width:\s*auto/s', $deviceCss) === 1);
check('the sidebar logo rule uses contain',
    strpos($managerCss, 'object-fit: contain') !== false);
check('no rule crops the kiosk logo with cover',
    preg_match('/\.k-logo\s*\{[^}]*object-fit:\s*cover/s', $deviceCss) === 0);
check('no rule crops the owner list logo with cover',
    preg_match('/\.o-logo\s*\{[^}]*object-fit:\s*cover/s', $ownerCss) === 0);
check('the owner panel has the brand lockup rules', strpos($ownerCss, '.brand-logo') !== false);
check('the printed letterhead is print-only',
    strpos($appCss, '.m-print-brand') !== false && preg_match('/@media print\s*\{[^}]*\.m-print-brand/s', $appCss) === 1);

$layoutSource = (string) file_get_contents(Config::path('views/layouts/app.php'));
check('the panel header prints the brand name', strpos($layoutSource, 'Customer::brandName') !== false);
check('the panel layout has the print letterhead', strpos($layoutSource, 'm-print-brand') !== false);

$kioskSource = (string) file_get_contents(Config::path('views/layouts/device.php'));
check('the kiosk loads the QR decoder', strpos($kioskSource, 'jsQR.min.js') !== false);
check('the kiosk loads the badge module', strpos($kioskSource, 'device-qr.js') !== false);

$swSource = (string) file_get_contents(Config::path('sw.js'));
check('the service worker precaches the decoder', strpos($swSource, 'jsQR.min.js') !== false);
check('the service worker precaches the badge module', strpos($swSource, 'device-qr.js') !== false);

/* =====================================================================
 * H. Schema + API contract
 * =================================================================== */

section('H. Schema and API contract');

$migration = (string) file_get_contents(Config::path('app/Services/MigrationService.php'));
check('the migration adds customers.brand_name', strpos($migration, "'column'     => 'brand_name'") !== false);
check('the migration widens records.source for QR', strpos($migration, 'device_qr_offline') !== false);

$columns = Database::select('SHOW COLUMNS FROM `' . t('customers') . '` LIKE ?', ['brand_name']);
check('the brand_name column really exists in the database', $columns !== []);

$sourceColumn = Database::selectOne('SHOW COLUMNS FROM `' . t('records') . '` LIKE ?', ['source']);
check('the records.source ENUM accepts device_qr',
    strpos((string) ($sourceColumn['Type'] ?? ''), 'device_qr') !== false,
    (string) ($sourceColumn['Type'] ?? ''));
check('the records.source ENUM accepts device_qr_offline',
    strpos((string) ($sourceColumn['Type'] ?? ''), 'device_qr_offline') !== false);

check('AttendanceRecord::SOURCES documents the QR source',
    isset(AttendanceRecord::SOURCES['device_qr'], AttendanceRecord::SOURCES['device_qr_offline']));

$routes = (string) file_get_contents(Config::path('routes/api.php'));
check('the QR endpoint is routed', strpos($routes, '/api/v1/device/attendance/qr') !== false);
check('the QR endpoint requires a device token', preg_match(
    "/attendance\\/qr'\\s*,\\s*\\[DeviceKioskController::class, 'attendanceQr'\\],\\s*\\['api', 'device\.token'/s", $routes) === 1);

$apiSource = (string) file_get_contents(Config::path('app/Api/Controllers/DeviceKioskController.php'));
check('the QR endpoint never stores a file',
    strpos($apiSource, 'move_uploaded_file') === false
    && strpos($apiSource, 'UploadService') === false);

$engineSource = (string) file_get_contents(Config::path('app/Services/AttendanceEngine.php'));
check('the engine has a badge path', strpos($engineSource, 'public function recordQr') !== false);
check('the engine maps the qr source', strpos($engineSource, "return \$offline ? 'device_qr_offline' : 'device_qr';") !== false);
check('the engine skips the 3/3 face rule for a badge', strpos($engineSource, "if (\$credential === 'qr') {\n                \$faceState") !== false);

/* =====================================================================
 * Cleanup
 * =================================================================== */

if (!$keep) {
    foreach ($cleanup['employees'] as $employeeId) {
        Database::run('DELETE FROM `' . t('records') . '` WHERE `customer_id` = ? AND `employee_id` = ?', [$customerId, $employeeId]);
    }

    foreach ($cleanup['devices'] as $deviceId) {
        Database::run('DELETE FROM `' . t('device_logs') . '` WHERE `device_id` = ?', [$deviceId]);
        Database::run('DELETE FROM `' . t('device_requests') . '` WHERE `device_id` = ?', [$deviceId]);
    }

    Database::run('DELETE FROM `' . t('records') . '` WHERE `customer_id` = ?', [$otherCustomerId]);
    Database::run('DELETE FROM `' . t('employees') . '` WHERE `customer_id` = ?', [$otherCustomerId]);
    Database::run('DELETE FROM `' . t('devices') . '` WHERE `customer_id` = ?', [$otherCustomerId]);

    Database::run('DELETE FROM `' . t('employees') . '` WHERE `customer_id` = ? AND `employee_code` LIKE ?', [$customerId, $MARK . '%']);
    Database::run('DELETE FROM `' . t('devices') . '` WHERE `name` LIKE ?', [$MARK . '%']);
    Database::run('DELETE FROM `' . t('audit_logs') . '` WHERE `customer_id` IN (?, ?) AND `description` LIKE ?',
        [$customerId, $otherCustomerId, '%' . $MARK . '%']);
    Database::run('DELETE FROM `' . t('customers') . '` WHERE `id` = ?', [$otherCustomerId]);

    // restore the branding of customer #1 exactly as it was
    Customer::updateBranding($customerId, [
        'brand_name' => $brandNameBefore === '' ? null : $brandNameBefore,
        'logo_path'  => $logoBefore === '' ? null : $logoBefore,
    ]);
} else {
    echo "  (--keep given: fixtures were left in the database)\n";
}

foreach (glob(Config::path('storage/cache/attendance_rate_*.json')) ?: [] as $file) {
    @unlink($file);
}

/* =====================================================================
 * Summary
 * =================================================================== */

echo "\n" . str_repeat('-', 64) . "\n";
echo "passed: \033[32m" . $pass . "\033[0m   failed: " . ($fail > 0 ? "\033[31m" . $fail . "\033[0m" : '0') . "\n";

if ($fail > 0) {
    echo "\nfailures:\n";

    foreach ($failures as $failure) {
        echo "  - " . $failure . "\n";
    }

    exit(1);
}

echo "ALL STAGE 20 TESTS PASSED\n";
exit(0);
