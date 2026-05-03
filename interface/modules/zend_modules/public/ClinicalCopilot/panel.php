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

        <div class="form-group mt-4">
            <label for="clinical-copilot-message" class="font-weight-bold"><?php echo xlt('Message'); ?></label>
            <div class="input-group">
                <input type="text" class="form-control" id="clinical-copilot-message" name="clinical_copilot_message"
                    maxlength="4000" autocomplete="off"
                    placeholder="<?php echo xla('Type a message (not sent yet)'); ?>"
                    aria-describedby="clinical-copilot-compose-help">
                <div class="input-group-append">
                    <button type="button" class="btn btn-secondary" id="clinical-copilot-send" disabled
                        title="<?php echo xla('Send is not enabled yet'); ?>"
                        aria-label="<?php echo xla('Send message'); ?>">
                        <span class="fa fa-play" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
            <small id="clinical-copilot-compose-help" class="form-text text-muted"><?php echo xlt('Send is not connected to the agent yet.'); ?></small>
        </div>
    </div>
</body>
</html>
