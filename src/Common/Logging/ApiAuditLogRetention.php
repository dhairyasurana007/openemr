<?php

/**
 * Deletes aged rows from API audit tables (log, log_comment_encrypt, api_log) for Track A retention.
 *
 * Intended for scheduled jobs when globals `api_audit_log_retention_days` is set; uses QueryUtils with noLog
 * to avoid recursive audit noise during purge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Common\Logging;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;

final class ApiAuditLogRetention
{
    private const CHUNK_SIZE = 500;

    /**
     * Remove API audit rows whose api_log.created_time is strictly older than the cutoff.
     *
     * @return int Number of api_log rows removed (same as associated log / log_comment_encrypt rows).
     *
     * @throws SqlQueryException
     * @throws \InvalidArgumentException
     */
    public static function purgeApiRequestLogsOlderThan(\DateTimeImmutable $cutoffExclusive): int
    {
        $cutoffStr = $cutoffExclusive->format('Y-m-d H:i:s');
        $rs = QueryUtils::sqlStatementThrowException(
            'SELECT DISTINCT `log_id` FROM `api_log` WHERE `created_time` < ?',
            [$cutoffStr],
            noLog: true
        );
        $logIds = [];
        while (($row = QueryUtils::fetchArrayFromResultSet($rs)) !== false) {
            $id = (int)($row['log_id'] ?? 0);
            if ($id > 0) {
                $logIds[] = $id;
            }
        }
        if ($logIds === []) {
            return 0;
        }
        $removed = count($logIds);
        foreach (array_chunk($logIds, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `api_log` WHERE `log_id` IN ({$placeholders})",
                $chunk,
                noLog: true
            );
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `log_comment_encrypt` WHERE `log_id` IN ({$placeholders})",
                $chunk,
                noLog: true
            );
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `log` WHERE `id` IN ({$placeholders})",
                $chunk,
                noLog: true
            );
        }

        return $removed;
    }

    /**
     * @throws SqlQueryException
     * @throws \InvalidArgumentException if $retentionDays is not positive
     */
    public static function purgeOlderThanDays(int $retentionDays): int
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('retentionDays must be at least 1');
        }
        $cutoff = (new \DateTimeImmutable('now'))->modify('-' . $retentionDays . ' days');

        return self::purgeApiRequestLogsOlderThan($cutoff);
    }
}
