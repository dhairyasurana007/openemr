<?php

/**
 * Persistence for clinician-confirmed structured extraction results.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;

final class ClinicalCopilotExtractedDataRepository
{
    private const TABLE = 'clinical_copilot_extracted_data';

    /**
     * @param array<string, mixed> $extractedFacts
     *
     * @throws SqlQueryException
     */
    public function saveConfirmedExtraction(
        int $pid,
        ?int $encounter,
        string $docType,
        string $fileName,
        int $confirmedByUserId,
        array $extractedFacts
    ): void {
        $json = json_encode($extractedFacts, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $sql = 'INSERT INTO `' . self::TABLE . '`'
            . ' (`pid`, `encounter`, `doc_type`, `source_file_name`, `extracted_json`, `extracted_hash`, `confirmed_by_user_id`, `confirmed_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, NOW())';

        QueryUtils::sqlStatementThrowException($sql, [
            $pid,
            $encounter,
            $docType,
            $fileName,
            $json,
            $hash,
            $confirmedByUserId,
        ]);
    }
}

