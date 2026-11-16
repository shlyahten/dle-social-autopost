<?php
/**
 * DLE Social AutoPost Plugin
 * Main Facade & Event Orchestrator
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/MediaHelper.php';
require_once __DIR__ . '/TemplateEngine.php';
require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/VkClient.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Queue.php';

class Autopost_Main
{
    /**
     * Hook triggered when a news item is saved (added or updated)
     *
     * @param int   $newsId
     * @param array $options ['send_tg' => bool, 'send_vk' => bool, 'is_new' => bool]
     * @return bool
     */
    public static function handleNewsSave($newsId, array $options = [])
    {
        global $db;

        $newsId = intval($newsId);
        if ($newsId <= 0) {
            return false;
        }

        $config = Autopost_Config::get();
        if (empty($config['enabled'])) {
            return false;
        }

        // Fetch news details
        $news = $db->super_query("SELECT * FROM " . PREFIX . "_post WHERE id = '{$newsId}'");
        if (empty($news['id'])) {
            return false;
        }

        // Determine if target should be sent
        $sendTg = false;
        $sendVk = false;

        $inCategory = self::isCategoryAllowed($news['category'], $config['categories'] ?? []);

        // Telegram decision
        if (!empty($config['tg_enabled'])) {
            if (isset($options['send_tg'])) {
                $sendTg = (bool)$options['send_tg'];
            } else {
                $sendTg = $inCategory;
            }
        }

        // VK decision
        if (!empty($config['vk_enabled'])) {
            if (isset($options['send_vk'])) {
                $sendVk = (bool)$options['send_vk'];
            } else {
                $sendVk = $inCategory;
            }
        }

        if (!$sendTg && !$sendVk) {
            return false;
        }

        // Determine target string
        $target = ($sendTg && $sendVk) ? 'all' : ($sendTg ? 'telegram' : 'vk');

        // Check if news is approved and not scheduled for the future
        $isApproved = !empty($news['approve']);
        $now = time();
        $newsTime = strtotime($news['date']);
        $isFuture = ($newsTime > $now);

        // If not approved or future publication date: queue for CRON
        if (!$isApproved || $isFuture) {
            Autopost_Queue::add($newsId, $target);
            return true;
        }

        // Hybrid mode: attempt immediate dispatch
        $result = self::publishNews($newsId, $target);

        // If immediate dispatch failed, enqueue for background retry
        if (!$result['success']) {
            Autopost_Queue::add($newsId, $target);
        }

        return true;
    }

    /**
     * Publish news item to designated target(s)
     *
     * @param int    $newsId
     * @param string $target 'all', 'telegram', or 'vk'
     * @return array ['success' => bool, 'error' => string|null, 'details' => array]
     */
    public static function publishNews($newsId, $target = 'all')
    {
        global $db;

        $newsId = intval($newsId);
        $config = Autopost_Config::get();

        $news = $db->super_query("SELECT * FROM " . PREFIX . "_post WHERE id = '{$newsId}'");
        if (empty($news['id'])) {
            return ['success' => false, 'error' => "News ID {$newsId} not found"];
        }

        // Extract image once for all channels
        $imageInfo = Autopost_MediaHelper::extractImage($news, $config['xfield_image'] ?? '');

        $errors = [];
        $successCount = 0;
        $totalTargets = 0;
        $details = [];

        // 1. Telegram
        if (($target === 'all' || $target === 'telegram') && !empty($config['tg_enabled'])) {
            $totalTargets++;
            $tgClient = new Autopost_TelegramClient(
                $config['tg_bot_token'] ?? '',
                $config['tg_chat_id'] ?? '',
                [
                    'enable'  => $config['tg_proxy_enable'] ?? 0,
                    'type'    => $config['tg_proxy_type'] ?? 'socks5',
                    'address' => $config['tg_proxy_addr'] ?? '',
                    'auth'    => $config['tg_proxy_auth'] ?? '',
                ],
                $config['tg_api_url'] ?? 'https://api.telegram.org'
            );

            $tgText = Autopost_TemplateEngine::render(
                $config['tg_template'] ?? '',
                $news,
                'telegram',
                $config
            );

            $btnText = !empty($config['tg_btn_enable']) ? ($config['tg_btn_text'] ?? 'Читать на сайте') : null;
            $btnUrl = !empty($config['tg_btn_enable']) ? Autopost_TemplateEngine::buildNewsUrl($news, 'telegram', $config) : null;

            $tgRes = $tgClient->sendPost($tgText, $imageInfo, $btnText, $btnUrl);
            $details['telegram'] = $tgRes;

            if ($tgRes['success']) {
                $successCount++;
                Autopost_Logger::log($newsId, 'telegram', 'success', $tgRes['message_id'] ?? null);
            } else {
                $errMsg = $tgRes['error'] ?? 'Unknown Telegram error';
                $errors[] = "Telegram: " . $errMsg;
                Autopost_Logger::log($newsId, 'telegram', 'error', null, $errMsg);
            }
        }

        // 2. VK
        if (($target === 'all' || $target === 'vk') && !empty($config['vk_enabled'])) {
            $totalTargets++;
            $vkClient = new Autopost_VkClient(
                $config['vk_token'] ?? '',
                $config['vk_group_id'] ?? '',
                $config['vk_from_group'] ?? 1,
                $config['vk_signed'] ?? 0
            );

            $vkText = Autopost_TemplateEngine::render(
                $config['vk_template'] ?? '',
                $news,
                'vk',
                $config
            );

            $linkUrl = Autopost_TemplateEngine::buildNewsUrl($news, 'vk', $config);

            $vkRes = $vkClient->sendPost($vkText, $imageInfo, $linkUrl);
            $details['vk'] = $vkRes;

            if ($vkRes['success']) {
                $successCount++;
                Autopost_Logger::log($newsId, 'vk', 'success', $vkRes['post_id'] ?? null);
            } else {
                $errMsg = $vkRes['error'] ?? 'Unknown VK error';
                $errors[] = "VK: " . $errMsg;
                Autopost_Logger::log($newsId, 'vk', 'error', null, $errMsg);
            }
        }

        $allOk = ($totalTargets > 0 && $successCount === $totalTargets);

        return [
            'success' => $allOk,
            'error'   => !empty($errors) ? implode('; ', $errors) : null,
            'details' => $details
        ];
    }

    /**
     * Check if news category matches allowed categories
     *
     * @param string|int $newsCat
     * @param array      $allowedCats
     * @return bool
     */
    public static function isCategoryAllowed($newsCat, array $allowedCats)
    {
        if (empty($allowedCats)) {
            return true; // No restriction = all categories allowed
        }

        $newsCatIds = array_map('intval', explode(',', (string)$newsCat));
        $allowedIds = array_map('intval', $allowedCats);

        return count(array_intersect($newsCatIds, $allowedIds)) > 0;
    }

    /**
     * Render checkbox HTML for addnews.php and editnews.php
     *
     * @param array|null $newsRow
     * @param bool       $isEdit
     * @return string
     */
    public static function renderFormCheckboxes($newsRow = null, $isEdit = false)
    {
        global $db;

        $config = Autopost_Config::get();
        if (empty($config['enabled'])) {
            return '';
        }

        $newsId = intval($newsRow['id'] ?? 0);
        $catIds = $newsRow['category'] ?? '';
        $matchesCat = self::isCategoryAllowed($catIds, $config['categories'] ?? []);

        // Check if already posted
        $alreadyTg = false;
        $alreadyVk = false;

        if ($isEdit && $newsId > 0) {
            $tgLog = $db->super_query("SELECT id FROM " . PREFIX . "_autopost_log WHERE news_id = '{$newsId}' AND channel = 'telegram' AND status = 'success' LIMIT 1");
            $alreadyTg = !empty($tgLog['id']);

            $vkLog = $db->super_query("SELECT id FROM " . PREFIX . "_autopost_log WHERE news_id = '{$newsId}' AND channel = 'vk' AND status = 'success' LIMIT 1");
            $alreadyVk = !empty($vkLog['id']);
        }

        $tgChecked = (!$isEdit && !empty($config['tg_enabled']) && $matchesCat) ? 'checked' : '';
        $vkChecked = (!$isEdit && !empty($config['vk_enabled']) && $matchesCat) ? 'checked' : '';

        $html = '<div class="panel panel-default" style="margin-top: 15px; border-left: 3px solid #1e88e5;">';
        $html .= '<div class="panel-heading"><h6 class="panel-title"><i class="fa fa-share-alt"></i> Автопостинг в соцсети</h6></div>';
        $html .= '<div class="panel-body" style="padding: 10px 15px;">';

        if (!empty($config['tg_enabled'])) {
            $tgStatusBadge = $alreadyTg ? ' <span class="label label-success" style="font-size:10px;">уже отправлено</span>' : '';
            $html .= '<div class="checkbox" style="margin-bottom: 5px;">';
            $html .= '<label><input type="checkbox" name="autopost_tg" value="1" ' . $tgChecked . '> <i class="fa fa-paper-plane" style="color:#0088cc;"></i> Отправить в Telegram-канал' . $tgStatusBadge . '</label>';
            $html .= '</div>';
        }

        if (!empty($config['vk_enabled'])) {
            $vkStatusBadge = $alreadyVk ? ' <span class="label label-success" style="font-size:10px;">уже отправлено</span>' : '';
            $html .= '<div class="checkbox" style="margin-bottom: 0;">';
            $html .= '<label><input type="checkbox" name="autopost_vk" value="1" ' . $vkChecked . '> <i class="fa fa-vk" style="color:#4a76a8;"></i> Отправить в группу ВКонтакте' . $vkStatusBadge . '</label>';
            $html .= '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }
}

// Class alias for backward compatibility
class_alias('Autopost_Main', 'DLE_Autopost');
