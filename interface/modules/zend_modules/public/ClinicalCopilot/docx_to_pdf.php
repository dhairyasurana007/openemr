<?php

/**
 * DOCX -> PDF conversion endpoint for Clinical Co-Pilot source-overlay preview.
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

/**
 * @param list<string> $paths
 */
function ccpCleanupPaths(array $paths): void
{
    foreach ($paths as $path) {
        if ($path === '' || !file_exists($path)) {
            continue;
        }
        if (is_dir($path)) {
            @rmdir($path);
            continue;
        }
        @unlink($path);
    }
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$tmpDir = '';
$tmpDocx = '';
$tmpPdf = '';
try {
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
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

    if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'File upload error']);
        exit;
    }

    $originalName = (string) ($_FILES['file']['name'] ?? '');
    if (!str_ends_with(strtolower($originalName), '.docx')) {
        http_response_code(400);
        echo json_encode(['error' => 'Only .docx is supported']);
        exit;
    }

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ccp_docx_' . bin2hex(random_bytes(8));
    if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Failed to create conversion temp directory');
    }

    $tmpDocx = $tmpDir . DIRECTORY_SEPARATOR . 'input.docx';
    $tmpPdf = $tmpDir . DIRECTORY_SEPARATOR . 'input.pdf';

    $uploadedTmp = (string) ($_FILES['file']['tmp_name'] ?? '');
    if ($uploadedTmp === '' || !is_uploaded_file($uploadedTmp)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid uploaded file']);
        exit;
    }
    if (!move_uploaded_file($uploadedTmp, $tmpDocx)) {
        throw new RuntimeException('Failed to move uploaded docx');
    }

    // Convert with LibreOffice/soffice (must be available in PATH on server host).
    $cmd = 'soffice --headless --convert-to pdf --outdir '
        . escapeshellarg($tmpDir)
        . ' '
        . escapeshellarg($tmpDocx);
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    if ($exitCode !== 0 || !is_file($tmpPdf)) {
        http_response_code(500);
        echo json_encode(['error' => 'DOCX to PDF conversion failed']);
        ccpCleanupPaths([$tmpDocx, $tmpPdf, $tmpDir]);
        exit;
    }

    $pdfBytes = file_get_contents($tmpPdf);
    if ($pdfBytes === false || $pdfBytes === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to read converted PDF']);
        ccpCleanupPaths([$tmpDocx, $tmpPdf, $tmpDir]);
        exit;
    }

    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Cache-Control: no-store');
    echo $pdfBytes;
    ccpCleanupPaths([$tmpDocx, $tmpPdf, $tmpDir]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Conversion unavailable']);
    ccpCleanupPaths([$tmpDocx, $tmpPdf, $tmpDir]);
    exit;
}

