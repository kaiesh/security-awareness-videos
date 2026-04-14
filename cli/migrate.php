<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;

Bootstrap::init();

// Administrative tool — intentionally NOT gated by pipeline_enabled.
// sync.php calls this while the kill switch is engaged.
//
// DDL requires CREATE/ALTER privileges which the runtime app user
// (securitydrama) does not have by design — least privilege keeps a SQL
// injection from being able to DROP TABLE. So this script connects as
// MySQL root via the unix socket (auth_socket), which requires running as
// OS root. Same auth path deploy.php uses for its initial mysql bootstrap.

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "migrate.php must run as root (uses MySQL auth_socket for DDL).\n");
    fwrite(STDERR, "Try: sudo php cli/migrate.php\n");
    exit(1);
}

// /var/lock is root-owned by default and the standard Linux location for
// system locks. Earlier versions of this script used /tmp, which caused
// permission issues when the lock file was created by one user (www-data)
// and reopened by another (root) — and /tmp is also subject to systemd
// PrivateTmp namespacing on some setups.
$lockPath = '/var/lock/securitydrama-migrate.lock';
$lockFile = @fopen($lockPath, 'c');
if ($lockFile === false) {
    fwrite(STDERR, "Could not open lock file {$lockPath}\n");
    exit(1);
}
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another migration process is already running.\n";
    exit(1);
}

$dbName = $_ENV['DB_NAME'] ?? 'securitydrama';
$socket = $_ENV['DB_SOCKET'] ?? '/var/run/mysqld/mysqld.sock';

try {
    $db = new PDO(
        "mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4",
        'root',
        '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (\PDOException $e) {
    fwrite(STDERR, "Could not connect to MySQL as root via {$socket}: " . $e->getMessage() . "\n");
    exit(1);
}

$tables = [
    'feed_sources' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS feed_sources (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(50) NOT NULL UNIQUE,
            category ENUM('cve','exploit','breach','news','vendor','scam','community') NOT NULL,
            feed_type ENUM('rss','json_api','json_download','html_scrape','nvd_api') NOT NULL,
            url VARCHAR(500) NOT NULL,
            polling_interval_minutes INT UNSIGNED NOT NULL DEFAULT 360,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_polled_at DATETIME NULL,
            last_successful_at DATETIME NULL,
            last_error TEXT NULL,
            items_fetched_total INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_active_poll (is_active, last_polled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'feed_items' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS feed_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_id INT UNSIGNED NOT NULL,
            external_id VARCHAR(100) NULL COMMENT 'CVE ID, advisory ID, or source-specific ID',
            content_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of title+description for dedup',
            title VARCHAR(500) NOT NULL,
            description TEXT NOT NULL,
            url VARCHAR(1000) NULL,
            severity ENUM('critical','high','medium','low','info','unknown') NOT NULL DEFAULT 'unknown',
            cvss_score DECIMAL(3,1) NULL,
            affected_products TEXT NULL COMMENT 'JSON array of product/ecosystem names',
            raw_data MEDIUMTEXT NULL COMMENT 'Original JSON/XML for reference',
            published_at DATETIME NULL,
            ingested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            relevance_score DECIMAL(5,2) NULL COMMENT 'Calculated by scoring engine, 0-100',
            audience_tags VARCHAR(200) NULL COMMENT 'Comma-separated: vibe_coder,smb,general',
            is_processed TINYINT(1) NOT NULL DEFAULT 0,
            FOREIGN KEY (source_id) REFERENCES feed_sources(id),
            UNIQUE KEY uk_content_hash (content_hash),
            INDEX idx_unprocessed (is_processed, relevance_score DESC),
            INDEX idx_external_id (external_id),
            INDEX idx_ingested (ingested_at),
            INDEX idx_severity (severity, relevance_score DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'content_queue' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS content_queue (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feed_item_id INT UNSIGNED NOT NULL,
            content_type ENUM('cve_alert','scam_drama','security_101','vibe_roast','breach_story') NOT NULL,
            target_audience ENUM('vibe_coder','smb','general') NOT NULL,
            status ENUM('pending_script','generating_script','pending_video','generating_video','pending_publish','publishing','published','failed') NOT NULL DEFAULT 'pending_script',
            priority INT UNSIGNED NOT NULL DEFAULT 50 COMMENT '1=highest, 100=lowest',
            script_id INT UNSIGNED NULL,
            video_id INT UNSIGNED NULL,
            failure_reason TEXT NULL,
            retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (feed_item_id) REFERENCES feed_items(id),
            INDEX idx_status_priority (status, priority ASC, created_at ASC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'scripts' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS scripts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            queue_id INT UNSIGNED NOT NULL,
            narration_text TEXT NOT NULL COMMENT 'Full voiceover narration',
            on_screen_text TEXT NULL COMMENT 'JSON array of timed text overlays',
            visual_direction TEXT NULL COMMENT 'JSON scene descriptions for video gen',
            hook_line VARCHAR(300) NOT NULL COMMENT 'First line / social media caption hook',
            cta_text VARCHAR(500) NULL COMMENT 'Call to action text',
            hashtags VARCHAR(300) NULL COMMENT 'Comma-separated hashtags',
            estimated_duration_seconds INT UNSIGNED NULL,
            llm_model VARCHAR(50) NOT NULL,
            llm_prompt_tokens INT UNSIGNED NULL,
            llm_completion_tokens INT UNSIGNED NULL,
            llm_cost_usd DECIMAL(6,4) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (queue_id) REFERENCES content_queue(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'videos' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS videos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            queue_id INT UNSIGNED NOT NULL,
            script_id INT UNSIGNED NOT NULL,
            provider VARCHAR(30) NOT NULL COMMENT 'heygen, synthesia, etc',
            provider_video_id VARCHAR(100) NULL COMMENT 'ID from the video provider',
            provider_status VARCHAR(30) NULL,
            storage_path VARCHAR(500) NULL COMMENT 'Path in DO Spaces',
            storage_url VARCHAR(1000) NULL COMMENT 'Full CDN URL',
            thumbnail_path VARCHAR(500) NULL,
            thumbnail_url VARCHAR(1000) NULL,
            duration_seconds INT UNSIGNED NULL,
            file_size_bytes BIGINT UNSIGNED NULL,
            resolution VARCHAR(20) NULL COMMENT '1080x1920, 1920x1080, etc',
            aspect_ratio ENUM('9:16','16:9','1:1') NOT NULL DEFAULT '9:16',
            provider_cost_credits DECIMAL(8,2) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            FOREIGN KEY (queue_id) REFERENCES content_queue(id),
            FOREIGN KEY (script_id) REFERENCES scripts(id),
            INDEX idx_provider_status (provider, provider_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'social_posts' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS social_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            video_id INT UNSIGNED NOT NULL,
            platform ENUM('youtube','x','reddit','instagram','facebook','tiktok','linkedin','threads','bluesky','mastodon','pinterest') NOT NULL,
            adapter VARCHAR(30) NOT NULL COMMENT 'Which adapter was used: direct, missinglettr',
            status ENUM('pending','posting','posted','failed') NOT NULL DEFAULT 'pending',
            platform_post_id VARCHAR(200) NULL COMMENT 'Post/video ID returned by platform',
            platform_url VARCHAR(1000) NULL COMMENT 'Direct URL to the post',
            title VARCHAR(300) NULL,
            description TEXT NULL,
            failure_reason TEXT NULL,
            retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            posted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (video_id) REFERENCES videos(id),
            UNIQUE KEY uk_video_platform (video_id, platform),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'platform_config' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS platform_config (
            platform VARCHAR(20) PRIMARY KEY,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            adapter ENUM('direct','missinglettr','disabled') NOT NULL DEFAULT 'disabled',
            post_type ENUM('native_video','link_to_youtube','text_with_link') NOT NULL DEFAULT 'native_video' COMMENT 'How to post: upload video natively, link to YT, or text+link',
            platform_config_json TEXT NULL COMMENT 'Platform-specific config as JSON (e.g. subreddits for Reddit)',
            max_daily_posts INT UNSIGNED NOT NULL DEFAULT 10,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'config' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS config (
            config_key VARCHAR(100) PRIMARY KEY,
            config_value TEXT NOT NULL,
            description VARCHAR(300) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'pipeline_log' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS pipeline_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            module VARCHAR(30) NOT NULL COMMENT 'ingest, score, script, video, publish',
            level ENUM('debug','info','warning','error','critical') NOT NULL,
            message TEXT NOT NULL,
            context TEXT NULL COMMENT 'JSON with additional context',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_module_level (module, level, created_at DESC),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'reddit_watch_keywords' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS reddit_watch_keywords (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            keyword VARCHAR(100) NOT NULL,
            keyword_type ENUM('package','cve','topic','technology') NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_keyword (keyword)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'broll_cache' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS broll_cache (
            query_hash CHAR(40) PRIMARY KEY,
            query_text VARCHAR(500) NOT NULL,
            source ENUM('pexels') NOT NULL DEFAULT 'pexels',
            source_video_id VARCHAR(100) NOT NULL,
            storage_path VARCHAR(500) NOT NULL COMMENT 'DO Spaces key',
            duration_seconds DECIMAL(6,2) NOT NULL,
            width INT UNSIGNED NOT NULL,
            height INT UNSIGNED NOT NULL,
            credit_text VARCHAR(300) NOT NULL COMMENT 'Pexels attribution string',
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'background_music' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS background_music (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category ENUM('cve_alert','scam_drama','security_101','vibe_roast','breach_story') NOT NULL,
            name VARCHAR(200) NOT NULL,
            storage_path VARCHAR(500) NOT NULL COMMENT 'DO Spaces key',
            duration_seconds DECIMAL(6,2) NULL,
            volume DECIMAL(3,2) NOT NULL DEFAULT 0.15 COMMENT 'Mix level 0.0-1.0',
            credit_text VARCHAR(300) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category_active (category, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,

    'reddit_threads' => <<<'SQL'
        CREATE TABLE IF NOT EXISTS reddit_threads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reddit_post_id VARCHAR(20) NOT NULL COMMENT 'Reddit fullname e.g. t3_abc123',
            subreddit VARCHAR(50) NOT NULL,
            title VARCHAR(500) NOT NULL,
            post_body TEXT NULL,
            author VARCHAR(50) NOT NULL,
            permalink VARCHAR(500) NOT NULL,
            upvotes INT NOT NULL DEFAULT 0,
            comment_count INT NOT NULL DEFAULT 0,
            matched_keywords TEXT NOT NULL COMMENT 'JSON array of keywords that matched',
            matched_video_id INT UNSIGNED NULL COMMENT 'Our video that is relevant',
            status ENUM('discovered','evaluating','approved','commented','skipped','failed') NOT NULL DEFAULT 'discovered',
            skip_reason VARCHAR(200) NULL,
            comment_text TEXT NULL COMMENT 'The generated comment text',
            reddit_comment_id VARCHAR(20) NULL COMMENT 'Our comment ID after posting',
            discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            commented_at DATETIME NULL,
            UNIQUE KEY uk_reddit_post (reddit_post_id),
            FOREIGN KEY (matched_video_id) REFERENCES videos(id),
            INDEX idx_status (status, discovered_at DESC),
            INDEX idx_subreddit (subreddit, discovered_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL,
];

$created = 0;
$errors = 0;

foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        echo "  [OK] {$name}\n";
        $created++;
    } catch (\PDOException $e) {
        echo "  [FAIL] {$name}: {$e->getMessage()}\n";
        $errors++;
    }
}

// =========================================================================
// Idempotent in-place schema changes for already-deployed servers.
// CREATE TABLE IF NOT EXISTS above only handles fresh installs; existing
// tables need explicit ALTERs. Each entry must be safe to re-run.
//
// MySQL appends ENUM values in-place without rewriting the table, so the
// feed_type ALTER is cheap regardless of whether nvd_api is already there.
// =========================================================================
$alterations = [
    'feed_sources.feed_type += nvd_api'
        => "ALTER TABLE feed_sources MODIFY COLUMN feed_type "
         . "ENUM('rss','json_api','json_download','html_scrape','nvd_api') NOT NULL",

    // scripts: per-platform title/description columns consumed by
    // PlatformFormatter, and raw LLM response for debugging.
    'scripts.+title_youtube'
        => "ALTER TABLE scripts ADD COLUMN title_youtube VARCHAR(300) NULL",
    'scripts.+title_social'
        => "ALTER TABLE scripts ADD COLUMN title_social VARCHAR(300) NULL",
    'scripts.+description_youtube'
        => "ALTER TABLE scripts ADD COLUMN description_youtube TEXT NULL",
    'scripts.+raw_response'
        => "ALTER TABLE scripts ADD COLUMN raw_response MEDIUMTEXT NULL",

    // videos: provider-side error text (content_queue.failure_reason
    // already captures the queue-level message, but we want the raw
    // provider response on the video row too).
    'videos.+provider_error'
        => "ALTER TABLE videos ADD COLUMN provider_error TEXT NULL",

    // videos: flag indicating whether the published mp4 went through the
    // b-roll compositor (1) or fell back to the narrator-only path (0).
    'videos.+composited'
        => "ALTER TABLE videos ADD COLUMN composited TINYINT(1) NOT NULL DEFAULT 0 "
         . "COMMENT '1 = published with b-roll compositing, 0 = narrator-only fallback'",
];

$altered = 0;
foreach ($alterations as $label => $sql) {
    try {
        $db->exec($sql);
        echo "  [ALTER OK] {$label}\n";
        $altered++;
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        // MySQL < 8.0.29 has no ADD COLUMN IF NOT EXISTS, so re-runs hit
        // "Duplicate column name" or "Duplicate key name" on already-applied
        // alterations. Treat those as success, not failure.
        if (str_contains($msg, 'Duplicate column name')
            || str_contains($msg, 'Duplicate key name')) {
            echo "  [ALTER SKIP] {$label} (already applied)\n";
            continue;
        }
        echo "  [ALTER FAIL] {$label}: {$msg}\n";
        $errors++;
    }
}

echo "\nMigration complete. Tables: {$created} OK, Alterations: {$altered} OK, {$errors} errors.\n";
exit($errors > 0 ? 1 : 0);
