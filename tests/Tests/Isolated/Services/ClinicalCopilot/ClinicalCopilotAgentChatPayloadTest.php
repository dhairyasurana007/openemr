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
    public function testToAgentJsonIncludesSurfaceAndCallerContext(): void
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
        $this->assertIsArray($json['caller_context']);
        $this->assertSame('UC2', $json['caller_context']['use_case']);
        $this->assertSame('p-uuid-1', $json['caller_context']['patient_uuid']);
        $this->assertSame('42', $json['caller_context']['encounter_id']);
    }

    public function testSchedulePayloadIncludesSlotIds(): void
    {
        $p = new ClinicalCopilotAgentChatPayload(
            message: 'day',
            useCase: ClinicalCopilotUseCase::UC1,
            scheduleDate: '2026-05-03',
            authorizedSlotIds: ['10', '11'],
        );
        $json = $p->toAgentJsonArray();
        $this->assertSame('schedule_day', $json['surface']);
        $this->assertSame(['10', '11'], $json['caller_context']['authorized_slot_ids']);
    }
}
