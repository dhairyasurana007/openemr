<?php

/**
 * Persistence for Clinical Co-Pilot encounter clinician intake completion and UC2 briefing cache.
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

final class ClinicalCopilotEncounterStateRepository
{
    private const TABLE = 'clinical_copilot_encounter_state';

    /**
     * Ensures a state row exists so UC2 status updates can run before clinician intake is recorded.
     *
     * @throws SqlQueryException
     */
    public function ensureEncounterRow(int $pid, int $encounter): void
    {
        $sql = 'INSERT INTO `' . self::TABLE . '` (`pid`, `encounter`) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE `pid` = `pid`';
        QueryUtils::sqlStatementThrowException($sql, [$pid, $encounter]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRow(int $pid, int $encounter): ?array
    {
        $sql = 'SELECT `pid`, `encounter`, `intake_completed_at`, `intake_completed_user`, `uc2_pregen_status`,'
            . ' `uc2_pregen_error`, `uc2_briefing_cached`, `uc2_pregen_updated_at` FROM `' . self::TABLE . '`'
            . ' WHERE `pid` = ? AND `encounter` = ? LIMIT 1';
        $rows = QueryUtils::fetchRecords($sql, [$pid, $encounter]);

        return $rows[0] ?? null;
    }

    /**
     * First completion wins for intake timestamp; user stored once.
     *
     * @throws SqlQueryException
     */
    public function recordIntakeComplete(int $pid, int $encounter, string $username): void
    {
        $sql = 'INSERT INTO `' . self::TABLE . '` (`pid`, `encounter`, `intake_completed_at`, `intake_completed_user`)'
            . ' VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE'
            . ' `intake_completed_at` = IFNULL(`intake_completed_at`, VALUES(`intake_completed_at`)),'
            . ' `intake_completed_user` = IFNULL(`intake_completed_user`, VALUES(`intake_completed_user`))';
        QueryUtils::sqlStatementThrowException($sql, [$pid, $encounter, $username]);
    }

    /**
     * @throws SqlQueryException
     */
    public function markUc2Pending(int $pid, int $encounter): void
    {
        $sql = 'UPDATE `' . self::TABLE . '` SET `uc2_pregen_status` = ?, `uc2_pregen_updated_at` = NOW()'
            . ' WHERE `pid` = ? AND `encounter` = ?';
        QueryUtils::sqlStatementThrowException($sql, ['pending', $pid, $encounter]);
    }

    /**
     * @throws SqlQueryException
     */
    public function markUc2Complete(int $pid, int $encounter, string $briefingText): void
    {
        $sql = 'UPDATE `' . self::TABLE . '` SET `uc2_pregen_status` = ?, `uc2_briefing_cached` = ?,'
            . ' `uc2_pregen_error` = NULL, `uc2_pregen_updated_at` = NOW() WHERE `pid` = ? AND `encounter` = ?';
        QueryUtils::sqlStatementThrowException($sql, ['complete', $briefingText, $pid, $encounter]);
    }

    /**
     * @throws SqlQueryException
     */
    public function markUc2Failed(int $pid, int $encounter, string $errorSummary): void
    {
        $sql = 'UPDATE `' . self::TABLE . '` SET `uc2_pregen_status` = ?, `uc2_pregen_error` = ?,'
            . ' `uc2_pregen_updated_at` = NOW() WHERE `pid` = ? AND `encounter` = ?';
        QueryUtils::sqlStatementThrowException($sql, ['failed', $errorSummary, $pid, $encounter]);
    }
}
