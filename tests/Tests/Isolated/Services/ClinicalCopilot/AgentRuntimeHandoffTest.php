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
use PHPUnit\Framework\TestCase;

class AgentRuntimeHandoffTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_AGENT_BASE_URL');
        putenv('CLINICAL_COPILOT_AGENT_PUBLIC_URL');
        parent::tearDown();
    }

    public function testEmptyWhenEnvUnset(): void
    {
        putenv('CLINICAL_COPILOT_AGENT_BASE_URL');
        putenv('CLINICAL_COPILOT_AGENT_PUBLIC_URL');
        $h = AgentRuntimeHandoff::fromEnvironment();
        $this->assertSame('', $h->privateAgentBaseUrl);
        $this->assertSame('', $h->browserAgentBaseUrl);
        $this->assertFalse($h->isConfigured());
    }

    public function testPrivateOnlySetsBrowserToPrivate(): void
    {
        putenv('CLINICAL_COPILOT_AGENT_BASE_URL=http://copilot-agent:8000/');
        putenv('CLINICAL_COPILOT_AGENT_PUBLIC_URL');
        $h = AgentRuntimeHandoff::fromEnvironment();
        $this->assertSame('http://copilot-agent:8000', $h->privateAgentBaseUrl);
        $this->assertSame('http://copilot-agent:8000', $h->browserAgentBaseUrl);
        $this->assertTrue($h->isConfigured());
    }

    public function testPublicOverridesBrowserUrl(): void
    {
        putenv('CLINICAL_COPILOT_AGENT_BASE_URL=http://copilot-agent:8000');
        putenv('CLINICAL_COPILOT_AGENT_PUBLIC_URL=https://agent.example.com/api/');
        $h = AgentRuntimeHandoff::fromEnvironment();
        $this->assertSame('http://copilot-agent:8000', $h->privateAgentBaseUrl);
        $this->assertSame('https://agent.example.com/api', $h->browserAgentBaseUrl);
    }
}
