<?php
/**
 * DLE Social AutoPost - Configuration Manager
 *
 * Manages plugin configuration stored in engine/data/autopost.php.
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

class AutopostConfig
{
    private static ?array $config = null;
    private static string $configFile = ENGINE_DIR . '/data/autopost.php';

    /**
     * Default configuration options.
     */
    public static function defaults(): array
    {
        return [
            // General
            'enable' => '1',
            'cron_key' => bin2hex(random_bytes(16)),
            'log_limit' => '500',

            // Telegram
            'tg_enable' => '0',
            'tg_bot_token' => '',
            'tg_channel_id' => '',
            'tg_api_url' => 'https://api.telegram.org', // Custom Bot API endpoint / mirror / TSPU bypass
            'tg_template' => "<b>{title}</b>\n\n{short-story}\n\n{tags}",
            'tg_send_photo' => '1',
            'tg_inline_button' => '1',
            'tg_button_text' => 'Читать на сайте',
            'tg_disable_web_page_preview' => '0',
            'tg_disable_notification' => '0',

            // Telegram Proxy (optional for blocked servers)
            'tg_proxy_enable' => '0',
            'tg_proxy_type' => 'CURLPROXY_SOCKS5', // CURLPROXY_HTTP or CURLPROXY_SOCKS5
            'tg_proxy_address' => '',
            'tg_proxy_auth' => '',

            // VK
            'vk_enable' => '0',
            'vk_access_token' => '',
            'vk_owner_id' => '', // negative ID for community (e.g. -12345678)
            'vk_from_group' => '1',
            'vk_signed' => '0',
            'vk_template' => "{title}\n\n{short-story}\n\nЧитать далее: {link}\n\n{tags}",
            'vk_attach_photo' => '1',

            // Filtering
            'allowed_categories' => '', // Comma-separated category IDs or empty for all
            'auto_tags_to_hashtags' => '1',
            'utm_enable' => '1',
            'utm_source' => '{channel}', // replaced with 'telegram' or 'vk'
            'utm_medium' => 'social',
            'utm_campaign' => 'autopost',
        ];
    }

    /**
     * Get single configuration value or entire config array.
     */
    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::load();
        }

        if ($key === null) {
            return self::$config;
        }

        return self::$config[$key] ?? $default;
    }

    /**
     * Set a single configuration value in-memory.
     */
    public static function set(string $key, mixed $value): void
    {
        if (self::$config === null) {
            self::load();
        }
        self::$config[$key] = $value;
    }

    /**
     * Load configuration from file, falling back to defaults.
     */
    public static function load(): array
    {
        $defaults = self::defaults();

        if (file_exists(self::$configFile)) {
            $saved = @include self::$configFile;
            if (is_array($saved)) {
                self::$config = array_merge($defaults, $saved);
                return self::$config;
            }
        }

        self::$config = $defaults;
        return self::$config;
    }

    /**
     * Save current configuration to file with atomic write.
     */
    public static function save(?array $data = null): bool
    {
        if ($data !== null) {
            $current = self::load();
            self::$config = array_merge($current, $data);
        }

        if (self::$config === null) {
            self::$config = self::defaults();
        }

        $exported = var_export(self::$config, true);
        $content = "<?php\n\n// DLE Social AutoPost Configuration\n// Auto-generated: " . date('Y-m-d H:i:s') . "\n\nreturn " . $exported . ";\n";

        $tempFile = self::$configFile . '.' . uniqid('tmp_', true);
        if (@file_put_contents($tempFile, $content) === false) {
            return false;
        }

        @chmod($tempFile, 0666);
        return @rename($tempFile, self::$configFile);
    }
}
