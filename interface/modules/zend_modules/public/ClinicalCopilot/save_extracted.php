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
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotExtractedDataApplyService;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotExtractedDataRepository;
use OpenEMR\Services\PatientService;

header('Content-Type: application/json; charset=utf-8');

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function ccpFlattenArray(array $input): array
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

function ccpNormalizeFieldName(string $name): string
{
    return strtolower(preg_replace('/[^a-z0-9]/', '', $name) ?? '');
}

function ccpNormalizeSex(string $value): string
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return '';
    }
    if (in_array($normalized, ['m', 'male', 'man', 'boy'], true)) {
        return 'M';
    }
    if (in_array($normalized, ['f', 'female', 'woman', 'girl'], true)) {
        return 'F';
    }
    return strtoupper(substr($normalized, 0, 1));
}

function ccpNormalizeDateYmd(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    $timestamp = strtotime($trimmed);
    if ($timestamp === false) {
        return '';
    }
    return date('Y-m-d', $timestamp);
}

/**
 * @param array<string, mixed> $extractedFacts
 * @param list<string> $fieldCandidates
 */
function ccpFirstNonEmptyFieldValue(array $extractedFacts, array $fieldCandidates): ?string
{
    $normalizedCandidates = array_map(static fn(string $candidate): string => ccpNormalizeFieldName($candidate), $fieldCandidates);
    $flat = ccpFlattenArray($extractedFacts);
    foreach ($flat as $path => $value) {
        $lastDot = strrpos($path, '.');
        $fieldName = $lastDot === false ? $path : substr($path, $lastDot + 1);
        if (!in_array(ccpNormalizeFieldName($fieldName), $normalizedCandidates, true)) {
            continue;
        }
        if (is_scalar($value)) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }
    return null;
}

/**
 * @param array<string, mixed> $extractedFacts
 */
function ccpResolvePidFromExtractedFacts(array $extractedFacts): ?int
{
    $name = ccpFirstNonEmptyFieldValue($extractedFacts, [
        'name',
        'full_name',
        'patient_name',
        'first_name',
        'last_name',
        'fname',
        'lname',
        'patient_first_name',
        'patient_last_name',
    ]);
    $dobRaw = ccpFirstNonEmptyFieldValue($extractedFacts, ['date_of_birth', 'dob', 'birth_date']);
    $sexRaw = ccpFirstNonEmptyFieldValue($extractedFacts, ['gender', 'sex']);
    if ($name === null || $dobRaw === null || $sexRaw === null) {
        return null;
    }

    $dob = ccpNormalizeDateYmd($dobRaw);
    $sex = ccpNormalizeSex($sexRaw);
    if ($dob === '' || $sex === '') {
        return null;
    }

    $search = [];
    $nameParts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($nameParts) >= 2) {
        $search['fname'] = $nameParts[0];
        $search['lname'] = $nameParts[count($nameParts) - 1];
    } else {
        $search['fname'] = $name;
        $search['lname'] = $name;
        $search['mname'] = $name;
    }
    $search['DOB'] = $dob;
    $search['sex'] = $sex;

    $patientService = new PatientService();
    $result = $patientService->getAll($search, false);
    if (!$result->isValid()) {
        return null;
    }
    $rows = $result->getData();
    if (!is_array($rows) || $rows === []) {
        return null;
    }

    $candidates = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowDob = ccpNormalizeDateYmd((string) ($row['DOB'] ?? ''));
        $rowSex = ccpNormalizeSex((string) ($row['sex'] ?? ''));
        $rowPid = (int) ($row['pid'] ?? 0);
        if ($rowPid <= 0) {
            continue;
        }
        if ($rowDob !== $dob || $rowSex !== $sex) {
            continue;
        }
        $candidates[$rowPid] = true;
    }

    if (count($candidates) !== 1) {
        return null;
    }

    return (int) array_key_first($candidates);
}

/**
 * @param array<string, mixed> $extractedFacts
 * @param list<string> $fieldCandidates
 */
function ccpHasNonEmptyField(array $extractedFacts, array $fieldCandidates): bool
{
    $normalizedCandidates = array_map(static fn(string $candidate): string => ccpNormalizeFieldName($candidate), $fieldCandidates);
    $flat = ccpFlattenArray($extractedFacts);
    foreach ($flat as $path => $value) {
        $lastDot = strrpos($path, '.');
        $fieldName = $lastDot === false ? $path : substr($path, $lastDot + 1);
        if (!in_array(ccpNormalizeFieldName($fieldName), $normalizedCandidates, true)) {
            continue;
        }
        if (is_scalar($value) && trim((string) $value) !== '') {
            return true;
        }
    }
    return false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $logger = ServiceContainer::getLogger();
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
    $hasName = ccpHasNonEmptyField($extractedFacts, [
        'name',
        'full_name',
        'patient_name',
        'first_name',
        'last_name',
        'fname',
        'lname',
        'patient_first_name',
        'patient_last_name',
    ]);
    $hasGender = ccpHasNonEmptyField($extractedFacts, ['gender', 'sex']);
    $hasDob = ccpHasNonEmptyField($extractedFacts, ['date_of_birth', 'dob', 'birth_date']);
    if (!$hasName || !$hasGender || !$hasDob) {
        http_response_code(422);
        echo json_encode(['error' => 'Unable to map data to a patient. Extraction must include non-empty patient identity fields for name (full, first, or last), gender, and date_of_birth.']);
        exit;
    }

    $pid = (int) trim((string) ($session->get('pid') ?? '0'));
    if ($pid <= 0) {
        $resolvedPid = ccpResolvePidFromExtractedFacts($extractedFacts);
        if ($resolvedPid !== null && $resolvedPid > 0) {
            $pid = $resolvedPid;
            $session->set('pid', (string) $pid);
        }
    }
    if ($pid <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No patient selected in session and unable to uniquely resolve patient from extracted identity fields.']);
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

    $saveWarning = null;
    try {
        $repository = new ClinicalCopilotExtractedDataRepository();
        $repository->saveConfirmedExtraction(
            $pid,
            $encounter,
            $docType,
            $fileName,
            $authUserId,
            $extractedFacts
        );
    } catch (\Throwable $throwable) {
        $saveWarning = 'Failed to persist extracted-data audit row; continuing with patient record apply.';
        $logger->warning('clinical_copilot_save_extracted_audit_failed', [
            'pid' => $pid,
            'encounter' => $encounter,
            'doc_type' => $docType,
            'source_file_name' => $fileName,
            'exception' => $throwable,
        ]);
    }
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

    echo json_encode(['ok' => true, 'applied' => $applyResult, 'warning' => $saveWarning], JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    http_response_code(500);
    echo json_encode(['error' => 'Encoding error']);
} catch (\Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to persist extracted data']);
}
