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
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    echo xlt('Not authorized');
    exit;
}

$handoff = AgentRuntimeHandoff::fromEnvironment();
$statusText = $handoff->isConfigured()
    ? xl('Server-side agent URL is configured. Messages are sent to the co-pilot agent, which calls OpenRouter.')
    : xl('Agent URL is not configured. Set CLINICAL_COPILOT_AGENT_BASE_URL (and optionally CLINICAL_COPILOT_AGENT_PUBLIC_URL) on the web server.');

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$copilotCsrfToken = CsrfUtils::collectCsrfToken($session);
$chatUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/chat.php';
$agentReady = $handoff->isConfigured();

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
                    placeholder="<?php echo xla('Type a message'); ?>"
                    aria-describedby="clinical-copilot-compose-help">
                <div class="input-group-append">
                    <button type="button" class="btn btn-secondary" id="clinical-copilot-send"
                        <?php echo $agentReady ? '' : 'disabled'; ?>
                        title="<?php echo $agentReady ? xla('Send to co-pilot agent') : xla('Configure CLINICAL_COPILOT_AGENT_BASE_URL first'); ?>"
                        aria-label="<?php echo xla('Send message'); ?>">
                        <span class="fa fa-play" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
            <small id="clinical-copilot-compose-help" class="form-text text-muted"><?php echo xlt('Replies appear below. The agent must have OPENROUTER_API_KEY set.'); ?></small>
        </div>
        <div id="clinical-copilot-reply" class="border rounded p-3 mt-3 bg-light" style="min-height: 4rem;">
            <span class="text-muted" id="clinical-copilot-reply-placeholder"><?php echo xlt('No reply yet.'); ?></span>
        </div>
    </div>
    <script>
        (function () {
            var chatUrl = <?php echo json_encode($chatUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var csrfToken = <?php echo json_encode($copilotCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var agentReady = <?php echo $agentReady ? 'true' : 'false'; ?>;
            var btn = document.getElementById('clinical-copilot-send');
            var input = document.getElementById('clinical-copilot-message');
            var replyBox = document.getElementById('clinical-copilot-reply');
            var placeholder = document.getElementById('clinical-copilot-reply-placeholder');
            if (!btn || !input || !replyBox) {
                return;
            }
            btn.addEventListener('click', function () {
                if (!agentReady) {
                    return;
                }
                var msg = (input.value || '').trim();
                if (!msg) {
                    return;
                }
                if (typeof top.restoreSession === 'function') {
                    top.restoreSession();
                }
                btn.disabled = true;
                if (placeholder) {
                    placeholder.remove();
                }
                replyBox.textContent = '';
                var loading = document.createElement('div');
                loading.className = 'text-muted';
                loading.appendChild(document.createTextNode(<?php echo json_encode(xl('Waiting for response') . '…'); ?>));
                replyBox.appendChild(loading);
                fetch(chatUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        message: msg,
                        csrf_token_form: csrfToken
                    })
                }).then(function (r) {
                    return r.json().then(function (data) {
                        return {ok: r.ok, status: r.status, data: data};
                    });
                }).then(function (res) {
                    replyBox.textContent = '';
                    if (res.ok && res.data && typeof res.data.reply === 'string') {
                        replyBox.appendChild(document.createTextNode(res.data.reply));
                    } else {
                        var err = (res.data && res.data.error) ? res.data.error : <?php echo json_encode(xl('Request failed')); ?>;
                        var p = document.createElement('p');
                        p.className = 'text-danger mb-0';
                        p.appendChild(document.createTextNode(err));
                        replyBox.appendChild(p);
                    }
                }).catch(function () {
                    replyBox.textContent = '';
                    var p = document.createElement('p');
                    p.className = 'text-danger mb-0';
                    p.appendChild(document.createTextNode(<?php echo json_encode(xl('Network error')); ?>));
                    replyBox.appendChild(p);
                }).finally(function () {
                    btn.disabled = false;
                });
            });
        })();
    </script>
</body>
</html>
