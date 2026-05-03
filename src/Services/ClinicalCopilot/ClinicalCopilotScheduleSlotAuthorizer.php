<?php

/**
 * Resolves appointment slot identifiers the current user may reference for schedule-scoped co-pilot calls.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use OpenEMR\Services\AppointmentService;
use OpenEMR\Services\Search\DateSearchField;

final class ClinicalCopilotScheduleSlotAuthorizer
{
    public function __construct(
        private readonly AppointmentService $appointmentService = new AppointmentService(),
    ) {
    }

    /**
     * @return list<string> Stable slot keys (pc_eid) for the given calendar day.
     */
    public function authorizedSlotIdsForDate(string $dateYmd): array
    {
        $search = [
            'pc_eventDate' => new DateSearchField('pc_eventDate', ['eq' . $dateYmd], DateSearchField::DATE_TYPE_DATE),
        ];
        $processingResult = $this->appointmentService->search($search, true);
        if ($processingResult->hasErrors() || !$processingResult->hasData()) {
            return [];
        }

        $ids = [];
        foreach ($processingResult->getData() as $row) {
            $eid = isset($row['pc_eid']) ? (string) $row['pc_eid'] : '';
            if ($eid !== '') {
                $ids[] = $eid;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $claimedSubset
     * @return list<string> Intersection in stable order of $master
     */
    public function filterToAuthorizedSubset(array $master, array $claimedSubset): array
    {
        return ClinicalCopilotSlotScopeUtil::orderedAuthorizedSlotIds($master, $claimedSubset);
    }
}
