<?php
/**
 * DLE Social AutoPost - Asynchronous Queue Manager
 *
 * Handles delayed publishing, retries on network failures, and batch execution.
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

class AutopostQueue
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_DONE       = 'done';

    public const MAX_ATTEMPTS = 5;

    /**
     * Add a task to the queue.
     */
    public static function enqueue(int $newsId, string $target = 'all'): int
    {
        global $db;

        if (!$db) {
            return 0;
        }

        $newsId = (int)$newsId;
        $target = $db->safesql(in_array($target, ['all', 'telegram', 'vk'], true) ? $target : 'all');

        // Check if an unprocessed task for this news & target already exists
        $existing = $db->super_query(
            "SELECT id FROM " . PREFIX . "_autopost_queue 
             WHERE news_id = {$newsId} AND target = '{$target}' AND status IN ('pending', 'processing') 
             LIMIT 1"
        );

        if (!empty($existing['id'])) {
            return (int)$existing['id'];
        }

        $db->query(
            "INSERT INTO " . PREFIX . "_autopost_queue 
             (news_id, target, status, attempts, created_at, updated_at) 
             VALUES 
             ({$newsId}, '{$target}', 'pending', 0, NOW(), NOW())"
        );

        return (int)$db->insert_id();
    }

    /**
     * Process pending tasks from the queue.
     * Called by CRON or background worker.
     */
    public static function processPending(int $limit = 10): array
    {
        global $db;

        if (!$db) {
            return ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        }

        $limit = max(1, min(50, (int)$limit));
        $now = date('Y-m-d H:i:s');

        // Select pending or retriable failed tasks
        $tasksQuery = $db->query(
            "SELECT * FROM " . PREFIX . "_autopost_queue 
             WHERE status = 'pending' OR (status = 'failed' AND attempts < " . self::MAX_ATTEMPTS . " AND updated_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)) 
             ORDER BY id ASC 
             LIMIT {$limit}"
        );

        $results = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        $tasks = [];
        while ($task = $db->get_row($tasksQuery)) {
            $tasks[] = $task;
        }

        require_once ENGINE_DIR . '/classes/autopost/Autopost.php';

        foreach ($tasks as $task) {
            $taskId = (int)$task['id'];
            $newsId = (int)$task['news_id'];
            $target = $task['target'];
            $attempts = (int)$task['attempts'] + 1;

            // Mark task as processing
            $db->query("UPDATE " . PREFIX . "_autopost_queue SET status = 'processing', attempts = {$attempts}, updated_at = NOW() WHERE id = {$taskId}");
            $results['processed']++;

            try {
                $success = AutopostDispatcher::publishNewsId($newsId, $target);

                if ($success) {
                    $db->query("UPDATE " . PREFIX . "_autopost_queue SET status = 'done', updated_at = NOW() WHERE id = {$taskId}");
                    $results['succeeded']++;
                } else {
                    $db->query("UPDATE " . PREFIX . "_autopost_queue SET status = 'failed', error_message = 'Failed to publish to all targets', updated_at = NOW() WHERE id = {$taskId}");
                    $results['failed']++;
                }
            } catch (\Throwable $e) {
                $error = $db->safesql(substr($e->getMessage(), 0, 500));
                $db->query("UPDATE " . PREFIX . "_autopost_queue SET status = 'failed', error_message = '{$error}', updated_at = NOW() WHERE id = {$taskId}");
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Check if a news item has already been successfully published.
     */
    public static function isAlreadyPublished(int $newsId, string $channel): bool
    {
        global $db;

        if (!$db) {
            return false;
        }

        $newsId = (int)$newsId;
        $channel = $db->safesql($channel);

        $check = $db->super_query(
            "SELECT id FROM " . PREFIX . "_autopost_log 
             WHERE news_id = {$newsId} AND channel = '{$channel}' AND status = 'success' 
             LIMIT 1"
        );

        return !empty($check['id']);
    }

    /**
     * Get queue statistics for admin dashboard.
     */
    public static function getStats(): array
    {
        global $db;

        if (!$db) {
            return ['pending' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0, 'total' => 0];
        }

        $res = $db->query("SELECT status, COUNT(*) as cnt FROM " . PREFIX . "_autopost_queue GROUP BY status");
        $stats = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0, 'total' => 0];

        while ($row = $db->get_row($res)) {
            $status = $row['status'];
            $cnt = (int)$row['cnt'];
            if (isset($stats[$status])) {
                $stats[$status] = $cnt;
            }
            $stats['total'] += $cnt;
        }

        return $stats;
    }

    /**
     * Get queue tasks list for admin.
     */
    public static function getTasks(int $limit = 50, int $offset = 0): array
    {
        global $db;

        if (!$db) {
            return [];
        }

        $limit = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT q.*, p.title as news_title 
                  FROM " . PREFIX . "_autopost_queue q 
                  LEFT JOIN " . PREFIX . "_post p ON p.id = q.news_id 
                  ORDER BY q.id DESC 
                  LIMIT {$offset}, {$limit}";

        $result = $db->query($query);
        $tasks = [];
        while ($row = $db->get_row($result)) {
            $tasks[] = $row;
        }

        return $tasks;
    }

    /**
     * Delete a single task from queue.
     */
    public static function deleteTask(int $taskId): void
    {
        global $db;
        if ($db) {
            $db->query("DELETE FROM " . PREFIX . "_autopost_queue WHERE id = " . (int)$taskId);
        }
    }

    /**
     * Clear all tasks from queue.
     */
    public static function clearAll(): void
    {
        global $db;
        if ($db) {
            $db->query("TRUNCATE TABLE " . PREFIX . "_autopost_queue");
        }
    }
}
