<?php
/**
 * DLE Social AutoPost Plugin
 * Template Renderer & Text Formatter
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

class Autopost_TemplateEngine
{
    /**
     * Render message template for specific channel
     *
     * @param string $template
     * @param array  $newsRow
     * @param string $channel 'telegram' or 'vk'
     * @param array  $config
     * @return string
     */
    public static function render($template, array $newsRow, $channel, array $config)
    {
        $link = self::buildNewsUrl($newsRow, $channel, $config);

        $title = strip_tags(stripslashes($newsRow['title'] ?? ''));
        $shortStory = self::cleanText($newsRow['short_story'] ?? '');
        $fullStory = self::cleanText($newsRow['full_story'] ?? '');
        $tags = self::formatTags($newsRow['tags'] ?? '');
        $category = self::getCategoryNames($newsRow['category'] ?? '');
        $date = !empty($newsRow['date']) ? date('d.m.Y H:i', strtotime($newsRow['date'])) : '';

        // If template is empty, provide sane fallback
        if (empty(trim($template))) {
            $template = ($channel === 'telegram')
                ? "<b>{title}</b>\n\n{short-story}\n\n{link}\n\n{tags}"
                : "{title}\n\n{short-story}\n\n{link}\n\n{tags}";
        }

        $placeholders = [
            '{title}'       => $title,
            '{short-story}' => $shortStory,
            '{full-story}'  => $fullStory,
            '{link}'        => $link,
            '{tags}'        => $tags,
            '{category}'    => $category,
            '{date}'        => $date,
        ];

        $rendered = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        // Normalize multiple blank lines to at most two
        $rendered = preg_replace("/\n{3,}/", "\n\n", trim($rendered));

        return $rendered;
    }

    /**
     * Construct canonical URL with optional UTM parameters
     *
     * @param array  $newsRow
     * @param string $channel
     * @param array  $pluginConfig
     * @return string
     */
    public static function buildNewsUrl(array $newsRow, $channel, array $pluginConfig)
    {
        global $config, $cat_info;

        $baseUrl = rtrim($config['http_home_url'], '/') . '/';
        $newsId = intval($newsRow['id'] ?? 0);
        $altName = trim($newsRow['alt_name'] ?? '');
        $catId = intval($newsRow['category'] ?? 0);
        $date = $newsRow['date'] ?? date('Y-m-d H:i:s');

        $url = '';

        if (!empty($config['allow_alt_url'])) {
            $seoType = intval($config['seo_type'] ?? 1);

            if ($seoType == 1) {
                // site.ru/123-alt_name.html
                $url = $baseUrl . $newsId . '-' . $altName . '.html';
            } elseif ($seoType == 2) {
                // site.ru/category/123-alt_name.html
                $catPath = '';
                if (!empty($cat_info[$catId]['alt_name'])) {
                    $catPath = self::getCategoryUrlPath($catId) . '/';
                }
                $url = $baseUrl . $catPath . $newsId . '-' . $altName . '.html';
            } else {
                // site.ru/YYYY/MM/DD/alt_name.html
                $url = $baseUrl . date('Y/m/d/', strtotime($date)) . $altName . '.html';
            }
        } else {
            $url = $baseUrl . 'index.php?newsid=' . $newsId;
        }

        // Apply UTM tags if enabled
        if (!empty($pluginConfig['utm_enable'])) {
            $params = [];
            if ($channel === 'telegram') {
                if (!empty($pluginConfig['utm_source_tg'])) $params['utm_source'] = $pluginConfig['utm_source_tg'];
                if (!empty($pluginConfig['utm_medium_tg'])) $params['utm_medium'] = $pluginConfig['utm_medium_tg'];
                if (!empty($pluginConfig['utm_campaign_tg'])) $params['utm_campaign'] = $pluginConfig['utm_campaign_tg'];
            } elseif ($channel === 'vk') {
                if (!empty($pluginConfig['utm_source_vk'])) $params['utm_source'] = $pluginConfig['utm_source_vk'];
                if (!empty($pluginConfig['utm_medium_vk'])) $params['utm_medium'] = $pluginConfig['utm_medium_vk'];
                if (!empty($pluginConfig['utm_campaign_vk'])) $params['utm_campaign'] = $pluginConfig['utm_campaign_vk'];
            }

            if (!empty($params)) {
                $separator = (strpos($url, '?') !== false) ? '&' : '?';
                $url .= $separator . http_build_query($params);
            }
        }

        return $url;
    }

    /**
     * Recursively resolve category path for URL
     *
     * @param int $catId
     * @return string
     */
    private static function getCategoryUrlPath($catId)
    {
        global $cat_info;

        if (empty($cat_info[$catId])) {
            return '';
        }

        $parts = [];
        $current = $catId;

        while ($current > 0 && !empty($cat_info[$current])) {
            array_unshift($parts, $cat_info[$current]['alt_name']);
            $current = intval($cat_info[$current]['parentid'] ?? 0);
        }

        return implode('/', $parts);
    }

    /**
     * Convert DLE tags into social hashtags
     *
     * @param string $tagsRaw
     * @return string
     */
    public static function formatTags($tagsRaw)
    {
        if (empty($tagsRaw)) {
            return '';
        }

        $items = explode(',', $tagsRaw);
        $hashtags = [];

        foreach ($items as $tag) {
            $clean = trim($tag);
            if (empty($clean)) {
                continue;
            }
            // Replace spaces with underscores and remove unwanted characters
            $clean = preg_replace('/[^\p{L}\p{N}_]+/u', '', str_replace(' ', '_', $clean));
            if (!empty($clean)) {
                $hashtags[] = '#' . $clean;
            }
        }

        return implode(' ', $hashtags);
    }

    /**
     * Get human-readable category name(s)
     *
     * @param string|int $catIds
     * @return string
     */
    public static function getCategoryNames($catIds)
    {
        global $cat_info;

        if (empty($catIds) || empty($cat_info)) {
            return '';
        }

        $ids = explode(',', (string)$catIds);
        $names = [];

        foreach ($ids as $id) {
            $id = intval(trim($id));
            if (!empty($cat_info[$id]['name'])) {
                $names[] = stripslashes($cat_info[$id]['name']);
            }
        }

        return implode(', ', $names);
    }

    /**
     * Clean HTML and BB-codes from text
     *
     * @param string $text
     * @return string
     */
    public static function cleanText($text)
    {
        if (empty($text)) {
            return '';
        }

        $text = stripslashes($text);

        // Remove script and style tags completely
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $text);

        // Remove DLE specific tags
        $text = preg_replace('/<!--dle_image_begin:.*?-->.*?<!--dle_image_end-->/is', '', $text);
        $text = preg_replace('/<!--TBegin:.*?-->.*?<!--TEnd-->/is', '', $text);
        $text = preg_replace('/<!--dle_media_begin:.*?-->.*?<!--dle_media_end-->/is', '', $text);

        // Replace <br> and </p> with newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);

        // Strip remaining HTML tags
        $text = strip_tags($text);

        // Strip BB-codes
        $text = preg_replace('/\[(b|i|u|s|quote|code|url|img|thumb|spoiler|video).*?\]/is', '', $text);
        $text = preg_replace('/\[\/(b|i|u|s|quote|code|url|img|thumb|spoiler|video)\]/is', '', $text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize multiple spaces and linebreaks
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Safely truncate text without breaking words or tags
     *
     * @param string $text
     * @param int $maxLength
     * @param string $append
     * @return string
     */
    public static function truncate($text, $maxLength = 1000, $append = '...')
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        $cutLength = $maxLength - mb_strlen($append, 'UTF-8');
        $sub = mb_substr($text, 0, $cutLength, 'UTF-8');

        // Break at last space if possible
        $lastSpace = mb_strrpos($sub, ' ', 0, 'UTF-8');
        if ($lastSpace !== false && $lastSpace > ($cutLength * 0.7)) {
            $sub = mb_substr($sub, 0, $lastSpace, 'UTF-8');
        }

        return rtrim($sub) . $append;
    }
}
