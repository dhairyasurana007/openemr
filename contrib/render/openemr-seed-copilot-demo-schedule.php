<?php

/**
 * First-boot seeding of **20 demo patients** for ``physician1`` by default and **back-to-back** calendar
 * appointments on one day (idempotent via
 * ``pubpid`` prefixes such as ``CCSEED-P1-``, ``CCSEED-P2-``, ``CCSEED-ADMIN-`` and ``pc_hometext`` marker
 * ``CCSEED_DEMO_APPT``). Each seeded patient also gets deterministic **completed historical visits**
 * (1-2 appointments at least 1 month before current date) and optional medical-problem entries with
 * marker ``CCSEED_DEMO_ILLNESS``. Each seeded patient gets distinct international-style demographics and a **Vitals**
 * form (height, weight, BP, pulse, temperature, respiration, waist, SpO₂) tied to a lightweight
 * ``CCSEED demo intake`` encounter when vitals are not already present (``form_vitals.note`` =
 * ``CCSEED_DEMO_VITAL``).
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
 *   When unset, this script now defaults to skipping the second provider.
 * - ``OE_SEED_COPILOT_SCHEDULE_DATE`` — ``YYYY-MM-DD``; empty = **today** (PHP ``date('Y-m-d')`` in container TZ).
 * - ``OE_SEED_COPILOT_FIRST_START`` — first appointment start ``HH:MM:SS``; default ``09:00:00``.
 * - ``OE_SEED_COPILOT_SLOT_SECONDS`` — duration per slot in seconds; default ``900`` (15 minutes).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 *
 * @phpstan-type CopilotVitals array{
 *   height: float,
 *   weight: float,
 *   bps: string,
 *   bpd: string,
 *   pulse: float,
 *   temperature: float,
 *   respiration: float,
 *   waist_circ: float,
 *   oxygen_saturation: float
 * }
 * @phpstan-type CopilotPatientDem array{
 *   fname: string,
 *   lname: string,
 *   sex: string,
 *   dob: string,
 *   street?: string,
 *   city?: string,
 *   state?: string,
 *   postal_code?: string,
 *   country_code?: string,
 *   phone_home?: string,
 *   language?: string,
 *   vitals: CopilotVitals
 * }
 */

declare(strict_types=1);

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Forms\BmiCategory;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\VitalsService;

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

// In CLI bootstrap contexts, globals.php expects HTTP_HOST for site resolution.
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
require_once $openemrRoot . '/library/forms.inc.php';

/**
 * Vitals are stored in USA units (inches, pounds, °F) to match typical demo installs.
 *
 * @return list<CopilotPatientDem>
 */
function copilotDemoPatientDefsPhysician1(): array
{
    return [
        [
            'fname' => 'Amara', 'lname' => 'Okafor', 'sex' => 'Female', 'dob' => '1988-02-11',
            'street' => '14 Allen Avenue', 'city' => 'Ikeja', 'state' => 'LA', 'postal_code' => '100001', 'country_code' => 'NG',
            'phone_home' => '+234-802-555-0101', 'language' => 'english',
            'vitals' => ['height' => 63.5, 'weight' => 138.0, 'bps' => '112', 'bpd' => '72', 'pulse' => 68.0, 'temperature' => 98.1, 'respiration' => 15.0, 'waist_circ' => 30.0, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Dmitri', 'lname' => 'Volkov', 'sex' => 'Male', 'dob' => '1975-09-23',
            'street' => 'Nevskiy pr. 28', 'city' => 'Saint Petersburg', 'state' => 'SPE', 'postal_code' => '191186', 'country_code' => 'RU',
            'phone_home' => '+7-812-555-0142', 'language' => 'english',
            'vitals' => ['height' => 72.0, 'weight' => 198.0, 'bps' => '128', 'bpd' => '82', 'pulse' => 74.0, 'temperature' => 98.4, 'respiration' => 14.0, 'waist_circ' => 38.5, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Mei-Ling', 'lname' => 'Huang', 'sex' => 'Female', 'dob' => '1992-04-05',
            'street' => 'Section 4, Xinyi Road', 'city' => 'Taipei', 'state' => 'TPE', 'postal_code' => '106', 'country_code' => 'TW',
            'phone_home' => '+886-2-5550-0193', 'language' => 'english',
            'vitals' => ['height' => 64.0, 'weight' => 121.0, 'bps' => '106', 'bpd' => '68', 'pulse' => 62.0, 'temperature' => 98.0, 'respiration' => 16.0, 'waist_circ' => 27.5, 'oxygen_saturation' => 99.0],
        ],
        [
            'fname' => 'Jamal', 'lname' => 'Okeke', 'sex' => 'Male', 'dob' => '2000-12-18',
            'street' => 'Ring Road South', 'city' => 'Accra', 'state' => 'AA', 'postal_code' => 'GA184', 'country_code' => 'GH',
            'phone_home' => '+233-24-555-0176', 'language' => 'english',
            'vitals' => ['height' => 70.5, 'weight' => 172.0, 'bps' => '118', 'bpd' => '76', 'pulse' => 58.0, 'temperature' => 97.9, 'respiration' => 15.0, 'waist_circ' => 33.0, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Priya', 'lname' => 'Sharma', 'sex' => 'Female', 'dob' => '1983-07-30',
            'street' => 'MG Road', 'city' => 'Bengaluru', 'state' => 'KA', 'postal_code' => '560001', 'country_code' => 'IN',
            'phone_home' => '+91-80-5550-1288', 'language' => 'english',
            'vitals' => ['height' => 62.0, 'weight' => 146.0, 'bps' => '122', 'bpd' => '78', 'pulse' => 76.0, 'temperature' => 98.6, 'respiration' => 17.0, 'waist_circ' => 34.0, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Carlos', 'lname' => 'Mendoza', 'sex' => 'Male', 'dob' => '1995-01-14',
            'street' => 'Av. Insurgentes Sur 300', 'city' => 'Ciudad de México', 'state' => 'CMX', 'postal_code' => '03100', 'country_code' => 'MX',
            'phone_home' => '+52-55-5550-0391', 'language' => 'spanish',
            'vitals' => ['height' => 67.0, 'weight' => 181.0, 'bps' => '124', 'bpd' => '80', 'pulse' => 72.0, 'temperature' => 98.3, 'respiration' => 16.0, 'waist_circ' => 36.0, 'oxygen_saturation' => 96.0],
        ],
        [
            'fname' => 'Aisha', 'lname' => 'Abdi', 'sex' => 'Female', 'dob' => '1991-11-08',
            'street' => 'Via Roma 12', 'city' => 'Palermo', 'state' => 'PA', 'postal_code' => '90133', 'country_code' => 'IT',
            'phone_home' => '+39-091-555-0204', 'language' => 'english',
            'vitals' => ['height' => 65.0, 'weight' => 152.0, 'bps' => '116', 'bpd' => '74', 'pulse' => 70.0, 'temperature' => 98.2, 'respiration' => 15.0, 'waist_circ' => 31.5, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Henrik', 'lname' => 'Lindström', 'sex' => 'Male', 'dob' => '1967-03-22',
            'street' => 'Drottninggatan 50', 'city' => 'Stockholm', 'state' => 'AB', 'postal_code' => '111 21', 'country_code' => 'SE',
            'phone_home' => '+46-8-555-01417', 'language' => 'english',
            'vitals' => ['height' => 73.0, 'weight' => 188.0, 'bps' => '132', 'bpd' => '84', 'pulse' => 66.0, 'temperature' => 98.0, 'respiration' => 14.0, 'waist_circ' => 39.0, 'oxygen_saturation' => 95.0],
        ],
        [
            'fname' => 'Fatima', 'lname' => 'Al-Nasser', 'sex' => 'Female', 'dob' => '1989-06-17',
            'street' => 'Tahlia Street', 'city' => 'Jeddah', 'state' => 'MK', 'postal_code' => '21432', 'country_code' => 'SA',
            'phone_home' => '+966-12-555-0189', 'language' => 'english',
            'vitals' => ['height' => 61.0, 'weight' => 128.0, 'bps' => '110', 'bpd' => '70', 'pulse' => 64.0, 'temperature' => 98.5, 'respiration' => 16.0, 'waist_circ' => 28.0, 'oxygen_saturation' => 99.0],
        ],
        [
            'fname' => 'Minh-Tu', 'lname' => 'Nguyen', 'sex' => 'Male', 'dob' => '1999-08-29',
            'street' => 'Lê Lợi 190', 'city' => 'Ho Chi Minh City', 'state' => 'SG', 'postal_code' => '700000', 'country_code' => 'VN',
            'phone_home' => '+84-28-5550-0255', 'language' => 'english',
            'vitals' => ['height' => 66.5, 'weight' => 139.0, 'bps' => '114', 'bpd' => '74', 'pulse' => 78.0, 'temperature' => 98.1, 'respiration' => 18.0, 'waist_circ' => 30.0, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Chioma', 'lname' => 'Eze', 'sex' => 'Female', 'dob' => '1972-05-04',
            'street' => 'Adeniran Ogunsanya St', 'city' => 'Lagos', 'state' => 'LA', 'postal_code' => '101241', 'country_code' => 'NG',
            'phone_home' => '+234-1-555-0133', 'language' => 'english',
            'vitals' => ['height' => 64.5, 'weight' => 168.0, 'bps' => '126', 'bpd' => '80', 'pulse' => 71.0, 'temperature' => 98.3, 'respiration' => 15.0, 'waist_circ' => 35.0, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Rajesh', 'lname' => 'Kapoor', 'sex' => 'Male', 'dob' => '1986-10-12',
            'street' => 'Park Street', 'city' => 'Kolkata', 'state' => 'WB', 'postal_code' => '700016', 'country_code' => 'IN',
            'phone_home' => '+91-33-5550-0441', 'language' => 'english',
            'vitals' => ['height' => 68.0, 'weight' => 175.0, 'bps' => '120', 'bpd' => '78', 'pulse' => 73.0, 'temperature' => 98.4, 'respiration' => 16.0, 'waist_circ' => 34.5, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Elena', 'lname' => 'Kowalczyk', 'sex' => 'Female', 'dob' => '1994-02-28',
            'street' => 'ul. Marszałkowska 10', 'city' => 'Warsaw', 'state' => 'MZ', 'postal_code' => '00-590', 'country_code' => 'PL',
            'phone_home' => '+48-22-555-0192', 'language' => 'english',
            'vitals' => ['height' => 66.0, 'weight' => 134.0, 'bps' => '108', 'bpd' => '70', 'pulse' => 60.0, 'temperature' => 97.8, 'respiration' => 14.0, 'waist_circ' => 29.0, 'oxygen_saturation' => 99.0],
        ],
        [
            'fname' => 'Hiroshi', 'lname' => 'Tanaka', 'sex' => 'Male', 'dob' => '1958-09-09',
            'street' => '2-15-12 Shibuya', 'city' => 'Tokyo', 'state' => '13', 'postal_code' => '150-0002', 'country_code' => 'JP',
            'phone_home' => '+81-3-5550-0771', 'language' => 'english',
            'vitals' => ['height' => 66.0, 'weight' => 154.0, 'bps' => '118', 'bpd' => '72', 'pulse' => 62.0, 'temperature' => 98.0, 'respiration' => 15.0, 'waist_circ' => 33.5, 'oxygen_saturation' => 96.0],
        ],
        [
            'fname' => 'Zara', 'lname' => 'Hassan', 'sex' => 'Female', 'dob' => '2003-04-16',
            'street' => 'Jalan Bukit Bintang 88', 'city' => 'Kuala Lumpur', 'state' => 'KUL', 'postal_code' => '55100', 'country_code' => 'MY',
            'phone_home' => '+60-3-5550-0288', 'language' => 'english',
            'vitals' => ['height' => 63.0, 'weight' => 118.0, 'bps' => '104', 'bpd' => '66', 'pulse' => 82.0, 'temperature' => 98.2, 'respiration' => 17.0, 'waist_circ' => 26.5, 'oxygen_saturation' => 99.0],
        ],
        [
            'fname' => 'Mateo', 'lname' => 'Herrera', 'sex' => 'Male', 'dob' => '1990-12-01',
            'street' => 'Carrera 7 #71-21', 'city' => 'Bogotá', 'state' => 'DC', 'postal_code' => '110221', 'country_code' => 'CO',
            'phone_home' => '+57-1-555-0366', 'language' => 'spanish',
            'vitals' => ['height' => 69.0, 'weight' => 165.0, 'bps' => '116', 'bpd' => '76', 'pulse' => 69.0, 'temperature' => 98.6, 'respiration' => 16.0, 'waist_circ' => 32.0, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Kemi', 'lname' => 'Oladipo', 'sex' => 'Female', 'dob' => '1979-07-07',
            'street' => 'Broad Street', 'city' => 'Lagos', 'state' => 'LA', 'postal_code' => '102273', 'country_code' => 'NG',
            'phone_home' => '+234-802-555-0299', 'language' => 'english',
            'vitals' => ['height' => 65.5, 'weight' => 158.0, 'bps' => '128', 'bpd' => '82', 'pulse' => 74.0, 'temperature' => 98.4, 'respiration' => 15.0, 'waist_circ' => 36.0, 'oxygen_saturation' => 96.0],
        ],
        [
            'fname' => 'Ivan', 'lname' => 'Petrov', 'sex' => 'Male', 'dob' => '1981-03-19',
            'street' => 'Vitosha Blvd 48', 'city' => 'Sofia', 'state' => '22', 'postal_code' => '1000', 'country_code' => 'BG',
            'phone_home' => '+359-2-555-0140', 'language' => 'english',
            'vitals' => ['height' => 71.0, 'weight' => 192.0, 'bps' => '130', 'bpd' => '86', 'pulse' => 77.0, 'temperature' => 98.2, 'respiration' => 15.0, 'waist_circ' => 37.5, 'oxygen_saturation' => 95.0],
        ],
        [
            'fname' => 'Sofia', 'lname' => 'Andersson', 'sex' => 'Female', 'dob' => '1998-01-25',
            'street' => 'Kungsportsavenyen 22', 'city' => 'Gothenburg', 'state' => 'O', 'postal_code' => '411 36', 'country_code' => 'SE',
            'phone_home' => '+46-31-555-0155', 'language' => 'english',
            'vitals' => ['height' => 67.0, 'weight' => 142.0, 'bps' => '112', 'bpd' => '72', 'pulse' => 65.0, 'temperature' => 98.0, 'respiration' => 15.0, 'waist_circ' => 30.5, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Kwame', 'lname' => 'Asante', 'sex' => 'Male', 'dob' => '1993-05-13',
            'street' => 'Oxford Street', 'city' => 'Accra', 'state' => 'AA', 'postal_code' => 'GA115', 'country_code' => 'GH',
            'phone_home' => '+233-30-555-0211', 'language' => 'english',
            'vitals' => ['height' => 69.5, 'weight' => 178.0, 'bps' => '122', 'bpd' => '78', 'pulse' => 68.0, 'temperature' => 98.3, 'respiration' => 16.0, 'waist_circ' => 34.0, 'oxygen_saturation' => 97.0],
        ],
    ];
}

/**
 * @return list<CopilotPatientDem>
 */
function copilotDemoPatientDefsPhysician2(): array
{
    return [
        [
            'fname' => 'Yuki', 'lname' => 'Nakamura', 'sex' => 'Female', 'dob' => '1987-04-22',
            'street' => 'Motomachi 3-5', 'city' => 'Yokohama', 'state' => '14', 'postal_code' => '231-0861', 'country_code' => 'JP',
            'phone_home' => '+81-45-5550-0332', 'language' => 'english',
            'vitals' => ['height' => 62.5, 'weight' => 115.0, 'bps' => '106', 'bpd' => '68', 'pulse' => 63.0, 'temperature' => 98.1, 'respiration' => 15.0, 'waist_circ' => 26.0, 'oxygen_saturation' => 99.0],
        ],
        [
            'fname' => 'Omar', 'lname' => 'Benali', 'sex' => 'Male', 'dob' => '1976-11-03',
            'street' => 'Rue Didouche Mourad 42', 'city' => 'Algiers', 'state' => '16', 'postal_code' => '16000', 'country_code' => 'DZ',
            'phone_home' => '+213-21-555-0177', 'language' => 'english',
            'vitals' => ['height' => 70.0, 'weight' => 182.0, 'bps' => '126', 'bpd' => '82', 'pulse' => 72.0, 'temperature' => 98.4, 'respiration' => 16.0, 'waist_circ' => 36.5, 'oxygen_saturation' => 96.0],
        ],
        [
            'fname' => 'Ingrid', 'lname' => 'Bergström', 'sex' => 'Female', 'dob' => '1969-08-14',
            'street' => 'Kungsgatan 3', 'city' => 'Uppsala', 'state' => 'C', 'postal_code' => '753 21', 'country_code' => 'SE',
            'phone_home' => '+46-18-555-0129', 'language' => 'english',
            'vitals' => ['height' => 65.0, 'weight' => 148.0, 'bps' => '118', 'bpd' => '76', 'pulse' => 67.0, 'temperature' => 98.0, 'respiration' => 14.0, 'waist_circ' => 32.5, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Tendai', 'lname' => 'Moyo', 'sex' => 'Male', 'dob' => '1996-02-19',
            'street' => 'Samora Machel Avenue', 'city' => 'Harare', 'state' => 'HA', 'postal_code' => '0000', 'country_code' => 'ZW',
            'phone_home' => '+263-4-555-0144', 'language' => 'english',
            'vitals' => ['height' => 71.5, 'weight' => 168.0, 'bps' => '114', 'bpd' => '74', 'pulse' => 75.0, 'temperature' => 98.2, 'respiration' => 17.0, 'waist_circ' => 32.0, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Lucía', 'lname' => 'Fernández', 'sex' => 'Female', 'dob' => '1991-09-07',
            'street' => 'Calle Serrano 45', 'city' => 'Madrid', 'state' => 'M', 'postal_code' => '28001', 'country_code' => 'ES',
            'phone_home' => '+34-91-555-0288', 'language' => 'spanish',
            'vitals' => ['height' => 64.0, 'weight' => 131.0, 'bps' => '110', 'bpd' => '72', 'pulse' => 61.0, 'temperature' => 98.5, 'respiration' => 15.0, 'waist_circ' => 29.0, 'oxygen_saturation' => 99.0],
        ],
        [
            'fname' => 'Viktor', 'lname' => 'Popov', 'sex' => 'Male', 'dob' => '1984-12-30',
            'street' => 'Khreshchatyk 15', 'city' => 'Kyiv', 'state' => '30', 'postal_code' => '01001', 'country_code' => 'UA',
            'phone_home' => '+380-44-555-0191', 'language' => 'english',
            'vitals' => ['height' => 73.5, 'weight' => 205.0, 'bps' => '134', 'bpd' => '88', 'pulse' => 78.0, 'temperature' => 98.3, 'respiration' => 15.0, 'waist_circ' => 40.0, 'oxygen_saturation' => 94.0],
        ],
        [
            'fname' => 'Naledi', 'lname' => 'Dlamini', 'sex' => 'Female', 'dob' => '2001-06-25',
            'street' => 'West Street', 'city' => 'Durban', 'state' => 'KZN', 'postal_code' => '4001', 'country_code' => 'ZA',
            'phone_home' => '+27-31-555-0233', 'language' => 'english',
            'vitals' => ['height' => 63.5, 'weight' => 124.0, 'bps' => '108', 'bpd' => '70', 'pulse' => 80.0, 'temperature' => 98.0, 'respiration' => 16.0, 'waist_circ' => 28.5, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Geoffrey', 'lname' => 'Okonkwo', 'sex' => 'Male', 'dob' => '1973-03-11',
            'street' => 'Awolowo Road', 'city' => 'Lagos', 'state' => 'LA', 'postal_code' => '101233', 'country_code' => 'NG',
            'phone_home' => '+234-803-555-0155', 'language' => 'english',
            'vitals' => ['height' => 68.5, 'weight' => 190.0, 'bps' => '130', 'bpd' => '84', 'pulse' => 70.0, 'temperature' => 98.4, 'respiration' => 15.0, 'waist_circ' => 38.0, 'oxygen_saturation' => 95.0],
        ],
        [
            'fname' => 'Anika', 'lname' => 'Krishnan', 'sex' => 'Female', 'dob' => '1998-10-08',
            'street' => 'Anna Salai', 'city' => 'Chennai', 'state' => 'TN', 'postal_code' => '600002', 'country_code' => 'IN',
            'phone_home' => '+91-44-5550-0777', 'language' => 'english',
            'vitals' => ['height' => 61.5, 'weight' => 112.0, 'bps' => '102', 'bpd' => '64', 'pulse' => 66.0, 'temperature' => 98.2, 'respiration' => 16.0, 'waist_circ' => 27.0, 'oxygen_saturation' => 99.0],
        ],
        [
            'fname' => 'Tomasz', 'lname' => 'Wójcik', 'sex' => 'Male', 'dob' => '1982-05-16',
            'street' => 'ul. Piotrkowska 80', 'city' => 'Łódź', 'state' => 'LD', 'postal_code' => '90-001', 'country_code' => 'PL',
            'phone_home' => '+48-42-555-0166', 'language' => 'english',
            'vitals' => ['height' => 72.0, 'weight' => 186.0, 'bps' => '124', 'bpd' => '80', 'pulse' => 73.0, 'temperature' => 98.1, 'respiration' => 15.0, 'waist_circ' => 36.0, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Brigitte', 'lname' => 'Dubois', 'sex' => 'Female', 'dob' => '1965-01-29',
            'street' => 'Rue de Rivoli 19', 'city' => 'Paris', 'state' => 'IDF', 'postal_code' => '75001', 'country_code' => 'FR',
            'phone_home' => '+33-1-5550-0442', 'language' => 'english',
            'vitals' => ['height' => 64.5, 'weight' => 144.0, 'bps' => '120', 'bpd' => '78', 'pulse' => 68.0, 'temperature' => 98.0, 'respiration' => 14.0, 'waist_circ' => 31.0, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Samir', 'lname' => 'Haddad', 'sex' => 'Male', 'dob' => '1990-07-21',
            'street' => 'Hamra Street', 'city' => 'Beirut', 'state' => 'BA', 'postal_code' => '1103', 'country_code' => 'LB',
            'phone_home' => '+961-1-555-0188', 'language' => 'english',
            'vitals' => ['height' => 69.0, 'weight' => 171.0, 'bps' => '118', 'bpd' => '76', 'pulse' => 71.0, 'temperature' => 98.6, 'respiration' => 16.0, 'waist_circ' => 33.5, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Fiona', 'lname' => 'MacLeod', 'sex' => 'Female', 'dob' => '1977-12-04',
            'street' => 'Princes Street 120', 'city' => 'Edinburgh', 'state' => 'SCT', 'postal_code' => 'EH2 4AD', 'country_code' => 'GB',
            'phone_home' => '+44-131-555-0299', 'language' => 'english',
            'vitals' => ['height' => 66.5, 'weight' => 156.0, 'bps' => '122', 'bpd' => '78', 'pulse' => 64.0, 'temperature' => 97.9, 'respiration' => 15.0, 'waist_circ' => 33.0, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Diego', 'lname' => 'Castillo', 'sex' => 'Male', 'dob' => '1994-04-13',
            'street' => 'Av. Corrientes 1234', 'city' => 'Buenos Aires', 'state' => 'C', 'postal_code' => 'C1043AAZ', 'country_code' => 'AR',
            'phone_home' => '+54-11-5550-0555', 'language' => 'spanish',
            'vitals' => ['height' => 67.5, 'weight' => 159.0, 'bps' => '116', 'bpd' => '74', 'pulse' => 69.0, 'temperature' => 98.3, 'respiration' => 16.0, 'waist_circ' => 32.5, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Akosua', 'lname' => 'Mensah', 'sex' => 'Female', 'dob' => '1989-08-18',
            'street' => 'Oxford Street', 'city' => 'Accra', 'state' => 'AA', 'postal_code' => 'GA184', 'country_code' => 'GH',
            'phone_home' => '+233-24-555-0311', 'language' => 'english',
            'vitals' => ['height' => 62.0, 'weight' => 136.0, 'bps' => '114', 'bpd' => '74', 'pulse' => 77.0, 'temperature' => 98.4, 'respiration' => 17.0, 'waist_circ' => 30.0, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Stefan', 'lname' => 'Jovanović', 'sex' => 'Male', 'dob' => '1980-02-02',
            'street' => 'Knez Mihailova 48', 'city' => 'Belgrade', 'state' => '00', 'postal_code' => '11000', 'country_code' => 'RS',
            'phone_home' => '+381-11-555-0144', 'language' => 'english',
            'vitals' => ['height' => 74.0, 'weight' => 198.0, 'bps' => '128', 'bpd' => '84', 'pulse' => 76.0, 'temperature' => 98.2, 'respiration' => 15.0, 'waist_circ' => 38.5, 'oxygen_saturation' => 96.0],
        ],
        [
            'fname' => 'Mirela', 'lname' => 'Ionescu', 'sex' => 'Female', 'dob' => '1993-11-27',
            'street' => 'Strada Lipscani 55', 'city' => 'Bucharest', 'state' => 'B', 'postal_code' => '030031', 'country_code' => 'RO',
            'phone_home' => '+40-21-555-0177', 'language' => 'english',
            'vitals' => ['height' => 65.5, 'weight' => 129.0, 'bps' => '110', 'bpd' => '70', 'pulse' => 62.0, 'temperature' => 98.1, 'respiration' => 15.0, 'waist_circ' => 28.5, 'oxygen_saturation' => 99.0],
        ],
        [
            'fname' => 'Chen', 'lname' => 'Wei', 'sex' => 'Male', 'dob' => '1971-06-09',
            'street' => 'Nanjing Road 100', 'city' => 'Shanghai', 'state' => 'SH', 'postal_code' => '200003', 'country_code' => 'CN',
            'phone_home' => '+86-21-5550-0888', 'language' => 'english',
            'vitals' => ['height' => 68.0, 'weight' => 162.0, 'bps' => '120', 'bpd' => '78', 'pulse' => 67.0, 'temperature' => 98.0, 'respiration' => 15.0, 'waist_circ' => 33.0, 'oxygen_saturation' => 97.0],
        ],
        [
            'fname' => 'Bridget', 'lname' => "O'Connor", 'sex' => 'Female', 'dob' => '1999-03-15',
            'street' => 'Patrick Street 22', 'city' => 'Cork', 'state' => 'M', 'postal_code' => 'T12 XF62', 'country_code' => 'IE',
            'phone_home' => '+353-21-555-0222', 'language' => 'english',
            'vitals' => ['height' => 67.0, 'weight' => 149.0, 'bps' => '116', 'bpd' => '74', 'pulse' => 72.0, 'temperature' => 98.3, 'respiration' => 16.0, 'waist_circ' => 31.5, 'oxygen_saturation' => 98.0],
        ],
        [
            'fname' => 'Aziz', 'lname' => 'Qureshi', 'sex' => 'Male', 'dob' => '1985-09-01',
            'street' => 'Jinnah Avenue', 'city' => 'Islamabad', 'state' => 'IS', 'postal_code' => '44000', 'country_code' => 'PK',
            'phone_home' => '+92-51-555-0199', 'language' => 'english',
            'vitals' => ['height' => 70.5, 'weight' => 176.0, 'bps' => '126', 'bpd' => '82', 'pulse' => 74.0, 'temperature' => 98.5, 'respiration' => 16.0, 'waist_circ' => 35.0, 'oxygen_saturation' => 96.0],
        ],
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
        return true;
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

function copilotBmiFromUsa(float $weightLb, float $heightIn): float
{
    if ($heightIn <= 0.0) {
        return 0.0;
    }

    return round(($weightLb / ($heightIn * $heightIn)) * 703, 1);
}

function copilotFacilityNameForId(int $facilityId): string
{
    $row = sqlQuery('SELECT `name` FROM `facility` WHERE `id` = ? LIMIT 1', [$facilityId]);
    if (is_array($row) && isset($row['name']) && trim((string) $row['name']) !== '') {
        return (string) $row['name'];
    }

    return 'Unknown';
}

function copilotDemoVitalsRowExists(int $pid): bool
{
    $row = sqlQuery(
        'SELECT `id` FROM `form_vitals` WHERE `pid` = ? AND `note` = ? LIMIT 1',
        [$pid, 'CCSEED_DEMO_VITAL']
    );

    return is_array($row) && isset($row['id']);
}

/**
 * Demo intake encounter for linking vitals (idempotent).
 */
function copilotEnsureDemoEncounter(int $pid, int $providerId, int $facilityId, int $pcCatId): int
{
    $row = sqlQuery(
        'SELECT `encounter` FROM `form_encounter` WHERE `pid` = ? AND `reason` = ? LIMIT 1',
        [$pid, 'CCSEED demo intake']
    );
    if (is_array($row) && isset($row['encounter'])) {
        return (int) $row['encounter'];
    }

    $encounter = (int) QueryUtils::generateId();
    $feUuid = (new UuidRegistry(['table_name' => 'form_encounter']))->createUuid();
    $facilityName = copilotFacilityNameForId($facilityId);

    sqlStatement(
        'INSERT INTO `form_encounter` SET
            `uuid` = ?,
            `date` = NOW(),
            `reason` = ?,
            `facility` = ?,
            `facility_id` = ?,
            `billing_facility` = ?,
            `pid` = ?,
            `encounter` = ?,
            `pc_catid` = ?,
            `provider_id` = ?,
            `class_code` = ?',
        [
            $feUuid,
            'CCSEED demo intake',
            $facilityName,
            $facilityId,
            $facilityId,
            $pid,
            $encounter,
            $pcCatId,
            $providerId,
            'AMB',
        ]
    );

    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: created CCSEED encounter encounter={$encounter} pid={$pid}.\n");

    return $encounter;
}

/**
 * @phpstan-param CopilotVitals $vitals
 */
function copilotEnsureDemoVitals(int $pid, int $providerId, int $facilityId, int $pcCatId, array $vitals): void
{
    if (copilotDemoVitalsRowExists($pid)) {
        return;
    }

    $encounter = copilotEnsureDemoEncounter($pid, $providerId, $facilityId, $pcCatId);
    $height = (float) ($vitals['height'] ?? 66.0);
    $weight = (float) ($vitals['weight'] ?? 170.0);
    $bmi = copilotBmiFromUsa($weight, $height);
    $bmiCategory = BmiCategory::fromBmi($bmi);
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $providerRow = sqlQuery('SELECT `username` FROM `users` WHERE `id` = ? LIMIT 1', [$providerId]);
    $providerUsername = (is_array($providerRow) && !empty($providerRow['username']))
        ? (string) $providerRow['username']
        : 'admin';
    $session->set('authUserID', $providerId);
    $session->set('authUser', $providerUsername);
    $session->set('authProvider', 'Default');
    $session->set('userauthorized', 1);
    $session->set('facilityId', $facilityId);

    $vitalsService = new VitalsService();
    $vitalsService->setShouldConvertVitalMeasurementsFlag(false);
    $vitalsService->save([
        'pid' => (string) $pid,
        'eid' => $encounter,
        'authorized' => '1',
        'activity' => '1',
        'bps' => (string) ($vitals['bps'] ?? '120'),
        'bpd' => (string) ($vitals['bpd'] ?? '80'),
        'weight' => $weight,
        'height' => $height,
        'temperature' => (float) ($vitals['temperature'] ?? 98.6),
        'temp_method' => 'Oral',
        'pulse' => (float) ($vitals['pulse'] ?? 72),
        'respiration' => (float) ($vitals['respiration'] ?? 16),
        'BMI' => $bmi,
        'BMI_status' => $bmiCategory !== null ? $bmiCategory->value : '',
        'waist_circ' => (float) ($vitals['waist_circ'] ?? 0),
        'oxygen_saturation' => (float) ($vitals['oxygen_saturation'] ?? 98),
        'oxygen_flow_rate' => 0.0,
        'user' => 'admin',
        'groupname' => 'Default',
        'note' => 'CCSEED_DEMO_VITAL',
    ]);

    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: seeded vitals pid={$pid}.\n");
}

/**
 * @phpstan-param CopilotPatientDem $dem
 */
function copilotFallbackPatientDem(int $slot, string $pLabel): array
{
    $h = 62 + ($slot % 7);
    $w = 125 + (($slot * 11) % 90);

    return [
        'fname' => 'Seed',
        'lname' => sprintf('%s%02d', $pLabel, $slot),
        'sex' => 'Unknown',
        'dob' => '1980-01-01',
        'vitals' => [
            'height' => (float) $h,
            'weight' => (float) $w,
            'bps' => (string) (108 + (($slot * 3) % 30)),
            'bpd' => (string) (66 + (($slot * 2) % 18)),
            'pulse' => (float) (58 + (($slot * 4) % 32)),
            'temperature' => round(97.4 + ($slot % 10) / 10, 1),
            'respiration' => (float) (12 + ($slot % 9)),
            'waist_circ' => (float) (28 + ($slot % 14)),
            'oxygen_saturation' => (float) (94 + ($slot % 6)),
        ],
    ];
}

/**
 * Accepts either keyed patient definitions or legacy positional rows:
 * [fname, lname, sex, dob]. Always returns keyed CopilotPatientDem.
 */
function copilotNormalizePatientDem(array $dem, int $slot, string $pLabel): array
{
    $fallback = copilotFallbackPatientDem($slot, $pLabel);

    // Legacy positional format from older seed arrays.
    if (!array_key_exists('fname', $dem) && array_key_exists(0, $dem)) {
        $dem = [
            'fname' => (string) ($dem[0] ?? ''),
            'lname' => (string) ($dem[1] ?? ''),
            'sex' => (string) ($dem[2] ?? ''),
            'dob' => (string) ($dem[3] ?? ''),
        ];
    }

    $out = $dem;
    foreach (['fname', 'lname', 'sex', 'dob', 'street', 'city', 'state', 'postal_code', 'country_code', 'phone_home', 'language'] as $k) {
        if (!isset($out[$k]) && isset($fallback[$k])) {
            $out[$k] = $fallback[$k];
        }
    }
    if (!isset($out['vitals']) || !is_array($out['vitals'])) {
        $out['vitals'] = $fallback['vitals'];
    }

    return $out;
}

/**
 * @phpstan-param CopilotPatientDem $dem
 */
function copilotEnsurePatient(string $pubpid, array $dem): int
{
    $fname = trim((string) ($dem['fname'] ?? ''));
    $lname = trim((string) ($dem['lname'] ?? ''));
    $sex = trim((string) ($dem['sex'] ?? ''));
    $dob = trim((string) ($dem['dob'] ?? ''));
    if ($fname === '') {
        $fname = 'Seed';
    }
    if ($lname === '') {
        $lname = 'Patient';
    }
    if ($sex === '') {
        $sex = 'Unknown';
    }
    $dobDt = \DateTimeImmutable::createFromFormat('Y-m-d', $dob);
    if ($dobDt === false || $dobDt->format('Y-m-d') !== $dob) {
        $dob = '1980-01-01';
    }

    $street = trim((string) ($dem['street'] ?? '1 Demo Clinic Way'));
    $city = trim((string) ($dem['city'] ?? 'Boston'));
    $state = trim((string) ($dem['state'] ?? 'MA'));
    $postal = trim((string) ($dem['postal_code'] ?? '02118'));
    $country = trim((string) ($dem['country_code'] ?? 'USA'));
    $phone = trim((string) ($dem['phone_home'] ?? '555-0100'));
    $language = trim((string) ($dem['language'] ?? 'english'));
    if ($street === '') {
        $street = '1 Demo Clinic Way';
    }
    if ($city === '') {
        $city = 'Boston';
    }
    if ($state === '') {
        $state = 'MA';
    }
    if ($postal === '') {
        $postal = '02118';
    }
    if ($country === '') {
        $country = 'USA';
    }
    if ($phone === '') {
        $phone = '555-0100';
    }
    if ($language === '') {
        $language = 'english';
    }

    $existing = sqlQuery('SELECT `pid` FROM `patient_data` WHERE `pubpid` = ? LIMIT 1', [$pubpid]);
    if (is_array($existing) && isset($existing['pid'])) {
        $pid = (int) $existing['pid'];
        sqlStatement(
            'UPDATE `patient_data` SET
                `fname` = ?,
                `lname` = ?,
                `DOB` = ?,
                `sex` = ?,
                `street` = ?,
                `city` = ?,
                `state` = ?,
                `postal_code` = ?,
                `country_code` = ?,
                `phone_home` = ?,
                `language` = ?,
                `updated_by` = 1
             WHERE `pid` = ?',
            [
                $fname,
                $lname,
                $dob,
                $sex,
                $street,
                $city,
                $state,
                $postal,
                $country,
                $phone,
                $language,
                $pid,
            ]
        );
        fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: updated patient pid={$pid} pubpid={$pubpid} ({$fname} {$lname}).\n");

        return $pid;
    }

    $pidRow = sqlQuery('SELECT MAX(`pid`) AS lastpid FROM `patient_data`');
    $nextPid = 1;
    if (is_array($pidRow) && isset($pidRow['lastpid']) && $pidRow['lastpid'] !== null) {
        $nextPid = (int) $pidRow['lastpid'] + 1;
    }

    $uuidBin = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
    $email = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $fname . '.' . $lname))
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
            $fname,
            $lname,
            '',
            $dob,
            $sex,
            $street,
            $city,
            $state,
            $postal,
            $country,
            $phone,
            $email,
            $nextPid,
            $pubpid,
            $language,
            'active',
            'standard',
        ]
    );

    fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: created patient pid={$nextPid} pubpid={$pubpid} ({$fname} {$lname}).\n");

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

function copilotHistoricalAppointmentExists(int $pid, string $eventDate, string $startTime): bool
{
    $row = sqlQuery(
        'SELECT `pc_eid` FROM `openemr_postcalendar_events`
         WHERE `pc_pid` = ? AND `pc_eventDate` = ? AND `pc_startTime` = ? AND `pc_hometext` = ?
         LIMIT 1',
        [(string) $pid, $eventDate, $startTime, 'CCSEED_DEMO_APPT_HIST']
    );

    return is_array($row) && !empty($row['pc_eid']);
}

function copilotHistoricalProblemExists(int $pid, string $title): bool
{
    $row = sqlQuery(
        'SELECT `id` FROM `lists`
         WHERE `pid` = ? AND `type` = ? AND `title` = ? AND `comments` = ?
         LIMIT 1',
        [$pid, 'medical_problem', $title, 'CCSEED_DEMO_ILLNESS']
    );

    return is_array($row) && isset($row['id']);
}

/**
 * @return list<string>
 */
function copilotIllnessCatalog(): array
{
    return [
        'Chronic cough',
        'Influenza',
        'Flu-like illness',
        'Muscle twitching',
        'Seasonal allergic rhinitis',
        'Migraine headaches',
        'Low back pain',
        'Gastroesophageal reflux symptoms',
    ];
}

function copilotEnsureHistoricalIllnesses(int $pid, int $slot): void
{
    // Deterministic subset: roughly half of seeded patients get problem-list entries.
    if ((($slot + $pid) % 2) !== 0) {
        return;
    }

    $catalog = copilotIllnessCatalog();
    $count = (($slot + $pid) % 2) + 1; // 1-2 problems
    $today = new \DateTimeImmutable('today');

    for ($index = 0; $index < $count; $index++) {
        $catalogIndex = ($slot * 3 + $pid + $index) % count($catalog);
        $title = $catalog[$catalogIndex];
        if (copilotHistoricalProblemExists($pid, $title)) {
            continue;
        }

        $begDate = $today->modify('-' . (35 + ($slot % 7) + ($index * 16)) . ' days')->format('Y-m-d H:i:s');
        $uuidBin = (new UuidRegistry(['table_name' => 'lists']))->createUuid();

        sqlStatement(
            'INSERT INTO `lists` SET
                `uuid` = ?,
                `date` = ?,
                `type` = ?,
                `subtype` = ?,
                `title` = ?,
                `begdate` = ?,
                `diagnosis` = ?,
                `activity` = ?,
                `comments` = ?,
                `pid` = ?,
                `user` = ?,
                `groupname` = ?,
                `outcome` = ?',
            [
                $uuidBin,
                $begDate,
                'medical_problem',
                'diagnosis',
                $title,
                $begDate,
                'CCSEED:' . $title,
                1,
                'CCSEED_DEMO_ILLNESS',
                $pid,
                'admin',
                'Default',
                0,
            ]
        );

        sqlStatement(
            'INSERT INTO `lists_touch` (`pid`, `type`, `date`)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `date` = VALUES(`date`)',
            [$pid, 'medical_problem']
        );

        fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: seeded problem-list illness pid={$pid} title={$title}.\n");
    }
}

function copilotEnsureHistoricalAppointmentsForPatient(
    int $pid,
    int $providerId,
    int $catId,
    int $facilityId,
    string $physicianLabel,
    int $slotSeconds,
    int $slot
): void {
    $base = new \DateTimeImmutable('09:00:00');
    $historyCount = (($slot + $providerId) % 2) + 1; // 1-2 completed visits
    for ($index = 0; $index < $historyCount; $index++) {
        // At least one month in the past.
        $daysBack = 35 + ($slot * 2) + ($index * 19);
        $eventDate = (new \DateTimeImmutable('today'))->modify("-{$daysBack} days")->format('Y-m-d');
        $start = $base->modify('+' . (($slot + $index) * $slotSeconds) . ' seconds');
        $startSql = $start->format('H:i:s');
        $endSql = $start->modify('+' . $slotSeconds . ' seconds')->format('H:i:s');

        if (copilotHistoricalAppointmentExists($pid, $eventDate, $startSql)) {
            continue;
        }

        $eventUuidBin = (new UuidRegistry(['table_name' => 'openemr_postcalendar_events']))->createUuid();
        $title = 'Completed office visit (history ' . $physicianLabel . ' ' . $slot . '-' . ($index + 1) . ')';

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
                'CCSEED_DEMO_APPT_HIST',
                $eventDate,
                $slotSeconds,
                serialize(copilotNoRecurrspec()),
                $startSql,
                $endSql,
                '@',
                serialize(copilotEmptyLocationSpec()),
                $facilityId,
                $facilityId,
                '',
            ]
        );

        fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: created historical completed appointment pid={$pid} {$eventDate} {$startSql}-{$endSql} provider_id={$providerId}.\n");
    }
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

/** @var list<array{username:string, defs:list<CopilotPatientDem>, physicianLabel:string}> */
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
        $dem = (isset($defs[$i]) && is_array($defs[$i])) ? $defs[$i] : copilotFallbackPatientDem($slot, $pLabel);
        $dem = copilotNormalizePatientDem($dem, $slot, $pLabel);
        $pid = copilotEnsurePatient($pubpid, $dem);
        $vitalsDem = is_array($dem['vitals'] ?? null) ? $dem['vitals'] : copilotFallbackPatientDem($slot, $pLabel)['vitals'];
        copilotEnsureDemoVitals($pid, $providerId, $facilityId, $catId, $vitalsDem);
        copilotEnsureHistoricalAppointmentsForPatient($pid, $providerId, $catId, $facilityId, $pLabel, $slotSeconds, $slot);
        copilotEnsureHistoricalIllnesses($pid, $slot);

        $start = $firstStart->modify('+' . ($i * $slotSeconds) . ' seconds');
        $startSql = $start->format('H:i:s');
        $end = $start->modify('+' . $slotSeconds . ' seconds');
        $endSql = $end->format('H:i:s');

        if (copilotAppointmentExists($pid, $scheduleDate, $startSql)) {
            fwrite(STDOUT, "openemr-seed-copilot-demo-schedule: appointment exists pid={$pid} {$scheduleDate} {$startSql}, skipping {$pLabel} slot {$slot}.\n");
            continue;
        }

        $eventUuidBin = (new UuidRegistry(['table_name' => 'openemr_postcalendar_events']))->createUuid();
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
