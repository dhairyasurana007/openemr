<?php

/**
 * Multipart upload proxy: OpenEMR web (session + ACL + CSRF) → copilot-agent /v1/extract.
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
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotLoginAppointmentSummaryResolver;

function ccpExtractReleaseSessionLock(): void
{
    if (PHP_SESSION_ACTIVE === session_status()) {
        session_write_close();
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function ccpExtractFlattenArray(array $input): array
{
    $flat = [];
    $stack = [['prefix' => '', 'value' => $input]];
    while ($stack !== []) {
        $frame = array_pop($stack);
        if (!is_array($frame)) {
            continue;
        }
        $prefix = is_string($frame['prefix'] ?? null) ? $frame['prefix'] : '';
        $value = $frame['value'] ?? null;
        if (!is_array($value)) {
            if ($prefix !== '') {
                $flat[$prefix] = $value;
            }
            continue;
        }
        foreach ($value as $key => $child) {
            $keyPart = is_int($key) ? (string) $key : $key;
            $nextPrefix = $prefix === '' ? $keyPart : ($prefix . '.' . $keyPart);
            if (is_array($child)) {
                $stack[] = ['prefix' => $nextPrefix, 'value' => $child];
            } else {
                $flat[$nextPrefix] = $child;
            }
        }
    }
    return $flat;
}

function ccpExtractNormalizeFieldName(string $name): string
{
    return strtolower(preg_replace('/[^a-z0-9]/', '', $name) ?? '');
}

function ccpExtractInferDocType(string $mimeType, string $fileExtension): string
{
    if ($mimeType === 'application/pdf' || $fileExtension === 'pdf') {
        return 'lab';
    }
    return 'intake_form';
}


/**
 * @param array<string, mixed> $extractedFacts
 * @param list<string> $fieldCandidates
 */
function ccpExtractHasNonEmptyField(array $extractedFacts, array $fieldCandidates): bool
{
    $normalizedCandidates = array_map(
        static fn(string $candidate): string => ccpExtractNormalizeFieldName($candidate),
        $fieldCandidates
    );
    $flat = ccpExtractFlattenArray($extractedFacts);
    foreach ($flat as $path => $value) {
        $lastDot = strrpos($path, '.');
        $fieldName = $lastDot === false ? $path : substr($path, $lastDot + 1);
        if (!in_array(ccpExtractNormalizeFieldName($fieldName), $normalizedCandidates, true)) {
            continue;
        }
        if (is_scalar($value) && trim((string) $value) !== '') {
            return true;
        }
    }
    return false;
}

header('Content-Type: application/json; charset=utf-8');
$logger = ServiceContainer::getLogger();
$requestId = uniqid('ccp_ext_', true);
$reqStart = microtime(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $candidateRequestId = isset($_POST['request_id']) ? trim((string) $_POST['request_id']) : '';
    if ($candidateRequestId !== '' && preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $candidateRequestId)) {
        $requestId = $candidateRequestId;
    }

    // CSRF token arrives as a POST field (multipart body, not JSON).
    $token = $_POST['csrf_token_form'] ?? '';
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

    $docType = trim((string) ($_POST['doc_type'] ?? ''));
    $docTypeInferred = false;

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        http_response_code(400);
        echo json_encode(['error' => 'File upload error (code ' . $uploadErr . ')']);
        exit;
    }

    $fileTmpPath = (string) $_FILES['file']['tmp_name'];
    $fileOriginalName = (string) ($_FILES['file']['name'] ?? 'upload');
    $fileExtension = strtolower(pathinfo($fileOriginalName, PATHINFO_EXTENSION));

    // Validate MIME type from file bytes (not the browser-reported Content-Type).
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($fileTmpPath);
    $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/tiff',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/hl7-v2',
        'text/plain',
    ];
    $genericMimes = ['application/zip', 'application/octet-stream'];
    $genericMimeAllowedExtensions = ['docx', 'xlsx', 'hl7', 'txt'];
    $isAllowedMime = in_array($mimeType, $allowedMimes, true);
    $isAllowedGenericMime = in_array($mimeType, $genericMimes, true)
        && in_array($fileExtension, $genericMimeAllowedExtensions, true);
    if (!$isAllowedMime && !$isAllowedGenericMime) {
        http_response_code(415);
        echo json_encode(['error' => 'Unsupported file type. Allowed: PDF, images (JPEG, PNG, GIF, WebP, TIFF), DOCX, XLSX, HL7, and TXT.']);
        exit;
    }
    if ($docType === '') {
        $docType = ccpExtractInferDocType($mimeType, $fileExtension);
        $docTypeInferred = true;
    }
    if (!in_array($docType, ['lab', 'intake_form'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid doc_type. Allowed values: lab, intake_form']);
        exit;
    }

    // Resolve the current patient identifier from the OpenEMR session.
    // pid is the integer primary-key; use it as the stable patient_id string.
    $patientId = trim((string) ($session->get('pid') ?? ''));
    if ($patientId === '' || $patientId === '0') {
        // Fallback: use the logged-in provider's current/imminent appointment patient.
        // This matches the login auto-summary context when an encounter hasn't yet set session pid.
        require_once($GLOBALS['srcdir'] . '/appointments.inc.php');
        $authUserId = (int) ($session->get('authUserID') ?? 0);
        $resolver = new ClinicalCopilotLoginAppointmentSummaryResolver();
        $match = $resolver->findForProvider($authUserId, new \DateTimeImmutable('now'));
        if ($match !== null && $match->patientPid > 0) {
            $patientId = (string) $match->patientPid;
            $session->set('pid', $patientId);
        }
    }
    if ($patientId === '0') {
        $patientId = '';
    }

    $handoff = AgentRuntimeHandoff::fromEnvironment();
    $base = $handoff->privateAgentBaseUrl;
    if ($base === '') {
        throw new \DomainException('Clinical co-pilot agent URL is not configured');
    }

    $url = rtrim($base, '/') . '/v1/extract';

    $secret = (string) (getenv('CLINICAL_COPILOT_INTERNAL_SECRET') ?: '');
    $headers = ['Accept' => 'application/json'];
    if ($secret !== '') {
        $headers[ClinicalCopilotInternalAuth::HEADER_NAME] = $secret;
    }
    if ($requestId !== '') {
        $headers['X-Request-Id'] = $requestId;
    }

    $fileContents = file_get_contents($fileTmpPath);
    if ($fileContents === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to read uploaded file']);
        exit;
    }

    ccpExtractReleaseSessionLock();

    $client = new Client(['timeout' => 120.0, 'connect_timeout' => 3.0]);
    $response = $client->post($url, [
        'headers' => $headers,
        'multipart' => [
            [
                'name'     => 'file',
                'contents' => $fileContents,
                'filename' => $fileOriginalName,
                'headers'  => ['Content-Type' => $mimeType],
            ],
            [
                'name'     => 'doc_type',
                'contents' => $docType,
            ],
            [
                'name'     => 'patient_id',
                'contents' => $patientId,
            ],
        ],
    ]);

    $raw = (string) $response->getBody();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('Clinical co-pilot extract endpoint returned invalid JSON.');
    }
    $latencyMs = (int) round((microtime(true) - $reqStart) * 1000.0);
    $logger->info('clinical_copilot_extract_proxy_ok', [
        'request_id' => $requestId,
        'doc_type'   => $docType,
        'mime_type'  => $mimeType,
        'total_ms'   => $latencyMs,
    ]);

    if (!array_key_exists('doc_type', $decoded)) {
        $decoded['doc_type'] = $docType;
    }
    if (!array_key_exists('doc_type_inferred', $decoded)) {
        $decoded['doc_type_inferred'] = $docTypeInferred;
    }

    echo json_encode($decoded, JSON_THROW_ON_ERROR);
} catch (\DomainException $e) {
    http_response_code(503);
    $logger->error('clinical_copilot_extract_proxy_domain_exception', [
        'request_id' => $requestId,
        'total_ms'   => (int) round((microtime(true) - $reqStart) * 1000.0),
        'exception'  => $e,
    ]);
    echo json_encode(['error' => 'Clinical co-pilot is not configured.']);
} catch (GuzzleException $e) {
    $logger->error('clinical_copilot_extract_proxy_transport_error', [
        'request_id' => $requestId,
        'total_ms'   => (int) round((microtime(true) - $reqStart) * 1000.0),
        'exception'  => $e,
    ]);
    http_response_code(502);
    echo json_encode(['error' => 'Clinical co-pilot is temporarily unavailable.']);
} catch (\RuntimeException $e) {
    $logger->error('clinical_copilot_extract_proxy_runtime_error', [
        'request_id' => $requestId,
        'total_ms'   => (int) round((microtime(true) - $reqStart) * 1000.0),
        'exception'  => $e,
    ]);
    http_response_code(502);
    echo json_encode(['error' => 'Clinical co-pilot is temporarily unavailable.']);
} catch (\JsonException $e) {
    http_response_code(500);
    $logger->error('clinical_copilot_extract_proxy_json_exception', [
        'request_id' => $requestId,
        'total_ms'   => (int) round((microtime(true) - $reqStart) * 1000.0),
        'exception'  => $e,
    ]);
    echo json_encode(['error' => 'Encoding error']);
}
