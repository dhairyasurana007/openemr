<?php

/**
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\ClinicalCopilot;

use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotSloBudgets;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotUseCase;
use PHPUnit\Framework\TestCase;

class ClinicalCopilotSloBudgetsTest extends TestCase
{
    public function testAgentHttpTimeoutsStayWithinSloPlusMargin(): void
    {
        foreach (ClinicalCopilotSloBudgets::allUseCases() as $uc) {
            $slo = ClinicalCopilotSloBudgets::productSloMaxSeconds($uc);
            $maxAllowed = ClinicalCopilotSloBudgets::maxAllowedAgentHttpTimeoutSeconds($uc);
            $configured = $uc->agentHttpTimeoutSeconds();
            self::assertGreaterThanOrEqual(
                $slo,
                $configured,
                $uc->value . ' HTTP timeout must cover the product SLO ceiling',
            );
            self::assertLessThanOrEqual(
                $maxAllowed,
                $configured,
                $uc->value . ' HTTP timeout must not exceed SLO plus configured margin (ops / alerting gate)',
            );
        }
    }

    public function testSloCeilingsMatchEncounterAndScheduleBudgets(): void
    {
        self::assertSame(20.0, ClinicalCopilotSloBudgets::productSloMaxSeconds(ClinicalCopilotUseCase::UC1));
        self::assertSame(5.0, ClinicalCopilotSloBudgets::productSloMaxSeconds(ClinicalCopilotUseCase::UC2));
        self::assertSame(8.0, ClinicalCopilotSloBudgets::productSloMaxSeconds(ClinicalCopilotUseCase::UC3));
        self::assertSame(43.0, ClinicalCopilotSloBudgets::productSloMaxSeconds(ClinicalCopilotUseCase::UC4));
        self::assertSame(10.0, ClinicalCopilotSloBudgets::productSloMaxSeconds(ClinicalCopilotUseCase::UC5));
        self::assertSame(15.0, ClinicalCopilotSloBudgets::productSloMaxSeconds(ClinicalCopilotUseCase::UC6));
        self::assertSame(15.0, ClinicalCopilotSloBudgets::productSloMaxSeconds(ClinicalCopilotUseCase::UC7));
    }
}
