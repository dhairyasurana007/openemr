<?php

/**
 * Optional first-boot seeding of two **Physicians** demo accounts (``physician1``, ``physician2``) plus **Clinicians**
 * (idempotent by username).
 *
 * Runs after the database is configured. Runs by default whenever this script executes; set
 * ``OPENEMR_AUTO_SEED_STANDARD_ROLES`` to ``false``, ``no``, ``off``, or ``0`` to skip.
 *
 * **Defaults:** two physicians (``physician1``, ``physician2``) and one clinician (``clinician``) whenever this script
 * runs, unless you set ``OE_SEED_CLINICIAN_USERNAME`` to an empty value to skip the clinician row only.
 * **Passwords for these seeded users are hardcoded to ``pass``** (demo only — edit this file to change them).
 *
 * ## Policy you can approximate (not automatic law — tune ACL under Administration → ACL)
 *
 * - **Administrators** (``OE_USER`` / ``openemr-auto-install.php``): full access via that group’s ACLs.
 * - **Physicians** (this script): intended as **appointment resources** — set ``calendar=1`` so they appear in
 *   provider pickers; calendar SQL often filters with ``pc_aid`` = the provider’s ``users.id``, so events **for**
 *   that doctor are what they naturally see when views restrict by provider. Chart access still follows **Patients**
 *   ACLs and site globals (facility limits, sensitive patients, etc.).
 * - **Clinicians** (this script): intended as **scheduling / care-coordination** staff — default ``calendar=0`` so
 *   they are **not** listed as an attending **provider** on the calendar while still able to schedule (per ACL).
 *   ``see_auth`` here is the **See Authorizations** user setting (Administration → Users), **not** a dedicated
 *   “only calendar rows I created” flag; OpenEMR does not expose that as one core field.
 *
 * ## ``users`` columns set here (overridable via env)
 *
 * | Env prefix | Role | Typical intent |
 * |------------|------|----------------|
 * | ``OE_SEED_PHYSICIAN1_*`` / ``OE_SEED_PHYSICIAN2_*`` | Built-in **Physicians** ACL group | Defaults ``physician1`` / ``physician2``. Per-slot env overrides shared ``OE_SEED_PHYSICIAN_*`` (e.g. ``OE_SEED_PHYSICIAN_SEE_AUTH``) when the slot-specific variable is unset. |
 * | ``OE_SEED_CLINICIAN_*`` | Built-in **Clinicians** ACL group | Default username ``clinician`` when unset (set to empty string to skip). Scheduler; default ``see_auth=3`` (All); ``calendar=0``. |
 *
 * ``see_auth``: ``1`` = None, ``2`` = Only Mine, ``3`` = All — **See Authorizations** (``interface/main/authorizations``), not every screen.
 *
 * ``calendar``: ``1`` = user can appear as a **calendar provider** (``UserService`` uses ``authorized=1 AND calendar=1``).
 *
 * ``cal_ui``: calendar UI preference (installer uses ``3`` for the initial admin; seeded users default the same).
 *
 * ``*_FACILITY_ID``: optional explicit ``facility.id``; empty = first facility in DB.
 *
 * **Troubleshooting physician login:** if ``users`` rows exist but login still fails, ensure a matching
 * ``users_secure`` row exists (this script **inserts** one when missing), a ``groups`` row for the
 * username (OpenEMR rejects login otherwise), and phpGACL membership (``AclExtended::setUserAro``). This
 * script repairs all three when an expected seed username already exists. For deterministic demo behavior,
 * this script always re-syncs the seeded users' password hash to the demo password on every run.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Uuid\UuidRegistry;

$openemrRoot = dirname(__DIR__, 2);
chdir($openemrRoot);

if (isStandardRoleSeedExplicitlyDisabled()) {
    fwrite(STDOUT, "openemr-seed-standard-role-users: OPENEMR_AUTO_SEED_STANDARD_ROLES is false/no/off/0, skipping.\n");
    exit(0);
}

$manual = getenv('MANUAL_SETUP');
if ($manual !== false && strtolower((string) $manual) === 'yes') {
    fwrite(STDOUT, "openemr-seed-standard-role-users: MANUAL_SETUP=yes, skipping.\n");
    exit(0);
}

if (getenv('OPENEMR_SKIP_AUTO_INSTALL') === '1') {
    fwrite(STDOUT, "openemr-seed-standard-role-users: OPENEMR_SKIP_AUTO_INSTALL=1, skipping.\n");
    exit(0);
}

$sqlconfPath = $openemrRoot . '/sites/default/sqlconf.php';
if (!is_readable($sqlconfPath)) {
    fwrite(STDERR, "openemr-seed-standard-role-users: sqlconf.php not readable at {$sqlconfPath}\n");
    exit(1);
}

require $sqlconfPath;

if (!isset($config) || (int) $config !== 1) {
    fwrite(STDOUT, "openemr-seed-standard-role-users: site not configured yet, skipping.\n");
    exit(0);
}

// In CLI bootstrap contexts (Render startup), globals.php expects HTTP_HOST for site resolution.
if (PHP_SAPI === 'cli') {
    if (empty($_SERVER['HTTP_HOST'])) {
        $_SERVER['HTTP_HOST'] = 'default';
    }
    if (empty($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = '/';
    }
}

$ignoreAuth = true;
require_once $openemrRoot . '/interface/globals.php';

/** @var non-empty-string */
const SEED_STANDARD_ROLE_DEMO_PASSWORD = 'pass';

/**
 * @return list<array{
 *     username:string,
 *     password:string,
 *     fname:string,
 *     lname:string,
 *     acl:list<string>,
 *     see_auth:int,
 *     calendar:int,
 *     cal_ui:int,
 *     facility_id:int
 * }>
 */
function seedDefinitionsFromEnv(): array
{
    $out = [];
    foreach ([1, 2] as $slot) {
        $pUser = getenv('OE_SEED_PHYSICIAN' . $slot . '_USERNAME');
        $username = ($pUser !== false && trim((string) $pUser) !== '') ? trim((string) $pUser) : ('physician' . $slot);
        $password = SEED_STANDARD_ROLE_DEMO_PASSWORD;

        $pFname = getenv('OE_SEED_PHYSICIAN' . $slot . '_FNAME');
        $fname = ($pFname !== false && trim((string) $pFname) !== '')
            ? trim((string) $pFname)
            : 'Physician';
        $pLname = getenv('OE_SEED_PHYSICIAN' . $slot . '_LNAME');
        $lname = ($pLname !== false && trim((string) $pLname) !== '')
            ? trim((string) $pLname)
            : ($slot === 1 ? 'One' : 'Two');

        $out[] = [
            'username' => $username,
            'password' => $password,
            'fname' => $fname,
            'lname' => $lname,
            'acl' => ['Physicians'],
            'see_auth' => readSeeAuthEnv(
                'OE_SEED_PHYSICIAN' . $slot . '_SEE_AUTH',
                readSeeAuthEnv('OE_SEED_PHYSICIAN_SEE_AUTH', 2)
            ),
            'calendar' => readBinaryIntEnv(
                'OE_SEED_PHYSICIAN' . $slot . '_CALENDAR',
                readBinaryIntEnv('OE_SEED_PHYSICIAN_CALENDAR', 1)
            ),
            'cal_ui' => readSmallIntEnv(
                'OE_SEED_PHYSICIAN' . $slot . '_CAL_UI',
                readSmallIntEnv('OE_SEED_PHYSICIAN_CAL_UI', 3)
            ),
            'facility_id' => readFacilityIdEnvPreferringSlot(
                'OE_SEED_PHYSICIAN' . $slot . '_FACILITY_ID',
                'OE_SEED_PHYSICIAN_FACILITY_ID'
            ),
        ];
    }

    $rawClinicianUsername = getenv('OE_SEED_CLINICIAN_USERNAME');
    if ($rawClinicianUsername === false) {
        $clinUser = 'clinician';
    } else {
        $clinUser = trim((string) $rawClinicianUsername);
    }

    if ($clinUser !== '') {
        $out[] = [
            'username' => $clinUser,
            'password' => SEED_STANDARD_ROLE_DEMO_PASSWORD,
            'fname' => trim((string) (getenv('OE_SEED_CLINICIAN_FNAME') ?: 'Clinician')),
            'lname' => trim((string) (getenv('OE_SEED_CLINICIAN_LNAME') ?: 'Demo')),
            'acl' => ['Clinicians'],
            'see_auth' => readSeeAuthEnv('OE_SEED_CLINICIAN_SEE_AUTH', 3),
            'calendar' => readBinaryIntEnv('OE_SEED_CLINICIAN_CALENDAR', 0),
            'cal_ui' => readSmallIntEnv('OE_SEED_CLINICIAN_CAL_UI', 3),
            'facility_id' => readFacilityIdEnv('OE_SEED_CLINICIAN_FACILITY_ID'),
        ];
    }

    return $out;
}

function readSeeAuthEnv(string $name, int $default): int
{
    $raw = getenv($name);
    if ($raw === false || trim((string) $raw) === '') {
        return $default;
    }

    $v = (int) trim((string) $raw);
    if ($v < 1 || $v > 3) {
        fwrite(STDERR, "openemr-seed-standard-role-users: invalid {$name}={$raw} (allowed 1–3); using default {$default}.\n");

        return $default;
    }

    return $v;
}

function readBinaryIntEnv(string $name, int $default): int
{
    $raw = getenv($name);
    if ($raw === false || trim((string) $raw) === '') {
        return $default;
    }

    return ((int) trim((string) $raw)) !== 0 ? 1 : 0;
}

function readSmallIntEnv(string $name, int $default): int
{
    $raw = getenv($name);
    if ($raw === false || trim((string) $raw) === '') {
        return $default;
    }

    return (int) trim((string) $raw);
}

function readFacilityIdEnv(string $name): int
{
    $raw = getenv($name);
    if ($raw === false || trim((string) $raw) === '') {
        return defaultFacilityId();
    }

    $id = (int) trim((string) $raw);
    if ($id <= 0) {
        return defaultFacilityId();
    }

    $row = sqlQuery('SELECT `id` FROM `facility` WHERE `id` = ?', [$id]);
    if (!is_array($row) || empty($row['id'])) {
        fwrite(STDERR, "openemr-seed-standard-role-users: {$name}={$id} not found; using default facility.\n");

        return defaultFacilityId();
    }

    return $id;
}

/**
 * Use ``$slotSpecific`` when set to a non-empty facility id; otherwise fall back to ``$shared`` env (then default).
 */
function readFacilityIdEnvPreferringSlot(string $slotSpecific, string $shared): int
{
    $raw = getenv($slotSpecific);
    if ($raw !== false && trim((string) $raw) !== '') {
        return readFacilityIdEnv($slotSpecific);
    }

    return readFacilityIdEnv($shared);
}

/**
 * Ensure phpGACL ARO groups exist, creating any that are missing rather than hard-failing.
 *
 * When install_gacl() fails mid-deploy the "Physicians" / "Clinicians" ARO groups may be absent even
 * though $config=1 is already written and the admin user exists.  Instead of exiting(1) and leaving
 * physicians permanently uncreatable, we create the missing groups directly via GaclApi so that
 * setUserAro() can succeed on the immediately following seedOneUser() call.
 *
 * @param list<string> $wantedTitles
 */
function ensurePhpGaclAroGroups(array $wantedTitles): void
{
    if ($wantedTitles === []) {
        return;
    }

    $available = array_values(AclExtended::aclGetGroupTitleList(true));
    $missing   = array_diff($wantedTitles, $available);

    if (empty($missing)) {
        return;
    }

    fwrite(
        STDOUT,
        "openemr-seed-standard-role-users: phpGACL ARO groups missing: ["
        . implode(', ', $missing) . "] — creating them now.\n"
    );

    $gacl = new \OpenEMR\Gacl\GaclApi();

    // Locate the root group so we can navigate to the 'Users' sub-group.
    $rootId = $gacl->get_root_group_id();
    if (!$rootId) {
        fwrite(STDERR, "openemr-seed-standard-role-users: phpGACL root group not found; DB may not be fully initialised.\n");
        exit(1);
    }

    // The standard phpGACL hierarchy: root → Users → Physicians/Clinicians.
    $usersGroupId = $gacl->get_group_id('users', null, 'ARO');
    if (!$usersGroupId) {
        $usersGroupId = $gacl->get_group_id(null, 'Users', 'ARO');
    }

    if (!$usersGroupId) {
        // 'Users' parent is also missing; create it directly under root.
        fwrite(STDOUT, "openemr-seed-standard-role-users: phpGACL 'Users' ARO group missing — creating under root.\n");
        $usersGroupId = $gacl->add_group('users', 'Users', $rootId, 'ARO');
        if (!$usersGroupId) {
            fwrite(STDERR, "openemr-seed-standard-role-users: Failed to create phpGACL 'Users' ARO group.\n");
            exit(1);
        }
    }

    // Canonical value (short name) for known role groups that install_gacl() would have created.
    $knownValues = [
        'Physicians' => 'doc',
        'Clinicians' => 'clin',
    ];

    foreach ($missing as $title) {
        $value   = $knownValues[$title] ?? strtolower(substr($title, 0, 10));
        $newId   = $gacl->add_group($value, $title, $usersGroupId, 'ARO');
        if (!$newId) {
            // add_group returns false if the value already exists under a different name or had a
            // transient error.  Check whether it actually exists before giving up.
            $existingId = $gacl->get_group_id($value, null, 'ARO');
            if ($existingId) {
                fwrite(STDOUT, "openemr-seed-standard-role-users: phpGACL group '{$title}' (value='{$value}') already exists as id={$existingId}.\n");
                continue;
            }

            fwrite(STDERR, "openemr-seed-standard-role-users: Failed to create phpGACL ARO group '{$title}'.\n");
            exit(1);
        }

        fwrite(STDOUT, "openemr-seed-standard-role-users: Created phpGACL ARO group '{$title}' (id={$newId}).\n");
    }
}

function defaultFacilityId(): int
{
    $row = sqlQuery('SELECT `id` FROM `facility` ORDER BY `id` ASC LIMIT 1');
    if (!is_array($row) || empty($row['id'])) {
        return 0;
    }

    return (int) $row['id'];
}

/**
 * OpenEMR login requires a ``groups`` row and at least one phpGACL ARO group; a ``users`` row alone is not enough.
 * Repair idempotently when a seed username already existed from a partial or legacy install.
 *
 * @param list<string> $aclGroupTitles
 */
function ensureSeedUserLoginPrerequisites(int $userId, string $username, array $aclGroupTitles, string $fname, string $lname): void
{
    $groupRow = sqlQuery('SELECT `name` FROM `groups` WHERE BINARY `user` = ? LIMIT 1', [$username]);
    if (!is_array($groupRow) || $groupRow['name'] === null || $groupRow['name'] === '') {
        sqlStatement('INSERT INTO `groups` (`name`, `user`) VALUES (?, ?)', ['Default', $username]);
        fwrite(STDOUT, "openemr-seed-standard-role-users: repaired missing `groups` row for '{$username}'.\n");
    }

    $aclTitles = AclExtended::aclGetGroupTitles($username);
    if (empty($aclTitles)) {
        $userRow = sqlQuery('SELECT `fname`, `mname`, `lname` FROM `users` WHERE `id` = ?', [$userId]);
        $useFname = (is_array($userRow) && isset($userRow['fname']) && (string) $userRow['fname'] !== '')
            ? (string) $userRow['fname']
            : $fname;
        $useMname = (is_array($userRow) && isset($userRow['mname'])) ? (string) $userRow['mname'] : '';
        $useLname = (is_array($userRow) && isset($userRow['lname']) && (string) $userRow['lname'] !== '')
            ? (string) $userRow['lname']
            : $lname;
        AclExtended::setUserAro($aclGroupTitles, $username, $useFname, $useMname, $useLname);
        fwrite(STDOUT, "openemr-seed-standard-role-users: repaired missing phpGACL group membership for '{$username}'.\n");
    }
}

/**
 * Ensure ``users_secure`` exists and optionally reset the password hash for an existing seeded account.
 *
 * OpenEMR authenticates against ``users_secure``; a ``users`` row without a matching ``users_secure`` row
 * produces a login that never succeeds. Older partial installs can also leave a stale hash while the
 * seed script skips creation ("already exists").
 */
function syncExistingSeedUserCredentials(int $userId, string $username, string $password): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        fwrite(STDERR, "openemr-seed-standard-role-users: password_hash failed for existing '{$username}'.\n");
        exit(1);
    }

    $secure = sqlQuery('SELECT `id` FROM `users_secure` WHERE `id` = ? LIMIT 1', [$userId]);
    if (!is_array($secure) || empty($secure['id'])) {
        sqlStatement(
            'INSERT INTO `users_secure` (`id`, `username`, `password`, `last_update_password`) VALUES (?, ?, ?, NOW())',
            [$userId, $username, $hash]
        );
        fwrite(
            STDOUT,
            "openemr-seed-standard-role-users: repaired missing `users_secure` row for existing '{$username}' (id={$userId}).\n"
        );

        return;
    }

    // Keep seeded demo-user credentials deterministic across redeploys.
    sqlStatement(
        'UPDATE `users_secure` SET `password` = ?, `last_update_password` = NOW() WHERE `id` = ?',
        [$hash, $userId]
    );
    fwrite(STDOUT, "openemr-seed-standard-role-users: reset password hash for existing '{$username}' (id={$userId}).\n");

    // If prior failed attempts blocked the account, clear counters during seed repair.
    sqlStatement(
        'UPDATE `users_secure` SET `login_fail_counter` = 0, `last_login_fail` = NULL, `auto_block_emailed` = 0 WHERE `id` = ?',
        [$userId]
    );
}

/**
 * @param list<string> $aclGroupTitles
 * @param array{see_auth:int,calendar:int,cal_ui:int,facility_id:int} $prefs
 */
function seedOneUser(
    string $username,
    string $password,
    string $fname,
    string $lname,
    array $aclGroupTitles,
    array $prefs
): void {
    $exists = sqlQuery('SELECT `id` FROM `users` WHERE BINARY `username` = ?', [$username]);
    if (is_array($exists) && !empty($exists['id'])) {
        $userId = (int) $exists['id'];
        syncExistingSeedUserCredentials($userId, $username, $password);
        ensureSeedUserLoginPrerequisites($userId, $username, $aclGroupTitles, $fname, $lname);
        fwrite(STDOUT, "openemr-seed-standard-role-users: user '{$username}' already exists, skipping create.\n");

        return;
    }

    $facilityId = $prefs['facility_id'];

    $newUserId = (int) sqlInsert(
        'INSERT INTO `users` (`username`, `password`, `authorized`, `active`, `fname`, `lname`, `facility_id`, `calendar`, `cal_ui`, `see_auth`)'
        . ' VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, ?)',
        [
            $username,
            'NoLongerUsed',
            $fname,
            $lname,
            $facilityId,
            $prefs['calendar'],
            $prefs['cal_ui'],
            $prefs['see_auth'],
        ]
    );

    if ($newUserId < 1) {
        fwrite(STDERR, "openemr-seed-standard-role-users: failed to insert users row for '{$username}'.\n");
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        fwrite(STDERR, "openemr-seed-standard-role-users: password_hash failed for '{$username}'.\n");
        exit(1);
    }

    sqlStatement(
        'INSERT INTO `users_secure` (`id`, `username`, `password`, `last_update_password`) VALUES (?, ?, ?, NOW())',
        [$newUserId, $username, $hash]
    );

    $uuidBytes = UuidRegistry::getRegistryForTable('users')->createUuid();
    sqlStatement(
        'UPDATE `users` SET `uuid` = ? WHERE `id` = ?',
        [$uuidBytes, $newUserId]
    );

    if ($facilityId > 0) {
        $facilityRow = sqlQuery('SELECT `name` FROM `facility` WHERE `id` = ?', [$facilityId]);
        if (is_array($facilityRow) && !empty($facilityRow['name'])) {
            sqlStatement('UPDATE `users` SET `facility` = ? WHERE `id` = ?', [(string) $facilityRow['name'], $newUserId]);
        }
    }

    sqlStatement('INSERT INTO `groups` (`name`, `user`) VALUES (?, ?)', ['Default', $username]);

    AclExtended::setUserAro($aclGroupTitles, $username, $fname, '', $lname);

    fwrite(
        STDOUT,
        "openemr-seed-standard-role-users: created '{$username}' groups=[" . implode(', ', $aclGroupTitles)
        . "] facility_id={$facilityId} calendar={$prefs['calendar']} cal_ui={$prefs['cal_ui']} see_auth={$prefs['see_auth']}.\n"
    );
}

/**
 * Demo standard-role seeding is on unless the operator explicitly disables it.
 *
 * @param string|false $raw
 */
function isStandardRoleSeedExplicitlyDisabled(): bool
{
    $raw = getenv('OPENEMR_AUTO_SEED_STANDARD_ROLES');
    if ($raw === false) {
        return false;
    }

    return in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
}

fwrite(STDOUT, "openemr-seed-standard-role-users: starting (idempotent).\n");

$defs = seedDefinitionsFromEnv();

$neededAclTitles = [];
foreach ($defs as $def) {
    foreach ($def['acl'] as $t) {
        $neededAclTitles[$t] = true;
    }
}

ensurePhpGaclAroGroups(array_keys($neededAclTitles));

foreach ($defs as $def) {
    $prefs = [
        'see_auth' => $def['see_auth'],
        'calendar' => $def['calendar'],
        'cal_ui' => $def['cal_ui'],
        'facility_id' => $def['facility_id'],
    ];
    seedOneUser(
        $def['username'],
        $def['password'],
        $def['fname'],
        $def['lname'],
        $def['acl'],
        $prefs
    );
}

fwrite(STDOUT, "openemr-seed-standard-role-users: completed.\n");
exit(0);
