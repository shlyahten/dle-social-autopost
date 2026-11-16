<?php
/**
 * DLE Social AutoPost Plugin
 * Admin Control Panel Module
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.1.1
 */

if (!defined('DATALIFEENGINE') || !defined('LOGGED_IN')) {
    die('Hacking attempt!');
}

if ($member_id['user_group'] != 1) {
    msg("error", $lang['index_denied'], $lang['index_denied']);
}

require_once ENGINE_DIR . '/classes/autopost/Autopost.php';

$selfUrl = $PHP_SELF . '?mod=autopost';
$action = $_REQUEST['action'] ?? 'settings';
$tab = $_REQUEST['tab'] ?? 'settings';

$message = '';
$messageType = 'info';

// -----------------------------------------------------------------------------
// POST Request Handling
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['user_hash']) || $_POST['user_hash'] !== $dle_login_hash) {
        die('Hacking attempt! CSRF token mismatch.');
    }

    // 1. Save Settings
    if ($action === 'save_settings') {
        $cats = isset($_POST['categories']) && is_array($_POST['categories']) ? array_map('intval', $_POST['categories']) : [];

        $newSettings = [
            'enabled'         => !empty($_POST['enabled']) ? 1 : 0,
            'categories'      => $cats,
            'xfield_image'    => trim(strip_tags($_POST['xfield_image'] ?? '')),

            // Telegram
            'tg_enabled'      => !empty($_POST['tg_enabled']) ? 1 : 0,
            'tg_bot_token'    => trim($_POST['tg_bot_token'] ?? ''),
            'tg_chat_id'      => trim($_POST['tg_chat_id'] ?? ''),
            'tg_api_url'      => !empty($_POST['tg_api_url']) ? trim($_POST['tg_api_url']) : 'https://api.telegram.org',
            'tg_parse_mode'   => 'HTML',
            'tg_template'     => trim($_POST['tg_template'] ?? ''),
            'tg_btn_enable'   => !empty($_POST['tg_btn_enable']) ? 1 : 0,
            'tg_btn_text'     => trim(strip_tags($_POST['tg_btn_text'] ?? 'Читать на сайте')),
            'tg_proxy_enable' => !empty($_POST['tg_proxy_enable']) ? 1 : 0,
            'tg_proxy_type'   => in_array($_POST['tg_proxy_type'] ?? '', ['socks5', 'http']) ? $_POST['tg_proxy_type'] : 'socks5',
            'tg_proxy_addr'   => trim($_POST['tg_proxy_addr'] ?? ''),
            'tg_proxy_auth'   => trim($_POST['tg_proxy_auth'] ?? ''),

            // VK
            'vk_enabled'      => !empty($_POST['vk_enabled']) ? 1 : 0,
            'vk_token'        => trim($_POST['vk_token'] ?? ''),
            'vk_group_id'     => trim($_POST['vk_group_id'] ?? ''),
            'vk_template'     => trim($_POST['vk_template'] ?? ''),
            'vk_from_group'   => !empty($_POST['vk_from_group']) ? 1 : 0,
            'vk_signed'       => !empty($_POST['vk_signed']) ? 1 : 0,

            // UTM
            'utm_enable'      => !empty($_POST['utm_enable']) ? 1 : 0,
            'utm_source_tg'   => trim($_POST['utm_source_tg'] ?? 'telegram'),
            'utm_medium_tg'   => trim($_POST['utm_medium_tg'] ?? 'channel'),
            'utm_campaign_tg' => trim($_POST['utm_campaign_tg'] ?? 'press_release'),
            'utm_source_vk'   => trim($_POST['utm_source_vk'] ?? 'vk'),
            'utm_medium_vk'   => trim($_POST['utm_medium_vk'] ?? 'wall'),
            'utm_campaign_vk' => trim($_POST['utm_campaign_vk'] ?? 'press_release'),

            // Cron & Limits
            'max_retries'     => max(1, intval($_POST['max_retries'] ?? 3)),
            'log_limit'       => max(50, intval($_POST['log_limit'] ?? 500)),
        ];

        if (!empty($_POST['cron_key'])) {
            $newSettings['cron_key'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['cron_key']);
        }

        if (Autopost_Config::save($newSettings)) {
            $message = 'Настройки автопостинга успешно сохранены!';
            $messageType = 'success';
        } else {
            $message = 'Ошибка сохранения настроек! Проверьте права на запись файла engine/data/autopost.php.';
            $messageType = 'danger';
        }
    }

    // 2. Test Telegram Connection
    if ($action === 'test_telegram') {
        $botToken = trim($_POST['tg_bot_token'] ?? '');
        $chatId = trim($_POST['tg_chat_id'] ?? '');
        $apiUrl = !empty($_POST['tg_api_url']) ? trim($_POST['tg_api_url']) : (!empty($cfg['tg_api_url']) ? $cfg['tg_api_url'] : 'https://api.telegram.org');
        $proxy = [
            'enable'  => !empty($_POST['tg_proxy_enable']),
            'type'    => $_POST['tg_proxy_type'] ?? 'socks5',
            'address' => trim($_POST['tg_proxy_addr'] ?? ''),
            'auth'    => trim($_POST['tg_proxy_auth'] ?? '')
        ];

        $client = new Autopost_TelegramClient($botToken, $chatId, $proxy, $apiUrl);
        $res = $client->testConnection();

        if ($res['success']) {
            $message = "Успешное подключение к Telegram! Бот: <b>@{$res['bot_username']}</b>, Чат: <b>{$res['chat_title']}</b>.";
            $messageType = 'success';
        } else {
            $message = "Ошибка подключения к Telegram: " . htmlspecialchars($res['error']);
            $messageType = 'danger';
        }
        $tab = 'diagnostics';
    }

    // 2.1 Check Telegram Endpoint Availability
    if ($action === 'check_telegram_network' || isset($_GET['check_network'])) {
        $checkUrl = trim($_POST['tg_api_url'] ?? ($cfg['tg_api_url'] ?? 'https://api.telegram.org'));
        $proxy = [
            'type'    => $_POST['tg_proxy_type'] ?? ($cfg['tg_proxy_type'] ?? 'socks5'),
            'address' => trim($_POST['tg_proxy_addr'] ?? ($cfg['tg_proxy_addr'] ?? '')),
            'auth'    => trim($_POST['tg_proxy_auth'] ?? ($cfg['tg_proxy_auth'] ?? ''))
        ];

        $tgNetworkReport = Autopost_TelegramClient::checkEndpointAvailability($checkUrl, $proxy);
        $tab = 'diagnostics';
    }

    // 3. Test VK Connection
    if ($action === 'test_vk') {
        $token = trim($_POST['vk_token'] ?? '');
        $groupId = trim($_POST['vk_group_id'] ?? '');

        $client = new Autopost_VkClient($token, $groupId);
        $res = $client->testConnection();

        if ($res['success']) {
            $message = "Успешное подключение к ВКонтакте! Сообщество: <b>{$res['group_name']}</b> (vk.com/{$res['screen_name']}).";
            $messageType = 'success';
        } else {
            $message = "Ошибка подключения к ВКонтакте: " . htmlspecialchars($res['error']);
            $messageType = 'danger';
        }
        $tab = 'diagnostics';
    }

    // 4. Manual Post Test (by News ID)
    if ($action === 'send_manual_test') {
        $testNewsId = intval($_POST['test_news_id'] ?? 0);
        $testTarget = $_POST['test_target'] ?? 'all';

        if ($testNewsId <= 0) {
            $message = 'Укажите корректный ID новости для отправки!';
            $messageType = 'warning';
        } else {
            $res = Autopost_Main::publishNews($testNewsId, $testTarget);
            if ($res['success']) {
                $message = "Новость #{$testNewsId} успешно отправлена в выбранные соцсети!";
                $messageType = 'success';
            } else {
                $message = "Ошибка отправки новости #{$testNewsId}: " . htmlspecialchars($res['error']);
                $messageType = 'danger';
            }
        }
        $tab = 'diagnostics';
    }

    // 5. Queue Operations
    if ($action === 'process_queue') {
        $res = Autopost_Queue::process(20);
        $message = "Обработка очереди завершена. Обработано: {$res['processed']}, успешно: {$res['succeeded']}, ошибок: {$res['failed']}.";
        $messageType = $res['failed'] > 0 ? 'warning' : 'success';
        $tab = 'queue';
    }

    if ($action === 'retry_queue_item') {
        $taskId = intval($_POST['task_id'] ?? 0);
        $res = Autopost_Queue::retry($taskId);
        if ($res['success']) {
            $message = "Задача #{$taskId} успешно выполнена!";
            $messageType = 'success';
        } else {
            $message = "Ошибка при повторной отправке задачи #{$taskId}: " . htmlspecialchars($res['error']);
            $messageType = 'danger';
        }
        $tab = 'queue';
    }

    if ($action === 'delete_queue_item') {
        $taskId = intval($_POST['task_id'] ?? 0);
        Autopost_Queue::delete($taskId);
        $message = "Задача #{$taskId} удалена из очереди.";
        $messageType = 'info';
        $tab = 'queue';
    }

    if ($action === 'clear_queue') {
        $clearType = $_POST['clear_type'] ?? 'done';
        Autopost_Queue::clear($clearType);
        $message = 'Очередь успешно очищена.';
        $messageType = 'info';
        $tab = 'queue';
    }

    // 6. Log Operations
    if ($action === 'clear_logs') {
        Autopost_Logger::clear();
        $message = 'Журнал публикаций полностью очищен.';
        $messageType = 'info';
        $tab = 'logs';
    }
}

// GET diagnostics trigger
if (isset($_GET['check_network'])) {
    $checkUrl = trim($_GET['tg_api_url'] ?? ($cfg['tg_api_url'] ?? 'https://api.telegram.org'));
    $proxy = [
        'type'    => $_GET['tg_proxy_type'] ?? ($cfg['tg_proxy_type'] ?? 'socks5'),
        'address' => trim($_GET['tg_proxy_addr'] ?? ($cfg['tg_proxy_addr'] ?? '')),
        'auth'    => trim($_GET['tg_proxy_auth'] ?? ($cfg['tg_proxy_auth'] ?? ''))
    ];

    $tgNetworkReport = Autopost_TelegramClient::checkEndpointAvailability($checkUrl, $proxy);
    $tab = 'diagnostics';
}

$cfg = Autopost_Config::get();

// -----------------------------------------------------------------------------
// UI Helpers (Compliant with DLE standard module architecture)
// -----------------------------------------------------------------------------
if (!function_exists('apShowRow')) {
    function apShowRow($title = "", $description = "", $field = "", $class = "") {
        $classAttr = $class ? " class=\"{$class}\"" : "";
        echo "<tr{$classAttr}>
            <td class=\"col-xs-12 col-sm-6 col-md-7\">
                <h6 class=\"media-heading text-semibold\">{$title}</h6>
                <div class=\"text-muted text-size-small hidden-xs\">{$description}</div>
            </td>
            <td class=\"col-xs-12 col-sm-6 col-md-5\">{$field}</td>
        </tr>";
    }
}

if (!function_exists('apMakeSwitch')) {
    function apMakeSwitch($name, $selected, $optional = false) {
        $optionalStr = $optional ? $optional : "";
        $checked = !empty($selected) ? "checked" : "";
        return "<input class=\"switch\" type=\"checkbox\" name=\"{$name}\" value=\"1\" {$checked} {$optionalStr}>";
    }
}

// -----------------------------------------------------------------------------
// UI Render
// -----------------------------------------------------------------------------
echoheader(
    "<i class=\"fa fa-share-alt position-left\"></i><span class=\"text-semibold\">Автопостинг VK & Telegram</span>",
    "Публикация пресс-релизов и новостей в Telegram-канал и группу ВКонтакте"
);

$queueCount = Autopost_Queue::count();
$logsCount = Autopost_Logger::countLogs();
?>

<!-- Theme Engine & UI Tokens (Compliant with Light and Dark DLE Themes) -->
<style>
.autopost-wrap {
    --ap-header-bg: #ffffff;
    --ap-header-border: #364A65;
    --ap-title-color: #1e293b;
    --ap-desc-color: #64748b;
    --ap-tg-color: #0088cc;
    --ap-vk-color: #4a76a8;
    --ap-code-bg: #f1f5f9;
    --ap-code-color: #0f172a;
    --ap-code-border: #cbd5e1;
    --ap-pre-bg: #f8fafc;
    --ap-pre-border: #e2e8f0;
    --ap-card-box-bg: #fafafa;
    --ap-card-box-border: #e2e8f0;
    --ap-card-box-text: #475569;
    --ap-input-addon-bg: #f1f5f9;
    --ap-input-addon-color: #475569;
    --ap-tag-bg: #e2e8f0;
    --ap-tag-text: #1e293b;
    --ap-tag-hover: #cbd5e1;
}

body.dle_theme_dark .autopost-wrap {
    --ap-header-bg: #222834;
    --ap-header-border: #446995;
    --ap-title-color: #f8fafc;
    --ap-desc-color: #94a3b8;
    --ap-tg-color: #38bdf8;
    --ap-vk-color: #60a5fa;
    --ap-code-bg: #1e293b;
    --ap-code-color: #38bdf8;
    --ap-code-border: #334155;
    --ap-pre-bg: #1e2532;
    --ap-pre-border: #2e3848;
    --ap-card-box-bg: #1a202c;
    --ap-card-box-border: #2d3748;
    --ap-card-box-text: #94a3b8;
    --ap-input-addon-bg: #1e293b;
    --ap-input-addon-color: #94a3b8;
    --ap-tag-bg: #334155;
    --ap-tag-text: #f1f5f9;
    --ap-tag-hover: #475569;
}

/* Header Banner */
.autopost-wrap .autopost-header {
    background-color: var(--ap-header-bg) !important;
    border-left: 4px solid var(--ap-header-border) !important;
    margin-bottom: 20px;
    border-radius: 3px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.autopost-wrap .autopost-header .autopost-title {
    color: var(--ap-title-color) !important;
    font-weight: 700 !important;
    margin: 0 0 4px 0 !important;
    font-size: 16px !important;
}
.autopost-wrap .autopost-header .autopost-desc {
    color: var(--ap-desc-color) !important;
    margin: 0 !important;
    font-size: 13px !important;
}

/* Navigation Navbar */
.autopost-wrap .navbar-component {
    margin-bottom: 20px;
}
.autopost-wrap .navbar-nav > li > a {
    font-weight: 500;
}

/* FIX DLE Block Code Bug - Inline code and badge tags */
.autopost-wrap code {
    display: inline !important;
    padding: 1px 5px !important;
    margin: 0 2px !important;
    font-size: 11px !important;
    font-family: Menlo, Monaco, Consolas, monospace !important;
    border-radius: 3px !important;
    white-space: nowrap !important;
    background-color: var(--ap-tag-bg) !important;
    color: var(--ap-tag-text) !important;
    border: 1px solid var(--ap-code-border) !important;
    text-shadow: none !important;
}
.autopost-wrap .tag-badge {
    display: inline-block !important;
    padding: 3px 8px !important;
    margin: 2px 3px !important;
    font-size: 11px !important;
    font-family: Menlo, Monaco, Consolas, monospace !important;
    border-radius: 3px !important;
    white-space: nowrap !important;
    background-color: var(--ap-tag-bg) !important;
    color: var(--ap-tag-text) !important;
    border: 1px solid var(--ap-code-border) !important;
    text-shadow: none !important;
    cursor: pointer;
    user-select: none;
    transition: background-color 0.15s ease, transform 0.15s ease;
}
.autopost-wrap .tag-badge:hover {
    background-color: var(--ap-tag-hover) !important;
    transform: translateY(-1px);
}
.autopost-wrap .tag-badge i {
    margin-right: 3px;
    opacity: 0.7;
}

/* Preformatted CRON Blocks */
.autopost-wrap .ap-code-block {
    display: block !important;
    position: relative !important;
    background-color: var(--ap-pre-bg) !important;
    border: 1px solid var(--ap-pre-border) !important;
    color: var(--ap-title-color) !important;
    padding: 10px 105px 10px 14px !important;
    border-radius: 4px !important;
    font-family: Menlo, Monaco, Consolas, monospace !important;
    font-size: 12px !important;
    margin: 0 !important;
    white-space: pre-wrap !important;
    word-break: break-all !important;
    text-shadow: none !important;
}
.autopost-wrap .ap-copy-btn {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    padding: 3px 10px !important;
    font-size: 11px !important;
}
body.dle_theme_dark .autopost-wrap .ap-copy-btn {
    background-color: #2e3848 !important;
    color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dle_theme_dark .autopost-wrap .ap-copy-btn:hover {
    background-color: #3b4759 !important;
    color: #ffffff !important;
}

/* Brand Accent Headings */
.autopost-wrap .panel-heading-tg {
    border-left: 3px solid var(--ap-tg-color) !important;
}
.autopost-wrap .panel-heading-tg .panel-title i {
    color: var(--ap-tg-color) !important;
}
.autopost-wrap .panel-heading-vk {
    border-left: 3px solid var(--ap-vk-color) !important;
}
.autopost-wrap .panel-heading-vk .panel-title i {
    color: var(--ap-vk-color) !important;
}

/* Heading Buttons with High-Contrast Colors */
.autopost-wrap .panel-heading .heading-elements .btn-default {
    background-color: rgba(255, 255, 255, 0.15) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.35) !important;
    box-shadow: none !important;
}
.autopost-wrap .panel-heading .heading-elements .btn-default:hover {
    background-color: rgba(255, 255, 255, 0.28) !important;
    color: #ffffff !important;
    border-color: rgba(255, 255, 255, 0.5) !important;
}
.autopost-wrap .panel-heading .heading-elements .btn-success,
.autopost-wrap .panel-heading .heading-elements .bg-success {
    background-color: #2e7d32 !important;
    color: #ffffff !important;
    border: 1px solid #1b5e20 !important;
}
.autopost-wrap .panel-heading .heading-elements .btn-success:hover,
.autopost-wrap .panel-heading .heading-elements .bg-success:hover {
    background-color: #388e3c !important;
    color: #ffffff !important;
}
.autopost-wrap .panel-heading .heading-elements .btn-danger,
.autopost-wrap .panel-heading .heading-elements .bg-danger {
    background-color: #c62828 !important;
    color: #ffffff !important;
    border: 1px solid #b71c1c !important;
}
.autopost-wrap .panel-heading .heading-elements .btn-danger:hover,
.autopost-wrap .panel-heading .heading-elements .bg-danger:hover {
    background-color: #d32f2f !important;
    color: #ffffff !important;
}
.autopost-wrap .panel-heading .heading-elements .btn-warning,
.autopost-wrap .panel-heading .heading-elements .bg-orange-600 {
    background-color: #e65100 !important;
    color: #ffffff !important;
    border: 1px solid #bf360c !important;
}
.autopost-wrap .panel-heading .heading-elements .btn-warning:hover,
.autopost-wrap .panel-heading .heading-elements .bg-orange-600:hover {
    background-color: #f57c00 !important;
    color: #ffffff !important;
}

/* Card Buttons (Light & Dark) */
.autopost-wrap .btn-info,
.autopost-wrap .bg-info {
    background-color: #0288d1 !important;
    color: #ffffff !important;
    border: 1px solid #0277bd !important;
}
.autopost-wrap .btn-info:hover,
.autopost-wrap .bg-info:hover {
    background-color: #039be5 !important;
    color: #ffffff !important;
}
.autopost-wrap .btn-primary,
.autopost-wrap .bg-primary {
    background-color: #4a76a8 !important;
    color: #ffffff !important;
    border: 1px solid #3d6593 !important;
}
.autopost-wrap .btn-primary:hover,
.autopost-wrap .bg-primary:hover {
    background-color: #5282b8 !important;
    color: #ffffff !important;
}
body.dle_theme_dark .autopost-wrap .btn-default {
    background-color: #263238 !important;
    color: #eceff1 !important;
    border-color: #37474f !important;
}
body.dle_theme_dark .autopost-wrap .btn-default:hover {
    background-color: #37474f !important;
    color: #ffffff !important;
}

/* Input Addons */
.autopost-wrap .input-group-addon {
    background-color: var(--ap-input-addon-bg) !important;
    color: var(--ap-input-addon-color) !important;
    border-color: var(--ap-card-box-border) !important;
}

/* Info Box */
.autopost-wrap .ap-info-box {
    padding: 16px 20px;
    background-color: var(--ap-card-box-bg);
    border: 1px dashed var(--ap-card-box-border);
    border-radius: 4px;
    text-align: center;
    color: var(--ap-card-box-text);
}

/* Badges for Channels & Statuses */
.autopost-wrap .badge-tg {
    background-color: #0288d1 !important;
    color: #fff !important;
}
.autopost-wrap .badge-vk {
    background-color: #4a76a8 !important;
    color: #fff !important;
}
.autopost-wrap .badge-all {
    background-color: #5c6bc0 !important;
    color: #fff !important;
}

/* Headings in tables */
.autopost-wrap .table > tbody > tr > td {
    vertical-align: middle;
}
.autopost-wrap .panel-footer {
    padding: 12px 20px;
}
</style>

<div class="autopost-wrap">

    <!-- Header Banner with Logo -->
    <div class="panel panel-flat autopost-header">
        <div class="panel-body" style="padding: 15px 20px; display: flex; align-items: center;">
            <img src="engine/skins/images/autopost.png" alt="AutoPost" style="width: 50px; height: 50px; margin-right: 18px; object-fit: contain;">
            <div>
                <h4 class="autopost-title">DLE Social AutoPost</h4>
                <p class="autopost-desc">Автоматическая кросс-публикация пресс-релизов и новостей в Telegram-канал и сообщество ВКонтакте</p>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <?php
    $tabs = [
        'settings'    => ['title' => 'Настройки', 'icon' => 'fa-cogs', 'badge' => null],
        'queue'       => ['title' => 'Очередь задач', 'icon' => 'fa-tasks', 'badge' => $queueCount > 0 ? $queueCount : null],
        'logs'        => ['title' => 'Журнал публикаций', 'icon' => 'fa-history', 'badge' => $logsCount > 0 ? $logsCount : null],
        'diagnostics' => ['title' => 'Диагностика и тест', 'icon' => 'fa-stethoscope', 'badge' => null],
    ];
    ?>
    <div class="navbar navbar-default navbar-component navbar-xs">
        <ul class="nav navbar-nav">
            <?php foreach ($tabs as $k => $info): ?>
                <?php $active = ($tab === $k) ? 'active' : ''; ?>
                <li class="<?php echo $active; ?>">
                    <a href="<?php echo $selfUrl . '&tab=' . $k; ?>">
                        <i class="fa <?php echo $info['icon']; ?> position-left"></i>
                        <?php echo $info['title']; ?>
                        <?php if (!empty($info['badge'])): ?>
                            <span class="badge bg-slate-600 position-right"><?php echo $info['badge']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Flash message -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-styled-left alert-bordered" style="margin-bottom: 20px;">
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span><span class="sr-only">Close</span></button>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php
    // =========================================================================
    // TAB 1: SETTINGS
    // =========================================================================
    if ($tab === 'settings'):
    ?>
    <form action="<?php echo $selfUrl; ?>&tab=settings" method="post">
        <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
        <input type="hidden" name="action" value="save_settings">

        <!-- 1. Global Settings -->
        <div class="panel panel-default">
            <div class="panel-heading">
                <h6 class="panel-title"><i class="fa fa-sliders position-left"></i> Основные параметры автопостинга</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-xs">
                    <tbody>
                        <?php
                        // Row 1: Global Enable
                        apShowRow(
                            "Включить автопостинг",
                            "Глобальная активация автоматической публикации новостей в подключенные соцсети.",
                            apMakeSwitch("enabled", $cfg['enabled'] ?? 0)
                        );

                        // Row 2: Categories
                        global $cat_info;
                        $selectedCats = $cfg['categories'] ?? [];
                        $catOptions = "";
                        if (!empty($cat_info)) {
                            foreach ($cat_info as $catId => $cat) {
                                $sel = in_array($catId, $selectedCats) ? " selected" : "";
                                $catOptions .= "<option value=\"{$catId}\"{$sel}>" . htmlspecialchars($cat['name']) . "</option>";
                            }
                        }
                        $catField = "<select name=\"categories[]\" class=\"form-control\" multiple=\"multiple\" style=\"height: 130px;\">{$catOptions}</select>";
                        apShowRow(
                            "Категории для автопостинга",
                            "Выберите категории для автоматической публикации. Если ничего не выбрано — автопостинг активен для всех категорий сайта.",
                            $catField
                        );

                        // Row 3: Xfield Image
                        $xfieldVal = htmlspecialchars($cfg['xfield_image'] ?? '');
                        $xfieldField = "<input type=\"text\" class=\"form-control\" name=\"xfield_image\" value=\"{$xfieldVal}\" placeholder=\"например: poster или image\">";
                        apShowRow(
                            "Имя доп. поля (xfield) изображения",
                            "Плагин в первую очередь берет изображение из указанного xfield. Если поле не заполнено — берется первое изображение из текста новости.",
                            $xfieldField
                        );
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. Telegram Settings -->
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-tg">
                <h6 class="panel-title"><i class="fa fa-paper-plane position-left"></i> Настройки Telegram-канала</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-xs">
                    <tbody>
                        <?php
                        // TG Enable
                        apShowRow(
                            "Включить отправку в Telegram",
                            "Активировать кросс-постинг публикаций в Telegram-канал.",
                            apMakeSwitch("tg_enabled", $cfg['tg_enabled'] ?? 0)
                        );

                        // TG Bot Token
                        $botTokenVal = htmlspecialchars($cfg['tg_bot_token'] ?? '');
                        apShowRow(
                            "Токен Telegram бота",
                            "API токен вашего бота, полученный у официального бота @BotFather.",
                            "<input type=\"text\" class=\"form-control\" name=\"tg_bot_token\" value=\"{$botTokenVal}\" placeholder=\"123456789:ABCdefGHIjklMNOpqrSTUvwxYZ\">"
                        );

                        // TG Chat ID
                        $chatIdVal = htmlspecialchars($cfg['tg_chat_id'] ?? '');
                        apShowRow(
                            "ID или Username канала",
                            "Имя публичного канала (начиная с <code>@</code>) или числовой ID канала/супергруппы (например: <code>-1001234567890</code>). Бот должен быть администратором канала!",
                            "<input type=\"text\" class=\"form-control\" name=\"tg_chat_id\" value=\"{$chatIdVal}\" placeholder=\"@my_channel или -1001234567890\">"
                        );

                        // TG Endpoint API
                        $apiUrlVal = htmlspecialchars($cfg['tg_api_url'] ?? 'https://api.telegram.org');
                        apShowRow(
                            "Пользовательский Endpoint API Telegram",
                            "По умолчанию: <code>https://api.telegram.org</code>. Вы можете указать собственный шлюз, реверс-прокси или локальный telegram-bot-api сервер (например: <code>https://tg-api.mysite.com</code>).",
                            "<input type=\"text\" class=\"form-control\" name=\"tg_api_url\" value=\"{$apiUrlVal}\" placeholder=\"https://api.telegram.org\">"
                        );

                        // TG Template
                        $tgTplVal = htmlspecialchars($cfg['tg_template'] ?? '');
                        $tgTags = ['{title}', '{short-story}', '{full-story}', '{link}', '{tags}', '{category}', '{date}'];
                        $tgTagsHtml = '<div style="margin-top: 6px;">';
                        foreach ($tgTags as $tag) {
                            $tgTagsHtml .= "<span class=\"tag-badge\" onclick=\"apInsertTag(this, 'tg_template')\"><i class=\"fa fa-tag\"></i>{$tag}</span>";
                        }
                        $tgTagsHtml .= '</div>';
                        $tgTplField = "<textarea class=\"form-control\" name=\"tg_template\" rows=\"4\">{$tgTplVal}</textarea>{$tgTagsHtml}";
                        apShowRow(
                            "Шаблон сообщения Telegram",
                            "Поддерживается разметка HTML (<code>&lt;b&gt;</code>, <code>&lt;i&gt;</code>, <code>&lt;a&gt;</code>, <code>&lt;code&gt;</code>). Нажмите на тег, чтобы вставить его в текст.",
                            $tgTplField
                        );

                        // TG Inline Button
                        $btnEnabled = apMakeSwitch("tg_btn_enable", $cfg['tg_btn_enable'] ?? 0);
                        $btnTextVal = htmlspecialchars($cfg['tg_btn_text'] ?? 'Читать на сайте');
                        $btnField = "
                            <div style=\"display: flex; align-items: center; gap: 15px;\">
                                <div>{$btnEnabled}</div>
                                <div style=\"flex: 1;\">
                                    <input type=\"text\" class=\"form-control input-sm\" name=\"tg_btn_text\" value=\"{$btnTextVal}\" placeholder=\"Текст на кнопке\">
                                </div>
                            </div>
                        ";
                        apShowRow(
                            "Кнопка-ссылка (Inline Button)",
                            "Прикреплять к сообщению инлайн-кнопку со ссылкой на полную новость сайта.",
                            $btnField
                        );

                        // TG Proxy
                        $proxyEnabled = apMakeSwitch("tg_proxy_enable", $cfg['tg_proxy_enable'] ?? 0);
                        $proxyType = $cfg['tg_proxy_type'] ?? 'socks5';
                        $proxyAddrVal = htmlspecialchars($cfg['tg_proxy_addr'] ?? '');
                        $proxyAuthVal = htmlspecialchars($cfg['tg_proxy_auth'] ?? '');
                        $proxyField = "
                            <div style=\"margin-bottom: 8px;\">{$proxyEnabled} <span class=\"text-muted text-size-small\" style=\"margin-left: 8px;\">Использовать прокси (если доступ к Telegram ограничен)</span></div>
                            <div class=\"row\">
                                <div class=\"col-sm-3\">
                                    <select name=\"tg_proxy_type\" class=\"form-control input-sm\">
                                        <option value=\"socks5\"" . ($proxyType === 'socks5' ? ' selected' : '') . ">SOCKS5</option>
                                        <option value=\"http\"" . ($proxyType === 'http' ? ' selected' : '') . ">HTTP / HTTPS</option>
                                    </select>
                                </div>
                                <div class=\"col-sm-5\">
                                    <input type=\"text\" class=\"form-control input-sm\" name=\"tg_proxy_addr\" value=\"{$proxyAddrVal}\" placeholder=\"ip:порт (например 127.0.0.1:9050)\">
                                </div>
                                <div class=\"col-sm-4\">
                                    <input type=\"text\" class=\"form-control input-sm\" name=\"tg_proxy_auth\" value=\"{$proxyAuthVal}\" placeholder=\"логин:пароль (опционально)\">
                                </div>
                            </div>
                            <div style=\"margin-top: 8px;\">
                                <a href=\"{$selfUrl}&tab=diagnostics&check_network=1\" class=\"btn btn-xs btn-default\"><i class=\"fa fa-wifi position-left text-orange\"></i>Проверить доступность Endpoint Telegram</a>
                            </div>
                        ";
                        apShowRow(
                            "Прокси-сервер для Telegram",
                            "Настройки сетевого шлюза для обхода блокировок или маршрутизации трафика.",
                            $proxyField
                        );
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. VKontakte Settings -->
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-vk">
                <h6 class="panel-title"><i class="fa fa-vk position-left"></i> Настройки группы ВКонтакте</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-xs">
                    <tbody>
                        <?php
                        // VK Enable
                        apShowRow(
                            "Включить отправку в VK",
                            "Активировать публикацию записей на стену сообщества ВКонтакте.",
                            apMakeSwitch("vk_enabled", $cfg['vk_enabled'] ?? 0)
                        );

                        // VK Token
                        $vkTokenVal = htmlspecialchars($cfg['vk_token'] ?? '');
                        apShowRow(
                            "Токен сообщества (Community Token)",
                            "Ключ доступа сообщества с правами «Стена» и «Фотографии» (Управление сообществом &rarr; Работа с API &rarr; Ключи доступа).",
                            "<input type=\"text\" class=\"form-control\" name=\"vk_token\" value=\"{$vkTokenVal}\" placeholder=\"vk1.a.ABCdef...\">"
                        );

                        // VK Group ID
                        $vkGroupIdVal = htmlspecialchars($cfg['vk_group_id'] ?? '');
                        apShowRow(
                            "ID группы ВКонтакте",
                            "Числовой идентификатор сообщества (без знака минус). Например: <code>123456789</code>.",
                            "<input type=\"text\" class=\"form-control\" name=\"vk_group_id\" value=\"{$vkGroupIdVal}\" placeholder=\"например: 123456789\">"
                        );

                        // VK Wall Options
                        $vkFromGroup = apMakeSwitch("vk_from_group", $cfg['vk_from_group'] ?? 1);
                        $vkSigned = apMakeSwitch("vk_signed", $cfg['vk_signed'] ?? 0);
                        $vkOptionsField = "
                            <div style=\"display: flex; gap: 25px; align-items: center; flex-wrap: wrap;\">
                                <label style=\"display: inline-flex; align-items: center; margin: 0; cursor: pointer;\">
                                    {$vkFromGroup} <span style=\"margin-left: 8px;\">От имени группы</span>
                                </label>
                                <label style=\"display: inline-flex; align-items: center; margin: 0; cursor: pointer;\">
                                    {$vkSigned} <span style=\"margin-left: 8px;\">Добавлять подпись автора</span>
                                </label>
                            </div>
                        ";
                        apShowRow(
                            "Параметры записи на стене",
                            "Опции авторства и отображения публикации в ленте сообщества.",
                            $vkOptionsField
                        );

                        // VK Template
                        $vkTplVal = htmlspecialchars($cfg['vk_template'] ?? '');
                        $vkTagsHtml = '<div style="margin-top: 6px;">';
                        foreach ($tgTags as $tag) {
                            $vkTagsHtml .= "<span class=\"tag-badge\" onclick=\"apInsertTag(this, 'vk_template')\"><i class=\"fa fa-tag\"></i>{$tag}</span>";
                        }
                        $vkTagsHtml .= '</div>';
                        $vkTplField = "<textarea class=\"form-control\" name=\"vk_template\" rows=\"4\">{$vkTplVal}</textarea>{$vkTagsHtml}";
                        apShowRow(
                            "Шаблон записи ВКонтакте",
                            "Текстовый шаблон публикации с поддержкой тегов подстановки.",
                            $vkTplField
                        );
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 4. UTM Analytics Settings -->
        <div class="panel panel-default">
            <div class="panel-heading">
                <h6 class="panel-title"><i class="fa fa-line-chart position-left"></i> Настройка UTM-меток для ссылок</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-xs">
                    <tbody>
                        <?php
                        // UTM Enable
                        apShowRow(
                            "Добавлять UTM-метки",
                            "Автоматически дополнять URL новости UTM-метками для сквозной аналитики в Яндекс.Метрике и Google Analytics.",
                            apMakeSwitch("utm_enable", $cfg['utm_enable'] ?? 0)
                        );

                        // UTM Telegram
                        $utmSrcTg = htmlspecialchars($cfg['utm_source_tg'] ?? 'telegram');
                        $utmMedTg = htmlspecialchars($cfg['utm_medium_tg'] ?? 'channel');
                        $utmCmpTg = htmlspecialchars($cfg['utm_campaign_tg'] ?? 'press_release');
                        $utmTgField = "
                            <div class=\"row\">
                                <div class=\"col-sm-4\">
                                    <div class=\"input-group\">
                                        <span class=\"input-group-addon\">source</span>
                                        <input type=\"text\" class=\"form-control input-sm\" name=\"utm_source_tg\" value=\"{$utmSrcTg}\">
                                    </div>
                                </div>
                                <div class=\"col-sm-4\">
                                    <div class=\"input-group\">
                                        <span class=\"input-group-addon\">medium</span>
                                        <input type=\"text\" class=\"form-control input-sm\" name=\"utm_medium_tg\" value=\"{$utmMedTg}\">
                                    </div>
                                </div>
                                <div class=\"col-sm-4\">
                                    <div class=\"input-group\">
                                        <span class=\"input-group-addon\">campaign</span>
                                        <input type=\"text\" class=\"form-control input-sm\" name=\"utm_campaign_tg\" value=\"{$utmCmpTg}\">
                                    </div>
                                </div>
                            </div>
                        ";
                        apShowRow(
                            "Метки для Telegram",
                            "Значения параметров utm_source, utm_medium, utm_campaign для Telegram.",
                            $utmTgField
                        );

                        // UTM VK
                        $utmSrcVk = htmlspecialchars($cfg['utm_source_vk'] ?? 'vk');
                        $utmMedVk = htmlspecialchars($cfg['utm_medium_vk'] ?? 'wall');
                        $utmCmpVk = htmlspecialchars($cfg['utm_campaign_vk'] ?? 'press_release');
                        $utmVkField = "
                            <div class=\"row\">
                                <div class=\"col-sm-4\">
                                    <div class=\"input-group\">
                                        <span class=\"input-group-addon\">source</span>
                                        <input type=\"text\" class=\"form-control input-sm\" name=\"utm_source_vk\" value=\"{$utmSrcVk}\">
                                    </div>
                                </div>
                                <div class=\"col-sm-4\">
                                    <div class=\"input-group\">
                                        <span class=\"input-group-addon\">medium</span>
                                        <input type=\"text\" class=\"form-control input-sm\" name=\"utm_medium_vk\" value=\"{$utmMedVk}\">
                                    </div>
                                </div>
                                <div class=\"col-sm-4\">
                                    <div class=\"input-group\">
                                        <span class=\"input-group-addon\">campaign</span>
                                        <input type=\"text\" class=\"form-control input-sm\" name=\"utm_campaign_vk\" value=\"{$utmCmpVk}\">
                                    </div>
                                </div>
                            </div>
                        ";
                        apShowRow(
                            "Метки для ВКонтакте",
                            "Значения параметров utm_source, utm_medium, utm_campaign для ВКонтакте.",
                            $utmVkField
                        );
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. CRON & Queue Settings -->
        <div class="panel panel-default">
            <div class="panel-heading">
                <h6 class="panel-title"><i class="fa fa-clock-o position-left"></i> Очередь и планировщик (CRON)</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-xs">
                    <tbody>
                        <?php
                        // Cron Secret Key
                        $cronKeyVal = htmlspecialchars($cfg['cron_key'] ?? '');
                        apShowRow(
                            "Секретный ключ CRON",
                            "Служит для авторизации вызова скрипта крона по протоколу HTTP.",
                            "<input type=\"text\" class=\"form-control\" name=\"cron_key\" value=\"{$cronKeyVal}\">"
                        );

                        // Cron CLI Command
                        $cliCmd = "*/2 * * * * /usr/bin/php " . ROOT_DIR . "/cron/autopost.php >/dev/null 2>&1";
                        $cliField = "
                            <div class=\"ap-code-container\" style=\"position: relative;\">
                                <pre class=\"ap-code-block\">{$cliCmd}</pre>
                                <button type=\"button\" class=\"btn btn-xs btn-default ap-copy-btn\" onclick=\"apCopy(this, '{$cliCmd}')\"><i class=\"fa fa-copy position-left\"></i>Копировать</button>
                            </div>
                        ";
                        apShowRow(
                            "Команда для crontab (CLI)",
                            "Рекомендуемая команда для системного планировщика cron сервера (запуск каждые 2 минуты).",
                            $cliField
                        );

                        // Cron HTTP Web Call
                        global $config;
                        $cronHttpUrl = rtrim($config['http_home_url'], '/') . '/cron/autopost.php?key=' . urlencode($cfg['cron_key'] ?? '');
                        $httpCmd = "curl -s \"{$cronHttpUrl}\" >/dev/null 2>&1";
                        $httpField = "
                            <div class=\"ap-code-container\" style=\"position: relative;\">
                                <pre class=\"ap-code-block\">{$httpCmd}</pre>
                                <button type=\"button\" class=\"btn btn-xs btn-default ap-copy-btn\" onclick=\"apCopy(this, '{$httpCmd}')\"><i class=\"fa fa-copy position-left\"></i>Копировать</button>
                            </div>
                        ";
                        apShowRow(
                            "Вызов через Web (HTTP/Curl)",
                            "Альтернативный запуск через внешний веб-планировщик (cron-job.org, webcron или curl).",
                            $httpField
                        );

                        // Retries Limit
                        $retriesVal = intval($cfg['max_retries'] ?? 3);
                        apShowRow(
                            "Лимит повторных попыток",
                            "Максимальное количество повторов отправки задачи из очереди при сетевых ошибках.",
                            "<input type=\"number\" class=\"form-control width-150\" name=\"max_retries\" value=\"{$retriesVal}\" min=\"1\" max=\"10\">"
                        );

                        // Logs Limit
                        $limitVal = intval($cfg['log_limit'] ?? 500);
                        apShowRow(
                            "Хранить записей в журнале",
                            "Автоматическая очистка старых записей при превышении лимита (от 50 до 5000).",
                            "<input type=\"number\" class=\"form-control width-150\" name=\"log_limit\" value=\"{$limitVal}\" min=\"50\" max=\"5000\">"
                        );
                        ?>
                    </tbody>
                </table>
            </div>
            <div class="panel-footer">
                <button type="submit" class="btn bg-teal btn-sm btn-raised position-left">
                    <i class="fa fa-floppy-o position-left"></i>Сохранить настройки
                </button>
            </div>
        </div>
    </form>
    <?php
    endif;

    // =========================================================================
    // TAB 2: QUEUE
    // =========================================================================
    if ($tab === 'queue'):
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;
        $statusFilter = !empty($_GET['status']) ? $_GET['status'] : null;

        $tasks = Autopost_Queue::getList($limit, $offset, $statusFilter);
        $totalTasks = Autopost_Queue::count($statusFilter);
        $totalPages = ceil($totalTasks / $limit);
    ?>
    <div class="panel panel-default">
        <div class="panel-heading">
            <h6 class="panel-title"><i class="fa fa-tasks position-left"></i> Задачи в очереди публикации (Всего: <?php echo $totalTasks; ?>)</h6>
            <div class="heading-elements not-collapsible">
                <form action="<?php echo $selfUrl; ?>&tab=queue" method="post" style="display:inline-block; margin-right: 6px;">
                    <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                    <input type="hidden" name="action" value="process_queue">
                    <button type="submit" class="btn btn-xs bg-success btn-raised"><i class="fa fa-play position-left"></i>Запустить очередь сейчас</button>
                </form>
                <form action="<?php echo $selfUrl; ?>&tab=queue" method="post" style="display:inline-block;" onsubmit="return confirm('Очистить завершенные задачи?');">
                    <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                    <input type="hidden" name="action" value="clear_queue">
                    <input type="hidden" name="clear_type" value="done">
                    <button type="submit" class="btn btn-xs btn-default btn-raised"><i class="fa fa-trash position-left"></i>Удалить завершенные</button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover table-xs">
                <thead>
                    <tr>
                        <th width="60">ID</th>
                        <th width="90">Новость</th>
                        <th>Заголовок новости</th>
                        <th width="120">Канал</th>
                        <th width="120">Статус</th>
                        <th width="90">Попыток</th>
                        <th>Ошибка (если есть)</th>
                        <th width="160">Обновлено</th>
                        <th width="100" class="text-right">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tasks)): ?>
                        <tr><td colspan="9" class="text-center text-muted" style="padding: 30px 20px;">Очередь задач пуста</td></tr>
                    <?php else: ?>
                        <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td><b>#<?php echo $t['id']; ?></b></td>
                                <td><a href="<?php echo $config['http_home_url']; ?>index.php?newsid=<?php echo $t['news_id']; ?>" target="_blank" class="text-semibold">#<?php echo $t['news_id']; ?></a></td>
                                <td>
                                    <a href="?mod=editnews&action=editnews&id=<?php echo $t['news_id']; ?>" target="_blank" class="text-semibold">
                                        <?php echo htmlspecialchars($t['news_title'] ?? 'Новость удалена'); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    if ($t['target'] === 'all') echo '<span class="badge badge-all">TG + VK</span>';
                                    elseif ($t['target'] === 'telegram') echo '<span class="badge badge-tg"><i class="fa fa-paper-plane"></i> Telegram</span>';
                                    else echo '<span class="badge badge-vk"><i class="fa fa-vk"></i> ВКонтакте</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    switch ($t['status']) {
                                        case 'pending': echo '<span class="label label-warning">Ожидание</span>'; break;
                                        case 'processing': echo '<span class="label label-info">В процессе</span>'; break;
                                        case 'done': echo '<span class="label label-success">Выполнено</span>'; break;
                                        case 'failed': echo '<span class="label label-danger">Сбой</span>'; break;
                                    }
                                    ?>
                                </td>
                                <td><b><?php echo $t['attempts']; ?></b> / <?php echo $t['max_attempts']; ?></td>
                                <td class="text-danger text-size-small"><?php echo htmlspecialchars($t['error_message'] ?? '—'); ?></td>
                                <td class="text-muted text-size-small"><?php echo $t['updated_at']; ?></td>
                                <td class="text-right">
                                    <form action="<?php echo $selfUrl; ?>&tab=queue" method="post" style="display:inline-block;">
                                        <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                                        <input type="hidden" name="action" value="retry_queue_item">
                                        <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
                                        <button type="submit" class="btn btn-xs btn-default" title="Повторить"><i class="fa fa-refresh"></i></button>
                                    </form>
                                    <form action="<?php echo $selfUrl; ?>&tab=queue" method="post" style="display:inline-block;" onsubmit="return confirm('Удалить эту задачу?');">
                                        <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                                        <input type="hidden" name="action" value="delete_queue_item">
                                        <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
                                        <button type="submit" class="btn btn-xs btn-danger" title="Удалить"><i class="fa fa-times"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    endif;

    // =========================================================================
    // TAB 3: LOGS
    // =========================================================================
    if ($tab === 'logs'):
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;
        $channelFilter = !empty($_GET['channel']) ? $_GET['channel'] : null;
        $statusFilter = !empty($_GET['status']) ? $_GET['status'] : null;

        $logs = Autopost_Logger::getLogs($limit, $offset, $channelFilter, $statusFilter);
        $totalLogs = Autopost_Logger::countLogs($channelFilter, $statusFilter);
    ?>
    <div class="panel panel-default">
        <div class="panel-heading">
            <h6 class="panel-title"><i class="fa fa-history position-left"></i> Журнал отправленных сообщений (Всего: <?php echo $totalLogs; ?>)</h6>
            <div class="heading-elements not-collapsible">
                <form action="<?php echo $selfUrl; ?>&tab=logs" method="post" onsubmit="return confirm('Очистить весь журнал публикаций?');">
                    <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                    <input type="hidden" name="action" value="clear_logs">
                    <button type="submit" class="btn btn-xs btn-danger btn-raised"><i class="fa fa-trash position-left"></i>Очистить журнал</button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover table-xs">
                <thead>
                    <tr>
                        <th width="70">ID</th>
                        <th width="150">Дата</th>
                        <th width="80">Новость</th>
                        <th>Заголовок новости</th>
                        <th width="120">Канал</th>
                        <th width="100">Статус</th>
                        <th width="160">ID поста в сети</th>
                        <th>Ошибка API (если была)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="8" class="text-center text-muted" style="padding: 30px 20px;">Журнал публикаций пуст</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td><b>#<?php echo $l['id']; ?></b></td>
                                <td class="text-muted text-size-small"><?php echo $l['post_date']; ?></td>
                                <td><a href="<?php echo $config['http_home_url']; ?>index.php?newsid=<?php echo $l['news_id']; ?>" target="_blank" class="text-semibold">#<?php echo $l['news_id']; ?></a></td>
                                <td>
                                    <a href="?mod=editnews&action=editnews&id=<?php echo $l['news_id']; ?>" target="_blank" class="text-semibold">
                                        <?php echo htmlspecialchars($l['news_title'] ?? 'Новость удалена'); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php echo ($l['channel'] === 'telegram')
                                        ? '<span class="badge badge-tg"><i class="fa fa-paper-plane"></i> Telegram</span>'
                                        : '<span class="badge badge-vk"><i class="fa fa-vk"></i> ВКонтакте</span>'; ?>
                                </td>
                                <td>
                                    <?php echo ($l['status'] === 'success')
                                        ? '<span class="label label-success">Успешно</span>'
                                        : '<span class="label label-danger">Ошибка</span>'; ?>
                                </td>
                                <td><code><?php echo htmlspecialchars($l['remote_id'] ?? '—'); ?></code></td>
                                <td class="text-danger text-size-small"><?php echo htmlspecialchars($l['error_message'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    endif;

    // =========================================================================
    // TAB 4: DIAGNOSTICS & TEST
    // =========================================================================
    if ($tab === 'diagnostics'):
    ?>
    <!-- Network Diagnostics (Endpoint Availability check) -->
    <div class="panel panel-default" style="border-top: 3px solid #ff9800; margin-bottom: 25px;">
        <div class="panel-heading">
            <h6 class="panel-title"><i class="fa fa-wifi position-left text-orange"></i> Сетевая диагностика доступности Endpoint Telegram API</h6>
            <div class="heading-elements not-collapsible">
                <form action="<?php echo $selfUrl; ?>&tab=diagnostics" method="post" style="margin: 0;">
                    <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                    <input type="hidden" name="action" value="check_telegram_network">
                    <input type="hidden" name="tg_api_url" value="<?php echo htmlspecialchars($cfg['tg_api_url'] ?? ''); ?>">
                    <input type="hidden" name="tg_proxy_type" value="<?php echo htmlspecialchars($cfg['tg_proxy_type'] ?? 'socks5'); ?>">
                    <input type="hidden" name="tg_proxy_addr" value="<?php echo htmlspecialchars($cfg['tg_proxy_addr'] ?? ''); ?>">
                    <input type="hidden" name="tg_proxy_auth" value="<?php echo htmlspecialchars($cfg['tg_proxy_auth'] ?? ''); ?>">
                    <button type="submit" class="btn btn-xs btn-warning btn-raised"><i class="fa fa-refresh position-left"></i>Запустить проверку доступности</button>
                </form>
            </div>
        </div>
        <div class="panel-body">
            <p class="text-muted" style="margin-bottom: 15px;">
                Данный инструмент проверяет сетевое подключение к официальному серверу Telegram (<code>api.telegram.org</code>) или указанному пользовательскому эндпоинту, замеряя время разрешения DNS, TCP-соединения и TLS-рукопожатия для диагностики стабильности связи.
            </p>

            <?php if (!empty($tgNetworkReport)): ?>
                <!-- Verdict Alert -->
                <?php
                $v = $tgNetworkReport['verdict'];
                $alertClass = 'alert-' . ($v['type'] ?? 'info');
                ?>
                <div class="alert <?php echo $alertClass; ?> alert-styled-left alert-bordered" style="margin-bottom: 20px;">
                    <h5 style="margin-top: 0; font-weight: 600;"><i class="fa fa-info-circle"></i> Вердикт: <?php echo htmlspecialchars($v['badge']); ?></h5>
                    <?php echo htmlspecialchars($v['message']); ?>
                </div>

                <!-- Detailed Results Table -->
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-xs">
                        <thead>
                            <tr>
                                <th>Эндпоинт / Направление</th>
                                <th width="140">Статус сети</th>
                                <th width="110">HTTP код</th>
                                <th width="110">Пинг (всего)</th>
                                <th width="90">DNS</th>
                                <th width="110">TCP Connect</th>
                                <th width="110">TLS Handshake</th>
                                <th>Детали / Ошибка cURL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tgNetworkReport['tests'] as $key => $test): ?>
                                <?php $r = $test['result']; ?>
                                <tr>
                                    <td>
                                        <b><?php echo htmlspecialchars($test['name']); ?></b><br>
                                        <small class="text-muted"><code><?php echo htmlspecialchars($test['url']); ?></code></small>
                                    </td>
                                    <td>
                                        <?php if ($r['success']): ?>
                                            <span class="label label-success"><i class="fa fa-check"></i> Доступен</span>
                                        <?php else: ?>
                                            <span class="label label-danger"><i class="fa fa-times"></i> Недоступен</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo $r['http_code'] > 0 ? $r['http_code'] : '0 (нет ответа)'; ?></code></td>
                                    <td><b><?php echo $r['time_ms']; ?> ms</b></td>
                                    <td><?php echo $r['dns_ms']; ?> ms</td>
                                    <td><?php echo $r['connect_ms']; ?> ms</td>
                                    <td><?php echo $r['tls_ms']; ?> ms</td>
                                    <td>
                                        <?php if (!empty($r['error'])): ?>
                                            <span class="text-danger text-size-small" style="font-family:monospace;"><?php echo htmlspecialchars($r['error']); ?></span>
                                        <?php else: ?>
                                            <span class="text-success text-size-small"><i class="fa fa-check-circle"></i> Успешное соединение с сервером</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="ap-info-box">
                    <p style="margin: 0;"><i class="fa fa-info-circle position-left"></i>Нажмите кнопку <b>«Запустить проверку доступности»</b> в правом углу, чтобы протестировать сетевое подключение к Telegram с этого сервера.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Connection Tests Side-by-Side -->
    <div class="row">
        <!-- Test Telegram -->
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading panel-heading-tg">
                    <h6 class="panel-title"><i class="fa fa-paper-plane position-left"></i> Проверка подключения к Telegram</h6>
                </div>
                <div class="panel-body">
                    <form action="<?php echo $selfUrl; ?>&tab=diagnostics" method="post">
                        <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                        <input type="hidden" name="action" value="test_telegram">

                        <div class="form-group">
                            <label class="text-semibold">Токен Telegram бота:</label>
                            <input type="text" class="form-control" name="tg_bot_token" value="<?php echo htmlspecialchars($cfg['tg_bot_token'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="text-semibold">ID или Username канала:</label>
                            <input type="text" class="form-control" name="tg_chat_id" value="<?php echo htmlspecialchars($cfg['tg_chat_id'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="text-semibold">Endpoint API Telegram:</label>
                            <input type="text" class="form-control" name="tg_api_url" value="<?php echo htmlspecialchars($cfg['tg_api_url'] ?? 'https://api.telegram.org'); ?>">
                        </div>

                        <input type="hidden" name="tg_proxy_enable" value="<?php echo !empty($cfg['tg_proxy_enable']) ? '1' : '0'; ?>">
                        <input type="hidden" name="tg_proxy_type" value="<?php echo htmlspecialchars($cfg['tg_proxy_type'] ?? 'socks5'); ?>">
                        <input type="hidden" name="tg_proxy_addr" value="<?php echo htmlspecialchars($cfg['tg_proxy_addr'] ?? ''); ?>">
                        <input type="hidden" name="tg_proxy_auth" value="<?php echo htmlspecialchars($cfg['tg_proxy_auth'] ?? ''); ?>">

                        <button type="submit" class="btn btn-info btn-sm btn-raised"><i class="fa fa-plug position-left"></i>Проверить подключение к Telegram</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Test VK -->
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading panel-heading-vk">
                    <h6 class="panel-title"><i class="fa fa-vk position-left"></i> Проверка подключения к ВКонтакте</h6>
                </div>
                <div class="panel-body">
                    <form action="<?php echo $selfUrl; ?>&tab=diagnostics" method="post">
                        <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                        <input type="hidden" name="action" value="test_vk">

                        <div class="form-group">
                            <label class="text-semibold">Токен сообщества (Community Token):</label>
                            <input type="text" class="form-control" name="vk_token" value="<?php echo htmlspecialchars($cfg['vk_token'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="text-semibold">ID группы (число без минуса):</label>
                            <input type="text" class="form-control" name="vk_group_id" value="<?php echo htmlspecialchars($cfg['vk_group_id'] ?? ''); ?>">
                        </div>

                        <div style="height: 60px;"></div>

                        <button type="submit" class="btn btn-primary btn-sm btn-raised"><i class="fa fa-plug position-left"></i>Проверить подключение к VK</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Test Send of Specific News -->
    <div class="panel panel-default">
        <div class="panel-heading">
            <h6 class="panel-title"><i class="fa fa-paper-plane position-left"></i> Ручная тестовая отправка существующей новости</h6>
        </div>
        <div class="panel-body">
            <form action="<?php echo $selfUrl; ?>&tab=diagnostics" method="post" class="form-inline">
                <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                <input type="hidden" name="action" value="send_manual_test">

                <div class="form-group" style="margin-right: 15px;">
                    <label style="margin-right: 8px;">ID новости в DLE:</label>
                    <input type="number" class="form-control" name="test_news_id" placeholder="например 1" required style="width: 130px;">
                </div>

                <div class="form-group" style="margin-right: 15px;">
                    <label style="margin-right: 8px;">Куда отправить:</label>
                    <select name="test_target" class="form-control">
                        <option value="all">И в Telegram, и в VK</option>
                        <option value="telegram">Только в Telegram</option>
                        <option value="vk">Только в ВКонтакте</option>
                    </select>
                </div>

                <button type="submit" class="btn bg-teal btn-raised"><i class="fa fa-send position-left"></i>Отправить тестовый пост</button>
            </form>
            <div class="text-muted text-size-small" style="margin-top: 10px;">
                Позволяет проверить, как реальная новость с вашего сайта будет выглядеть в канале Telegram и группе VK перед включением автоматического режима.
            </div>
        </div>
    </div>
    <?php
    endif;
    ?>

</div><!-- /.autopost-wrap -->

<!-- Interactive Helpers: Tag Inserter & Copy to Clipboard -->
<script>
function apInsertTag(badgeEl, textareaName) {
    var tag = badgeEl.innerText || badgeEl.textContent;
    tag = tag.trim();
    var ta = document.querySelector('textarea[name="' + textareaName + '"]');
    if (!ta) return;
    
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var text = ta.value;
    
    ta.value = text.substring(0, start) + tag + text.substring(end);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = start + tag.length;
}

function apCopy(btn, text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            var oldHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-check"></i> Скопировано';
            setTimeout(function() { btn.innerHTML = oldHtml; }, 2000);
        });
    } else {
        var input = document.createElement('textarea');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        var oldHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa fa-check"></i> Скопировано';
        setTimeout(function() { btn.innerHTML = oldHtml; }, 2000);
    }
}
</script>

<?php
echofooter();
