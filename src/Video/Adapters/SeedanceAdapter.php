<?php

declare(strict_types=1);

namespace SecurityDrama\Video\Adapters;

use RuntimeException;
use SecurityDrama\Config;
use SecurityDrama\HttpClient;
use SecurityDrama\Video\VideoGeneratorInterface;

final class SeedanceAdapter implements VideoGeneratorInterface
{
    private const QUEUE_BASE = 'https://queue.fal.run/fal-ai/seedance-2.0/text-to-video';

    private HttpClient $http;
    private Config $config;
    private string $apiKey;

    public function __construct()
    {
        $this->http = new HttpClient();
        $this->config = Config::getInstance();

        $this->apiKey = $this->config->get('FAL_API_KEY', '');
        if ($this->apiKey === '') {
            throw new RuntimeException('FAL_API_KEY is not configured');
        }
    }

    public function submitJob(array $scriptData, array $options): string
    {
        $narration = $scriptData['narration'] ?? '';
        $visualDirection = $scriptData['visual_direction'] ?? '';
        $title = $scriptData['title'] ?? 'Security Awareness Video';

        // Seedance needs a descriptive visual prompt, not just narration text.
        // Combine visual_direction (scene descriptions) with narration context.
        $prompt = $this->buildPrompt($title, $narration, $visualDirection);

        $resolution = $this->config->get('seedance_resolution', '720p');
        $duration = $this->config->get('seedance_duration', '10');
        $aspectRatio = $this->config->get('video_aspect_ratio', '9:16');

        $body = [
            'prompt'       => $prompt,
            'duration'     => $duration,
            'resolution'   => $resolution,
            'aspect_ratio' => $aspectRatio,
        ];

        $response = $this->http->post(
            self::QUEUE_BASE,
            $body,
            $this->authHeaders()
        );

        $data = $this->decodeResponse($response);

        $requestId = $data['request_id'] ?? null;
        if ($requestId === null) {
            throw new RuntimeException('Seedance API did not return a request_id');
        }

        return $requestId;
    }

    public function checkStatus(string $jobId): array
    {
        $response = $this->http->get(
            self::QUEUE_BASE . '/requests/' . urlencode($jobId) . '/status',
            $this->authHeaders()
        );

        $data = $this->decodeResponse($response);

        $falStatus = $data['status'] ?? 'UNKNOWN';
        $status = $this->mapStatus($falStatus);

        $result = [
            'status'    => $status,
            'video_url' => null,
            'error'     => null,
        ];

        if ($status === 'completed') {
            // Fetch the full result to get the video URL
            $resultResponse = $this->http->get(
                self::QUEUE_BASE . '/requests/' . urlencode($jobId),
                $this->authHeaders()
            );
            $resultData = $this->decodeResponse($resultResponse);
            $result['video_url'] = $resultData['video']['url']
                ?? $resultData['output']['video']['url']
                ?? null;
        }

        if ($status === 'failed') {
            $result['error'] = $data['error'] ?? 'Unknown Seedance error';
        }

        return $result;
    }

    public function downloadVideo(string $videoUrl, string $localPath): bool
    {
        return $this->http->downloadToFile($videoUrl, $localPath);
    }

    public function getProviderName(): string
    {
        return 'seedance';
    }

    private function buildPrompt(string $title, string $narration, string $visualDirection): string
    {
        $parts = [];

        if ($visualDirection !== '') {
            $parts[] = $visualDirection;
        }

        // Add context from the narration so the video is thematically relevant,
        // but frame it as visual direction rather than spoken text.
        if ($narration !== '') {
            $parts[] = "The video should visually convey the following message: " . $narration;
        }

        if (empty($parts)) {
            $parts[] = "A dramatic cybersecurity awareness video about: " . $title;
        }

        return implode("\n\n", $parts);
    }

    private function mapStatus(string $falStatus): string
    {
        return match (strtoupper($falStatus)) {
            'IN_QUEUE'    => 'pending',
            'IN_PROGRESS' => 'processing',
            'COMPLETED'   => 'completed',
            'FAILED'      => 'failed',
            default       => 'pending',
        };
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ];
    }

    private function decodeResponse(array $response): array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(
                "Seedance/fal.ai API returned HTTP {$response['status']}: {$response['body']}"
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('Seedance/fal.ai API returned invalid JSON');
        }

        return $data;
    }
}
