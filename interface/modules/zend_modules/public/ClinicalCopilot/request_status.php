<?php

/**
 * JSON proxy: OpenEMR web (session + ACL) -> copilot-agent /v1/request-status/{request_id}.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../globals.php');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\RestControllers\ClinicalCopilot\ClinicalCopilotInternalAuth;
use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

$requestId = isset($_GET['request_id']) ? trim((string) $_GET['request_id']) : '';
if ($requestId === '' || !preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $requestId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request_id']);
    exit;
}

try {
    $handoff = AgentRuntimeHandoff::fromEnvironment();
    $base = $handoff->privateAgentBaseUrl;
    if ($base === '') {
        throw new \DomainException('Clinical co-pilot agent URL is not configured');
    }

    $url = rtrim($base, '/') . '/v1/request-status/' . rawurlencode($requestId);
    $secret = (string) (getenv('CLINICAL_COPILOT_INTERNAL_SECRET') ?: '');
    $headers = ['Accept' => 'application/json'];
    if ($secret !== '') {
        $headers[ClinicalCopilotInternalAuth::HEADER_NAME] = $secret;
    }

    $client = new Client(['timeout' => 4.0, 'connect_timeout' => 1.0]);
    $response = $client->get($url, ['headers' => $headers]);
    $raw = (string) $response->getBody();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('Clinical co-pilot request-status endpoint returned invalid JSON.');
    }
    echo json_encode($decoded, JSON_THROW_ON_ERROR);
} catch (\DomainException) {
    http_response_code(503);
    echo json_encode(['error' => 'Clinical co-pilot is not configured.']);
} catch (GuzzleException | \RuntimeException) {
    http_response_code(502);
    echo json_encode(['error' => 'Clinical co-pilot is temporarily unavailable.']);
} catch (\JsonException) {
    http_response_code(500);
    echo json_encode(['error' => 'Encoding error']);
}

