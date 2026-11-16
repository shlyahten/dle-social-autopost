<?php
/**
 * DLE Social AutoPost Plugin
 * Telegram Bot API Client
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

class Autopost_TelegramClient
{
    private $botToken;
    private $chatId;
    private $proxyConfig;
    private $apiUrl;

    public function __construct($botToken, $chatId, array $proxyConfig = [], $apiUrl = 'https://api.telegram.org')
    {
        $this->botToken = trim($botToken);
        $this->chatId = trim($chatId);
        $this->proxyConfig = $proxyConfig;
        $this->apiUrl = !empty(trim($apiUrl)) ? rtrim(trim($apiUrl), '/') : 'https://api.telegram.org';
    }

    /**
     * Send post to Telegram channel
     *
     * @param string      $text
     * @param array|null  $imageInfo ['url' => ..., 'local_path' => ...]
     * @param string|null $buttonText
     * @param string|null $buttonUrl
     * @return array ['success' => bool, 'message_id' => string|null, 'error' => string|null]
     */
    public function sendPost($text, $imageInfo = null, $buttonText = null, $buttonUrl = null)
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            return ['success' => false, 'error' => 'Telegram Bot Token or Chat ID is not configured'];
        }

        $replyMarkup = null;
        if (!empty($buttonText) && !empty($buttonUrl)) {
            $replyMarkup = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => $buttonText, 'url' => $buttonUrl]
                    ]
                ]
            ]);
        }

        // If image is provided, try sending photo
        if (!empty($imageInfo)) {
            $caption = Autopost_TemplateEngine::truncate($text, 1024);
            $photoResult = $this->sendPhoto($imageInfo, $caption, $replyMarkup);
            if ($photoResult['success']) {
                return $photoResult;
            }
            // If sendPhoto failed specifically due to image issue, fallback to sendMessage
        }

        // Send text message (max 4096 chars)
        $messageText = Autopost_TemplateEngine::truncate($text, 4000);
        return $this->sendMessage($messageText, $replyMarkup);
    }

    /**
     * Send photo via Telegram API
     *
     * @param array       $imageInfo
     * @param string      $caption
     * @param string|null $replyMarkup
     * @return array
     */
    public function sendPhoto($imageInfo, $caption, $replyMarkup = null)
    {
        $params = [
            'chat_id'    => $this->chatId,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        // If local file exists, use CURLFile
        if (!empty($imageInfo['local_path']) && file_exists($imageInfo['local_path'])) {
            $params['photo'] = new CURLFile($imageInfo['local_path']);
        } elseif (!empty($imageInfo['url'])) {
            $params['photo'] = $imageInfo['url'];
        } else {
            return ['success' => false, 'error' => 'No valid photo source provided'];
        }

        return $this->request('sendPhoto', $params);
    }

    /**
     * Send text message via Telegram API
     *
     * @param string      $text
     * @param string|null $replyMarkup
     * @return array
     */
    public function sendMessage($text, $replyMarkup = null)
    {
        $params = [
            'chat_id'                  => $this->chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => false,
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->request('sendMessage', $params);
    }

    /**
     * Test connection to bot and chat
     *
     * @return array ['success' => bool, 'bot_username' => string, 'chat_title' => string, 'error' => string|null]
     */
    public function testConnection()
    {
        if (empty($this->botToken)) {
            return ['success' => false, 'error' => 'Bot Token is empty'];
        }

        $botRes = $this->request('getMe');
        if (!$botRes['success']) {
            return ['success' => false, 'error' => 'Invalid Bot Token: ' . ($botRes['error'] ?? 'Unknown error')];
        }

        $botUsername = $botRes['result']['username'] ?? 'Unknown';

        if (!empty($this->chatId)) {
            $chatRes = $this->request('getChat', ['chat_id' => $this->chatId]);
            if (!$chatRes['success']) {
                return [
                    'success'      => false,
                    'bot_username' => $botUsername,
                    'error'        => 'Chat error: ' . ($chatRes['error'] ?? 'Chat not found or bot is not an administrator')
                ];
            }
            $chatTitle = $chatRes['result']['title'] ?? ($chatRes['result']['username'] ?? $this->chatId);
            return [
                'success'      => true,
                'bot_username' => $botUsername,
                'chat_title'   => $chatTitle
            ];
        }

        return [
            'success'      => true,
            'bot_username' => $botUsername,
            'chat_title'   => 'N/A'
        ];
    }

    /**
     * Check endpoint availability & network latency for TSPU diagnostic
     *
     * @param string|null $customUrl Optional custom URL to test in addition to official API
     * @param array       $proxyConfig Optional proxy configuration to test
     * @return array Detailed diagnostic report with latency, DNS, TCP, SSL stats and verdict
     */
    public static function checkEndpointAvailability($customUrl = null, array $proxyConfig = [])
    {
        $officialUrl = 'https://api.telegram.org';
        $tests = [];

        // 1. Direct probe to official Telegram API
        $officialProbe = self::probeUrl($officialUrl, []);
        $tests['official_direct'] = [
            'label'   => 'Прямое подключение к api.telegram.org',
            'url'     => $officialUrl,
            'proxy'   => false,
            'result'  => $officialProbe
        ];

        // 2. Probe official API via configured proxy (if proxy provided)
        if (!empty($proxyConfig['address'])) {
            $proxyProbe = self::probeUrl($officialUrl, $proxyConfig);
            $tests['official_proxy'] = [
                'label'   => 'Подключение к api.telegram.org через указанный прокси',
                'url'     => $officialUrl,
                'proxy'   => true,
                'result'  => $proxyProbe
            ];
        }

        // 3. Probe custom endpoint (if configured)
        if (!empty($customUrl) && rtrim($customUrl, '/') !== $officialUrl) {
            $customProbe = self::probeUrl($customUrl, []);
            $tests['custom_endpoint'] = [
                'label'   => 'Подключение к кастомному URL API (зеркало / реверс-прокси)',
                'url'     => $customUrl,
                'proxy'   => false,
                'result'  => $customProbe
            ];
        }

        // Analyze results and produce human-friendly verdict
        $directOk = $tests['official_direct']['result']['success'];
        $proxyOk = isset($tests['official_proxy']) ? $tests['official_proxy']['result']['success'] : null;
        $customOk = isset($tests['custom_endpoint']) ? $tests['custom_endpoint']['result']['success'] : null;

        $verdict = [];
        if ($directOk) {
            $verdict = [
                'status'        => 'ok',
                'class'         => 'success',
                'need_proxy'    => false,
                'title'         => 'Прямой доступ к Telegram API открыт!',
                'recommendation'=> 'Сервер успешно соединяется с api.telegram.org без ограничений. Прокси и кастомный URL не требуются.'
            ];
        } elseif ($customOk) {
            $verdict = [
                'status'        => 'custom_ok',
                'class'         => 'info',
                'need_proxy'    => false,
                'title'         => 'Прямой доступ заблокирован, но кастомный URL API работает!',
                'recommendation'=> 'Прямой адрес api.telegram.org блокируется ТСПУ/провайдером, однако настроенный вами кастомный URL API отвечает штатно. Используйте его для публикации.'
            ];
        } elseif ($proxyOk) {
            $verdict = [
                'status'        => 'proxy_ok',
                'class'         => 'warning',
                'need_proxy'    => true,
                'title'         => 'Прямой доступ заблокирован ТСПУ! Прокси работает успешно.',
                'recommendation'=> 'Прямое подключение к api.telegram.org заблокировано ТСПУ (сброс TCP/TLS или таймаут). Настроенный прокси исправно обходит блокировку. Обязательно оставьте прокси включенным.'
            ];
        } else {
            $verdict = [
                'status'        => 'blocked',
                'class'         => 'danger',
                'need_proxy'    => true,
                'title'         => 'Внимание: Доступ к Telegram API заблокирован!',
                'recommendation'=> 'Прямое соединение к Telegram API блокируется (вероятно, ТСПУ РКН на сервере хостинга). Для работы автопостинга необходимо включить SOCKS5/HTTP прокси либо настроить свой URL реверс-прокси (зеркала).'
            ];
        }

        return [
            'tests'   => $tests,
            'verdict' => $verdict
        ];
    }

    /**
     * Low-level network probe helper
     *
     * @param string $url
     * @param array  $proxyConfig
     * @return array
     */
    private static function probeUrl($url, array $proxyConfig = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);

        if (!empty($proxyConfig['address'])) {
            $proxyType = (isset($proxyConfig['type']) && strtolower($proxyConfig['type']) === 'socks5')
                ? CURLPROXY_SOCKS5
                : CURLPROXY_HTTP;
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxyType);
            curl_setopt($ch, CURLOPT_PROXY, $proxyConfig['address']);
            if (!empty($proxyConfig['auth'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyConfig['auth']);
            }
        }

        $start = microtime(true);
        $response = curl_exec($ch);
        $totalTime = microtime(true) - $start;

        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $httpCode = intval($info['http_code'] ?? 0);

        // Telegram root typically returns 404 with JSON {"ok":false,"error_code":404,...}
        // Any HTTP response from server means connection succeeded
        $isSuccess = ($response !== false && $httpCode > 0 && $httpCode < 500);

        return [
            'success'    => $isSuccess,
            'http_code'  => $httpCode,
            'time_ms'    => round(($info['total_time'] ?? $totalTime) * 1000, 1),
            'connect_ms' => round(($info['connect_time'] ?? 0) * 1000, 1),
            'dns_ms'     => round(($info['namelookup_time'] ?? 0) * 1000, 1),
            'tls_ms'     => round(($info['appconnect_time'] ?? 0) * 1000, 1),
            'error'      => !empty($curlError) ? "cURL error [{$curlErrno}]: {$curlError}" : null,
            'ip'         => $info['primary_ip'] ?? null,
        ];
    }

    /**
     * Make HTTP request to Telegram API
     *
     * @param string $method
     * @param array  $params
     * @return array
     */
    private function request($method, array $params = [])
    {
        $url = $this->apiUrl . '/bot' . $this->botToken . '/' . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }

        // Apply proxy if configured
        if (!empty($this->proxyConfig['enable']) && !empty($this->proxyConfig['address'])) {
            $proxyType = (isset($this->proxyConfig['type']) && strtolower($this->proxyConfig['type']) === 'socks5')
                ? CURLPROXY_SOCKS5
                : CURLPROXY_HTTP;
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxyType);
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyConfig['address']);

            if (!empty($this->proxyConfig['auth'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxyConfig['auth']);
            }
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['ok'])) {
            return ['success' => false, 'error' => "HTTP {$httpCode}: Invalid JSON response ({$response})"];
        }

        if (!$data['ok']) {
            $desc = $data['description'] ?? 'Unknown Telegram error';
            $code = $data['error_code'] ?? $httpCode;
            return ['success' => false, 'error' => "Telegram error [{$code}]: {$desc}"];
        }

        $messageId = null;
        if (isset($data['result']['message_id'])) {
            $messageId = (string)$data['result']['message_id'];
        }

        return [
            'success'    => true,
            'message_id' => $messageId,
            'result'     => $data['result'] ?? []
        ];
    }
}
