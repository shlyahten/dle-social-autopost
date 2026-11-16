<?php
/**
 * DLE Social AutoPost - VK API Client
 *
 * Implements wall posting via VK API v5.131+ using Community Access Tokens.
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

class AutopostVkClient
{
    private const API_VERSION = '5.131';
    private const API_BASE = 'https://api.vk.com/method/';

    private string $accessToken;
    private string $ownerId; // negative int for group: -12345678
    private bool $fromGroup;
    private bool $signed;

    public function __construct(string $accessToken, string $ownerId, bool $fromGroup = true, bool $signed = false)
    {
        $this->accessToken = trim($accessToken);
        $cleanId = preg_replace('/[^\d]/', '', $ownerId);
        $this->ownerId = '-' . $cleanId; // ensure negative for community
        $this->fromGroup = $fromGroup;
        $this->signed = $signed;
    }

    /**
     * Send post to community wall.
     *
     * @param string $message Plain text message (VK doesn't support HTML)
     * @param string|null $imageUrl Optional main image to attach
     * @param array $options Additional VK options
     * @return array ['success' => bool, 'post_id' => string|null, 'error' => string|null]
     */
    public function postToWall(string $message, ?string $imageUrl = null, array $options = []): array
    {
        if (empty($this->accessToken) || empty($this->ownerId) || $this->ownerId === '-') {
            return ['success' => false, 'post_id' => null, 'error' => 'VK credentials not configured'];
        }

        $attachments = [];

        // Upload photo if provided
        if (!empty($imageUrl)) {
            $photoAttachment = $this->uploadWallPhoto($imageUrl);
            if ($photoAttachment) {
                $attachments[] = $photoAttachment;
            }
        }

        $params = [
            'owner_id'    => $this->ownerId,
            'from_group'  => $this->fromGroup ? 1 : 0,
            'signed'      => $this->signed ? 1 : 0,
            'message'     => $message,
            'attachments' => implode(',', $attachments),
        ];

        $response = $this->apiRequest('wall.post', $params);

        if (!empty($response['response']['post_id'])) {
            return [
                'success' => true,
                'post_id' => $this->ownerId . '_' . $response['response']['post_id'],
                'error'   => null,
            ];
        }

        $errorMsg = $response['error']['error_msg'] ?? 'Unknown VK API error';
        $errorCode = $response['error']['error_code'] ?? 0;

        return [
            'success' => false,
            'post_id' => null,
            'error'   => "[Code {$errorCode}] {$errorMsg}",
        ];
    }

    /**
     * Upload photo to community wall album.
     *
     * Steps:
     * 1. photos.getWallUploadServer
     * 2. POST multipart file to upload_url
     * 3. photos.saveWallPhoto
     */
    public function uploadWallPhoto(string $imageUrl): ?string
    {
        require_once ENGINE_DIR . '/classes/autopost/MediaHelper.php';

        $localFile = AutopostMediaHelper::downloadToTemp($imageUrl);
        if (!$localFile) {
            return null;
        }

        try {
            // 1. Get upload server
            $groupId = abs((int)$this->ownerId);
            $serverResp = $this->apiRequest('photos.getWallUploadServer', ['group_id' => $groupId]);

            if (empty($serverResp['response']['upload_url'])) {
                @unlink($localFile);
                return null;
            }

            $uploadUrl = $serverResp['response']['upload_url'];

            // 2. Upload file via CURL multipart
            $ch = curl_init($uploadUrl);
            $cfile = new CURLFile($localFile, mime_content_type($localFile), basename($localFile));

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['photo' => $cfile]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $uploadResult = curl_exec($ch);
            curl_close($ch);
            @unlink($localFile);

            if (!$uploadResult) {
                return null;
            }

            $uploadData = json_decode($uploadResult, true);
            if (empty($uploadData['photo']) || empty($uploadData['server']) || empty($uploadData['hash'])) {
                return null;
            }

            // 3. Save photo
            $saveParams = [
                'group_id' => $groupId,
                'photo'    => $uploadData['photo'],
                'server'   => $uploadData['server'],
                'hash'     => $uploadData['hash'],
            ];

            $saveResp = $this->apiRequest('photos.saveWallPhoto', $saveParams);

            if (!empty($saveResp['response'][0]['id'])) {
                $photo = $saveResp['response'][0];
                return 'photo' . $photo['owner_id'] . '_' . $photo['id'];
            }

            return null;
        } catch (\Throwable $e) {
            @unlink($localFile);
            return null;
        }
    }

    /**
     * Test connection to VK API with configured token.
     */
    public function testConnection(): array
    {
        $groupId = abs((int)$this->ownerId);
        $resp = $this->apiRequest('groups.getById', ['group_id' => $groupId]);

        if (!empty($resp['response'][0]['id'])) {
            $group = $resp['response'][0];
            return [
                'success' => true,
                'name'    => $group['name'] ?? 'VK Group',
                'id'      => $group['id'] ?? $groupId,
                'error'   => null,
            ];
        }

        $errorMsg = $resp['error']['error_msg'] ?? 'Unknown error';
        $errorCode = $resp['error']['error_code'] ?? 0;

        return [
            'success' => false,
            'name'    => null,
            'id'      => null,
            'error'   => "[Code {$errorCode}] {$errorMsg}",
        ];
    }

    /**
     * Execute VK API HTTP request.
     */
    private function apiRequest(string $method, array $params): array
    {
        $params['access_token'] = $this->accessToken;
        $params['v'] = self::API_VERSION;

        $url = self::API_BASE . $method;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'DLE-Social-AutoPost/1.0');

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return ['error' => ['error_code' => $errno, 'error_msg' => 'cURL error: ' . $errstr]];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['error' => ['error_code' => 500, 'error_msg' => 'Invalid JSON from VK API']];
        }

        return $data;
    }
}
