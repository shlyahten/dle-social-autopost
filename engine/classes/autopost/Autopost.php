<?php
/**
 * DLE Social AutoPost - Main Dispatcher & Event Orchestrator
 *
 * Hooks into DLE news lifecycle (addnews, editnews, cron) and routes posts
 * to Telegram and VK based on category filters, publication status, and queue rules.
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

require_once ENGINE_DIR . '/classes/autopost/Config.php';
require_once ENGINE_DIR . '/classes/autopost/Logger.php';
require_once ENGINE_DIR . '/classes/autopost/Queue.php';
require_once ENGINE_DIR . '/classes/autopost/MediaHelper.php';
require_once ENGINE_DIR . '/classes/autopost/TemplateEngine.php';
require_once ENGINE_DIR . '/classes/autopost/TelegramClient.php';
require_once ENGINE_DIR . '/classes/autopost/VkClient.php';

class AutopostDispatcher
{
    /**
     * Handle news save/update hook from addnews.php or editnews.php.
     *
     * @param int $newsId ID of saved news
     * @param array $newsData Full news data array
     * @param string $action 'add' or 'edit'
     */
    public static function handleNewsSaved(int $newsId, array $newsData, string $action = 'add'): void
    {
        $config = AutopostConfig::load();

        if (empty($config['enable']) || $config['enable'] !== '1') {
            return;
        }

        // Do not publish if news is unapproved or scheduled for future
        $approve = (int)($newsData['approve'] ?? 1);
        if ($approve !== 1) {
            return;
        }

        // Check if date is in the future (scheduled news)
        $postDate = !empty($newsData['date']) ? strtotime($newsData['date']) : time();
        if ($postDate > time()) {
            // Queue for delayed execution by CRON
            AutopostQueue::enqueue($newsId, 'all');
            return;
        }

        // Category filter check
        if (!self::isCategoryAllowed($newsData['category'] ?? '', $config)) {
            return;
        }

        // Process immediate dispatch or queue
        self::publishNewsRow($newsData, 'all', $config);
    }

    /**
     * Publish a news item by ID (used by CRON worker and admin test).
     */
    public static function publishNewsId(int $newsId, string $target = 'all'): bool
    {
        global $db;

        if (!$db) {
            return false;
        }

        $newsId = (int)$newsId;
        $news = $db->super_query("SELECT * FROM " . PREFIX . "_post WHERE id = {$newsId} LIMIT 1");

        if (empty($news['id'])) {
            AutopostLogger::error($newsId, $target, 'News post not found in database');
            return false;
        }

        $config = AutopostConfig::load();
        return self::publishNewsRow($news, $target, $config);
    }

    /**
     * Publish given news row to specified target ('all', 'telegram', 'vk').
     */
    public static function publishNewsRow(array $news, string $target = 'all', ?array $config = null): bool
    {
        if ($config === null) {
            $config = AutopostConfig::load();
        }

        $newsId = (int)($news['id'] ?? 0);
        $overallSuccess = true;

        // 1. Telegram Dispatch
        if ($target === 'all' || $target === 'telegram') {
            if (!empty($config['tg_enable']) && $config['tg_enable'] === '1') {
                if (!AutopostQueue::isAlreadyPublished($newsId, 'telegram')) {
                    $tgResult = self::dispatchToTelegram($news, $config);
                    if (!$tgResult) {
                        $overallSuccess = false;
                    }
                }
            }
        }

        // 2. VK Dispatch
        if ($target === 'all' || $target === 'vk') {
            if (!empty($config['vk_enable']) && $config['vk_enable'] === '1') {
                if (!AutopostQueue::isAlreadyPublished($newsId, 'vk')) {
                    $vkResult = self::dispatchToVk($news, $config);
                    if (!$vkResult) {
                        $overallSuccess = false;
                    }
                }
            }
        }

        return $overallSuccess;
    }

    /**
     * Dispatch news item to Telegram.
     */
    public static function dispatchToTelegram(array $news, array $config): bool
    {
        $newsId = (int)($news['id'] ?? 0);

        try {
            $proxyConfig = [
                'tg_proxy_enable'  => $config['tg_proxy_enable'] ?? '0',
                'tg_proxy_type'    => $config['tg_proxy_type'] ?? 'CURLPROXY_SOCKS5',
                'tg_proxy_address' => $config['tg_proxy_address'] ?? '',
                'tg_proxy_auth'    => $config['tg_proxy_auth'] ?? '',
            ];

            $apiUrl = $config['tg_api_url'] ?? null;
            $client = new AutopostTelegramClient($config['tg_bot_token'] ?? '', $config['tg_channel_id'] ?? '', $proxyConfig, $apiUrl);

            // Render message template
            $template = $config['tg_template'] ?? "<b>{title}</b>\n\n{short-story}\n\n{tags}";
            $message = AutopostTemplateEngine::render($template, $news, 'telegram', $config);

            // Extract main image if photo sending is enabled
            $imageUrl = null;
            if (!empty($config['tg_send_photo']) && $config['tg_send_photo'] === '1') {
                $imageUrl = AutopostMediaHelper::extractMainImage($news, $config);
            }

            // Options (buttons, preview)
            $options = [
                'disable_web_page_preview' => ($config['tg_disable_web_page_preview'] ?? '0') === '1',
                'disable_notification'     => ($config['tg_disable_notification'] ?? '0') === '1',
            ];

            if (!empty($config['tg_inline_button']) && $config['tg_inline_button'] === '1') {
                $options['button_text'] = $config['tg_button_text'] ?? 'Читать на сайте';
                $options['button_url']  = AutopostTemplateEngine::buildNewsUrl($news, 'telegram', $config);
            }

            $response = $client->sendMessage($message, $imageUrl, $options);

            if ($response['success']) {
                AutopostLogger::success($newsId, 'telegram', (string)($response['message_id'] ?? ''));
                return true;
            }

            AutopostLogger::error($newsId, 'telegram', $response['error'] ?? 'Unknown Telegram error');
            return false;
        } catch (\Throwable $e) {
            AutopostLogger::error($newsId, 'telegram', 'Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Dispatch news item to VK.
     */
    public static function dispatchToVk(array $news, array $config): bool
    {
        $newsId = (int)($news['id'] ?? 0);

        try {
            $client = new AutopostVkClient(
                $config['vk_access_token'] ?? '',
                $config['vk_owner_id'] ?? '',
                ($config['vk_from_group'] ?? '1') === '1',
                ($config['vk_signed'] ?? '0') === '1'
            );

            // Render message template
            $template = $config['vk_template'] ?? "{title}\n\n{short-story}\n\nЧитать далее: {link}\n\n{tags}";
            $message = AutopostTemplateEngine::render($template, $news, 'vk', $config);

            // Extract main image if photo sending is enabled
            $imageUrl = null;
            if (!empty($config['vk_attach_photo']) && $config['vk_attach_photo'] === '1') {
                $imageUrl = AutopostMediaHelper::extractMainImage($news, $config);
            }

            $response = $client->postToWall($message, $imageUrl);

            if ($response['success']) {
                AutopostLogger::success($newsId, 'vk', (string)($response['post_id'] ?? ''));
                return true;
            }

            AutopostLogger::error($newsId, 'vk', $response['error'] ?? 'Unknown VK error');
            return false;
        } catch (\Throwable $e) {
            AutopostLogger::error($newsId, 'vk', 'Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify if the news category is permitted by configuration filter.
     */
    public static function isCategoryAllowed(string $categoryIds, array $config): bool
    {
        $allowed = trim($config['allowed_categories'] ?? '');

        // If empty, all categories are allowed
        if (empty($allowed)) {
            return true;
        }

        $allowedList = array_map('trim', explode(',', $allowed));
        $newsCategories = array_map('trim', explode(',', $categoryIds));

        foreach ($newsCategories as $cat) {
            if (in_array($cat, $allowedList, true)) {
                return true;
            }
        }

        return false;
    }
}
