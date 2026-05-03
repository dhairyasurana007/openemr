<?php

/**
 * Optional first-boot seeding of **20 demo patients** (diverse names) and **back-to-back** calendar
 * appointments on one day (idempotent via ``pubpid`` prefix ``CCSEED-`` and ``pc_hometext`` marker).
 *
 * Runs after the database is configured. Disabled unless ``OPENEMR_AUTO_SEED_COPILOT_DEMO_SCHEDULE`` is truthy.
 *
 * Environment (optional):
 *
 * - ``OE_SEED_COPILOT_PROVIDER_USERNAME`` — calendar provider (``users.username``); default ``physician1``.
 * - ``OE_SEED_COPILOT_SCHEDULE_DATE`` — ``YYYY-MM-DD``; empty = **today** (PHP ``date('Y-m-d')`` in container TZ).
 * - ``OE_SEED_COPILOT_FIRST_START`` — first appointment start ``HH:MM:SS``; default ``09:00:00``.
 * - ``OE_SEED_COPILOT_SLOT_SECONDS`` — duration per slot in seconds; default ``900`` (15 minutes).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Uuid\UuidRegistry;

$openemrRoot = dirname(__DIR__, 2);
chdir($openemrRoot);

/** @param string|false $raw */
function copilotDemoIsEnvTruthy(string|false $raw): bool
{
    if ($raw === false) {
        return false;
    }

    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
}

if (!copilotDemoIsEnvTruthy(getenv('OPENEMR_AUTO_SEED_COPILOT_DEMO_SCHEDULE'))) {
    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: OPENEMR_AUTO_SEED_COPILOT_DEMO_SCHEDULE not enabled, skipping.\n");
    exit(0);
}

$manual = getenv('MANUAL_SETUP');
if ($manual !== false && strtolower((string) $manual) === 'yes') {
    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: MANUAL_SETUP=yes, skipping.\n");
    exit(0);
}

if (getenv('OPENEMR_SKIP_AUTO_INSTALL') === '1') {
    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: OPENEMR_SKIP_AUTO_INSTALL=1, skipping.\n");
    exit(0);
}

$sqlconfPath = $openemrRoot . '/sites/default/sqlconf.php';
if (!is_readable($sqlconfPath)) {
    fwrite(STDERR, "openemr-seed-copilot-demo-schedule: sqlconf.php not readable at {$sqlconfPath}\n");
    exit(1);
}

require $sqlconfPath;

if (!isset($config) || (int) $config !== 1) {
    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: site not configured yet, skipping.\n");
    exit(0);
}

$ignoreAuth = true;
require_once $openemrRoot . '/interface/globals.php';

/**
 * @return list<array{fname:string,lname:string,sex:string,dob:string}>
 */
function copilotDemoPatientDefs(): array
{
    return [
        ['Amara', 'Okafor', 'Female', '1988-02-11'],
        ['Dmitri', 'Volkov', 'Male', '1975-09-23'],
        ['Mei-Ling', 'Huang', 'Female', '1992-04-05'],
        ['Jamal', 'Washington', 'Male', '2000-12-18'],
        ['Priya', 'Sharma', 'Female', '1983-07-30'],
        ['Carlos', 'Mendoza', 'Male', '1995-01-14'],
        ['Aisha', 'Abdi', 'Female', '1991-11-08'],
        ['Henrik', 'Lindström', 'Male', '1967-03-22'],
        ['Fatima', 'Al-Nasser', 'Female', '1989-06-17'],
        ['Minh-Tu', 'Nguyen', 'Male', '1999-08-29'],
        ['Chioma', 'Eze', 'Female', '1972-05-04'],
        ['Raj', 'Patel', 'Male', '1986-10-12'],
        ['Elena', 'Kowalczyk', 'Female', '1994-02-28'],
        ['Hiroshi', 'Tanaka', 'Male', '1958-09-09'],
        ['Zara', 'Rahman', 'Female', '2003-04-16'],
        ['Mateo', 'Herrera', 'Male', '1990-12-01'],
        ['Kemi', 'Oladipo', 'Female', '1979-07-07'],
        ['Ivan', 'Petrov', 'Male', '1981-03-19'],
        ['Sofia', 'Andersson', 'Female', '1998-01-25'],
        ['Kwame', 'Asante', 'Male', '1993-05-13'],
    ];
}

/** @return array{no: array<string, mixed>} */
function copilotNoRecurrspec(): array
{
    return [
        'event_repeat_freq' => '',
        'event_repeat_freq_type' => '',
        'event_repeat_on_num' => '1',
        'event_repeat_on_day' => '0',
        'event_repeat_on_freq' => '0',
        'exdate' => '',
    ];
}

/** @return array<string, string> */
function copilotEmptyLocationSpec(): array
{
    return [
        'event_location' => '',
        'event_street1' => '',
        'event_street2' => '',
        'event_city' => '',
        'event_state' => '',
        'event_postal' => '',
    ];
}

function copilotResolveOfficeVisitCategoryId(): int
{
    $row = sqlQuery(
        "SELECT `pc_catid` FROM `openemr_postcalendar_categories` WHERE `pc_constant_id` = 'office_visit' LIMIT 1"
    );
    if (is_array($row) && isset($row['pc_catid'])) {
        return (int) $row['pc_catid'];
    }

    $row2 = sqlQuery('SELECT `pc_catid` FROM `openemr_postcalendar_categories` ORDER BY `pc_catid` LIMIT 1');
    if (is_array($row2) && isset($row2['pc_catid'])) {
        return (int) $row2['pc_catid'];
    }

    return 5;
}

function copilotResolveDefaultFacilityId(): int
{
    $row = sqlQuery('SELECT `id` FROM `facility` ORDER BY `id` LIMIT 1');
    if (is_array($row) && isset($row['id'])) {
        return (int) $row['id'];
    }

    return 3;
}

function copilotResolveProviderUserId(string $username): int
{
    $u = trim($username);
    if ($u === '') {
        $u = 'physician1';
    }
    $row = sqlQuery('SELECT `id` FROM `users` WHERE BINARY `username` = ? LIMIT 1', [$u]);
    if (is_array($row) && !empty($row['id'])) {
        return (int) $row['id'];
    }

    $row2 = sqlQuery("SELECT `id` FROM `users` WHERE `username` = 'admin' LIMIT 1");
    if (is_array($row2) && !empty($row2['id'])) {
        fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: provider '{$u}' not found; using admin id.\n");

        return (int) $row2['id'];
    }

    return 1;
}

/**
 * @param array{fname:string,lname:string,sex:string,dob:string} $dem
 */
function copilotEnsurePatient(string $pubpid, array $dem): int
{
    $existing = sqlQuery('SELECT `pid` FROM `patient_data` WHERE `pubpid` = ? LIMIT 1', [$pubpid]);
    if (is_array($existing) && isset($existing['pid'])) {
        return (int) $existing['pid'];
    }

    $pidRow = sqlQuery('SELECT MAX(`pid`) AS lastpid FROM `patient_data`');
    $nextPid = 1;
    if (is_array($pidRow) && isset($pidRow['lastpid']) && $pidRow['lastpid'] !== null) {
        $nextPid = (int) $pidRow['lastpid'] + 1;
    }

    $uuidString = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
    $uuidBin = UuidRegistry::uuidToBytes($uuidString);
    $email = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $dem['fname'] . '.' . $dem['lname']))
        . '@seed.copilot.openemr.invalid';

    sqlStatement(
        'INSERT INTO `patient_data` SET
            `uuid` = ?,
            `fname` = ?, `lname` = ?, `mname` = ?,
            `DOB` = ?, `sex` = ?,
            `street` = ?, `city` = ?, `state` = ?, `postal_code` = ?, `country_code` = ?,
            `phone_home` = ?, `email` = ?,
            `pid` = ?, `pubpid` = ?,
            `date` = NOW(), `regdate` = NOW(),
            `language` = ?, `status` = ?, `pricelevel` = ?,
            `created_by` = 1, `updated_by` = 1',
        [
            $uuidBin,
            $dem['fname'],
            $dem['lname'],
            '',
            $dem['dob'],
            $dem['sex'],
            '1 Demo Clinic Way',
            'Boston',
            'MA',
            '02118',
            'USA',
            '555-0100',
            $email,
            $nextPid,
            $pubpid,
            'english',
            'active',
            'standard',
        ]
    );

    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: created patient pid={$nextPid} pubpid={$pubpid} ({$dem['fname']} {$dem['lname']}).\n");

    return $nextPid;
}

function copilotAppointmentExists(int $pid, string $eventDate, string $startTime): bool
{
    $row = sqlQuery(
        'SELECT `pc_eid` FROM `openemr_postcalendar_events`
         WHERE `pc_pid` = ? AND `pc_eventDate` = ? AND `pc_startTime` = ? AND `pc_hometext` = ?
         LIMIT 1',
        [(string) $pid, $eventDate, $startTime, 'CCSEED_DEMO_APPT']
    );

    return is_array($row) && !empty($row['pc_eid']);
}

/**
 * @return non-empty-string
 */
function copilotScheduleDate(): string
{
    $raw = getenv('OE_SEED_COPILOT_SCHEDULE_DATE');
    if ($raw !== false && trim((string) $raw) !== '') {
        $d = trim((string) $raw);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $d);

        return ($dt !== false && $dt->format('Y-m-d') === $d) ? $d : date('Y-m-d');
    }

    return date('Y-m-d');
}

fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: starting (idempotent).\n");

$defs = copilotDemoPatientDefs();
$scheduleDate = copilotScheduleDate();
$providerUsername = trim((string) (getenv('OE_SEED_COPILOT_PROVIDER_USERNAME') ?: 'physician1'));
$providerId = copilotResolveProviderUserId($providerUsername);
$catId = copilotResolveOfficeVisitCategoryId();
$facilityId = copilotResolveDefaultFacilityId();
$slotSeconds = (int) (getenv('OE_SEED_COPILOT_SLOT_SECONDS') ?: '900');
if ($slotSeconds < 300) {
    $slotSeconds = 900;
}

$firstStartRaw = trim((string) (getenv('OE_SEED_COPILOT_FIRST_START') ?: '09:00:00'));
$firstStart = \DateTimeImmutable::createFromFormat('H:i:s', $firstStartRaw);
if ($firstStart === false) {
    $firstStart = \DateTimeImmutable::createFromFormat('H:i', $firstStartRaw);
}
if ($firstStart === false) {
    $firstStart = new \DateTimeImmutable('09:00:00');
}

$noRecur = copilotNoRecurrspec();
$locSpec = serialize(copilotEmptyLocationSpec());
$recSerialized = serialize($noRecur);

for ($i = 0; $i < 20; $i++) {
    $slot = $i + 1;
    $pubpid = sprintf('CCSEED-%02d', $slot);
    $dem = $defs[$i];
    $pid = copilotEnsurePatient($pubpid, $dem);

    $start = $firstStart->modify('+' . ($i * $slotSeconds) . ' seconds');
    $startSql = $start->format('H:i:s');
    $end = $start->modify('+' . $slotSeconds . ' seconds');
    $endSql = $end->format('H:i:s');

    if (copilotAppointmentExists($pid, $scheduleDate, $startSql)) {
        fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: appointment exists pid={$pid} {$scheduleDate} {$startSql}, skipping slot {$slot}.\n");
        continue;
    }

    $eventUuid = (new UuidRegistry(['table_name' => 'openemr_postcalendar_events']))->createUuid();
    $eventUuidBin = UuidRegistry::uuidToBytes($eventUuid);
    $title = 'Office visit (demo ' . $slot . ')';

    sqlStatement(
        'INSERT INTO `openemr_postcalendar_events` (
            `uuid`,
            `pc_catid`, `pc_multiple`, `pc_aid`, `pc_pid`, `pc_gid`,
            `pc_title`, `pc_time`, `pc_hometext`,
            `pc_informant`, `pc_eventDate`, `pc_endDate`, `pc_duration`, `pc_recurrtype`,
            `pc_recurrspec`, `pc_startTime`, `pc_endTime`, `pc_alldayevent`,
            `pc_apptstatus`, `pc_prefcatid`, `pc_location`, `pc_eventstatus`, `pc_sharing`,
            `pc_facility`, `pc_billing_location`, `pc_room`
        ) VALUES (
            ?, ?, 0, ?, ?, 0,
            ?, NOW(), ?,
            1, ?, NULL, ?, 0,
            ?, ?, ?, 0,
            ?, 0, ?, 1, 1,
            ?, ?, ?
        )',
        [
            $eventUuidBin,
            $catId,
            (string) $providerId,
            (string) $pid,
            $title,
            'CCSEED_DEMO_APPT',
            $scheduleDate,
            $slotSeconds,
            $recSerialized,
            $startSql,
            $endSql,
            '-',
            $locSpec,
            $facilityId,
            $facilityId,
            '',
        ]
    );

    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: created appointment pid={$pid} {$scheduleDate} {$startSql}–{$endSql} provider_id={$providerId}.\n");
}

fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: completed.\n");
exit(0);
