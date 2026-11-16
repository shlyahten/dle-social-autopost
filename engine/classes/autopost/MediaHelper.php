<?php
/**
 * DLE Social AutoPost Plugin
 * Media & Image Extractor Helper
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

class Autopost_MediaHelper
{
    /**
     * Find main image for news
     *
     * @param array $newsRow News data from dle_post table
     * @param string $preferredXfield Name of xfield configured for image
     * @return array|null Returns ['url' => ..., 'local_path' => ...] or null
     */
    public static function extractImage(array $newsRow, $preferredXfield = '')
    {
        global $config;

        $siteUrl = rtrim($config['http_home_url'], '/') . '/';

        // 1. Check preferred extra field (xfield)
        if (!empty($preferredXfield) && !empty($newsRow['xfields'])) {
            $xfieldsData = self::parseXfields($newsRow['xfields']);
            if (!empty($xfieldsData[$preferredXfield])) {
                $xfValue = trim($xfieldsData[$preferredXfield]);
                $imageInfo = self::resolveImage($xfValue, $siteUrl);
                if ($imageInfo) {
                    return $imageInfo;
                }
            }
        }

        // 2. Scan short_story then full_story for images
        $stories = [
            $newsRow['short_story'] ?? '',
            $newsRow['full_story'] ?? ''
        ];

        foreach ($stories as $content) {
            if (empty($content)) {
                continue;
            }

            // A. DLE specific image comments
            if (preg_match('/<!--dle_image_begin:(.+?)(?:\|.*?)?-->/is', $content, $m)) {
                $imageInfo = self::resolveImage(trim($m[1]), $siteUrl);
                if ($imageInfo) return $imageInfo;
            }

            // B. DLE thumbnails
            if (preg_match('/<!--TBegin:(.+?)(?:\|.*?)?-->/is', $content, $m)) {
                $imageInfo = self::resolveImage(trim($m[1]), $siteUrl);
                if ($imageInfo) return $imageInfo;
            }

            // C. HTML <img> tags
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/is', $content, $m)) {
                $imageInfo = self::resolveImage(trim($m[1]), $siteUrl);
                if ($imageInfo) return $imageInfo;
            }

            // D. BB-codes [img] and [thumb]
            if (preg_match('/\[(?:img|thumb)\]([^\[]+)\[\/(?:img|thumb)\]/is', $content, $m)) {
                $imageInfo = self::resolveImage(trim($m[1]), $siteUrl);
                if ($imageInfo) return $imageInfo;
            }
        }

        return null;
    }

    /**
     * Parse DLE xfields string
     *
     * @param string $xfieldsRaw
     * @return array
     */
    public static function parseXfields($xfieldsRaw)
    {
        $result = [];
        if (empty($xfieldsRaw)) {
            return $result;
        }

        $items = explode('||', $xfieldsRaw);
        foreach ($items as $item) {
            $parts = explode('|', $item, 2);
            if (count($parts) === 2) {
                $result[$parts[0]] = $parts[1];
            }
        }

        return $result;
    }

    /**
     * Resolve image path or URL
     *
     * @param string $imagePath
     * @param string $siteUrl
     * @return array|null
     */
    private static function resolveImage($imagePath, $siteUrl)
    {
        $imagePath = trim($imagePath);
        if (empty($imagePath)) {
            return null;
        }

        // Handle multiple images if comma-separated
        if (strpos($imagePath, ',') !== false) {
            $parts = explode(',', $imagePath);
            $imagePath = trim($parts[0]);
        }

        // Clean quotes/spaces
        $imagePath = str_replace(['"', "'", "\r", "\n"], '', $imagePath);

        // Check if full URL
        if (preg_match('~^https?://~i', $imagePath)) {
            $url = $imagePath;
            // Check if it belongs to local server
            $localPath = null;
            if (strpos($url, $siteUrl) === 0) {
                $rel = substr($url, strlen($siteUrl));
                $candidate = ROOT_DIR . '/' . ltrim($rel, '/');
                if (file_exists($candidate) && is_readable($candidate)) {
                    $localPath = $candidate;
                }
            }
            return [
                'url'        => $url,
                'local_path' => $localPath
            ];
        }

        // Check absolute path relative to domain root: /uploads/...
        if (strpos($imagePath, '/') === 0) {
            $candidate = ROOT_DIR . $imagePath;
            $url = rtrim($siteUrl, '/') . $imagePath;
            return [
                'url'        => $url,
                'local_path' => (file_exists($candidate) && is_readable($candidate)) ? $candidate : null
            ];
        }

        // Relative path inside uploads/posts/
        $candidateInUploads = ROOT_DIR . '/uploads/posts/' . $imagePath;
        if (file_exists($candidateInUploads) && is_readable($candidateInUploads)) {
            return [
                'url'        => $siteUrl . 'uploads/posts/' . $imagePath,
                'local_path' => $candidateInUploads
            ];
        }

        // General relative path
        $candidateRoot = ROOT_DIR . '/' . $imagePath;
        if (file_exists($candidateRoot) && is_readable($candidateRoot)) {
            return [
                'url'        => $siteUrl . $imagePath,
                'local_path' => $candidateRoot
            ];
        }

        return [
            'url'        => $siteUrl . $imagePath,
            'local_path' => null
        ];
    }
}
