<?php

/**
 * Builds {@see ClinicalCopilotAgentChatPayload} from an authenticated browser JSON POST (session-bound).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\PatientService;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ClinicalCopilotWebChatComposer
{
    public function __construct(
        private readonly EncounterService $encounterService = new EncounterService(),
        private readonly PatientService $patientService = new PatientService(),
        private readonly ClinicalCopilotScheduleSlotAuthorizer $scheduleSlotAuthorizer = new ClinicalCopilotScheduleSlotAuthorizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function compose(SessionInterface $session, array $payload, string $message): ClinicalCopilotAgentChatPayload
    {
        $useCase = ClinicalCopilotUseCase::tryParse(isset($payload['use_case']) ? (string) $payload['use_case'] : null)
            ?? ClinicalCopilotUseCase::UC4;

        if ($useCase->isScheduleScoped()) {
            return $this->composeSchedule($message, $useCase, $payload);
        }

        return $this->composeEncounter($session, $message, $useCase, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function composeSchedule(string $message, ClinicalCopilotUseCase $useCase, array $payload): ClinicalCopilotAgentChatPayload
    {
        if (!AclMain::aclCheckCore('patients', 'appt')) {
            throw new AccessDeniedHttpException('Not authorized for schedule');
        }

        $date = isset($payload['schedule_date']) ? trim((string) $payload['schedule_date']) : '';
        $this->assertIsoYmd($date);

        $master = $this->scheduleSlotAuthorizer->authorizedSlotIdsForDate($date);
        $slotFilter = $this->parseOptionalSlotIds($payload['slot_ids'] ?? null);
        $authorized = $slotFilter === []
            ? $master
            : $this->scheduleSlotAuthorizer->filterToAuthorizedSubset($master, $slotFilter);
        if ($slotFilter !== [] && $authorized === []) {
            throw new BadRequestHttpException('slot_ids must be a subset of authorized slots for schedule_date');
        }

        return new ClinicalCopilotAgentChatPayload(
            message: $message,
            useCase: $useCase,
            scheduleDate: $date,
            authorizedSlotIds: $authorized,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function composeEncounter(SessionInterface $session, string $message, ClinicalCopilotUseCase $useCase, array $payload): ClinicalCopilotAgentChatPayload
    {
        if (!AclMain::aclCheckCore('encounters', 'notes') && !AclMain::aclCheckCore('encounters', 'notes_a')) {
            throw new AccessDeniedHttpException('Not authorized for encounter documentation');
        }

        $pid = trim((string) $session->get('pid'));
        if ($pid === '' || $pid === '0') {
            throw new BadRequestHttpException('No patient selected in session');
        }

        $encounterId = isset($payload['encounter_id']) ? trim((string) $payload['encounter_id']) : '';
        if ($encounterId === '') {
            $encounterId = trim((string) ($session->get('encounter') ?? ''));
        }
        if ($encounterId === '' || $encounterId === '0') {
            throw new BadRequestHttpException('Missing encounter_id');
        }

        $encRow = $this->encounterService->getOneByPidEid($pid, $encounterId);
        if ($encRow === []) {
            throw new AccessDeniedHttpException('Encounter not available for current patient');
        }

        $binUuid = $this->patientService->getUuid($pid);
        if ($binUuid === false || $binUuid === '') {
            throw new BadRequestHttpException('Unable to resolve patient identifier');
        }

        $puuid = UuidRegistry::uuidToString($binUuid);

        return new ClinicalCopilotAgentChatPayload(
            message: $message,
            useCase: $useCase,
            patientUuid: $puuid,
            encounterId: $encounterId,
        );
    }

    private function assertIsoYmd(string $value): void
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('Invalid schedule_date (expected YYYY-MM-DD)');
        }
    }

    /**
     * @return list<string>
     */
    private function parseOptionalSlotIds(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new BadRequestHttpException('slot_ids must be a JSON array of strings');
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_int($item)) {
                throw new BadRequestHttpException('slot_ids must contain only string or integer identifiers');
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }
}
