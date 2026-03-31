<?php

declare(strict_types=1);

namespace SecurityDrama\Reddit;

use RuntimeException;
use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\HttpClient;
use SecurityDrama\Logger;

final class RedditCommenter
{
    private const MODULE = 'RedditCommenter';
    private const API_BASE = 'https://oauth.reddit.com';
    private const TOKEN_URL = 'https://www.reddit.com/api/v1/access_token';

    /** @var int Maximum comments for the same video URL within 7 days */
    private const MAX_VIDEO_LINKS_PER_WEEK = 2;

    /** @var int Skip threads with more than this many comments */
    private const MAX_THREAD_COMMENTS = 200;

    /** @var int Minimum comment karma required to post */
    private const MIN_COMMENT_KARMA = 100;

    private Database $db;
    private Config $config;
    private HttpClient $http;

    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
        $this->http = new HttpClient();
    }

    public function run(int $remainingBudget): int
    {
        if ($remainingBudget <= 0) {
            Logger::debug(self::MODULE, 'No remaining budget for comments');
            return 0;
        }

        // Apply gradual ramp-up limit
        $rampUpLimit = $this->getRampUpLimit();
        $effectiveBudget = min($remainingBudget, $rampUpLimit);

        if ($effectiveBudget <= 0) {
            Logger::info(self::MODULE, 'Ramp-up limit reached for today');
            return 0;
        }

        // Check account karma before proceeding
        if (!$this->checkAccountKarma()) {
            return 0;
        }

        $threads = $this->db->fetchAll(
            "SELECT rt.*, v.youtube_url
             FROM reddit_threads rt
             JOIN videos v ON v.id = rt.matched_video_id
             WHERE rt.status = 'approved'
             ORDER BY rt.match_score DESC
             LIMIT ?",
            [$effectiveBudget]
        );

        if (empty($threads)) {
            Logger::debug(self::MODULE, 'No approved threads to comment on');
            return 0;
        }

        $commented = 0;
        $today = date('Y-m-d');
        $commentInterval = (int) $this->config->get('reddit_comment_interval_minutes', '30');

        foreach ($threads as $thread) {
            try {
                // Max 1 comment per subreddit per day
                $subCommentToday = $this->db->fetchOne(
                    "SELECT COUNT(*) AS cnt FROM reddit_threads
                     WHERE subreddit = ? AND status = 'commented' AND DATE(commented_at) = ?",
                    [$thread['subreddit'], $today]
                );
                if ((int) ($subCommentToday['cnt'] ?? 0) >= 1) {
                    Logger::debug(self::MODULE, 'Already commented in subreddit today', [
                        'subreddit' => $thread['subreddit'],
                    ]);
                    continue;
                }

                // Minimum gap between comments
                $lastComment = $this->db->fetchOne(
                    "SELECT commented_at FROM reddit_threads
                     WHERE status = 'commented'
                     ORDER BY commented_at DESC LIMIT 1"
                );
                if ($lastComment !== null && $lastComment['commented_at'] !== null) {
                    $lastTime = strtotime($lastComment['commented_at']);
                    $minNextTime = $lastTime + ($commentInterval * 60);
                    if (time() < $minNextTime) {
                        Logger::debug(self::MODULE, 'Comment interval not elapsed', [
                            'minutes_remaining' => (int) ceil(($minNextTime - time()) / 60),
                        ]);
                        break; // No point checking further threads this run
                    }
                }

                // No duplicate video linking: same video URL max 2 in 7 days
                $youtubeUrl = $thread['youtube_url'] ?? '';
                if ($youtubeUrl !== '') {
                    $recentVideoLinks = $this->db->fetchOne(
                        "SELECT COUNT(*) AS cnt FROM reddit_threads
                         WHERE status = 'commented'
                           AND matched_video_id = ?
                           AND commented_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                        [$thread['matched_video_id']]
                    );
                    if ((int) ($recentVideoLinks['cnt'] ?? 0) >= self::MAX_VIDEO_LINKS_PER_WEEK) {
                        Logger::debug(self::MODULE, 'Video link limit reached for week', [
                            'video_id' => $thread['matched_video_id'],
                        ]);
                        $this->db->execute(
                            "UPDATE reddit_threads SET status = 'skipped', skip_reason = 'video link limit reached' WHERE id = ?",
                            [$thread['id']]
                        );
                        continue;
                    }
                }

                // Verify thread is still active
                if (!$this->verifyThreadActive($thread)) {
                    $this->db->execute(
                        "UPDATE reddit_threads SET status = 'skipped', skip_reason = 'thread inactive or locked' WHERE id = ?",
                        [$thread['id']]
                    );
                    continue;
                }

                // Post the comment
                $commentId = $this->postComment($thread['reddit_thread_id'], $thread['generated_comment']);

                $this->db->execute(
                    "UPDATE reddit_threads
                     SET reddit_comment_id = ?, status = 'commented', commented_at = NOW()
                     WHERE id = ?",
                    [$commentId, $thread['id']]
                );

                Logger::info(self::MODULE, 'Comment posted', [
                    'thread_id'  => $thread['id'],
                    'subreddit'  => $thread['subreddit'],
                    'comment_id' => $commentId,
                ]);

                $commented++;
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, 'Failed to post comment', [
                    'thread_id' => $thread['id'],
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Logger::info(self::MODULE, "Commenting complete: {$commented} comments posted");
        return $commented;
    }

    private function checkAccountKarma(): bool
    {
        try {
            $this->ensureAccessToken();

            $response = $this->http->get(self::API_BASE . '/api/v1/me', [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'User-Agent'    => $this->getUserAgent(),
            ]);

            if ($response['status'] < 200 || $response['status'] >= 300) {
                Logger::error(self::MODULE, 'Failed to fetch account info', [
                    'status' => $response['status'],
                ]);
                return false;
            }

            $data = json_decode($response['body'], true);
            $commentKarma = (int) ($data['comment_karma'] ?? 0);

            if ($commentKarma < self::MIN_COMMENT_KARMA) {
                Logger::warning(self::MODULE, 'Account comment karma too low, skipping all comments', [
                    'comment_karma' => $commentKarma,
                    'minimum'       => self::MIN_COMMENT_KARMA,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Failed to check account karma', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function verifyThreadActive(array $thread): bool
    {
        try {
            $this->ensureAccessToken();

            $redditId = $thread['reddit_thread_id'];
            // Strip t3_ prefix if needed for info endpoint
            $response = $this->http->get(
                self::API_BASE . '/api/info?id=' . urlencode($redditId),
                [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'User-Agent'    => $this->getUserAgent(),
                ]
            );

            if ($response['status'] < 200 || $response['status'] >= 300) {
                return false;
            }

            $data = json_decode($response['body'], true);
            $children = $data['data']['children'] ?? [];

            if (empty($children)) {
                return false;
            }

            $post = $children[0]['data'] ?? [];

            // Check if locked, deleted, or removed
            if (!empty($post['locked'])) {
                return false;
            }
            if (($post['author'] ?? '[deleted]') === '[deleted]') {
                return false;
            }
            if (!empty($post['removed_by_category'])) {
                return false;
            }

            // Check comment count
            $numComments = (int) ($post['num_comments'] ?? 0);
            if ($numComments > self::MAX_THREAD_COMMENTS) {
                Logger::debug(self::MODULE, 'Thread has too many comments', [
                    'thread_id'    => $thread['id'],
                    'num_comments' => $numComments,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Failed to verify thread', [
                'thread_id' => $thread['id'],
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function postComment(string $thingId, string $text): string
    {
        $this->ensureAccessToken();

        $response = $this->http->post(
            self::API_BASE . '/api/comment',
            http_build_query([
                'thing_id' => $thingId,
                'text'     => $text,
            ]),
            [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'User-Agent'    => $this->getUserAgent(),
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(
                "Reddit comment API error (HTTP {$response['status']}): " . mb_substr($response['body'], 0, 200)
            );
        }

        $data = json_decode($response['body'], true);

        // Reddit returns the new comment data nested in json.data.things
        $commentData = $data['json']['data']['things'][0]['data'] ?? [];
        $commentId = $commentData['name'] ?? $commentData['id'] ?? '';

        if ($commentId === '') {
            throw new RuntimeException('Reddit API did not return a comment ID');
        }

        return $commentId;
    }

    private function getRampUpLimit(): int
    {
        $enabledDate = $this->config->get('reddit_engagement_enabled_date');
        if ($enabledDate === null) {
            // If no date tracked, use configured max
            return (int) $this->config->get('reddit_max_comments_daily', '3');
        }

        $daysActive = (int) ((time() - strtotime((string) $enabledDate)) / 86400);
        $configuredMax = (int) $this->config->get('reddit_max_comments_daily', '3');

        if ($daysActive < 7) {
            return min(1, $configuredMax); // Week 1: max 1/day
        }
        if ($daysActive < 14) {
            return min(2, $configuredMax); // Week 2: max 2/day
        }

        return $configuredMax; // Week 3+: configured max
    }

    private function ensureAccessToken(): void
    {
        if ($this->accessToken !== null && $this->tokenExpiresAt !== null && time() < $this->tokenExpiresAt) {
            return;
        }

        $clientId = $this->config->get('REDDIT_CLIENT_ID', '');
        $clientSecret = $this->config->get('REDDIT_CLIENT_SECRET', '');
        $username = $this->config->get('REDDIT_USERNAME', '');
        $password = $this->config->get('REDDIT_PASSWORD', '');

        if ($clientId === '' || $clientSecret === '' || $username === '' || $password === '') {
            throw new RuntimeException('Reddit OAuth credentials not configured');
        }

        $response = $this->http->post(
            self::TOKEN_URL,
            http_build_query([
                'grant_type' => 'password',
                'username'   => $username,
                'password'   => $password,
            ]),
            [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'User-Agent'    => $this->getUserAgent(),
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(
                "Reddit OAuth failed (HTTP {$response['status']}): " . mb_substr($response['body'], 0, 200)
            );
        }

        $data = json_decode($response['body'], true);
        if ($data === null || !isset($data['access_token'])) {
            throw new RuntimeException('Reddit OAuth response missing access_token');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600) - 60;
    }

    private function getUserAgent(): string
    {
        return 'SecurityDrama/1.0 (by /u/' . ($this->config->get('REDDIT_USERNAME', 'securitydrama')) . ')';
    }
}
