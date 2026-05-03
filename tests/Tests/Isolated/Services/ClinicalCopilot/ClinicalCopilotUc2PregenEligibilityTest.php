<?php

/**
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\ClinicalCopilot;

use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotUc2PregenEligibility;
use PHPUnit\Framework\TestCase;

class ClinicalCopilotUc2PregenEligibilityTest extends TestCase
{
    public function testShouldInvokeWhenNoRow(): void
    {
        $this->assertTrue(ClinicalCopilotUc2PregenEligibility::shouldInvokeAgent(null, time()));
    }

    public function testShouldNotInvokeWhenCachedComplete(): void
    {
        $row = [
            'uc2_pregen_status' => 'complete',
            'uc2_briefing_cached' => 'Brief',
            'uc2_pregen_updated_at' => '2026-01-01 00:00:00',
        ];
        $this->assertFalse(ClinicalCopilotUc2PregenEligibility::shouldInvokeAgent($row, time()));
        $this->assertTrue(ClinicalCopilotUc2PregenEligibility::hasCachedBriefing($row));
    }

    public function testShouldNotInvokeWhenPendingFresh(): void
    {
        $now = 1_700_000_000;
        $row = [
            'uc2_pregen_status' => 'pending',
            'uc2_briefing_cached' => '',
            'uc2_pregen_updated_at' => gmdate('Y-m-d H:i:s', $now - 60),
        ];
        $this->assertFalse(ClinicalCopilotUc2PregenEligibility::shouldInvokeAgent($row, $now));
    }

    public function testShouldInvokeWhenPendingStale(): void
    {
        $now = 1_700_000_000;
        $row = [
            'uc2_pregen_status' => 'pending',
            'uc2_briefing_cached' => '',
            'uc2_pregen_updated_at' => gmdate('Y-m-d H:i:s', $now - 400),
        ];
        $this->assertTrue(ClinicalCopilotUc2PregenEligibility::shouldInvokeAgent($row, $now));
    }

    public function testHasCachedBriefingFalseWhenIncomplete(): void
    {
        $row = [
            'uc2_pregen_status' => 'failed',
            'uc2_briefing_cached' => '',
        ];
        $this->assertFalse(ClinicalCopilotUc2PregenEligibility::hasCachedBriefing($row));
    }
}
