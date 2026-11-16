<?php
/**
 * DLE Social AutoPost Plugin
 * Admin Control Panel Module
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
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
    }

    // 2.1 Check Telegram Endpoint Availability (Network / TSPU check)
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

$cfg = Autopost_Config::get();

// -----------------------------------------------------------------------------
// UI Render
// -----------------------------------------------------------------------------
echoheader("Автопостинг VK & Telegram", "Публикация пресс-релизов и новостей в Telegram-канал и группу ВКонтакте");

// Navigation Tabs
$tabs = [
    'settings'    => ['title' => 'Настройки', 'icon' => 'fa-cogs'],
    'queue'       => ['title' => 'Очередь задач', 'icon' => 'fa-tasks'],
    'logs'        => ['title' => 'Журнал публикаций', 'icon' => 'fa-history'],
    'diagnostics' => ['title' => 'Диагностика и тест', 'icon' => 'fa-stethoscope'],
];

echo '<div class="navbar navbar-default navbar-component navbar-xs" style="margin-bottom: 20px;">';
echo '<ul class="nav navbar-nav">';
foreach ($tabs as $k => $info) {
    $active = ($tab === $k) ? 'active' : '';
    echo "<li class='{$active}'><a href='{$selfUrl}&tab={$k}'><i class='fa {$info['icon']} position-left'></i> {$info['title']}</a></li>";
}
echo '</ul>';
echo '</div>';

// Flash message
if (!empty($message)) {
    echo "<div class='alert alert-{$messageType} alert-styled-left alert-bordered' style='margin-bottom: 20px;'>
            <button type='button' class='close' data-dismiss='alert'><span>&times;</span><span class='sr-only'>Close</span></button>
            {$message}
          </div>";
}

// -----------------------------------------------------------------------------
// TAB 1: SETTINGS
// -----------------------------------------------------------------------------
if ($tab === 'settings'):
?>
<form action="<?php echo $selfUrl; ?>&tab=settings" method="post" class="form-horizontal">
    <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
    <input type="hidden" name="action" value="save_settings">

    <!-- Global Settings -->
    <div class="panel panel-default">
        <div class="panel-heading">
            <h6 class="panel-title"><i class="fa fa-sliders"></i> Основные параметры</h6>
        </div>
        <div class="panel-body">
            <div class="form-group">
                <label class="control-label col-md-3">Включить автопостинг:</label>
                <div class="col-md-9">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="enabled" value="1" <?php echo !empty($cfg['enabled']) ? 'checked' : ''; ?>>
                        Глобальное включение автоматической отправки постов
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Категории для автопостинга:</label>
                <div class="col-md-9">
                    <select name="categories[]" class="form-control" multiple="multiple" style="height: 140px;">
                        <?php
                        global $cat_info;
                        $selectedCats = $cfg['categories'] ?? [];
                        if (!empty($cat_info)) {
                            foreach ($cat_info as $catId => $cat) {
                                $sel = in_array($catId, $selectedCats) ? 'selected' : '';
                                echo "<option value='{$catId}' {$sel}>" . htmlspecialchars($cat['name']) . "</option>";
                            }
                        }
                        ?>
                    </select>
                    <span class="help-block">Выберите категории (например, «Пресс-релизы», «Новости»). Если ничего не выбрано — автопостинг активен для всех категорий.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Имя доп. поля (xfield) изображения:</label>
                <div class="col-md-4">
                    <input type="text" class="form-control" name="xfield_image" value="<?php echo htmlspecialchars($cfg['xfield_image'] ?? ''); ?>" placeholder="например: poster или image">
                    <span class="help-block">Если заполнено, плагин в первую очередь берет изображение из этого xfield. Если пусто или xfield не заполнен — берется первое изображение из текста новости.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Telegram Channel Settings -->
    <div class="panel panel-default">
        <div class="panel-heading" style="background-color: #f7fbff; border-left: 3px solid #0088cc;">
            <h6 class="panel-title"><i class="fa fa-paper-plane" style="color:#0088cc;"></i> Настройки Telegram-канала</h6>
        </div>
        <div class="panel-body">
            <div class="form-group">
                <label class="control-label col-md-3">Включить отправку в Telegram:</label>
                <div class="col-md-9">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="tg_enabled" value="1" <?php echo !empty($cfg['tg_enabled']) ? 'checked' : ''; ?>>
                        Активировать публикацию в Telegram
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Токен Telegram бота:</label>
                <div class="col-md-6">
                    <input type="text" class="form-control" name="tg_bot_token" value="<?php echo htmlspecialchars($cfg['tg_bot_token'] ?? ''); ?>" placeholder="123456789:ABCdefGHIjklMNOpqrSTUvwxYZ">
                    <span class="help-block">Токен бота, полученный у @BotFather.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">ID или Username канала:</label>
                <div class="col-md-4">
                    <input type="text" class="form-control" name="tg_chat_id" value="<?php echo htmlspecialchars($cfg['tg_chat_id'] ?? ''); ?>" placeholder="@my_channel или -1001234567890">
                    <span class="help-block">Имя публичного канала (начиная с @) или числовой ID канала/супергруппы. Бот должен быть администратором канала с правом публикации!</span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Кастомный URL API Telegram (Зеркало / Reverse Proxy):</label>
                <div class="col-md-6">
                    <input type="text" class="form-control" name="tg_api_url" value="<?php echo htmlspecialchars($cfg['tg_api_url'] ?? 'https://api.telegram.org'); ?>" placeholder="https://api.telegram.org">
                    <span class="help-block">По умолчанию: <code>https://api.telegram.org</code>. Если прямой доступ к серверам Telegram заблокирован ТСПУ/РКН на вашем хостинге, укажите URL вашего прокси-зеркала (Cloudflare Worker, Nginx reverse proxy или локальный telegram-bot-api сервер, например <code>https://tg-proxy.mysite.com</code>).</span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Шаблон сообщения Telegram:</label>
                <div class="col-md-7">
                    <textarea class="form-control" name="tg_template" rows="5"><?php echo htmlspecialchars($cfg['tg_template'] ?? ''); ?></textarea>
                    <span class="help-block">Доступные теги: <code>{title}</code>, <code>{short-story}</code>, <code>{full-story}</code>, <code>{link}</code>, <code>{tags}</code>, <code>{category}</code>, <code>{date}</code>. Поддерживается HTML (<code>&lt;b&gt;</code>, <code>&lt;i&gt;</code>, <code>&lt;a&gt;</code>, <code>&lt;code&gt;</code>).</span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Кнопка-ссылка (Inline Button):</label>
                <div class="col-md-9">
                    <label class="checkbox-inline" style="margin-bottom: 8px;">
                        <input type="checkbox" name="tg_btn_enable" value="1" <?php echo !empty($cfg['tg_btn_enable']) ? 'checked' : ''; ?>>
                        Прикреплять инлайн-кнопку со ссылкой на новость
                    </label>
                    <div style="max-width: 300px;">
                        <input type="text" class="form-control input-sm" name="tg_btn_text" value="<?php echo htmlspecialchars($cfg['tg_btn_text'] ?? 'Читать на сайте'); ?>" placeholder="Текст на кнопке">
                    </div>
                </div>
            </div>

            <!-- Telegram Proxy -->
            <div class="form-group">
                <label class="control-label col-md-3">Прокси для Telegram:</label>
                <div class="col-md-9">
                    <label class="checkbox-inline" style="margin-bottom: 8px;">
                        <input type="checkbox" name="tg_proxy_enable" value="1" <?php echo !empty($cfg['tg_proxy_enable']) ? 'checked' : ''; ?>>
                        Использовать прокси (если доступ к api.telegram.org ограничен)
                    </label>
                    <div class="row">
                        <div class="col-md-3">
                            <select name="tg_proxy_type" class="form-control input-sm">
                                <option value="socks5" <?php echo ($cfg['tg_proxy_type'] ?? '') === 'socks5' ? 'selected' : ''; ?>>SOCKS5</option>
                                <option value="http" <?php echo ($cfg['tg_proxy_type'] ?? '') === 'http' ? 'selected' : ''; ?>>HTTP / HTTPS</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control input-sm" name="tg_proxy_addr" value="<?php echo htmlspecialchars($cfg['tg_proxy_addr'] ?? ''); ?>" placeholder="ip:порт (например 127.0.0.1:9050)">
                        </div>
                        <div class="col-md-3">
                            <input type="text" class="form-control input-sm" name="tg_proxy_auth" value="<?php echo htmlspecialchars($cfg['tg_proxy_auth'] ?? ''); ?>" placeholder="логин:пароль (опционально)">
                        </div>
                    </div>
                    <div style="margin-top: 8px;">
                        <a href="<?php echo $selfUrl; ?>&tab=diagnostics&check_network=1" class="btn btn-xs btn-default"><i class="fa fa-wifi"></i> Проверить доступность Telegram с сервера (нужен ли прокси/зеркало)</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- VK Group Settings -->
    <div class="panel panel-default">
        <div class="panel-heading" style="background-color: #f7f9fc; border-left: 3px solid #4a76a8;">
            <h6 class="panel-title"><i class="fa fa-vk" style="color:#4a76a8;"></i> Настройки группы ВКонтакте</h6>
        </div>
        <div class="panel-body">
            <div class="form-group">
                <label class="control-label col-md-3">Включить отправку в VK:</label>
                <div class="col-md-9">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="vk_enabled" value="1" <?php echo !empty($cfg['vk_enabled']) ? 'checked' : ''; ?>>
                        Активировать публикацию на стену сообщества ВКонтакте
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Токен сообщества VK:</label>
                <div class="col-md-6">
                    <input type="text" class="form-control" name="vk_token" value="<?php echo htmlspecialchars($cfg['vk_token'] ?? ''); ?>" placeholder="vk1.a.ABCdef...">
                    <span class="help-block">Ключ доступа (Community Access Token), созданный в Управление сообществом &rarr; Работа с API &rarr; Ключи доступа (с правами «Стена» и «Фотографии»).</span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">ID группы ВКонтакте:</label>
                <div class="col-md-4">
                    <input type="text" class="form-control" name="vk_group_id" value="<?php echo htmlspecialchars($cfg['vk_group_id'] ?? ''); ?>" placeholder="например: 123456789">
                    <span class="help-block">Числовой ID группы (без знака минус).</span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Параметры записи на стене:</label>
                <div class="col-md-9">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="vk_from_group" value="1" <?php echo !empty($cfg['vk_from_group']) ? 'checked' : ''; ?>>
                        Публиковать от имени группы
                    </label>
                    <label class="checkbox-inline">
                        <input type="checkbox" name="vk_signed" value="1" <?php echo !empty($cfg['vk_signed']) ? 'checked' : ''; ?>>
                        Добавлять подпись автора
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Шаблон записи ВКонтакте:</label>
                <div class="col-md-7">
                    <textarea class="form-control" name="vk_template" rows="5"><?php echo htmlspecialchars($cfg['vk_template'] ?? ''); ?></textarea>
                    <span class="help-block">Доступные теги: <code>{title}</code>, <code>{short-story}</code>, <code>{full-story}</code>, <code>{link}</code>, <code>{tags}</code>, <code>{category}</code>, <code>{date}</code>.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- UTM Settings -->
    <div class="panel panel-default">
        <div class="panel-heading">
            <h6 class="panel-title"><i class="fa fa-line-chart"></i> Настройка UTM-меток для ссылок</h6>
        </div>
        <div class="panel-body">
            <div class="form-group">
                <label class="control-label col-md-3">Добавлять UTM-метки:</label>
                <div class="col-md-9">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="utm_enable" value="1" <?php echo !empty($cfg['utm_enable']) ? 'checked' : ''; ?>>
                        Автоматически дополнять URL новости UTM-метками для сквозной аналитики
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Метки для Telegram:</label>
                <div class="col-md-9">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-addon">source</span>
                                <input type="text" class="form-control" name="utm_source_tg" value="<?php echo htmlspecialchars($cfg['utm_source_tg'] ?? 'telegram'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-addon">medium</span>
                                <input type="text" class="form-control" name="utm_medium_tg" value="<?php echo htmlspecialchars($cfg['utm_medium_tg'] ?? 'channel'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-addon">campaign</span>
                                <input type="text" class="form-control" name="utm_campaign_tg" value="<?php echo htmlspecialchars($cfg['utm_campaign_tg'] ?? 'press_release'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Метки для ВКонтакте:</label>
                <div class="col-md-9">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-addon">source</span>
                                <input type="text" class="form-control" name="utm_source_vk" value="<?php echo htmlspecialchars($cfg['utm_source_vk'] ?? 'vk'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-addon">medium</span>
                                <input type="text" class="form-control" name="utm_medium_vk" value="<?php echo htmlspecialchars($cfg['utm_medium_vk'] ?? 'wall'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-addon">campaign</span>
                                <input type="text" class="form-control" name="utm_campaign_vk" value="<?php echo htmlspecialchars($cfg['utm_campaign_vk'] ?? 'press_release'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CRON & Queue Settings -->
    <div class="panel panel-default">
        <div class="panel-heading">
            <h6 class="panel-title"><i class="fa fa-clock-o"></i> Очередь и планировщик (CRON)</h6>
        </div>
        <div class="panel-body">
            <div class="form-group">
                <label class="control-label col-md-3">Секретный ключ CRON:</label>
                <div class="col-md-4">
                    <input type="text" class="form-control" name="cron_key" value="<?php echo htmlspecialchars($cfg['cron_key'] ?? ''); ?>">
                    <span class="help-block">Служит для авторизации вызова скрипта крона по HTTP.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Команда для crontab (CLI):</label>
                <div class="col-md-9">
                    <pre style="padding: 8px 12px; background: #f8f9fa;">*/2 * * * * /usr/bin/php <?php echo ROOT_DIR; ?>/cron/autopost.php >/dev/null 2>&1</pre>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Вызов через Web (HTTP/Curl):</label>
                <div class="col-md-9">
                    <?php global $config; $cronHttpUrl = rtrim($config['http_home_url'], '/') . '/cron/autopost.php?key=' . urlencode($cfg['cron_key'] ?? ''); ?>
                    <pre style="padding: 8px 12px; background: #f8f9fa;">curl -s "<?php echo $cronHttpUrl; ?>" >/dev/null 2>&1</pre>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Лимит повторных попыток:</label>
                <div class="col-md-2">
                    <input type="number" class="form-control" name="max_retries" value="<?php echo intval($cfg['max_retries'] ?? 3); ?>" min="1" max="10">
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-md-3">Хранить записей в журнале:</label>
                <div class="col-md-2">
                    <input type="number" class="form-control" name="log_limit" value="<?php echo intval($cfg['log_limit'] ?? 500); ?>" min="50" max="5000">
                </div>
            </div>
        </div>
    </div>

    <div style="margin-bottom: 30px;">
        <button type="submit" class="btn btn-primary btn-lg"><i class="fa fa-save"></i> Сохранить настройки</button>
    </div>
</form>
<?php
endif;

// -----------------------------------------------------------------------------
// TAB 2: QUEUE
// -----------------------------------------------------------------------------
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
    <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
        <h6 class="panel-title"><i class="fa fa-tasks"></i> Задачи в очереди публикации (Всего: <?php echo $totalTasks; ?>)</h6>
        <div>
            <form action="<?php echo $selfUrl; ?>&tab=queue" method="post" style="display:inline-block; margin-right: 5px;">
                <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                <input type="hidden" name="action" value="process_queue">
                <button type="submit" class="btn btn-sm btn-success"><i class="fa fa-play"></i> Запустить очередь сейчас</button>
            </form>
            <form action="<?php echo $selfUrl; ?>&tab=queue" method="post" style="display:inline-block;" onsubmit="return confirm('Очистить завершенные задачи?');">
                <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                <input type="hidden" name="action" value="clear_queue">
                <input type="hidden" name="clear_type" value="done">
                <button type="submit" class="btn btn-sm btn-default"><i class="fa fa-trash"></i> Удалить завершенные</button>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th width="60">ID</th>
                    <th width="100">Новость</th>
                    <th>Заголовок новости</th>
                    <th width="120">Канал</th>
                    <th width="120">Статус</th>
                    <th width="90">Попыток</th>
                    <th>Ошибка (если есть)</th>
                    <th width="160">Обновлено</th>
                    <th width="140" class="text-right">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding: 20px;">Очередь пуста</td></tr>
                <?php else: ?>
                    <?php foreach ($tasks as $t): ?>
                        <tr>
                            <td>#<?php echo $t['id']; ?></td>
                            <td><a href="<?php echo $config['http_home_url']; ?>index.php?newsid=<?php echo $t['news_id']; ?>" target="_blank">#<?php echo $t['news_id']; ?></a></td>
                            <td>
                                <b><a href="?mod=editnews&action=editnews&id=<?php echo $t['news_id']; ?>" target="_blank">
                                    <?php echo htmlspecialchars($t['news_title'] ?? 'Новость удалена'); ?>
                                </a></b>
                            </td>
                            <td>
                                <?php
                                if ($t['target'] === 'all') echo '<span class="label label-primary">TG + VK</span>';
                                elseif ($t['target'] === 'telegram') echo '<span class="label label-info">Telegram</span>';
                                else echo '<span class="label label-default">ВКонтакте</span>';
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
                            <td><?php echo $t['attempts']; ?></td>
                            <td style="color:#d9534f; font-size:12px;"><?php echo htmlspecialchars($t['error_message'] ?? '—'); ?></td>
                            <td><?php echo $t['updated_at']; ?></td>
                            <td class="text-right">
                                <form action="<?php echo $selfUrl; ?>&tab=queue" method="post" style="display:inline-block;">
                                    <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                                    <input type="hidden" name="action" value="retry_queue_item">
                                    <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
                                    <button type="submit" class="btn btn-xs btn-default" title="Повторить отправку"><i class="fa fa-refresh"></i></button>
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

// -----------------------------------------------------------------------------
// TAB 3: LOGS
// -----------------------------------------------------------------------------
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
    <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
        <h6 class="panel-title"><i class="fa fa-history"></i> Журнал отправленных сообщений (Всего: <?php echo $totalLogs; ?>)</h6>
        <form action="<?php echo $selfUrl; ?>&tab=logs" method="post" onsubmit="return confirm('Очистить весь журнал публикаций?');">
            <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
            <input type="hidden" name="action" value="clear_logs">
            <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i> Очистить журнал</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th width="70">ID</th>
                    <th width="160">Дата</th>
                    <th width="90">Новость</th>
                    <th>Заголовок новости</th>
                    <th width="110">Канал</th>
                    <th width="100">Статус</th>
                    <th width="160">ID поста / сообщения</th>
                    <th>Ошибка API</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding: 20px;">Журнал пуст</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td>#<?php echo $l['id']; ?></td>
                            <td><?php echo $l['post_date']; ?></td>
                            <td><a href="<?php echo $config['http_home_url']; ?>index.php?newsid=<?php echo $l['news_id']; ?>" target="_blank">#<?php echo $l['news_id']; ?></a></td>
                            <td>
                                <b><a href="?mod=editnews&action=editnews&id=<?php echo $l['news_id']; ?>" target="_blank">
                                    <?php echo htmlspecialchars($l['news_title'] ?? 'Новость удалена'); ?>
                                </a></b>
                            </td>
                            <td>
                                <?php echo ($l['channel'] === 'telegram')
                                    ? '<span class="label label-info"><i class="fa fa-paper-plane"></i> Telegram</span>'
                                    : '<span class="label label-primary"><i class="fa fa-vk"></i> ВКонтакте</span>'; ?>
                            </td>
                            <td>
                                <?php echo ($l['status'] === 'success')
                                    ? '<span class="label label-success">Успешно</span>'
                                    : '<span class="label label-danger">Ошибка</span>'; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($l['remote_id'] ?? '—'); ?></code></td>
                            <td style="color:#d9534f; font-size:12px;"><?php echo htmlspecialchars($l['error_message'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
endif;

// -----------------------------------------------------------------------------
// TAB 4: DIAGNOSTICS & TEST
// -----------------------------------------------------------------------------
if ($tab === 'diagnostics'):
?>
<!-- Network Diagnostics (TSPU / Proxy check) -->
<div class="panel panel-default" style="border-top: 3px solid #ff9800; margin-bottom: 25px;">
    <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
        <h6 class="panel-title"><i class="fa fa-wifi" style="color:#ff9800;"></i> Сетевая диагностика доступности Telegram API (Проверка блокировок ТСПУ)</h6>
        <form action="<?php echo $selfUrl; ?>&tab=diagnostics" method="post" style="margin: 0;">
            <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
            <input type="hidden" name="action" value="check_telegram_network">
            <input type="hidden" name="tg_api_url" value="<?php echo htmlspecialchars($cfg['tg_api_url'] ?? ''); ?>">
            <input type="hidden" name="tg_proxy_type" value="<?php echo htmlspecialchars($cfg['tg_proxy_type'] ?? 'socks5'); ?>">
            <input type="hidden" name="tg_proxy_addr" value="<?php echo htmlspecialchars($cfg['tg_proxy_addr'] ?? ''); ?>">
            <input type="hidden" name="tg_proxy_auth" value="<?php echo htmlspecialchars($cfg['tg_proxy_auth'] ?? ''); ?>">
            <button type="submit" class="btn btn-sm btn-warning"><i class="fa fa-refresh"></i> Запустить проверку доступности</button>
        </form>
    </div>
    <div class="panel-body">
        <p class="text-muted" style="margin-bottom: 15px;">
            Данный инструмент отправляет прямые запросы к официальному серверу Telegram (<code>api.telegram.org</code>), а также через указанное зеркало или прокси, замеряя время DNS, TCP и TLS рукопожатия, чтобы однозначно определить, заблокирован ли Telegram на вашем сервере ТСПУ и требуется ли прокси.
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
                <table class="table table-bordered table-striped">
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
                                    <small class="text-muted"><?php echo htmlspecialchars($test['url']); ?></small>
                                </td>
                                <td>
                                    <?php if ($r['success']): ?>
                                        <span class="label label-success"><i class="fa fa-check"></i> Доступен</span>
                                     <?php else: ?>
                                        <span class="label label-danger"><i class="fa fa-ban"></i> Заблокирован</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo $r['http_code'] > 0 ? $r['http_code'] : '0 (нет ответа)'; ?></code></td>
                                <td><b><?php echo $r['time_ms']; ?> ms</b></td>
                                <td><?php echo $r['dns_ms']; ?> ms</td>
                                <td><?php echo $r['connect_ms']; ?> ms</td>
                                <td><?php echo $r['tls_ms']; ?> ms</td>
                                <td>
                                    <?php if (!empty($r['error'])): ?>
                                        <span style="color:#d9534f; font-size:12px; font-family:monospace;"><?php echo htmlspecialchars($r['error']); ?></span>
                                    <?php elseif ($r['success']): ?>
                                        <span class="text-success" style="font-size:12px;"><i class="fa fa-check-circle"></i> Успешное соединение с сервером</span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 15px; background: #fafafa; border: 1px dashed #ddd; border-radius: 4px; text-align: center;">
                <p style="margin: 0; color: #777;">Нажмите кнопку <b>«Запустить проверку доступности»</b>, чтобы протестировать сетевое подключение к Telegram с этого сервера и узнать, нужен ли прокси.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <!-- Test Telegram -->
    <div class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading" style="border-left: 3px solid #0088cc;">
                <h6 class="panel-title"><i class="fa fa-paper-plane" style="color:#0088cc;"></i> Проверка подключения к Telegram</h6>
            </div>
            <div class="panel-body">
                <form action="<?php echo $selfUrl; ?>&tab=diagnostics" method="post">
                    <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                    <input type="hidden" name="action" value="test_telegram">
                    
                    <div class="form-group">
                        <label>Токен бота:</label>
                        <input type="text" class="form-control" name="tg_bot_token" value="<?php echo htmlspecialchars($cfg['tg_bot_token'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>ID или Username канала:</label>
                        <input type="text" class="form-control" name="tg_chat_id" value="<?php echo htmlspecialchars($cfg['tg_chat_id'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>URL API Telegram (зеркало / proxy):</label>
                        <input type="text" class="form-control" name="tg_api_url" value="<?php echo htmlspecialchars($cfg['tg_api_url'] ?? 'https://api.telegram.org'); ?>">
                    </div>

                    <input type="hidden" name="tg_proxy_enable" value="<?php echo !empty($cfg['tg_proxy_enable']) ? '1' : '0'; ?>">
                    <input type="hidden" name="tg_proxy_type" value="<?php echo htmlspecialchars($cfg['tg_proxy_type'] ?? 'socks5'); ?>">
                    <input type="hidden" name="tg_proxy_addr" value="<?php echo htmlspecialchars($cfg['tg_proxy_addr'] ?? ''); ?>">
                    <input type="hidden" name="tg_proxy_auth" value="<?php echo htmlspecialchars($cfg['tg_proxy_auth'] ?? ''); ?>">

                    <button type="submit" class="btn btn-info"><i class="fa fa-plug"></i> Проверить подключение к Telegram</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Test VK -->
    <div class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading" style="border-left: 3px solid #4a76a8;">
                <h6 class="panel-title"><i class="fa fa-vk" style="color:#4a76a8;"></i> Проверка подключения к ВКонтакте</h6>
            </div>
            <div class="panel-body">
                <form action="<?php echo $selfUrl; ?>&tab=diagnostics" method="post">
                    <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
                    <input type="hidden" name="action" value="test_vk">

                    <div class="form-group">
                        <label>Токен сообщества (Community Token):</label>
                        <input type="text" class="form-control" name="vk_token" value="<?php echo htmlspecialchars($cfg['vk_token'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>ID группы (число без минуса):</label>
                        <input type="text" class="form-control" name="vk_group_id" value="<?php echo htmlspecialchars($cfg['vk_group_id'] ?? ''); ?>">
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fa fa-plug"></i> Проверить подключение к VK</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Manual Test Send of Specific News -->
<div class="panel panel-default">
    <div class="panel-heading">
        <h6 class="panel-title"><i class="fa fa-send"></i> Ручная тестовая отправка существующей новости</h6>
    </div>
    <div class="panel-body">
        <form action="<?php echo $selfUrl; ?>&tab=diagnostics" method="post" class="form-inline">
            <input type="hidden" name="user_hash" value="<?php echo $dle_login_hash; ?>">
            <input type="hidden" name="action" value="send_manual_test">

            <div class="form-group" style="margin-right: 15px;">
                <label style="margin-right: 8px;">ID новости в DLE:</label>
                <input type="number" class="form-control" name="test_news_id" placeholder="например 1" required style="width: 120px;">
            </div>

            <div class="form-group" style="margin-right: 15px;">
                <label style="margin-right: 8px;">Куда отправить:</label>
                <select name="test_target" class="form-control">
                    <option value="all">И в Telegram, и в VK</option>
                    <option value="telegram">Только в Telegram</option>
                    <option value="vk">Только в ВКонтакте</option>
                </select>
            </div>

            <button type="submit" class="btn btn-success"><i class="fa fa-paper-plane"></i> Отправить тестовый пост</button>
        </form>
        <span class="help-block" style="margin-top: 10px;">Позволяет проверить, как реальная новость с вашего сайта будет выглядеть в канале Telegram и группе VK.</span>
    </div>
</div>
<?php
endif;

echofooter();
