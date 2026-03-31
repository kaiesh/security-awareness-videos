<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

\SecurityDrama\Bootstrap::init(dirname(__DIR__));

session_start();

header('Content-Type: application/json; charset=utf-8');

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

/**
 * Send a JSON response and exit.
 */
function jsonResponse(array $data, int $status = 200): never
{
    global $jsonFlags;
    http_response_code($status);
    echo json_encode($data, $jsonFlags);
    exit;
}

/**
 * Send an error response.
 */
function jsonError(string $message, int $status = 400): never
{
    jsonResponse(['error' => $message], $status);
}

// --- Rate limiting ---
$rateLimitDir = '/tmp/securitydrama';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0700, true);
}

$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
$rateLimitFile = $rateLimitDir . '/rate_' . $ipHash . '.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$now = time();

$rateData = [];
if (file_exists($rateLimitFile)) {
    $raw = file_get_contents($rateLimitFile);
    if ($raw !== false) {
        $rateData = json_decode($raw, true) ?? [];
    }
}

$windowKey = $method === 'GET' ? 'get' : 'post';
$maxRequests = $method === 'GET' ? 60 : 20;

if (!isset($rateData[$windowKey]) || ($rateData[$windowKey]['window_start'] ?? 0) < $now - 60) {
    $rateData[$windowKey] = ['window_start' => $now, 'count' => 0];
}

$rateData[$windowKey]['count']++;

if ($rateData[$windowKey]['count'] > $maxRequests) {
    $retryAfter = 60 - ($now - $rateData[$windowKey]['window_start']);
    if ($retryAfter < 1) {
        $retryAfter = 1;
    }
    header('Retry-After: ' . $retryAfter);
    jsonError('Rate limit exceeded. Try again in ' . $retryAfter . ' seconds.', 429);
}

file_put_contents($rateLimitFile, json_encode($rateData), LOCK_EX);

// --- CSRF validation for state-changing requests ---
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrfSession = $_SESSION['csrf_token'] ?? '';

    if ($csrfSession === '' || !hash_equals($csrfSession, $csrfHeader)) {
        jsonError('Invalid CSRF token.', 403);
    }
}

// --- Routing ---
$action = $_GET['action'] ?? '';
$db = \SecurityDrama\Database::getInstance();

switch ($action) {

    // ============================================================
    // Dashboard stats
    // ============================================================
    case 'dashboard-stats':
        $feedToday = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM feed_items WHERE DATE(ingested_at) = CURDATE()"
        );
        $feedTotal = $db->fetchOne("SELECT COUNT(*) AS cnt FROM feed_items");

        $queueBreakdown = $db->fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM content_queue GROUP BY status"
        );

        $videosToday = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM videos WHERE DATE(created_at) = CURDATE()"
        );

        $config = \SecurityDrama\Config::getInstance();
        $videoTarget = (int) $config->get('daily_video_target', '3');

        $postsByPlatform = $db->fetchAll(
            "SELECT platform, COUNT(*) AS cnt FROM social_posts GROUP BY platform"
        );

        $recentLogs = $db->fetchAll(
            "SELECT id, module, level, message, created_at FROM pipeline_log ORDER BY created_at DESC LIMIT 10"
        );

        $pipelineEnabled = (int) $config->get('pipeline_enabled', '1');

        jsonResponse([
            'feed_items_today'  => (int) ($feedToday['cnt'] ?? 0),
            'feed_items_total'  => (int) ($feedTotal['cnt'] ?? 0),
            'queue_breakdown'   => $queueBreakdown,
            'videos_today'      => (int) ($videosToday['cnt'] ?? 0),
            'daily_video_target' => $videoTarget,
            'posts_by_platform' => $postsByPlatform,
            'recent_logs'       => $recentLogs,
            'pipeline_enabled'  => $pipelineEnabled,
        ]);

    // ============================================================
    // Queue list
    // ============================================================
    case 'queue':
        $status = $_GET['status'] ?? '';
        $pageNum = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($pageNum - 1) * 50;

        $where = '';
        $params = [];
        if ($status !== '') {
            $where = 'WHERE cq.status = ?';
            $params[] = $status;
        }

        $countRow = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM content_queue cq {$where}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $params[] = $offset;
        $rows = $db->fetchAll(
            "SELECT cq.id, cq.status, cq.content_type, cq.target_audience, cq.priority, cq.created_at,
                    fi.title AS feed_title
             FROM content_queue cq
             LEFT JOIN feed_items fi ON fi.id = cq.feed_item_id
             {$where}
             ORDER BY cq.priority ASC, cq.created_at DESC
             LIMIT 50 OFFSET ?",
            $params
        );

        jsonResponse([
            'items'       => $rows,
            'total'       => $total,
            'page'        => $pageNum,
            'total_pages' => (int) ceil($total / 50),
        ]);

    // ============================================================
    // Queue detail
    // ============================================================
    case 'queue-detail':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            jsonError('Invalid queue ID.');
        }

        $item = $db->fetchOne(
            "SELECT cq.*, fi.title AS feed_title, fi.description AS feed_description, fi.url AS feed_url
             FROM content_queue cq
             LEFT JOIN feed_items fi ON fi.id = cq.feed_item_id
             WHERE cq.id = ?",
            [$id]
        );
        if ($item === null) {
            jsonError('Queue item not found.', 404);
        }

        $script = $db->fetchOne("SELECT * FROM scripts WHERE queue_id = ?", [$id]);
        $video = $db->fetchOne("SELECT * FROM videos WHERE queue_id = ?", [$id]);

        $posts = [];
        if ($video !== null) {
            $posts = $db->fetchAll(
                "SELECT * FROM social_posts WHERE video_id = ? ORDER BY platform",
                [(int) $video['id']]
            );
        }

        jsonResponse([
            'item'   => $item,
            'script' => $script,
            'video'  => $video,
            'posts'  => $posts,
        ]);

    // ============================================================
    // Feed toggle
    // ============================================================
    case 'feed-toggle':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            jsonError('Invalid feed ID.');
        }

        $feed = $db->fetchOne("SELECT id, is_active FROM feed_sources WHERE id = ?", [$id]);
        if ($feed === null) {
            jsonError('Feed source not found.', 404);
        }

        $newState = $feed['is_active'] ? 0 : 1;
        $db->execute("UPDATE feed_sources SET is_active = ? WHERE id = ?", [$newState, $id]);

        jsonResponse(['success' => true, 'is_active' => $newState]);

    // ============================================================
    // Feed poll now
    // ============================================================
    case 'feed-poll':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            jsonError('Invalid feed ID.');
        }

        $feed = $db->fetchOne("SELECT * FROM feed_sources WHERE id = ?", [$id]);
        if ($feed === null) {
            jsonError('Feed source not found.', 404);
        }

        try {
            $ingester = new \SecurityDrama\Ingest\FeedIngester();

            // Force the source to be "due" by updating last_polled_at to a past date
            $db->execute(
                "UPDATE feed_sources SET last_polled_at = '2000-01-01 00:00:00' WHERE id = ?",
                [$id]
            );

            $stats = $ingester->run();

            jsonResponse([
                'success' => true,
                'message' => "Polled feed: {$feed['name']}",
                'stats'   => $stats,
            ]);
        } catch (\Throwable $e) {
            jsonError('Feed poll failed: ' . $e->getMessage(), 500);
        }

    // ============================================================
    // Config get
    // ============================================================
    case 'config':
        $config = \SecurityDrama\Config::getInstance();

        $dbConfig = $db->fetchAll("SELECT `key`, `value` FROM config");

        $platformConfig = $db->fetchAll("SELECT * FROM platform_config ORDER BY platform");

        jsonResponse([
            'config'          => $dbConfig,
            'platform_config' => $platformConfig,
        ]);

    // ============================================================
    // Config save
    // ============================================================
    case 'config-save':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            jsonError('Invalid JSON body.');
        }

        $config = \SecurityDrama\Config::getInstance();
        $errors = [];

        $validations = [
            'daily_video_target'   => ['type' => 'int', 'min' => 1, 'max' => 10],
            'min_relevance_score'  => ['type' => 'int', 'min' => 0, 'max' => 100],
            'log_retention_days'   => ['type' => 'int', 'min' => 1, 'max' => 365],
        ];

        foreach ($input as $key => $value) {
            if (isset($validations[$key])) {
                $rule = $validations[$key];
                $intVal = (int) $value;
                if ($intVal < $rule['min'] || $intVal > $rule['max']) {
                    $errors[] = "{$key} must be between {$rule['min']} and {$rule['max']}.";
                    continue;
                }
            }
            $config->set((string) $key, (string) $value);
        }

        if (!empty($errors)) {
            jsonResponse(['success' => false, 'errors' => $errors], 422);
        }

        jsonResponse(['success' => true]);

    // ============================================================
    // Platform config save
    // ============================================================
    case 'platform-save':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            jsonError('Invalid JSON body.');
        }

        $platform = $input['platform'] ?? '';
        if ($platform === '') {
            jsonError('Platform is required.');
        }

        $existing = $db->fetchOne("SELECT platform FROM platform_config WHERE platform = ?", [$platform]);
        if ($existing === null) {
            jsonError('Unknown platform.', 404);
        }

        $isEnabled = isset($input['is_enabled']) ? (int) $input['is_enabled'] : null;
        $adapter = $input['adapter'] ?? null;
        $postType = $input['post_type'] ?? null;
        $platformConfigJson = $input['platform_config_json'] ?? null;
        $maxDailyPosts = isset($input['max_daily_posts']) ? (int) $input['max_daily_posts'] : null;

        $sets = [];
        $params = [];

        if ($isEnabled !== null) {
            $sets[] = 'is_enabled = ?';
            $params[] = $isEnabled;
        }
        if ($adapter !== null) {
            $sets[] = 'adapter = ?';
            $params[] = $adapter;
        }
        if ($postType !== null) {
            $sets[] = 'post_type = ?';
            $params[] = $postType;
        }
        if ($platformConfigJson !== null) {
            $sets[] = 'platform_config_json = ?';
            $params[] = $platformConfigJson;
        }
        if ($maxDailyPosts !== null) {
            $sets[] = 'max_daily_posts = ?';
            $params[] = $maxDailyPosts;
        }

        if (empty($sets)) {
            jsonError('No fields to update.');
        }

        $params[] = $platform;
        $db->execute(
            "UPDATE platform_config SET " . implode(', ', $sets) . " WHERE platform = ?",
            $params
        );

        jsonResponse(['success' => true]);

    // ============================================================
    // Logs
    // ============================================================
    case 'logs':
        $module = $_GET['module'] ?? '';
        $level = $_GET['level'] ?? '';
        $pageNum = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($pageNum - 1) * 50;

        $where = [];
        $params = [];

        if ($module !== '') {
            $where[] = 'module = ?';
            $params[] = $module;
        }
        if ($level !== '') {
            $where[] = 'level = ?';
            $params[] = $level;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countRow = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM pipeline_log {$whereClause}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $params[] = $offset;
        $rows = $db->fetchAll(
            "SELECT id, module, level, message, context, created_at
             FROM pipeline_log
             {$whereClause}
             ORDER BY created_at DESC
             LIMIT 50 OFFSET ?",
            $params
        );

        jsonResponse([
            'items'       => $rows,
            'total'       => $total,
            'page'        => $pageNum,
            'total_pages' => (int) ceil($total / 50),
        ]);

    // ============================================================
    // Reddit stats
    // ============================================================
    case 'reddit-stats':
        $today = date('Y-m-d');

        $discovered = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM reddit_threads WHERE DATE(discovered_at) = ?",
            [$today]
        );
        $matched = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM reddit_threads WHERE matched_video_id IS NOT NULL AND DATE(discovered_at) = ?",
            [$today]
        );
        $commented = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM reddit_threads WHERE status = 'commented' AND DATE(commented_at) = ?",
            [$today]
        );
        $skipped = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM reddit_threads WHERE status = 'skipped' AND DATE(discovered_at) = ?",
            [$today]
        );

        $config = \SecurityDrama\Config::getInstance();
        $engagementEnabled = (int) $config->get('reddit_engagement_enabled', '1');

        jsonResponse([
            'discovered_today'    => (int) ($discovered['cnt'] ?? 0),
            'matched_today'       => (int) ($matched['cnt'] ?? 0),
            'commented_today'     => (int) ($commented['cnt'] ?? 0),
            'skipped_today'       => (int) ($skipped['cnt'] ?? 0),
            'engagement_enabled'  => $engagementEnabled,
        ]);

    // ============================================================
    // Reddit threads list
    // ============================================================
    case 'reddit-threads':
        $status = $_GET['status'] ?? '';
        $subreddit = $_GET['subreddit'] ?? '';
        $pageNum = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($pageNum - 1) * 50;

        $where = [];
        $params = [];

        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($subreddit !== '') {
            $where[] = 'subreddit = ?';
            $params[] = $subreddit;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countRow = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM reddit_threads {$whereClause}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $params[] = $offset;
        $rows = $db->fetchAll(
            "SELECT * FROM reddit_threads
             {$whereClause}
             ORDER BY discovered_at DESC
             LIMIT 50 OFFSET ?",
            $params
        );

        jsonResponse([
            'items'       => $rows,
            'total'       => $total,
            'page'        => $pageNum,
            'total_pages' => (int) ceil($total / 50),
        ]);

    // ============================================================
    // Reddit pause
    // ============================================================
    case 'reddit-pause':
        $config = \SecurityDrama\Config::getInstance();
        $config->set('reddit_engagement_enabled', '0');
        \SecurityDrama\Logger::warning('admin', 'Reddit engagement paused via dashboard');
        jsonResponse(['success' => true]);

    // ============================================================
    // Reddit keyword add
    // ============================================================
    case 'reddit-keyword-add':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            jsonError('Invalid JSON body.');
        }

        $keyword = trim($input['keyword'] ?? '');
        $keywordType = $input['keyword_type'] ?? 'topic';

        if ($keyword === '') {
            jsonError('Keyword is required.');
        }

        $validTypes = ['package', 'cve', 'topic', 'technology'];
        if (!in_array($keywordType, $validTypes, true)) {
            jsonError('Invalid keyword type.');
        }

        try {
            $db->execute(
                "INSERT INTO reddit_watch_keywords (keyword, keyword_type) VALUES (?, ?)",
                [$keyword, $keywordType]
            );
            jsonResponse(['success' => true, 'id' => (int) $db->lastInsertId()]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                jsonError('Keyword already exists.', 409);
            }
            throw $e;
        }

    // ============================================================
    // Reddit keyword toggle
    // ============================================================
    case 'reddit-keyword-toggle':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            jsonError('Invalid keyword ID.');
        }

        $kw = $db->fetchOne("SELECT id, is_active FROM reddit_watch_keywords WHERE id = ?", [$id]);
        if ($kw === null) {
            jsonError('Keyword not found.', 404);
        }

        $newState = $kw['is_active'] ? 0 : 1;
        $db->execute("UPDATE reddit_watch_keywords SET is_active = ? WHERE id = ?", [$newState, $id]);

        jsonResponse(['success' => true, 'is_active' => $newState]);

    // ============================================================
    // Unknown action
    // ============================================================
    default:
        jsonError('Unknown action.', 404);
}
