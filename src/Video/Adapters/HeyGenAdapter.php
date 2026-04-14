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

        return $this->submitDirectVideoAgentJob($scriptData);
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

    /**
     * Submit a job to HeyGen's Video Agent endpoint (/v1/video_agent/generate).
     *
     * Unlike submitDirectAvatarJob (which sends narration text straight to a
     * static talking-head render), the Video Agent takes a free-form prompt
     * describing the video you want and plans pacing, b-roll cuts, and shot
     * variety itself. We build the prompt from the narration script plus the
     * per-segment b-roll hints Claude already emits in scripts.visual_direction.
     *
     * HeyGen prompt cap is 10,000 chars. Our typical script runs ~1–2k chars
     * including segment hints, so there's plenty of headroom.
     */
    private function submitDirectVideoAgentJob(array $scriptData): string
    {
        $narration = trim((string) ($scriptData['narration'] ?? ''));
        if ($narration === '') {
            throw new RuntimeException('HeyGen video agent requires non-empty narration');
        }

        $avatarId = $this->resolveAvatarLook();
        if ($avatarId === '') {
            throw new RuntimeException(
                'HeyGen video agent mode requires heygen_avatar_id (or heygen_avatar_group_id) to be configured'
            );
        }

        $prompt = $this->buildVideoAgentPrompt($scriptData);
        $durationSec = $this->estimateDurationSeconds($narration);

        $body = [
            'prompt' => $prompt,
            'config' => [
                'avatar_id'    => $avatarId,
                'duration_sec' => $durationSec,
                'orientation'  => 'portrait',
            ],
        ];

        Logger::info(self::MODULE, 'Submitting HeyGen video agent job', [
            'avatar_id'    => $avatarId,
            'duration_sec' => $durationSec,
            'prompt_chars' => strlen($prompt),
        ]);

        $response = $this->http->post(
            self::API_BASE . '/v1/video_agent/generate',
            $body,
            $this->authHeaders()
        );

        $data = $this->decodeResponse($response);

        $videoId = $data['data']['video_id'] ?? null;
        if ($videoId === null) {
            throw new RuntimeException('HeyGen video agent generate did not return a video_id');
        }

        return (string) $videoId;
    }

    /**
     * Build the free-form prompt we hand to /v1/video_agent/generate.
     * Includes the verbatim narration plus structured b-roll direction.
     */
    private function buildVideoAgentPrompt(array $scriptData): string
    {
        $narration = trim((string) ($scriptData['narration'] ?? ''));
        $title     = trim((string) ($scriptData['title'] ?? ''));
        $rawDirection = (string) ($scriptData['visual_direction'] ?? '');

        $segments = [];
        $overallStyle = '';
        if ($rawDirection !== '') {
            $decoded = json_decode($rawDirection, true);
            if (is_array($decoded)) {
                $segments = is_array($decoded['segments'] ?? null) ? $decoded['segments'] : [];
                $overallStyle = trim((string) ($decoded['overall_style'] ?? ''));
            }
        }

        $lines = [];
        $lines[] = 'Create a short vertical (9:16 portrait) security-awareness explainer video for social feeds (TikTok, Instagram Reels, YouTube Shorts).';
        $lines[] = 'Audience: general consumers and non-technical staff who need to grasp a real cybersecurity risk in under a minute.';
        $lines[] = 'Tone: punchy, slightly dramatic, educational but entertaining — this is the "Security Drama" brand. Avoid corporate stiffness and generic stock-explainer pacing.';
        $lines[] = 'Pacing: quick cuts, energy from the first frame, no silent pads.';
        $lines[] = 'Captions: include burned-in captions — viewers often watch muted.';
        $lines[] = '';

        if ($title !== '') {
            $lines[] = 'Title / topic: ' . $title;
            $lines[] = '';
        }

        $lines[] = 'Narration — the avatar should speak this script verbatim, in order:';
        $lines[] = '"""';
        $lines[] = $narration;
        $lines[] = '"""';
        $lines[] = '';

        if ($overallStyle !== '') {
            $lines[] = 'Overall visual style: ' . $overallStyle;
            $lines[] = '';
        }

        if (!empty($segments)) {
            $lines[] = 'Segment-by-segment visual direction (first and last segments on camera with the narrator; interior segments cut to b-roll that matches the subject of the narration chunk):';
            foreach ($segments as $i => $seg) {
                $mode = (string) ($seg['visual_mode'] ?? 'narrator');
                $dur = (float) ($seg['duration_seconds'] ?? 0);
                $chunk = $this->truncateForPrompt(trim((string) ($seg['narration_chunk'] ?? '')), 140);
                $broll = trim((string) ($seg['broll_query'] ?? ''));

                $idx = $i + 1;
                if ($mode === 'broll' && $broll !== '') {
                    $lines[] = sprintf(
                        '%d. [~%ds] B-ROLL — visual subject: %s — narration: "%s"',
                        $idx,
                        (int) round($dur),
                        $broll,
                        $chunk
                    );
                } else {
                    $lines[] = sprintf(
                        '%d. [~%ds] NARRATOR on camera — narration: "%s"',
                        $idx,
                        (int) round($dur),
                        $chunk
                    );
                }
            }
            $lines[] = '';
        }

        $prompt = implode("\n", $lines);

        if (strlen($prompt) > 9500) {
            $prompt = substr($prompt, 0, 9500);
        }

        return $prompt;
    }

    private function estimateDurationSeconds(string $narration): int
    {
        $words = preg_split('/\s+/', trim($narration)) ?: [];
        $wordCount = count(array_filter($words, static fn(string $w): bool => $w !== ''));
        $seconds = (int) round($wordCount / 2.5);
        return max(5, $seconds);
    }

    private function truncateForPrompt(string $text, int $maxLen): string
    {
        if (strlen($text) <= $maxLen) {
            return $text;
        }
        return substr($text, 0, $maxLen - 3) . '...';
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

            // HeyGen photo-avatar looks use lowercase statuses like
            // "completed", "pending", "failed". Only "completed" is
            // usable with /v2/video/generate — anything else returns
            // "avatar look not found".
            $usableStatuses = ['COMPLETED', 'ACTIVE', 'READY', 'TRAINED'];
            $usable = array_values(array_filter(
                $looks,
                static fn(array $look): bool => in_array(
                    strtoupper((string) ($look['status'] ?? '')),
                    ['COMPLETED', 'ACTIVE', 'READY', 'TRAINED'],
                    true
                )
            ));

            if (empty($usable)) {
                // Log every status we saw so the operator can see
                // whether HeyGen has added a new state we need to
                // whitelist. Unlike the earlier version we do NOT
                // fall back to the full (unfiltered) list — picking
                // a draft/in-training look gets a 404 from generate.
                $seenStatuses = array_values(array_unique(array_map(
                    static fn(array $l): string => (string) ($l['status'] ?? ''),
                    $looks
                )));
                throw new RuntimeException(
                    "Avatar group {$groupId} has no usable looks "
                    . '(accepted: ' . implode(',', $usableStatuses) . '; '
                    . 'seen: ' . implode(',', $seenStatuses) . ')'
                );
            }

            $picked = $usable[random_int(0, count($usable) - 1)];
            $pickedId = (string) ($picked['id'] ?? '');
            if ($pickedId === '') {
                throw new RuntimeException('Picked avatar look has no id');
            }

            Logger::info(self::MODULE, 'Picked avatar look', [
                'group_id'      => $groupId,
                'avatar_id'     => $pickedId,
                'avatar_name'   => $picked['name'] ?? null,
                'avatar_status' => $picked['status'] ?? null,
                'pool_size'     => count($usable),
                'total_looks'   => count($looks),
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

        // Log a compact summary of every look so the operator can see
        // which ones the filter accepts/rejects without hitting HeyGen
        // again.
        $summary = array_map(
            static fn(array $l): array => [
                'id'     => $l['id'] ?? null,
                'name'   => $l['name'] ?? null,
                'status' => $l['status'] ?? null,
            ],
            $list
        );
        Logger::info(self::MODULE, 'Fetched avatar group looks', [
            'group_id'    => $groupId,
            'total_looks' => count($list),
            'looks'       => $summary,
        ]);

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
