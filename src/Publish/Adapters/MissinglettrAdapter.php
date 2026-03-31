<?php

declare(strict_types=1);

namespace SecurityDrama\Publish\Adapters;

use RuntimeException;
use SecurityDrama\HttpClient;
use SecurityDrama\Logger;
use SecurityDrama\Publish\PublishAdapterInterface;

final class MissinglettrAdapter implements PublishAdapterInterface
{
    private const API_BASE = 'https://api.missinglettr-api.com/api/v1';

    private const SUPPORTED_PLATFORMS = [
        'twitter', 'linkedin', 'facebook', 'instagram', 'pinterest',
        'tiktok', 'youtube', 'threads', 'reddit', 'google_business',
        'mastodon', 'bluesky',
    ];

    /**
     * Map our internal platform names to Missinglettr platform names.
     */
    private const PLATFORM_MAP = [
        'x'               => 'twitter',
        'twitter'          => 'twitter',
        'linkedin'         => 'linkedin',
        'facebook'         => 'facebook',
        'instagram'        => 'instagram',
        'pinterest'        => 'pinterest',
        'tiktok'           => 'tiktok',
        'youtube'          => 'youtube',
        'threads'          => 'threads',
        'reddit'           => 'reddit',
        'google_business'  => 'google_business',
        'mastodon'         => 'mastodon',
        'bluesky'          => 'bluesky',
    ];

    private HttpClient $http;
    private string $apiKey;
    private string $workspaceId;

    public function __construct()
    {
        $this->http = new HttpClient();
        $this->apiKey = $_ENV['MISSINGLETTR_API_KEY'] ?? '';
        $this->workspaceId = $_ENV['MISSINGLETTR_WORKSPACE_ID'] ?? '';

        if ($this->apiKey === '') {
            throw new RuntimeException('MISSINGLETTR_API_KEY environment variable is not set');
        }
    }

    public function getAdapterName(): string
    {
        return 'missinglettr';
    }

    public function supportsPlatform(string $platform): bool
    {
        $mapped = self::PLATFORM_MAP[$platform] ?? null;
        return $mapped !== null && in_array($mapped, self::SUPPORTED_PLATFORMS, true);
    }

    public function publish(string $platform, array $videoData, array $contentData, array $platformConfig): array
    {
        $missinglettrPlatform = self::PLATFORM_MAP[$platform] ?? $platform;

        if (!$this->supportsPlatform($platform)) {
            return [
                'success'  => false,
                'post_id'  => '',
                'post_url' => '',
                'error'    => "Platform '{$platform}' is not supported by Missinglettr",
            ];
        }

        $postType = $platformConfig['post_type'] ?? 'text_with_link';

        $payload = $this->buildPayload($missinglettrPlatform, $videoData, $contentData, $platformConfig, $postType);

        try {
            $response = $this->http->post(
                self::API_BASE . '/posts',
                $payload,
                $this->getHeaders()
            );

            $body = json_decode($response['body'], true) ?? [];

            if ($response['status'] >= 200 && $response['status'] < 300) {
                $postId = (string) ($body['id'] ?? $body['post_id'] ?? '');
                $postUrl = (string) ($body['url'] ?? $body['post_url'] ?? '');

                Logger::info('publish', "Missinglettr post created for {$platform}", [
                    'post_id'  => $postId,
                    'platform' => $platform,
                ]);

                return [
                    'success'  => true,
                    'post_id'  => $postId,
                    'post_url' => $postUrl,
                    'error'    => '',
                ];
            }

            $error = $body['message'] ?? $body['error'] ?? "HTTP {$response['status']}";
            Logger::error('publish', "Missinglettr publish failed for {$platform}: {$error}");

            return [
                'success'  => false,
                'post_id'  => '',
                'post_url' => '',
                'error'    => $error,
            ];
        } catch (\Throwable $e) {
            Logger::error('publish', "Missinglettr exception for {$platform}: {$e->getMessage()}");

            return [
                'success'  => false,
                'post_id'  => '',
                'post_url' => '',
                'error'    => $e->getMessage(),
            ];
        }
    }

    private function buildPayload(string $missinglettrPlatform, array $videoData, array $contentData, array $platformConfig, string $postType): array
    {
        $caption = $contentData['caption'] ?? $contentData['description'] ?? '';
        $title = $contentData['title'] ?? '';
        $text = $title !== '' ? "{$title}\n\n{$caption}" : $caption;

        $payload = [
            'text'          => $text,
            'platforms'     => [$missinglettrPlatform],
            'schedule_type' => 'immediate',
        ];

        if ($this->workspaceId !== '') {
            $payload['workspace_id'] = $this->workspaceId;
        }

        switch ($postType) {
            case 'native_video':
                $mediaUrl = $videoData['cdn_url'] ?? $videoData['media_url'] ?? '';
                if ($mediaUrl !== '') {
                    $payload['media_url'] = $mediaUrl;
                }
                break;

            case 'link_to_youtube':
                $youtubeUrl = $platformConfig['youtube_url'] ?? $videoData['youtube_url'] ?? '';
                if ($youtubeUrl !== '') {
                    $payload['link'] = $youtubeUrl;
                    $payload['text'] = $text . "\n\n" . $youtubeUrl;
                }
                break;

            case 'text_with_link':
                $link = $platformConfig['link'] ?? $videoData['cdn_url'] ?? '';
                if ($link !== '') {
                    $payload['link'] = $link;
                }
                break;
        }

        return $payload;
    }

    private function getHeaders(): array
    {
        return [
            'X-API-Key'   => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }
}
