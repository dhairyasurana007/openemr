<?php

/**
 * React Patient Dashboard Module Bootstrap Class
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Codex <noreply@example.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\PatientDashboardReact;

use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    private const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-patient-dashboard-react';

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();

        foreach ($menu as $item) {
            if ($item->menu_id === 'misimg') {
                $dashboardItem = new stdClass();
                $dashboardItem->requirement = 0;
                $dashboardItem->target = 'mod0';
                $dashboardItem->menu_id = 'patdashreact0';
                $dashboardItem->label = xlt('Modern Patient Dashboard');
                $dashboardItem->url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
                $dashboardItem->children = [];
                $dashboardItem->acl_req = ['patients', 'demo'];
                $dashboardItem->global_req = [];

                $item->children[] = $dashboardItem;
                break;
            }
        }

        $event->setMenu($menu);
        return $event;
    }
}
