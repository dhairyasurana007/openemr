<?php

/**
 * Canonical product SLO ceilings (seconds) for Clinical Co-Pilot UC1–UC7 and HTTP client margin.
 *
 * Used for monitoring thresholds and CI gates; OpenEMR→agent HTTP timeouts in
 * {@see ClinicalCopilotUseCase::agentHttpTimeoutSeconds()} must stay within
 * {@see self::PRODUCT_SLO_MAX_SECONDS} + {@see self::MAX_EXTRA_HTTP_SECONDS_OVER_SLO}.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

final class ClinicalCopilotSloBudgets
{
    /**
     * Maximum seconds allowed beyond the product SLO for a single OpenEMR→agent HTTP call.
     */
    public const MAX_EXTRA_HTTP_SECONDS_OVER_SLO = 2.0;

    public static function productSloMaxSeconds(ClinicalCopilotUseCase $useCase): float
    {
        return match ($useCase) {
            ClinicalCopilotUseCase::UC1 => 20.0,
            ClinicalCopilotUseCase::UC2 => 5.0,
            ClinicalCopilotUseCase::UC3 => 8.0,
            ClinicalCopilotUseCase::UC4 => 43.0,
            ClinicalCopilotUseCase::UC5 => 10.0,
            ClinicalCopilotUseCase::UC6 => 15.0,
            ClinicalCopilotUseCase::UC7 => 15.0,
        };
    }

    /**
     * Upper bound for agent HTTP timeout (seconds) implied by the product SLO plus transport margin.
     */
    public static function maxAllowedAgentHttpTimeoutSeconds(ClinicalCopilotUseCase $useCase): float
    {
        return self::productSloMaxSeconds($useCase) + self::MAX_EXTRA_HTTP_SECONDS_OVER_SLO;
    }

    /**
     * @return list<ClinicalCopilotUseCase>
     */
    public static function allUseCases(): array
    {
        return ClinicalCopilotUseCase::cases();
    }
}
