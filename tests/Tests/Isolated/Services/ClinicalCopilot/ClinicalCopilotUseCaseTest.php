<?php

/**
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\ClinicalCopilot;

use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotUseCase;
use PHPUnit\Framework\TestCase;

class ClinicalCopilotUseCaseTest extends TestCase
{
    public function testAgentHttpTimeoutsMatchSloCeilings(): void
    {
        $this->assertSame(22.0, ClinicalCopilotUseCase::UC1->agentHttpTimeoutSeconds());
        $this->assertSame(6.0, ClinicalCopilotUseCase::UC2->agentHttpTimeoutSeconds());
        $this->assertSame(9.0, ClinicalCopilotUseCase::UC4->agentHttpTimeoutSeconds());
        $this->assertSame(11.0, ClinicalCopilotUseCase::UC5->agentHttpTimeoutSeconds());
        $this->assertSame(16.0, ClinicalCopilotUseCase::UC6->agentHttpTimeoutSeconds());
    }

    public function testAgentSurfaceMapping(): void
    {
        $this->assertSame('schedule_day', ClinicalCopilotUseCase::UC1->agentSurface());
        $this->assertSame('encounter', ClinicalCopilotUseCase::UC2->agentSurface());
        $this->assertSame('uc5_draft', ClinicalCopilotUseCase::UC5->agentSurface());
    }

    public function testTryParseIsCaseInsensitive(): void
    {
        $this->assertSame(ClinicalCopilotUseCase::UC3, ClinicalCopilotUseCase::tryParse('uc3'));
        $this->assertNull(ClinicalCopilotUseCase::tryParse('UC99'));
    }
}
