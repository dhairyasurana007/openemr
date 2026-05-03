<?php

/**
 * JSON body for copilot-agent POST /v1/chat (message, surface, optional caller binding).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

/**
 * @phpstan-type CallerContextArray array{
 *     use_case?: string,
 *     patient_uuid?: string,
 *     encounter_id?: string,
 *     schedule_date?: string,
 *     authorized_slot_ids?: list<string>
 * }
 */
final readonly class ClinicalCopilotAgentChatPayload
{
    /**
     * @param list<string>|null $authorizedSlotIds
     */
    public function __construct(
        public string $message,
        public ClinicalCopilotUseCase $useCase,
        public ?string $patientUuid = null,
        public ?string $encounterId = null,
        public ?string $scheduleDate = null,
        public ?array $authorizedSlotIds = null,
        public ?float $httpTimeoutOverrideSeconds = null,
    ) {
    }

    public function effectiveHttpTimeoutSeconds(): float
    {
        if ($this->httpTimeoutOverrideSeconds !== null && $this->httpTimeoutOverrideSeconds > 0) {
            return $this->httpTimeoutOverrideSeconds;
        }

        return $this->useCase->agentHttpTimeoutSeconds();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAgentJsonArray(): array
    {
        $out = [
            'message' => $this->message,
            'surface' => $this->useCase->agentSurface(),
        ];

        $ctx = $this->buildCallerContext();
        if ($ctx !== []) {
            $out['caller_context'] = $ctx;
        }

        return $out;
    }

    /**
     * @return CallerContextArray
     */
    private function buildCallerContext(): array
    {
        $ctx = [
            'use_case' => $this->useCase->value,
        ];
        if ($this->patientUuid !== null && $this->patientUuid !== '') {
            $ctx['patient_uuid'] = $this->patientUuid;
        }
        if ($this->encounterId !== null && $this->encounterId !== '') {
            $ctx['encounter_id'] = $this->encounterId;
        }
        if ($this->scheduleDate !== null && $this->scheduleDate !== '') {
            $ctx['schedule_date'] = $this->scheduleDate;
        }
        if ($this->authorizedSlotIds !== null && $this->authorizedSlotIds !== []) {
            $ctx['authorized_slot_ids'] = array_values($this->authorizedSlotIds);
        }

        return $ctx;
    }
}
