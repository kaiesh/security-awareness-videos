<?php

declare(strict_types=1);

namespace SecurityDrama\Reddit;

use SecurityDrama\Database;
use SecurityDrama\Logger;
use SecurityDrama\Script\ClaudeClient;

final class CommentGenerator
{
    private const MODULE = 'CommentGenerator';

    private Database $db;
    private ClaudeClient $claude;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->claude = new ClaudeClient();
    }

    public function run(): int
    {
        $threads = $this->db->fetchAll(
            "SELECT rt.*, v.youtube_url, s.narration, s.title AS script_title
             FROM reddit_threads rt
             JOIN videos v ON v.id = rt.matched_video_id
             JOIN content_queue cq ON cq.id = v.queue_id
             JOIN scripts s ON s.id = cq.script_id
             WHERE rt.status = 'evaluating'"
        );

        if (empty($threads)) {
            Logger::debug(self::MODULE, 'No evaluating threads to generate comments for');
            return 0;
        }

        $generated = 0;

        foreach ($threads as $thread) {
            try {
                $result = $this->generateComment($thread);

                if ($result['should_post']) {
                    $this->db->execute(
                        "UPDATE reddit_threads
                         SET generated_comment = ?, status = 'approved'
                         WHERE id = ?",
                        [$result['comment'], $thread['id']]
                    );

                    Logger::info(self::MODULE, 'Comment approved', [
                        'thread_id' => $thread['id'],
                        'subreddit' => $thread['subreddit'],
                    ]);

                    $generated++;
                } else {
                    $this->db->execute(
                        "UPDATE reddit_threads
                         SET status = 'skipped', skip_reason = ?
                         WHERE id = ?",
                        [$result['reason'], $thread['id']]
                    );

                    Logger::info(self::MODULE, 'Comment rejected by Claude', [
                        'thread_id' => $thread['id'],
                        'reason'    => $result['reason'],
                    ]);
                }
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, 'Failed to generate comment', [
                    'thread_id' => $thread['id'],
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Logger::info(self::MODULE, "Comment generation complete: {$generated} approved");
        return $generated;
    }

    /**
     * @return array{comment: string, should_post: bool, reason: string}
     */
    private function generateComment(array $thread): array
    {
        $systemPrompt = $this->buildSystemPrompt($thread['subreddit'] ?? '');
        $userPrompt = $this->buildUserPrompt($thread);

        $response = $this->claude->sendMessage($systemPrompt, $userPrompt, 'claude-sonnet-4-20250514', 500);

        $parsed = $this->parseResponse($response['text']);

        return $parsed;
    }

    private function buildSystemPrompt(string $subreddit): string
    {
        $toneGuidance = match (strtolower($subreddit)) {
            'vibecoding'  => 'Use a casual, relatable tone. Light humor is fine. Speak like a fellow developer who gets it.',
            'netsec'      => 'Be technically precise and concise. Reference specific mechanisms, protocols, or attack vectors where relevant.',
            'scams'       => 'Be empathetic and supportive. Focus on helping the person understand the risk and protect themselves.',
            'cybersecurity' => 'Balance technical accuracy with accessibility. Assume the reader has some security knowledge.',
            'hacking'     => 'Be technically grounded. Focus on the mechanics and practical implications.',
            'privacy'     => 'Emphasize practical steps and real-world implications. Be measured, not alarmist.',
            default       => 'Be helpful, knowledgeable, and conversational. Match the tone of the subreddit.',
        };

        return <<<PROMPT
You are a knowledgeable security community member commenting on a Reddit post. You are NOT a marketer, promoter, or content creator.

Your task: Write a genuine, helpful comment that addresses the poster's question or concern. If there is a relevant video resource, weave the link in naturally as a supplementary reference.

Rules:
- Address the poster's specific question or concern FIRST with a substantive answer
- Keep to 2-4 sentences
- If including a video link, frame it as a resource you found helpful — NEVER say "check out my video", "I made a video", "shameless plug", or anything that implies ownership
- Acceptable phrasings: "there's a good breakdown here", "this covers the technical details", "found this helpful when I was looking into this"
- NEVER mention any brand name
- The comment must stand on its own as valuable even without the link

Tone for r/{$subreddit}: {$toneGuidance}

Respond with ONLY a JSON object (no markdown fencing):
{"comment": "your comment text", "should_post": true, "reason": "why this is appropriate"}

Set should_post to false if:
- The post doesn't genuinely need the video as a resource
- The comment would feel forced or spammy
- The thread topic doesn't align well enough with the video
- Including a link would be inappropriate for the conversation
PROMPT;
    }

    private function buildUserPrompt(array $thread): string
    {
        $title = $thread['title'] ?? '';
        $body = $thread['body'] ?? '';
        $subreddit = $thread['subreddit'] ?? '';
        $narration = $thread['narration'] ?? '';
        $scriptTitle = $thread['script_title'] ?? '';
        $youtubeUrl = $thread['youtube_url'] ?? '';

        $bodyExcerpt = mb_strlen($body) > 1500 ? mb_substr($body, 0, 1500) . '...' : $body;
        $narrationExcerpt = mb_strlen($narration) > 1000 ? mb_substr($narration, 0, 1000) . '...' : $narration;

        return <<<PROMPT
Reddit post in r/{$subreddit}:
Title: {$title}
Body: {$bodyExcerpt}

Matched video:
Video title: {$scriptTitle}
Video URL: {$youtubeUrl}
Video script excerpt: {$narrationExcerpt}

Write your comment now.
PROMPT;
    }

    /**
     * @return array{comment: string, should_post: bool, reason: string}
     */
    private function parseResponse(string $text): array
    {
        // Strip markdown code fencing if present
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $data = json_decode(trim($text), true);

        if ($data === null || !isset($data['comment'], $data['should_post'])) {
            Logger::warning(self::MODULE, 'Failed to parse Claude response as JSON', [
                'response' => mb_substr($text, 0, 500),
            ]);

            return [
                'comment'     => '',
                'should_post' => false,
                'reason'      => 'Failed to parse Claude response',
            ];
        }

        return [
            'comment'     => (string) $data['comment'],
            'should_post' => (bool) $data['should_post'],
            'reason'      => (string) ($data['reason'] ?? ''),
        ];
    }
}
