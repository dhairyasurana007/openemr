<?php

/**
 * React Patient Dashboard Module Bootstrap
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Codex <noreply@example.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\PatientDashboardReact\Bootstrap;

/**
 * @global \OpenEMR\Core\ModulesClassLoader $classLoader Injected by the OpenEMR module loader.
 */
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\PatientDashboardReact\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$eventDispatcher = OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher();
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();
