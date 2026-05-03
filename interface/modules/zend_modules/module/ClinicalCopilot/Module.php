<?php

/**
 * Clinical Co-Pilot Laminas module shell (encounter / schedule UI wiring follows).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace ClinicalCopilot;

class Module
{
    public const NAMESPACE_NAME = 'ClinicalCopilot';

    /**
     * @return array<string, mixed>
     */
    public function getAutoloaderConfig(): array
    {
        return [
            \Laminas\Loader\StandardAutoloader::class => [
                'namespaces' => [
                    'OpenEMR\\ZendModules\\' . __NAMESPACE__ => __DIR__ . '/src/' . self::NAMESPACE_NAME,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
