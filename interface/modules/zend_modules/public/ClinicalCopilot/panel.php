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
$extractUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/extract.php';
$multimodalChatUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/multimodal_chat.php';
$loginAppointmentAutosummaryUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/login_appointment_autosummary.php';
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
            min-width: 0;
        }
        #clinical-copilot-messages {
            flex: 1 1 auto;
            min-height: 0;
            min-width: 0;
            overflow-y: auto;
            overflow-x: hidden;
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
            max-width: min(100%, 32rem);
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
            overflow-wrap: anywhere;
            max-width: 100%;
            box-sizing: border-box;
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
            <div class="form-group mb-0 mt-2 d-flex align-items-center">
                <select id="ccp-doc-type" class="form-control form-control-sm mr-2" style="max-width: 150px;"
                    aria-label="<?php echo xla('Document type'); ?>"
                    <?php echo $agentReady ? '' : 'disabled'; ?>>
                    <option value="lab_pdf"><?php echo xlt('Lab PDF'); ?></option>
                    <option value="intake_form"><?php echo xlt('Intake Form'); ?></option>
                </select>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="ccp-upload-btn"
                    <?php echo $agentReady ? '' : 'disabled'; ?>
                    title="<?php echo $agentReady ? xla('Upload a lab PDF or intake form image for extraction') : xla('Configure CLINICAL_COPILOT_AGENT_BASE_URL first'); ?>"
                    aria-label="<?php echo xla('Upload document for extraction'); ?>">
                    <span class="fa fa-upload mr-1" aria-hidden="true"></span><?php echo xlt('Upload Document'); ?>
                </button>
                <input type="file" id="ccp-file-input" accept=".pdf,image/*" class="d-none"
                    aria-label="<?php echo xla('Select file to extract'); ?>">
            </div>
        </div>
    </div>
    <script>
        (function () {
            var chatUrl = <?php echo json_encode($chatUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var extractUrl = <?php echo json_encode($extractUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var multimodalChatUrl = <?php echo json_encode($multimodalChatUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var loginAppointmentAutosummaryUrl = <?php echo json_encode($loginAppointmentAutosummaryUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var csrfToken = <?php echo json_encode($copilotCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var agentReady = <?php echo $agentReady ? 'true' : 'false'; ?>;
            var extractedFacts = null;
            var btn = document.getElementById('clinical-copilot-send');
            var input = document.getElementById('clinical-copilot-message');
            var messagesEl = document.getElementById('clinical-copilot-messages');
            var uploadBtn = document.getElementById('ccp-upload-btn');
            var fileInput = document.getElementById('ccp-file-input');
            var docTypeSelect = document.getElementById('ccp-doc-type');
            if (!btn || !input || !messagesEl) {
                return;
            }

            function scrollToBottom() {
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            function appendBubble(role, text, isError, metaLabelOverride) {
                var row = document.createElement('div');
                row.className = 'clinical-copilot-msg mb-2 ' + (role === 'user' ? 'clinical-copilot-msg-user' : 'clinical-copilot-msg-assistant');
                var bubble = document.createElement('div');
                bubble.className = 'clinical-copilot-bubble' + (isError ? ' border border-danger text-danger' : '');
                bubble.appendChild(document.createTextNode(text));
                row.appendChild(bubble);
                var meta = document.createElement('div');
                meta.className = 'clinical-copilot-msg-meta ' + (role === 'user' ? 'text-right pr-1' : 'pl-1');
                var metaLabel = metaLabelOverride;
                if (!metaLabel) {
                    metaLabel = role === 'user'
                        ? <?php echo json_encode(xl('You')); ?>
                        : (isError ? <?php echo json_encode(xl('Error')); ?> : <?php echo json_encode(xl('Assistant')); ?>);
                }
                meta.appendChild(document.createTextNode(metaLabel));
                row.appendChild(meta);
                messagesEl.appendChild(row);
                scrollToBottom();
            }

            function startLiveStatus(rowId, phases, tickMs) {
                var phaseIndex = 0;
                var timer = null;
                function renderPhase() {
                    var row = document.getElementById(rowId);
                    if (!row) {
                        return;
                    }
                    var bubble = row.querySelector('.clinical-copilot-bubble');
                    if (!bubble) {
                        return;
                    }
                    bubble.textContent = phases[phaseIndex];
                    phaseIndex = (phaseIndex + 1) % phases.length;
                }
                renderPhase();
                timer = window.setInterval(renderPhase, tickMs);
                return function stopLiveStatus() {
                    if (timer !== null) {
                        window.clearInterval(timer);
                    }
                };
            }

            function normalizeCitationValue(value) {
                if (typeof value === 'string') {
                    return value.trim();
                }
                return '';
            }

            function formatCitations(citations) {
                if (!Array.isArray(citations) || citations.length === 0) {
                    return '';
                }

                var lines = [<?php echo json_encode(xl('Sources') . ':'); ?>];
                for (var i = 0; i < citations.length; i++) {
                    var citation = citations[i];
                    if (!citation || typeof citation !== 'object') {
                        continue;
                    }
                    var sourceType = normalizeCitationValue(citation.source_type) || 'source';
                    var sourceId = normalizeCitationValue(citation.source_id) || '?';
                    var pageOrSection = normalizeCitationValue(citation.page_or_section);
                    var fieldId = normalizeCitationValue(citation.field_or_chunk_id);
                    var quote = normalizeCitationValue(citation.quote_or_value);
                    var description = normalizeCitationValue(citation.description);
                    var url = normalizeCitationValue(citation.url);

                    var parts = [sourceType + ' ' + sourceId];
                    if (description) {
                        parts.push(description);
                    }
                    if (pageOrSection) {
                        parts.push(pageOrSection);
                    }
                    if (fieldId) {
                        parts.push(fieldId);
                    }
                    if (url) {
                        parts.push(url);
                    }
                    if (quote) {
                        parts.push('"' + quote + '"');
                    }

                    lines.push('- ' + parts.join(' | '));
                }

                if (lines.length === 1) {
                    return '';
                }
                return lines.join('\n');
            }

            function formatActivityTrace(data, useMultimodal) {
                var lines = [<?php echo json_encode(xl('Activity') . ':'); ?>];
                var hasDetail = false;

                if (useMultimodal && Array.isArray(data.routing_log)) {
                    for (var i = 0; i < data.routing_log.length; i++) {
                        var step = data.routing_log[i];
                        if (!step || typeof step !== 'object') {
                            continue;
                        }
                        var node = normalizeCitationValue(step.node) || 'step';
                        var decision = normalizeCitationValue(step.decision);
                        var reason = normalizeCitationValue(step.reason);
                        var summary = node;
                        if (decision) {
                            summary += ' | ' + decision;
                        }
                        if (reason) {
                            summary += ' | ' + reason;
                        }
                        lines.push('- ' + summary);
                        hasDetail = true;
                    }
                }

                if (!useMultimodal && Array.isArray(data.tools_used)) {
                    for (var j = 0; j < data.tools_used.length; j++) {
                        var tool = data.tools_used[j];
                        if (!tool || typeof tool !== 'object') {
                            continue;
                        }
                        var name = normalizeCitationValue(tool.name) || 'tool';
                        var status = normalizeCitationValue(tool.status) || 'ok';
                        lines.push('- ' + name + ' | ' + status);
                        hasDetail = true;
                    }
                }

                if (useMultimodal && Array.isArray(data.citations)) {
                    var seenSources = {};
                    for (var k = 0; k < data.citations.length; k++) {
                        var citation = data.citations[k];
                        if (!citation || typeof citation !== 'object') {
                            continue;
                        }
                        var sourceType = normalizeCitationValue(citation.source_type);
                        var sourceId = normalizeCitationValue(citation.source_id);
                        var url = normalizeCitationValue(citation.url);
                        if (sourceType !== 'guideline') {
                            continue;
                        }
                        var sourceKey = sourceId + '|' + url;
                        if (seenSources[sourceKey]) {
                            continue;
                        }
                        seenSources[sourceKey] = true;
                        var sourceLine = sourceId || <?php echo json_encode(xl('Guideline source')); ?>;
                        if (url) {
                            sourceLine += ' | ' + url;
                        }
                        lines.push('- ' + <?php echo json_encode(xl('Searched')); ?> + ' | ' + sourceLine);
                        hasDetail = true;
                    }
                }

                if (!hasDetail) {
                    return '';
                }
                return lines.join('\n');
            }

            function removeIntroIfPresent() {
                var intro = document.getElementById('clinical-copilot-intro');
                if (intro) {
                    intro.remove();
                }
            }

            if (uploadBtn && fileInput && docTypeSelect) {
                uploadBtn.addEventListener('click', function () {
                    if (!agentReady) {
                        return;
                    }
                    fileInput.click();
                });

                fileInput.addEventListener('change', function () {
                    var file = fileInput.files && fileInput.files[0];
                    if (!file) {
                        return;
                    }
                    if (typeof top.restoreSession === 'function') {
                        top.restoreSession();
                    }
                    removeIntroIfPresent();

                    var loadingRow = document.createElement('div');
                    loadingRow.className = 'clinical-copilot-msg clinical-copilot-msg-assistant mb-2';
                    loadingRow.id = 'ccp-extract-loading-row';
                    var loadingBubble = document.createElement('div');
                    loadingBubble.className = 'clinical-copilot-bubble text-muted border';
                    loadingBubble.appendChild(document.createTextNode(''));
                    loadingRow.appendChild(loadingBubble);
                    messagesEl.appendChild(loadingRow);
                    scrollToBottom();
                    var stopExtractStatus = startLiveStatus(
                        'ccp-extract-loading-row',
                        [
                            <?php echo json_encode(xl('Reading file') . '…'); ?>,
                            <?php echo json_encode(xl('Extracting fields') . '…'); ?>,
                            <?php echo json_encode(xl('Preparing structured output') . '…'); ?>,
                        ],
                        900
                    );

                    uploadBtn.disabled = true;

                    var formData = new FormData();
                    formData.append('file', file);
                    formData.append('doc_type', docTypeSelect.value);
                    formData.append('csrf_token_form', csrfToken);

                    fetch(extractUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    }).then(function (r) {
                        return r.json().then(function (data) {
                            return {ok: r.ok, status: r.status, data: data};
                        });
                    }).then(function (res) {
                        stopExtractStatus();
                        var lr = document.getElementById('ccp-extract-loading-row');
                        if (lr) {
                            lr.remove();
                        }
                        if (res.ok && res.data && res.data.extracted) {
                            extractedFacts = res.data.extracted;
                            appendBubble('assistant', JSON.stringify(res.data.extracted, null, 2), false, <?php echo json_encode(xl('Extraction result')); ?>);
                        } else {
                            var err = (res.data && res.data.error) ? res.data.error : <?php echo json_encode(xl('Extraction failed')); ?>;
                            appendBubble('assistant', err, true);
                        }
                    }).catch(function () {
                        stopExtractStatus();
                        var lr = document.getElementById('ccp-extract-loading-row');
                        if (lr) {
                            lr.remove();
                        }
                        appendBubble('assistant', <?php echo json_encode(xl('Network error during extraction')); ?>, true);
                    }).finally(function () {
                        uploadBtn.disabled = !agentReady;
                        fileInput.value = '';
                    });
                });
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
                loadingBubble.appendChild(document.createTextNode(''));
                loadingRow.appendChild(loadingBubble);
                messagesEl.appendChild(loadingRow);
                scrollToBottom();
                var stopChatStatus = startLiveStatus(
                    'clinical-copilot-loading-row',
                    [
                        <?php echo json_encode(xl('Thinking') . '…'); ?>,
                        <?php echo json_encode(xl('Reviewing context') . '…'); ?>,
                        <?php echo json_encode(xl('Composing response') . '…'); ?>,
                    ],
                    900
                );

                var useMultimodal = extractedFacts !== null;
                var targetUrl = useMultimodal ? multimodalChatUrl : chatUrl;
                var requestBody = {message: msg, csrf_token_form: csrfToken};
                if (useMultimodal) {
                    requestBody.extracted_facts = extractedFacts;
                    requestBody.use_rag = true;
                }

                fetch(targetUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(requestBody)
                }).then(function (r) {
                    return r.json().then(function (data) {
                        return {ok: r.ok, status: r.status, data: data};
                    });
                }).then(function (res) {
                    stopChatStatus();
                    var lr = document.getElementById('clinical-copilot-loading-row');
                    if (lr) {
                        lr.remove();
                    }
                    if (res.ok && res.data && typeof res.data.reply === 'string') {
                        appendBubble('assistant', res.data.reply, false);
                        var activityText = formatActivityTrace(res.data, useMultimodal);
                        if (activityText) {
                            appendBubble('assistant', activityText, false, <?php echo json_encode(xl('Trace')); ?>);
                        }
                        var citationsText = formatCitations(res.data.citations);
                        if (citationsText) {
                            appendBubble('assistant', citationsText, false, <?php echo json_encode(xl('Citations')); ?>);
                        }
                    } else {
                        var err = (res.data && res.data.error) ? res.data.error : <?php echo json_encode(xl('Request failed')); ?>;
                        appendBubble('assistant', err, true);
                    }
                }).catch(function () {
                    stopChatStatus();
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

            if (agentReady) {
                var loadRow = document.createElement('div');
                loadRow.className = 'clinical-copilot-msg clinical-copilot-msg-assistant mb-2';
                loadRow.id = 'clinical-copilot-login-auto-loading';
                var loadBubble = document.createElement('div');
                loadBubble.className = 'clinical-copilot-bubble text-muted border';
                loadBubble.appendChild(document.createTextNode(<?php echo json_encode(xl('Checking for a current or imminent visit') . '…'); ?>));
                loadRow.appendChild(loadBubble);
                messagesEl.appendChild(loadRow);
                scrollToBottom();
                fetch(loginAppointmentAutosummaryUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({csrf_token_form: csrfToken})
                }).then(function (r) {
                    return r.json().then(function (data) {
                        return {ok: r.ok, data: data};
                    });
                }).then(function (res) {
                    var lr = document.getElementById('clinical-copilot-login-auto-loading');
                    if (lr) {
                        lr.remove();
                    }
                    if (!res.ok || !res.data || !res.data.ok) {
                        return;
                    }
                    if (res.data.ran === true && typeof res.data.reply === 'string' && res.data.reply.trim() !== '') {
                        removeIntroIfPresent();
                        var hdr = <?php echo json_encode(xl('Automatic summary (visit now or starting within 5 minutes)') . ':'); ?>;
                        appendBubble('assistant', hdr + '\n\n' + res.data.reply, false, <?php echo json_encode(xl('Visit summary')); ?>);
                    }
                }).catch(function () {
                    var lr2 = document.getElementById('clinical-copilot-login-auto-loading');
                    if (lr2) {
                        lr2.remove();
                    }
                });
            }
        })();
    </script>
</body>
</html>
