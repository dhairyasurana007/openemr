<?php

/**
 * Persists metadata-only rows to `ai_audit_log` (no prompt or reply text).
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

final class ClinicalCopilotAiAuditRepository
{
    public const TABLE_NAME = 'ai_audit_log';

    /**
     * Best-effort insert; failures are swallowed so co-pilot chat remains available if the table is missing.
     *
     * @param non-empty-string $eventKind
     * @param non-empty-string $outcome
     */
    public function recordAgentChatMetadata(
        ClinicalCopilotAgentChatAuditBinding $who,
        ClinicalCopilotUseCase $useCase,
        string $eventKind,
        string $outcome,
        int $latencyMs,
        ?int $httpStatus,
        ?string $errorClass,
    ): void {
        $surface = $useCase->agentSurface();
        $sql = 'INSERT INTO `' . self::TABLE_NAME . '` (`user_id`, `use_case`, `surface`, `pid`, `encounter`,'
            . ' `event_kind`, `outcome`, `http_status`, `latency_ms`, `error_class`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        try {
            QueryUtils::sqlStatementThrowException($sql, [
                $who->userId,
                $useCase->value,
                $surface,
                $who->pid,
                $who->encounter,
                $eventKind,
                $outcome,
                $httpStatus,
                $latencyMs,
                $errorClass,
            ]);
        } catch (SqlQueryException) {
            // Table may not exist on older DBs; avoid impacting the clinical path.
        }
    }
}
