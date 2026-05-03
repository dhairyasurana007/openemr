<?php

/**
 * JSON API: encounter-scoped Clinical Co-Pilot clinician intake completion + UC2 pre-generation state.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$sessionAllowWrite = true;
require_once(__DIR__ . '/../../../../globals.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotAgentChatAuditBinding;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotEncounterPrompts;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotEncounterStateRepository;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotUc2PregenEligibility;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotUseCase;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotWebChatComposer;
use OpenEMR\Services\ClinicalCopilot\CopilotAgentChatBridge;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

header('Content-Type: application/json; charset=utf-8');

/**
 * Encounter documentation or relaxed encounter access for intake completion.
 */
function ccp_encounter_intake_acl_ok(): bool
{
    return AclMain::aclCheckCore('encounters', 'notes')
        || AclMain::aclCheckCore('encounters', 'notes_a')
        || AclMain::aclCheckCore('encounters', 'relaxed');
}

/**
 * Read-only encounter co-pilot (matches encounter panel read path).
 */
function ccp_encounter_panel_read_acl_ok(): bool
{
    return AclMain::aclCheckCore('encounters', 'notes') || AclMain::aclCheckCore('encounters', 'notes_a');
}

/**
 * Runs UC2 against the agent when eligible and persists the briefing (idempotent; not UC1).
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ccp_run_uc2_pregen_if_needed(
    ClinicalCopilotEncounterStateRepository $repository,
    SessionInterface $session,
    AgentRuntimeHandoff $handoff,
    int $pid,
    int $encounterId,
    array $payload,
    string $authUser,
    string $authGroup,
    bool $auditOutcome,
    bool $ensureRowExists,
): array {
    if ($ensureRowExists) {
        $repository->ensureEncounterRow($pid, $encounterId);
    }

    $row = $repository->getRow($pid, $encounterId);
    $now = time();

    if (ClinicalCopilotUc2PregenEligibility::hasCachedBriefing($row)) {
        return [
            'ok' => true,
            'uc2_from_cache' => true,
            'uc2_briefing' => trim((string) ($row['uc2_briefing_cached'] ?? '')),
            'uc2_status' => 'complete',
            'intake_completed_at' => $row['intake_completed_at'] ?? null,
        ];
    }

    if (!ClinicalCopilotUc2PregenEligibility::shouldInvokeAgent($row, $now)) {
        return [
            'ok' => true,
            'uc2_from_cache' => false,
            'uc2_status' => 'pending',
            'uc2_briefing' => null,
            'intake_completed_at' => $row['intake_completed_at'] ?? null,
        ];
    }

    if (!$handoff->isConfigured()) {
        return [
            'ok' => true,
            'uc2_from_cache' => false,
            'uc2_briefing' => null,
            'agent_unavailable' => true,
            'uc2_status' => 'none',
            'intake_completed_at' => $row['intake_completed_at'] ?? null,
        ];
    }

    $repository->markUc2Pending($pid, $encounterId);

    $composer = new ClinicalCopilotWebChatComposer();
    $bridge = new CopilotAgentChatBridge();
    $agentPayload = $composer->compose($session, array_merge($payload, [
        'use_case' => ClinicalCopilotUseCase::UC2->value,
        'encounter_id' => (string) $encounterId,
    ]), ClinicalCopilotEncounterPrompts::UC2_PREVISIT_FACTS);

    try {
        $audit = ClinicalCopilotAgentChatAuditBinding::fromSessionAndPayload($session, $agentPayload);
        $out = $bridge->forwardPayload($agentPayload, $handoff, $audit);
        $reply = trim((string) ($out['reply'] ?? ''));
        $repository->markUc2Complete($pid, $encounterId, $reply);
        if ($auditOutcome) {
            EventAuditLogger::getInstance()->newEvent(
                'clinical-copilot-uc2-pregen',
                $authUser,
                $authGroup,
                1,
                'UC2 pre-visit briefing generated for encounter ' . $encounterId,
                $pid,
            );
        }
        $after = $repository->getRow($pid, $encounterId);

        return [
            'ok' => true,
            'uc2_from_cache' => false,
            'uc2_briefing' => $reply,
            'uc2_status' => 'complete',
            'intake_completed_at' => $after['intake_completed_at'] ?? null,
        ];
    } catch (\Throwable) {
        $repository->markUc2Failed($pid, $encounterId, 'agent_error');
        if ($auditOutcome) {
            EventAuditLogger::getInstance()->newEvent(
                'clinical-copilot-uc2-pregen',
                $authUser,
                $authGroup,
                0,
                'UC2 pre-generation failed for encounter ' . $encounterId,
                $pid,
            );
        }
        $after = $repository->getRow($pid, $encounterId);

        return [
            'ok' => true,
            'uc2_from_cache' => false,
            'uc2_briefing' => null,
            'uc2_error' => 'Clinical co-pilot agent request failed.',
            'uc2_status' => 'failed',
            'intake_completed_at' => $after['intake_completed_at'] ?? null,
        ];
    }
}

try {
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    if (!AclMain::aclCheckCore('patients', 'demo')) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized']);
        exit;
    }

    $pid = (int) trim((string) $session->get('pid'));
    $encounterId = (int) trim((string) $session->get('encounter'));
    if ($pid <= 0 || $encounterId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No patient encounter in session']);
        exit;
    }

    $repository = new ClinicalCopilotEncounterStateRepository();
    $handoff = AgentRuntimeHandoff::fromEnvironment();
    $agentConfigured = $handoff->isConfigured();

    if ($method === 'GET') {
        if (!ccp_encounter_panel_read_acl_ok()) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized']);
            exit;
        }
        $row = $repository->getRow($pid, $encounterId);
        echo json_encode([
            'intake_completed' => $row !== null && $row['intake_completed_at'] !== null && $row['intake_completed_at'] !== '',
            'intake_completed_at' => $row['intake_completed_at'] ?? null,
            'intake_completed_user' => $row['intake_completed_user'] ?? null,
            'uc2_briefing_cached' => isset($row['uc2_briefing_cached']) && is_string($row['uc2_briefing_cached'])
                ? $row['uc2_briefing_cached'] : null,
            'uc2_pregen_status' => $row['uc2_pregen_status'] ?? 'none',
            'agent_configured' => $agentConfigured,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $token = $payload['csrf_token_form'] ?? '';
    if (!is_string($token) || !CsrfUtils::verifyCsrfToken($token, $session, 'default')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF validation failed']);
        exit;
    }

    $action = isset($payload['action']) ? (string) $payload['action'] : '';
    if ($action !== 'clinician_intake_complete' && $action !== 'uc2_open_encounter_fallback') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    if ($action === 'uc2_open_encounter_fallback') {
        if (!ccp_encounter_panel_read_acl_ok()) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized']);
            exit;
        }
        $authUser = (string) ($session->get('authUser') ?? '');
        $authGroup = (string) ($session->get('authProvider') ?? '');
        try {
            $out = ccp_run_uc2_pregen_if_needed(
                $repository,
                $session,
                $handoff,
                $pid,
                $encounterId,
                $payload,
                $authUser,
                $authGroup,
                auditOutcome: false,
                ensureRowExists: true,
            );
            echo json_encode($out, JSON_THROW_ON_ERROR);
        } catch (SqlQueryException) {
            http_response_code(503);
            echo json_encode(['error' => 'Clinical co-pilot state storage is unavailable.']);
        }
        exit;
    }

    if (!ccp_encounter_intake_acl_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized']);
        exit;
    }

    $authUser = (string) ($session->get('authUser') ?? '');
    $authGroup = (string) ($session->get('authProvider') ?? '');

    $before = $repository->getRow($pid, $encounterId);
    $firstIntakeCompletion = $before === null
        || ($before['intake_completed_at'] ?? null) === null
        || $before['intake_completed_at'] === '';

    try {
        $repository->recordIntakeComplete($pid, $encounterId, $authUser);
    } catch (SqlQueryException) {
        http_response_code(503);
        echo json_encode(['error' => 'Clinical co-pilot state storage is unavailable.']);
        exit;
    }

    if ($firstIntakeCompletion) {
        EventAuditLogger::getInstance()->newEvent(
            'clinical-copilot-clinician-intake',
            $authUser,
            $authGroup,
            1,
            'Clinician intake marked complete for encounter ' . $encounterId,
            $pid,
        );
    }

    try {
        $out = ccp_run_uc2_pregen_if_needed(
            $repository,
            $session,
            $handoff,
            $pid,
            $encounterId,
            $payload,
            $authUser,
            $authGroup,
            auditOutcome: true,
            ensureRowExists: false,
        );
        $out['intake_completed'] = true;
        echo json_encode($out, JSON_THROW_ON_ERROR);
    } catch (SqlQueryException) {
        http_response_code(503);
        echo json_encode(['error' => 'Unable to record UC2 pre-generation state.']);
    }
} catch (HttpExceptionInterface $e) {
    $msg = $e->getMessage();
    if ($msg === '') {
        $msg = 'Request rejected';
    }
    http_response_code($e->getStatusCode());
    echo json_encode(['error' => $msg], JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    http_response_code(500);
    echo json_encode(['error' => 'Encoding error']);
}
