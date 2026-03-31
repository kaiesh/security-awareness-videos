<?php

declare(strict_types=1);

namespace SecurityDrama\Reddit;

use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\Logger;

final class EngagementOrchestrator
{
    private const MODULE = 'RedditEngagement';

    private Database $db;
    private Config $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
    }

    public function run(): void
    {
        $enabled = (int) $this->config->get('reddit_engagement_enabled', '0');
        if ($enabled !== 1) {
            Logger::debug(self::MODULE, 'Reddit engagement is disabled');
            return;
        }

        $maxDaily = (int) $this->config->get('reddit_max_comments_daily', '3');
        $postedToday = $this->getCommentsPostedToday();
        $remaining = $maxDaily - $postedToday;

        Logger::info(self::MODULE, 'Starting Reddit engagement run', [
            'max_daily'    => $maxDaily,
            'posted_today' => $postedToday,
            'remaining'    => $remaining,
        ]);

        if ($remaining <= 0) {
            Logger::info(self::MODULE, 'Daily comment budget exhausted');
            return;
        }

        // Run pipeline stages in sequence
        $crawled = 0;
        $matched = 0;
        $generated = 0;
        $commented = 0;

        try {
            $crawler = new RedditCrawler();
            $crawled = $crawler->run();
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Crawler failed', ['error' => $e->getMessage()]);
        }

        try {
            $matcher = new ThreadMatcher();
            $matched = $matcher->run();
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Matcher failed', ['error' => $e->getMessage()]);
        }

        try {
            $generator = new CommentGenerator();
            $generated = $generator->run();
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Comment generator failed', ['error' => $e->getMessage()]);
        }

        try {
            $commenter = new RedditCommenter();
            $commented = $commenter->run($remaining);
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Commenter failed', ['error' => $e->getMessage()]);
        }

        Logger::info(self::MODULE, 'Reddit engagement run complete', [
            'threads_discovered' => $crawled,
            'threads_matched'    => $matched,
            'comments_generated' => $generated,
            'comments_posted'    => $commented,
        ]);
    }

    private function getCommentsPostedToday(): int
    {
        $today = date('Y-m-d');
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM reddit_threads
             WHERE status = 'commented' AND DATE(commented_at) = ?",
            [$today]
        );

        return (int) ($row['cnt'] ?? 0);
    }
}
