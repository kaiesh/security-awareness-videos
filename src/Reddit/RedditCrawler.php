<?php

declare(strict_types=1);

namespace SecurityDrama\Reddit;

use RuntimeException;
use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\HttpClient;
use SecurityDrama\Logger;

final class RedditCrawler
{
    private const MODULE = 'RedditCrawler';
    private const TOKEN_URL = 'https://www.reddit.com/api/v1/access_token';
    private const API_BASE = 'https://oauth.reddit.com';

    private Database $db;
    private Config $config;
    private HttpClient $http;

    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;
    private int $requestCount = 0;
    private float $windowStart;
    private ?float $rateLimitRemaining = null;
    private ?float $rateLimitReset = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
        $this->http = new HttpClient();
        $this->windowStart = microtime(true);
    }

    public function run(): int
    {
        $subreddits = $this->config->get('reddit_monitor_subreddits', '');
        if ($subreddits === '') {
            Logger::info(self::MODULE, 'No subreddits configured for monitoring');
            return 0;
        }

        $subredditList = array_map('trim', explode(',', (string) $subreddits));
        $maxAgeHours = (int) $this->config->get('reddit_max_thread_age_hours', '48');
        $cutoffTimestamp = time() - ($maxAgeHours * 3600);
        $today = date('Y-m-d');

        $keywords = $this->db->fetchAll('SELECT keyword FROM reddit_watch_keywords');
        $keywordList = array_column($keywords, 'keyword');

        $ourUsername = strtolower((string) $this->config->get('REDDIT_USERNAME', ''));

        // Subreddits we already commented in today
        $commentedSubs = $this->db->fetchAll(
            "SELECT DISTINCT subreddit FROM reddit_threads
             WHERE status = 'commented' AND DATE(commented_at) = ?",
            [$today]
        );
        $commentedSubNames = array_map(
            fn(array $row): string => strtolower($row['subreddit']),
            $commentedSubs
        );

        // Existing thread reddit_ids
        $existingRows = $this->db->fetchAll('SELECT reddit_thread_id FROM reddit_threads');
        $existingIds = array_flip(array_column($existingRows, 'reddit_thread_id'));

        $discovered = 0;

        foreach ($subredditList as $subreddit) {
            $subreddit = trim($subreddit);
            if ($subreddit === '') {
                continue;
            }

            // Fetch new posts
            try {
                $posts = $this->apiGet("/r/{$subreddit}/new", ['limit' => '50']);
                $discovered += $this->processPosts(
                    $posts, $subreddit, $cutoffTimestamp, $existingIds,
                    $commentedSubNames, $ourUsername, $keywordList, $today
                );
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, "Failed to fetch new posts from r/{$subreddit}", [
                    'error' => $e->getMessage(),
                ]);
            }

            // Search for each keyword within subreddit
            foreach ($keywordList as $keyword) {
                try {
                    $posts = $this->apiGet("/r/{$subreddit}/search", [
                        'q'           => $keyword,
                        'restrict_sr' => 'on',
                        'sort'        => 'new',
                        't'           => 'day',
                        'limit'       => '25',
                    ]);
                    $discovered += $this->processPosts(
                        $posts, $subreddit, $cutoffTimestamp, $existingIds,
                        $commentedSubNames, $ourUsername, $keywordList, $today
                    );
                } catch (\Throwable $e) {
                    Logger::error(self::MODULE, "Failed to search r/{$subreddit} for '{$keyword}'", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Logger::info(self::MODULE, "Crawl complete: {$discovered} threads discovered");
        return $discovered;
    }

    private function processPosts(
        array $response,
        string $subreddit,
        int $cutoffTimestamp,
        array &$existingIds,
        array $commentedSubNames,
        string $ourUsername,
        array $keywordList,
        string $today
    ): int {
        $children = $response['data']['children'] ?? [];
        $discovered = 0;

        foreach ($children as $child) {
            $post = $child['data'] ?? [];
            $redditId = $post['name'] ?? '';
            $createdUtc = (int) ($post['created_utc'] ?? 0);
            $author = strtolower($post['author'] ?? '');
            $flair = strtolower($post['link_flair_text'] ?? '');
            $title = $post['title'] ?? '';
            $body = $post['selftext'] ?? '';
            $postSub = $post['subreddit'] ?? $subreddit;
            $permalink = $post['permalink'] ?? '';
            $numComments = (int) ($post['num_comments'] ?? 0);

            // Filter: too old
            if ($createdUtc < $cutoffTimestamp) {
                continue;
            }

            // Filter: already tracked
            if (isset($existingIds[$redditId])) {
                continue;
            }

            // Filter: subreddit we commented in today
            if (in_array(strtolower($postSub), $commentedSubNames, true)) {
                continue;
            }

            // Filter: our own account
            if ($ourUsername !== '' && $author === $ourUsername) {
                continue;
            }

            // Filter: mod/announcement flair
            if (str_contains($flair, 'mod') || str_contains($flair, 'announcement')) {
                continue;
            }

            // Check keyword match in title and body
            $combined = strtolower($title . ' ' . $body);
            $matched = false;
            foreach ($keywordList as $keyword) {
                if (str_contains($combined, strtolower($keyword))) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            // Insert as discovered
            $this->db->execute(
                "INSERT INTO reddit_threads
                 (reddit_thread_id, subreddit, title, body, author, permalink,
                  num_comments, created_utc, status, discovered_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), 'discovered', NOW())",
                [$redditId, $postSub, $title, $body, $author, $permalink, $numComments, $createdUtc]
            );

            $existingIds[$redditId] = true;
            $discovered++;

            Logger::debug(self::MODULE, 'Discovered thread', [
                'reddit_id' => $redditId,
                'subreddit' => $postSub,
                'title'     => mb_substr($title, 0, 100),
            ]);
        }

        return $discovered;
    }

    private function apiGet(string $endpoint, array $params = []): array
    {
        $this->enforceRateLimit();
        $this->ensureAccessToken();

        $url = self::API_BASE . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = $this->http->get($url, [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'User-Agent'    => $this->getUserAgent(),
        ]);

        $this->updateRateLimits($response['headers']);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(
                "Reddit API error (HTTP {$response['status']}): " . mb_substr($response['body'], 0, 200)
            );
        }

        $data = json_decode($response['body'], true);
        if ($data === null) {
            throw new RuntimeException('Failed to parse Reddit API response as JSON');
        }

        return $data;
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

        Logger::debug(self::MODULE, 'Reddit OAuth token acquired');
    }

    private function enforceRateLimit(): void
    {
        // Respect Reddit's X-Ratelimit headers
        if ($this->rateLimitRemaining !== null && $this->rateLimitRemaining <= 1 && $this->rateLimitReset !== null) {
            $sleepSeconds = (int) ceil($this->rateLimitReset);
            if ($sleepSeconds > 0 && $sleepSeconds <= 600) {
                Logger::debug(self::MODULE, "Rate limit approaching, sleeping {$sleepSeconds}s");
                sleep($sleepSeconds);
            }
        }

        // Hard cap: max 60 requests per minute
        $this->requestCount++;
        $elapsed = microtime(true) - $this->windowStart;

        if ($this->requestCount >= 60) {
            if ($elapsed < 60.0) {
                $wait = (int) ceil(60.0 - $elapsed);
                Logger::debug(self::MODULE, "Hit 60 req/min limit, sleeping {$wait}s");
                sleep($wait);
            }
            $this->requestCount = 0;
            $this->windowStart = microtime(true);
        }

        // Minimum 1 second between requests
        sleep(1);
    }

    private function updateRateLimits(array $headers): void
    {
        if (isset($headers['x-ratelimit-remaining'])) {
            $this->rateLimitRemaining = (float) $headers['x-ratelimit-remaining'];
        }
        if (isset($headers['x-ratelimit-reset'])) {
            $this->rateLimitReset = (float) $headers['x-ratelimit-reset'];
        }
    }

    private function getUserAgent(): string
    {
        return 'SecurityDrama/1.0 (by /u/' . ($this->config->get('REDDIT_USERNAME', 'securitydrama')) . ')';
    }
}
