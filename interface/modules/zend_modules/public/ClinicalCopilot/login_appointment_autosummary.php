<?php

/**
 * One-shot post-login: if the user has a current or imminent calendar visit, ask the agent for a chart summary.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$sessionAllowWrite = true;
require_once(__DIR__ . '/../../../../globals.php');

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotAgentChatAuditBinding;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotAgentChatPayload;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotLoginAppointmentSummaryResolver;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotUseCase;
use OpenEMR\Services\ClinicalCopilot\CopilotAgentChatBridge;
use OpenEMR\Services\PatientService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

header('Content-Type: application/json; charset=utf-8');

const SESSION_DONE_KEY = 'clinical_copilot_login_appt_autosummary_done';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_THROW_ON_ERROR);
        exit;
    }

    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_THROW_ON_ERROR);
        exit;
    }

    $token = $payload['csrf_token_form'] ?? '';
    if (!is_string($token) || !CsrfUtils::verifyCsrfToken($token, $session, 'default')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_THROW_ON_ERROR);
        exit;
    }

    if (!AclMain::aclCheckCore('patients', 'demo')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not authorized'], JSON_THROW_ON_ERROR);
        exit;
    }

    if (!AclMain::aclCheckCore('encounters', 'notes') && !AclMain::aclCheckCore('encounters', 'notes_a')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not authorized'], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($session->get(SESSION_DONE_KEY)) {
        echo json_encode(['ok' => true, 'skipped' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    $handoff = AgentRuntimeHandoff::fromEnvironment();
    if (!$handoff->isConfigured()) {
        $session->set(SESSION_DONE_KEY, true);
        echo json_encode(['ok' => true, 'ran' => false, 'reason' => 'agent_not_configured'], JSON_THROW_ON_ERROR);
        exit;
    }

    require_once($GLOBALS['srcdir'] . '/appointments.inc.php');

    $authUserId = (int) ($session->get('authUserID') ?? 0);
    $resolver = new ClinicalCopilotLoginAppointmentSummaryResolver();
    $match = $resolver->findForProvider($authUserId, new \DateTimeImmutable('now'));

    if ($match === null) {
        $session->set(SESSION_DONE_KEY, true);
        echo json_encode(['ok' => true, 'ran' => false, 'reason' => 'no_appointment'], JSON_THROW_ON_ERROR);
        exit;
    }

    $patientService = new PatientService();
    $binUuid = $patientService->getUuid((string) $match->patientPid);
    if ($binUuid === false || $binUuid === '') {
        $session->set(SESSION_DONE_KEY, true);
        echo json_encode(['ok' => true, 'ran' => false, 'reason' => 'patient_uuid_unavailable'], JSON_THROW_ON_ERROR);
        exit;
    }

    $puuid = UuidRegistry::uuidToString($binUuid);
    $startIso = $match->appointmentStart->format(\DateTimeInterface::ATOM);
    $safeTitle = trim(preg_replace('/\s+/', ' ', str_replace(["\n", "\r", '"', "'"], [' ', ' ', ' ', '’'], $match->appointmentTitle)));
    if ($safeTitle === '') {
        $safeTitle = 'scheduled visit';
    }
    if (strlen($safeTitle) > 180) {
        $safeTitle = substr($safeTitle, 0, 180);
    }

    $message = 'For this patient, produce a concise clinical readiness summary for the clinician who is about to see '
        . 'them for the scheduled appointment titled "' . $safeTitle . '" starting at ' . $startIso . '. '
        . 'Use the patient chart retrieval tools only. Include: active problems, allergies, notable current medications, '
        . 'and any very recent vitals or labs returned by tools. If the chart has little data, say so plainly. '
        . 'Do not invent clinical facts.';

    if (strlen($message) > 4000) {
        $message = substr($message, 0, 4000);
    }

    $agentPayload = new ClinicalCopilotAgentChatPayload(
        message: $message,
        useCase: ClinicalCopilotUseCase::UC4,
        patientUuid: $puuid,
        encounterId: null,
        httpTimeoutOverrideSeconds: 24.0,
    );

    $audit = ClinicalCopilotAgentChatAuditBinding::forUserAndPatient($authUserId, $match->patientPid, null);
    $bridge = new CopilotAgentChatBridge();
    $out = $bridge->forwardPayload($agentPayload, $handoff, $audit);

    $session->set(SESSION_DONE_KEY, true);
    echo json_encode([
        'ok' => true,
        'ran' => true,
        'reply' => $out['reply'],
    ], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $e) {
    $msg = $e->getMessage();
    if ($msg === '') {
        $msg = 'Request rejected';
    }
    http_response_code($e->getStatusCode());
    echo json_encode(['ok' => false, 'error' => $msg], JSON_THROW_ON_ERROR);
} catch (\DomainException $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Clinical co-pilot is not configured.'], JSON_THROW_ON_ERROR);
} catch (\RuntimeException $e) {
    ServiceContainer::getLogger()->error('clinical_copilot_login_appt_autosummary_failed', ['exception' => $e]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Clinical co-pilot is temporarily unavailable.'], JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Encoding error'], JSON_THROW_ON_ERROR);
}
