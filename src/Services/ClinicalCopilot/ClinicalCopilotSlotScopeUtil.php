<?php

/**
 * Pure helpers for schedule slot identity sets (no database or service layer).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

final class ClinicalCopilotSlotScopeUtil
{
    /**
     * @param list<string> $master
     * @param list<string> $claimedSubset
     * @return list<string>
     */
    public static function orderedAuthorizedSlotIds(array $master, array $claimedSubset): array
    {
        if ($claimedSubset === []) {
            return [];
        }

        $want = [];
        foreach ($claimedSubset as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $want[$id] = true;
        }
        $out = [];
        foreach ($master as $id) {
            if (isset($want[$id])) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
