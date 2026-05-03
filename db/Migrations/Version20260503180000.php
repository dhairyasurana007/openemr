<?php

/**
 * Clinical Co-Pilot AI audit log (metadata only; no message bodies).
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_audit_log for Clinical Co-Pilot metadata audit trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ai_audit_log` (
  `id` bigint(20) NOT NULL auto_increment,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` bigint(20) NOT NULL DEFAULT 0,
  `use_case` varchar(8) NOT NULL DEFAULT '',
  `surface` varchar(32) NOT NULL DEFAULT '',
  `pid` int(11) NOT NULL DEFAULT 0,
  `encounter` int(11) DEFAULT NULL,
  `event_kind` varchar(64) NOT NULL DEFAULT 'agent_chat',
  `outcome` varchar(24) NOT NULL DEFAULT '',
  `http_status` int(11) DEFAULT NULL,
  `latency_ms` int(11) NOT NULL DEFAULT 0,
  `error_class` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ai_audit_created` (`created_at`),
  KEY `idx_ai_audit_use_case_created` (`use_case`, `created_at`),
  KEY `idx_ai_audit_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB COMMENT='Clinical co-pilot AI audit events (metadata; no message bodies)'
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `ai_audit_log`');
    }
}
