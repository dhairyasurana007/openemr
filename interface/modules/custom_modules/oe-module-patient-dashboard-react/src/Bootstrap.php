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

use OpenEMR\Events\UserInterface\PageHeadingRenderEvent;
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
        $this->eventDispatcher->addListener(PageHeadingRenderEvent::EVENT_PAGE_HEADING_RENDER, $this->addDashboardShortcut(...), 5);
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

    public function addDashboardShortcut(PageHeadingRenderEvent $event): PageHeadingRenderEvent
    {
        if ($event->getPageId() !== 'core.mrd') {
            return $event;
        }

        $pid = (int)($_GET['pid'] ?? $_GET['set_pid'] ?? 0);
        $url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
        if ($pid > 0) {
            $url .= '?pid=' . rawurlencode((string)$pid);
        }

        $button = '<a class="btn btn-outline-primary btn-sm ml-2" href="' . attr($url) . '">' . xlt('Open Modern Dashboard') . '</a>';
        $event->appendTitleNavContent($button);

        return $event;
    }
}
