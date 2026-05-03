<?php

/**
 * Calendar appointment selected for login-time co-pilot auto-summary.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use DateTimeImmutable;

final readonly class ClinicalCopilotLoginAppointmentMatch
{
    public function __construct(
        public int $patientPid,
        public DateTimeImmutable $appointmentStart,
        public DateTimeImmutable $appointmentEnd,
        public string $appointmentTitle,
    ) {
    }
}
