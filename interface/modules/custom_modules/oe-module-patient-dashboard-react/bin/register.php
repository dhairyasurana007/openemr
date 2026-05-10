<?php

/**
 * CLI script — registers and enables the React Patient Dashboard module in OpenEMR's modules table.
 *
 * Run inside the Docker container:
 *   docker compose exec openemr php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-patient-dashboard-react/bin/register.php
 *
 * Safe to run multiple times — exits cleanly if the module is already registered/enabled.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

// Match the pattern used by all contrib/render seed scripts: set HTTP context globals that
// globals.php needs for site resolution before it is loaded, then suppress auth checks.
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'default';
}
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}
$ignoreAuth = true;

// globals.php is at interface/globals.php — four levels up from bin/
require_once dirname(__DIR__, 4) . '/globals.php';

use OpenEMR\Common\Database\QueryUtils;

const MODULE_DIR  = 'oe-module-patient-dashboard-react';
const MODULE_NAME = 'React Patient Dashboard';
const MODULE_TYPE_CUSTOM = 0;
const MODULE_RELATIVE_LINK = '/interface/modules/custom_modules/oe-module-patient-dashboard-react/public/index.php';

$existing = QueryUtils::fetchRecords(
    "SELECT mod_id, mod_active FROM modules WHERE mod_directory = ?",
    [MODULE_DIR]
);

if (!empty($existing)) {
    $row = $existing[0];
    if ((int) $row['mod_active'] === 1) {
        echo "Module already registered and active (mod_id={$row['mod_id']}).\n";
        exit(0);
    }
    sqlStatementNoLog("UPDATE modules SET mod_active = 1 WHERE mod_directory = ?", [MODULE_DIR]);
    echo "Module enabled (was registered but inactive, mod_id={$row['mod_id']}).\n";
    exit(0);
}

$modId = QueryUtils::sqlInsert(
    "INSERT INTO modules SET
        mod_name          = ?,
        mod_active        = 1,
        mod_ui_name       = ?,
        mod_relative_link = ?,
        mod_directory     = ?,
        type              = ?,
        date              = NOW()",
    [MODULE_NAME, MODULE_NAME, MODULE_RELATIVE_LINK, MODULE_DIR, MODULE_TYPE_CUSTOM]
);

sqlStatementNoLog(
    "INSERT INTO module_acl_sections VALUES (?, ?, 0, ?, ?)",
    [$modId, MODULE_NAME, strtolower(MODULE_DIR), $modId]
);

echo "Module registered and enabled (mod_id={$modId}).\n";
