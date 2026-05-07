<?php

/**
 * JSON body for copilot-agent POST /v1/multimodal-chat.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

/**
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
            'use_rag' => true,
        ];

        if ($this->patientUuid !== null && $this->patientUuid !== '') {
            $out['patient_id'] = $this->patientUuid;
        }

        return $out;
    }
}
