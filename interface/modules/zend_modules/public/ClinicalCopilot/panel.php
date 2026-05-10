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
$docxToPdfUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/docx_to_pdf.php';
$saveExtractedUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/save_extracted.php';
$multimodalChatUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/multimodal_chat.php';
$loginAppointmentAutosummaryUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/login_appointment_autosummary.php';
$agentReady = $handoff->isConfigured();
$citationOverlayCssUrl = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/assets/citation_overlay.css';
$citationOverlayJsUrl  = $web_root . '/interface/modules/zend_modules/public/ClinicalCopilot/assets/citation_overlay.js';

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
        .clinical-copilot-bubble h1,
        .clinical-copilot-bubble h2,
        .clinical-copilot-bubble h3 {
            margin: 0.25rem 0 0.35rem 0;
            line-height: 1.2;
        }
        .clinical-copilot-bubble h1 {
            font-size: 1.35rem;
        }
        .clinical-copilot-bubble h2 {
            font-size: 1.2rem;
        }
        .clinical-copilot-bubble h3 {
            font-size: 1.05rem;
        }
        .clinical-copilot-bubble p,
        .clinical-copilot-bubble ul {
            margin: 0 0 0.35rem 0;
        }
        .clinical-copilot-bubble ul {
            padding-left: 1.1rem;
        }
        .clinical-copilot-bubble code {
            background: rgba(0, 0, 0, 0.08);
            border-radius: 0.2rem;
            padding: 0.05rem 0.25rem;
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.9em;
        }
        .clinical-copilot-msg-meta {
            font-size: 0.75rem;
            color: var(--gray, #6c757d);
            margin-top: 0.15rem;
        }
        .clinical-copilot-loading {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .clinical-copilot-spinner {
            width: 0.95rem;
            height: 0.95rem;
            border: 2px solid rgba(0, 0, 0, 0.2);
            border-top-color: rgba(0, 0, 0, 0.65);
            border-radius: 50%;
            animation: clinical-copilot-spin 0.8s linear infinite;
            flex: 0 0 auto;
        }
        @keyframes clinical-copilot-spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
    </style>
    <link rel="stylesheet" href="<?php echo text($citationOverlayCssUrl); ?>">
    <!-- PDF.js 3.11.174 — vendor under public/assets/vendor/pdfjs/ and add SRI hash before production deploy -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
</head>
<body>
    <div id="clinical-copilot-shell" class="px-0">
        <div class="px-3 py-2 border-bottom bg-light flex-shrink-0">
            <h4 class="mb-1"><?php echo xlt('Clinical Co-Pilot'); ?></h4>
        </div>

        <div id="clinical-copilot-messages" class="px-2 py-2" role="log" aria-live="polite" aria-relevant="additions">
            <div id="clinical-copilot-intro" class="clinical-copilot-msg clinical-copilot-msg-assistant mb-2">
                <div class="clinical-copilot-bubble text-muted border"><?php echo xlt('Conversation will appear here. Type below and press send.'); ?></div>
            </div>
        </div>

        <div id="ccp-pdf-overlay-panel" class="d-none flex-shrink-0" style="max-height:420px;overflow-y:auto;border-top:1px solid rgba(0,0,0,.12);background:#fff;padding:8px 10px;">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted font-weight-bold"><?php echo xlt('PDF Source'); ?></small>
                <button type="button" id="ccp-pdf-overlay-close" class="btn btn-link btn-sm p-0" style="font-size:1.25rem;line-height:1;color:#6c757d;" aria-label="<?php echo xla('Close PDF preview'); ?>">&#215;</button>
            </div>
            <canvas id="ccp-pdf-canvas" style="max-width:100%;display:block;"></canvas>
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
            </div>
            <div class="form-group mb-0 mt-2 d-flex align-items-center flex-wrap gap-2">
                <div class="dropdown">
                    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" id="ccp-upload-btn"
                        data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
                        <?php echo $agentReady ? '' : 'disabled'; ?>
                        title="<?php echo $agentReady ? xla('Upload a lab or intake form document for extraction') : xla('Configure CLINICAL_COPILOT_AGENT_BASE_URL first'); ?>"
                        aria-label="<?php echo xla('Upload document for extraction'); ?>">
                        <span class="fa fa-upload mr-1" aria-hidden="true"></span><?php echo xlt('Upload Document'); ?>
                    </button>
                    <div class="dropdown-menu" aria-labelledby="ccp-upload-btn">
                        <button type="button" class="dropdown-item" id="ccp-upload-lab"><?php echo xlt('Lab'); ?></button>
                        <button type="button" class="dropdown-item" id="ccp-upload-intake"><?php echo xlt('Intake Form'); ?></button>
                    </div>
                </div>
                <input type="file" id="ccp-file-input" accept=".pdf,.tif,.tiff,.png,.jpg,.jpeg,.docx,.xlsx,.hl7,.txt" class="d-none"
                    aria-label="<?php echo xla('Select file to extract'); ?>">
            </div>
        </div>
    </div>
    <script>
        (function () {
            var chatUrl = <?php echo json_encode($chatUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var extractUrl = <?php echo json_encode($extractUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var docxToPdfUrl = <?php echo json_encode($docxToPdfUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var saveExtractedUrl = <?php echo json_encode($saveExtractedUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var multimodalChatUrl = <?php echo json_encode($multimodalChatUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var loginAppointmentAutosummaryUrl = <?php echo json_encode($loginAppointmentAutosummaryUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var csrfToken = <?php echo json_encode($copilotCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var agentReady = <?php echo $agentReady ? 'true' : 'false'; ?>;
            var extractedFacts = null;
            var btn = document.getElementById('clinical-copilot-send');
            var input = document.getElementById('clinical-copilot-message');
            var messagesEl = document.getElementById('clinical-copilot-messages');
            var uploadBtn = document.getElementById('ccp-upload-btn');
            var uploadLabBtn = document.getElementById('ccp-upload-lab');
            var uploadIntakeBtn = document.getElementById('ccp-upload-intake');
            var fileInput = document.getElementById('ccp-file-input');
            var selectedUploadDocType = 'intake_form';
            var pendingExtractConfirmation = false;
            var pendingExtractDocType = '';
            var pendingExtractFileName = '';
            var pendingIdentityCollection = false;
            var pendingIdentityMissingFields = [];
            var pdfBlobMap = {};
            var currentExtractPreviewBlobUrl = null;
            var pdfOverlayPanel = document.getElementById('ccp-pdf-overlay-panel');
            var pdfOverlayClose = document.getElementById('ccp-pdf-overlay-close');
            if (!btn || !input || !messagesEl) {
                return;
            }
            if (pdfOverlayClose) {
                pdfOverlayClose.addEventListener('click', function () {
                    if (pdfOverlayPanel) {
                        pdfOverlayPanel.classList.add('d-none');
                    }
                });
            }

            function scrollToBottom() {
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            function appendBubble(role, text, isError, metaLabelOverride) {
                var row = document.createElement('div');
                row.className = 'clinical-copilot-msg mb-2 ' + (role === 'user' ? 'clinical-copilot-msg-user' : 'clinical-copilot-msg-assistant');
                var bubble = document.createElement('div');
                bubble.className = 'clinical-copilot-bubble' + (isError ? ' border border-danger text-danger' : '');
                bubble.innerHTML = renderMarkdown(text);
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

            function escapeHtml(raw) {
                return String(raw)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function renderInlineMarkdown(escapedText) {
                return escapedText
                    .replace(/`([^`]+)`/g, '<code>$1</code>')
                    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            }

            function renderMarkdown(rawText) {
                var text = typeof rawText === 'string' ? rawText : '';
                if (!text) {
                    return '';
                }
                var lines = text.split(/\r?\n/);
                var html = [];
                var listOpen = false;

                function closeList() {
                    if (listOpen) {
                        html.push('</ul>');
                        listOpen = false;
                    }
                }

                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i];
                    var heading = /^(#{1,3})\s+(.*)$/.exec(line);
                    var bullet = /^-\s+(.*)$/.exec(line);
                    if (heading) {
                        closeList();
                        var level = heading[1].length;
                        var headingText = renderInlineMarkdown(escapeHtml(heading[2]));
                        html.push('<h' + level + '>' + headingText + '</h' + level + '>');
                        continue;
                    }
                    if (bullet) {
                        if (!listOpen) {
                            html.push('<ul>');
                            listOpen = true;
                        }
                        html.push('<li>' + renderInlineMarkdown(escapeHtml(bullet[1])) + '</li>');
                        continue;
                    }
                    closeList();
                    if (line.trim() === '') {
                        html.push('<br>');
                        continue;
                    }
                    html.push('<p>' + renderInlineMarkdown(escapeHtml(line)) + '</p>');
                }
                closeList();
                return html.join('');
            }

            function startLiveStatus(rowId, phases, tickMs) {
                var phaseIndex = 0;
                var timer = null;
                var startedAtMs = Date.now();
                function renderPhase() {
                    var row = document.getElementById(rowId);
                    if (!row) {
                        return;
                    }
                    var bubble = row.querySelector('.clinical-copilot-bubble');
                    if (!bubble) {
                        return;
                    }
                    var elapsedSeconds = Math.max(1, Math.floor((Date.now() - startedAtMs) / 1000));
                    var phaseText = phases[phaseIndex] || '';
                    var spinnerHtml = '<span class="clinical-copilot-spinner" aria-hidden="true"></span>';
                    if (phaseIndex < (phases.length - 1)) {
                        bubble.innerHTML = '<span class="clinical-copilot-loading">' + spinnerHtml
                            + '<span>' + escapeHtml(phaseText + ' (' + elapsedSeconds + 's)') + '</span></span>';
                        phaseIndex++;
                    } else {
                        bubble.innerHTML = '<span class="clinical-copilot-loading">' + spinnerHtml
                            + '<span>' + escapeHtml(phaseText + ' (' + elapsedSeconds + 's)') + '</span></span>';
                    }
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

            function sourceIdVariants(sourceId) {
                var raw = normalizeCitationValue(sourceId);
                if (!raw) {
                    return [];
                }
                var variants = {};
                variants[raw] = true;
                variants[raw.toLowerCase()] = true;
                // Also match common URL/path style IDs that differ only by basename.
                var parts = raw.split(/[\\/]/);
                var baseName = parts.length > 0 ? parts[parts.length - 1] : '';
                if (baseName) {
                    variants[baseName] = true;
                    variants[baseName.toLowerCase()] = true;
                }
                return Object.keys(variants);
            }

            function registerSourceBlob(sourceId, blobUrl) {
                if (!blobUrl) {
                    return;
                }
                var keys = sourceIdVariants(sourceId);
                if (keys.length === 0) {
                    return;
                }
                for (var i = 0; i < keys.length; i++) {
                    pdfBlobMap[keys[i]] = blobUrl;
                }
            }

            function getBlobUrlForCitationSource(sourceId) {
                var keys = sourceIdVariants(sourceId);
                for (var i = 0; i < keys.length; i++) {
                    if (pdfBlobMap[keys[i]]) {
                        return pdfBlobMap[keys[i]];
                    }
                }
                // Fallback for non-PDF originals where extractor source IDs do not round-trip.
                return currentExtractPreviewBlobUrl;
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

            function appendCitationRow(citations) {
                if (!Array.isArray(citations) || citations.length === 0) {
                    return;
                }
                var bboxStatsBySourcePage = {};
                for (var ci = 0; ci < citations.length; ci++) {
                    var scanCit = citations[ci];
                    if (!scanCit || typeof scanCit !== 'object') {
                        continue;
                    }
                    if (!Array.isArray(scanCit.bbox) || scanCit.bbox.length < 4) {
                        continue;
                    }
                    var scanSid = normalizeCitationValue(scanCit.source_id) || 'unknown';
                    var scanPage = (typeof scanCit.page_number === 'number' && scanCit.page_number >= 1) ? scanCit.page_number : 1;
                    var scanKey = scanSid + '|' + String(scanPage);
                    var sx0 = Number(scanCit.bbox[0]);
                    var sy0 = Number(scanCit.bbox[1]);
                    var sx1 = Number(scanCit.bbox[2]);
                    var sy1 = Number(scanCit.bbox[3]);
                    if (!isFinite(sx0) || !isFinite(sy0) || !isFinite(sx1) || !isFinite(sy1)) {
                        continue;
                    }
                    var maxX = Math.max(sx0, sx1);
                    var maxY = Math.max(sy0, sy1);
                    if (!bboxStatsBySourcePage[scanKey]) {
                        bboxStatsBySourcePage[scanKey] = { maxX: maxX, maxY: maxY };
                    } else {
                        bboxStatsBySourcePage[scanKey].maxX = Math.max(bboxStatsBySourcePage[scanKey].maxX, maxX);
                        bboxStatsBySourcePage[scanKey].maxY = Math.max(bboxStatsBySourcePage[scanKey].maxY, maxY);
                    }
                }

                var row = document.createElement('div');
                row.className = 'clinical-copilot-msg mb-2 clinical-copilot-msg-assistant';
                var bubble = document.createElement('div');
                bubble.className = 'clinical-copilot-bubble';

                var labelEl = document.createElement('div');
                labelEl.style.cssText = 'font-size:0.8rem;color:#6c757d;margin-bottom:4px;';
                labelEl.textContent = <?php echo json_encode(xl('Sources') . ':'); ?>;
                bubble.appendChild(labelEl);

                for (var i = 0; i < citations.length; i++) {
                    var cit = citations[i];
                    if (!cit || typeof cit !== 'object') {
                        continue;
                    }
                    var badge = document.createElement('span');
                    badge.className = 'citation-badge';

                    var badgeLabel = normalizeCitationValue(cit.badge_label) || normalizeCitationValue(cit.field_or_chunk_id);
                    var sType = normalizeCitationValue(cit.source_type) || 'source';
                    var pageLabel = normalizeCitationValue(cit.page_or_section);
                    var displayName = badgeLabel || sType;
                    badge.textContent = displayName + (pageLabel ? ' - ' + pageLabel : '');

                    var quoteText = normalizeCitationValue(cit.quote_or_value);
                    badge.title = quoteText || displayName;

                    var hasBbox = Array.isArray(cit.bbox) && cit.bbox.length >= 4;
                    var hasPage = (typeof cit.page_number === 'number' && cit.page_number >= 1);
                    var sid = normalizeCitationValue(cit.source_id);
                    var blobUrl = getBlobUrlForCitationSource(sid);
                    var statsKey = (sid || 'unknown') + '|' + String(hasPage ? cit.page_number : 1);
                    var pageStats = bboxStatsBySourcePage[statsKey] || null;

                    if (hasBbox && hasPage && blobUrl) {
                        var coordMeta = (cit.pdf_coordinates && typeof cit.pdf_coordinates === 'object') ? cit.pdf_coordinates : null;
                        badge.setAttribute('data-has-bbox', 'true');
                        badge.title = (badge.title ? badge.title + ' ' : '') + <?php echo json_encode('(' . xl('click to view in PDF') . ')'); ?>;
                        (function (url, pn, bx, stats, meta, quote, fieldId) {
                            badge.addEventListener('click', function () {
                                if (window.ClinicalCopilotCitationOverlay) {
                                    window.ClinicalCopilotCitationOverlay.renderBboxOverlay(url, pn, bx, stats, meta, quote, fieldId);
                                }
                            });
                        })(blobUrl, cit.page_number, cit.bbox, pageStats, coordMeta, normalizeCitationValue(cit.quote_or_value), normalizeCitationValue(cit.field_or_chunk_id));
                    }
                    bubble.appendChild(badge);
                }

                var meta = document.createElement('div');
                meta.className = 'clinical-copilot-msg-meta pl-1';
                meta.textContent = <?php echo json_encode(xl('Citations')); ?>;
                row.appendChild(bubble);
                row.appendChild(meta);
                messagesEl.appendChild(row);
                scrollToBottom();
            }

            function toHumanFieldLabel(value) {
                var textValue = String(value || '').trim();
                if (!textValue) {
                    return '';
                }
                textValue = textValue.replace(/([a-z0-9])([A-Z])/g, '$1 $2');
                textValue = textValue.replace(/[._-]+/g, ' ');
                textValue = textValue.replace(/\s+/g, ' ').trim();
                return textValue ? (textValue.charAt(0).toUpperCase() + textValue.slice(1)) : '';
            }

            function inferCitationBadgeLabel(result, citation, index) {
                if (!result || typeof result !== 'object') {
                    return <?php echo json_encode(xl('Field')); ?> + ' ' + String(index + 1);
                }

                var scalarFields = [];
                Object.keys(result).forEach(function (key) {
                    if (key === 'citation') {
                        return;
                    }
                    var value = result[key];
                    if (value === null || typeof value === 'object') {
                        return;
                    }
                    var textValue = String(value).trim();
                    if (textValue === '') {
                        return;
                    }
                    scalarFields.push({ key: key, value: textValue });
                });

                var labelCandidates = [
                    result.field_label,
                    result.field_name,
                    result.field,
                    result.label,
                    result.name,
                    result.key,
                    citation && citation.field_or_chunk_id
                ];
                for (var i = 0; i < labelCandidates.length; i++) {
                    var label = toHumanFieldLabel(labelCandidates[i]);
                    if (label) {
                        return label;
                    }
                }

                var quotedValue = normalizeCitationValue(citation && citation.quote_or_value).toLowerCase();
                if (quotedValue) {
                    for (var q = 0; q < scalarFields.length; q++) {
                        if (scalarFields[q].value.toLowerCase() === quotedValue) {
                            var quotedKeyLabel = toHumanFieldLabel(scalarFields[q].key);
                            if (quotedKeyLabel) {
                                return quotedKeyLabel;
                            }
                        }
                    }
                }

                var ignoredKeys = {
                    citation: true,
                    source: true,
                    source_type: true,
                    source_id: true,
                    page_number: true,
                    page_or_section: true,
                    bbox: true
                };
                var keys = Object.keys(result);
                for (var j = 0; j < keys.length; j++) {
                    var key = keys[j];
                    if (ignoredKeys[key]) {
                        continue;
                    }
                    var value = result[key];
                    if (value === null || typeof value === 'object') {
                        continue;
                    }
                    if (String(value).trim() === '') {
                        continue;
                    }
                    var keyLabel = toHumanFieldLabel(key);
                    if (keyLabel) {
                        return keyLabel;
                    }
                }

                if (scalarFields.length === 1) {
                    var singleFieldLabel = toHumanFieldLabel(scalarFields[0].key);
                    if (singleFieldLabel) {
                        return singleFieldLabel;
                    }
                }

                return <?php echo json_encode(xl('Field')); ?> + ' ' + String(index + 1);
            }
            function collectCitationsFromExtracted(extracted) {
                var citations = [];
                if (!extracted || typeof extracted !== 'object') {
                    return citations;
                }
                if (extracted.citation && typeof extracted.citation === 'object') {
                    var topCitation = Object.assign({}, extracted.citation);
                    topCitation.badge_label = toHumanFieldLabel(topCitation.field_or_chunk_id)
                        || <?php echo json_encode(xl('Document summary')); ?>;
                    if (extracted.pdf_coordinates && typeof extracted.pdf_coordinates === 'object') {
                        topCitation.pdf_coordinates = extracted.pdf_coordinates;
                    }
                    citations.push(topCitation);
                }
                if (Array.isArray(extracted.results)) {
                    for (var i = 0; i < extracted.results.length; i++) {
                        var result = extracted.results[i];
                        if (!result || typeof result !== 'object') {
                            continue;
                        }
                        if (result.citation && typeof result.citation === 'object') {
                            var citationCopy = Object.assign({}, result.citation);
                            citationCopy.badge_label = inferCitationBadgeLabel(result, result.citation, i);
                            if (result.pdf_coordinates && typeof result.pdf_coordinates === 'object') {
                                citationCopy.pdf_coordinates = result.pdf_coordinates;
                            }
                            citations.push(citationCopy);
                        }
                    }
                }
                return citations;
            }

            function enrichExtractedWithPdfCoordinates(extracted) {
                if (!extracted || typeof extracted !== 'object') {
                    return extracted;
                }

                function buildCoordinates(citation) {
                    if (!citation || typeof citation !== 'object') {
                        return null;
                    }
                    var bbox = Array.isArray(citation.bbox) ? citation.bbox : null;
                    var hasValidBbox = bbox && bbox.length >= 4
                        && isFinite(Number(bbox[0])) && isFinite(Number(bbox[1]))
                        && isFinite(Number(bbox[2])) && isFinite(Number(bbox[3]));
                    var hasPage = (typeof citation.page_number === 'number' && citation.page_number >= 1);
                    if (!hasValidBbox && !hasPage) {
                        return null;
                    }
                    var coords = {
                        page_number: hasPage ? citation.page_number : null,
                        bbox: hasValidBbox ? [
                            Number(bbox[0]),
                            Number(bbox[1]),
                            Number(bbox[2]),
                            Number(bbox[3])
                        ] : null,
                        source_id: normalizeCitationValue(citation.source_id) || null,
                        page_or_section: normalizeCitationValue(citation.page_or_section) || null
                    };
                    if (typeof citation.coordinate_space === 'string' && citation.coordinate_space.trim() !== '') {
                        coords.coordinate_space = citation.coordinate_space.trim();
                    }
                    if (typeof citation.coordinate_origin === 'string' && citation.coordinate_origin.trim() !== '') {
                        coords.coordinate_origin = citation.coordinate_origin.trim();
                    }
                    var numericMetaFields = [
                        'source_image_width',
                        'source_image_height',
                        'crop_origin_x',
                        'crop_origin_y',
                        'crop_width_pts',
                        'crop_height_pts',
                        'rotation_degrees'
                    ];
                    for (var m = 0; m < numericMetaFields.length; m++) {
                        var metaKey = numericMetaFields[m];
                        var metaValue = Number(citation[metaKey]);
                        if (isFinite(metaValue)) {
                            coords[metaKey] = metaValue;
                        }
                    }
                    return coords;
                }

                if (Array.isArray(extracted.results)) {
                    for (var i = 0; i < extracted.results.length; i++) {
                        var result = extracted.results[i];
                        if (!result || typeof result !== 'object') {
                            continue;
                        }
                        var resultCoords = buildCoordinates(result.citation);
                        if (resultCoords) {
                            result.pdf_coordinates = resultCoords;
                        }
                    }
                }

                var topCoords = buildCoordinates(extracted.citation);
                if (topCoords) {
                    extracted.pdf_coordinates = topCoords;
                }

                return extracted;
            }

            function removeIntroIfPresent() {
                var intro = document.getElementById('clinical-copilot-intro');
                if (intro) {
                    intro.remove();
                }
            }

            function flattenObject(input, prefix, output) {
                if (input === null || typeof input !== 'object') {
                    return;
                }
                Object.keys(input).forEach(function (key) {
                    var value = input[key];
                    var nextPrefix = prefix ? (prefix + '.' + key) : key;
                    if (value !== null && typeof value === 'object') {
                        flattenObject(value, nextPrefix, output);
                    } else {
                        output[nextPrefix] = value;
                    }
                });
            }

            function normalizeFieldName(value) {
                return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '');
            }

            function hasNonEmptyExtractedField(extracted, candidates) {
                if (!extracted || typeof extracted !== 'object') {
                    return false;
                }
                var flat = {};
                flattenObject(extracted, '', flat);
                var normalizedCandidates = candidates.map(function (candidate) {
                    return normalizeFieldName(candidate);
                });
                var paths = Object.keys(flat);
                for (var index = 0; index < paths.length; index++) {
                    var path = paths[index];
                    var value = flat[path];
                    var lastDot = path.lastIndexOf('.');
                    var fieldName = lastDot >= 0 ? path.substring(lastDot + 1) : path;
                    if (normalizedCandidates.indexOf(normalizeFieldName(fieldName)) === -1) {
                        continue;
                    }
                    if (value !== null && String(value).trim() !== '') {
                        return true;
                    }
                }
                return false;
            }

            function getMissingIdentityFields(extracted) {
                var missing = [];
                if (!hasNonEmptyExtractedField(extracted, ['name', 'full_name', 'patient_name', 'first_name', 'last_name', 'fname', 'lname', 'patient_first_name', 'patient_last_name'])) {
                    missing.push('name');
                }
                if (!hasNonEmptyExtractedField(extracted, ['gender', 'sex'])) {
                    missing.push('gender');
                }
                if (!hasNonEmptyExtractedField(extracted, ['date_of_birth', 'dob', 'birth_date'])) {
                    missing.push('date_of_birth');
                }
                return missing;
            }

            function parseIdentityFromMessage(messageText) {
                var text = String(messageText || '');
                var parsed = {};

                var nameMatch = /(?:^|[\n,;])\s*(?:name|patient\s*name)\s*[:=]\s*([^\n,;]+)/i.exec(text);
                if (nameMatch && nameMatch[1]) {
                    parsed.name = nameMatch[1].trim();
                }

                var genderMatch = /(?:^|[\n,;])\s*(?:gender|sex)\s*[:=]\s*([^\n,;]+)/i.exec(text);
                if (genderMatch && genderMatch[1]) {
                    parsed.gender = genderMatch[1].trim();
                }

                var dobMatch = /(?:^|[\n,;])\s*(?:date\s*of\s*birth|dob|birth\s*date)\s*[:=]\s*([^\n,;]+)/i.exec(text);
                if (dobMatch && dobMatch[1]) {
                    parsed.date_of_birth = dobMatch[1].trim();
                }

                if (!parsed.name && pendingIdentityCollection && pendingIdentityMissingFields.indexOf('name') !== -1) {
                    var compactText = text.replace(/\s+/g, ' ').trim();
                    if (compactText !== '' && compactText.indexOf(':') === -1 && compactText.indexOf('=') === -1) {
                        parsed.name = compactText;
                    }
                }

                return parsed;
            }

            function mergeIdentityIntoExtractedFacts(identity) {
                if (!extractedFacts || typeof extractedFacts !== 'object') {
                    extractedFacts = {};
                }
                if (identity.name) {
                    extractedFacts.name = identity.name;
                }
                if (identity.gender) {
                    extractedFacts.gender = identity.gender;
                }
                if (identity.date_of_birth) {
                    extractedFacts.date_of_birth = identity.date_of_birth;
                }
            }

            function handlePendingIdentityCollection(messageText) {
                if (!pendingIdentityCollection) {
                    return false;
                }
                var identity = parseIdentityFromMessage(messageText);
                mergeIdentityIntoExtractedFacts(identity);
                var stillMissing = getMissingIdentityFields(extractedFacts);
                pendingIdentityMissingFields = stillMissing;
                if (stillMissing.length > 0) {
                    appendBubble(
                        'assistant',
                        <?php echo json_encode(xl('Still missing required fields')); ?> + ': ' + stillMissing.join(', ')
                            + '\n' + <?php echo json_encode(xl('You can reply with only a first or last name when name is missing. For multiple fields, use format like: Name: <value>, DOB: <value>, Gender: <value>.')); ?>,
                        true
                    );
                    return true;
                }

                pendingIdentityCollection = false;
                pendingExtractConfirmation = true;
                appendBubble('assistant', JSON.stringify(extractedFacts, null, 2), false, <?php echo json_encode(xl('Updated extraction result')); ?>);
                appendBubble('assistant', <?php echo json_encode(xl('Does this extracted data look correct? Reply yes to save it to the active patient record, or no to skip.')); ?>, false);
                return true;
            }

            function normalizeSimpleText(text) {
                return String(text || '')
                    .toLowerCase()
                    .replace(/[^a-z0-9\s]/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
            }

            function isYesEquivalent(text) {
                var normalized = normalizeSimpleText(text);
                if (!normalized) {
                    return false;
                }
                var yesPhrases = {
                    'y': true,
                    'yes': true,
                    'yeah': true,
                    'yep': true,
                    'yup': true,
                    'sure': true,
                    'ok': true,
                    'okay': true,
                    'correct': true,
                    'looks good': true,
                    'sounds good': true,
                    'affirmative': true
                };
                return !!yesPhrases[normalized];
            }

            function isNoEquivalent(text) {
                var normalized = normalizeSimpleText(text);
                if (!normalized) {
                    return false;
                }
                var noPhrases = {
                    'n': true,
                    'no': true,
                    'nope': true,
                    'nah': true,
                    'negative': true,
                    'not correct': true
                };
                return !!noPhrases[normalized];
            }

            function isConvertibleToPdfPreview(file) {
                if (!file || !file.name) {
                    return false;
                }
                var fileName = String(file.name).toLowerCase();
                return fileName.endsWith('.docx')
                    || fileName.endsWith('.xlsx')
                    || fileName.endsWith('.jpg')
                    || fileName.endsWith('.jpeg')
                    || fileName.endsWith('.png')
                    || fileName.endsWith('.gif')
                    || fileName.endsWith('.webp')
                    || fileName.endsWith('.tif')
                    || fileName.endsWith('.tiff');
            }

            function convertToPdfPreviewBlob(file) {
                if (!isConvertibleToPdfPreview(file)) {
                    return Promise.resolve(file);
                }
                var formData = new FormData();
                formData.append('file', file);
                formData.append('csrf_token_form', csrfToken);
                return fetch(docxToPdfUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                }).then(function (r) {
                    if (!r.ok) {
                        return Promise.resolve(file);
                    }
                    return r.blob().then(function (b) {
                        if (!b || b.size === 0) {
                            return file;
                        }
                        return b;
                    }).catch(function () {
                        return file;
                    });
                }).catch(function () {
                    return file;
                });
            }

            function handleExtractConfirmationReply(messageText) {
                if (!pendingExtractConfirmation) {
                    return false;
                }
                if (isNoEquivalent(messageText)) {
                    pendingExtractConfirmation = false;
                    appendBubble('assistant', <?php echo json_encode(xl('Understood. I will not save that extraction. You can upload another document when ready.')); ?>, false);
                    return true;
                }
                if (!isYesEquivalent(messageText)) {
                    appendBubble('assistant', <?php echo json_encode(xl('Please reply with yes to save, or no to skip.')); ?>, false);
                    return true;
                }

                pendingExtractConfirmation = false;
                var payload = {
                    csrf_token_form: csrfToken,
                    doc_type: pendingExtractDocType,
                    source_file_name: pendingExtractFileName,
                    extracted_facts: extractedFacts
                };
                appendBubble('assistant', <?php echo json_encode(xl('Saving confirmed extracted data to patient record') . '...'); ?>, false, <?php echo json_encode(xl('Save status')); ?>);
                fetch(saveExtractedUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                }).then(function (r) {
                    return r.text().then(function (text) {
                        var data = null;
                        if (text) {
                            try {
                                data = JSON.parse(text);
                            } catch (e) {
                                data = null;
                            }
                        }
                        return {ok: r.ok, status: r.status, data: data, raw: text};
                    });
                }).then(function (res) {
                    if (res.ok && res.data && res.data.ok === true) {
                        var applied = res.data.applied || {};
                        var savedLines = [<?php echo json_encode(xl('Confirmed extraction has been saved for the active patient.')); ?>];
                        if (applied && applied.vitals_form_id) {
                            savedLines.push('- ' + <?php echo json_encode(xl('Vitals form created')); ?> + ': ' + applied.vitals_form_id);
                        }
                        if (applied && applied.pnote_id) {
                            savedLines.push('- ' + <?php echo json_encode(xl('Patient note created')); ?> + ': ' + applied.pnote_id);
                        }
                        if (res.data.warning) {
                            savedLines.push('- ' + <?php echo json_encode(xl('Warning')); ?> + ': ' + res.data.warning);
                        }
                        appendBubble('assistant', savedLines.join('\n'), false);
                    } else {
                        var err = (res.data && res.data.error) ? res.data.error : '';
                        if (!err && res.raw) {
                            err = res.raw;
                        }
                        if (!err) {
                            err = <?php echo json_encode(xl('Failed to persist extracted data')); ?> + ' (HTTP ' + res.status + ')';
                        }
                        appendBubble('assistant', err, true);
                    }
                }).catch(function () {
                    appendBubble('assistant', <?php echo json_encode(xl('Network error while saving extracted data')); ?>, true);
                });
                return true;
            }

            if (uploadBtn && fileInput && uploadLabBtn && uploadIntakeBtn) {
                uploadLabBtn.addEventListener('click', function () {
                    if (!agentReady || uploadBtn.disabled) {
                        return;
                    }
                    selectedUploadDocType = 'lab';
                    fileInput.click();
                });

                uploadIntakeBtn.addEventListener('click', function () {
                    if (!agentReady || uploadBtn.disabled) {
                        return;
                    }
                    selectedUploadDocType = 'intake_form';
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
                            <?php echo json_encode(xl('Uploading document to extraction service') . '…'); ?>,
                            <?php echo json_encode(xl('Extracting structured fields from document') . '…'); ?>,
                            <?php echo json_encode(xl('Waiting for extraction response') . '…'); ?>,
                        ],
                        1800
                    );

                    uploadBtn.disabled = true;
                    convertToPdfPreviewBlob(file).then(function (previewBlob) {
                        var extractionFile = file;
                        var previewForOverlay = previewBlob || file;
                        if (previewBlob && previewBlob !== file && previewBlob.type === 'application/pdf') {
                            extractionFile = previewBlob;
                        }

                        var formData = new FormData();
                        if (extractionFile === file) {
                            formData.append('file', file);
                        } else {
                            var baseName = String(file.name || 'uploaded-document').replace(/\.[^.]+$/, '');
                            formData.append('file', extractionFile, baseName + '.pdf');
                        }
                        formData.append('csrf_token_form', csrfToken);
                        formData.append('doc_type', selectedUploadDocType);

                        return fetch(extractUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        }).then(function (r) {
                            return r.json().then(function (data) {
                                return {ok: r.ok, status: r.status, data: data, previewBlob: previewForOverlay};
                            });
                        });
                    }).then(function (res) {
                        stopExtractStatus();
                        var lr = document.getElementById('ccp-extract-loading-row');
                        if (lr) {
                            lr.remove();
                        }
                        if (res.ok && res.data && res.data.extracted) {
                            res.data.extracted = enrichExtractedWithPdfCoordinates(res.data.extracted);
                            extractedFacts = res.data.extracted;
                            var extr = res.data.extracted;
                            var blobUrl = URL.createObjectURL(res.previewBlob || file);
                            currentExtractPreviewBlobUrl = blobUrl;
                            if (extr && Array.isArray(extr.results)) {
                                for (var ri = 0; ri < extr.results.length; ri++) {
                                    var rc = extr.results[ri] && extr.results[ri].citation;
                                    if (rc && rc.source_id) {
                                        registerSourceBlob(rc.source_id, blobUrl);
                                    }
                                }
                            }
                            if (extr && extr.citation && extr.citation.source_id) {
                                registerSourceBlob(extr.citation.source_id, blobUrl);
                            }
                            pendingExtractDocType = res.data.doc_type || 'lab';
                            pendingExtractFileName = file.name || 'uploaded-document';
                            appendBubble('assistant', JSON.stringify(res.data.extracted, null, 2), false, <?php echo json_encode(xl('Extraction result')); ?>);
                            var extractionCitations = collectCitationsFromExtracted(res.data.extracted);
                            if (extractionCitations.length > 0) {
                                appendCitationRow(extractionCitations);
                            }
                            var missingFields = getMissingIdentityFields(extractedFacts);
                            pendingIdentityMissingFields = missingFields;
                            if (missingFields.length > 0) {
                                pendingExtractConfirmation = false;
                                pendingIdentityCollection = true;
                                appendBubble(
                                    'assistant',
                                    <?php echo json_encode(xl('Unable to map data to a patient because required identity fields are missing')); ?>
                                        + ':\n' + missingFields.join('\n')
                                        + '\n' + <?php echo json_encode(xl('You can reply with only a first or last name when name is missing. Use this format: Name: <value>, DOB: <value>, Gender: <value>.')); ?>,
                                    true
                                );
                            } else {
                                pendingIdentityCollection = false;
                                pendingExtractConfirmation = true;
                                appendBubble('assistant', <?php echo json_encode(xl('Does this extracted data look correct? Reply yes to save it to the active patient record, or no to skip.')); ?>, false);
                            }
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
                if (handlePendingIdentityCollection(msg)) {
                    input.focus();
                    return;
                }
                if (handleExtractConfirmationReply(msg)) {
                    input.focus();
                    return;
                }
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
                        <?php echo json_encode(xl('Sending request to co-pilot') . '…'); ?>,
                        <?php echo json_encode(xl('Retrieving context and sources') . '…'); ?>,
                        <?php echo json_encode(xl('Generating response') . '…'); ?>,
                    ],
                    1800
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
                        if (Array.isArray(res.data.citations) && res.data.citations.length > 0) {
                            appendCitationRow(res.data.citations);
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
    <script src="<?php echo text($citationOverlayJsUrl); ?>"></script>
</body>
</html>
