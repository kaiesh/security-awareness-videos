<?php

declare(strict_types=1);

namespace SecurityDrama\Selection;

use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\Logger;

final class ContentSelector
{
    private const MODULE = 'ContentSelector';

    private Database $db;
    private Config $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
    }

    public function run(): int
    {
        $dailyTarget = (int) $this->config->get('daily_video_target', '2');
        $minScore = (int) $this->config->get('min_relevance_score', '40');
        $today = date('Y-m-d');

        // Count items already in content_queue for today (excluding failed)
        $existing = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM content_queue
             WHERE DATE(created_at) = ? AND status != 'failed'",
            [$today]
        );
        $existingCount = (int) ($existing['cnt'] ?? 0);

        $needed = $dailyTarget - $existingCount;
        if ($needed <= 0) {
            Logger::info(self::MODULE, 'Daily target already met', [
                'target'   => $dailyTarget,
                'existing' => $existingCount,
            ]);
            return 0;
        }

        // Find top N unqueued feed_items by relevance_score
        $candidates = $this->db->fetchAll(
            "SELECT fi.*, fs.category AS source_category
             FROM feed_items fi
             JOIN feed_sources fs ON fi.source_id = fs.id
             WHERE fi.is_processed = 1
               AND fi.relevance_score >= ?
               AND fi.id NOT IN (SELECT feed_item_id FROM content_queue)
             ORDER BY fi.relevance_score DESC
             LIMIT ?",
            [$minScore, $needed]
        );

        $queued = 0;

        foreach ($candidates as $item) {
            try {
                $contentType = $this->determineContentType($item);
                $targetAudience = $this->determineTargetAudience($item);

                $this->db->execute(
                    "INSERT INTO content_queue
                     (feed_item_id, content_type, target_audience, status, created_at)
                     VALUES (?, ?, ?, 'pending_script', NOW())",
                    [$item['id'], $contentType, $targetAudience]
                );

                $queued++;

                Logger::info(self::MODULE, 'Queued item for content', [
                    'feed_item_id'   => $item['id'],
                    'content_type'   => $contentType,
                    'target_audience' => $targetAudience,
                    'relevance_score' => $item['relevance_score'],
                ]);
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, 'Failed to queue item', [
                    'feed_item_id' => $item['id'],
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        Logger::info(self::MODULE, "Selection complete: {$queued} items queued", [
            'target' => $dailyTarget,
            'needed' => $needed,
            'queued' => $queued,
        ]);

        return $queued;
    }

    private function determineContentType(array $item): string
    {
        $externalId = $item['external_id'] ?? '';
        if (str_starts_with($externalId, 'CVE-')) {
            return 'cve_alert';
        }

        $category = strtolower($item['source_category'] ?? '');
        if ($category === 'breach') {
            return 'breach_story';
        }
        if ($category === 'scam') {
            return 'scam_drama';
        }

        $audienceTags = json_decode($item['audience_tags'] ?? '[]', true) ?: [];
        $severity = strtolower($item['severity'] ?? 'unknown');
        if (in_array('vibe_coder', $audienceTags, true)
            && in_array($severity, ['high', 'critical'], true)) {
            return 'vibe_roast';
        }

        return 'security_101';
    }

    private function determineTargetAudience(array $item): string
    {
        // Must return a value from content_queue.target_audience enum:
        // vibe_coder, smb, general.
        $audienceTags = json_decode($item['audience_tags'] ?? '[]', true) ?: [];

        if (in_array('vibe_coder', $audienceTags, true)) {
            return 'vibe_coder';
        }

        if (in_array('smb', $audienceTags, true)) {
            return 'smb';
        }

        return 'general';
    }
}
