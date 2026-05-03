<?php

/**
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\ClinicalCopilot;

use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotSlotScopeUtil;
use PHPUnit\Framework\TestCase;

class ClinicalCopilotSlotScopeUtilTest extends TestCase
{
    public function testOrderedAuthorizedSlotIdsPreservesMasterOrder(): void
    {
        $master = ['3', '1', '2'];
        $this->assertSame(
            ['1', '2'],
            ClinicalCopilotSlotScopeUtil::orderedAuthorizedSlotIds($master, ['2', '1', '99'])
        );
    }
}
