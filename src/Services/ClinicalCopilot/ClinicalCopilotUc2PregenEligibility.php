<?php

/**
 * Pure rules for idempotent UC2 pre-generation (encounter-scoped, not schedule UC1).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

/**
 * @phpstan-type StateRow array{
 *     uc2_pregen_status?: string,
 *     uc2_briefing_cached?: string|null,
 *     uc2_pregen_updated_at?: string|null
 * }
 */
final class ClinicalCopilotUc2PregenEligibility
{
    private const PENDING_STALE_SECONDS = 300;

    /**
     * Agent should run only when there is no usable cached briefing and no in-flight pending window.
     *
     * @param StateRow|null $row
     */
    public static function shouldInvokeAgent(?array $row, int $nowUnix): bool
    {
        if ($row === null) {
            return true;
        }
        $cached = trim((string) ($row['uc2_briefing_cached'] ?? ''));
        $status = (string) ($row['uc2_pregen_status'] ?? 'none');
        if ($status === 'complete' && $cached !== '') {
            return false;
        }
        if ($status === 'pending') {
            $updated = strtotime((string) ($row['uc2_pregen_updated_at'] ?? '')) ?: 0;
            if ($updated > 0 && ($nowUnix - $updated) < self::PENDING_STALE_SECONDS) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param StateRow|null $row
     */
    public static function hasCachedBriefing(?array $row): bool
    {
        if ($row === null) {
            return false;
        }
        $cached = trim((string) ($row['uc2_briefing_cached'] ?? ''));

        return $cached !== '' && (($row['uc2_pregen_status'] ?? '') === 'complete');
    }
}
