<?php

/**
 * Session-derived binding for {@see ClinicalCopilotAiAuditRepository} (no message bodies).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class ClinicalCopilotAgentChatAuditBinding
{
    public function __construct(
        public int $userId,
        public int $pid,
        public ?int $encounter,
    ) {
    }

    public static function fromSessionAndPayload(SessionInterface $session, ClinicalCopilotAgentChatPayload $payload): self
    {
        $userId = (int) ($session->get('authUserID') ?? 0);
        if ($payload->useCase->isScheduleScoped()) {
            return new self($userId, 0, null);
        }

        $pid = (int) ($session->get('pid') ?? 0);
        $encounter = null;
        if ($payload->encounterId !== null && $payload->encounterId !== '') {
            $encounter = (int) $payload->encounterId;
        } else {
            $fromSession = trim((string) ($session->get('encounter') ?? ''));
            if ($fromSession !== '' && $fromSession !== '0') {
                $encounter = (int) $fromSession;
            }
        }

        return new self($userId, $pid, $encounter);
    }
}
