<?php
/**
 * DLE Social AutoPost - Audit & Debug Logger
 *
 * Logs successful sends, retries, and errors to the database and PHP error log.
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

class AutopostLogger
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR   = 'error';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Record an autopost attempt in the database log.
     */
    public static function log(
        int $newsId,
        string $channel,
        string $status,
        ?string $remoteId = null,
        ?string $errorMessage = null,
        ?array $context = null
    ): void {
        global $db;

        if (!$db) {
            return;
        }

        $newsId = (int)$newsId;
        $channel = $db->safesql(substr($channel, 0, 32));
        $status = $db->safesql(substr($status, 0, 16));
        $remoteId = $remoteId !== null ? "'" . $db->safesql(substr($remoteId, 0, 128)) . "'" : "NULL";
        $errorMessage = $errorMessage !== null ? "'" . $db->safesql(substr($errorMessage, 0, 1000)) . "'" : "NULL";

        $db->query(
            "INSERT INTO " . PREFIX . "_autopost_log 
            (news_id, channel, remote_id, status, error_message, post_date) 
            VALUES 
            ({$newsId}, '{$channel}', {$remoteId}, '{$status}', {$errorMessage}, NOW())"
        );

        // Auto-clean old logs if table grows too large
        self::trimLogs();
    }

    /**
     * Helper to log an error.
     */
    public static function error(int $newsId, string $channel, string $errorMessage, ?array $context = null): void
    {
        self::log($newsId, $channel, self::STATUS_ERROR, null, $errorMessage, $context);
    }

    /**
     * Helper to log success.
     */
    public static function success(int $newsId, string $channel, ?string $remoteId = null, ?array $context = null): void
    {
        self::log($newsId, $channel, self::STATUS_SUCCESS, $remoteId, null, $context);
    }

    /**
     * Get recent logs for admin panel display.
     */
    public static function getRecent(int $limit = 100, int $offset = 0, ?string $statusFilter = null): array
    {
        global $db;

        if (!$db) {
            return [];
        }

        $limit = max(1, min(500, (int)$limit));
        $offset = max(0, (int)$offset);

        $where = "1=1";
        if ($statusFilter && in_array($statusFilter, [self::STATUS_SUCCESS, self::STATUS_ERROR, self::STATUS_SKIPPED], true)) {
            $where .= " AND l.status = '" . $db->safesql($statusFilter) . "'";
        }

        $query = "SELECT l.*, p.title as news_title 
                  FROM " . PREFIX . "_autopost_log l 
                  LEFT JOIN " . PREFIX . "_post p ON p.id = l.news_id 
                  WHERE {$where} 
                  ORDER BY l.id DESC 
                  LIMIT {$offset}, {$limit}";

        $result = $db->query($query);
        $rows = [];
        while ($row = $db->get_row($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Count total log entries.
     */
    public static function countTotal(?string $statusFilter = null): int
    {
        global $db;

        if (!$db) {
            return 0;
        }

        $where = "1=1";
        if ($statusFilter && in_array($statusFilter, [self::STATUS_SUCCESS, self::STATUS_ERROR, self::STATUS_SKIPPED], true)) {
            $where .= " AND status = '" . $db->safesql($statusFilter) . "'";
        }

        $row = $db->super_query("SELECT COUNT(*) as total FROM " . PREFIX . "_autopost_log WHERE {$where}");
        return (int)($row['total'] ?? 0);
    }

    /**
     * Clear all log records.
     */
    public static function clear(): void
    {
        global $db;
        if ($db) {
            $db->query("TRUNCATE TABLE " . PREFIX . "_autopost_log");
        }
    }

    /**
     * Trim logs to the configured max limit to avoid table bloat.
     */
    private static function trimLogs(): void
    {
        global $db;

        if (!$db) {
            return;
        }

        // Randomly execute 1 in 20 requests
        if (random_int(1, 20) !== 1) {
            return;
        }

        $limit = (int)AutopostConfig::get('log_limit', 500);
        if ($limit <= 0) {
            $limit = 500;
        }

        $db->query(
            "DELETE FROM " . PREFIX . "_autopost_log 
             WHERE id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM " . PREFIX . "_autopost_log ORDER BY id DESC LIMIT {$limit}
                 ) AS tmp
             )"
        );
    }
}
