<?php

/**
 * Schedule / day-list Clinical Co-Pilot (UC1 morning day view, UC6 remainder, UC7 no-show sweep).
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
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotScheduleSlotAuthorizer;

if (!AclMain::aclCheckCore('patients', 'demo') || !AclMain::aclCheckCore('patients', 'appt')) {
    echo xlt('Not authorized');
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$handoff = AgentRuntimeHandoff::fromEnvironment();
$copilotCsrfToken = CsrfUtils::collectCsrfToken($session);
$chatUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/chat.php';
$agentReady = $handoff->isConfigured();

$dateIn = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dateIn);
$scheduleDate = ($dt !== false && $dt->format('Y-m-d') === $dateIn) ? $dateIn : (new \DateTimeImmutable('today'))->format('Y-m-d');

$authorizer = new ClinicalCopilotScheduleSlotAuthorizer();
$slotCount = count($authorizer->authorizedSlotIdsForDate($scheduleDate));

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo text(xl('Day Co-Pilot')); ?></title>
    <style>
        html, body { height: 100%; margin: 0; }
        #ccp-day { height: 100%; display: flex; flex-direction: column; }
        #ccp-day-messages { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
        .ccp-bubble { padding: 0.5rem 0.85rem; margin: 0.35rem 0; white-space: pre-wrap; word-break: break-word; }
        .ccp-msg { max-width: 52rem; margin-left: auto; margin-right: auto; }
        .ccp-msg-user { text-align: right; }
        .ccp-msg-user .ccp-bubble { display: inline-block; text-align: left; background: var(--primary, #007bff); color: #fff; border-radius: 1rem 1rem 0.25rem 1rem; }
        .ccp-msg-assistant .ccp-bubble { display: inline-block; background: var(--light, #e9ecef); border-radius: 1rem 1rem 1rem 0.25rem; }
    </style>
</head>
<body>
<div id="ccp-day" class="px-0">
    <div class="px-3 py-2 border-bottom bg-light">
        <h5 class="mb-2"><?php echo xlt('Schedule day Co-Pilot'); ?></h5>
        <form class="form-inline mb-2" method="get" action="">
            <label class="mr-2 font-weight-bold" for="ccp-day-date"><?php echo xlt('Date'); ?></label>
            <input type="date" name="date" id="ccp-day-date" class="form-control form-control-sm mr-2" value="<?php echo attr($scheduleDate); ?>" />
            <button type="submit" class="btn btn-sm btn-secondary"><?php echo xlt('Apply'); ?></button>
        </form>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="ccp-day-uc" class="font-weight-bold"><?php echo xlt('Use case'); ?></label>
                <select id="ccp-day-uc" class="form-control form-control-sm" <?php echo $agentReady ? '' : 'disabled'; ?>>
                    <option value="UC1"><?php echo xlt('UC1 Full day (~20 slots)'); ?></option>
                    <option value="UC6"><?php echo xlt('UC6 Afternoon remainder'); ?></option>
                    <option value="UC7"><?php echo xlt('UC7 No-show / missed list'); ?></option>
                </select>
            </div>
            <div class="form-group col-md-8 mb-0">
                <small class="text-muted d-block">
                    <?php echo text(xl('Authorized slots for this date') . ': ' . (string) $slotCount); ?>
                </small>
                <small class="text-muted"><?php echo xlt('Wide-and-shallow facts only; no visit-order or staffing advice.'); ?></small>
            </div>
        </div>
    </div>
    <div id="ccp-day-messages" class="px-2 py-2"></div>
    <div class="px-3 py-3 border-top bg-light">
        <div class="input-group">
            <input type="text" class="form-control" id="ccp-day-input" maxlength="4000" autocomplete="off"
                placeholder="<?php echo xla('Ask about this day schedule…'); ?>"
                <?php echo $agentReady ? '' : 'disabled'; ?> />
            <div class="input-group-append">
                <button type="button" class="btn btn-primary" id="ccp-day-send" <?php echo $agentReady ? '' : 'disabled'; ?>><?php echo xlt('Send'); ?></button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var chatUrl = <?php echo json_encode($chatUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var csrfToken = <?php echo json_encode($copilotCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var agentReady = <?php echo $agentReady ? 'true' : 'false'; ?>;
    var scheduleDate = <?php echo json_encode($scheduleDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var sloMs = { UC1: 25000, UC6: 19000, UC7: 19000 };
    var messagesEl = document.getElementById('ccp-day-messages');
    var input = document.getElementById('ccp-day-input');
    var btn = document.getElementById('ccp-day-send');
    var ucSel = document.getElementById('ccp-day-uc');
    function currentUc() { return ucSel ? ucSel.value : 'UC1'; }
    function timeoutMs() { return sloMs[currentUc()] || 23000; }
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
    function sendMessage() {
        if (!agentReady) return;
        var msg = (input.value || '').trim();
        if (!msg) return;
        if (typeof top.restoreSession === 'function') top.restoreSession();
        appendBubble('user', msg, false);
        input.value = '';
        btn.disabled = true;
        var uc = currentUc();
        var loading = document.createElement('div');
        loading.className = 'ccp-msg ccp-msg-assistant mb-2';
        loading.id = 'ccp-day-loading';
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
                schedule_date: scheduleDate
            })
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        }).then(function (res) {
            var lr = document.getElementById('ccp-day-loading');
            if (lr) lr.remove();
            if (res.ok && res.data && typeof res.data.reply === 'string') {
                appendBubble('assistant', res.data.reply, false);
            } else {
                var err = (res.data && res.data.error) ? res.data.error : <?php echo json_encode(xl('Request failed')); ?>;
                appendBubble('assistant', err, true);
            }
        }).catch(function () {
            var lr = document.getElementById('ccp-day-loading');
            if (lr) lr.remove();
            appendBubble('assistant', <?php echo json_encode(xl('Network error or timeout')); ?>, true);
        }).finally(function () {
            clearTimeout(tid);
            btn.disabled = false;
            input.focus();
        });
    }
    if (btn) btn.addEventListener('click', sendMessage);
    if (input) input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); sendMessage(); }
    });
})();
</script>
</body>
</html>
