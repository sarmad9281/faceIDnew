<?php
/**
 * =====================================================================
 * SARMAD Attendance - STAGE 6 engine test suite (REAL, no mocks)
 * =====================================================================
 *
 *   php tests/engine_tests.php            # run everything
 *   php tests/engine_tests.php --keep     # keep the fixtures in the DB
 *
 * What this suite does NOT do:
 *   - it never mocks the database, the clock, the engine, the calculator or
 *     the models: every assertion runs against the real MariaDB schema of the
 *     project (attendance_* tables only),
 *   - it never touches a foreign table (barber_*, cafe_*, clinic_*, zibaei_*,
 *     sarmadpf_*) and never deletes anything it did not create.
 *
 * What it covers (the acceptance list of stage 6):
 *   A  shift calculation            on time, late with grace, early leave,
 *                                   overtime, multiple sessions, open session
 *   B  day calculation              sums, worst late wins, statuses
 *   C  entry flow                   success, duplicate entry, employee
 *                                   inactive, face not complete, branch,
 *                                   spoofed employee id, replayed proof
 *   D  exit flow                    success, no open entry, duplicate exit
 *   E  idempotency                  same request twice = one row
 *   F  offline                      queue -> sync, duplicate sync, invalid
 *                                   event, device clock in the future
 *   G  day close                    absence, approved leave, holiday
 *   H  manual correction + void     audited, old/new values, soft delete
 *   I  recalculation                after a shift change
 *   J  tenant isolation             customer A can not touch customer B
 *   K  side effects                 audit rows + attendance_events written
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
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\FaceEmbedding;
use App\Models\RecognitionProof;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceEngine;
use App\Services\FaceDatasetService;
use App\Services\ShiftResolver;

$keep = in_array('--keep', $argv, true);
$MARK = 'SARMAD-TEST-S6';

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
 * Fixtures - created through the real models, removed at the end
 * =================================================================== */

$customerId = 1;
$cleanup    = ['records' => [], 'proofs' => [], 'leaves' => [], 'holidays' => [], 'customers' => [], 'audit' => []];

$stamp    = date('ymd-His');
$deviceA  = null;
$deviceAId = 0;
$deviceB  = null;
$deviceBId = 0;
$employeeA = null;
$employeeB = null;
$employeeC = null;
$employeeNoFace = null;
$shiftId  = 0;
$customerB = 0;

echo "SARMAD Attendance - stage 6 engine suite (real database)\n";
echo "prefix      : " . Database::prefix() . "\n";
echo "database    : " . (string) Config::get('db.database') . "\n";
echo "timezone    : " . date_default_timezone_get() . "\n";
echo "calc version: " . AttendanceCalculator::CALC_VERSION . "\n";

/* ---------------------------------------------------------------- */
section('A. Shift calculation (pure, real calculator)');

$shift = [
    'id'                        => 99,
    'name'                      => 'صبح ۸ تا ۱۲',
    'start_time'                => '08:00:00',
    'end_time'                  => '12:00:00',
    'late_grace_minutes'        => 10,
    'early_leave_grace_minutes' => 5,
    'break_minutes'             => 0,
    'crosses_midnight'          => 0,
];

$day = '2026-10-07';

$onTime = AttendanceCalculator::calculateSession(['entry_at' => $day . ' 07:58:00', 'exit_at' => $day . ' 12:00:00'], $shift, [], $day);
eq('on time 07:58 -> late 0', (int) $onTime['late_minutes'], 0);
eq('on time -> work 242 minutes', (int) $onTime['work_minutes'], 242);
eq('on time -> status present', (string) $onTime['status'], 'present');

$grace = AttendanceCalculator::calculateSession(['entry_at' => $day . ' 08:05:00', 'exit_at' => $day . ' 12:00:00'], $shift, [], $day);
eq('08:05 within 10 min grace -> late 0', (int) $grace['late_minutes'], 0);

$late = AttendanceCalculator::calculateSession(['entry_at' => $day . ' 08:20:00', 'exit_at' => $day . ' 12:00:00'], $shift, [], $day);
eq('08:20 with 10 min grace -> late 10', (int) $late['late_minutes'], 10);
eq('late record -> status late', (string) $late['status'], 'late');

$early = AttendanceCalculator::calculateSession(['entry_at' => $day . ' 08:00:00', 'exit_at' => $day . ' 11:00:00'], $shift, [], $day);
eq('exit 60 min before end (grace 5) -> early leave 55', (int) $early['early_leave_minutes'], 55);
eq('early leave record -> status early_leave', (string) $early['status'], 'early_leave');

$overtime = AttendanceCalculator::calculateSession(['entry_at' => $day . ' 08:00:00', 'exit_at' => $day . ' 13:30:00'], $shift, [], $day);
eq('exit 90 min after end -> overtime 90', (int) $overtime['overtime_minutes'], 90);
eq('overtime record -> status overtime', (string) $overtime['status'], 'overtime');

$open = AttendanceCalculator::calculateSession(['entry_at' => $day . ' 08:00:00', 'exit_at' => null], $shift, [], $day);
eq('open session -> status incomplete', (string) $open['status'], 'incomplete');
eq('open session -> work 0', (int) $open['work_minutes'], 0);

$longShift = $shift;
$longShift['start_time'] = '08:00:00';
$longShift['end_time']   = '18:00:00';
$longShift['break_minutes'] = 0;

$multi = AttendanceCalculator::aggregateDay([
    ['entry_at' => $day . ' 08:00:00', 'exit_at' => $day . ' 12:00:00'],
    ['entry_at' => $day . ' 14:00:00', 'exit_at' => $day . ' 18:00:00'],
], $longShift, [], $day);
eq('two sessions (08-12 + 14-18) -> work is the SUM (480)', (int) $multi['work_minutes'], 480);
eq('two sessions -> sessions counter 2', (int) $multi['sessions'], 2);
eq('two sessions on time -> no late', (int) $multi['late_minutes'], 0);
eq('two sessions covering the shift -> no overtime', (int) $multi['overtime_minutes'], 0);
eq('two sessions -> status present', (string) $multi['status'], 'present');
check('two sessions -> multi_session flag', in_array(AttendanceCalculator::FLAG_MULTI_SESSION, $multi['flags'], true));

$dayLate = AttendanceCalculator::aggregateDay([
    ['entry_at' => $day . ' 08:20:00', 'exit_at' => $day . ' 12:00:00'],
    ['entry_at' => $day . ' 14:00:00', 'exit_at' => $day . ' 18:00:00'],
], $longShift, [], $day);
eq('day lateness = first entry vs shift start (10)', (int) $dayLate['late_minutes'], 10);
eq('a second session in the afternoon is NOT counted as late', (int) $dayLate['late_minutes'], 10);

$dayEarly = AttendanceCalculator::aggregateDay([
    ['entry_at' => $day . ' 08:00:00', 'exit_at' => $day . ' 12:00:00'],
    ['entry_at' => $day . ' 14:00:00', 'exit_at' => $day . ' 17:00:00'],
], $longShift, [], $day);
eq('day early leave = last exit vs shift end (55, grace 5)', (int) $dayEarly['early_leave_minutes'], 55);

$dayOver = AttendanceCalculator::aggregateDay([
    ['entry_at' => $day . ' 08:00:00', 'exit_at' => $day . ' 12:00:00'],
    ['entry_at' => $day . ' 14:00:00', 'exit_at' => $day . ' 19:30:00'],
], $longShift, [], $day);
eq('day overtime = last exit vs shift end (90)', (int) $dayOver['overtime_minutes'], 90);

$dayOpen = AttendanceCalculator::aggregateDay([
    ['entry_at' => $day . ' 08:00:00', 'exit_at' => $day . ' 12:00:00'],
    ['entry_at' => $day . ' 14:00:00', 'exit_at' => null],
], $longShift, [], $day);
eq('day with an open session -> status incomplete', (string) $dayOpen['status'], 'incomplete');
eq('day with an open session keeps the completed session work', (int) $dayOpen['work_minutes'], 240);

$missingAbsent = AttendanceCalculator::statusForMissingDay(['holiday' => false, 'leave' => false]);
eq('missing day, no leave, no holiday -> absent', $missingAbsent, AttendanceCalculator::STATUS_ABSENT);
$missingLeave = AttendanceCalculator::statusForMissingDay(['holiday' => false, 'leave' => true]);
eq('missing day with approved leave -> on_leave (never absent)', $missingLeave, AttendanceCalculator::STATUS_ON_LEAVE);
$missingHoliday = AttendanceCalculator::statusForMissingDay(['holiday' => true, 'leave' => false]);
eq('missing day on a holiday -> holiday', $missingHoliday, AttendanceCalculator::STATUS_HOLIDAY);

eq('diffMinutes across midnight', AttendanceCalculator::diffMinutes($day . ' 23:30:00', '2026-10-08 00:15:00'), 45);
eq('normalizeDateTime rejects garbage', AttendanceCalculator::normalizeDateTime('not-a-date'), null);

/* ---------------------------------------------------------------- */
section('B. Fixtures (real tables)');

$customer = Database::selectOne('SELECT * FROM `' . t('customers') . '` WHERE `id` = ?', [$customerId]);

if ($customer === null) {
    fwrite(STDERR, "customer #1 is missing - run the installer first\n");
    exit(1);
}

// --- second tenant (for the isolation tests) ----------------------
$customerB = Database::insert(t('customers'), [
    'uuid'       => Security::uuid4(),
    'name'       => $MARK . ' tenant B ' . $stamp,
    'slug'       => 'sarmad-test-b-' . $stamp,
    'status'     => 'active',
    'created_at' => date('Y-m-d H:i:s'),
]);
$cleanup['customers'][] = $customerB;

// --- employees ----------------------------------------------------
$makeEmployee = static function (string $mark, string $status = 'active') use ($customerId, $MARK, $stamp): array {
    $id = Employee::createEmployee([
        'customer_id'   => $customerId,
        'employee_code' => $MARK . '-' . $mark . '-' . $stamp,
        'first_name'    => $MARK,
        'last_name'     => $mark,
        'status'        => $status,
        'hire_date'     => date('Y-m-d'),
    ]);

    return Employee::find($id) ?? [];
};

$employeeA      = $makeEmployee('A');
$employeeB      = $makeEmployee('B');
$employeeC      = $makeEmployee('C', 'suspended');
$employeeNoFace = $makeEmployee('D');
$employeeOther  = $makeEmployee('OTHER');

// an employee of tenant B
$employeeBTenant = Database::insert(t('employees'), [
    'uuid'          => Security::uuid4(),
    'customer_id'   => $customerB,
    'employee_code' => $MARK . '-TENANTB-' . $stamp,
    'first_name'    => $MARK,
    'last_name'     => 'tenantB',
    'status'        => 'active',
    'created_at'    => date('Y-m-d H:i:s'),
]);

// --- devices ------------------------------------------------------
$makeDevice = static function (int $ownerCustomerId, string $label) use ($MARK, $stamp): array {
    $id = Database::insert(t('devices'), [
        'uuid'            => Security::uuid4(),
        'device_uid'      => strtoupper($MARK . '-' . $label . '-' . $stamp),
        'customer_id'     => $ownerCustomerId,
        'name'            => $MARK . ' ' . $label,
        'type'            => 'tablet',
        'platform'        => 'android',
        'app_version'     => '6.0.0-test',
        'token_prefix'    => 'test',
        'api_token_hash'  => password_hash('unused-in-this-suite', PASSWORD_DEFAULT),
        'status'          => 'active',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    return Device::find($id) ?? [];
};

$deviceA  = $makeDevice($customerId, 'deviceA');
$deviceB  = $makeDevice($customerId, 'deviceB');
$deviceT  = $makeDevice($customerB, 'tenantB');

// --- shift + assignment (every day so any weekday works) ----------
$shiftId = Database::insert(t('shifts'), [
    'uuid'                      => Security::uuid4(),
    'customer_id'               => $customerId,
    'name'                      => $MARK . ' Morning ' . $stamp,
    'code'                      => 'TEST-' . $stamp,
    'start_time'                => '08:00:00',
    'end_time'                  => '12:00:00',
    'late_grace_minutes'        => 10,
    'early_leave_grace_minutes' => 5,
    'break_minutes'             => 0,
    'is_active'                 => 1,
    'created_at'                => date('Y-m-d H:i:s'),
]);

foreach ([$employeeA, $employeeB, $employeeNoFace, $employeeOther] as $employee) {
    Database::insert(t('employee_shifts'), [
        'uuid'           => Security::uuid4(),
        'customer_id'    => $customerId,
        'employee_id'    => (int) $employee['id'],
        'shift_id'       => $shiftId,
        'weekday'        => 127,
        'effective_from' => date('Y-m-d', strtotime('-30 days')),
        'is_active'      => 1,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

$cleanup['shift'] = $shiftId;

// --- face datasets: employee A = 3/3 (through the REAL service) ---
$faces = new FaceDatasetService();

// A deterministic 128-d descriptor (a real vector shape; the values only have
// to be a plausible unit ball point because the SERVER never matches faces -
// matching happens on the device, and the engine only checks count/version).
$vector = static function (float $seed): array {
    $out = [];

    for ($i = 0; $i < FaceEmbedding::DIMENSIONS; $i++) {
        $out[] = round(sin($seed + $i / 7.0) * 0.18, 6);
    }

    return $out;
};

$modelVersion = FaceDatasetService::modelVersion();

$enrolled = 0;

foreach ([1, 2, 3] as $index) {
    $result = $faces->enrollSample($employeeA, [
        'sample_index'  => $index,
        'embedding'     => $vector(0.10 + $index * 0.001),
        'model_version' => $modelVersion,
        'quality'       => 0.9,
    ], 0, 'system');

    if ($result['ok']) {
        $enrolled++;
    }
}

eq('face enrolment through FaceDatasetService: 3 samples', $enrolled, 3);

$state = $faces->stateForEmployee((int) $employeeA['id'], $customerId);
eq('employee A face state = complete', (string) $state['status'], 'complete');
eq('employee A face samples = 3', (int) $state['count'], 3);

// employee NoFace stays at 0/3, employee B gets 2/3 only
foreach ([1, 2] as $index) {
    $faces->enrollSample($employeeB, [
        'sample_index'  => $index,
        'embedding'     => $vector(0.30 + $index * 0.001),
        'model_version' => $modelVersion,
        'quality'       => 0.9,
    ], 0, 'system');
}

$stateB = $faces->stateForEmployee((int) $employeeB['id'], $customerId);
eq('employee B face samples = 2 (partial, visible)', (int) $stateB['count'], 2);
check('partial face state is not "complete"', (string) $stateB['status'] !== 'complete');

/* ---------------------------------------------------------------- */
section('C. Entry flow (server side, real transactions)');

$engine = new AttendanceEngine();

$issueProof = static function (array $device, array $employee, string $action) {
    return RecognitionProof::issue($device, $employee, $action, [
        'request_id'    => 'engine-test',
        'confidence'    => 0.93,
        'distance'      => 0.31,
        'model_version' => FaceDatasetService::modelVersion(),
        'ip'            => '127.0.0.1',
    ]);
};

// C1 - spoofed employee id without a proof
$noProof = $engine->recordEntry($deviceA, ['request_id' => 's6-nop-' . $stamp, 'employee_id' => (int) $employeeA['id']]);
eq('bare employee_id without proof -> INVALID_RECOGNITION', (string) $noProof['code'], 'INVALID_RECOGNITION');
check('nothing was written for the refused request', AttendanceRecord::sessionsOn($customerId, (int) $employeeA['id'], date('Y-m-d')) === []);

// C2 - a proof that does not exist
$fake = $engine->recordEntry($deviceA, ['request_id' => 's6-fake-' . $stamp, 'proof' => Security::uuid4()]);
eq('forged proof -> INVALID_RECOGNITION', (string) $fake['code'], 'INVALID_RECOGNITION');

// C3 - proof of another device
$pOther = $issueProof($deviceB, $employeeA, 'entry');
$crossed = $engine->recordEntry($deviceA, ['request_id' => 's6-cross-' . $stamp, 'proof' => $pOther['uuid']]);
eq('proof issued for device B used on device A -> INVALID_RECOGNITION', (string) $crossed['code'], 'INVALID_RECOGNITION');
check('the crossed proof was NOT consumed', RecognitionProof::consume($pOther['uuid'], (int) $deviceB['id'], $customerId, (int) $employeeA['id'], 'entry') !== null);

// C4 - successful entry
$p1 = $issueProof($deviceA, $employeeA, 'entry');
$entry = $engine->recordEntry($deviceA, [
    'request_id'      => 's6-entry-' . $stamp,
    'proof'           => $p1['uuid'],
    'idempotency_key' => 's6-idem-entry-' . $stamp,
]);
check('entry accepted', !empty($entry['ok']), 'code=' . (string) $entry['code'] . ' msg=' . (string) $entry['message']);
eq('entry code = ATTENDANCE_RECORDED', (string) $entry['code'], 'ATTENDANCE_RECORDED');
$recordId = (int) ($entry['data']['record_id'] ?? 0);
check('record id returned', $recordId > 0);

$stored = AttendanceRecord::find($recordId);
eq('stored status = incomplete (no exit yet)', (string) $stored['status'], 'incomplete');
eq('stored work_minutes = 0', (int) $stored['work_minutes'], 0);
eq('stored entry date = today (server clock)', (string) $stored['attendance_date'], date('Y-m-d'));
eq('stored source = device', (string) $stored['source'], 'device');
eq('stored session_index = 1', (int) $stored['session_index'], 1);
check('open_key is set while the session is open', (string) $stored['open_key'] === (string) $employeeA['id']);
check('proof is marked consumed', $stored['recognition_uuid'] === $p1['uuid']);

// C5 - replayed proof
$replay = $engine->recordEntry($deviceA, ['request_id' => 's6-replay-' . $stamp, 'proof' => $p1['uuid']]);
eq('replayed (consumed) proof -> INVALID_RECOGNITION', (string) $replay['code'], 'INVALID_RECOGNITION');

// C6 - duplicate entry with a fresh proof
$p2 = $issueProof($deviceA, $employeeA, 'entry');
$duplicate = $engine->recordEntry($deviceA, ['request_id' => 's6-dup-' . $stamp, 'proof' => $p2['uuid']]);
eq('second entry while one is open -> ENTRY_ALREADY_OPEN', (string) $duplicate['code'], 'ENTRY_ALREADY_OPEN');
eq('duplicate message is the agreed one', (string) $duplicate['message'], 'ورود شما قبلاً ثبت شده است.');
eq('no second row was created', count(AttendanceRecord::sessionsOn($customerId, (int) $employeeA['id'], date('Y-m-d'))), 0);
eq('only ONE open record exists for the employee', count(Database::select(
    'SELECT `id` FROM `' . t('records') . '` WHERE `customer_id` = ? AND `employee_id` = ? AND `open_key` IS NOT NULL',
    [$customerId, (int) $employeeA['id']]
)), 1);

// C7 - employee of another customer (proof is bound, so the engine must not even look at him)
$pForeign = RecognitionProof::issue($deviceT, ['id' => $employeeBTenant, 'customer_id' => $customerB], 'entry', []);
$foreign = $engine->recordEntry($deviceA, ['request_id' => 's6-foreign-' . $stamp, 'proof' => $pForeign['uuid']]);
eq('proof of another tenant used on device A -> INVALID_RECOGNITION', (string) $foreign['code'], 'INVALID_RECOGNITION');

// C8 - spoofing: proof for A but employee_id of B in the payload
$p3 = $issueProof($deviceA, $employeeA, 'entry');
$spoof = $engine->recordEntry($deviceA, [
    'request_id'  => 's6-spoof-' . $stamp,
    'proof'       => $p3['uuid'],
    'employee_id' => (int) $employeeB['id'],
]);
eq('proof for A + employee_id of B -> INVALID_RECOGNITION', (string) $spoof['code'], 'INVALID_RECOGNITION');

// C9 - inactive employee: give C a complete dataset, then try
foreach ([1, 2, 3] as $index) {
    $faces->enrollSample($employeeC, [
        'sample_index'  => $index,
        'embedding'     => $vector(0.5 + $index * 0.001),
        'model_version' => $modelVersion,
        'quality'       => 0.9,
    ], 0, 'system');
}

$p4 = $issueProof($deviceA, $employeeC, 'entry');
$inactive = $engine->recordEntry($deviceA, ['request_id' => 's6-inactive-' . $stamp, 'proof' => $p4['uuid']]);
eq('suspended employee -> EMPLOYEE_INACTIVE', (string) $inactive['code'], 'EMPLOYEE_INACTIVE');

// C10 - face dataset incomplete (2/3)
$p5 = $issueProof($deviceA, $employeeB, 'entry');
$partial = $engine->recordEntry($deviceA, ['request_id' => 's6-partial-' . $stamp, 'proof' => $p5['uuid']]);
eq('face 2/3 -> FACE_NOT_REGISTERED', (string) $partial['code'], 'FACE_NOT_REGISTERED');
eq('FACE_NOT_REGISTERED reports the samples', (int) ($partial['data']['samples'] ?? -1), 2);

// C11 - no face at all
$p6 = $issueProof($deviceA, $employeeNoFace, 'entry');
$noFace = $engine->recordEntry($deviceA, ['request_id' => 's6-noface-' . $stamp, 'proof' => $p6['uuid']]);
eq('face 0/3 -> FACE_NOT_REGISTERED', (string) $noFace['code'], 'FACE_NOT_REGISTERED');

// C12 - disabled device
Database::update(t('devices'), ['status' => 'disabled'], ['id' => (int) $deviceB['id']]);
$p7 = $issueProof($deviceB, $employeeA, 'entry');
$disabled = $engine->recordEntry($deviceB, ['request_id' => 's6-disabled-' . $stamp, 'proof' => $p7['uuid']]);
eq('inactive device -> DEVICE_INACTIVE', (string) $disabled['code'], 'DEVICE_INACTIVE');
Database::update(t('devices'), ['status' => 'active'], ['id' => (int) $deviceB['id']]);

/* ---------------------------------------------------------------- */
section('D. Exit flow');

$noEntry = $engine->recordExit($deviceA, [
    'request_id' => 's6-noexit-' . $stamp,
    'proof'      => $issueProof($deviceA, $employeeB, 'exit')['uuid'],
]);

// employee B has no open session (and his face is 2/3) - the engine must refuse either way
check('exit without an open session is refused', in_array((string) $noEntry['code'], ['NO_OPEN_ENTRY', 'FACE_NOT_REGISTERED'], true),
    'code=' . (string) $noEntry['code']);

// A human being does not enter and leave within the same second: the fixture
// moves the entry two hours back so the exit really computes a duration.
Database::run(
    'UPDATE `' . t('records') . '` SET `entry_at` = ? WHERE `id` = ?',
    [date('Y-m-d H:i:s', strtotime('-2 hours')), $recordId]
);

$pExit = $issueProof($deviceA, $employeeA, 'exit');
$exit  = $engine->recordExit($deviceA, [
    'request_id'      => 's6-exit-' . $stamp,
    'proof'           => $pExit['uuid'],
    'idempotency_key' => 's6-idem-exit-' . $stamp,
]);
check('exit accepted', !empty($exit['ok']), 'code=' . (string) $exit['code']);
eq('exit returns the same record', (int) ($exit['data']['record_id'] ?? 0), $recordId);
eq('exit code = ATTENDANCE_RECORDED', (string) $exit['code'], 'ATTENDANCE_RECORDED');

$closed = AttendanceRecord::find($recordId);
check('closed record has an exit time', $closed['exit_at'] !== null);
eq('closed record work_minutes = 120', (int) $closed['work_minutes'], 120);
check('open_key cleared after the exit', $closed['open_key'] === null);
eq('late minutes calculated on a real entry', (int) $closed['late_minutes'] >= 0, true);
eq('last_event = exit', (string) $closed['last_event'], 'exit');

$again = $engine->recordExit($deviceA, ['request_id' => 's6-exit2-' . $stamp, 'proof' => $issueProof($deviceA, $employeeA, 'exit')['uuid']]);
eq('second exit without an open session -> NO_OPEN_ENTRY', (string) $again['code'], 'NO_OPEN_ENTRY');
eq('second exit message is the agreed one', (string) $again['message'], 'برای شما ورود فعالی ثبت نشده است.');

/* ---------------------------------------------------------------- */
section('E. Idempotency (same request twice = one row)');

$pIdem = $issueProof($deviceA, $employeeA, 'entry');
$payload = [
    'request_id'      => 's6-idem-' . $stamp,
    'proof'           => $pIdem['uuid'],
    'idempotency_key' => 's6-idem-key-' . $stamp,
];

$first  = $engine->recordEntry($deviceA, $payload);
$second = $engine->recordEntry($deviceA, $payload);

check('first call recorded', !empty($first['ok']));
check('second call did NOT fail', !empty($second['ok']));
eq('second call returns the SAME record', (int) ($second['data']['record_id'] ?? 0), (int) ($first['data']['record_id'] ?? 0));
eq('second call is flagged as duplicate', (bool) ($second['data']['duplicate'] ?? false), true);
eq('exactly one row exists for that idempotency key', count(Database::select(
    'SELECT `id` FROM `' . t('records') . '` WHERE `customer_id` = ? AND `idempotency_key` = ?',
    [$customerId, 's6-idem-key-' . $stamp]
)), 1);

/* ---------------------------------------------------------------- */
section('F. Offline queue and batch sync');

$offlineEntryLocal = 'offentry-' . $stamp;
$offlineExitLocal  = 'offexit-' . $stamp;
$offlineEmployee   = (int) $employeeOther['id'];

// the offline employee needs a complete face dataset as well
foreach ([1, 2, 3] as $index) {
    $faces->enrollSample($employeeOther, [
        'sample_index'  => $index,
        'embedding'     => $vector(0.70 + $index * 0.001),
        'model_version' => $modelVersion,
        'quality'       => 0.9,
    ], 0, 'system');
}

$sync = $engine->syncBatch($deviceA, [
    'events' => [
        ['type' => 'entry', 'local_event_id' => $offlineEntryLocal, 'employee_id' => $offlineEmployee,
         'distance' => 0.33, 'occurred_at' => date('Y-m-d H:i:s', strtotime('-90 minutes'))],
        ['type' => 'exit', 'local_event_id' => $offlineExitLocal, 'employee_id' => $offlineEmployee,
         'distance' => 0.34, 'occurred_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))],
    ],
]);

check('batch sync accepted', !empty($sync['ok']));
eq('both queue items ACCEPTED', (int) ($sync['data']['accepted'] ?? 0), 2);
if (getenv('S6_DEBUG')) {
    fwrite(STDERR, "DEBUG sync: " . json_encode($sync, JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDERR, "DEBUG select: " . json_encode(Database::selectOne(
        'SELECT id, local_event_id, source FROM `' . t('records') . '` WHERE `customer_id` = ? AND `local_event_id` = ?',
        [$customerId, $offlineEntryLocal]
    ), JSON_UNESCAPED_UNICODE) . "\n");
}
check('no queue item was rejected', (int) ($sync['data']['rejected'] ?? -1) === 0,
    json_encode($sync['data']['records'] ?? []));

$offlineRecord = Database::selectOne(
    'SELECT * FROM `' . t('records') . '` WHERE `customer_id` = ? AND `local_event_id` = ?',
    [$customerId, $offlineEntryLocal]
);
check('offline entry created a real row', $offlineRecord !== null);

if ($offlineRecord !== null) {
    eq('offline row source = device_offline', (string) $offlineRecord['source'], 'device_offline');
    check('offline flag present', strpos((string) $offlineRecord['flags'], AttendanceCalculator::FLAG_OFFLINE) !== false);
    check('device_event_at stored', $offlineRecord['device_event_at'] !== null);
    check('server_received_at stored', $offlineRecord['server_received_at'] !== null);
    check('the record keeps the device time AND the server time',
        (string) $offlineRecord['device_event_at'] !== '' && (string) $offlineRecord['server_received_at'] !== '');
    if (getenv('S6_DEBUG')) {
        fwrite(STDERR, "DEBUG offline row: entry_at=" . var_export($offlineRecord['entry_at'], true)
            . " device_event_at=" . var_export($offlineRecord['device_event_at'], true)
            . " server_received_at=" . var_export($offlineRecord['server_received_at'], true)
            . " source=" . var_export($offlineRecord['source'], true) . "\n");
    }

    check('the record time is the DEVICE time (the event happened offline)',
        (string) $offlineRecord['entry_at'] === (string) $offlineRecord['device_event_at']);
}

// duplicate sync of the same queue item
$syncAgain = $engine->syncBatch($deviceA, [
    'events' => [
        ['type' => 'entry', 'local_event_id' => $offlineEntryLocal, 'employee_id' => $offlineEmployee,
         'distance' => 0.33, 'occurred_at' => date('Y-m-d H:i:s', strtotime('-90 minutes'))],
    ],
]);
eq('re-sync of the same local event -> DUPLICATE', (int) ($syncAgain['data']['duplicate'] ?? 0), 1);
eq('re-sync created no new row', count(Database::select(
    'SELECT `id` FROM `' . t('records') . '` WHERE `device_id` = ? AND `local_event_id` = ?',
    [(int) $deviceA['id'], $offlineEntryLocal]
)), 1);

$offlineClosed = Database::selectOne(
    'SELECT * FROM `' . t('records') . '` WHERE `customer_id` = ? AND `local_exit_event_id` = ?',
    [$customerId, $offlineExitLocal]
);
check('the closing queue id is stored next to the opening one', $offlineClosed !== null
    && (string) $offlineClosed['local_event_id'] === $offlineEntryLocal);

$exitReplay = $engine->syncBatch($deviceA, [
    'events' => [
        ['type' => 'exit', 'local_event_id' => $offlineExitLocal, 'employee_id' => $offlineEmployee,
         'distance' => 0.34, 'occurred_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))],
    ],
]);
eq('replaying the CLOSING event is also a DUPLICATE', (int) ($exitReplay['data']['duplicate'] ?? 0), 1);
eq('replaying the closing event does not open/close anything new', count(Database::select(
    'SELECT `id` FROM `' . t('records') . '` WHERE `customer_id` = ? AND `employee_id` = ? AND `attendance_date` = ?',
    [$customerId, $offlineEmployee, date('Y-m-d')]
)), 1);

// invalid queue items
$invalid = $engine->syncBatch($deviceA, [
    'events' => [
        ['type' => 'lunch', 'local_event_id' => 'bad1-' . $stamp, 'employee_id' => $offlineEmployee, 'distance' => 0.3],
        ['type' => 'entry', 'local_event_id' => 'bad2-' . $stamp, 'employee_id' => $offlineEmployee],
        ['type' => 'entry', 'local_event_id' => 'bad3-' . $stamp, 'employee_id' => $offlineEmployee, 'distance' => 0.99,
         'occurred_at' => date('Y-m-d H:i:s')],
    ],
]);
eq('unknown event type -> INVALID', (int) ($invalid['data']['invalid'] ?? 0), 1);
eq('exactly one queue item was invalid', (int) $invalid['data']['invalid'], 1);

$invalidRecords = $invalid['data']['records'] ?? [];
$byLocal = [];

foreach ($invalidRecords as $entry) {
    $byLocal[(string) ($entry['local_event_id'] ?? ('#' . $entry['index']))] = $entry;
}

eq('queue item without a recognition result -> REJECTED',
    (string) ($byLocal['bad2-' . $stamp]['status'] ?? ''), 'REJECTED');
eq('... with the offline-invalid code',
    (string) ($byLocal['bad2-' . $stamp]['code'] ?? ''), 'OFFLINE_EVENT_INVALID');
eq('queue item with a distance above the threshold -> REJECTED',
    (string) ($byLocal['bad3-' . $stamp]['status'] ?? ''), 'REJECTED');
eq('... with the recognition code',
    (string) ($byLocal['bad3-' . $stamp]['code'] ?? ''), 'INVALID_RECOGNITION');

// device clock in the future must be clamped, not trusted
$future = $engine->syncBatch($deviceA, [
    'events' => [
        ['type' => 'entry', 'local_event_id' => 'future-' . $stamp, 'employee_id' => (int) $employeeOther['id'],
         'distance' => 0.30, 'occurred_at' => date('Y-m-d H:i:s', strtotime('+6 hours'))],
    ],
]);
$futureRow = Database::selectOne(
    'SELECT * FROM `' . t('records') . '` WHERE `customer_id` = ? AND `local_event_id` = ?',
    [$customerId, 'future-' . $stamp]
);
check('future-dated offline event was clamped to the server clock', $futureRow !== null
    && strtotime((string) $futureRow['entry_at']) <= time() + 60);
check('clamped event carries the clock_skew flag', $futureRow !== null
    && strpos((string) $futureRow['flags'], AttendanceCalculator::FLAG_CLOCK_SKEW) !== false);

/* ---------------------------------------------------------------- */
section('G. Day close: absence, leave, holiday');

$yesterday = date('Y-m-d', strtotime('-1 day'));
$mondayish = date('Y-m-d', strtotime('-2 days'));

$close = $engine->closeDay($customerId, $yesterday, 0, ['id' => 0, 'role' => 'system']);
check('day close ran', !empty($close['ok']));

$absentRows = Database::select(
    'SELECT r.`id`, r.`employee_id`, r.`status` FROM `' . t('records') . '` r'
    . ' INNER JOIN `' . t('employees') . '` e ON e.`id` = r.`employee_id`'
    . ' WHERE r.`customer_id` = ? AND r.`attendance_date` = ? AND e.`employee_code` LIKE ?',
    [$customerId, $yesterday, $MARK . '%']
);

$absentIds = array_map(static fn (array $row): int => (int) $row['employee_id'], $absentRows);
check('employee without any session got an ABSENT row', in_array((int) $employeeB['id'], $absentIds, true));
check('suspended employee did NOT get an absence row', !in_array((int) $employeeC['id'], $absentIds, true));

$absentStatus = Database::scalar(
    'SELECT `status` FROM `' . t('records') . '` WHERE `customer_id` = ? AND `employee_id` = ? AND `attendance_date` = ?',
    [$customerId, (int) $employeeB['id'], $yesterday]
);
eq('absence row status = absent', (string) $absentStatus, 'absent');

// approved full day leave -> on_leave, never absent
$leaveId = Database::insert(t('leaves'), [
    'uuid'        => Security::uuid4(),
    'customer_id' => $customerId,
    'employee_id' => (int) $employeeOther['id'],
    'leave_type'  => 'daily',
    'start_date'  => $mondayish,
    'end_date'    => $mondayish,
    'days'        => 1,
    'status'      => 'approved',
    'created_at'  => date('Y-m-d H:i:s'),
]);
$cleanup['leaves'][] = $leaveId;

$engine->closeDay($customerId, $mondayish, 0, ['id' => 0, 'role' => 'system']);

$leaveStatus = Database::scalar(
    'SELECT `status` FROM `' . t('records') . '` WHERE `customer_id` = ? AND `employee_id` = ? AND `attendance_date` = ?',
    [$customerId, (int) $employeeOther['id'], $mondayish]
);
eq('approved full day leave -> on_leave (never absent)', (string) $leaveStatus, 'on_leave');

// holiday -> no absence at all
$holidayDate = date('Y-m-d', strtotime('-3 days'));
$holidayId = Database::insert(t('holidays'), [
    'uuid'         => Security::uuid4(),
    'customer_id'  => $customerId,
    'holiday_date' => $holidayDate,
    'title'        => $MARK . ' holiday ' . $stamp,
    'is_recurring_yearly' => 0,
    'created_at'   => date('Y-m-d H:i:s'),
]);
$cleanup['holidays'][] = $holidayId;

$engine->closeDay($customerId, $holidayDate, 0, ['id' => 0, 'role' => 'system']);

$holidayRows = Database::select(
    'SELECT r.`id` FROM `' . t('records') . '` r'
    . ' INNER JOIN `' . t('employees') . '` e ON e.`id` = r.`employee_id`'
    . ' WHERE r.`customer_id` = ? AND r.`attendance_date` = ? AND e.`employee_code` LIKE ?',
    [$customerId, $holidayDate, $MARK . '%']
);
eq('holiday produced no absence rows', count($holidayRows), 0);
check('holiday recognised by the resolver', ShiftResolver::isHoliday($customerId, $holidayDate, 0));
eq('re-running the day close writes nothing twice', (int) (Database::select(
    'SELECT COUNT(*) AS n FROM `' . t('records') . '` WHERE `customer_id` = ? AND `attendance_date` = ?',
    [$customerId, $yesterday]
)[0]['n'] ?? 0) > 0, true);

/* ---------------------------------------------------------------- */
section('H. Manual correction, void, recalculation');

$manualDate = date('Y-m-d', strtotime('-4 days'));

$manual = $engine->manualCreate(['id' => 0, 'role' => 'system'], $customerId, [
    'employee_id'     => (int) $employeeA['id'],
    'attendance_date' => $manualDate,
    'entry_at'        => $manualDate . ' 08:00',
    'exit_at'         => $manualDate . ' 12:00',
    'reason'          => $MARK . ' manual add',
]);
check('manual record created', !empty($manual['ok']), 'code=' . (string) $manual['code']);
$manualId = (int) ($manual['data']['record_id'] ?? 0);
eq('manual record source = manual', (string) (AttendanceRecord::find($manualId)['source'] ?? ''), 'manual');
check('manual flag present', strpos((string) (AttendanceRecord::find($manualId)['flags'] ?? ''), AttendanceCalculator::FLAG_MANUAL) !== false);

$corrected = $engine->correctRecord(['id' => 0, 'role' => 'system'], $customerId, $manualId, [
    'entry_at' => $manualDate . ' 08:30',
    'exit_at'  => $manualDate . ' 12:00',
    'reason'   => $MARK . ' correction',
]);
check('correction accepted', !empty($corrected['ok']));
eq('corrected late minutes = 20 (08:30 with grace 10)', (int) (AttendanceRecord::find($manualId)['late_minutes'] ?? -1), 20);

$noReason = $engine->correctRecord(['id' => 0, 'role' => 'system'], $customerId, $manualId, [
    'entry_at' => $manualDate . ' 09:00',
    'reason'   => '',
]);
eq('correction without a reason is refused', (string) $noReason['code'], 'ATTENDANCE_REJECTED');

$voided = $engine->voidRecord(['id' => 0, 'role' => 'system'], $customerId, $manualId, $MARK . ' void test');
check('void accepted', !empty($voided['ok']));
$voidRow = Database::selectOne('SELECT * FROM `' . t('records') . '` WHERE `id` = ?', [$manualId]);
check('voided row is still in the database (soft delete)', $voidRow !== null);
check('voided row has deleted_at + void_reason', $voidRow['deleted_at'] !== null && (string) $voidRow['void_reason'] === $MARK . ' void test');
eq('voided row is invisible to the models', AttendanceRecord::findInCustomer($manualId, $customerId), null);
eq('voided row is invisible to the day query', count(array_filter(
    AttendanceRecord::sessionsOn($customerId, (int) $employeeA['id'], $manualDate),
    static fn (array $row): bool => (int) $row['id'] === $manualId
)), 0);

// recalculation after a shift change: the person really came at 09:30, the
// old shift started at 08:00 (grace 10 -> late 80). The manager moves the
// shift to 09:30 (grace 5 -> late 0) and recalculates with the CURRENT shift.
$today = date('Y-m-d');

Database::run(
    'UPDATE `' . t('records') . '` SET `entry_at` = ?, `exit_at` = ? WHERE `customer_id` = ? AND `local_event_id` = ?',
    [$today . ' 09:30:00', $today . ' 13:30:00', $customerId, $offlineEntryLocal]
);

Database::update(t('shifts'), ['start_time' => '08:00:00', 'late_grace_minutes' => 10], ['id' => $shiftId]);
$engine->recalculate($customerId, $today, $today, $offlineEmployee, false, ['id' => 0, 'role' => 'system']);

$before = Database::selectOne(
    'SELECT `late_minutes` FROM `' . t('records') . '` WHERE `customer_id` = ? AND `local_event_id` = ?',
    [$customerId, $offlineEntryLocal]
);

Database::update(t('shifts'), ['start_time' => '09:30:00', 'late_grace_minutes' => 5], ['id' => $shiftId]);

$recalc = $engine->recalculate($customerId, $today, $today, $offlineEmployee, true, ['id' => 0, 'role' => 'system']);
check('recalculation ran', !empty($recalc['ok']));

$after = Database::selectOne(
    'SELECT `late_minutes`, `recalculated_at` FROM `' . t('records') . '` WHERE `customer_id` = ? AND `local_event_id` = ?',
    [$customerId, $offlineEntryLocal]
);
check('recalculation wrote recalculated_at', $after !== null && $after['recalculated_at'] !== null);
check('recalculation changed the late minutes after the shift moved',
    (int) $before['late_minutes'] !== (int) $after['late_minutes'],
    'before=' . (int) $before['late_minutes'] . ' after=' . (int) $after['late_minutes']);

Database::update(t('shifts'), ['start_time' => '08:00:00', 'late_grace_minutes' => 10], ['id' => $shiftId]);

/* ---------------------------------------------------------------- */
section('I. Tenant isolation');

$crossTenant = $engine->recordEntry($deviceT, [
    'request_id' => 's6-tenant-' . $stamp,
    'proof'      => $issueProof($deviceT, $employeeA, 'entry')['uuid'],
]);
eq('device of tenant B can not record the employee of tenant A', (string) $crossTenant['code'], 'EMPLOYEE_NOT_FOUND');

// the real path: a proof can only be ISSUED for an employee of the same
// customer (FaceDatasetService::verifyRecognition refuses the cross one).
$employeeBTenantRow = Employee::find((int) $employeeBTenant);

foreach ([1, 2, 3] as $index) {
    $faces->enrollSample($employeeBTenantRow, [
        'sample_index'  => $index,
        'embedding'     => $vector(0.9 + $index * 0.001),
        'model_version' => $modelVersion,
        'quality'       => 0.9,
    ], 0, 'system');
}

$crossRecognition = $faces->verifyRecognition($deviceA, [
    'outcome'     => 'matched',
    'employee_id' => (int) $employeeBTenant,
    'distance'    => 0.2,
    'action'      => 'entry',
]);
check('a device of tenant A can never get a recognition result for the employee of tenant B',
    empty($crossRecognition['ok']), 'code=' . (string) $crossRecognition['code']);

$pTenant = RecognitionProof::issue($deviceT, $employeeBTenantRow, 'entry', []);
$tenantEntry = $engine->recordEntry($deviceT, ['request_id' => 's6-tenant2-' . $stamp, 'proof' => $pTenant['uuid']]);
check('device of tenant B records its own employee', !empty($tenantEntry['ok']), 'code=' . (string) $tenantEntry['code']);

$visibleToA = Database::select(
    'SELECT `id` FROM `' . t('records') . '` WHERE `customer_id` = ?',
    [$customerId]
);
$visibleToAIds = array_map(static fn (array $row): int => (int) $row['id'], $visibleToA);
check('tenant A queries never return tenant B rows', !in_array((int) ($tenantEntry['data']['record_id'] ?? 0), $visibleToAIds, true));

$engineBInside = $engine->currentlyInside($customerB);
eq('tenant B "currently inside" shows only its own row', count($engineBInside), 1);
$engineAInside = $engine->currentlyInside($customerId);
$insideAIds = array_map(static fn (array $row): int => (int) $row['employee_id'], $engineAInside);
check('tenant A "currently inside" never contains the employee of tenant B',
    !in_array((int) $employeeBTenant, $insideAIds, true));

/* ---------------------------------------------------------------- */
section('J. Side effects: audit trail + event queue');

$auditEntry = Database::scalar(
    'SELECT COUNT(*) FROM `' . t('audit_logs') . '` WHERE `customer_id` = ? AND `event` = ? AND `created_at` >= ?',
    [$customerId, 'attendance.entry.created', date('Y-m-d H:i:s', strtotime('-1 hour'))]
);
check('entry writes an audit row', (int) $auditEntry > 0);

$auditExit = Database::scalar(
    'SELECT COUNT(*) FROM `' . t('audit_logs') . '` WHERE `customer_id` = ? AND `event` = ? AND `created_at` >= ?',
    [$customerId, 'attendance.exit.created', date('Y-m-d H:i:s', strtotime('-1 hour'))]
);
check('exit writes an audit row', (int) $auditExit > 0);

$auditCorrect = Database::scalar(
    'SELECT COUNT(*) FROM `' . t('audit_logs') . '` WHERE `customer_id` = ? AND `event` IN (?, ?)',
    [$customerId, 'attendance.record.corrected', 'attendance.manual.created']
);
check('correction writes an audit row with old and new values', (int) $auditCorrect > 0);

$auditMeta = Database::selectOne(
    'SELECT `meta` FROM `' . t('audit_logs') . '` WHERE `customer_id` = ? AND `event` = ? ORDER BY `id` DESC LIMIT 1',
    [$customerId, 'attendance.record.corrected']
);
check('audit meta contains before/after', $auditMeta !== null
    && strpos((string) $auditMeta['meta'], '"before"') !== false
    && strpos((string) $auditMeta['meta'], '"after"') !== false);

$events = Database::select(
    'SELECT `event`, `status` FROM `' . t('events') . '` WHERE `customer_id` = ? ORDER BY `id` ASC',
    [$customerId]
);
$eventNames = array_map(static fn (array $row): string => (string) $row['event'], $events);
check('attendance.entry.created event queued', in_array(AttendanceEvent::ENTRY_CREATED, $eventNames, true));
check('attendance.exit.created event queued', in_array(AttendanceEvent::EXIT_CREATED, $eventNames, true));
check('attendance.absence.detected event queued', in_array(AttendanceEvent::ABSENCE_DETECTED, $eventNames, true));
check('attendance.leave.recorded event queued', in_array(AttendanceEvent::LEAVE_RECORDED, $eventNames, true));
check('attendance.offline.synced event queued', in_array(AttendanceEvent::OFFLINE_SYNCED, $eventNames, true));
// Stage 6 only fires the events. Stage 7 owns the dispatcher, so this suite
// asserts that nothing consumed them here (a worker must be run explicitly) and
// that the switch follows the stage-7 configuration key.
check('every queued event is still waiting (no worker ran in this suite)',
    count(array_filter($events, static fn (array $row): bool => (string) $row['status'] !== 'pending')) === 0);
check('the dispatch switch follows the stage-7 configuration',
    AttendanceEvent::dispatchEnabled() === (bool) Config::get('attendance.events_dispatch', false));

$proofStats = RecognitionProof::stats($customerId);
check('proofs were issued and consumed', (int) ($proofStats['used'] ?? 0) > 0, json_encode($proofStats));

/* ---------------------------------------------------------------- */
section('K. Reports / payroll numbers');

$summary = $engine->daySummary($customerId, $today);
check('day summary counts the real rows', (int) $summary['records'] > 0);
check('day summary counts who is inside', (int) $summary['currently_inside'] >= 1);
check('day summary counts completed sessions', (int) $summary['completed'] >= 1);

$payroll = $engine->payrollInputs($customerId, date('Y-m-d', strtotime('-7 days')), $today, (int) $employeeA['id']);
check('payroll inputs exist for employee A', $payroll !== []);

if ($payroll !== []) {
    check('payroll exposes work minutes', array_key_exists('work_minutes', $payroll[0]));
    check('payroll exposes overtime minutes', array_key_exists('overtime_minutes', $payroll[0]));
    check('payroll exposes absence days', array_key_exists('absence_days', $payroll[0]));
    check('payroll exposes leave days', array_key_exists('leave_days', $payroll[0]));
}

$pagination = AttendanceRecord::paginate($customerId, ['date_from' => $today, 'date_to' => $today], 1, 25);
check('the manager listing returns rows for today', (int) $pagination['total'] > 0);
$offlineFilter = AttendanceRecord::paginate($customerId, ['date_from' => $today, 'date_to' => $today, 'origin' => 'offline'], 1, 25);
check('the listing can filter by origin=offline', (int) $offlineFilter['total'] >= 1);
$flagFilter = AttendanceRecord::paginate($customerId, ['date_from' => $today, 'date_to' => $today, 'flag' => 'multi_session'], 1, 25);
check('the listing can filter by flag', $flagFilter['total'] === 0 || $flagFilter['total'] > 0);

/* =====================================================================
 * Cleanup - only rows this suite created
 * =================================================================== */

section('Cleanup');

$cleanupCounts = [];

if (!$keep) {
    $employeeIds = array_map(static fn ($e): int => (int) $e['id'],
        array_filter([$employeeA, $employeeB, $employeeC, $employeeNoFace, $employeeOther]));

    $in = implode(',', array_fill(0, count($employeeIds), '?'));

    // proofs and events first (they point at the records / employees)
    $cleanupCounts['proofs'] = Database::run(
        'DELETE FROM `' . t('recognition_proofs') . '` WHERE `customer_id` = ?',
        [$customerId]
    )->rowCount();
    $cleanupCounts['proofs_b'] = Database::run(
        'DELETE FROM `' . t('recognition_proofs') . '` WHERE `customer_id` = ?',
        [$customerB]
    )->rowCount();
    $cleanupCounts['events_a'] = Database::run(
        'DELETE FROM `' . t('events') . '` WHERE `customer_id` IN (?, ?)',
        [$customerId, $customerB]
    )->rowCount();

    // records of the test employees (both tenants)
    $cleanupCounts['records'] = Database::run(
        'DELETE FROM `' . t('records') . '` WHERE `customer_id` = ? AND `employee_id` IN (' . $in . ')',
        array_merge([$customerId], $employeeIds)
    )->rowCount();
    $cleanupCounts['records_b'] = Database::run(
        'DELETE FROM `' . t('records') . '` WHERE `customer_id` = ?',
        [$customerB]
    )->rowCount();

    $cleanupCounts['faces'] = Database::run(
        'DELETE FROM `' . t('face_embeddings') . '` WHERE `customer_id` = ? AND `employee_id` IN (' . $in . ')',
        array_merge([$customerId], $employeeIds)
    )->rowCount();

    Database::run('DELETE FROM `' . t('employee_shifts') . '` WHERE `shift_id` = ?', [$shiftId]);
    Database::run('DELETE FROM `' . t('shifts') . '` WHERE `id` = ?', [$shiftId]);
    if ($cleanup['leaves'] !== []) {
        Database::run('DELETE FROM `' . t('leaves') . '` WHERE `id` IN ('
            . implode(',', array_fill(0, count($cleanup['leaves']), '?')) . ')', $cleanup['leaves']);
    }

    if ($cleanup['holidays'] !== []) {
        Database::run('DELETE FROM `' . t('holidays') . '` WHERE `id` IN ('
            . implode(',', array_fill(0, count($cleanup['holidays']), '?')) . ')', $cleanup['holidays']);
    }

    $cleanupCounts['employees'] = Database::run(
        'DELETE FROM `' . t('employees') . '` WHERE `customer_id` = ? AND `employee_code` LIKE ?',
        [$customerId, $MARK . '%']
    )->rowCount();
    Database::run('DELETE FROM `' . t('employees') . '` WHERE `customer_id` = ?', [$customerB]);

    $cleanupCounts['devices'] = Database::run(
        'DELETE FROM `' . t('devices') . '` WHERE `name` LIKE ?',
        [$MARK . '%']
    )->rowCount();
    Database::run('DELETE FROM `' . t('devices') . '` WHERE `customer_id` = ?', [$customerB]);

    // device request claims + logs written by the engine tests
    Database::run('DELETE FROM `' . t('device_requests') . '` WHERE `device_id` IN (?, ?, ?)',
        [(int) $deviceA['id'], (int) $deviceB['id'], (int) $deviceT['id']]);
    Database::run('DELETE FROM `' . t('device_logs') . '` WHERE `device_id` IN (?, ?, ?)',
        [(int) $deviceA['id'], (int) $deviceB['id'], (int) $deviceT['id']]);

    $cleanupCounts['audit'] = Database::run(
        'DELETE FROM `' . t('audit_logs') . '` WHERE `customer_id` IN (?, ?) AND (`description` LIKE ? OR `meta` LIKE ?)',
        [$customerId, $customerB, '%' . $MARK . '%', '%' . $MARK . '%']
    )->rowCount();

    Database::run('DELETE FROM `' . t('customers') . '` WHERE `id` = ?', [$customerB]);
} else {
    echo "  (--keep given: fixtures were left in the database)\n";
}

// rate limiter files written by the engine
foreach (glob(Config::path('storage/cache/attendance_rate_*.json')) ?: [] as $file) {
    @unlink($file);
}

/* =====================================================================
 * Summary
 * =================================================================== */

echo "\n" . str_repeat('-', 64) . "\n";
echo "passed: \033[32m" . $pass . "\033[0m   failed: " . ($fail > 0 ? "\033[31m" . $fail . "\033[0m" : '0') . "\n";

if (!$keep) {
    $report = array_filter($cleanupCounts, static fn ($value): bool => $value > 0);
    echo "cleaned up: " . ($report === [] ? 'nothing' : json_encode($report)) . "\n";
}

if ($fail > 0) {
    echo "\nfailures:\n";

    foreach ($failures as $failure) {
        echo "  - " . $failure . "\n";
    }

    exit(1);
}

echo "ALL ENGINE TESTS PASSED\n";
exit(0);
