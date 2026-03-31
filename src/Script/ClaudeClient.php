<?php

declare(strict_types=1);

namespace SecurityDrama\Script;

use RuntimeException;
use SecurityDrama\HttpClient;
use SecurityDrama\Logger;

final class ClaudeClient
{
    private const MODULE = 'ClaudeClient';
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private HttpClient $http;
    private string $apiKey;

    public function __construct()
    {
        $this->http = new HttpClient();
        $this->apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? '';

        if ($this->apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY environment variable is not set');
        }
    }

    /**
     * Send a message to the Anthropic Messages API.
     *
     * @return array{text: string, prompt_tokens: int, completion_tokens: int, model: string}
     */
    public function sendMessage(
        string $systemPrompt,
        string $userPrompt,
        string $model = 'claude-sonnet-4-20250514',
        int $maxTokens = 2000
    ): array {
        $headers = [
            'x-api-key'         => $this->apiKey,
            'anthropic-version'  => self::API_VERSION,
            'content-type'       => 'application/json',
        ];

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $systemPrompt,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ];

        Logger::debug(self::MODULE, 'Sending request to Claude API', [
            'model'      => $model,
            'max_tokens' => $maxTokens,
        ]);

        $response = $this->http->post(self::API_URL, $payload, $headers, [
            'timeout' => 120,
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $body = $response['body'] ?? '';
            Logger::error(self::MODULE, 'Claude API returned error', [
                'status' => $response['status'],
                'body'   => substr($body, 0, 500),
            ]);
            throw new RuntimeException(
                "Claude API error (HTTP {$response['status']}): " . substr($body, 0, 200)
            );
        }

        $data = json_decode($response['body'], true);
        if ($data === null) {
            throw new RuntimeException('Failed to parse Claude API response as JSON');
        }

        $text = $data['content'][0]['text'] ?? null;
        if ($text === null) {
            throw new RuntimeException('Claude API response missing content text');
        }

        $usage = $data['usage'] ?? [];

        Logger::debug(self::MODULE, 'Claude API response received', [
            'model'             => $data['model'] ?? $model,
            'prompt_tokens'     => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
        ]);

        return [
            'text'              => $text,
            'prompt_tokens'     => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
            'model'             => $data['model'] ?? $model,
        ];
    }
}
