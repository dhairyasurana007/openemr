<?php

/**
 * JSON proxy: OpenEMR web (session + ACL + CSRF) → copilot-agent → OpenRouter.
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
use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotAgentChatAuditBinding;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotAgentChatPayload;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotUseCase;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotWebChatComposer;
use OpenEMR\Services\ClinicalCopilot\CopilotAgentChatBridge;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
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

    if (!AclMain::aclCheckCore('patients', 'demo')) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized']);
        exit;
    }

    $message = $payload['message'] ?? '';
    if (!is_string($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid message']);
        exit;
    }
    $message = trim($message);
    if ($message === '' || strlen($message) > 4000) {
        http_response_code(400);
        echo json_encode(['error' => 'Message must be between 1 and 4000 characters']);
        exit;
    }

    $handoff = AgentRuntimeHandoff::fromEnvironment();
    $bridge = new CopilotAgentChatBridge();

    $useCaseRaw = $payload['use_case'] ?? '';
    $hasExplicitUseCase = is_string($useCaseRaw) && trim($useCaseRaw) !== '';
    if (!$hasExplicitUseCase) {
        $audit = ClinicalCopilotAgentChatAuditBinding::fromSessionAndPayload(
            $session,
            new ClinicalCopilotAgentChatPayload($message, ClinicalCopilotUseCase::UC4),
        );
        $out = $bridge->forwardMessage($message, $handoff, $audit);
        echo json_encode(['reply' => $out['reply']], JSON_THROW_ON_ERROR);
        exit;
    }

    $parsed = ClinicalCopilotUseCase::tryParse($useCaseRaw);
    if ($parsed === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid use_case']);
        exit;
    }

    if ($parsed->isScheduleScoped() && !AclMain::aclCheckCore('patients', 'appt')) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized for schedule']);
        exit;
    }

    $composer = new ClinicalCopilotWebChatComposer();
    $agentPayload = $composer->compose($session, $payload, $message);
    $audit = ClinicalCopilotAgentChatAuditBinding::fromSessionAndPayload($session, $agentPayload);
    $out = $bridge->forwardPayload($agentPayload, $handoff, $audit);
    echo json_encode(['reply' => $out['reply']], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $e) {
    $msg = $e->getMessage();
    if ($msg === '') {
        $msg = 'Request rejected';
    }
    http_response_code($e->getStatusCode());
    echo json_encode(['error' => $msg], JSON_THROW_ON_ERROR);
} catch (\DomainException $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Clinical co-pilot is not configured.']);
} catch (\RuntimeException $e) {
    ServiceContainer::getLogger()->error('clinical_copilot_chat_proxy_failed', ['exception' => $e]);
    http_response_code(502);
    echo json_encode(['error' => 'Clinical co-pilot is temporarily unavailable.']);
} catch (\JsonException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Encoding error']);
}
