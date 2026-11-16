<?php
/**
 * DLE Social AutoPost Plugin
 * VK (Vkontakte) API Client
 * 
 * @package    DLE_Autopost
 * @author     Antigravity / Google DeepMind
 * @version    1.0.0
 */

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

class Autopost_VkClient
{
    const API_VERSION = '5.131';

    private $token;
    private $groupId;
    private $fromGroup;
    private $signed;

    public function __construct($token, $groupId, $fromGroup = 1, $signed = 0)
    {
        $this->token = trim($token);
        // Normalize group ID (ensure positive number for API calls, negative for wall owner_id)
        $this->groupId = abs(intval($groupId));
        $this->fromGroup = intval($fromGroup);
        $this->signed = intval($signed);
    }

    /**
     * Send post to VK community wall
     *
     * @param string     $text
     * @param array|null $imageInfo ['url' => ..., 'local_path' => ...]
     * @param string|null $linkUrl
     * @return array ['success' => bool, 'post_id' => string|null, 'error' => string|null]
     */
    public function sendPost($text, $imageInfo = null, $linkUrl = null)
    {
        if (empty($this->token) || empty($this->groupId)) {
            return ['success' => false, 'error' => 'VK Access Token or Group ID is not configured'];
        }

        $attachments = [];

        // Upload photo if present
        if (!empty($imageInfo)) {
            $photoAttachment = $this->uploadWallPhoto($imageInfo);
            if ($photoAttachment) {
                $attachments[] = $photoAttachment;
            }
        }

        // Attach link if provided and not already in text
        if (!empty($linkUrl) && strpos($text, $linkUrl) === false) {
            $attachments[] = $linkUrl;
        }

        $params = [
            'owner_id'   => '-' . $this->groupId,
            'from_group' => $this->fromGroup,
            'signed'     => $this->signed,
            'message'    => $text,
        ];

        if (!empty($attachments)) {
            $params['attachments'] = implode(',', $attachments);
        }

        $response = $this->request('wall.post', $params);

        if (!$response['success']) {
            return $response;
        }

        $postId = $response['result']['post_id'] ?? null;
        return [
            'success' => true,
            'post_id' => $postId ? (string)$postId : null,
            'result'  => $response['result']
        ];
    }

    /**
     * Upload photo to VK server for wall attachment
     *
     * @param array $imageInfo
     * @return string|null e.g. "photo-123456_789012"
     */
    private function uploadWallPhoto(array $imageInfo)
    {
        // 1. Get upload server
        $serverRes = $this->request('photos.getWallUploadServer', [
            'group_id' => $this->groupId
        ]);

        if (!$serverRes['success'] || empty($serverRes['result']['upload_url'])) {
            return null;
        }

        $uploadUrl = $serverRes['result']['upload_url'];

        // Determine file to upload
        $tempFile = null;
        $filePath = null;

        if (!empty($imageInfo['local_path']) && file_exists($imageInfo['local_path'])) {
            $filePath = $imageInfo['local_path'];
        } elseif (!empty($imageInfo['url'])) {
            // Download remote image to temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'vk_img_');
            $imgContent = @file_get_contents($imageInfo['url']);
            if ($imgContent !== false && !empty($imgContent)) {
                file_put_contents($tempFile, $imgContent);
                $filePath = $tempFile;
            }
        }

        if (!$filePath || !file_exists($filePath)) {
            if ($tempFile && file_exists($tempFile)) @unlink($tempFile);
            return null;
        }

        // 2. Upload file to VK upload URL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'photo' => new CURLFile($filePath)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $uploadRaw = curl_exec($ch);
        curl_close($ch);

        if ($tempFile && file_exists($tempFile)) {
            @unlink($tempFile);
        }

        if (!$uploadRaw) {
            return null;
        }

        $uploadData = json_decode($uploadRaw, true);
        if (empty($uploadData['photo']) || empty($uploadData['server']) || empty($uploadData['hash'])) {
            return null;
        }

        // 3. Save wall photo in VK
        $saveRes = $this->request('photos.saveWallPhoto', [
            'group_id' => $this->groupId,
            'photo'    => $uploadData['photo'],
            'server'   => $uploadData['server'],
            'hash'     => $uploadData['hash']
        ]);

        if (!$saveRes['success'] || empty($saveRes['result'][0]['id'])) {
            return null;
        }

        $saved = $saveRes['result'][0];
        return 'photo' . $saved['owner_id'] . '_' . $saved['id'];
    }

    /**
     * Test VK connection & permissions
     *
     * @return array
     */
    public function testConnection()
    {
        if (empty($this->token)) {
            return ['success' => false, 'error' => 'VK Access Token is empty'];
        }

        if (empty($this->groupId)) {
            return ['success' => false, 'error' => 'VK Group ID is empty'];
        }

        $res = $this->request('groups.getById', [
            'group_id' => $this->groupId,
            'fields'   => 'description,can_post'
        ]);

        if (!$res['success']) {
            return ['success' => false, 'error' => 'VK API error: ' . ($res['error'] ?? 'Unknown error')];
        }

        $group = $res['result']['groups'][0] ?? ($res['result'][0] ?? null);
        if (!$group) {
            return ['success' => false, 'error' => 'Group not found for ID: ' . $this->groupId];
        }

        $groupName = $group['name'] ?? ('ID ' . $this->groupId);
        return [
            'success'    => true,
            'group_name' => $groupName,
            'screen_name'=> $group['screen_name'] ?? ''
        ];
    }

    /**
     * Execute VK API request
     *
     * @param string $method
     * @param array  $params
     * @return array
     */
    private function request($method, array $params = [])
    {
        $params['access_token'] = $this->token;
        $params['v'] = self::API_VERSION;

        $url = 'https://api.vk.com/method/' . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        $data = json_decode($response, true);
        if (!$data) {
            return ['success' => false, 'error' => "HTTP {$httpCode}: Invalid JSON response ({$response})"];
        }

        if (isset($data['error'])) {
            $errCode = $data['error']['error_code'] ?? $httpCode;
            $errMsg = $data['error']['error_msg'] ?? 'Unknown VK error';
            return ['success' => false, 'error' => "VK error [{$errCode}]: {$errMsg}"];
        }

        return [
            'success' => true,
            'result'  => $data['response'] ?? []
        ];
    }
}
