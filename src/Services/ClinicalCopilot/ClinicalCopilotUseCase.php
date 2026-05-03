<?php

/**
 * Clinical Co-Pilot use cases (UC1–UC7) with HTTP client timeouts aligned to product SLOs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

/**
 * @phpstan-type AgentSurface 'encounter'|'schedule_day'|'uc5_draft'
 */
enum ClinicalCopilotUseCase: string
{
    case UC1 = 'UC1';
    case UC2 = 'UC2';
    case UC3 = 'UC3';
    case UC4 = 'UC4';
    case UC5 = 'UC5';
    case UC6 = 'UC6';
    case UC7 = 'UC7';

    /**
     * Guzzle/OpenEMR→agent HTTP timeout (seconds). Matches encounter/schedule SLO ceilings plus buffer.
     */
    public function agentHttpTimeoutSeconds(): float
    {
        return match ($this) {
            self::UC1 => 22.0,
            self::UC2 => 6.0,
            self::UC3 => 9.0,
            self::UC4 => 9.0,
            self::UC5 => 11.0,
            self::UC6 => 16.0,
            self::UC7 => 16.0,
        };
    }

    /**
     * Browser fetch abort aligned with {@see self::agentHttpTimeoutSeconds()} plus small proxy margin.
     */
    public function browserFetchTimeoutMs(): int
    {
        return (int) (($this->agentHttpTimeoutSeconds() + 3.0) * 1000);
    }

    /**
     * @return AgentSurface
     */
    public function agentSurface(): string
    {
        return match ($this) {
            self::UC1, self::UC6, self::UC7 => 'schedule_day',
            self::UC5 => 'uc5_draft',
            self::UC2, self::UC3, self::UC4 => 'encounter',
        };
    }

    public function isScheduleScoped(): bool
    {
        return match ($this) {
            self::UC1, self::UC6, self::UC7 => true,
            default => false,
        };
    }

    public static function tryParse(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $upper = strtoupper(trim($raw));

        return match ($upper) {
            'UC1' => self::UC1,
            'UC2' => self::UC2,
            'UC3' => self::UC3,
            'UC4' => self::UC4,
            'UC5' => self::UC5,
            'UC6' => self::UC6,
            'UC7' => self::UC7,
            default => null,
        };
    }
}
