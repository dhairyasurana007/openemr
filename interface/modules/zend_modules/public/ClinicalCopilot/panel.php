<?php

/**
 * Clinical Co-Pilot shell UI (opened from the main menu).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../globals.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    echo xlt('Not authorized');
    exit;
}

$handoff = AgentRuntimeHandoff::fromEnvironment();
$statusText = $handoff->isConfigured()
    ? xl('Server-side agent URL is configured. Encounter and schedule tools will attach here.')
    : xl('Agent URL is not configured. Set CLINICAL_COPILOT_AGENT_BASE_URL (and optionally CLINICAL_COPILOT_AGENT_PUBLIC_URL) on the web server.');

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo text(xl('Clinical Co-Pilot')); ?></title>
</head>
<body>
    <div class="container mt-3">
        <h3><?php echo xlt('Clinical Co-Pilot'); ?></h3>
        <p class="text-muted"><?php echo text($statusText); ?></p>
    </div>
</body>
</html>
