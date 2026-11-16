<?php
/**
 * Automated Test Suite for DLE Social AutoPost Plugin
 *
 * Usage: php tests/test_autopost.php
 */

$repoDir = dirname(__DIR__);

// Setup DLE Environment Mock
if (!defined('DATALIFEENGINE')) {
    define('DATALIFEENGINE', true);
}
if (!defined('ENGINE_DIR')) {
    define('ENGINE_DIR', $repoDir . '/engine');
}
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', $repoDir);
}
if (!defined('PREFIX')) {
    define('PREFIX', 'dle');
}

// Global mocks
global $config, $db;
$config = [
    'http_home_url' => 'https://example.com/',
    'charset'       => 'utf-8',
];

class MockDb
{
    public function super_query($query)
    {
        return [];
    }
    public function query($query)
    {
        return true;
    }
    public function insert_id()
    {
        return 101;
    }
    public function safesql($val)
    {
        return addslashes($val);
    }
}
$db = new MockDb();

require_once ENGINE_DIR . '/classes/autopost/Config.php';
require_once ENGINE_DIR . '/classes/autopost/MediaHelper.php';
require_once ENGINE_DIR . '/classes/autopost/TemplateEngine.php';
require_once ENGINE_DIR . '/classes/autopost/TelegramClient.php';
require_once ENGINE_DIR . '/classes/autopost/VkClient.php';
require_once ENGINE_DIR . '/classes/autopost/Queue.php';
require_once ENGINE_DIR . '/classes/autopost/Logger.php';
require_once ENGINE_DIR . '/classes/autopost/Autopost.php';

$passCount = 0;
$failCount = 0;

function assertTest(string $name, bool $condition, string $details = '')
{
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$name}: {$details}\n";
        $failCount++;
    }
}

echo "=== DLE Social AutoPost Automated Test Suite ===\n\n";

// 1. Config Defaults and Structure
echo "1. Тест конфигурации и настроек по умолчанию:\n";
$defaults = Autopost_Config::getDefaults();
assertTest("Метод getDefaults возвращает массив", is_array($defaults));
assertTest("В конфигурации есть поле tg_enabled", isset($defaults['tg_enabled']));
assertTest("В конфигурации есть поле tg_api_url", isset($defaults['tg_api_url']));
assertTest("tg_api_url по умолчанию равен https://api.telegram.org", $defaults['tg_api_url'] === 'https://api.telegram.org');
assertTest("В конфигурации есть поле vk_enabled", isset($defaults['vk_enabled']));
assertTest("В конфигурации есть поле max_retries", isset($defaults['max_retries']));
assertTest("В конфигурации есть поле cron_key", isset($defaults['cron_key']));
assertTest("В конфигурации есть поле log_limit", isset($defaults['log_limit']));

// 2. MediaHelper Image Extraction
echo "\n2. Тест помощника медиа и парсинга изображений (MediaHelper):\n";
$newsWithBb = [
    'short_story' => 'Вводный текст новости [img]https://example.com/uploads/posts/photo.jpg[/img] продолжение.',
    'full_story'  => '',
    'xfields'     => '',
];
$extractedBb = Autopost_MediaHelper::extractImage($newsWithBb);
assertTest("Извлечение картинки из BB-кода [img]", !empty($extractedBb['url']) && $extractedBb['url'] === 'https://example.com/uploads/posts/photo.jpg');

$newsWithHtml = [
    'short_story' => 'Текст без картинок',
    'full_story'  => '<p>Полный текст с <img src="/uploads/posts/2026/test.png" alt="img"></p>',
    'xfields'     => '',
];
$extractedHtml = Autopost_MediaHelper::extractImage($newsWithHtml);
assertTest("Извлечение относительной картинки и резолв абсолютного URL", !empty($extractedHtml['url']) && $extractedHtml['url'] === 'https://example.com/uploads/posts/2026/test.png');

$newsWithXfield = [
    'short_story' => '',
    'full_story'  => '',
    'xfields'     => 'poster|https://example.com/poster.jpg||source|PressRelease',
];
$extractedXf = Autopost_MediaHelper::extractImage($newsWithXfield, 'poster');
assertTest("Извлечение постера из дополнительного поля xfield", !empty($extractedXf['url']) && $extractedXf['url'] === 'https://example.com/poster.jpg');

// 3. TemplateEngine Placeholders and Text Cleaning
echo "\n3. Тест шаблонизатора и форматирования текста (TemplateEngine):\n";
$newsRow = [
    'id'          => 42,
    'title'       => 'Новый релиз плагина и обновление DLE',
    'short_story' => '<b>DLE Social AutoPost</b> успешно протестирован на версии 21.0! [b]Подробности[/b] в релизе.',
    'full_story'  => 'Полный текст новости.',
    'tags'        => 'dle, релиз, autopost',
    'category'    => '1,2',
    'date'        => '2026-11-16 12:00:00',
    'alt_name'    => 'new-release-autopost',
];

$tplTg = "<b>{title}</b>\n\n{short-story}\n\n{tags}";
$renderedTg = Autopost_TemplateEngine::render($tplTg, $newsRow, 'telegram', $defaults);
assertTest("Заголовок корректно подставлен в шаблон", strpos($renderedTg, 'Новый релиз плагина и обновление DLE') !== false);
assertTest("BB-коды [b] очищены в short-story", strpos($renderedTg, '[b]') === false && strpos($renderedTg, 'Подробности в релизе') !== false);
assertTest("Теги преобразованы в хэштеги", strpos($renderedTg, '#dle') !== false && strpos($renderedTg, '#autopost') !== false);

// 4. Telegram Client & Custom Endpoint
echo "\n4. Тест клиента Telegram API и кастомного эндпоинта:\n";
$tgDefault = new Autopost_TelegramClient('123456:ABC', '-100123456');
$refProp = new ReflectionProperty($tgDefault, 'apiUrl');
$refProp->setAccessible(true);
assertTest("Telegram клиент использует дефолтный URL https://api.telegram.org", $refProp->getValue($tgDefault) === 'https://api.telegram.org');

$tgCustom = new Autopost_TelegramClient('123456:ABC', '-100123456', [], 'https://proxy.example.com/api/');
assertTest("Telegram клиент корректно сохраняет кастомный Endpoint API без слэша", $refProp->getValue($tgCustom) === 'https://proxy.example.com/api');

assertTest("Метод checkEndpointAvailability существует", method_exists('Autopost_TelegramClient', 'checkEndpointAvailability'));
$diag = Autopost_TelegramClient::checkEndpointAvailability(null, []);
assertTest("checkEndpointAvailability возвращает массив с tests и verdict", is_array($diag) && isset($diag['tests']) && isset($diag['verdict']));

// 5. VK Client
echo "\n5. Тест клиента VK API:\n";
$vk = new Autopost_VkClient('test_token', '-123456');
assertTest("Метод sendPost существует", method_exists($vk, 'sendPost'));
$vkEmpty = new Autopost_VkClient('', '');
$postRes = $vkEmpty->sendPost('Test message');
assertTest("При пустом токене VK sendPost возвращает success = false", $postRes['success'] === false);

// 6. Queue & Logger
echo "\n6. Тест очереди и системы логирования:\n";
assertTest("Класс Autopost_Queue существует", class_exists('Autopost_Queue'));
assertTest("Метод Autopost_Queue::add существует", method_exists('Autopost_Queue', 'add'));
assertTest("Метод Autopost_Queue::process существует", method_exists('Autopost_Queue', 'process'));
assertTest("Класс Autopost_Logger существует", class_exists('Autopost_Logger'));
assertTest("Метод Autopost_Logger::log существует", method_exists('Autopost_Logger', 'log'));

// 7. Plugin XML Manifest Integrity
echo "\n7. Тест целостности манифеста плагина plugin.xml:\n";
$xml = simplexml_load_file($repoDir . '/plugin.xml');
assertTest("Манифест plugin.xml валиден", $xml !== false);
assertTest("Название плагина корректно", strpos((string)$xml->name, 'DLE Social AutoPost') !== false);
$currentVersion = (string)$xml->version;
assertTest("Версия плагина указана корректно ($currentVersion)", preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?/', $currentVersion) === 1);
assertTest("Совместимость с DLE 21.0 указана", strpos((string)$xml->dleversion, '21.0') !== false);
assertTest("Иконка autopost.png указана", (string)$xml->icon === 'autopost.png');
assertTest("Тег <filedelete> равен 1 для чистого удаления", (string)$xml->filedelete === '1');
assertTest("Тег <upgradeurl> указывает на GitHub Pages", strpos((string)$xml->upgradeurl, 'shlyahten.github.io') !== false);

// Check filelist files exist on disk
$filelist = array_filter(array_map('trim', explode("\n", (string)$xml->filelist)));
$missingFiles = [];
foreach ($filelist as $f) {
    if (!file_exists($repoDir . '/' . $f)) {
        $missingFiles[] = $f;
    }
}
assertTest("Все файлы из <filelist> присутствуют на диске (" . count($filelist) . " файлов)", empty($missingFiles), implode(', ', $missingFiles));

// Check hook files
$rawXml = file_get_contents($repoDir . '/plugin.xml');
assertTest("Хук engine/inc/addnews.php присутствует", strpos($rawXml, 'name="engine/inc/addnews.php"') !== false);
assertTest("Хук engine/inc/editnews.php присутствует", strpos($rawXml, 'name="engine/inc/editnews.php"') !== false);
assertTest("Хук editnews.php использует валидный селектор кэша/новостей", strpos($rawXml, 'cprefix') !== false);
assertTest("Хук engine/modules/cron.php присутствует", strpos($rawXml, 'name="engine/modules/cron.php"') !== false);
assertTest("Хук cron.php использует валидный маркер set_vars", strpos($rawXml, 'set_vars( "cron"') !== false);

// 8. Admin Brand Icon
echo "\n8. Тест иконки плагина:\n";
$iconPath = $repoDir . '/engine/skins/images/autopost.png';
assertTest("Иконка autopost.png существует", file_exists($iconPath));
$iconSize = @getimagesize($iconPath);
assertTest("Иконка autopost.png является PNG 128x128", $iconSize && $iconSize[0] === 128 && $iconSize[1] === 128 && $iconSize[2] === IMAGETYPE_PNG);

// 9. Absence of TSPU / RKN Terms & Security Leak Check
echo "\n9. Тест чистоты терминологии (отсутствие ТСПУ / РКН):\n";
$scanFiles = [
    $repoDir . '/plugin.xml',
    $repoDir . '/engine/classes/autopost/Config.php',
    $repoDir . '/engine/classes/autopost/TelegramClient.php',
    $repoDir . '/engine/inc/autopost.php',
    $repoDir . '/docs/TELEGRAM_SETUP.md',
    $repoDir . '/README.md',
];
$blockedFound = 0;
foreach ($scanFiles as $sf) {
    if (file_exists($sf)) {
        $c = file_get_contents($sf);
        if (preg_match('/(тспу|tspu|ркн|rkn)/iu', $c, $m)) {
            $blockedFound++;
            echo "  [FAIL] Найдено запрещенное слово '{$m[1]}' в " . basename($sf) . "\n";
        }
    }
}
assertTest("В исходных текстах отсутствуют термины ТСПУ/РКН", $blockedFound === 0);

// 10. Distribution ZIP Integrity
echo "\n10. Тест дистрибутивного ZIP-архива:\n";
$zipPath = $repoDir . '/dist/dle-social-autopost-v' . $currentVersion . '.zip';
if (!file_exists($zipPath)) {
    $zipPath = $repoDir . '/dist/dle-social-autopost.zip';
}
if (file_exists($zipPath)) {
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    assertTest("Архив dist/" . basename($zipPath) . " открывается", $opened === true);
    if ($opened === true) {
        assertTest("Манифест plugin.xml лежит в корне архива", $zip->locateName('plugin.xml') !== false);
        assertTest("Иконка лежит в engine/skins/images/autopost.png", $zip->locateName('engine/skins/images/autopost.png') !== false);
        assertTest("Главный модуль лежит в engine/classes/autopost/Autopost.php", $zip->locateName('engine/classes/autopost/Autopost.php') !== false);
        assertTest("Крон-скрипт лежит в cron/autopost.php", $zip->locateName('cron/autopost.php') !== false);
        $zip->close();
    }
} else {
    echo "  [INFO] Дистрибутивный архив еще не собран (тест пропускается перед сборкой)\n";
}

echo "\n=======================================\n";
echo "ИТОГ ТЕСТИРОВАНИЯ: Успешно: {$passCount}, Ошибок: {$failCount}\n";
echo "=======================================\n";

if ($failCount > 0) {
    exit(1);
}
