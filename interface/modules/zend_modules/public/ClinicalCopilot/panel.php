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
    ? xl('Messages go to the co-pilot agent (OpenRouter, default Anthropic Haiku).')
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
    <style>
        html, body {
            height: 100%;
            margin: 0;
        }
        #clinical-copilot-shell {
            height: 100%;
            display: flex;
            flex-direction: column;
            max-width: 100%;
        }
        #clinical-copilot-messages {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            background: var(--white, #fff);
        }
        #clinical-copilot-composer {
            flex: 0 0 auto;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            background: var(--light, #f8f9fa);
        }
        .clinical-copilot-msg {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .clinical-copilot-msg-user {
            text-align: right;
        }
        .clinical-copilot-msg-user .clinical-copilot-bubble {
            display: inline-block;
            text-align: left;
            background: var(--primary, #007bff);
            color: #fff;
            border-radius: 1rem 1rem 0.25rem 1rem;
        }
        .clinical-copilot-msg-assistant .clinical-copilot-bubble {
            display: inline-block;
            background: var(--light, #e9ecef);
            color: inherit;
            border-radius: 1rem 1rem 1rem 0.25rem;
        }
        .clinical-copilot-bubble {
            padding: 0.5rem 0.85rem;
            margin: 0.35rem 0;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .clinical-copilot-msg-meta {
            font-size: 0.75rem;
            color: var(--gray, #6c757d);
            margin-top: 0.15rem;
        }
    </style>
</head>
<body>
    <div id="clinical-copilot-shell" class="px-0">
        <div class="px-3 py-2 border-bottom bg-light flex-shrink-0">
            <h4 class="mb-1"><?php echo xlt('Clinical Co-Pilot'); ?></h4>
            <small class="text-muted d-block"><?php echo text($statusText); ?></small>
        </div>

        <div id="clinical-copilot-messages" class="px-2 py-2" role="log" aria-live="polite" aria-relevant="additions">
            <div id="clinical-copilot-intro" class="clinical-copilot-msg clinical-copilot-msg-assistant mb-2">
                <div class="clinical-copilot-bubble text-muted border"><?php echo xlt('Conversation will appear here. Type below and press send.'); ?></div>
            </div>
        </div>

        <div id="clinical-copilot-composer" class="px-3 py-3">
            <div class="form-group mb-2">
                <label for="clinical-copilot-message" class="font-weight-bold sr-only"><?php echo xlt('Message'); ?></label>
                <div class="input-group input-group-lg">
                    <input type="text" class="form-control" id="clinical-copilot-message" name="clinical_copilot_message"
                        maxlength="4000" autocomplete="off"
                        placeholder="<?php echo xla('Message'); ?>"
                        aria-describedby="clinical-copilot-compose-help">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-primary" id="clinical-copilot-send"
                            <?php echo $agentReady ? '' : 'disabled'; ?>
                            title="<?php echo $agentReady ? xla('Send to co-pilot agent') : xla('Configure CLINICAL_COPILOT_AGENT_BASE_URL first'); ?>"
                            aria-label="<?php echo xla('Send message'); ?>">
                            <span class="fa fa-play" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <small id="clinical-copilot-compose-help" class="form-text text-muted"><?php echo xlt('Requires OPENROUTER_API_KEY on the agent. Override model with OPENROUTER_MODEL.'); ?></small>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var chatUrl = <?php echo json_encode($chatUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var csrfToken = <?php echo json_encode($copilotCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var agentReady = <?php echo $agentReady ? 'true' : 'false'; ?>;
            var btn = document.getElementById('clinical-copilot-send');
            var input = document.getElementById('clinical-copilot-message');
            var messagesEl = document.getElementById('clinical-copilot-messages');
            if (!btn || !input || !messagesEl) {
                return;
            }

            function scrollToBottom() {
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            function appendBubble(role, text, isError) {
                var row = document.createElement('div');
                row.className = 'clinical-copilot-msg mb-2 ' + (role === 'user' ? 'clinical-copilot-msg-user' : 'clinical-copilot-msg-assistant');
                var bubble = document.createElement('div');
                bubble.className = 'clinical-copilot-bubble' + (isError ? ' border border-danger text-danger' : '');
                bubble.appendChild(document.createTextNode(text));
                row.appendChild(bubble);
                var meta = document.createElement('div');
                meta.className = 'clinical-copilot-msg-meta ' + (role === 'user' ? 'text-right pr-1' : 'pl-1');
                meta.appendChild(document.createTextNode(
                    role === 'user'
                        ? <?php echo json_encode(xl('You')); ?>
                        : (isError ? <?php echo json_encode(xl('Error')); ?> : <?php echo json_encode(xl('Assistant')); ?>)
                ));
                row.appendChild(meta);
                messagesEl.appendChild(row);
                scrollToBottom();
            }

            function removeIntroIfPresent() {
                var intro = document.getElementById('clinical-copilot-intro');
                if (intro) {
                    intro.remove();
                }
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
                removeIntroIfPresent();
                appendBubble('user', msg, false);
                input.value = '';
                btn.disabled = true;
                var loadingRow = document.createElement('div');
                loadingRow.className = 'clinical-copilot-msg clinical-copilot-msg-assistant mb-2';
                loadingRow.id = 'clinical-copilot-loading-row';
                var loadingBubble = document.createElement('div');
                loadingBubble.className = 'clinical-copilot-bubble text-muted border';
                loadingBubble.appendChild(document.createTextNode(<?php echo json_encode(xl('Waiting for response') . '…'); ?>));
                loadingRow.appendChild(loadingBubble);
                messagesEl.appendChild(loadingRow);
                scrollToBottom();

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
                    var lr = document.getElementById('clinical-copilot-loading-row');
                    if (lr) {
                        lr.remove();
                    }
                    if (res.ok && res.data && typeof res.data.reply === 'string') {
                        appendBubble('assistant', res.data.reply, false);
                    } else {
                        var err = (res.data && res.data.error) ? res.data.error : <?php echo json_encode(xl('Request failed')); ?>;
                        appendBubble('assistant', err, true);
                    }
                }).catch(function () {
                    var lr = document.getElementById('clinical-copilot-loading-row');
                    if (lr) {
                        lr.remove();
                    }
                    appendBubble('assistant', <?php echo json_encode(xl('Network error')); ?>, true);
                }).finally(function () {
                    btn.disabled = false;
                    input.focus();
                });
            });

            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' && !ev.shiftKey) {
                    ev.preventDefault();
                    btn.click();
                }
            });
        })();
    </script>
</body>
</html>
