<?php

/**
 * Server-side agent URL handoff for the Clinical Co-Pilot module and future UI.
 *
 * Reads deployment environment variables (Compose / Render / PaaS); no globals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

/**
 * Immutable snapshot of agent base URLs for PHP→agent and browser→agent paths.
 */
final readonly class AgentRuntimeHandoff
{
    private function __construct(
        public string $privateAgentBaseUrl,
        public string $browserAgentBaseUrl,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $private = self::normalizeBaseUrl(self::readEnv('CLINICAL_COPILOT_AGENT_BASE_URL'));
        $public = self::normalizeBaseUrl(self::readEnv('CLINICAL_COPILOT_AGENT_PUBLIC_URL'));
        $browser = $public !== '' ? $public : $private;

        return new self(
            privateAgentBaseUrl: $private,
            browserAgentBaseUrl: $browser,
        );
    }

    public function isConfigured(): bool
    {
        return $this->privateAgentBaseUrl !== '';
    }

    private static function readEnv(string $name): string
    {
        $raw = getenv($name);
        if ($raw === false) {
            return '';
        }

        return trim((string) $raw);
    }

    private static function normalizeBaseUrl(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        return rtrim($raw, '/');
    }
}
