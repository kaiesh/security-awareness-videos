<?php

declare(strict_types=1);

namespace SecurityDrama\Reddit;

use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\Logger;

final class ThreadMatcher
{
    private const MODULE = 'ThreadMatcher';

    private Database $db;
    private Config $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
    }

    public function run(): int
    {
        $minScore = (int) $this->config->get('reddit_min_match_score', '40');

        $threads = $this->db->fetchAll(
            "SELECT * FROM reddit_threads WHERE status = 'discovered'"
        );

        if (empty($threads)) {
            Logger::debug(self::MODULE, 'No discovered threads to match');
            return 0;
        }

        $videos = $this->loadVideosWithScripts();
        $matched = 0;

        foreach ($threads as $thread) {
            try {
                $bestMatch = $this->findBestMatch($thread, $videos);

                if ($bestMatch !== null && $bestMatch['score'] >= $minScore) {
                    $this->db->execute(
                        "UPDATE reddit_threads
                         SET matched_video_id = ?, match_score = ?, status = 'evaluating'
                         WHERE id = ?",
                        [$bestMatch['video_id'], $bestMatch['score'], $thread['id']]
                    );

                    Logger::info(self::MODULE, 'Matched thread to video', [
                        'thread_id'  => $thread['id'],
                        'video_id'   => $bestMatch['video_id'],
                        'score'      => $bestMatch['score'],
                        'match_type' => $bestMatch['type'],
                    ]);

                    $matched++;
                } else {
                    $this->db->execute(
                        "UPDATE reddit_threads
                         SET status = 'skipped', skip_reason = 'no matching video'
                         WHERE id = ?",
                        [$thread['id']]
                    );

                    Logger::debug(self::MODULE, 'No match for thread', [
                        'thread_id' => $thread['id'],
                        'best_score' => $bestMatch['score'] ?? 0,
                    ]);
                }
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, 'Error matching thread', [
                    'thread_id' => $thread['id'],
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Logger::info(self::MODULE, "Matching complete: {$matched} threads matched");
        return $matched;
    }

    private function loadVideosWithScripts(): array
    {
        return $this->db->fetchAll(
            "SELECT v.id AS video_id, v.youtube_url,
                    s.title AS script_title, s.narration,
                    fi.title AS feed_title, fi.summary AS feed_summary,
                    fi.external_id
             FROM videos v
             JOIN content_queue cq ON cq.id = v.queue_id
             JOIN scripts s ON s.id = cq.script_id
             JOIN feed_items fi ON fi.id = cq.feed_item_id
             WHERE v.provider_status = 'completed'
               AND v.youtube_url IS NOT NULL"
        );
    }

    /**
     * @return array{video_id: int, score: int, type: string}|null
     */
    private function findBestMatch(array $thread, array $videos): ?array
    {
        $threadText = strtolower(($thread['title'] ?? '') . ' ' . ($thread['body'] ?? ''));
        $bestMatch = null;

        foreach ($videos as $video) {
            $score = 0;
            $matchType = 'none';

            // CVE ID match (score 100)
            $threadCves = $this->extractCves($threadText);
            if (!empty($threadCves)) {
                $videoText = strtolower(
                    ($video['script_title'] ?? '') . ' ' .
                    ($video['narration'] ?? '') . ' ' .
                    ($video['feed_title'] ?? '') . ' ' .
                    ($video['feed_summary'] ?? '') . ' ' .
                    ($video['external_id'] ?? '')
                );
                $videoCves = $this->extractCves($videoText);

                $commonCves = array_intersect($threadCves, $videoCves);
                if (!empty($commonCves)) {
                    $score = 100;
                    $matchType = 'cve';
                }
            }

            // Package/technology match (score 80)
            if ($score < 80) {
                $techScore = $this->scoreTechnologyMatch($threadText, $video);
                if ($techScore > 0) {
                    $score = max($score, 80);
                    $matchType = $score === 80 ? 'technology' : $matchType;
                }
            }

            // Topic overlap (score 50 for 3+ terms, 20 for single keyword)
            if ($score < 50) {
                $topicScore = $this->scoreTopicOverlap($threadText, $video);
                if ($topicScore > $score) {
                    $score = $topicScore;
                    $matchType = $topicScore >= 50 ? 'topic_overlap' : 'keyword';
                }
            }

            if ($score > 0 && ($bestMatch === null || $score > $bestMatch['score'])) {
                $bestMatch = [
                    'video_id' => (int) $video['video_id'],
                    'score'    => $score,
                    'type'     => $matchType,
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * @return string[]
     */
    private function extractCves(string $text): array
    {
        if (preg_match_all('/cve-\d{4}-\d{4,}/', $text, $matches)) {
            return array_unique($matches[0]);
        }
        return [];
    }

    private function scoreTechnologyMatch(string $threadText, array $video): int
    {
        // Extract significant technology/package names from video content
        $videoText = strtolower(
            ($video['script_title'] ?? '') . ' ' .
            ($video['narration'] ?? '') . ' ' .
            ($video['feed_title'] ?? '')
        );

        // Common security-related technologies and packages
        $techPatterns = [
            'openssl', 'log4j', 'apache', 'nginx', 'wordpress', 'npm', 'pip',
            'docker', 'kubernetes', 'aws', 'azure', 'chrome', 'firefox',
            'android', 'ios', 'windows', 'linux', 'macos', 'php', 'python',
            'java', 'node', 'react', 'django', 'laravel', 'spring',
            'postgresql', 'mysql', 'mongodb', 'redis', 'elasticsearch',
            'jenkins', 'gitlab', 'github', 'terraform', 'ansible',
        ];

        foreach ($techPatterns as $tech) {
            if (str_contains($videoText, $tech) && str_contains($threadText, $tech)) {
                return 1;
            }
        }

        return 0;
    }

    private function scoreTopicOverlap(string $threadText, array $video): int
    {
        $videoText = strtolower(
            ($video['script_title'] ?? '') . ' ' .
            ($video['narration'] ?? '') . ' ' .
            ($video['feed_title'] ?? '') . ' ' .
            ($video['feed_summary'] ?? '')
        );

        // Extract significant words (4+ chars, not stopwords)
        $threadWords = $this->extractSignificantWords($threadText);
        $videoWords = $this->extractSignificantWords($videoText);

        $overlap = array_intersect($threadWords, $videoWords);
        $overlapCount = count($overlap);

        if ($overlapCount >= 3) {
            return 50;
        }
        if ($overlapCount >= 1) {
            return 20;
        }

        return 0;
    }

    /**
     * @return string[]
     */
    private function extractSignificantWords(string $text): array
    {
        $stopwords = [
            'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all',
            'can', 'had', 'her', 'was', 'one', 'our', 'out', 'has',
            'have', 'been', 'from', 'they', 'this', 'that', 'with',
            'will', 'each', 'make', 'like', 'just', 'over', 'such',
            'also', 'back', 'into', 'your', 'than', 'them', 'then',
            'some', 'what', 'about', 'which', 'when', 'there', 'their',
            'would', 'could', 'should', 'does', 'these', 'those',
            'being', 'other', 'more', 'very', 'here', 'where',
        ];

        preg_match_all('/[a-z][a-z0-9_-]{3,}/', $text, $matches);
        $words = array_unique($matches[0]);

        return array_values(array_diff($words, $stopwords));
    }
}
