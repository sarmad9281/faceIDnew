/**
 * =====================================================================
 * SARMAD Attendance - kiosk offline queue test (REAL, no mocks)
 * =====================================================================
 *
 *   node tests/device_queue_test.js            run everything
 *   node tests/device_queue_test.js --keep     keep the fixtures in the DB
 *
 * Part A drives assets/js/device-queue.js - the very module the kiosk page
 * loads - with the real field contract of the server (R14).
 *
 * Part B takes the batch this module builds and POSTs it to the RUNNING dev
 * server (POST /api/v1/device/attendance/sync) with a REAL device token, so the
 * device -> server offline path is proven end to end: accepted, duplicate,
 * rejected and invalid records, the per-record verdicts, and the attendance
 * rows that really appear in the database.
 *
 * The fixtures (customer, branch, device, employees, shifts, records) are
 * created through the real models and removed at the end, unless --keep is
 * given. No table outside attendance_* is touched.
 */

'use strict';

const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const BASE = process.env.SARMAD_BASE || 'http://127.0.0.1:8080';
const KEEP = process.argv.includes('--keep');
const MARK = 'SARMAD-TEST-DQ';
const queue = require(path.join(ROOT, 'assets/js/device-queue.js'));

let pass = 0;
const failures = [];

function check(label, condition, detail) {
  if (condition) {
    pass++;
    console.log(`  \x1b[32m✓\x1b[0m ${label}`);
    return;
  }

  failures.push(label + (detail === undefined ? '' : ` — ${detail}`));
  console.log(`  \x1b[31m✗ ${label}\x1b[0m${detail === undefined ? '' : ` — ${detail}`}`);
}

function eq(label, actual, expected) {
  check(label, actual === expected, `actual=${JSON.stringify(actual)} expected=${JSON.stringify(expected)}`);
}

function section(title) {
  console.log(`\n\x1b[1m== ${title} ==\x1b[0m`);
}

function php(code) {
  return execFileSync('php', ['-r', code], { cwd: ROOT, encoding: 'utf8' }).trim();
}

let seq = 0;
const nextId = () => `dq-${Date.now().toString(36)}-${(++seq).toString(36)}`;

/* =====================================================================
 * A. The queue module itself (the code that runs on the device)
 * =================================================================== */
section('A. Queue module (device side, real file)');

let memory = [];
const storage = {
  read: () => memory.slice(),
  write: (items) => { memory = items.slice(); }
};

const local = queue.create(storage, { max: 5 });

const stored = local.push({
  local_event_id: 'evt-1',
  device_id: 7,
  event_type: 'entry',
  employee_id: 12,
  local_created_at: '2026-10-07T08:04:11.000Z',
  request_id: nextId(),
  recognition: { outcome: 'FACE_MATCHED', distance: 0.41234, confidence: 0.71, sample_index: 2, model_version: '1.0.0' }
});

check('a recognised punch is stored', stored !== null);
eq('the queue id equals the local event id', stored.queue_id, 'evt-1');
eq('the local event id is kept', stored.local_event_id, 'evt-1');
eq('the device id is stored', stored.device_id, 7);
eq('the event type is stored', stored.event_type, 'entry');
eq('the employee id from the LOCAL match is stored', stored.employee_id, 12);
eq('the local created at is stored', stored.local_created_at, '2026-10-07T08:04:11.000Z');
eq('the recognition result is stored', stored.recognition.outcome, 'FACE_MATCHED');
eq('the distance is rounded to 4 decimals', stored.recognition.distance, 0.4123);
eq('the sample index is stored', stored.recognition.sample_index, 2);
eq('the model version is stored', stored.recognition.model_version, '1.0.0');
check('no image or descriptor field exists on the item',
  !('image' in stored) && !('embedding' in stored) && !('descriptor' in stored));

const duplicate = local.push({
  local_event_id: 'evt-1',
  event_type: 'entry',
  employee_id: 12,
  local_created_at: '2026-10-07T08:04:12.000Z'
});
eq('a double tap can not queue the same local event twice', local.count(), 1);
eq('the stored item is returned instead', duplicate.local_event_id, 'evt-1');

const refused = local.push({ local_event_id: 'evt-2', event_type: 'entry', employee_id: 0 });
eq('an item without an employee id is refused', refused, null);
eq('... and nothing is stored', local.count(), 1);

local.push({ local_event_id: 'evt-2', event_type: 'exit', employee_id: 13, local_created_at: '2026-10-07T17:31:00.000Z' });

const batch = local.buildBatch(50);
eq('the batch carries both events', batch.length, 2);
eq('the batch item speaks the server dialect', batch[0].type, 'entry');
eq('the batch item carries the local event id', batch[0].local_event_id, 'evt-1');
eq('the batch item carries the employee id', batch[0].employee_id, 12);
eq('the batch item carries the local created at as occurred_at', batch[0].occurred_at, '2026-10-07T08:04:11.000Z');
check('the batch never contains an employee-less record', batch.every((row) => row.employee_id > 0));
check('the batch items carry the local recognition data',
  batch[0].distance === 0.4123 && batch[0].confidence === 0.71 && batch[0].sample_index === 2);

const capped = queue.create(storage, { max: 2 });
for (let i = 0; i < 4; i++) {
  capped.push({ local_event_id: 'cap-' + i, event_type: 'entry', employee_id: 20 + i, local_created_at: '2026-10-07T09:0' + i + ':00.000Z' });
}
check('the queue is bounded', capped.count() <= 2, `count=${capped.count()}`);

// applyResults: accepted/duplicate are dropped, refused items stay
memory = [];
const applied = queue.create(storage, { max: 50 });
['a', 'b', 'c', 'd'].forEach((id) => {
  applied.push({ local_event_id: id, event_type: 'entry', employee_id: 30, local_created_at: '2026-10-07T09:00:00.000Z' });
});

const result = applied.applyResults([
  { local_event_id: 'a', status: 'ACCEPTED', code: 'ATTENDANCE_RECORDED' },
  { local_event_id: 'b', status: 'DUPLICATE', code: 'DUPLICATE_REQUEST' },
  { local_event_id: 'c', status: 'REJECTED', code: 'EMPLOYEE_INACTIVE', message: 'x' },
  { local_event_id: 'd', status: 'INVALID', code: 'OFFLINE_EVENT_INVALID', message: 'y' }
]);

eq('accepted + duplicate are counted', result.accepted + result.duplicate, 2);
eq('they are dropped from the queue', result.kept.length, 2);
eq('the refused items stay in the queue', result.kept.map((row) => row.local_event_id).join(','), 'c,d');
eq('the refused items are reported', result.refused.length, 2);
check('a refused punch is never silently lost', applied.count() === 2);

const summary = applied.summary();
eq('the summary counts the queue', summary.count, 2);
eq('the summary splits by type', summary.entry, 2);

/* =====================================================================
 * B. The real server: POST /api/v1/device/attendance/sync
 * =================================================================== */
section('B. Real server round trip (device token, live API)');

const env = JSON.parse(php(`
define('SARMAD_APP', true); define('SARMAD_START', microtime(true)); define('SARMAD_ROOT', '${ROOT}');
require '${ROOT}/app/Bootstrap.php'; App\\Bootstrap::bootCli();
use App\\Core\\Database; use App\\Core\\Security;
$MARK = '${MARK}';
$stamp = date('ymd-His');

// --- purge anything an earlier crashed run left behind ---------------------
$empIds = array_map(static fn($r) => (int) $r['id'], Database::select('SELECT id FROM '.Database::table('employees').' WHERE employee_code LIKE ?', [$MARK.'%']));
foreach ($empIds as $id) {
  Database::run('DELETE FROM '.Database::table('records').' WHERE employee_id = ?', [$id]);
  Database::run('DELETE FROM '.Database::table('recognition_proofs').' WHERE employee_id = ?', [$id]);
  Database::run('UPDATE '.Database::table('devices').' SET branch_id = NULL WHERE id IN (SELECT device_id FROM '.Database::table('device_logs').' WHERE 1=0)', []);
}
Database::run('DELETE FROM '.Database::table('recognition_proofs').' WHERE customer_id IN (SELECT id FROM '.Database::table('customers').' WHERE name LIKE ?)', [$MARK.'%']);
foreach ($empIds as $id) { Database::run('DELETE FROM '.Database::table('employees').' WHERE id = ?', [$id]); }
foreach (Database::select('SELECT id FROM '.Database::table('devices').' WHERE name LIKE ?', [$MARK.'%']) as $row) {
  $did = (int) $row['id'];
  Database::run('DELETE FROM '.Database::table('device_logs').' WHERE device_id = ?', [$did]);
  Database::run('DELETE FROM '.Database::table('device_requests').' WHERE device_id = ?', [$did]);
  Database::run('DELETE FROM '.Database::table('device_settings').' WHERE device_id = ?', [$did]);
  Database::run('DELETE FROM '.Database::table('devices').' WHERE id = ?', [$did]);
}
foreach (Database::select('SELECT id FROM '.Database::table('customers').' WHERE name LIKE ?', [$MARK.'%']) as $row) {
  Database::run('DELETE FROM '.Database::table('customers').' WHERE id = ?', [(int) $row['id']]);
}
Database::run('DELETE FROM '.Database::table('shifts').' WHERE name LIKE ?', [$MARK.'%']);
Database::run('DELETE FROM '.Database::table('branches').' WHERE name LIKE ?', [$MARK.'%']);

// --- fixtures (real models) ------------------------------------------------
App\\Services\\FeatureService::set(1, 'attendance', true, 0);
App\\Services\\FeatureService::set(1, 'employees', true, 0);

$branchId = Database::insert(Database::table('branches'), [
  'uuid' => Security::uuid4(), 'customer_id' => 1, 'name' => $MARK.' branch '.$stamp, 'code' => 'DQ-'.$stamp,
  'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);
$shiftId = Database::insert(Database::table('shifts'), [
  'uuid' => Security::uuid4(), 'customer_id' => 1, 'name' => $MARK.' shift '.$stamp, 'code' => 'DQ-S-'.$stamp,
  'start_time' => '08:00:00', 'end_time' => '17:00:00', 'late_grace_minutes' => 10, 'early_leave_grace_minutes' => 10,
  'break_minutes' => 0, 'crosses_midnight' => 0, 'weekly_pattern' => 'all', 'is_active' => 1,
  'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);
$employeeId = Database::insert(Database::table('employees'), [
  'uuid' => Security::uuid4(), 'customer_id' => 1, 'branch_id' => $branchId, 'employee_code' => $MARK.'-E1',
  'first_name' => $MARK, 'last_name' => 'queue', 'employment_type' => 'full_time', 'status' => 'active',
  'face_enrolled' => 1, 'face_status' => 'enrolled', 'hire_date' => date('Y-m-d', strtotime('-40 days')),
  'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);
$inactiveId = Database::insert(Database::table('employees'), [
  'uuid' => Security::uuid4(), 'customer_id' => 1, 'branch_id' => $branchId, 'employee_code' => $MARK.'-E2',
  'first_name' => $MARK, 'last_name' => 'inactive', 'employment_type' => 'full_time', 'status' => 'suspended',
  'face_enrolled' => 1, 'face_status' => 'enrolled', 'hire_date' => date('Y-m-d', strtotime('-40 days')),
  'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);
foreach ([$employeeId, $inactiveId] as $id) {
  Database::run('INSERT INTO '.Database::table('employee_shifts').' (uuid,customer_id,employee_id,shift_id,effective_from,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
    [Security::uuid4(), 1, $id, $shiftId, date('Y-m-d', strtotime('-30 days')), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
}
// --- real 3/3 face enrolment (the engine only records at 3/3) --------------
$faces = new App\\Services\\FaceDatasetService();
$employeeRow = Database::selectOne('SELECT * FROM '.Database::table('employees').' WHERE id = ?', [$employeeId]);
$vector = static function (float $seed): array {
  $out = [];
  for ($i = 0; $i < App\\Models\\FaceEmbedding::DIMENSIONS; $i++) { $out[] = round(sin($seed + $i / 7.0) * 0.18, 6); }
  return $out;
};
$modelVersion = App\\Services\\FaceDatasetService::modelVersion();
foreach ([1, 2, 3] as $index) {
  $res = $faces->enrollSample($employeeRow, ['sample_index' => $index, 'embedding' => $vector(0.20 + $index * 0.001), 'model_version' => $modelVersion, 'quality' => 0.9], 0, 'system');
  if (empty($res['ok'])) { fwrite(STDERR, 'enrol failed: '.json_encode($res, JSON_UNESCAPED_UNICODE)); exit(1); }
}
$faceState = $faces->stateForEmployee($employeeId, 1);
if (($faceState['status'] ?? '') !== 'complete') { fwrite(STDERR, 'face state: '.json_encode($faceState, JSON_UNESCAPED_UNICODE)); exit(1); }

$devices = new App\\Services\\DeviceService();
$registered = $devices->register(1, [
  'name' => $MARK.' kiosk '.$stamp, 'branch_id' => $branchId, 'platform' => 'android',
  'device_uid' => $MARK.'-UID-'.$stamp, 'app_version' => '1.0.0',
]);
if (empty($registered['ok'])) { fwrite(STDERR, 'register failed: '.json_encode($registered, JSON_UNESCAPED_UNICODE)); exit(1); }
$deviceId = (int) $registered['device']['id'];
$activated = $devices->activate($deviceId);
if (empty($activated['ok'])) { fwrite(STDERR, 'activate failed: '.json_encode($activated, JSON_UNESCAPED_UNICODE)); exit(1); }
$issued = App\\Models\\Device::issueToken($deviceId, 365);
echo json_encode([
  'device_id' => $deviceId, 'employee_id' => $employeeId, 'inactive_id' => $inactiveId,
  'branch_id' => $branchId, 'token' => $issued['token'],
], JSON_UNESCAPED_UNICODE);
`));

const DEVICE_ID = env.device_id;
const EMPLOYEE_ID = env.employee_id;
const INACTIVE_ID = env.inactive_id;
const TOKEN = env.token;

check('a real device was activated through DeviceService', DEVICE_ID > 0 && TOKEN.length > 20, JSON.stringify({ device: DEVICE_ID, token: TOKEN.slice(0, 8) }));

async function apiDevice(pathname, body) {
  const response = await fetch(`${BASE}${pathname}`, {
    method: 'POST',
    headers: { 'Authorization': `Bearer ${TOKEN}`, 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify(body)
  });

  return { status: response.status, payload: await response.json() };
}

function dbValue(sql) {
  return php(`
define('SARMAD_APP', true); define('SARMAD_START', microtime(true)); define('SARMAD_ROOT', '${ROOT}');
require '${ROOT}/app/Bootstrap.php'; App\\Bootstrap::bootCli();
echo (string) App\\Core\\Database::scalar(${JSON.stringify(sql)});
`);
}

function buildDeviceItem(eventType, employeeId, secondsAgo) {
  const item = queue.item({
    local_event_id: nextId(),
    device_id: DEVICE_ID,
    event_type: eventType,
    employee_id: employeeId,
    local_created_at: new Date(Date.now() - (secondsAgo || 0) * 1000).toISOString(),
    request_id: nextId(),
    recognition: { outcome: 'FACE_MATCHED', distance: 0.38, confidence: 0.8, sample_index: 1, model_version: '1.0.0' }
  });

  return item;
}

(async () => {
  // ---- 1. a real offline batch: entry + exit of one employee, plus one bad row
  const entryItem = buildDeviceItem('entry', EMPLOYEE_ID, 600);
  const exitItem = buildDeviceItem('exit', EMPLOYEE_ID, 300);

  const localQueue = queue.create({ read: () => memory.slice(), write: (items) => { memory = items.slice(); } }, { max: 50 });
  memory = [];
  localQueue.push(entryItem);
  localQueue.push(exitItem);
  localQueue.push({ local_event_id: 'bad id with spaces', event_type: 'entry', employee_id: EMPLOYEE_ID, local_created_at: new Date().toISOString() });
  localQueue.push(buildDeviceItem('lunch', EMPLOYEE_ID, 120));

  const payload = { events: localQueue.buildBatch(50) };
  eq('the batch carries the whole local queue', payload.events.length, 4);
  check('the queued batch carries no stale server proof (deliberate: a proof lives seconds and would be refused on reconnect)',
    !('proof' in payload.events[0]) && !('proof' in payload.events[1]) && !('proof' in payload.events[3]));
  eq('the client normalises an unknown action to entry (never a silent punch)',
    payload.events[3].type, 'entry');

  const first = await apiDevice('/api/v1/device/attendance/sync', payload);
  eq('the sync answers 200', first.status, 200);
  eq('the batch is acknowledged', first.payload.code, 'OFFLINE_SYNC_DONE');
  // The engine really implements R6 (several sessions a day): the entry of the
  // morning is closed by the exit, so the third recognised entry opens the
  // SECOND session of the day instead of being refused.
  eq('three records were accepted', first.payload.data.accepted, 3);
  eq('one record was invalid', first.payload.data.invalid, 1);
  eq('nothing was rejected', first.payload.data.rejected, 0);
  check('every item received its own verdict', first.payload.data.records.length === 4);

  const verdicts = {};
  first.payload.data.records.forEach((row) => { verdicts[row.index] = row.status; });
  eq('the entry was accepted', verdicts[0], 'ACCEPTED');
  eq('the exit was accepted', verdicts[1], 'ACCEPTED');
  eq('the malformed local event id was refused as INVALID', verdicts[2], 'INVALID');
  eq('the afternoon entry opens a second session (multi session rule)', verdicts[3], 'ACCEPTED');
  eq('... and is a real attendance record', first.payload.data.records[3].code, 'ATTENDANCE_RECORDED');

  const entryRecordId = first.payload.data.records[0].record_id;
  check('the accepted entry returned its record id', Number(entryRecordId) > 0, JSON.stringify(first.payload.data.records[0]));

  const rows = dbValue(`SELECT COUNT(*) FROM attendance_records WHERE employee_id = ${EMPLOYEE_ID} AND deleted_at IS NULL`);
  eq('two sessions were written for the day', rows, '2');
  eq('one session is still open', dbValue(`SELECT COUNT(*) FROM attendance_records WHERE employee_id = ${EMPLOYEE_ID} AND open_key IS NOT NULL`), '1');
  eq('one session is closed with a real duration', dbValue(`SELECT COUNT(*) FROM attendance_records WHERE employee_id = ${EMPLOYEE_ID} AND exit_at IS NOT NULL AND work_minutes > 0`), '1');

  const source = dbValue(`SELECT source FROM attendance_records WHERE employee_id = ${EMPLOYEE_ID} LIMIT 1`);
  eq('the row is marked as an offline record', source, 'device_offline');

  const deviceTimes = dbValue(`SELECT CONCAT(IFNULL(device_event_at,'-'), '|', IFNULL(server_received_at,'-')) FROM attendance_records WHERE employee_id = ${EMPLOYEE_ID} ORDER BY id ASC LIMIT 1`);
  check('the device time and the server received time are both stored', /^\d{4}-\d{2}-\d{2} /.test(deviceTimes) && deviceTimes.includes('|'), deviceTimes);

  // the offline punch was stamped 10 minutes before it reached the server, and
  // both stamps are in the application timezone (no UTC drift)
  const drift = dbValue(`SELECT TIMESTAMPDIFF(SECOND, device_event_at, server_received_at) FROM attendance_records WHERE employee_id = ${EMPLOYEE_ID} ORDER BY id ASC LIMIT 1`);
  check('the offline stamp keeps the real gap to the server clock', Number(drift) > 540 && Number(drift) < 720, `drift=${drift}s`);

  // ---- 2. the local queue applies the verdicts like the kiosk does
  const applied = localQueue.applyResults(first.payload.data.records);
  eq('the three accepted records left the queue', applied.kept.length, 1);
  eq('only the refused item stayed in the queue', applied.kept[0].local_event_id, 'bad id with spaces');
  eq('the applied results count three acceptances', applied.accepted, 3);

  // ---- 3. replaying the same batch is DUPLICATE, never a second row
  const replay = await apiDevice('/api/v1/device/attendance/sync', payload);
  eq('the replay answers 200', replay.status, 200);
  eq('the replay accepts nothing new', replay.payload.data.accepted, 0);
  eq('the replay reports the three records as duplicates', replay.payload.data.duplicate, 3);
  eq('still exactly two attendance rows', dbValue(`SELECT COUNT(*) FROM attendance_records WHERE employee_id = ${EMPLOYEE_ID} AND deleted_at IS NULL`), '2');
  eq('exactly one exit event is stored', dbValue(`SELECT COUNT(*) FROM attendance_events WHERE customer_id = 1 AND event = 'attendance.exit.created' AND employee_id = ${EMPLOYEE_ID}`), '1');
  eq('exactly two entry events are stored', dbValue(`SELECT COUNT(*) FROM attendance_events WHERE customer_id = 1 AND event = 'attendance.entry.created' AND employee_id = ${EMPLOYEE_ID}`), '2');

  // ---- 4. an inactive employee is REJECTED (server side re-validation)
  const inactive = await apiDevice('/api/v1/device/attendance/sync', {
    events: [queue.buildBatch([buildDeviceItem('entry', INACTIVE_ID, 60)])[0]]
  });
  eq('the inactive employee is refused', inactive.payload.data.rejected, 1);
  eq('... with the documented code', inactive.payload.data.records[0].code, 'EMPLOYEE_INACTIVE');
  eq('... and no row was written for it', dbValue(`SELECT COUNT(*) FROM attendance_records WHERE employee_id = ${INACTIVE_ID}`), '0');

  // ---- 5. a foreign employee (another customer) can never be registered
  const foreign = await apiDevice('/api/v1/device/attendance/sync', {
    events: [queue.buildBatch([buildDeviceItem('entry', 999999, 30)])[0]]
  });
  eq('an unknown employee id is refused', foreign.payload.data.rejected, 1);
  eq('... with EMPLOYEE_NOT_FOUND', foreign.payload.data.records[0].code, 'EMPLOYEE_NOT_FOUND');

  // ---- 5b. a raw item with an unknown event type (bypassing the client)
  const badType = await apiDevice('/api/v1/device/attendance/sync', {
    events: [{ type: 'lunch', local_event_id: nextId(), employee_id: EMPLOYEE_ID, occurred_at: new Date().toISOString() }]
  });
  eq('the server refuses an unknown event type', badType.payload.data.invalid, 1);
  eq('... with OFFLINE_EVENT_INVALID', badType.payload.data.records[0].code, 'OFFLINE_EVENT_INVALID');

  // ---- 6. an empty batch and a device without a token
  const empty = await apiDevice('/api/v1/device/attendance/sync', { events: [] });
  eq('an empty batch is refused', empty.payload.code, 'OFFLINE_EVENT_INVALID');

  const unauth = await fetch(`${BASE}/api/v1/device/attendance/sync`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ events: [{}] })
  });
  const unauthBody = await unauth.json();
  eq('a sync without a token is refused', unauthBody.code, 'DEVICE_UNAUTHORIZED');

  // ---- 6b. every attempt is visible on the device page (device_logs)
  // The offline path writes the same kind of row as an online punch, so the
  // device history is complete: the 3 accepted rows of batch #1 and the two
  // refusals that reached the engine (inactive employee + unknown employee).
  // Replays and malformed rows are refused by syncBatch before the engine and
  // are reported in the batch verdict, not as an attendance attempt.
  eq('the accepted offline punches were logged for the device',
    dbValue(`SELECT COUNT(*) FROM attendance_device_logs WHERE device_id = ${DEVICE_ID} AND event = 'device.attendance.submitted' AND JSON_EXTRACT(meta, '$.outcome') = 'recorded'`), '3');
  eq('both refused sync events were logged too',
    dbValue(`SELECT COUNT(*) FROM attendance_device_logs WHERE device_id = ${DEVICE_ID} AND event = 'device.attendance.submitted' AND JSON_EXTRACT(meta, '$.outcome') = 'rejected'`), '2');
  eq('the offline log rows are marked as device_offline',
    dbValue(`SELECT COUNT(*) FROM attendance_device_logs WHERE device_id = ${DEVICE_ID} AND event = 'device.attendance.submitted' AND JSON_EXTRACT(meta, '$.source') = 'device_offline'`), '5');

  // ---- 7. a real online punch (proof path) leaves the same kind of row
  const proofPhp = php(`
define('SARMAD_APP', true); define('SARMAD_START', microtime(true)); define('SARMAD_ROOT', '${ROOT}');
require '${ROOT}/app/Bootstrap.php'; App\\Bootstrap::bootCli();
use App\\Core\\Database;
$device = Database::selectOne('SELECT * FROM '.Database::table('devices').' WHERE id = ?', [${DEVICE_ID}]);
$employee = Database::selectOne('SELECT * FROM '.Database::table('employees').' WHERE id = ?', [${EMPLOYEE_ID}]);
$proof = App\\Models\\RecognitionProof::issue($device, $employee, 'entry', ['request_id' => 'dq-proof', 'confidence' => 0.8, 'distance' => 0.3, 'model_version' => '1.0.0']);
echo json_encode(['proof' => $proof['uuid']], JSON_UNESCAPED_UNICODE);
`);
  const proof = JSON.parse(proofPhp);

  const online = await apiDevice('/api/v1/device/attendance/entry', {
    request_id: nextId(), proof: proof.proof, action: 'entry'
  });
  eq('the online proof path answers with the conflict status', online.status, 409);
  eq('the open session refuses a third entry', online.payload.code, 'ENTRY_ALREADY_OPEN');
  check('the refusal carries the documented Persian message',
    online.payload.success === false && String(online.payload.message).includes('ورود شما قبلاً ثبت شده است'), JSON.stringify(online.payload).slice(0, 160));
  eq('still two rows after the refused entry', dbValue(`SELECT COUNT(*) FROM attendance_records WHERE employee_id = ${EMPLOYEE_ID} AND deleted_at IS NULL`), '2');

  /* =================================================================
   * Cleanup
   * =============================================================== */
  section('Cleanup');

  if (KEEP) {
    console.log('  --keep: fixtures stay in the database');
  } else {
    php(`
define('SARMAD_APP', true); define('SARMAD_START', microtime(true)); define('SARMAD_ROOT', '${ROOT}');
require '${ROOT}/app/Bootstrap.php'; App\\Bootstrap::bootCli();
use App\\Core\\Database;
$MARK = '${MARK}';
foreach (Database::select('SELECT id FROM '.Database::table('employees').' WHERE employee_code LIKE ?', [$MARK.'%']) as $row) {
  $id = (int) $row['id'];
  Database::run('DELETE FROM '.Database::table('records').' WHERE employee_id = ?', [$id]);
  Database::run('DELETE FROM '.Database::table('events').' WHERE employee_id = ?', [$id]);
  Database::run('DELETE FROM '.Database::table('recognition_proofs').' WHERE employee_id = ?', [$id]);
  Database::run('DELETE FROM '.Database::table('employee_shifts').' WHERE employee_id = ?', [$id]);
  Database::run('DELETE FROM '.Database::table('employees').' WHERE id = ?', [$id]);
}
foreach (Database::select('SELECT id FROM '.Database::table('devices').' WHERE name LIKE ?', [$MARK.'%']) as $row) {
  $did = (int) $row['id'];
  Database::run('DELETE FROM '.Database::table('device_logs').' WHERE device_id = ?', [$did]);
  Database::run('DELETE FROM '.Database::table('device_requests').' WHERE device_id = ?', [$did]);
  Database::run('DELETE FROM '.Database::table('device_settings').' WHERE device_id = ?', [$did]);
  Database::run('DELETE FROM '.Database::table('devices').' WHERE id = ?', [$did]);
}
Database::run('DELETE FROM '.Database::table('shifts').' WHERE name LIKE ?', [$MARK.'%']);
Database::run('DELETE FROM '.Database::table('branches').' WHERE name LIKE ?', [$MARK.'%']);
Database::run('DELETE FROM '.Database::table('audit_logs').' WHERE created_at >= NOW() - INTERVAL 20 MINUTE AND event LIKE "device.%"');
echo 'ok';
`);
    const leftEmployees = dbValue(`SELECT COUNT(*) FROM attendance_employees WHERE employee_code LIKE '${MARK}%'`);
    const leftRecords = dbValue(`SELECT COUNT(*) FROM attendance_records`);
    eq('the marked employees are gone', leftEmployees, '0');
    eq('no attendance row is left behind', leftRecords, '0');
  }

  console.log('\n----------------------------------------------------------------');
  console.log(`passed: \x1b[32m${pass}\x1b[0m   failed: \x1b[31m${failures.length}\x1b[0m`);

  if (failures.length > 0) {
    console.log('\nfailures:');
    failures.forEach((text) => console.log('  - ' + text));
    process.exit(1);
  }

  console.log('DEVICE QUEUE TEST PASSED');
})().catch((error) => {
  console.error('FATAL', error);
  process.exit(1);
});
