<?php

/**
 * JSON API: persist clinician-confirmed extracted facts for the active patient.
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
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotExtractedDataApplyService;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotExtractedDataRepository;

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

    $pid = (int) trim((string) ($session->get('pid') ?? '0'));
    if ($pid <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No patient selected in session']);
        exit;
    }

    $encounterRaw = trim((string) ($session->get('encounter') ?? ''));
    $encounter = null;
    if ($encounterRaw !== '' && $encounterRaw !== '0') {
        $encounter = (int) $encounterRaw;
    }

    $docType = trim((string) ($payload['doc_type'] ?? ''));
    if (!in_array($docType, ['lab_pdf', 'intake_form'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid doc_type']);
        exit;
    }

    $fileName = trim((string) ($payload['source_file_name'] ?? ''));
    if ($fileName === '') {
        $fileName = 'uploaded-document';
    }
    if (mb_strlen($fileName) > 255) {
        $fileName = mb_substr($fileName, 0, 255);
    }

    $extractedFacts = $payload['extracted_facts'] ?? null;
    if (!is_array($extractedFacts)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid extracted_facts']);
        exit;
    }

    $authUserId = (int) trim((string) ($session->get('authUserID') ?? '0'));
    if ($authUserId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Unable to resolve authenticated user']);
        exit;
    }
    $authUser = trim((string) ($session->get('authUser') ?? ''));
    if ($authUser === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Unable to resolve authenticated username']);
        exit;
    }
    $authProvider = trim((string) ($session->get('authProvider') ?? ''));
    if ($authProvider === '') {
        $authProvider = 'Default';
    }

    $repository = new ClinicalCopilotExtractedDataRepository();
    $repository->saveConfirmedExtraction(
        $pid,
        $encounter,
        $docType,
        $fileName,
        $authUserId,
        $extractedFacts
    );
    $applyService = new ClinicalCopilotExtractedDataApplyService();
    $applyResult = $applyService->applyConfirmedExtraction(
        $pid,
        $encounter,
        $docType,
        $authUser,
        $authProvider,
        $authUserId,
        $extractedFacts
    );

    echo json_encode(['ok' => true, 'applied' => $applyResult], JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    http_response_code(500);
    echo json_encode(['error' => 'Encoding error']);
} catch (\Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to persist extracted data']);
}
