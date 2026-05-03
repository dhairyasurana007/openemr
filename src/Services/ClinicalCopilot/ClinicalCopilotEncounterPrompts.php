<?php

/**
 * Default user prompts for encounter-scoped Clinical Co-Pilot flows.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

final class ClinicalCopilotEncounterPrompts
{
    public const UC2_PREVISIT_FACTS = 'Summarize chief complaint, intake, and chart facts for this visit (facts only, no prioritization).';
}
