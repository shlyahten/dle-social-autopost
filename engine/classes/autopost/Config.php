<?php
/**
 * DLE Social AutoPost Plugin
 * Configuration Manager
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

class Autopost_Config
{
    private static $configFile = ENGINE_DIR . '/data/autopost.php';
    private static $config = null;

    /**
     * Get default settings
     *
     * @return array
     */
    public static function getDefaults()
    {
        return [
            'enabled'            => 1,
            'categories'         => [], // empty = all categories
            'xfield_image'       => '', // e.g. 'image', 'poster'
            
            // Telegram settings
            'tg_enabled'         => 0,
            'tg_bot_token'       => '',
            'tg_chat_id'         => '',
            'tg_api_url'         => 'https://api.telegram.org', // custom Endpoint API URL (proxy / gateway)
            'tg_parse_mode'      => 'HTML', // HTML or Markdown
            'tg_template'        => "<b>{title}</b>\n\n{short-story}\n\n{tags}",
            'tg_btn_enable'      => 1,
            'tg_btn_text'        => 'Читать на сайте',
            'tg_proxy_enable'    => 0,
            'tg_proxy_type'      => 'socks5', // socks5 or http
            'tg_proxy_addr'      => '',
            'tg_proxy_auth'      => '',

            // VK settings
            'vk_enabled'         => 0,
            'vk_token'           => '',
            'vk_group_id'        => '',
            'vk_template'        => "{title}\n\n{short-story}\n\n{link}\n\n{tags}",
            'vk_from_group'      => 1,
            'vk_signed'          => 0,

            // UTM tracking
            'utm_enable'         => 1,
            'utm_source_tg'      => 'telegram',
            'utm_medium_tg'      => 'channel',
            'utm_campaign_tg'    => 'press_release',
            'utm_source_vk'      => 'vk',
            'utm_medium_vk'      => 'wall',
            'utm_campaign_vk'    => 'press_release',

            // Queue & Cron
            'max_retries'        => 3,
            'cron_key'           => md5(uniqid(mt_rand(), true)),
            'log_limit'          => 500,
        ];
    }

    /**
     * Load settings with defaults
     *
     * @return array
     */
    public static function get()
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $defaults = self::getDefaults();

        if (file_exists(self::$configFile)) {
            $saved = @include(self::$configFile);
            if (is_array($saved)) {
                self::$config = array_merge($defaults, $saved);
                return self::$config;
            }
        }

        self::$config = $defaults;
        return self::$config;
    }

    /**
     * Save settings to engine/data/autopost.php
     *
     * @param array $newSettings
     * @return bool
     */
    public static function save(array $newSettings)
    {
        $current = self::get();
        $merged = array_merge($current, $newSettings);

        $content = "<?php\n\n";
        $content .= "// DLE Social AutoPost Configuration\n";
        $content .= "// Auto-generated on " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "return " . var_export($merged, true) . ";\n";

        $result = @file_put_contents(self::$configFile, $content, LOCK_EX);
        if ($result !== false) {
            @chmod(self::$configFile, 0666);
            self::$config = $merged;
            return true;
        }

        return false;
    }
}
