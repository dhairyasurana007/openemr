<?php

/**
 * Finds the logged-in provider's current or imminently starting patient appointment (calendar).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use DateInterval;
use DateTimeImmutable;
use OpenEMR\Core\OEGlobalsBag;

final class ClinicalCopilotLoginAppointmentSummaryResolver
{
    private const CANCELLED_APPT_STATUSES = ['x', '%'];

    /**
     * @param list<array<string, mixed>> $events Rows from {@see fetchAppointments()} / {@see fetchEvents()}.
     */
    public function findForProvider(int $authUserId, DateTimeImmutable $now): ?ClinicalCopilotLoginAppointmentMatch
    {
        if ($authUserId <= 0) {
            return null;
        }

        $from = $now->modify('-1 day')->format('Y-m-d');
        $to = $now->modify('+1 day')->format('Y-m-d');
        /** @var list<array<string, mixed>> $events */
        $events = fetchAppointments($from, $to, null, (string) $authUserId);

        $defaultDur = $this->defaultDurationSeconds();

        return self::selectMatchingEvent($events, $now, 300, $defaultDur);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    public static function selectMatchingEvent(
        array $events,
        DateTimeImmutable $now,
        int $lookaheadSeconds,
        int $defaultDurationSeconds,
    ): ?ClinicalCopilotLoginAppointmentMatch {
        if ($lookaheadSeconds < 0) {
            $lookaheadSeconds = 0;
        }
        if ($defaultDurationSeconds <= 0) {
            $defaultDurationSeconds = 900;
        }

        $deadline = $now->add(new DateInterval('PT' . $lookaheadSeconds . 'S'));

        /** @var list<array{start: DateTimeImmutable, end: DateTimeImmutable, pid: int, title: string}> $current */
        $current = [];
        /** @var list<array{start: DateTimeImmutable, end: DateTimeImmutable, pid: int, title: string}> $upcoming */
        $upcoming = [];

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $bounds = self::eventBounds($ev, $defaultDurationSeconds);
            if ($bounds === null) {
                continue;
            }
            [$start, $end, $pid, $title] = $bounds;

            if ($start <= $now && $now < $end) {
                $current[] = ['start' => $start, 'end' => $end, 'pid' => $pid, 'title' => $title];
            } elseif ($start > $now && $start <= $deadline) {
                $upcoming[] = ['start' => $start, 'end' => $end, 'pid' => $pid, 'title' => $title];
            }
        }

        $pick = static function (array $list): ?ClinicalCopilotLoginAppointmentMatch {
            if ($list === []) {
                return null;
            }
            usort($list, static function (array $a, array $b): int {
                return $a['start'] <=> $b['start'];
            });
            $c = $list[0];
            return new ClinicalCopilotLoginAppointmentMatch(
                patientPid: $c['pid'],
                appointmentStart: $c['start'],
                appointmentEnd: $c['end'],
                appointmentTitle: $c['title'],
            );
        };

        return $pick($current) ?? $pick($upcoming);
    }

    public function defaultDurationSeconds(): int
    {
        $bag = OEGlobalsBag::getInstance();
        if ($bag->has('calendar_interval')) {
            $m = (int) $bag->get('calendar_interval');
            if ($m > 0) {
                return $m * 60;
            }
        }

        return 900;
    }

    /**
     * @param array<string, mixed> $ev
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: int, 3: string}|null
     */
    private static function eventBounds(array $ev, int $defaultDurationSeconds): ?array
    {
        $status = (string) ($ev['pc_apptstatus'] ?? '');
        if (in_array($status, self::CANCELLED_APPT_STATUSES, true)) {
            return null;
        }

        $pid = (int) ($ev['pid'] ?? $ev['pc_pid'] ?? 0);
        if ($pid <= 0) {
            return null;
        }

        $date = trim((string) ($ev['pc_eventDate'] ?? ''));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $timeRaw = trim((string) ($ev['pc_startTime'] ?? ''));
        if ($timeRaw === '') {
            $timeRaw = '00:00:00';
        }
        if (strlen($timeRaw) === 5) {
            $timeRaw .= ':00';
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $timeRaw);
        if ($start === false) {
            return null;
        }

        $dur = (int) ($ev['pc_duration'] ?? 0);
        if ($dur <= 0) {
            $dur = $defaultDurationSeconds;
        }

        $end = $start->add(new DateInterval('PT' . $dur . 'S'));
        $title = trim((string) ($ev['pc_title'] ?? ''));

        return [$start, $end, $pid, $title];
    }
}
