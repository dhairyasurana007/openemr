<?php

/**
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\ClinicalCopilot;

use OpenEMR\Services\ClinicalCopilot\AgentRuntimeHandoff;
use OpenEMR\Services\ClinicalCopilot\CopilotAgentChatBridge;
use PHPUnit\Framework\TestCase;

class CopilotAgentChatBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_AGENT_BASE_URL');
        putenv('CLINICAL_COPILOT_AGENT_PUBLIC_URL');
        parent::tearDown();
    }

    public function testForwardMessageRequiresAgentBaseUrl(): void
    {
        putenv('CLINICAL_COPILOT_AGENT_BASE_URL');
        putenv('CLINICAL_COPILOT_AGENT_PUBLIC_URL');
        $handoff = AgentRuntimeHandoff::fromEnvironment();
        $bridge = new CopilotAgentChatBridge();
        $this->expectException(\DomainException::class);
        $bridge->forwardMessage('hello', $handoff);
    }
}
