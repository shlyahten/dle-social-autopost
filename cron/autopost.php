<?php
/**
 * DLE Social AutoPost Plugin
 * CRON Queue Worker & Scheduled Publisher
 * 
 * Usage via CLI:
 *   /usr/bin/php /path/to/dle/cron/autopost.php
 * 
 * Usage via HTTP:
 *   https://example.com/cron/autopost.php?key=YOUR_CRON_KEY
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

define('DATALIFEENGINE', true);
define('ROOT_DIR', dirname(__DIR__));
define('ENGINE_DIR', ROOT_DIR . '/engine');

$startTime = microtime(true);
$isCli = (php_sapi_name() === 'cli');

// Basic security checks
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

// Check configuration file
$configFile = ENGINE_DIR . '/data/autopost.php';
if (!file_exists($configFile)) {
    $err = ['status' => 'error', 'message' => 'Autopost configuration file not found'];
    echo $isCli ? ($err['message'] . PHP_EOL) : json_encode($err);
    exit(1);
}

// Load DLE Database & Main Configuration
if (!file_exists(ENGINE_DIR . '/data/config.php') || !file_exists(ENGINE_DIR . '/data/dbconfig.php')) {
    $err = ['status' => 'error', 'message' => 'DLE system config files not found'];
    echo $isCli ? ($err['message'] . PHP_EOL) : json_encode($err);
    exit(1);
}

require_once ENGINE_DIR . '/data/config.php';
require_once ENGINE_DIR . '/data/dbconfig.php';
require_once ENGINE_DIR . '/classes/mysql.php';

// Verify HTTP Secret Key if called via Web
require_once ENGINE_DIR . '/classes/autopost/Config.php';
$autopostConfig = Autopost_Config::get();

if (!$isCli) {
    $providedKey = $_GET['key'] ?? '';
    $expectedKey = $autopostConfig['cron_key'] ?? '';
    if (empty($expectedKey) || $providedKey !== $expectedKey) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access denied: Invalid cron key']);
        exit;
    }
}

// Check if plugin is enabled
if (empty($autopostConfig['enabled'])) {
    $res = ['status' => 'skipped', 'message' => 'Autopost is disabled globally'];
    echo $isCli ? ($res['message'] . PHP_EOL) : json_encode($res);
    exit(0);
}

// Load Autopost core classes
require_once ENGINE_DIR . '/classes/autopost/Autopost.php';

// 1. Process tasks in the queue
$batchSize = 15;
$queueResult = Autopost_Queue::process($batchSize);

// 2. Scan for newly published scheduled news
// In DLE, when scheduled news reaches its date, DLE sets approve = 1.
// Check if any news in allowed categories with date in past 24 hours hasn't been logged yet.
$cats = $autopostConfig['categories'] ?? [];
$catWhere = "";
if (!empty($cats)) {
    $catIds = array_map('intval', $cats);
    $catWhere = "AND category REGEXP '(^|,)(\" . implode('|', $catIds) . \")(,|$)'";
}

$now = date('Y-m-d H:i:s');
$oneDayAgo = date('Y-m-d H:i:s', time() - 86400);

$checkSql = "SELECT p.id FROM " . PREFIX . "_post p 
             WHERE p.approve = 1 
             AND p.date <= '{$now}' 
             AND p.date >= '{$oneDayAgo}' 
             {$catWhere} 
             AND NOT EXISTS (
                 SELECT 1 FROM " . PREFIX . "_autopost_log l 
                 WHERE l.news_id = p.id AND l.status = 'success'
             )
             AND NOT EXISTS (
                 SELECT 1 FROM " . PREFIX . "_autopost_queue q 
                 WHERE q.news_id = p.id AND q.status IN ('pending', 'processing', 'done')
             )
             ORDER BY p.date DESC LIMIT 5";

$unprocessedNews = $db->query($checkSql);
$scheduledQueued = 0;

while ($row = $db->get_row($unprocessedNews)) {
    Autopost_Queue::add($row['id'], 'all');
    $scheduledQueued++;
}

// If new scheduled items were added, process another batch
if ($scheduledQueued > 0) {
    $extraQueue = Autopost_Queue::process($scheduledQueued);
    $queueResult['processed'] += $extraQueue['processed'];
    $queueResult['succeeded'] += $extraQueue['succeeded'];
    $queueResult['failed'] += $extraQueue['failed'];
}

$elapsed = round(microtime(true) - $startTime, 4);

$response = [
    'status'           => 'ok',
    'scheduled_queued' => $scheduledQueued,
    'queue_processed'  => $queueResult['processed'],
    'queue_succeeded'  => $queueResult['succeeded'],
    'queue_failed'     => $queueResult['failed'],
    'execution_time_s' => $elapsed
];

if ($isCli) {
    echo "DLE AutoPost CRON Worker Completed." . PHP_EOL;
    echo "Scheduled queued: {$scheduledQueued}" . PHP_EOL;
    echo "Queue processed:  {$queueResult['processed']} (Succeeded: {$queueResult['succeeded']}, Failed: {$queueResult['failed']})" . PHP_EOL;
    echo "Execution time:   {$elapsed}s" . PHP_EOL;
} else {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

exit(0);
