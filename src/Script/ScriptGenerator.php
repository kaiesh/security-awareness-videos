<?php

declare(strict_types=1);

namespace SecurityDrama\Script;

use SecurityDrama\Bootstrap;
use SecurityDrama\Database;
use SecurityDrama\Logger;

final class ScriptGenerator
{
    private const MODULE = 'ScriptGenerator';

    private Database $db;
    private ClaudeClient $claude;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->claude = new ClaudeClient();
    }

    public function run(): int
    {
        $items = $this->db->fetchAll(
            "SELECT cq.*, fi.title, fi.description, fi.severity, fi.external_id,
                    fi.affected_products, fi.url AS source_url
             FROM content_queue cq
             JOIN feed_items fi ON cq.feed_item_id = fi.id
             WHERE cq.status = 'pending_script'
             ORDER BY cq.id ASC"
        );

        $processed = 0;

        foreach ($items as $item) {
            try {
                $this->processItem($item);
                $processed++;
            } catch (\Throwable $e) {
                $this->handleFailure($item, $e);
            }
        }

        Logger::info(self::MODULE, "Script generation complete: {$processed} scripts generated");

        return $processed;
    }

    private function processItem(array $item): void
    {
        $queueId = $item['id'];

        // Update status to generating_script
        $this->db->execute(
            "UPDATE content_queue SET status = 'generating_script' WHERE id = ?",
            [$queueId]
        );

        // Load prompt template
        $contentType = $item['content_type'];
        $promptFile = Bootstrap::basePath() . "/config/prompts/{$contentType}.txt";

        if (!file_exists($promptFile)) {
            throw new \RuntimeException("Prompt template not found: {$promptFile}");
        }

        $template = file_get_contents($promptFile);
        if ($template === false) {
            throw new \RuntimeException("Failed to read prompt template: {$promptFile}");
        }

        // Split template into system prompt and user prompt
        [$systemPrompt, $userPromptTemplate] = $this->parseTemplate($template);

        // Build user prompt with injected values
        $userPrompt = $this->injectValues($userPromptTemplate, $item);

        Logger::info(self::MODULE, 'Generating script via Claude', [
            'queue_id'     => $queueId,
            'content_type' => $contentType,
            'title'        => $item['title'] ?? '',
        ]);

        // Call Claude API
        $response = $this->claude->sendMessage($systemPrompt, $userPrompt);

        // Parse structured JSON from Claude's response
        $scriptData = $this->parseScriptJson($response['text']);

        // visual_direction stores the structured segment plan as JSON so the
        // compositor can read it back. The legacy prose description is kept
        // under `overall_style` for adapters (Seedance) that still consume it.
        $visualDirectionJson = json_encode([
            'segments'      => $scriptData['segments'] ?? [],
            'overall_style' => $scriptData['visual_direction'] ?? null,
        ]);

        // Insert into scripts table
        $this->db->execute(
            "INSERT INTO scripts
             (queue_id, narration_text, hook_line, on_screen_text, visual_direction,
              cta_text, hashtags, title_youtube, title_social, description_youtube,
              raw_response, llm_prompt_tokens, llm_completion_tokens, llm_model, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $queueId,
                $scriptData['narration'] ?? '',
                $scriptData['hook_line'] ?? '',
                json_encode($scriptData['on_screen_text'] ?? []),
                $visualDirectionJson,
                $scriptData['cta'] ?? '',
                json_encode($scriptData['hashtags'] ?? []),
                $scriptData['title_youtube'] ?? '',
                $scriptData['title_social'] ?? '',
                $scriptData['description_youtube'] ?? '',
                $response['text'],
                $response['prompt_tokens'],
                $response['completion_tokens'],
                $response['model'],
            ]
        );

        $scriptId = $this->db->lastInsertId();

        // Update content_queue with script_id and status
        $this->db->execute(
            "UPDATE content_queue SET script_id = ?, status = 'pending_video' WHERE id = ?",
            [$scriptId, $queueId]
        );

        Logger::info(self::MODULE, 'Script generated successfully', [
            'queue_id'  => $queueId,
            'script_id' => $scriptId,
            'model'     => $response['model'],
        ]);
    }

    private function handleFailure(array $item, \Throwable $e): void
    {
        $queueId = $item['id'];
        $retryCount = (int) ($item['retry_count'] ?? 0);

        $this->db->execute(
            "UPDATE content_queue
             SET status = 'failed', retry_count = ?
             WHERE id = ?",
            [$retryCount + 1, $queueId]
        );

        Logger::error(self::MODULE, 'Script generation failed', [
            'queue_id'    => $queueId,
            'retry_count' => $retryCount + 1,
            'error'       => $e->getMessage(),
        ]);
    }

    /**
     * Parse template into system prompt and user prompt sections.
     * Template format uses === SYSTEM === and === USER === delimiters.
     *
     * @return array{0: string, 1: string}
     */
    private function parseTemplate(string $template): array
    {
        $systemPrompt = '';
        $userPrompt = '';

        if (preg_match('/===\s*SYSTEM\s*===\s*\n(.*?)(?=\n===\s*USER\s*===)/s', $template, $sysMatch)) {
            $systemPrompt = trim($sysMatch[1]);
        }

        if (preg_match('/===\s*USER\s*===\s*\n(.*)/s', $template, $userMatch)) {
            $userPrompt = trim($userMatch[1]);
        }

        if ($systemPrompt === '' || $userPrompt === '') {
            throw new \RuntimeException('Prompt template must contain === SYSTEM === and === USER === sections');
        }

        return [$systemPrompt, $userPrompt];
    }

    private function injectValues(string $template, array $item): string
    {
        $replacements = [
            '{{TITLE}}'             => $item['title'] ?? 'N/A',
            '{{DESCRIPTION}}'      => $item['description'] ?? 'N/A',
            '{{SEVERITY}}'         => $item['severity'] ?? 'unknown',
            '{{CVE_ID}}'           => $item['external_id'] ?? 'N/A',
            '{{AFFECTED_PRODUCTS}}' => $item['affected_products'] ?? 'N/A',
            '{{SOURCE_URL}}'       => $item['source_url'] ?? 'N/A',
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );
    }

    /**
     * Extract and parse JSON from Claude's response text.
     * Handles responses where JSON may be wrapped in markdown code blocks.
     */
    private function parseScriptJson(string $text): array
    {
        // Try to extract JSON from markdown code block
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $text, $matches)) {
            $text = $matches[1];
        }

        $data = json_decode(trim($text), true);
        if ($data === null) {
            throw new \RuntimeException(
                'Failed to parse script JSON from Claude response: ' . json_last_error_msg()
            );
        }

        // Validate required fields
        $required = ['narration', 'hook_line', 'on_screen_text', 'visual_direction', 'cta', 'segments'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Script JSON missing required field: {$field}");
            }
        }

        if (!is_array($data['segments']) || count($data['segments']) < 3) {
            throw new \RuntimeException('Script JSON `segments` must be an array with at least 3 entries');
        }

        return $data;
    }
}
