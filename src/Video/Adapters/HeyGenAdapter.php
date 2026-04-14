<?php

declare(strict_types=1);

namespace SecurityDrama\Video\Adapters;

use RuntimeException;
use SecurityDrama\Config;
use SecurityDrama\HttpClient;
use SecurityDrama\Logger;
use SecurityDrama\Video\VideoGeneratorInterface;

final class HeyGenAdapter implements VideoGeneratorInterface
{
    private const API_BASE = 'https://api.heygen.com';
    private const MODULE   = 'heygen';

    /** @var array<string,array<int,array<string,mixed>>> Cache of group_id => looks */
    private static array $lookCache = [];

    private HttpClient $http;
    private Config $config;
    private string $apiKey;

    public function __construct()
    {
        $this->http = new HttpClient();
        $this->config = Config::getInstance();

        $this->apiKey = $this->config->get('HEYGEN_API_KEY', '');
        if ($this->apiKey === '') {
            throw new RuntimeException('HEYGEN_API_KEY is not configured');
        }
    }

    public function submitJob(array $scriptData, array $options): string
    {
        $templateId = $this->config->get('heygen_template_id');

        if ($templateId !== null && $templateId !== '') {
            return $this->submitTemplateJob($templateId, $scriptData);
        }

        return $this->submitDirectAvatarJob($scriptData);
    }

    public function checkStatus(string $jobId): array
    {
        $response = $this->http->get(
            self::API_BASE . '/v1/video_status.get?video_id=' . urlencode($jobId),
            $this->authHeaders()
        );

        $data = $this->decodeResponse($response);

        $status = $data['data']['status'] ?? 'unknown';
        $result = [
            'status'    => $status,
            'video_url' => null,
            'error'     => null,
        ];

        if ($status === 'completed') {
            $result['video_url'] = $data['data']['video_url'] ?? null;
        }

        if ($status === 'failed') {
            $result['error'] = $data['data']['error'] ?? 'Unknown HeyGen error';
        }

        return $result;
    }

    public function downloadVideo(string $videoUrl, string $localPath): bool
    {
        return $this->http->downloadToFile($videoUrl, $localPath);
    }

    public function getProviderName(): string
    {
        return 'heygen';
    }

    private function submitTemplateJob(string $templateId, array $scriptData): string
    {
        $title = $scriptData['title'] ?? 'Security Awareness Video';
        $narration = $scriptData['narration'] ?? '';

        $body = [
            'test'    => false,
            'caption' => true,
            'title'   => $title,
            'variables' => [
                'script' => [
                    'name'       => 'script',
                    'type'       => 'text',
                    'properties' => [
                        'content' => $narration,
                    ],
                ],
            ],
        ];

        $response = $this->http->post(
            self::API_BASE . '/v2/template/' . urlencode($templateId) . '/generate',
            $body,
            $this->authHeaders()
        );

        $data = $this->decodeResponse($response);

        $videoId = $data['data']['video_id'] ?? null;
        if ($videoId === null) {
            throw new RuntimeException('HeyGen template generate did not return a video_id');
        }

        return $videoId;
    }

    private function submitDirectAvatarJob(array $scriptData): string
    {
        $voiceId = $this->config->get('heygen_voice_id', '');
        $narration = $scriptData['narration'] ?? '';
        $title = $scriptData['title'] ?? 'Security Awareness Video';

        $avatarId = $this->resolveAvatarLook();

        if ($avatarId === '' || $voiceId === '') {
            throw new RuntimeException(
                'HeyGen direct avatar mode requires heygen_avatar_id (or heygen_avatar_group_id) and heygen_voice_id to be configured'
            );
        }

        $body = [
            'video_inputs' => [
                [
                    'character' => [
                        'type'      => 'avatar',
                        'avatar_id' => $avatarId,
                    ],
                    'voice' => [
                        'type'     => 'text',
                        'voice_id' => $voiceId,
                        'input_text' => $narration,
                    ],
                ],
            ],
            'title'     => $title,
            'dimension' => [
                'width'  => 1080,
                'height' => 1920,
            ],
            'aspect_ratio' => '9:16',
        ];

        $response = $this->http->post(
            self::API_BASE . '/v2/video/generate',
            $body,
            $this->authHeaders()
        );

        $data = $this->decodeResponse($response);

        $videoId = $data['data']['video_id'] ?? null;
        if ($videoId === null) {
            throw new RuntimeException('HeyGen video generate did not return a video_id');
        }

        return $videoId;
    }

    private function resolveAvatarLook(): string
    {
        $explicitId = (string) $this->config->get('heygen_avatar_id', '');
        if ($explicitId !== '') {
            Logger::debug(self::MODULE, 'Using pinned heygen_avatar_id (rotation skipped)', [
                'avatar_id' => $explicitId,
            ]);
            return $explicitId;
        }

        $groupId = (string) $this->config->get('heygen_avatar_group_id', '');
        if ($groupId === '') {
            return '';
        }

        try {
            $looks = $this->fetchGroupLooks($groupId);
            if (empty($looks)) {
                throw new RuntimeException("Avatar group {$groupId} returned no looks");
            }

            $active = array_values(array_filter(
                $looks,
                static fn(array $look): bool => strtoupper((string) ($look['status'] ?? '')) === 'ACTIVE'
            ));
            $pool = !empty($active) ? $active : $looks;

            $picked = $pool[random_int(0, count($pool) - 1)];
            $pickedId = (string) ($picked['id'] ?? '');
            if ($pickedId === '') {
                throw new RuntimeException('Picked avatar look has no id');
            }

            Logger::info(self::MODULE, 'Picked avatar look', [
                'group_id'    => $groupId,
                'avatar_id'   => $pickedId,
                'avatar_name' => $picked['name'] ?? null,
                'pool_size'   => count($pool),
            ]);

            return $pickedId;
        } catch (\Throwable $e) {
            Logger::warning(self::MODULE, 'Avatar look rotation failed', [
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchGroupLooks(string $groupId): array
    {
        if (isset(self::$lookCache[$groupId])) {
            return self::$lookCache[$groupId];
        }

        $response = $this->http->get(
            self::API_BASE . '/v2/avatar_group/' . urlencode($groupId) . '/avatars',
            $this->authHeaders()
        );

        $data = $this->decodeResponse($response);

        $list = $data['data']['avatar_list'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        self::$lookCache[$groupId] = $list;
        return $list;
    }

    private function authHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'Accept'    => 'application/json',
        ];
    }

    private function decodeResponse(array $response): array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(
                "HeyGen API returned HTTP {$response['status']}: {$response['body']}"
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('HeyGen API returned invalid JSON');
        }

        return $data;
    }
}
