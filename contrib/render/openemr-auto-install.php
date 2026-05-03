<?php

/**
 * Non-interactive first-boot installer (replaces the web setup.php flow for containers).
 *
 * Reads the same environment variables as the official flex `openemr.sh` / `auto_configure.php`
 * stack (MYSQL_HOST, MYSQL_ROOT_PASS, MYSQL_USER, …) and runs {@see Installer::quick_install()}.
 *
 * Idempotent: if sites/default/sqlconf.php already has $config === 1, exits successfully.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Services\Globals\GlobalConnectorsEnum;

$openemrRoot = dirname(__DIR__, 2);
chdir($openemrRoot);

require_once $openemrRoot . '/vendor/autoload.php';

if (is_file($openemrRoot . '/library/authentication/password_hashing.php')) {
    require_once $openemrRoot . '/library/authentication/password_hashing.php';
}

$sqlconfPath = $openemrRoot . '/sites/default/sqlconf.php';
if (!is_readable($sqlconfPath)) {
    fwrite(STDERR, "openemr-auto-install: sqlconf.php not readable at {$sqlconfPath}\n");
    exit(1);
}

require $sqlconfPath;

if (isset($config) && (int) $config === 1) {
    fwrite(STDOUT, "openemr-auto-install: already configured (\$config=1), skipping.\n");
    exit(0);
}

$manual = getenv('MANUAL_SETUP');
if ($manual !== false && strtolower((string) $manual) === 'yes') {
    fwrite(STDOUT, "openemr-auto-install: MANUAL_SETUP=yes, skipping automated install.\n");
    exit(0);
}

if (getenv('OPENEMR_SKIP_AUTO_INSTALL') === '1') {
    fwrite(STDOUT, "openemr-auto-install: OPENEMR_SKIP_AUTO_INSTALL=1, skipping.\n");
    exit(0);
}

$mysqlHost = getenv('MYSQL_HOST');
if ($mysqlHost === false || $mysqlHost === '') {
    fwrite(STDOUT, "openemr-auto-install: MYSQL_HOST not set, skipping (use setup.php or set env).\n");
    exit(0);
}

$rootPassEnv = getenv('MYSQL_ROOT_PASS');
if ($rootPassEnv === false || $rootPassEnv === '') {
    $altRoot = getenv('MYSQL_ROOT_PASSWORD');
    if ($altRoot !== false && $altRoot !== '') {
        $rootPassEnv = $altRoot;
    }
}

$noRoot = getenv('MYSQL_NO_ROOT');
$noRootDbAccess = ($noRoot !== false && $noRoot !== '' && strtolower((string) $noRoot) !== 'no' && strtolower((string) $noRoot) !== '0');

// Match openemr.sh: MYSQL_ROOT_PASS defaults to "root" when unset or empty (unless using pre-provisioned DB).
if (!$noRootDbAccess && ($rootPassEnv === false || $rootPassEnv === '')) {
    $rootPassEnv = 'root';
}

$mysqlPort = getenv('MYSQL_PORT');
$mysqlRootUser = getenv('MYSQL_ROOT_USER');
$mysqlUser = getenv('MYSQL_USER');
$mysqlPass = getenv('MYSQL_PASS');
$mysqlDatabase = getenv('MYSQL_DATABASE');
$mysqlCollation = getenv('MYSQL_COLLATION');
$mysqlLoginhost = getenv('MYSQL_LOGINHOST');

$oeUser = getenv('OE_USER');
$oeUserName = getenv('OE_USER_NAME');
$oePass = getenv('OE_PASS');

$installSettings = [
    'iuser' => ($oeUser !== false && $oeUser !== '') ? $oeUser : 'admin',
    'iuname' => ($oeUserName !== false && $oeUserName !== '') ? $oeUserName : 'Administrator',
    'iuserpass' => ($oePass !== false && $oePass !== '') ? $oePass : 'pass',
    'igroup' => 'Default',
    'server' => $mysqlHost,
    'loginhost' => ($mysqlLoginhost !== false && $mysqlLoginhost !== '') ? $mysqlLoginhost : '%',
    'port' => ($mysqlPort !== false && $mysqlPort !== '') ? $mysqlPort : '3306',
    'root' => ($mysqlRootUser !== false && $mysqlRootUser !== '') ? $mysqlRootUser : 'root',
    'rootpass' => ($rootPassEnv !== false && $rootPassEnv !== '') ? (string) $rootPassEnv : '',
    'login' => ($mysqlUser !== false && $mysqlUser !== '') ? $mysqlUser : 'openemr',
    'pass' => ($mysqlPass !== false && $mysqlPass !== '') ? $mysqlPass : 'openemr',
    'dbname' => ($mysqlDatabase !== false && $mysqlDatabase !== '') ? $mysqlDatabase : 'openemr',
    'collate' => ($mysqlCollation !== false && $mysqlCollation !== '') ? $mysqlCollation : 'utf8mb4_general_ci',
    'site' => 'default',
    'source_site_id' => '',
    'clone_database' => '',
    'no_root_db_access' => $noRootDbAccess ? '1' : '',
    'development_translations' => '',
];

$siteAddr = getenv('OPENEMR_SETTING_site_addr_oath');
if ($siteAddr !== false && $siteAddr !== '') {
    $customGlobals = [
        GlobalConnectorsEnum::SITE_ADDRESS_OAUTH->value => ['value' => $siteAddr],
    ];
    $installSettings['custom_globals'] = json_encode($customGlobals, JSON_THROW_ON_ERROR);
}

$installer = new Installer($installSettings, new OpenEMR\Common\Logging\SystemLogger());

fwrite(STDOUT, "openemr-auto-install: running quick_install (schema load can take several minutes; Render may log \"No open ports\" until Apache starts after this).\n");
if (\function_exists('fflush')) {
    @fflush(STDOUT);
}

if (!$installer->quick_install()) {
    fwrite(STDERR, 'openemr-auto-install: ERROR: ' . $installer->error_message . "\n");
    exit(1);
}

fwrite(STDOUT, $installer->debug_message . "\n");
fwrite(STDOUT, "openemr-auto-install: completed successfully.\n");
exit(0);
