<?php

/**
 * Clinical Co-Pilot module services (agent URL handoff for future controllers/views).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\ZendModules\ClinicalCopilot;

use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;
use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoffFactory;

return [
    'service_manager' => [
        'factories' => [
            AgentRuntimeHandoff::class => AgentRuntimeHandoffFactory::class,
        ],
    ],
];
