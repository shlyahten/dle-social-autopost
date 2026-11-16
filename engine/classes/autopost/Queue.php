<?php
/**
 * DLE Social AutoPost Plugin
 * Queue Manager & Processor
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

class Autopost_Queue
{
    /**
     * Add news to publication queue
     *
     * @param int    $newsId
     * @param string $target 'all', 'telegram', or 'vk'
     * @return int|bool Queue ID or false
     */
    public static function add($newsId, $target = 'all')
    {
        global $db;

        $newsId = intval($newsId);
        $target = in_array($target, ['telegram', 'vk', 'all']) ? $target : 'all';
        $now = date('Y-m-d H:i:s');

        // Check if pending task already exists for this news and target
        $existing = $db->super_query("SELECT id FROM " . PREFIX . "_autopost_queue 
                                      WHERE news_id = '{$newsId}' 
                                      AND target = '{$target}' 
                                      AND status IN ('pending', 'processing')");
        if (!empty($existing['id'])) {
            return intval($existing['id']);
        }

        $sql = "INSERT INTO " . PREFIX . "_autopost_queue 
                (`news_id`, `target`, `status`, `attempts`, `error_message`, `created_at`, `updated_at`) 
                VALUES ('{$newsId}', '{$target}', 'pending', 0, NULL, '{$now}', '{$now}')";

        $db->query($sql);
        return $db->insert_id();
    }

    /**
     * Process pending queue tasks
     *
     * @param int $batchSize Number of items to process in one run
     * @return array Summary of processed items
     */
    public static function process($batchSize = 10)
    {
        global $db;

        $batchSize = max(1, intval($batchSize));
        $config = Autopost_Config::get();
        $maxRetries = intval($config['max_retries'] ?? 3);

        // Fetch pending items
        $sql = "SELECT * FROM " . PREFIX . "_autopost_queue 
                WHERE status = 'pending' 
                AND attempts < {$maxRetries} 
                ORDER BY id ASC 
                LIMIT {$batchSize}";

        $res = $db->query($sql);
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        while ($task = $db->get_row($res)) {
            $taskId = intval($task['id']);
            $newsId = intval($task['news_id']);
            $target = $task['target'];
            $attempts = intval($task['attempts']) + 1;
            $now = date('Y-m-d H:i:s');

            // Mark as processing
            $db->query("UPDATE " . PREFIX . "_autopost_queue 
                        SET status = 'processing', attempts = '{$attempts}', updated_at = '{$now}' 
                        WHERE id = '{$taskId}'");

            // Dispatch task
            $result = Autopost_Main::publishNews($newsId, $target);
            $processed++;

            if ($result['success']) {
                $succeeded++;
                $db->query("UPDATE " . PREFIX . "_autopost_queue 
                            SET status = 'done', error_message = NULL, updated_at = '{$now}' 
                            WHERE id = '{$taskId}'");
            } else {
                $failed++;
                $errorMsg = $db->safesql(substr($result['error'] ?? 'Unknown error', 0, 500));
                $newStatus = ($attempts >= $maxRetries) ? 'failed' : 'pending';

                $db->query("UPDATE " . PREFIX . "_autopost_queue 
                            SET status = '{$newStatus}', error_message = '{$errorMsg}', updated_at = '{$now}' 
                            WHERE id = '{$taskId}'");
            }
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed'    => $failed
        ];
    }

    /**
     * Retry failed or pending task immediately
     *
     * @param int $taskId
     * @return array
     */
    public static function retry($taskId)
    {
        global $db;

        $taskId = intval($taskId);
        $task = $db->super_query("SELECT * FROM " . PREFIX . "_autopost_queue WHERE id = '{$taskId}'");

        if (empty($task['id'])) {
            return ['success' => false, 'error' => 'Queue task not found'];
        }

        $newsId = intval($task['news_id']);
        $target = $task['target'];
        $now = date('Y-m-d H:i:s');

        $result = Autopost_Main::publishNews($newsId, $target);

        if ($result['success']) {
            $db->query("UPDATE " . PREFIX . "_autopost_queue 
                        SET status = 'done', error_message = NULL, updated_at = '{$now}' 
                        WHERE id = '{$taskId}'");
        } else {
            $errorMsg = $db->safesql(substr($result['error'] ?? 'Unknown error', 0, 500));
            $db->query("UPDATE " . PREFIX . "_autopost_queue 
                        SET status = 'failed', error_message = '{$errorMsg}', updated_at = '{$now}' 
                        WHERE id = '{$taskId}'");
        }

        return $result;
    }

    /**
     * Delete queue task
     *
     * @param int $taskId
     * @return bool
     */
    public static function delete($taskId)
    {
        global $db;
        $taskId = intval($taskId);
        $db->query("DELETE FROM " . PREFIX . "_autopost_queue WHERE id = '{$taskId}'");
        return true;
    }

    /**
     * Clear finished / failed tasks
     *
     * @param string $status 'done', 'failed', or 'all'
     */
    public static function clear($status = 'done')
    {
        global $db;
        if ($status === 'all') {
            $db->query("TRUNCATE TABLE " . PREFIX . "_autopost_queue");
        } elseif (in_array($status, ['done', 'failed'])) {
            $db->query("DELETE FROM " . PREFIX . "_autopost_queue WHERE status = '{$status}'");
        }
    }

    /**
     * Get queue list with pagination
     *
     * @param int $limit
     * @param int $offset
     * @param string|null $status
     * @return array
     */
    public static function getList($limit = 30, $offset = 0, $status = null)
    {
        global $db;

        $where = "";
        if ($status && in_array($status, ['pending', 'processing', 'failed', 'done'])) {
            $where = "WHERE q.status = '" . $db->safesql($status) . "'";
        }

        $limit = intval($limit);
        $offset = intval($offset);

        $sql = "SELECT q.*, p.title as news_title 
                FROM " . PREFIX . "_autopost_queue q 
                LEFT JOIN " . PREFIX . "_post p ON q.news_id = p.id 
                {$where} 
                ORDER BY q.id DESC 
                LIMIT {$offset}, {$limit}";

        $rows = [];
        $res = $db->query($sql);
        while ($row = $db->get_row($res)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Count queue items
     *
     * @param string|null $status
     * @return int
     */
    public static function count($status = null)
    {
        global $db;

        $where = "";
        if ($status && in_array($status, ['pending', 'processing', 'failed', 'done'])) {
            $where = "WHERE status = '" . $db->safesql($status) . "'";
        }

        $row = $db->super_query("SELECT COUNT(*) as count FROM " . PREFIX . "_autopost_queue {$where}");
        return intval($row['count'] ?? 0);
    }
}
