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

use DateTimeImmutable;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;

final class ClinicalCopilotExtractedDataRepository
{
    private const TABLE = 'clinical_copilot_extracted_data';
    private ?bool $auditTableExists = null;

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
        if (!$this->isAuditTableAvailable()) {
            return;
        }

        $json = json_encode($extractedFacts, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $confirmedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = 'INSERT INTO ' . self::TABLE
            . ' (pid, encounter, doc_type, source_file_name, extracted_json, extracted_hash, confirmed_by_user_id, confirmed_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

        QueryUtils::sqlStatementThrowException($sql, [
            $pid,
            $encounter,
            $docType,
            $fileName,
            $json,
            $hash,
            $confirmedByUserId,
            $confirmedAt,
        ]);
    }

    private function isAuditTableAvailable(): bool
    {
        if ($this->auditTableExists !== null) {
            return $this->auditTableExists;
        }

        $row = QueryUtils::querySingleRow(
            'SELECT 1 AS present FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [self::TABLE]
        );
        $this->auditTableExists = is_array($row) && !empty($row['present']);
        return $this->auditTableExists;
    }
}
