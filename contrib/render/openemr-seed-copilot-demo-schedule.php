<?php

/**
 * First-boot seeding of **up to 40 demo patients** (20 per configured calendar provider by default:
 * ``physician1`` and ``physician2``) and **back-to-back** calendar appointments on one day (idempotent via
 * ``pubpid`` prefixes such as ``CCSEED-P1-``, ``CCSEED-P2-``, ``CCSEED-ADMIN-`` and ``pc_hometext`` marker
 * ``CCSEED_DEMO_APPT``).
 *
 * Runs after the database is configured whenever this script executes, including flex ``auto_configure.php``
 * first boot. Set ``OPENEMR_AUTO_SEED_COPILOT_DEMO_SCHEDULE`` to ``false``, ``no``, ``off``, or ``0`` to skip.
 *
 * Environment (optional):
 *
 * - ``OE_SEED_COPILOT_PROVIDER_USERNAME`` — first calendar provider (``users.username``); default ``physician1``.
 * - ``OE_SEED_COPILOT_PHYSICIAN2_USERNAME`` — second provider; default ``physician2``.
 * - ``OE_SEED_COPILOT_SKIP_SECOND_PROVIDER`` — ``true`` / ``yes`` / ``1`` / ``on`` to seed **only** the first provider
 *   (for example 20 patients + back-to-back slots on ``admin`` when combined with
 *   ``OE_SEED_COPILOT_PROVIDER_USERNAME=admin``).
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

function isCopilotDemoSeedExplicitlyDisabled(): bool
{
    $raw = getenv('OPENEMR_AUTO_SEED_COPILOT_DEMO_SCHEDULE');
    if ($raw === false) {
        return false;
    }

    return in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
}

if (isCopilotDemoSeedExplicitlyDisabled()) {
    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: OPENEMR_AUTO_SEED_COPILOT_DEMO_SCHEDULE is false/no/off/0, skipping.\n");
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
function copilotDemoPatientDefsPhysician1(): array
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

/**
 * @return list<array{fname:string,lname:string,sex:string,dob:string}>
 */
function copilotDemoPatientDefsPhysician2(): array
{
    return [
        ['Yuki', 'Nakamura', 'Female', '1987-04-22'],
        ['Omar', 'Benali', 'Male', '1976-11-03'],
        ['Ingrid', 'Bergström', 'Female', '1969-08-14'],
        ['Tendai', 'Moyo', 'Male', '1996-02-19'],
        ['Lucía', 'Fernández', 'Female', '1991-09-07'],
        ['Viktor', 'Popov', 'Male', '1984-12-30'],
        ['Naledi', 'Dlamini', 'Female', '2001-06-25'],
        ['Geoffrey', 'Okonkwo', 'Male', '1973-03-11'],
        ['Anika', 'Krishnan', 'Female', '1998-10-08'],
        ['Tomasz', 'Wójcik', 'Male', '1982-05-16'],
        ['Brigitte', 'Dubois', 'Female', '1965-01-29'],
        ['Samir', 'Haddad', 'Male', '1990-07-21'],
        ['Fiona', 'MacLeod', 'Female', '1977-12-04'],
        ['Diego', 'Castillo', 'Male', '1994-04-13'],
        ['Akosua', 'Mensah', 'Female', '1989-08-18'],
        ['Stefan', 'Jovanović', 'Male', '1980-02-02'],
        ['Mirela', 'Ionescu', 'Female', '1993-11-27'],
        ['Chen', 'Wei', 'Male', '1971-06-09'],
        ['Bridget', "O'Connor", 'Female', '1999-03-15'],
        ['Aziz', 'Rahman', 'Male', '1985-09-01'],
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

/**
 * Stable segment for ``pubpid`` (``CCSEED-{segment}-NN``). Keeps ``P1`` / ``P2`` for the stock demo usernames.
 */
function copilotPubpidSegmentForUsername(string $username): string
{
    $u = strtolower(trim($username));
    if ($u === 'physician1') {
        return 'P1';
    }
    if ($u === 'physician2') {
        return 'P2';
    }
    if ($u === 'admin') {
        return 'ADMIN';
    }

    $cleaned = preg_replace('/[^a-z0-9]+/i', '', $u);
    $slug = strtoupper(is_string($cleaned) ? $cleaned : '');
    if ($slug === '') {
        return 'USR';
    }

    return strlen($slug) > 12 ? substr($slug, 0, 12) : $slug;
}

function copilotSkipSecondProviderBlock(): bool
{
    $raw = getenv('OE_SEED_COPILOT_SKIP_SECOND_PROVIDER');
    if ($raw === false) {
        return false;
    }

    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
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

$scheduleDate = copilotScheduleDate();
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

$firstProviderUsername = trim((string) (getenv('OE_SEED_COPILOT_PROVIDER_USERNAME') ?: 'physician1'));
$secondProviderUsername = trim((string) (getenv('OE_SEED_COPILOT_PHYSICIAN2_USERNAME') ?: 'physician2'));

/** @var list<array{username:string, defs:list<array{fname:string,lname:string,sex:string,dob:string}>, physicianLabel:string}> */
$providerBlocks = [
    [
        'username' => $firstProviderUsername,
        'defs' => copilotDemoPatientDefsPhysician1(),
        'physicianLabel' => copilotPubpidSegmentForUsername($firstProviderUsername),
    ],
];
if (!copilotSkipSecondProviderBlock()) {
    $providerBlocks[] = [
        'username' => $secondProviderUsername,
        'defs' => copilotDemoPatientDefsPhysician2(),
        'physicianLabel' => copilotPubpidSegmentForUsername($secondProviderUsername),
    ];
}

foreach ($providerBlocks as $block) {
    $providerUsername = $block['username'];
    $defs = $block['defs'];
    $pLabel = $block['physicianLabel'];
    $providerId = copilotResolveProviderUserId($providerUsername);

    for ($i = 0; $i < 20; $i++) {
        $slot = $i + 1;
        $pubpid = sprintf('CCSEED-%s-%02d', $pLabel, $slot);
        $dem = $defs[$i];
        $pid = copilotEnsurePatient($pubpid, $dem);

        $start = $firstStart->modify('+' . ($i * $slotSeconds) . ' seconds');
        $startSql = $start->format('H:i:s');
        $end = $start->modify('+' . $slotSeconds . ' seconds');
        $endSql = $end->format('H:i:s');

        if (copilotAppointmentExists($pid, $scheduleDate, $startSql)) {
            fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: appointment exists pid={$pid} {$scheduleDate} {$startSql}, skipping {$pLabel} slot {$slot}.\n");
            continue;
        }

        $eventUuid = (new UuidRegistry(['table_name' => 'openemr_postcalendar_events']))->createUuid();
        $eventUuidBin = UuidRegistry::uuidToBytes($eventUuid);
        $title = 'Office visit (demo ' . $pLabel . ' ' . $slot . ')';

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

        fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: created appointment pid={$pid} {$scheduleDate} {$startSql}–{$endSql} provider={$providerUsername} provider_id={$providerId}.\n");
    }
}

fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: completed.\n");
exit(0);
