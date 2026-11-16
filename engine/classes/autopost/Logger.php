<?php
/**
 * DLE Social AutoPost Plugin
 * Database Logger
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

class Autopost_Logger
{
    /**
     * Add log record
     *
     * @param int         $newsId
     * @param string      $channel 'telegram' or 'vk'
     * @param string      $status 'success' or 'error'
     * @param string|null $remoteId ID of post or message
     * @param string|null $errorMessage
     * @return bool
     */
    public static function log($newsId, $channel, $status, $remoteId = null, $errorMessage = null)
    {
        global $db;

        $newsId = intval($newsId);
        $channel = in_array($channel, ['telegram', 'vk']) ? $channel : 'telegram';
        $status = ($status === 'success') ? 'success' : 'error';
        $remoteId = $remoteId ? $db->safesql(substr((string)$remoteId, 0, 100)) : null;
        $errorMessage = $errorMessage ? $db->safesql($errorMessage) : null;
        $now = date('Y-m-d H:i:s');

        $remoteIdSql = $remoteId ? "'{$remoteId}'" : "NULL";
        $errorSql = $errorMessage ? "'{$errorMessage}'" : "NULL";

        $sql = "INSERT INTO " . PREFIX . "_autopost_log 
                (`news_id`, `channel`, `remote_id`, `status`, `error_message`, `post_date`) 
                VALUES ('{$newsId}', '{$channel}', {$remoteIdSql}, '{$status}', {$errorSql}, '{$now}')";

        $db->query($sql);

        // Periodically trim old logs to avoid table bloat
        if (mt_rand(1, 20) === 1) {
            $config = Autopost_Config::get();
            $limit = intval($config['log_limit'] ?? 500);
            self::trim($limit);
        }

        return true;
    }

    /**
     * Get logs with pagination and optional filters
     *
     * @param int $limit
     * @param int $offset
     * @param string|null $channel
     * @param string|null $status
     * @return array
     */
    public static function getLogs($limit = 30, $offset = 0, $channel = null, $status = null)
    {
        global $db;

        $where = [];
        if ($channel && in_array($channel, ['telegram', 'vk'])) {
            $where[] = "`l`.`channel` = '" . $db->safesql($channel) . "'";
        }
        if ($status && in_array($status, ['success', 'error'])) {
            $where[] = "`l`.`status` = '" . $db->safesql($status) . "'";
        }

        $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $limit = intval($limit);
        $offset = intval($offset);

        $sql = "SELECT l.*, p.title as news_title 
                FROM " . PREFIX . "_autopost_log l 
                LEFT JOIN " . PREFIX . "_post p ON l.news_id = p.id 
                {$whereClause} 
                ORDER BY l.id DESC 
                LIMIT {$offset}, {$limit}";

        $rows = [];
        $res = $db->query($sql);
        while ($row = $db->get_row($res)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Count total log entries
     *
     * @param string|null $channel
     * @param string|null $status
     * @return int
     */
    public static function countLogs($channel = null, $status = null)
    {
        global $db;

        $where = [];
        if ($channel && in_array($channel, ['telegram', 'vk'])) {
            $where[] = "`channel` = '" . $db->safesql($channel) . "'";
        }
        if ($status && in_array($status, ['success', 'error'])) {
            $where[] = "`status` = '" . $db->safesql($status) . "'";
        }

        $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
        $row = $db->super_query("SELECT COUNT(*) as count FROM " . PREFIX . "_autopost_log {$whereClause}");

        return intval($row['count'] ?? 0);
    }

    /**
     * Delete old logs, keeping the most recent $keepCount records
     *
     * @param int $keepCount
     */
    public static function trim($keepCount = 500)
    {
        global $db;

        $keepCount = max(50, intval($keepCount));
        $row = $db->super_query("SELECT id FROM " . PREFIX . "_autopost_log ORDER BY id DESC LIMIT {$keepCount}, 1");
        if (!empty($row['id'])) {
            $cutoffId = intval($row['id']);
            $db->query("DELETE FROM " . PREFIX . "_autopost_log WHERE id <= {$cutoffId}");
        }
    }

    /**
     * Clear all logs
     */
    public static function clear()
    {
        global $db;
        $db->query("TRUNCATE TABLE " . PREFIX . "_autopost_log");
    }
}
