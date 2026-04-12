<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;
use SecurityDrama\Database;

Bootstrap::init();

// Administrative tool — intentionally NOT gated by pipeline_enabled.
// sync.php calls this while the kill switch is engaged.

$lockFile = fopen('/tmp/securitydrama_seed_feeds.lock', 'c');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another seed process is already running.\n";
    exit(1);
}

$db = Database::getInstance();

// =========================================================================
// 1. Seed feed_sources from config/feeds.php
// =========================================================================

echo "Seeding feed_sources...\n";

$feeds = require __DIR__ . '/../config/feeds.php';
$feedCount = 0;

foreach ($feeds as $feed) {
    $db->execute(
        'INSERT INTO feed_sources (name, slug, category, feed_type, url, polling_interval_minutes)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             name = VALUES(name),
             category = VALUES(category),
             feed_type = VALUES(feed_type),
             url = VALUES(url),
             polling_interval_minutes = VALUES(polling_interval_minutes)',
        [
            $feed['name'],
            $feed['slug'],
            $feed['category'],
            $feed['feed_type'],
            $feed['url'],
            $feed['polling_interval_minutes'],
        ]
    );
    $feedCount++;
}

echo "  Upserted {$feedCount} feed sources.\n";

// =========================================================================
// 2. Seed platform_config
// =========================================================================

echo "Seeding platform_config...\n";

$platforms = [
    ['youtube',   1, 'direct',       'native_video',    null],
    ['x',         1, 'missinglettr', 'native_video',    null],
    ['reddit',    1, 'missinglettr', 'link_to_youtube', '{"subreddits":["cybersecurity","netsec","vibecoding"]}'],
    ['instagram', 1, 'missinglettr', 'native_video',    null],
    ['facebook',  1, 'missinglettr', 'native_video',    null],
    ['tiktok',    1, 'missinglettr', 'native_video',    null],
    ['linkedin',  1, 'missinglettr', 'native_video',    null],
    ['threads',   0, 'missinglettr', 'text_with_link',  null],
    ['bluesky',   0, 'missinglettr', 'text_with_link',  null],
    ['mastodon',  0, 'missinglettr', 'text_with_link',  null],
    ['pinterest', 0, 'disabled',     'native_video',    null],
];

$platformCount = 0;

foreach ($platforms as [$platform, $isEnabled, $adapter, $postType, $configJson]) {
    $db->execute(
        'INSERT INTO platform_config (platform, is_enabled, adapter, post_type, platform_config_json)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             is_enabled = VALUES(is_enabled),
             adapter = VALUES(adapter),
             post_type = VALUES(post_type),
             platform_config_json = VALUES(platform_config_json)',
        [$platform, $isEnabled, $adapter, $postType, $configJson]
    );
    $platformCount++;
}

echo "  Upserted {$platformCount} platform configs.\n";

// =========================================================================
// 3. Seed config table with default values
// =========================================================================

echo "Seeding config...\n";

$configDefaults = [
    ['daily_video_target',             '2',       'Videos per day (1-10)'],
    ['video_provider',                 'heygen',  'Active video adapter'],
    ['video_aspect_ratio',             '9:16',    'Default aspect ratio'],
    ['heygen_template_id',             '',        'HeyGen template ID'],
    ['heygen_avatar_id',               '',        'Fallback avatar ID'],
    ['heygen_voice_id',                '',        'Fallback voice ID'],
    ['seedance_resolution',            '720p',    'Seedance video resolution (480p, 720p)'],
    ['seedance_duration',              '10',      'Seedance video duration in seconds (4-15)'],
    ['min_relevance_score',            '40',      'Min score for selection'],
    ['max_retry_count',                '3',       'Max retries for failures'],
    ['video_poll_interval_seconds',    '60',      'Video status check interval'],
    ['log_retention_days',             '30',      'Days to keep logs'],
    ['pipeline_enabled',               '1',       'Master kill switch'],
    ['reddit_engagement_enabled',      '0',       'Reddit engagement master switch'],
    ['reddit_monitor_subreddits',      'vibecoding,webdev,reactjs,nextjs,cybersecurity,netsec,AskNetsec,smallbusiness,Scams', 'Subreddits to monitor'],
    ['reddit_max_comments_daily',      '5',       'Max comments/day'],
    ['reddit_comment_interval_minutes','30',      'Min gap between comments'],
    ['reddit_max_thread_age_hours',    '48',      'Max thread age'],
    ['reddit_min_match_score',         '40',      'Min keyword match score'],
];

$configCount = 0;

foreach ($configDefaults as [$key, $value, $description]) {
    $db->execute(
        'INSERT INTO config (config_key, config_value, description)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
             config_value = VALUES(config_value),
             description = VALUES(description)',
        [$key, $value, $description]
    );
    $configCount++;
}

echo "  Upserted {$configCount} config entries.\n";

// =========================================================================
// 4. Seed reddit_watch_keywords
// =========================================================================

echo "Seeding reddit_watch_keywords...\n";

$keywords = [
    // Packages / technologies
    ['next.js',      'package'],
    ['supabase',     'package'],
    ['lovable',      'package'],
    ['cursor',       'package'],
    ['react',        'package'],
    ['node.js',      'package'],
    ['express',      'package'],
    ['django',       'package'],
    ['flask',        'package'],
    ['fastapi',      'package'],
    ['vue',          'package'],
    ['angular',      'package'],
    ['svelte',       'package'],
    ['vercel',       'package'],
    ['netlify',      'package'],
    ['prisma',       'package'],
    ['drizzle',      'package'],
    ['tailwind',     'package'],

    // Security terms
    ['sql injection',          'topic'],
    ['xss',                    'topic'],
    ['csrf',                   'topic'],
    ['rce',                    'topic'],
    ['ransomware',             'topic'],
    ['phishing',               'topic'],
    ['data breach',            'topic'],
    ['api key leaked',         'topic'],
    ['.env exposed',           'topic'],
    ['password leak',          'topic'],
    ['zero day',               'topic'],
    ['malware',                'topic'],
    ['supply chain attack',    'topic'],
    ['npm malicious package',  'topic'],
    ['typosquatting',          'topic'],
];

$keywordCount = 0;

foreach ($keywords as [$keyword, $type]) {
    $db->execute(
        'INSERT INTO reddit_watch_keywords (keyword, keyword_type)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
             keyword_type = VALUES(keyword_type)',
        [$keyword, $type]
    );
    $keywordCount++;
}

echo "  Upserted {$keywordCount} watch keywords.\n";

echo "\nSeeding complete.\n";
