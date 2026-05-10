<?php

/**
 * React Patient Dashboard Entry Point
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Codex <noreply@example.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once dirname(__FILE__, 5) . '/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    die(xlt('Not authorized'));
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid = (int)($_GET['pid'] ?? $_GET['set_pid'] ?? ($session->get('pid') ?? 0));
$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleWebPath = $webRoot . '/interface/modules/custom_modules/oe-module-patient-dashboard-react';
$legacyDashboardUrl = $webRoot . '/interface/patient_file/summary/demographics.php';
if ($pid > 0) {
    $legacyDashboardUrl .= '?set_pid=' . rawurlencode((string)$pid);
}

Header::setupHeader();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt('Modern Patient Dashboard'); ?></title>
    <link rel="stylesheet" href="<?php echo attr($moduleWebPath); ?>/public/assets/dashboard.css">
</head>
<body class="bg-light">
<div class="container-fluid mt-2">
    <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo attr(xla('Dashboard View Toggle')); ?>">
        <a class="btn btn-outline-secondary" href="<?php echo attr($legacyDashboardUrl); ?>" onclick="top.restoreSession()">
            <?php echo xlt('Legacy'); ?>
        </a>
        <button type="button" class="btn btn-primary active" aria-pressed="true" disabled>
            <?php echo xlt('Modern'); ?>
        </button>
    </div>
</div>
<div id="patient-dashboard-react-root"></div>
<script>
window.OEMR_DASHBOARD_BOOTSTRAP = {
    webRoot: <?php echo js_escape($webRoot); ?>,
    moduleWebPath: <?php echo js_escape($moduleWebPath); ?>,
    patientId: <?php echo js_escape((string)$pid); ?>,
    csrfToken: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>,
    apiBase: <?php echo js_escape($webRoot . '/apis/default'); ?>,
    timezone: <?php echo js_escape(date_default_timezone_get()); ?>,
};
</script>
<script type="module" src="<?php echo attr($moduleWebPath); ?>/public/assets/dashboard.js"></script>
</body>
</html>
