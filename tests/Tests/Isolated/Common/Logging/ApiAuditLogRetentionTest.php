<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Logging;

use OpenEMR\Common\Logging\ApiAuditLogRetention;
use PHPUnit\Framework\TestCase;

class ApiAuditLogRetentionTest extends TestCase
{
    public function testPurgeOlderThanDaysRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiAuditLogRetention::purgeOlderThanDays(0);
    }
}
