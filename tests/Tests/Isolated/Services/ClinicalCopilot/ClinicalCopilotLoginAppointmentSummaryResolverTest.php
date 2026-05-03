<?php

/**
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\ClinicalCopilot;

use DateTimeImmutable;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotLoginAppointmentSummaryResolver;
use PHPUnit\Framework\TestCase;

class ClinicalCopilotLoginAppointmentSummaryResolverTest extends TestCase
{
    public function testSelectsInProgressAppointment(): void
    {
        $now = new DateTimeImmutable('2026-05-03 10:15:00');
        $events = [[
            'pc_eventDate' => '2026-05-03',
            'pc_startTime' => '10:00:00',
            'pc_duration' => 3600,
            'pid' => 7,
            'pc_apptstatus' => '@',
            'pc_title' => 'Follow-up',
        ]];
        $m = ClinicalCopilotLoginAppointmentSummaryResolver::selectMatchingEvent($events, $now, 300, 900);
        $this->assertNotNull($m);
        $this->assertSame(7, $m->patientPid);
        $this->assertSame('Follow-up', $m->appointmentTitle);
    }

    public function testSelectsAppointmentStartingWithinLookahead(): void
    {
        $now = new DateTimeImmutable('2026-05-03 09:58:00');
        $events = [[
            'pc_eventDate' => '2026-05-03',
            'pc_startTime' => '10:00:00',
            'pc_duration' => 1800,
            'pid' => 8,
            'pc_apptstatus' => '^',
            'pc_title' => 'New patient',
        ]];
        $m = ClinicalCopilotLoginAppointmentSummaryResolver::selectMatchingEvent($events, $now, 300, 900);
        $this->assertNotNull($m);
        $this->assertSame(8, $m->patientPid);
    }

    public function testReturnsNullWhenNextAppointmentOutsideWindow(): void
    {
        $now = new DateTimeImmutable('2026-05-03 09:00:00');
        $events = [[
            'pc_eventDate' => '2026-05-03',
            'pc_startTime' => '10:30:00',
            'pc_duration' => 1800,
            'pid' => 9,
            'pc_apptstatus' => '@',
            'pc_title' => 'Later',
        ]];
        $m = ClinicalCopilotLoginAppointmentSummaryResolver::selectMatchingEvent($events, $now, 300, 900);
        $this->assertNull($m);
    }

    public function testSkipsCancelledAppointments(): void
    {
        $now = new DateTimeImmutable('2026-05-03 10:15:00');
        $events = [[
            'pc_eventDate' => '2026-05-03',
            'pc_startTime' => '10:00:00',
            'pc_duration' => 3600,
            'pid' => 10,
            'pc_apptstatus' => 'x',
            'pc_title' => 'Cancelled',
        ]];
        $m = ClinicalCopilotLoginAppointmentSummaryResolver::selectMatchingEvent($events, $now, 300, 900);
        $this->assertNull($m);
    }

    public function testPrefersInProgressOverUpcoming(): void
    {
        $now = new DateTimeImmutable('2026-05-03 10:20:00');
        $events = [
            [
                'pc_eventDate' => '2026-05-03',
                'pc_startTime' => '10:22:00',
                'pc_duration' => 1800,
                'pid' => 20,
                'pc_apptstatus' => '@',
                'pc_title' => 'Soon',
            ],
            [
                'pc_eventDate' => '2026-05-03',
                'pc_startTime' => '10:00:00',
                'pc_duration' => 3600,
                'pid' => 21,
                'pc_apptstatus' => '@',
                'pc_title' => 'Current',
            ],
        ];
        $m = ClinicalCopilotLoginAppointmentSummaryResolver::selectMatchingEvent($events, $now, 300, 900);
        $this->assertNotNull($m);
        $this->assertSame(21, $m->patientPid);
        $this->assertSame('Current', $m->appointmentTitle);
    }
}
