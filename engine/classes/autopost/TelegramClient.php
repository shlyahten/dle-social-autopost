<?php
/**
 * DLE Social AutoPost - Telegram Bot API Client
 *
 * Handles communication with Telegram Bot API via cURL with HTML parsing,
 * inline button attachments, optional SOCKS5/HTTP proxy, custom API endpoints (TSPU bypass),
 * and network reachability diagnostic tools.
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

class AutopostTelegramClient
{
    private const DEFAULT_API_BASE = 'https://api.telegram.org';

    private string $botToken;
    private string $channelId;
    private string $apiBaseUrl;
    private array $proxyConfig;

    public function __construct(string $botToken, string $channelId, array $proxyConfig = [], ?string $apiBaseUrl = null)
    {
        $this->botToken = trim($botToken);
        $this->channelId = trim($channelId);
        $this->proxyConfig = $proxyConfig;

        // Custom API Base URL support (TSPU/DPI bypass via reverse proxy, mirror, or local telegram-bot-api)
        $customUrl = trim($apiBaseUrl ?? '');
        if (!empty($customUrl)) {
            $this->apiBaseUrl = rtrim($customUrl, '/');
        } else {
            $this->apiBaseUrl = self::DEFAULT_API_BASE;
        }
    }

    /**
     * Send message to channel with optional photo and inline button.
     *
     * @param string $text Formatted HTML text
     * @param string|null $imageUrl Optional main image URL
     * @param array $options ['button_text' => '', 'button_url' => '', 'disable_web_page_preview' => false]
     * @return array ['success' => bool, 'message_id' => int|null, 'error' => string|null]
     */
    public function sendMessage(string $text, ?string $imageUrl = null, array $options = []): array
    {
        if (empty($this->botToken) || empty($this->channelId)) {
            return ['success' => false, 'message_id' => null, 'error' => 'Telegram credentials not configured'];
        }

        // Build inline keyboard if button URL provided
        $replyMarkup = null;
        if (!empty($options['button_url']) && !empty($options['button_text'])) {
            $replyMarkup = json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => $options['button_text'],
                            'url'  => $options['button_url'],
                        ]
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);
        }

        // If image URL is present, use sendPhoto; otherwise sendMessage
        if (!empty($imageUrl)) {
            return $this->sendPhoto($text, $imageUrl, $replyMarkup, $options);
        }

        $params = [
            'chat_id'                  => $this->channelId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => !empty($options['disable_web_page_preview']) ? 'true' : 'false',
            'disable_notification'     => !empty($options['disable_notification']) ? 'true' : 'false',
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->apiRequest('sendMessage', $params);
    }

    /**
     * Send photo with caption.
     */
    private function sendPhoto(string $caption, string $imageUrl, ?string $replyMarkup = null, array $options = []): array
    {
        // Telegram caption limit is 1024 characters; truncate if necessary
        if (mb_strlen($caption, 'UTF-8') > 1024) {
            $caption = mb_substr($caption, 0, 1020, 'UTF-8') . '...';
        }

        $params = [
            'chat_id'              => $this->channelId,
            'photo'                => $imageUrl,
            'caption'              => $caption,
            'parse_mode'           => 'HTML',
            'disable_notification' => !empty($options['disable_notification']) ? 'true' : 'false',
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        $result = $this->apiRequest('sendPhoto', $params);

        // Fallback: If image cannot be downloaded by Telegram servers (e.g. localhost or auth protected),
        // fallback to standard sendMessage
        if (!$result['success'] && strpos($result['error'] ?? '', 'wrong file identifier') !== false) {
            unset($params['photo'], $params['caption']);
            $params['text'] = $caption;
            return $this->apiRequest('sendMessage', $params);
        }

        return $result;
    }

    /**
     * Test connection to Telegram Bot API and verify bot token & channel access.
     */
    public function testConnection(): array
    {
        // 1. Check getMe
        $getMe = $this->apiRequest('getMe');
        if (!$getMe['success']) {
            return [
                'success' => false,
                'bot_name' => null,
                'channel_title' => null,
                'error' => 'getMe failed: ' . ($getMe['error'] ?? 'Unknown error'),
            ];
        }

        $botName = $getMe['result']['first_name'] ?? 'Bot';
        $botUsername = $getMe['result']['username'] ?? '';

        // 2. Check chat / channel access
        $getChat = $this->apiRequest('getChat', ['chat_id' => $this->channelId]);
        if (!$getChat['success']) {
            return [
                'success' => false,
                'bot_name' => "{$botName} (@{$botUsername})",
                'channel_title' => null,
                'error' => 'getChat failed (verify channel ID and bot admin rights): ' . ($getChat['error'] ?? 'Unknown error'),
            ];
        }

        $chatTitle = $getChat['result']['title'] ?? $this->channelId;

        return [
            'success' => true,
            'bot_name' => "{$botName} (@{$botUsername})",
            'channel_title' => $chatTitle,
            'error' => null,
        ];
    }

    /**
     * Check network reachability and latency of Telegram Bot API endpoints.
     * Tests DNS resolution, TCP connection, and TLS handshake times directly to api.telegram.org
     * and the configured custom endpoint to diagnose TSPU/DPI blocking.
     *
     * @param string|null $targetUrl Optional specific URL to test (defaults to configured or api.telegram.org)
     * @return array Diagnostic metrics and recommendations
     */
    public static function checkEndpointAvailability(?string $targetUrl = null, array $proxyConfig = []): array
    {
        $url = trim($targetUrl ?? '');
        if (empty($url)) {
            $url = self::DEFAULT_API_BASE;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'api.telegram.org';
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'http' ? 80 : 443);

        $diag = [
            'target_url'     => $url,
            'host'           => $host,
            'port'           => $port,
            'dns_resolved'   => false,
            'ip_addresses'   => [],
            'tcp_connected'  => false,
            'ssl_handshake'  => false,
            'http_code'      => 0,
            'time_namelookup'=> 0.0,
            'time_connect'   => 0.0,
            'time_appconnect'=> 0.0,
            'time_total'     => 0.0,
            'blocked_by_tspu'=> false,
            'proxy_needed'   => false,
            'verdict'        => '',
            'error'          => null,
        ];

        // 1. DNS Resolution check
        $dnsStart = microtime(true);
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        $diag['time_namelookup'] = round((microtime(true) - $dnsStart), 4);

        if (!empty($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $diag['ip_addresses'][] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $diag['ip_addresses'][] = $r['ipv6'];
                }
            }
            $diag['dns_resolved'] = !empty($diag['ip_addresses']);
        }

        // 2. cURL HTTP/HTTPS Probe
        $testEndpoint = rtrim($url, '/') . '/bot123456:FAKE_TOKEN_FOR_PROBE/getMe';
        $ch = curl_init($testEndpoint);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'DLE-Social-AutoPost-Probe/1.1');

        // Apply proxy if requested
        if (!empty($proxyConfig['tg_proxy_enable']) && $proxyConfig['tg_proxy_enable'] === '1' && !empty($proxyConfig['tg_proxy_address'])) {
            $proxyType = ($proxyConfig['tg_proxy_type'] ?? '') === 'CURLPROXY_SOCKS5' ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP;
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxyType);
            curl_setopt($ch, CURLOPT_PROXY, $proxyConfig['tg_proxy_address']);
            if (!empty($proxyConfig['tg_proxy_auth'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyConfig['tg_proxy_auth']);
            }
        }

        $body = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);

        $info = curl_getinfo($ch);
        curl_close($ch);

        $diag['http_code']       = (int)($info['http_code'] ?? 0);
        $diag['time_namelookup'] = round($info['namelookup_time'] ?? $diag['time_namelookup'], 4);
        $diag['time_connect']    = round($info['connect_time'] ?? 0.0, 4);
        $diag['time_appconnect'] = round($info['appconnect_time'] ?? 0.0, 4);
        $diag['time_total']      = round($info['total_time'] ?? 0.0, 4);

        if ($diag['time_connect'] > 0) {
            $diag['tcp_connected'] = true;
        }
        if ($diag['time_appconnect'] > 0 || ($parsed['scheme'] === 'http' && $diag['tcp_connected'])) {
            $diag['ssl_handshake'] = true;
        }

        // Diagnostic analysis
        // Note: For a fake token, Telegram Bot API returns HTTP 401 or 404 with JSON {"ok":false,"error_code":401...}
        // This confirms the server is reachable and NOT blocked!
        if ($diag['http_code'] === 401 || $diag['http_code'] === 404 || ($diag['http_code'] >= 200 && $diag['http_code'] < 400)) {
            $diag['blocked_by_tspu'] = false;
            $diag['proxy_needed'] = false;
            $diag['verdict'] = 'Доступ свободен. Эндпоинт отвечает штатно (' . $diag['time_total'] . ' сек). Прокси или зеркало НЕ требуются.';
            return $diag;
        }

        // Detection of TSPU/DPI blocks:
        // CURLE_OPERATION_TIMEDOUT (28): Connection drops or silent drops after ClientHello (classic TSPU behavior)
        // CURLE_COULDNT_CONNECT (7): Connection refused / TCP reset
        // CURLE_SSL_CONNECT_ERROR (35): Handshake reset by TSPU
        if (in_array($curlErrno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR], true)) {
            $diag['blocked_by_tspu'] = true;
            $diag['proxy_needed'] = true;
            $diag['error'] = "cURL error {$curlErrno}: {$curlError}";
            $diag['verdict'] = 'Блокировка ТСПУ / Сетевой сброс. Соединение сбрасывается или зависает по таймауту. ТРЕБУЕТСЯ прокси или кастомный URL (зеркало / reverse proxy).';
            return $diag;
        }

        if (!$diag['dns_resolved']) {
            $diag['blocked_by_tspu'] = true;
            $diag['proxy_needed'] = true;
            $diag['error'] = 'Не удалось разрешить DNS-имя хоста ' . $host;
            $diag['verdict'] = 'DNS блокировка или отсутствие подключения к сети. ТРЕБУЕТСЯ прокси или зеркало.';
            return $diag;
        }

        // Generic error
        $diag['error'] = $curlErrno ? "cURL error {$curlErrno}: {$curlError}" : "HTTP Status {$diag['http_code']}";
        $diag['proxy_needed'] = true;
        $diag['verdict'] = 'Соединение нестабильно (' . ($diag['error'] ?? '') . '). Рекомендуется настроить прокси или зеркало.';

        return $diag;
    }

    /**
     * Execute HTTP request to Telegram Bot API.
     */
    private function apiRequest(string $method, array $params = []): array
    {
        $url = $this->apiBaseUrl . '/bot' . $this->botToken . '/' . $method;

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'DLE-Social-AutoPost/1.1');

        // Apply proxy settings if enabled
        if (!empty($this->proxyConfig['tg_proxy_enable']) && $this->proxyConfig['tg_proxy_enable'] === '1' && !empty($this->proxyConfig['tg_proxy_address'])) {
            $proxyType = ($this->proxyConfig['tg_proxy_type'] ?? '') === 'CURLPROXY_SOCKS5' ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP;
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxyType);
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyConfig['tg_proxy_address']);

            if (!empty($this->proxyConfig['tg_proxy_auth'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxyConfig['tg_proxy_auth']);
            }
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'success' => false,
                'result'  => null,
                'error'   => "cURL error {$errno}: {$errstr} (проверьте блокировку ТСПУ или настройте кастомный URL / прокси)",
            ];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [
                'success' => false,
                'result'  => null,
                'error'   => "Invalid JSON response (HTTP {$httpCode})",
            ];
        }

        if (empty($data['ok'])) {
            $errMsg = $data['description'] ?? 'Unknown Telegram error';
            $errCode = $data['error_code'] ?? $httpCode;
            return [
                'success' => false,
                'result'  => null,
                'error'   => "[Telegram {$errCode}] {$errMsg}",
            ];
        }

        return [
            'success'    => true,
            'result'     => $data['result'] ?? null,
            'message_id' => $data['result']['message_id'] ?? null,
            'error'      => null,
        ];
    }
}
