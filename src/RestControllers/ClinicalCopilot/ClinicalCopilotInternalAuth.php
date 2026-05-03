<?php

/**
 * Server-to-server authentication for Clinical Co-Pilot REST bridges.
 *
 * When the environment variable CLINICAL_COPILOT_INTERNAL_SECRET is non-empty,
 * every request to co-pilot retrieval routes must include the same value in
 * X-Clinical-Copilot-Internal-Secret (timing-safe compare). When unset or empty,
 * only the normal OAuth/API ACL checks apply (useful for local development).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers\ClinicalCopilot;

use OpenEMR\Common\Http\HttpRestRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ClinicalCopilotInternalAuth
{
    public const HEADER_NAME = 'X-Clinical-Copilot-Internal-Secret';

    /**
     * Enforce optional shared secret between agent (or PHP proxy) and OpenEMR.
     */
    public static function assertConfiguredSecretMatches(HttpRestRequest $request): void
    {
        $configured = self::readConfiguredSecret();
        if ($configured === '') {
            return;
        }

        $provided = (string) ($request->headers->get(self::HEADER_NAME) ?? '');
        if (!hash_equals($configured, $provided)) {
            throw new AccessDeniedHttpException('Clinical co-pilot internal authentication failed');
        }
    }

    private static function readConfiguredSecret(): string
    {
        $raw = getenv('CLINICAL_COPILOT_INTERNAL_SECRET');
        if ($raw === false || $raw === '') {
            return '';
        }

        return trim((string) $raw);
    }
}
