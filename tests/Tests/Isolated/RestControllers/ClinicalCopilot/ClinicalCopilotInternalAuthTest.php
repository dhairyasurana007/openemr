<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestControllers\ClinicalCopilot;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\ClinicalCopilot\ClinicalCopilotInternalAuth;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ClinicalCopilotInternalAuthTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_INTERNAL_SECRET');
        parent::tearDown();
    }

    public function testAllowsRequestWhenSecretNotConfigured(): void
    {
        putenv('CLINICAL_COPILOT_INTERNAL_SECRET=');
        $request = HttpRestRequest::create('/api/clinical-copilot/retrieval/list-schedule-slots', 'GET');
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        $this->addToAssertionCount(1);
    }

    public function testRejectsMismatchedSecret(): void
    {
        putenv('CLINICAL_COPILOT_INTERNAL_SECRET=expected-secret');
        $request = HttpRestRequest::create('/api/clinical-copilot/retrieval/list-schedule-slots', 'GET');
        $this->expectException(AccessDeniedHttpException::class);
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
    }

    public function testAcceptsMatchingHeader(): void
    {
        putenv('CLINICAL_COPILOT_INTERNAL_SECRET=expected-secret');
        $request = HttpRestRequest::create(
            '/api/clinical-copilot/retrieval/list-schedule-slots',
            'GET',
            [],
            [],
            [],
            ['HTTP_X_CLINICAL_COPILOT_INTERNAL_SECRET' => 'expected-secret']
        );
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        $this->addToAssertionCount(1);
    }
}
