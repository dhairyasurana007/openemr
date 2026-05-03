<?php

/**
 * Encounter-scoped Clinical Co-Pilot (UC2 pre-visit briefing, UC3 critical flags, UC4 Q&A, UC5 draft).
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
if (!AclMain::aclCheckCore('encounters', 'notes') && !AclMain::aclCheckCore('encounters', 'notes_a')) {
    echo xlt('Not authorized');
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid = trim((string) $session->get('pid'));
$encounterId = trim((string) $session->get('encounter'));
if ($pid === '' || $pid === '0' || $encounterId === '' || $encounterId === '0') {
    echo xlt('Select a patient encounter to use the co-pilot.');
    exit;
}

$handoff = AgentRuntimeHandoff::fromEnvironment();
$copilotCsrfToken = CsrfUtils::collectCsrfToken($session);
$chatUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/chat.php';
$agentReady = $handoff->isConfigured();

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo text(xl('Encounter Co-Pilot')); ?></title>
    <style>
        html, body { height: 100%; margin: 0; }
        #ccp-enc { height: 100%; display: flex; flex-direction: column; max-width: 100%; }
        #ccp-enc-messages { flex: 1 1 auto; min-height: 0; overflow-y: auto; background: var(--white, #fff); }
        #ccp-enc-composer { flex: 0 0 auto; border-top: 1px solid rgba(0, 0, 0, 0.1); background: var(--light, #f8f9fa); }
        .ccp-bubble { padding: 0.5rem 0.85rem; margin: 0.35rem 0; white-space: pre-wrap; word-break: break-word; }
        .ccp-msg { max-width: 52rem; margin-left: auto; margin-right: auto; }
        .ccp-msg-user { text-align: right; }
        .ccp-msg-user .ccp-bubble { display: inline-block; text-align: left; background: var(--primary, #007bff); color: #fff; border-radius: 1rem 1rem 0.25rem 1rem; }
        .ccp-msg-assistant .ccp-bubble { display: inline-block; background: var(--light, #e9ecef); border-radius: 1rem 1rem 1rem 0.25rem; }
    </style>
</head>
<body>
<div id="ccp-enc" class="px-0">
    <div class="px-3 py-2 border-bottom bg-light flex-shrink-0">
        <h5 class="mb-1"><?php echo xlt('Encounter Co-Pilot'); ?></h5>
        <div class="form-row align-items-end">
            <div class="form-group col-md-4 mb-2 mb-md-0">
                <label for="ccp-use-case" class="font-weight-bold"><?php echo xlt('Use case'); ?></label>
                <select id="ccp-use-case" class="form-control form-control-sm" <?php echo $agentReady ? '' : 'disabled'; ?>>
                    <option value="UC2"><?php echo xlt('UC2 Pre-visit briefing'); ?></option>
                    <option value="UC3"><?php echo xlt('UC3 Critical flags'); ?></option>
                    <option value="UC4" selected><?php echo xlt('UC4 In-room Q&A'); ?></option>
                    <option value="UC5"><?php echo xlt('UC5 Patient message draft'); ?></option>
                </select>
            </div>
            <div class="form-group col-md-8 mb-0">
                <small class="text-muted"><?php echo xlt('Informative only; responses are bounded by chart data and configured SLO timeouts.'); ?></small>
            </div>
        </div>
    </div>
    <div id="ccp-enc-messages" class="px-2 py-2" role="log" aria-live="polite"></div>
    <div id="ccp-enc-composer" class="px-3 py-3">
        <div class="input-group">
            <input type="text" class="form-control" id="ccp-enc-input" maxlength="4000" autocomplete="off"
                placeholder="<?php echo xla('Ask a factual chart question or choose a prompt…'); ?>"
                <?php echo $agentReady ? '' : 'disabled'; ?> />
            <div class="input-group-append">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="ccp-prompt-uc2" <?php echo $agentReady ? '' : 'disabled'; ?>><?php echo xlt('Briefing'); ?></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="ccp-prompt-uc3" <?php echo $agentReady ? '' : 'disabled'; ?>><?php echo xlt('Flags'); ?></button>
                <button type="button" class="btn btn-primary" id="ccp-enc-send" <?php echo $agentReady ? '' : 'disabled'; ?>><?php echo xlt('Send'); ?></button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var chatUrl = <?php echo json_encode($chatUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var csrfToken = <?php echo json_encode($copilotCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var agentReady = <?php echo $agentReady ? 'true' : 'false'; ?>;
    var encounterId = <?php echo json_encode($encounterId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var sloMs = { UC2: 9000, UC3: 12000, UC4: 12000, UC5: 14000 };
    var ucSel = document.getElementById('ccp-use-case');
    var input = document.getElementById('ccp-enc-input');
    var btn = document.getElementById('ccp-enc-send');
    var messagesEl = document.getElementById('ccp-enc-messages');
    function currentUc() { return ucSel ? ucSel.value : 'UC4'; }
    function timeoutMs() { return sloMs[currentUc()] || 12000; }
    function scrollToBottom() { messagesEl.scrollTop = messagesEl.scrollHeight; }
    function appendBubble(role, text, isError) {
        var row = document.createElement('div');
        row.className = 'ccp-msg mb-2 ' + (role === 'user' ? 'ccp-msg-user' : 'ccp-msg-assistant');
        var bubble = document.createElement('div');
        bubble.className = 'ccp-bubble' + (isError ? ' border border-danger text-danger' : '');
        bubble.appendChild(document.createTextNode(text));
        row.appendChild(bubble);
        messagesEl.appendChild(row);
        scrollToBottom();
    }
    function sendMessage(text) {
        if (!agentReady) return;
        var msg = (text || '').trim();
        if (!msg) return;
        if (typeof top.restoreSession === 'function') top.restoreSession();
        appendBubble('user', msg, false);
        input.value = '';
        btn.disabled = true;
        var uc = currentUc();
        var loading = document.createElement('div');
        loading.className = 'ccp-msg ccp-msg-assistant mb-2';
        loading.id = 'ccp-enc-loading';
        var lb = document.createElement('div');
        lb.className = 'ccp-bubble text-muted border';
        lb.appendChild(document.createTextNode(<?php echo json_encode(xl('Waiting for response') . '…'); ?>));
        loading.appendChild(lb);
        messagesEl.appendChild(loading);
        scrollToBottom();
        var ctl = new AbortController();
        var tid = setTimeout(function () { ctl.abort(); }, timeoutMs());
        fetch(chatUrl, {
            method: 'POST',
            credentials: 'same-origin',
            signal: ctl.signal,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: msg,
                csrf_token_form: csrfToken,
                use_case: uc,
                encounter_id: encounterId
            })
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        }).then(function (res) {
            var lr = document.getElementById('ccp-enc-loading');
            if (lr) lr.remove();
            if (res.ok && res.data && typeof res.data.reply === 'string') {
                appendBubble('assistant', res.data.reply, false);
            } else {
                var err = (res.data && res.data.error) ? res.data.error : <?php echo json_encode(xl('Request failed')); ?>;
                appendBubble('assistant', err, true);
            }
        }).catch(function () {
            var lr = document.getElementById('ccp-enc-loading');
            if (lr) lr.remove();
            appendBubble('assistant', <?php echo json_encode(xl('Network error or timeout')); ?>, true);
        }).finally(function () {
            clearTimeout(tid);
            btn.disabled = false;
            input.focus();
        });
    }
    if (btn) btn.addEventListener('click', function () { sendMessage(input.value); });
    if (input) input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); btn.click(); }
    });
    var p2 = document.getElementById('ccp-prompt-uc2');
    var p3 = document.getElementById('ccp-prompt-uc3');
    if (p2) p2.addEventListener('click', function () {
        if (ucSel) ucSel.value = 'UC2';
        sendMessage(<?php echo json_encode(xl('Summarize chief complaint, intake, and chart facts for this visit (facts only, no prioritization).')); ?>);
    });
    if (p3) p3.addEventListener('click', function () {
        if (ucSel) ucSel.value = 'UC3';
        sendMessage(<?php echo json_encode(xl('List critical chart flags for this patient relevant to today (allergies, high-risk meds, abnormal vitals/labs) with values only.')); ?>);
    });
})();
</script>
</body>
</html>
