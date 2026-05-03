<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestControllers\ClinicalCopilot;

use OpenEMR\RestControllers\ClinicalCopilot\ClinicalCopilotRetrievalRestController;
use PHPUnit\Framework\TestCase;

class ClinicalCopilotRetrievalRestControllerTest extends TestCase
{
    public function testCitationShapeIsStable(): void
    {
        $c = ClinicalCopilotRetrievalRestController::citation('list_schedule_slots', 'schedule', '/api/clinical-copilot/retrieval/list-schedule-slots');
        $this->assertSame('openemr_rest', $c['type']);
        $this->assertSame('list_schedule_slots', $c['tool']);
        $this->assertSame('schedule', $c['domain']);
        $this->assertSame('GET', $c['method']);
        $this->assertSame('/api/clinical-copilot/retrieval/list-schedule-slots', $c['path']);
    }

    public function testSchemaVersionConstant(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', ClinicalCopilotRetrievalRestController::SCHEMA_VERSION);
    }

    public function testGetCalendarCitationPath(): void
    {
        $c = ClinicalCopilotRetrievalRestController::citation('get_calendar', 'calendar', '/api/clinical-copilot/retrieval/calendar');
        $this->assertSame('get_calendar', $c['tool']);
        $this->assertSame('/api/clinical-copilot/retrieval/calendar', $c['path']);
    }
}
