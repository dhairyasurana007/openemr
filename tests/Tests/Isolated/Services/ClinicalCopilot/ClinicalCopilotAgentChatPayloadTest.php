<?php

/**
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\ClinicalCopilot;

use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotAgentChatPayload;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotUseCase;
use PHPUnit\Framework\TestCase;

class ClinicalCopilotAgentChatPayloadTest extends TestCase
{
    public function testToAgentJsonIncludesSurfaceAndPatientId(): void
    {
        $p = new ClinicalCopilotAgentChatPayload(
            message: 'hello',
            useCase: ClinicalCopilotUseCase::UC2,
            patientUuid: 'p-uuid-1',
            encounterId: '42',
        );
        $json = $p->toAgentJsonArray();
        $this->assertSame('hello', $json['message']);
        $this->assertSame('encounter', $json['surface']);
        $this->assertSame('p-uuid-1', $json['patient_id']);
        $this->assertArrayNotHasKey('caller_context', $json);
    }

    public function testSchedulePayloadDoesNotIncludeCallerContext(): void
    {
        $p = new ClinicalCopilotAgentChatPayload(
            message: 'day',
            useCase: ClinicalCopilotUseCase::UC1,
            scheduleDate: '2026-05-03',
            authorizedSlotIds: ['10', '11'],
        );
        $json = $p->toAgentJsonArray();
        $this->assertSame('schedule_day', $json['surface']);
        $this->assertArrayNotHasKey('caller_context', $json);
    }
}
