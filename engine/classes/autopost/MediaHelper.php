<?php
/**
 * DLE Social AutoPost - Media & Image Extractor
 *
 * Extracts primary image and media URLs from news stories, xfields, or attachments.
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

class AutopostMediaHelper
{
    /**
     * Extract the main image URL for a given news item.
     *
     * Priority:
     * 1. XField image/gallery field (if configured or found)
     * 2. First <img> tag in short_story or full_story
     * 3. First [img] BB-code in short_story or full_story
     * 4. Uploaded DLE attachments image
     */
    public static function extractMainImage(array $news, array $config): ?string
    {
        global $config;

        $siteUrl = rtrim($config['http_home_url'] ?? '', '/');

        // 1. Check xfields first
        if (!empty($news['xfields'])) {
            $xfieldImage = self::findImageInXfields($news['xfields']);
            if ($xfieldImage) {
                return self::normalizeImageUrl($xfieldImage, $siteUrl);
            }
        }

        // 2. Check HTML / BB tags in short_story
        $content = ($news['short_story'] ?? '') . ' ' . ($news['full_story'] ?? '');

        // HTML <img> tag
        if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
            $url = $matches[1];
            // Filter out smileys or tiny icons
            if (!preg_match('/(dleimages|engine\/data\/emoticons)/i', $url)) {
                return self::normalizeImageUrl($url, $siteUrl);
            }
        }

        // DLE BB-code [img]url[/img]
        if (preg_match('/\[img\](.*?)\[\/img\]/i', $content, $matches)) {
            return self::normalizeImageUrl(trim($matches[1]), $siteUrl);
        }

        // DLE BB-code [thumb]url[/thumb]
        if (preg_match('/\[thumb\](.*?)\[\/thumb\]/i', $content, $matches)) {
            return self::normalizeImageUrl(trim($matches[1]), $siteUrl);
        }

        return null;
    }

    /**
     * Parse xfields data string and look for image paths.
     */
    public static function findImageInXfields(string $xfieldsData): ?string
    {
        $fields = explode('||', $xfieldsData);
        foreach ($fields as $field) {
            if (empty($field)) {
                continue;
            }
            $parts = explode('|', $field);
            if (count($parts) >= 2) {
                $val = trim($parts[1]);
                // Check if value looks like an image file
                if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $val)) {
                    // DLE image fields may store "subfolder/image.jpg" or serialized data
                    if (str_starts_with($val, '{') || str_starts_with($val, '[')) {
                        $json = @json_decode($val, true);
                        if (is_array($json) && !empty($json[0]['image'])) {
                            return $json[0]['image'];
                        }
                    }
                    return $val;
                }
            }
        }
        return null;
    }

    /**
     * Ensure the image URL is absolute and properly accessible.
     */
    public static function normalizeImageUrl(string $url, string $siteUrl): string
    {
        $url = trim($url);

        // Protocol-relative URL
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        // Already absolute URL
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // Relative to root
        if (str_starts_with($url, '/')) {
            return $siteUrl . $url;
        }

        // Relative path inside uploads
        if (str_starts_with($url, 'uploads/')) {
            return $siteUrl . '/' . $url;
        }

        return $siteUrl . '/uploads/posts/' . $url;
    }

    /**
     * Download an image to a temporary local file for upload via multipart/form-data.
     */
    public static function downloadToTemp(string $imageUrl): ?string
    {
        // If image is local on the server filesystem, use local file directly
        $parsed = parse_url($imageUrl);
        if ($parsed && !empty($parsed['path'])) {
            $localPath = ROOT_DIR . $parsed['path'];
            if (file_exists($localPath) && is_readable($localPath)) {
                return $localPath;
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'autopost_img_');
        if (!$tmpFile) {
            return null;
        }

        $ch = curl_init($imageUrl);
        $fp = fopen($tmpFile, 'wb');

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'DLE-Social-AutoPost/1.0 (+https://dle-news.ru)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode >= 400 || filesize($tmpFile) < 100) {
            @unlink($tmpFile);
            return null;
        }

        return $tmpFile;
    }
}
