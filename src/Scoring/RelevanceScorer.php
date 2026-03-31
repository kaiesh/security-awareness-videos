<?php

declare(strict_types=1);

namespace SecurityDrama\Scoring;

use SecurityDrama\Database;
use SecurityDrama\Logger;

final class RelevanceScorer
{
    private const MODULE = 'RelevanceScorer';

    private const SEVERITY_SCORES = [
        'critical' => 30,
        'high'     => 22,
        'medium'   => 12,
        'low'      => 5,
        'unknown'  => 3,
    ];

    private const VIBE_CODER_ECOSYSTEMS = [
        'npm', 'pypi', 'next.js', 'react', 'supabase', 'vercel', 'node.js',
        'go', 'rust', 'php composer', 'express', 'django', 'flask', 'fastapi',
        'vue', 'angular', 'svelte', 'deno', 'bun',
    ];

    private const POLLING_TIER_MAP = [
        360  => 1,
        720  => 2,
        1440 => 3,
    ];

    private const TIER_SCORES = [
        1 => 15,
        2 => 10,
        3 => 5,
    ];

    private Database $db;

    /** @var array<int, int> Cache of feed_source_id => tier */
    private array $sourceTierCache = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function run(): int
    {
        $items = $this->db->fetchAll(
            'SELECT fi.*, fs.polling_interval_minutes, fs.category AS source_category
             FROM feed_items fi
             JOIN feed_sources fs ON fi.feed_source_id = fs.id
             WHERE fi.is_processed = 0
             ORDER BY fi.id ASC'
        );

        $count = 0;

        foreach ($items as $item) {
            try {
                $score = $this->scoreItem($item);
                $tags = $this->buildAudienceTags($item);

                $this->db->execute(
                    'UPDATE feed_items
                     SET relevance_score = ?, audience_tags = ?, is_processed = 1
                     WHERE id = ?',
                    [$score, json_encode($tags), $item['id']]
                );

                $count++;

                Logger::debug(self::MODULE, 'Scored item', [
                    'feed_item_id'   => $item['id'],
                    'title'          => $item['title'] ?? '',
                    'relevance_score' => $score,
                    'audience_tags'  => $tags,
                ]);
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, 'Failed to score item', [
                    'feed_item_id' => $item['id'],
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        Logger::info(self::MODULE, "Scoring complete: {$count} items scored");

        return $count;
    }

    private function scoreItem(array $item): int
    {
        $score = 0;

        $score += $this->scoreSeverity($item);
        $score += $this->scoreVibeCoderRelevance($item);
        $score += $this->scoreRecency($item);
        $score += $this->scoreSourceAuthority($item);
        $score += $this->scoreExploitability($item);

        return min(100, max(0, $score));
    }

    private function scoreSeverity(array $item): int
    {
        $severity = strtolower($item['severity'] ?? 'unknown');
        return self::SEVERITY_SCORES[$severity] ?? self::SEVERITY_SCORES['unknown'];
    }

    private function scoreVibeCoderRelevance(array $item): int
    {
        $affected = strtolower($item['affected_products'] ?? '');
        $title = strtolower($item['title'] ?? '');
        $description = strtolower($item['description'] ?? '');
        $searchable = $affected . ' ' . $title . ' ' . $description;

        foreach (self::VIBE_CODER_ECOSYSTEMS as $ecosystem) {
            if (str_contains($searchable, $ecosystem)) {
                // Full match in affected_products field is a direct hit
                if (str_contains($affected, $ecosystem)) {
                    return 25;
                }
                // Mention in title/description is a partial match
                return 12;
            }
        }

        return 0;
    }

    private function scoreRecency(array $item): int
    {
        $published = $item['published_at'] ?? $item['created_at'] ?? null;
        if ($published === null) {
            return 0;
        }

        $publishedTime = strtotime($published);
        if ($publishedTime === false) {
            return 0;
        }

        $hoursAgo = (time() - $publishedTime) / 3600;

        if ($hoursAgo < 6) {
            return 15;
        }
        if ($hoursAgo < 24) {
            return 10;
        }
        if ($hoursAgo < 48) {
            return 5;
        }

        return 0;
    }

    private function scoreSourceAuthority(array $item): int
    {
        $interval = (int) ($item['polling_interval_minutes'] ?? 1440);

        $tier = self::POLLING_TIER_MAP[$interval] ?? 3;

        return self::TIER_SCORES[$tier] ?? 5;
    }

    private function scoreExploitability(array $item): int
    {
        $title = strtolower($item['title'] ?? '');
        $description = strtolower($item['description'] ?? '');
        $searchable = $title . ' ' . $description;

        // Check CISA KEV
        if (str_contains($searchable, 'cisa kev')
            || str_contains($searchable, 'known exploited vulnerabilit')) {
            return 15;
        }

        // Check exploit-db reference
        if (str_contains($searchable, 'exploit-db')
            || str_contains($searchable, 'exploitdb')) {
            return 10;
        }

        // Check "actively exploited" language
        if (str_contains($searchable, 'actively exploited')
            || str_contains($searchable, 'exploitation in the wild')
            || str_contains($searchable, 'exploited in the wild')) {
            return 8;
        }

        return 0;
    }

    private function buildAudienceTags(array $item): array
    {
        $tags = [];

        $affected = strtolower($item['affected_products'] ?? '');
        $title = strtolower($item['title'] ?? '');
        $description = strtolower($item['description'] ?? '');
        $searchable = $affected . ' ' . $title . ' ' . $description;

        foreach (self::VIBE_CODER_ECOSYSTEMS as $ecosystem) {
            if (str_contains($searchable, $ecosystem)) {
                $tags[] = 'vibe_coder';
                break;
            }
        }

        $severity = strtolower($item['severity'] ?? 'unknown');
        if (in_array($severity, ['critical', 'high'], true)) {
            $tags[] = 'high_severity';
        }

        $category = strtolower($item['source_category'] ?? '');
        if ($category !== '') {
            $tags[] = $category;
        }

        if (str_contains($searchable, 'actively exploited')
            || str_contains($searchable, 'exploitation in the wild')) {
            $tags[] = 'actively_exploited';
        }

        // General audience tag for broad-interest items
        if (str_contains($searchable, 'phishing')
            || str_contains($searchable, 'ransomware')
            || str_contains($searchable, 'data breach')
            || str_contains($searchable, 'password')) {
            $tags[] = 'general_audience';
        }

        return array_unique($tags);
    }
}
