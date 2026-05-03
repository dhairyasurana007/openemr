<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Session;

use OpenEMR\Common\Session\SessionConfigurationBuilder;
use PHPUnit\Framework\TestCase;

class SessionConfigurationBuilderTest extends TestCase
{
    public function testForApiEnablesSecureCookies(): void
    {
        $config = SessionConfigurationBuilder::forApi('/sites/default');
        $this->assertTrue($config['cookie_secure'], 'API sessions must require secure cookies (HTTPS-only delivery).');
        $this->assertTrue($config['cookie_httponly']);
    }

    public function testForOAuthUsesSameSiteNoneAndSecure(): void
    {
        $config = SessionConfigurationBuilder::forOAuth('/sites/default');
        $this->assertSame('None', $config['cookie_samesite']);
        $this->assertTrue($config['cookie_secure']);
    }

    public function testDefaultBuilderUsesStrictSameSite(): void
    {
        $config = (new SessionConfigurationBuilder())->build();
        $this->assertSame('Strict', $config['cookie_samesite']);
        $this->assertTrue($config['use_strict_mode']);
    }
}
