<?php
/**
 * DLE Social AutoPost - Template Engine & Variable Interpolation
 *
 * Renders message templates with tokens ({title}, {short-story}, {link}, {tags}, {category}, etc.).
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

class AutopostTemplateEngine
{
    /**
     * Render template for target channel.
     *
     * @param string $template Template string with tokens
     * @param array $news News post data row from DLE DB
     * @param string $channel Target channel: 'telegram' or 'vk'
     * @param array $pluginConfig Plugin config
     * @return string Rendered message text
     */
    public static function render(string $template, array $news, string $channel, array $pluginConfig): string
    {
        global $config;

        $title = htmlspecialchars_decode(strip_tags($news['title'] ?? ''), ENT_QUOTES);
        $shortStory = self::cleanText($news['short_story'] ?? '');
        $fullStory = self::cleanText($news['full_story'] ?? '');

        // Generate news URL with UTM tags
        $link = self::buildNewsUrl($news, $channel, $pluginConfig);

        // Tags to hashtags
        $tags = self::formatTags($news['tags'] ?? '', $channel);

        // Categories list
        $category = self::formatCategory($news['category'] ?? '');

        // Publication date
        $date = !empty($news['date']) ? date('d.m.Y H:i', strtotime($news['date'])) : date('d.m.Y H:i');

        // Replacements
        $vars = [
            '{title}'       => $title,
            '{short-story}' => $shortStory,
            '{full-story}'  => $fullStory,
            '{link}'        => $link,
            '{tags}'        => $tags,
            '{category}'    => $category,
            '{date}'        => $date,
        ];

        // Format specific adjustments
        if ($channel === 'telegram') {
            // For Telegram HTML mode, escape title and texts
            $vars['{title}'] = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $vars['{short-story}'] = htmlspecialchars($shortStory, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $vars['{full-story}'] = htmlspecialchars($fullStory, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $text = str_replace(array_keys($vars), array_values($vars), $template);

        // Clean up excessive blank lines (more than 2 consecutive newlines)
        $text = preg_replace("/(\r?\n){3,}/", "\n\n", trim($text));

        return $text;
    }

    /**
     * Clean HTML and BB-codes from text for plain text or clean message previews.
     */
    public static function cleanText(string $text): string
    {
        // Strip BB-codes like [b], [/b], [img]...[/img], [quote]...[/quote]
        $text = preg_replace('/\[(img|thumb|quote|code|attachment|hide)[^\]]*\].*?\[\/\1\]/is', '', $text);
        $text = preg_replace('/\[\/?(b|i|u|s|url|color|size|font|center|left|right)[^\]]*\]/i', '', $text);

        // Strip HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespaces
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/(\r?\n)+/', "\n", $text);

        return trim($text);
    }

    /**
     * Format tags as hashtags suitable for Telegram and VK (e.g., #Новости #Технологии).
     */
    public static function formatTags(string $tagsString, string $channel): string
    {
        if (empty(trim($tagsString))) {
            return '';
        }

        $tags = explode(',', $tagsString);
        $hashtags = [];

        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (empty($tag)) {
                continue;
            }
            // Replace spaces and special characters with underscores for valid hashtag
            $tagClean = preg_replace('/[^\p{L}\p{Nd}_]/u', '_', $tag);
            $tagClean = trim($tagClean, '_');
            if (!empty($tagClean)) {
                $hashtags[] = '#' . $tagClean;
            }
        }

        return implode(' ', $hashtags);
    }

    /**
     * Format news category IDs to names.
     */
    public static function formatCategory(string $categoryIds): string
    {
        global $cat_info;

        if (empty($categoryIds) || empty($cat_info)) {
            return '';
        }

        $ids = explode(',', $categoryIds);
        $names = [];

        foreach ($ids as $id) {
            $id = (int)trim($id);
            if (!empty($cat_info[$id]['name'])) {
                $names[] = $cat_info[$id]['name'];
            }
        }

        return implode(', ', $names);
    }

    /**
     * Build absolute canonical news URL with optional UTM parameters.
     */
    public static function buildNewsUrl(array $news, string $channel, array $pluginConfig): string
    {
        global $config;

        $siteUrl = rtrim($config['http_home_url'] ?? '', '/');

        // Check if DLE full_link helper exists or build standard URL
        if (function_exists('totranslit') && !empty($news['alt_name'])) {
            $altName = $news['alt_name'];
        } else {
            $altName = 'post';
        }

        $newsId = (int)($news['id'] ?? 0);
        $catId = (int)($news['category'] ?? 0);

        // Standard DLE URL patterns based on SEO settings
        $seoType = (int)($config['allow_alt_url'] ?? 0);

        if ($seoType === 1) {
            // /category/123-alt_name.html or /123-alt_name.html
            $url = $siteUrl . '/' . $newsId . '-' . $altName . '.html';
        } elseif ($seoType === 2) {
            // /123-alt_name.html
            $url = $siteUrl . '/' . $newsId . '-' . $altName . '.html';
        } else {
            // index.php?newsid=123
            $url = $siteUrl . '/index.php?newsid=' . $newsId;
        }

        // Add UTM tags if enabled
        if (!empty($pluginConfig['utm_enable']) && $pluginConfig['utm_enable'] === '1') {
            $utmSource = str_replace('{channel}', $channel, $pluginConfig['utm_source'] ?? $channel);
            $utmMedium = $pluginConfig['utm_medium'] ?? 'social';
            $utmCampaign = $pluginConfig['utm_campaign'] ?? 'autopost';

            $queryParams = [
                'utm_source'   => $utmSource,
                'utm_medium'   => $utmMedium,
                'utm_campaign' => $utmCampaign,
            ];

            $separator = (str_contains($url, '?')) ? '&' : '?';
            $url .= $separator . http_build_query($queryParams);
        }

        return $url;
    }
}
