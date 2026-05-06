<?php

/**
 * JSON proxy: OpenEMR web (session + ACL + CSRF) → copilot-agent /v1/multimodal-chat.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$sessionAllowWrite = true;
require_once(__DIR__ . '/../../../../globals.php');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\RestControllers\ClinicalCopilot\ClinicalCopilotInternalAuth;
use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;

function ccpMmReleaseSessionLock(): void
{
    if (PHP_SESSION_ACTIVE === session_status()) {
        session_write_close();
    }
}

header('Content-Type: application/json; charset=utf-8');
$logger = ServiceContainer::getLogger();
$requestId = uniqid('ccp_mm_', true);
$reqStart = microtime(true);

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
    $base = $handoff->privateAgentBaseUrl;
    if ($base === '') {
        throw new \DomainException('Clinical co-pilot agent URL is not configured');
    }

    $url = rtrim($base, '/') . '/v1/multimodal-chat';

    $secret = (string) (getenv('CLINICAL_COPILOT_INTERNAL_SECRET') ?: '');
    $headers = [
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json',
    ];
    if ($secret !== '') {
        $headers[ClinicalCopilotInternalAuth::HEADER_NAME] = $secret;
    }
    if ($requestId !== '') {
        $headers['X-Request-Id'] = $requestId;
    }

    $agentPayload = ['message' => $message];
    if (isset($payload['patient_id']) && is_string($payload['patient_id'])) {
        $agentPayload['patient_id'] = $payload['patient_id'];
    }
    if (isset($payload['extracted_facts']) && is_array($payload['extracted_facts'])) {
        $agentPayload['extracted_facts'] = $payload['extracted_facts'];
    }
    if (isset($payload['surface']) && is_string($payload['surface'])) {
        $agentPayload['surface'] = $payload['surface'];
    }

    ccpMmReleaseSessionLock();

    $client = new Client(['timeout' => 120.0, 'connect_timeout' => 3.0]);
    $response = $client->post($url, [
        'headers' => $headers,
        'json'    => $agentPayload,
    ]);

    $responseRaw = (string) $response->getBody();
    $decoded = json_decode($responseRaw, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('Clinical co-pilot multimodal-chat endpoint returned invalid JSON.');
    }

    $latencyMs = (int) round((microtime(true) - $reqStart) * 1000.0);
    $logger->info('clinical_copilot_multimodal_chat_proxy_ok', [
        'request_id'     => $requestId,
        'total_ms'       => $latencyMs,
        'routing_steps'  => count($decoded['routing_log'] ?? []),
    ]);

    echo json_encode($decoded, JSON_THROW_ON_ERROR);
} catch (\DomainException $e) {
    http_response_code(503);
    $logger->error('clinical_copilot_multimodal_chat_proxy_domain_exception', [
        'request_id' => $requestId,
        'total_ms'   => (int) round((microtime(true) - $reqStart) * 1000.0),
        'exception'  => $e,
    ]);
    echo json_encode(['error' => 'Clinical co-pilot is not configured.']);
} catch (GuzzleException $e) {
    $logger->error('clinical_copilot_multimodal_chat_proxy_transport_error', [
        'request_id' => $requestId,
        'total_ms'   => (int) round((microtime(true) - $reqStart) * 1000.0),
        'exception'  => $e,
    ]);
    http_response_code(502);
    echo json_encode(['error' => 'Clinical co-pilot is temporarily unavailable.']);
} catch (\RuntimeException $e) {
    $logger->error('clinical_copilot_multimodal_chat_proxy_runtime_error', [
        'request_id' => $requestId,
        'total_ms'   => (int) round((microtime(true) - $reqStart) * 1000.0),
        'exception'  => $e,
    ]);
    http_response_code(502);
    echo json_encode(['error' => 'Clinical co-pilot is temporarily unavailable.']);
} catch (\JsonException $e) {
    http_response_code(500);
    $logger->error('clinical_copilot_multimodal_chat_proxy_json_exception', [
        'request_id' => $requestId,
        'total_ms'   => (int) round((microtime(true) - $reqStart) * 1000.0),
        'exception'  => $e,
    ]);
    echo json_encode(['error' => 'Encoding error']);
}
