<?php

declare(strict_types=1);

namespace SecurityDrama\Publish\Adapters;

use RuntimeException;
use SecurityDrama\HttpClient;
use SecurityDrama\Logger;
use SecurityDrama\Publish\PublishAdapterInterface;

final class DirectYouTubeAdapter implements PublishAdapterInterface
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';
    private const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB

    private HttpClient $http;
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;

    public function __construct()
    {
        $this->http = new HttpClient();
        $this->clientId = $_ENV['YOUTUBE_CLIENT_ID'] ?? '';
        $this->clientSecret = $_ENV['YOUTUBE_CLIENT_SECRET'] ?? '';
        $this->refreshToken = $_ENV['YOUTUBE_REFRESH_TOKEN'] ?? '';

        if ($this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new RuntimeException('YouTube OAuth credentials are not fully configured (YOUTUBE_CLIENT_ID, YOUTUBE_CLIENT_SECRET, YOUTUBE_REFRESH_TOKEN)');
        }
    }

    public function getAdapterName(): string
    {
        return 'direct_youtube';
    }

    public function supportsPlatform(string $platform): bool
    {
        return $platform === 'youtube';
    }

    public function publish(string $platform, array $videoData, array $contentData, array $platformConfig): array
    {
        if ($platform !== 'youtube') {
            return [
                'success'  => false,
                'post_id'  => '',
                'post_url' => '',
                'error'    => 'DirectYouTubeAdapter only supports the youtube platform',
            ];
        }

        $localPath = $videoData['local_path'] ?? '';
        if ($localPath === '' || !file_exists($localPath)) {
            return [
                'success'  => false,
                'post_id'  => '',
                'post_url' => '',
                'error'    => "Video file not found: {$localPath}",
            ];
        }

        try {
            $accessToken = $this->refreshAccessToken();

            $title = $contentData['title'] ?? 'Security Drama';
            $description = $contentData['description'] ?? '';
            $hashtagString = $contentData['hashtag_string'] ?? '';
            $tags = $this->extractTags($hashtagString);

            // Detect Shorts: 9:16 aspect ratio and under 60 seconds
            $isShorts = $this->isShorts($localPath, $videoData);
            if ($isShorts && !str_contains($title, '#Shorts')) {
                $title = $title . ' #Shorts';
            }

            $metadata = [
                'snippet' => [
                    'title'       => $title,
                    'description' => $description,
                    'tags'        => $tags,
                    'categoryId'  => '28', // Science & Technology
                ],
                'status' => [
                    'privacyStatus' => $platformConfig['privacy'] ?? 'public',
                ],
            ];

            $uploadUrl = $this->initiateResumableUpload($accessToken, $metadata, $localPath);
            $videoId = $this->uploadInChunks($uploadUrl, $localPath, $accessToken);

            $postUrl = $isShorts
                ? "https://youtube.com/shorts/{$videoId}"
                : "https://youtube.com/watch?v={$videoId}";

            Logger::info('publish', "YouTube upload complete: {$videoId}", [
                'video_id' => $videoId,
                'is_shorts' => $isShorts,
            ]);

            return [
                'success'  => true,
                'post_id'  => $videoId,
                'post_url' => $postUrl,
                'error'    => '',
            ];
        } catch (\Throwable $e) {
            Logger::error('publish', "YouTube upload failed: {$e->getMessage()}");

            return [
                'success'  => false,
                'post_id'  => '',
                'post_url' => '',
                'error'    => $e->getMessage(),
            ];
        }
    }

    private function refreshAccessToken(): string
    {
        $response = $this->http->post(self::TOKEN_URL, http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type'    => 'refresh_token',
        ]), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $body = json_decode($response['body'], true);

        if (!isset($body['access_token'])) {
            $error = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            throw new RuntimeException("Failed to refresh YouTube access token: {$error}");
        }

        Logger::debug('publish', 'YouTube access token refreshed');

        return $body['access_token'];
    }

    /**
     * Initiate a resumable upload session and return the upload URI.
     */
    private function initiateResumableUpload(string $accessToken, array $metadata, string $localPath): string
    {
        $fileSize = filesize($localPath);
        $url = self::UPLOAD_URL . '?uploadType=resumable&part=snippet,status';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$accessToken}",
                'Content-Type: application/json; charset=UTF-8',
                "X-Upload-Content-Length: {$fileSize}",
                'X-Upload-Content-Type: video/mp4',
            ],
            CURLOPT_POSTFIELDS     => json_encode($metadata),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException("Failed to initiate resumable upload: HTTP {$httpCode}");
        }

        // Extract Location header for the upload URI
        if (preg_match('/^location:\s*(.+)$/mi', $response, $matches)) {
            return trim($matches[1]);
        }

        throw new RuntimeException('No upload URI returned from YouTube resumable upload initiation');
    }

    /**
     * Upload video file in 5MB chunks using the resumable upload URI.
     */
    private function uploadInChunks(string $uploadUrl, string $localPath, string $accessToken): string
    {
        $fileSize = filesize($localPath);
        $handle = fopen($localPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open video file: {$localPath}");
        }

        try {
            $offset = 0;

            while ($offset < $fileSize) {
                $chunkData = fread($handle, self::CHUNK_SIZE);
                if ($chunkData === false) {
                    throw new RuntimeException('Failed to read video file chunk');
                }

                $chunkLength = strlen($chunkData);
                $rangeEnd = $offset + $chunkLength - 1;
                $contentRange = "bytes {$offset}-{$rangeEnd}/{$fileSize}";

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $uploadUrl,
                    CURLOPT_CUSTOMREQUEST  => 'PUT',
                    CURLOPT_HTTPHEADER     => [
                        "Authorization: Bearer {$accessToken}",
                        'Content-Type: video/mp4',
                        "Content-Length: {$chunkLength}",
                        "Content-Range: {$contentRange}",
                    ],
                    CURLOPT_POSTFIELDS     => $chunkData,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 300,
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($response === false) {
                    throw new RuntimeException("Chunk upload failed at offset {$offset}");
                }

                // 308 Resume Incomplete = more chunks needed, 200/201 = upload complete
                if ($httpCode === 200 || $httpCode === 201) {
                    $body = json_decode($response, true);
                    $videoId = $body['id'] ?? '';
                    if ($videoId === '') {
                        throw new RuntimeException('Upload completed but no video ID returned');
                    }
                    return $videoId;
                }

                if ($httpCode !== 308) {
                    throw new RuntimeException("Unexpected status {$httpCode} during chunk upload at offset {$offset}");
                }

                $offset += $chunkLength;
            }

            throw new RuntimeException('Upload completed all chunks but never received a final response');
        } finally {
            fclose($handle);
        }
    }

    /**
     * Determine if video is a YouTube Short (9:16 aspect ratio, under 60 seconds).
     */
    private function isShorts(string $localPath, array $videoData): bool
    {
        $aspectRatio = $videoData['aspect_ratio'] ?? '';
        $duration = (float) ($videoData['duration_seconds'] ?? 0);

        if ($aspectRatio === '9:16' && $duration > 0 && $duration <= 60) {
            return true;
        }

        // Fallback: check dimensions from video data
        $width = (int) ($videoData['width'] ?? 0);
        $height = (int) ($videoData['height'] ?? 0);

        if ($width > 0 && $height > 0 && $height > $width && $duration > 0 && $duration <= 60) {
            return true;
        }

        return false;
    }

    /**
     * Extract individual tags from a hashtag string.
     *
     * @return string[]
     */
    private function extractTags(string $hashtagString): array
    {
        if ($hashtagString === '') {
            return [];
        }

        preg_match_all('/#(\w+)/', $hashtagString, $matches);
        return $matches[1] ?? [];
    }
}
