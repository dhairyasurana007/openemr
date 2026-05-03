<?php

/**
 * Adds the Clinical Co-Pilot entry to the main application menu.
 *
 * Label is plain English; it is not passed through {@see xlt()} here because
 * this listener runs after {@see MenuRole::menuUpdateEntries()} and menu JSON
 * labels are translated in that pass only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ClinicalCopilotMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            MenuEvent::MENU_UPDATE => 'onMenuUpdate',
        ];
    }

    public function onMenuUpdate(MenuEvent $menu): MenuEvent
    {
        $items = $menu->getMenu();
        if (!is_array($items)) {
            return $menu;
        }

        $entry = new \stdClass();
        $entry->label = 'Clinical Co-Pilot';
        $entry->menu_id = 'cpl0';
        $entry->target = 'cpl';
        $entry->url = '/interface/modules/zend_modules/public/ClinicalCopilot/panel.php';
        $entry->children = [];
        $entry->requirement = 0;
        $entry->acl_req = ['patients', 'demo'];

        $items[] = $entry;
        $menu->setMenu($items);

        return $menu;
    }
}
