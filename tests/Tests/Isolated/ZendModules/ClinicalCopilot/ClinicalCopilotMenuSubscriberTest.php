<?php

/**
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\ZendModules\ClinicalCopilot;

use OpenEMR\Menu\MenuEvent;
use OpenEMR\Services\ClinicalCopilot\ClinicalCopilotMenuSubscriber;
use PHPUnit\Framework\TestCase;

class ClinicalCopilotMenuSubscriberTest extends TestCase
{
    public function testOnMenuUpdateAppendsCopilotEntry(): void
    {
        $subscriber = new ClinicalCopilotMenuSubscriber();
        $existing = new \stdClass();
        $existing->label = 'Test';
        $event = new MenuEvent([$existing]);
        $out = $subscriber->onMenuUpdate($event);
        $menu = $out->getMenu();
        $this->assertCount(2, $menu);
        $this->assertSame('cpl', $menu[1]->target);
        $this->assertStringContainsString('ClinicalCopilot/panel.php', (string) $menu[1]->url);
    }

    public function testOnMenuUpdateLeavesNonArrayUnchanged(): void
    {
        $subscriber = new ClinicalCopilotMenuSubscriber();
        $event = new MenuEvent(new \stdClass());
        $out = $subscriber->onMenuUpdate($event);
        $this->assertInstanceOf(\stdClass::class, $out->getMenu());
    }
}
