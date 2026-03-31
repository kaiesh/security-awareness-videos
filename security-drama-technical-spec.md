# TECHNICAL SPECIFICATION: Security Drama

## Project overview

Security Drama is a fully automated content pipeline that ingests security vulnerability feeds, generates dramatic short-form video content using AI, and publishes to social media platforms. It runs on a single DigitalOcean droplet (1GB RAM) with PHP/MySQL backend.

**This document is the implementation blueprint. Build exactly what is specified here.**

---

## Table of contents

1. [System architecture](#1-system-architecture)
2. [Infrastructure and deployment](#2-infrastructure-and-deployment)
3. [Database schema](#3-database-schema)
4. [Application structure](#4-application-structure)
5. [Module 1: Feed ingestion](#5-module-1-feed-ingestion)
6. [Module 2: Content scoring and selection](#6-module-2-content-scoring-and-selection)
7. [Module 3: Script generation](#7-module-3-script-generation)
8. [Module 4: Video generation (pluggable)](#8-module-4-video-generation-pluggable)
9. [Module 5: Social media publishing (pluggable per-platform)](#9-module-5-social-media-publishing-pluggable-per-platform)
10. [Module 6: Pipeline orchestrator](#10-module-6-pipeline-orchestrator)
11. [Module 7: Admin dashboard](#11-module-7-admin-dashboard)
12. [Module 8: Reddit engagement crawler](#12-module-8-reddit-engagement-crawler)
13. [Configuration system](#13-configuration-system)
14. [Cron schedule](#14-cron-schedule)
15. [Third-party API integrations](#15-third-party-api-integrations)
16. [API keys and subscriptions required](#16-api-keys-and-subscriptions-required)
17. [Error handling and logging](#17-error-handling-and-logging)
18. [Security hardening](#18-security-hardening)
19. [Memory and performance constraints](#19-memory-and-performance-constraints)
20. [File and directory structure](#20-file-and-directory-structure)
21. [Implementation order](#21-implementation-order)

---

## 1. System architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    CRON SCHEDULER (systemd timers)               │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────────────┐  │
│  │ Ingest   │  │ Select   │  │ Generate │  │ Publish        │  │
│  │ (6-hour) │  │ (6-hour) │  │ (daily)  │  │ (after render) │  │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └───────┬────────┘  │
└───────┼──────────────┼─────────────┼────────────────┼───────────┘
        │              │             │                │
        ▼              ▼             ▼                ▼
┌─────────────────────────────────────────────────────────────────┐
│                         PHP APPLICATION                          │
│                                                                  │
│  ┌─────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐ │
│  │ Feed    │  │ Scoring  │  │ Script   │  │ Video Generator  │ │
│  │ Ingest  │  │ Engine   │  │ Writer   │  │ (Interface)      │ │
│  │ Module  │  │          │  │ (Claude) │  │  └─ HeyGen Adapt │ │
│  └────┬────┘  └────┬─────┘  └────┬─────┘  │  └─ Future...   │ │
│       │            │             │         └────────┬─────────┘ │
│       │            │             │                  │           │
│       ▼            ▼             ▼                  ▼           │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                      MySQL Database                       │   │
│  └──────────────────────────────────────────────────────────┘   │
│       │                                          │              │
│       │            ┌──────────────────┐          │              │
│       └───────────►│ Social Publisher  │◄─────────┘              │
│                    │  ├─ YouTube       │                         │
│                    │  ├─ X (Twitter)   │                         │
│                    │  ├─ Reddit        │                         │
│                    │  ├─ Instagram     │                         │
│                    │  └─ Facebook      │                         │
│                    └────────┬─────────┘                         │
└─────────────────────────────┼───────────────────────────────────┘
                              │
                    ┌─────────▼──────────┐
                    │ DigitalOcean Spaces │
                    │ (Video Storage)     │
                    └────────────────────┘
```

### Data flow

1. **Ingest** — Cron triggers feed polling every 6 hours. Raw feed items are normalised and stored in `feed_items` table. Deduplication by CVE ID or content hash.
2. **Score & Select** — After ingestion, each new item is scored for relevance (audience fit, severity, trendiness). Top N items (configurable) are promoted to `content_queue` with status `pending_script`.
3. **Script generation** — Pipeline picks items from queue, sends context to Claude API, receives structured script (narration, visual cues, on-screen text, CTA). Stored in `scripts` table. Status moves to `pending_video`.
4. **Video generation** — Pipeline sends script to video provider (HeyGen) via pluggable adapter. Polls for completion. Downloads rendered video. Uploads to DO Spaces. Status moves to `pending_publish`.
5. **Publish** — Pipeline takes completed videos and posts to each configured social platform via their native APIs. Records post IDs and URLs. Status moves to `published`.

---

## 2. Infrastructure and deployment

### Server

- **Provider:** DigitalOcean
- **Droplet:** Basic, 1GB RAM, 1 vCPU, 25GB SSD ($6/month)
- **OS:** Ubuntu 24.04 LTS
- **Web server:** Apache 2.4 with mod_php (serving admin dashboard only — no public-facing web traffic needed)
- **PHP:** 8.3 (CLI primary, mod_php for admin dashboard)
- **MySQL:** 8.0
- **SSL:** Let's Encrypt via certbot (for admin dashboard)

### Storage

- **Provider:** DigitalOcean Spaces
- **Region:** Same as droplet
- **Bucket name:** `securitydrama-media`
- **Access:** S3-compatible API using `aws/aws-sdk-php` composer package (the only heavy dependency)
- **Purpose:** Store rendered video files, thumbnails, and any generated assets. Videos are typically 10-50MB each.

### PHP dependencies (via Composer)

Keep dependencies minimal. The only required packages are:

```json
{
    "require": {
        "aws/aws-sdk-php": "^3.300"
    }
}
```

All HTTP requests to external APIs (Claude, HeyGen, social platforms) should use PHP's built-in `curl` functions. Do NOT add Guzzle or other HTTP libraries. RSS/XML parsing should use PHP's built-in `SimpleXML` and `DOMDocument`. JSON parsing uses built-in `json_decode`/`json_encode`.

### Memory management

The 1GB RAM constraint means:
- MySQL `innodb_buffer_pool_size` = 256MB
- PHP CLI `memory_limit` = 128MB
- PHP mod_php `memory_limit` = 64MB (set via `php_admin_value` in VirtualHost)
- Apache `MaxRequestWorkers` = 3
- Process only ONE pipeline item at a time (no parallel execution)
- Stream video downloads to disk, never load full video into memory
- Feed ingestion processes one feed source at a time, flushing between each

---

## 3. Database schema

Database name: `securitydrama`

### Table: `feed_sources`

Stores the configuration for each feed we poll.

```sql
CREATE TABLE feed_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    category ENUM('cve','exploit','breach','news','vendor','scam','community') NOT NULL,
    feed_type ENUM('rss','json_api','json_download','html_scrape') NOT NULL,
    url VARCHAR(500) NOT NULL,
    polling_interval_minutes INT UNSIGNED NOT NULL DEFAULT 360,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_polled_at DATETIME NULL,
    last_successful_at DATETIME NULL,
    last_error TEXT NULL,
    items_fetched_total INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active_poll (is_active, last_polled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `feed_items`

Normalised feed items from all sources.

```sql
CREATE TABLE feed_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `content_queue`

Items selected for content production.

```sql
CREATE TABLE content_queue (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `scripts`

Generated video scripts.

```sql
CREATE TABLE scripts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `videos`

Generated video files and metadata.

```sql
CREATE TABLE videos (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `social_posts`

Tracks each post to each social platform.

```sql
CREATE TABLE social_posts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `platform_config`

Per-platform publishing configuration. Each platform can independently select its publishing adapter (direct API, Missinglettr, or disabled). This is the table that drives the "dropdown" in the admin dashboard for selecting adapters per platform.

```sql
CREATE TABLE platform_config (
    platform VARCHAR(20) PRIMARY KEY,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    adapter ENUM('direct','missinglettr','disabled') NOT NULL DEFAULT 'disabled',
    post_type ENUM('native_video','link_to_youtube','text_with_link') NOT NULL DEFAULT 'native_video' COMMENT 'How to post: upload video natively, link to YT, or text+link',
    platform_config_json TEXT NULL COMMENT 'Platform-specific config as JSON (e.g. subreddits for Reddit)',
    max_daily_posts INT UNSIGNED NOT NULL DEFAULT 10,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Seed this table on deploy with the following defaults:

| platform | is_enabled | adapter | post_type | platform_config_json |
|---|---|---|---|---|
| `youtube` | 1 | `direct` | `native_video` | `null` |
| `x` | 1 | `missinglettr` | `native_video` | `null` |
| `reddit` | 1 | `missinglettr` | `link_to_youtube` | `{"subreddits":["cybersecurity","netsec","vibecoding"]}` |
| `instagram` | 1 | `missinglettr` | `native_video` | `null` |
| `facebook` | 1 | `missinglettr` | `native_video` | `null` |
| `tiktok` | 1 | `missinglettr` | `native_video` | `null` |
| `linkedin` | 1 | `missinglettr` | `native_video` | `null` |
| `threads` | 0 | `missinglettr` | `text_with_link` | `null` |
| `bluesky` | 0 | `missinglettr` | `text_with_link` | `null` |
| `mastodon` | 0 | `missinglettr` | `text_with_link` | `null` |
| `pinterest` | 0 | `disabled` | `native_video` | `null` |
```

### Table: `config`

Key-value configuration store (replaces Redis for simple config).

```sql
CREATE TABLE config (
    config_key VARCHAR(100) PRIMARY KEY,
    config_value TEXT NOT NULL,
    description VARCHAR(300) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `pipeline_log`

Audit log for all pipeline operations.

```sql
CREATE TABLE pipeline_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(30) NOT NULL COMMENT 'ingest, score, script, video, publish',
    level ENUM('debug','info','warning','error','critical') NOT NULL,
    message TEXT NOT NULL,
    context TEXT NULL COMMENT 'JSON with additional context',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module_level (module, level, created_at DESC),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Add automatic purge: delete pipeline_log rows older than 30 days via a daily cron.

---

## 4. Application structure

All application code lives under `/var/www/securitydrama/`. The application is NOT a framework — it is a collection of PHP scripts and classes with a shared bootstrap.

### Directory layout

```
/var/www/securitydrama/
├── composer.json
├── composer.lock
├── vendor/                     # Composer autoload (aws-sdk-php only)
├── config/
│   ├── app.php                 # Main config (loaded from env + DB)
│   ├── feeds.php               # Feed source definitions for seeding
│   └── prompts/                # LLM prompt templates
│       ├── cve_alert.txt
│       ├── scam_drama.txt
│       ├── security_101.txt
│       ├── vibe_roast.txt
│       └── breach_story.txt
├── src/
│   ├── Bootstrap.php           # DB connection, autoloader, config loader
│   ├── Database.php            # Thin PDO wrapper (singleton)
│   ├── Config.php              # Read/write config from DB + file
│   ├── Logger.php              # Writes to pipeline_log table
│   ├── HttpClient.php          # Thin curl wrapper for all API calls
│   ├── Storage.php             # DO Spaces upload/download via S3 API
│   ├── Ingest/
│   │   ├── FeedIngester.php        # Main ingestion orchestrator
│   │   ├── Parsers/
│   │   │   ├── RssParser.php       # RSS/Atom XML parsing
│   │   │   ├── JsonApiParser.php   # JSON REST API polling
│   │   │   ├── JsonDownloadParser.php  # JSON file download + diff
│   │   │   └── NvdApiParser.php    # Specialised NVD API client
│   │   └── Normaliser.php         # Normalise all feed items to common schema
│   ├── Scoring/
│   │   └── RelevanceScorer.php    # Score and tag feed items
│   ├── Selection/
│   │   └── ContentSelector.php    # Pick top items for content queue
│   ├── Script/
│   │   ├── ScriptGenerator.php    # Orchestrates script creation
│   │   └── ClaudeClient.php       # Claude API wrapper
│   ├── Video/
│   │   ├── VideoGeneratorInterface.php  # Interface all providers implement
│   │   ├── VideoOrchestrator.php        # Manages generation lifecycle
│   │   └── Adapters/
│   │       └── HeyGenAdapter.php        # HeyGen API implementation
│   ├── Publish/
│   │   ├── SocialPublisher.php         # Orchestrator - reads platform_config, routes to adapters
│   │   ├── PublishAdapterInterface.php  # Interface all publishing adapters implement
│   │   ├── Adapters/
│   │   │   ├── DirectYouTubeAdapter.php # Direct YouTube Data API upload
│   │   │   ├── DirectXAdapter.php       # Direct X/Twitter API (future)
│   │   │   ├── DirectInstagramAdapter.php # Direct Meta Graph API (future)
│   │   │   ├── DirectFacebookAdapter.php  # Direct Facebook Graph API (future)
│   │   │   ├── DirectRedditAdapter.php    # Direct Reddit API (future)
│   │   │   └── MissinglettrAdapter.php    # Missinglettr API — handles all platforms it supports
│   │   └── PlatformFormatter.php       # Formats captions/titles per platform constraints
│   └── Pipeline/
│       └── Orchestrator.php        # Master pipeline runner
│   ├── Reddit/
│   │   ├── RedditCrawler.php          # Discovers relevant threads
│   │   ├── ThreadMatcher.php          # Matches threads to existing videos
│   │   ├── CommentGenerator.php       # Uses Claude to generate contextual comments
│   │   ├── RedditCommenter.php        # Posts comments via Reddit API
│   │   └── EngagementOrchestrator.php # Runs the full engagement cycle
├── cli/
│   ├── ingest.php              # CLI: run feed ingestion
│   ├── score.php               # CLI: run scoring on new items
│   ├── select.php              # CLI: select content for queue
│   ├── generate-script.php     # CLI: generate scripts for queued items
│   ├── generate-video.php      # CLI: trigger video generation
│   ├── poll-video.php          # CLI: check video generation status
│   ├── publish.php             # CLI: publish completed videos
│   ├── pipeline.php            # CLI: run full pipeline cycle
│   ├── reddit-crawl.php        # CLI: discover relevant Reddit threads
│   ├── reddit-engage.php       # CLI: generate and post Reddit comments
│   ├── seed-feeds.php          # CLI: seed feed_sources from config
│   └── purge-logs.php          # CLI: delete old log entries
├── web/
│   ├── index.php               # Admin dashboard entry point
│   ├── api.php                 # Simple API router for dashboard AJAX
│   ├── assets/
│   │   ├── style.css           # Tailwind CSS (CDN linked, not compiled)
│   │   └── app.js              # Vanilla JS for dashboard
│   └── views/
│       ├── layout.php          # HTML layout wrapper
│       ├── dashboard.php       # Overview stats
│       ├── queue.php           # Content queue view
│       ├── feeds.php           # Feed source management
│       ├── videos.php          # Video list and status
│       ├── posts.php           # Social post tracking
│       ├── config.php          # Configuration editor
│       ├── reddit.php          # Reddit engagement monitoring
│       └── logs.php            # Pipeline log viewer
└── .env                        # Environment variables (API keys etc)
```

### Bootstrap (`src/Bootstrap.php`)

Every CLI script and web request starts with:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Bootstrap.php';
```

Bootstrap does:
1. Load `.env` file into `$_ENV`
2. Establish singleton PDO connection to MySQL
3. Register a simple PSR-4 style autoloader for `src/` classes
4. Initialise the Config singleton (merges `.env` with `config` DB table)
5. Initialise the Logger singleton

---

## 5. Module 1: Feed ingestion

### File: `src/Ingest/FeedIngester.php`

The ingester iterates over all active `feed_sources` whose `last_polled_at` is older than their `polling_interval_minutes`. For each source, it:

1. Selects the correct parser based on `feed_type`
2. Fetches data from the URL
3. Parses items using the appropriate parser
4. Normalises each item via `Normaliser.php`
5. Generates a `content_hash` = `SHA-256(title . description)` for deduplication
6. Inserts into `feed_items` (INSERT IGNORE on content_hash unique key)
7. Updates `feed_sources.last_polled_at` and `items_fetched_total`
8. Catches errors per-source, logs them, updates `last_error`, continues to next source

### Parser: `RssParser.php`

Handles RSS 2.0 and Atom feeds. Uses `SimpleXML`. Extracts: title, description/summary, link, pubDate, category tags.

### Parser: `JsonApiParser.php`

Handles REST APIs that return JSON. Configurable per-source via a `response_map` stored in the feed source config that maps JSON paths to normalised fields. Uses `json_decode` and dot-notation path traversal.

### Parser: `NvdApiParser.php`

Specialised parser for the NVD CVE API. Queries:
```
GET https://services.nvd.nist.gov/rest/json/cves/2.0?pubStartDate={last_poll}&pubEndDate={now}&cvssV3Severity=HIGH
```
Then a second query for CRITICAL. Respects rate limit of 5 requests per 30 seconds (sleep 6 seconds between requests). Extracts: CVE ID, description, CVSS score, severity, affected CPE names, references, CISA KEV status.

### Parser: `JsonDownloadParser.php`

Downloads a JSON file (e.g., CISA KEV catalogue), diffs against previous download stored in `/tmp/`, extracts new entries.

### Normaliser (`src/Ingest/Normaliser.php`)

Maps all parsed data to the `feed_items` schema. Key logic:
- `severity` mapping: Map CVSS 9.0-10.0 → critical, 7.0-8.9 → high, 4.0-6.9 → medium, 0.1-3.9 → low, 0 or null → unknown
- `affected_products`: Extract from CPE names, npm package names, or keyword extraction from description. Store as JSON array.
- `audience_tags`: Initial tag based on source category and affected products. If products match known vibe-coder ecosystems (npm, PyPI, Next.js, React, Supabase, Vercel, Node.js, Go, Rust crates, PHP Composer), tag as `vibe_coder`. If the source category is `breach`, `scam`, or description mentions small business / phishing / ransomware / email, tag as `smb`. Default: `general`.

### Feed source seed data

The file `config/feeds.php` returns an array of feed source definitions. On running `cli/seed-feeds.php`, these are upserted into the `feed_sources` table. Include all 53 feeds from the feed sources document, with correct URLs, categories, feed types, and polling intervals.

Group the seeds by priority tier:
- **Tier 1 (6-hourly):** NVD API, OSV.dev, CISA KEV, CVEFeed RSS, Exploit-DB RSS, HIBP breaches RSS, BleepingComputer RSS, The Hacker News RSS
- **Tier 2 (12-hourly):** URLhaus CSV, OpenPhish, SANS ISC RSS, ZDI RSS, Node.js RSS, Chrome releases, AWS bulletins, MSRC blog, CVE Daily RSS
- **Tier 3 (24-hourly):** All remaining news blogs, Reddit feeds, vendor-specific feeds, scam/FTC feeds, DataBreaches.net

---

## 6. Module 2: Content scoring and selection

### File: `src/Scoring/RelevanceScorer.php`

Runs on all `feed_items` where `is_processed = 0`. Calculates a `relevance_score` (0-100) based on:

| Factor | Weight | Logic |
|---|---|---|
| Severity | 30 | critical=30, high=22, medium=12, low=5, unknown=3 |
| Vibe coder relevance | 25 | Affected product matches known vibe-coder ecosystem = 25, partial match = 12 |
| Recency | 15 | Published < 6 hours ago = 15, < 24h = 10, < 48h = 5, older = 0 |
| Source authority | 15 | Tier 1 source = 15, Tier 2 = 10, Tier 3 = 5 |
| Exploitability signal | 15 | In CISA KEV = 15, has exploit-db entry = 10, description mentions "actively exploited" = 8 |

After scoring, set `is_processed = 1`.

### File: `src/Selection/ContentSelector.php`

Runs after scoring. Selects items for the content queue:

1. Read config value `daily_video_target` (default: 2, configurable from 1-10)
2. Count how many items are already in `content_queue` for today (status != 'failed')
3. If count < target, select the top N unqueued `feed_items` by `relevance_score DESC` where `relevance_score >= 40`
4. For each selected item, determine `content_type`:
   - If `external_id` starts with "CVE-" → `cve_alert`
   - If source category is `breach` → `breach_story`
   - If source category is `scam` → `scam_drama`
   - If `audience_tags` contains `vibe_coder` and severity is high/critical → `vibe_roast`
   - Else → `security_101`
5. Determine `target_audience` from `audience_tags` (prefer most specific)
6. Insert into `content_queue` with status `pending_script`

---

## 7. Module 3: Script generation

### File: `src/Script/ScriptGenerator.php`

Processes `content_queue` items with status `pending_script`.

For each item:
1. Update status to `generating_script`
2. Load the appropriate prompt template from `config/prompts/{content_type}.txt`
3. Build the prompt by injecting: feed item title, description, severity, CVE ID (if applicable), affected products, source URL
4. Call Claude API via `ClaudeClient.php`
5. Parse the structured response (Claude must return JSON — enforce via system prompt)
6. Insert into `scripts` table
7. Update `content_queue.script_id` and status to `pending_video`
8. On failure: set status to `failed`, increment `retry_count`, log error

### Prompt template structure

Each prompt file in `config/prompts/` must instruct Claude to return a JSON object with this exact structure:

```json
{
    "narration": "Full voiceover text, 60-90 seconds when read aloud...",
    "hook_line": "Opening line that grabs attention in first 3 seconds",
    "on_screen_text": [
        {"time_seconds": 0, "duration_seconds": 5, "text": "Text overlay content"},
        {"time_seconds": 5, "duration_seconds": 8, "text": "Next overlay"}
    ],
    "visual_direction": "Description of what the video visuals should show",
    "cta": "Call to action text for end of video",
    "hashtags": ["cybersecurity", "vibecheck", "cve2025"],
    "title_youtube": "YouTube title (max 100 chars)",
    "title_social": "Social media caption (max 280 chars for X)",
    "description_youtube": "YouTube description with links and context"
}
```

The system prompt for all script generation calls should include:
- The brand voice description: dramatic, urgent, accessible to non-technical audiences, educational but entertaining
- Audience context (vibe coder vs SMB)
- Format constraints (60-90 seconds narration, specific JSON output format)
- Instruction to include a concrete actionable takeaway
- Instruction to NOT use technical jargon the target audience wouldn't understand

### File: `src/Script/ClaudeClient.php`

Thin wrapper around the Anthropic Messages API.

```
POST https://api.anthropic.com/v1/messages
Headers:
  x-api-key: {ANTHROPIC_API_KEY}
  anthropic-version: 2023-06-01
  content-type: application/json
Body:
  model: claude-sonnet-4-20250514
  max_tokens: 2000
  system: {system_prompt}
  messages: [{role: "user", content: {user_prompt}}]
```

Parse `response.content[0].text` to extract the JSON. Use `claude-sonnet-4-20250514` (cost-effective for script generation). Store token usage from `response.usage` for cost tracking.

---

## 8. Module 4: Video generation (pluggable)

### Interface: `src/Video/VideoGeneratorInterface.php`

```php
<?php
interface VideoGeneratorInterface
{
    /**
     * Submit a video generation job.
     * Returns a provider-specific job ID.
     */
    public function submitJob(array $scriptData, array $options): string;

    /**
     * Check the status of a submitted job.
     * Returns: ['status' => 'pending|processing|completed|failed', 'video_url' => '...', 'error' => '...']
     */
    public function checkStatus(string $jobId): array;

    /**
     * Download the completed video to a local temp file.
     * Returns the local file path.
     * MUST stream to disk (not load into memory).
     */
    public function downloadVideo(string $videoUrl, string $localPath): bool;

    /**
     * Get the provider name identifier.
     */
    public function getProviderName(): string;
}
```

### File: `src/Video/VideoOrchestrator.php`

Manages the video generation lifecycle:

1. Picks `content_queue` items with status `pending_video`
2. Loads the associated script
3. Instantiates the configured video adapter (read `video_provider` from config table)
4. Calls `submitJob()` with script data
5. Stores the provider job ID in `videos` table with status reflecting provider status
6. Updates queue status to `generating_video`

Separately, `cli/poll-video.php` runs on a cron and:
1. Finds all `videos` with `provider_status` in ('pending', 'processing')
2. Calls `checkStatus()` on each
3. If completed: downloads video to `/tmp/`, uploads to DO Spaces via `Storage.php`, updates video record with storage URL and metadata
4. Updates queue status to `pending_publish`
5. If failed: updates queue to `failed` with reason

### Adapter: `src/Video/Adapters/HeyGenAdapter.php`

Implements `VideoGeneratorInterface` using HeyGen's API.

**Primary approach: Template-based generation.**

Before using the API, the operator must create a video template in HeyGen's web interface with:
- A branded intro/outro
- A placeholder variable `{{script}}` for the narration
- A selected avatar and voice
- Desired aspect ratio (9:16 for Shorts/Reels, 16:9 for YouTube)
- Subtitle/caption styling

The template ID is stored in config as `heygen_template_id`.

**API calls:**

1. Generate video from template:
```
POST https://api.heygen.com/v2/template/{template_id}/generate
Headers:
  x-api-key: {HEYGEN_API_KEY}
  content-type: application/json
Body:
  {
    "test": false,
    "caption": true,
    "title": "{video_title}",
    "variables": {
      "script": {
        "name": "script",
        "type": "text",
        "properties": {"content": "{narration_text}"}
      }
    }
  }
```
Returns `data.video_id`.

2. Check status:
```
GET https://api.heygen.com/v1/video_status.get?video_id={video_id}
Headers:
  x-api-key: {HEYGEN_API_KEY}
```
Poll every 60 seconds. Status values: `pending`, `processing`, `completed`, `failed`.
When `completed`, response includes `data.video_url`.

3. Download: Stream from `video_url` to local temp file using curl with `CURLOPT_FILE`.

**Alternative approach: Direct avatar video (no template).**

If template is not configured, fall back to:
```
POST https://api.heygen.com/v2/video/generate
Headers:
  x-api-key: {HEYGEN_API_KEY}
Body:
  {
    "video_inputs": [{
      "character": {
        "type": "avatar",
        "avatar_id": "{config.heygen_avatar_id}",
        "avatar_style": "normal"
      },
      "voice": {
        "type": "text",
        "input_text": "{narration_text}",
        "voice_id": "{config.heygen_voice_id}"
      }
    }],
    "dimension": {"width": 1080, "height": 1920},
    "aspect_ratio": "9:16",
    "test": false
  }
```

---

## 9. Module 5: Social media publishing (pluggable per-platform)

### Architecture overview

Social publishing uses a **pluggable adapter pattern per platform**. Each platform (YouTube, X, Instagram, etc.) independently selects which publishing adapter to use. This is configured in the `platform_config` database table and editable via a dropdown in the admin dashboard.

Available adapters:
- **`direct`** — Uses the platform's own API. Requires platform-specific OAuth credentials. Best for platforms where native video upload is critical (YouTube Shorts).
- **`missinglettr`** — Routes through the Missinglettr API, which handles auth, formatting, and posting to 12 platforms via a single API call. Ideal for most platforms, especially at launch.
- **`disabled`** — Platform is configured but not active. No posts are created.

This means you can start with YouTube on `direct` and everything else on `missinglettr`, then gradually migrate individual platforms to `direct` adapters as needed — without changing any pipeline code.

### Interface: `src/Publish/PublishAdapterInterface.php`

```php
<?php
interface PublishAdapterInterface
{
    /**
     * Publish content to a platform.
     *
     * @param string $platform Platform identifier (youtube, x, reddit, etc.)
     * @param array $videoData ['local_path' => '...', 'storage_url' => '...', 'duration' => N, 'aspect_ratio' => '9:16']
     * @param array $contentData ['title' => '...', 'description' => '...', 'caption' => '...', 'hashtags' => [...], 'youtube_url' => '...' or null]
     * @param array $platformConfig Platform-specific config from platform_config_json
     * @return array ['success' => bool, 'post_id' => '...', 'post_url' => '...', 'error' => '...']
     */
    public function publish(string $platform, array $videoData, array $contentData, array $platformConfig): array;

    /**
     * Get the adapter name identifier.
     */
    public function getAdapterName(): string;

    /**
     * Check if this adapter supports the given platform.
     */
    public function supportsPlatform(string $platform): bool;
}
```

### File: `src/Publish/SocialPublisher.php`

The orchestrator reads `platform_config` and routes each platform to the correct adapter:

1. Gets `content_queue` items with status `pending_publish`
2. For each, reads the `videos` record and associated `scripts` record
3. Queries `platform_config` for all rows where `is_enabled = 1` and `adapter != 'disabled'`
4. Downloads the video from DO Spaces to `/tmp/` (once per video, shared across all platforms)
5. For each enabled platform:
   a. Creates a `social_posts` record with status `pending` and the adapter name
   b. Instantiates the correct adapter based on `platform_config.adapter`
   c. Builds `contentData` using `PlatformFormatter.php` to respect per-platform character limits
   d. Determines the correct `post_type` — if `link_to_youtube`, waits for YouTube post to complete first and uses its URL
   e. Calls `adapter->publish()`
   f. Updates `social_posts` with result
6. After all platforms complete, updates queue status to `published`
7. Deletes the local temp video file

**Platform ordering matters:** Always publish to YouTube FIRST (if enabled) so that `link_to_youtube` post types for other platforms have a YouTube URL to reference.

### File: `src/Publish/PlatformFormatter.php`

Formats content to respect per-platform constraints:

| Platform | Max Caption Length | Title Supported | Hashtag Format |
|---|---|---|---|
| youtube | 5000 (description) | 100 chars | In description |
| x | 280 (4000 Premium) | No (text only) | Inline #hashtags |
| reddit | 300 (title) | Yes | No hashtags |
| instagram | 2200 | No (caption only) | Inline #hashtags |
| facebook | 63206 | Yes | Inline #hashtags |
| tiktok | 4000 | No (caption only) | Inline #hashtags |
| linkedin | 3000 | No (text only) | Inline #hashtags |
| threads | 500 | No (text only) | Inline #hashtags |
| bluesky | 300 | No (text only) | No hashtags |
| mastodon | 500 | No (text only) | Inline #hashtags |
| pinterest | 500 | Yes (100 chars) | No hashtags (use keywords) |

The formatter takes the script's `title_youtube`, `title_social`, `description_youtube`, `hook_line`, and `hashtags` and produces platform-specific `title`, `caption/description`, and `hashtag_string` for each platform.

### Adapter: `src/Publish/Adapters/MissinglettrAdapter.php`

Handles publishing to any platform supported by the Missinglettr API. One adapter, many platforms.

**API base URL:** `https://api.missinglettr-api.com/api/v1`
**Auth:** Bearer token via `X-API-Key` header
**Docs:** https://docs.missinglettr-api.com

**Supported platforms:** twitter, linkedin, facebook, instagram, pinterest, tiktok, youtube, threads, reddit, google_business, mastodon, bluesky

Process:
1. Determine `post_type` from `platform_config`:
   - `native_video`: Include `media_url` pointing to the DO Spaces CDN URL of the video. Missinglettr handles the upload to the platform.
   - `link_to_youtube`: Post text + YouTube URL. No video upload needed.
   - `text_with_link`: Post caption text with a link to the YouTube video. For platforms that don't support video via Missinglettr.
2. Make API call:
```
POST https://api.missinglettr-api.com/api/v1/posts
Headers:
  X-API-Key: {MISSINGLETTR_API_KEY}
  Content-Type: application/json
Body:
  {
    "text": "{formatted_caption}",
    "platforms": ["{platform_id}"],
    "media_url": "{DO_Spaces_CDN_URL}",  // for native_video
    "link": "{youtube_url}",              // for link_to_youtube
    "schedule_type": "immediate",
    "workspace_id": "{MISSINGLETTR_WORKSPACE_ID}"
  }
```
3. Parse response for post ID and confirmation
4. Return result to SocialPublisher

**Batch posting optimisation:** When multiple platforms all use the Missinglettr adapter, group them into a single API call using the `platforms` array (e.g., `["twitter", "linkedin", "facebook", "reddit"]`) rather than making separate calls per platform. This reduces API usage and ensures consistent posting times.

**Note on Missinglettr free tier:** 500 posts/month covers approximately 2 videos/day across 8 platforms. If `daily_video_target` exceeds 2, the Pro plan ($49/month) will be needed for 10,000 posts/month.

### Adapter: `src/Publish/Adapters/DirectYouTubeAdapter.php`

Direct upload to YouTube using YouTube Data API v3. This is the only platform that MUST use a direct adapter at launch because YouTube Shorts require native video upload for algorithmic reach.

**API:** YouTube Data API v3
**Auth:** OAuth 2.0 with offline refresh token (obtained once via manual flow)
**Upload endpoint:** `POST https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status`

Process:
1. Refresh OAuth access token using stored refresh token via `POST https://oauth2.googleapis.com/token`
2. Initiate resumable upload with metadata (title, description, tags, categoryId=28 for Science & Technology, privacy=public)
3. Upload video file in chunks (5MB chunks to stay within memory limits)
4. Set video to YouTube Shorts by including `#Shorts` in title/description (for 9:16 videos under 60s)
5. Optionally upload custom thumbnail via `POST https://www.googleapis.com/upload/youtube/v3/thumbnails/set`
6. Return `['success' => true, 'post_id' => $videoId, 'post_url' => 'https://youtube.com/shorts/' . $videoId]`

**Important:** YouTube API has a daily quota of 10,000 units. Each video upload costs 1,600 units. This limits uploads to ~6 videos/day. Factor this into `daily_video_target` max.

### Future direct adapters

These adapters are NOT built at launch but the interface supports them. When the time comes, create a new class implementing `PublishAdapterInterface` and change the platform's adapter value in `platform_config` from `missinglettr` to `direct`.

Stub files with a `throw new \RuntimeException('Direct adapter not yet implemented for ' . $platform)` should be created for: `DirectXAdapter.php`, `DirectInstagramAdapter.php`, `DirectFacebookAdapter.php`, `DirectRedditAdapter.php`. The technical details for each direct integration are preserved below for future reference.

**X (Twitter) direct adapter (future):**
- API: X API v2. Auth: OAuth 1.0a.
- Chunked media upload: `POST https://upload.twitter.com/1.1/media/upload.json` (INIT/APPEND/FINALIZE)
- Create tweet: `POST https://api.x.com/2/tweets`
- Docs: https://developer.x.com/en/docs, https://docs.x.com/x-api/media/quickstart/media-upload-chunked

**Instagram direct adapter (future):**
- API: Instagram Graph API. Auth: Long-lived Page Access Token.
- Create Reels container: `POST https://graph.facebook.com/v21.0/{ig_user_id}/media` with `video_url`, `caption`, `media_type=REELS`
- Poll then publish: `POST https://graph.facebook.com/v21.0/{ig_user_id}/media_publish`
- Docs: https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/content-publishing#reels

**Facebook direct adapter (future):**
- API: Facebook Graph API. Auth: Long-lived Page Access Token.
- Upload: `POST https://graph-video.facebook.com/v21.0/{page_id}/videos`
- Docs: https://developers.facebook.com/docs/video-api/guides/publishing

**Reddit direct adapter (future):**
- API: Reddit API. Auth: OAuth 2.0 (script app type).
- Submit: `POST https://oauth.reddit.com/api/submit`
- Docs: https://www.reddit.com/dev/api/

---

## 10. Module 6: Pipeline orchestrator

### File: `src/Pipeline/Orchestrator.php`

The master pipeline script (`cli/pipeline.php`) runs the full cycle:

```php
<?php
// 1. Ingest new feed items
$ingester = new FeedIngester();
$ingester->run();

// 2. Score unprocessed items
$scorer = new RelevanceScorer();
$scorer->run();

// 3. Select items for content queue
$selector = new ContentSelector();
$selector->run();

// 4. Generate scripts for pending items
$scriptGen = new ScriptGenerator();
$scriptGen->run();

// 5. Submit video generation jobs
$videoOrch = new VideoOrchestrator();
$videoOrch->submitPending();

// 6. Poll video generation status
$videoOrch->pollInProgress();

// 7. Publish completed videos
$publisher = new SocialPublisher();
$publisher->run();
```

Each step handles its own errors independently. A failure in step 4 does not prevent step 6 from completing previously started videos.

The orchestrator also enforces the `daily_video_target` config. If the target for today has been met (counting videos with status `published` or earlier in the pipeline for today), the selection step is skipped.

---

## 11. Module 7: Admin dashboard

### Technology

- **Server:** Apache 2.4 with mod_php
- **CSS:** Tailwind CSS via CDN link (`<script src="https://cdn.tailwindcss.com"></script>`)
- **JS:** Vanilla JavaScript. Fetch API for AJAX calls to `web/api.php`.
- **Auth:** Simple HTTP Basic Auth configured via `.htaccess`. Credentials in `.htpasswd`.

### Pages

**Dashboard (`web/views/dashboard.php`):**
- Total feed items ingested (today / all time)
- Content queue status breakdown (pending_script / generating_video / published / failed)
- Videos generated today vs target
- Social posts by platform (posted / failed)
- Last 10 pipeline log entries

**Queue (`web/views/queue.php`):**
- Table of content_queue items with status, content_type, feed item title, timestamps
- Filter by status
- Click to view full detail (associated script, video, social posts)

**Feeds (`web/views/feeds.php`):**
- Table of feed_sources with last polled time, success/error status, item count
- Toggle active/inactive
- Manual "poll now" button (triggers AJAX call that runs ingest for that source)

**Videos (`web/views/videos.php`):**
- List of generated videos with status, provider, duration, storage link
- Preview player (embed video from DO Spaces URL)

**Posts (`web/views/posts.php`):**
- List of social_posts with platform, status, post URL
- Filter by platform

**Config (`web/views/config.php`):**
- Edit all configurable values from the `config` table
- **Platform publishing section:** For each row in `platform_config`, show:
  - Platform name and icon
  - Toggle switch for `is_enabled`
  - Dropdown to select `adapter` (direct / missinglettr / disabled). Only show `direct` option if the corresponding direct adapter class exists and is implemented (not a stub). Grey out and show "Coming soon" for stub adapters.
  - Dropdown to select `post_type` (native_video / link_to_youtube / text_with_link)
  - Editable JSON field for `platform_config_json` (e.g., Reddit subreddits)
  - `max_daily_posts` number input
- Key general configs: `daily_video_target`, `video_provider`, `heygen_template_id`, `heygen_avatar_id`, `heygen_voice_id`

**Logs (`web/views/logs.php`):**
- Searchable/filterable pipeline_log viewer
- Filter by module, level, date range

### API router (`web/api.php`)

Simple router that maps URL patterns to actions:

```
GET  /api/dashboard-stats     → returns JSON stats
GET  /api/queue?status=...    → returns queue items
POST /api/feeds/{id}/toggle   → toggle feed active/inactive
POST /api/feeds/{id}/poll     → trigger manual poll
GET  /api/config              → list all config
POST /api/config              → update config values
GET  /api/logs?module=...     → filtered log entries
```

---

## 12. Module 8: Reddit engagement crawler

### Purpose

This module monitors Reddit for posts and comments where people are discussing security topics that match videos we've already produced. It generates contextual, genuinely helpful comments that include a link to the relevant video. The goal is organic traffic growth through authentic community participation — not spam.

**Critical constraint:** Reddit has a zero-tolerance policy for spam and self-promotion bots. This module must behave like a knowledgeable human community member who happens to have a relevant resource. Comments must add standalone value even without the link. The system uses Claude to generate unique, contextual replies that directly address the poster's question or concern, with the video link offered as supplementary context. If Claude determines no helpful comment can be made, the thread is skipped.

### Database tables

#### Table: `reddit_watch_keywords`

Keywords and package names to monitor across Reddit. Seeded from known vibe-coder ecosystems and common security terms.

```sql
CREATE TABLE reddit_watch_keywords (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    keyword VARCHAR(100) NOT NULL,
    keyword_type ENUM('package','cve','topic','technology') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_keyword (keyword)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Seed with: known vibe-coder packages (next.js, supabase, lovable, cursor, react, node.js, express, django, flask, fastapi, etc.), common vulnerability terms (sql injection, xss, csrf, rce, ransomware, phishing, data breach, api key leaked, .env exposed, etc.), and tool names (cursor, replit, bolt, windsurf, v0, etc.).

#### Table: `reddit_threads`

Tracks threads we've discovered and their engagement status.

```sql
CREATE TABLE reddit_threads (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### File structure

```
src/Reddit/
├── RedditCrawler.php          # Discovers relevant threads
├── ThreadMatcher.php          # Matches threads to existing videos
├── CommentGenerator.php       # Uses Claude to generate contextual comments
├── RedditCommenter.php        # Posts comments via Reddit API
└── EngagementOrchestrator.php # Runs the full engagement cycle

cli/
├── reddit-crawl.php           # CLI: discover new threads
├── reddit-engage.php          # CLI: generate and post comments
```

### File: `src/Reddit/RedditCrawler.php`

Scans configured subreddits for posts mentioning watched keywords.

**Subreddits to monitor** (configurable in `config` table as `reddit_monitor_subreddits`):
- `vibecoding`, `webdev`, `node`, `reactjs`, `nextjs`, `python`, `django`, `flask`
- `cybersecurity`, `netsec`, `hacking`, `AskNetsec`, `sysadmin`
- `smallbusiness`, `Entrepreneur`, `startups`
- `Scams`, `personalfinance` (for scam/phishing threads)

**Crawling process:**
1. For each monitored subreddit, fetch recent posts via Reddit API:
   ```
   GET https://oauth.reddit.com/r/{subreddit}/new?limit=50
   ```
   Also search within each subreddit for specific keywords:
   ```
   GET https://oauth.reddit.com/r/{subreddit}/search?q={keyword}&restrict_sr=on&sort=new&t=day&limit=25
   ```
2. For each post, check title and body text against `reddit_watch_keywords`
3. Filter out posts that are:
   - Older than 48 hours (stale threads get no engagement)
   - Already in `reddit_threads` table (dedup by `reddit_post_id`)
   - From subreddits where we've already commented today (avoid appearing spammy in one community)
   - Authored by our own account
   - Flaired as "mod" or "announcement" (don't reply to official threads)
4. Insert matching posts into `reddit_threads` with status `discovered`
5. Respect Reddit API rate limits: max 60 requests per minute. Sleep 1 second between requests. Use `X-Ratelimit-Remaining` and `X-Ratelimit-Reset` headers to pace.

### File: `src/Reddit/ThreadMatcher.php`

Matches discovered threads to videos in our library.

1. Query `reddit_threads` where status = `discovered`
2. For each thread, search our `videos` table (via associated `feed_items` and `scripts`) for content that matches the thread's keywords:
   - Match by CVE ID: if the thread mentions a specific CVE (regex pattern `CVE-\d{4}-\d{4,}`) and we have a video about that CVE
   - Match by package/technology: if the thread discusses a package and we have a video about a vulnerability in that package
   - Match by topic: if the thread discusses a broad topic (phishing, ransomware, password security) and we have a relevant security 101 or scam dramatisation video
3. Scoring — prefer matches where:
   - Exact CVE ID match (strongest signal — score 100)
   - Exact package name match with active vulnerability (score 80)
   - Topic/keyword overlap with 3+ terms (score 50)
   - Single keyword match (score 20 — likely too weak, mark as `skipped`)
4. If a match with score >= 40 is found, set `matched_video_id` and update status to `evaluating`
5. If no match, set status to `skipped` with `skip_reason = 'no matching video'`

### File: `src/Reddit/CommentGenerator.php`

Uses Claude to generate a contextual, helpful comment.

1. Query `reddit_threads` where status = `evaluating`
2. For each thread, build a prompt for Claude containing:
   - The Reddit post title and body
   - The subreddit name (for tone matching)
   - The matched video's script narration (so Claude knows what the video covers)
   - The video's YouTube URL (to include as a link)
3. System prompt instructs Claude to:
   - **Act as a knowledgeable security community member**, not a marketer
   - **Directly address the poster's question or concern first** with a substantive, helpful answer
   - **Naturally weave in the video link** as a supplementary resource, NOT as the main point of the comment
   - **Match the subreddit's tone** (casual for r/vibecoding, more technical for r/netsec, empathetic for r/Scams)
   - **Never use phrases** like "check out my video", "I made a video about this", "shameless plug", or any other obvious self-promotion language. Instead use phrases like "there's a good breakdown of this here", "this video covers the technical details", "found this helpful when I was looking into the same thing"
   - **Return a JSON response** with `{"comment": "...", "should_post": true/false, "reason": "..."}`. Set `should_post: false` if Claude determines no genuinely helpful comment can be made (e.g., the thread is a meme post, the video is only tangentially related, or the thread already has a comprehensive answer)
   - **Keep comments to 2-4 sentences.** Reddit users ignore walls of text from unknown accounts.
   - **Never mention our brand name.** The comment should feel anonymous.
4. If `should_post` is true: store the comment text, update status to `approved`
5. If `should_post` is false: update status to `skipped` with the reason

### File: `src/Reddit/RedditCommenter.php`

Posts approved comments to Reddit.

1. Query `reddit_threads` where status = `approved`
2. For each thread:
   - Verify the thread is still active (not locked, not deleted) by fetching its current state
   - Post comment via Reddit API:
     ```
     POST https://oauth.reddit.com/api/comment
     Body: thing_id={reddit_post_id}&text={comment_text}
     ```
   - Store the returned comment ID in `reddit_comment_id`
   - Update status to `commented`
3. **Rate limiting is critical:**
   - Maximum comments per day: configurable via `reddit_max_comments_daily` (default: 5, max: 15)
   - Minimum gap between comments: 30 minutes (configurable via `reddit_comment_interval_minutes`)
   - Maximum 1 comment per subreddit per day (avoid appearing as a spam bot targeting one community)
   - Never comment on the same thread twice
4. On failure (403 forbidden, rate limited, thread locked): update status to `failed` with reason

### File: `src/Reddit/EngagementOrchestrator.php`

Runs the full engagement cycle:

```php
<?php
// 1. Check daily comment budget
$todayCount = $this->getCommentsPostedToday();
$dailyMax = Config::get('reddit_max_comments_daily', 5);
if ($todayCount >= $dailyMax) {
    Logger::info('reddit', "Daily comment limit reached ($todayCount/$dailyMax)");
    return;
}

// 2. Crawl for new threads
$crawler = new RedditCrawler();
$crawler->run();

// 3. Match threads to videos
$matcher = new ThreadMatcher();
$matcher->run();

// 4. Generate comments for matched threads
$generator = new CommentGenerator();
$generator->run();

// 5. Post approved comments (respecting rate limits)
$commenter = new RedditCommenter();
$commenter->run($dailyMax - $todayCount);
```

### Configuration values

Add these to the `config` table seed:

| Key | Default Value | Description |
|---|---|---|
| `reddit_engagement_enabled` | `0` | Master switch for engagement crawler (start disabled, enable after account has karma) |
| `reddit_monitor_subreddits` | `vibecoding,webdev,reactjs,nextjs,cybersecurity,netsec,AskNetsec,smallbusiness,Scams` | Comma-separated subreddits to monitor |
| `reddit_max_comments_daily` | `5` | Maximum comments to post per day |
| `reddit_comment_interval_minutes` | `30` | Minimum minutes between comments |
| `reddit_max_thread_age_hours` | `48` | Don't comment on threads older than this |
| `reddit_min_match_score` | `40` | Minimum keyword match score to consider a thread |

### Anti-spam safeguards

These are non-configurable hard limits baked into the code:

1. **Account karma gating:** The module should check the Reddit account's karma before posting. If comment karma is below 100, log a warning and skip posting. This prevents a brand new account from immediately self-promoting.
2. **Subreddit daily cap:** Never post more than 1 comment per subreddit per day, regardless of `reddit_max_comments_daily`.
3. **Thread freshness check:** Always re-fetch the thread before commenting. If it's been locked, deleted, or has more than 200 comments (we'd get buried), skip it.
4. **No duplicate video linking:** Never link the same video URL in more than 2 comments within a 7-day window. Linking the same URL repeatedly is a classic spam signal.
5. **Human-readable comment history:** The admin dashboard should show all posted comments with the thread context, so the operator can review tone and quality. Include a "Pause engagement" button that sets `reddit_engagement_enabled` to 0 instantly.
6. **Gradual ramp-up:** When first enabled, the system should start at 1 comment/day for the first week, 2/day for the second week, and only reach the configured max after 3 weeks. This mimics organic account behaviour.

### Dashboard additions

Add a **Reddit Engagement** page (`web/views/reddit.php`) to the admin dashboard:

- Summary stats: threads discovered today, matched, commented, skipped
- Table of `reddit_threads` with status, subreddit, matched video, comment preview
- Filter by status and subreddit
- Click to expand: see full thread title/body, matched keywords, generated comment text, and link to the Reddit thread
- "Pause all engagement" emergency button
- Watch keywords management: add/remove/toggle keywords

---

## 13. Configuration system

### `.env` file (secrets — never committed to git)

```env
# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=securitydrama
DB_USER=securitydrama
DB_PASS=

# Anthropic (Claude)
ANTHROPIC_API_KEY=

# HeyGen
HEYGEN_API_KEY=

# Missinglettr API
MISSINGLETTR_API_KEY=
MISSINGLETTR_WORKSPACE_ID=

# DigitalOcean Spaces
DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_REGION=sgp1
DO_SPACES_BUCKET=securitydrama-media
DO_SPACES_ENDPOINT=https://sgp1.digitaloceanspaces.com
DO_SPACES_CDN_URL=https://securitydrama-media.sgp1.cdn.digitaloceanspaces.com

# YouTube (Direct adapter — only platform requiring direct API at launch)
YOUTUBE_CLIENT_ID=
YOUTUBE_CLIENT_SECRET=
YOUTUBE_REFRESH_TOKEN=

# X / Twitter (Reserved for future direct adapter)
X_CONSUMER_KEY=
X_CONSUMER_SECRET=
X_ACCESS_TOKEN=
X_ACCESS_TOKEN_SECRET=

# Reddit (Reserved for future direct adapter)
REDDIT_CLIENT_ID=
REDDIT_CLIENT_SECRET=
REDDIT_USERNAME=
REDDIT_PASSWORD=

# Instagram / Facebook / Meta (Reserved for future direct adapter)
META_PAGE_ACCESS_TOKEN=
META_IG_USER_ID=
META_PAGE_ID=

# Admin Dashboard
ADMIN_USER=admin
ADMIN_PASS=
```

### `config` table (runtime settings — editable via dashboard)

Seed these on first deploy:

| Key | Default Value | Description |
|---|---|---|
| `daily_video_target` | `2` | Number of videos to produce per day (1-10) |
| `video_provider` | `heygen` | Active video generation adapter |
| `video_aspect_ratio` | `9:16` | Default aspect ratio |
| `heygen_template_id` | `` | HeyGen template ID (set after creating template) |
| `heygen_avatar_id` | `` | Fallback avatar ID if not using template |
| `heygen_voice_id` | `` | Fallback voice ID if not using template |
| `min_relevance_score` | `40` | Minimum score for content selection |
| `max_retry_count` | `3` | Max retries for failed pipeline items |
| `video_poll_interval_seconds` | `60` | How often to check video gen status |
| `log_retention_days` | `30` | Days to keep pipeline logs |
| `pipeline_enabled` | `1` | Master kill switch (0 = pause all automation) |

**Note:** Per-platform publishing config (which adapter, enabled/disabled, post type) is managed in the `platform_config` table, NOT in the `config` table. This keeps platform settings structured rather than as arbitrary key-value pairs.

---

## 14. Cron schedule

All cron entries use `cli/` scripts. Set up via `crontab -e` for the `www-data` user (or a dedicated app user).

```crontab
# Feed ingestion - every 6 hours
0 */6 * * * /usr/bin/php /var/www/securitydrama/cli/ingest.php >> /var/log/securitydrama/ingest.log 2>&1

# Scoring and selection - 15 minutes after ingestion
15 */6 * * * /usr/bin/php /var/www/securitydrama/cli/score.php >> /var/log/securitydrama/score.log 2>&1
20 */6 * * * /usr/bin/php /var/www/securitydrama/cli/select.php >> /var/log/securitydrama/select.log 2>&1

# Script generation - every 2 hours (picks up newly selected items)
30 */2 * * * /usr/bin/php /var/www/securitydrama/cli/generate-script.php >> /var/log/securitydrama/script.log 2>&1

# Video generation submit - every 2 hours (picks up items with scripts)
45 */2 * * * /usr/bin/php /var/www/securitydrama/cli/generate-video.php >> /var/log/securitydrama/video.log 2>&1

# Video status polling - every 5 minutes (checks if renders are complete)
*/5 * * * * /usr/bin/php /var/www/securitydrama/cli/poll-video.php >> /var/log/securitydrama/poll.log 2>&1

# Publishing - every 30 minutes (publishes completed videos)
*/30 * * * * /usr/bin/php /var/www/securitydrama/cli/publish.php >> /var/log/securitydrama/publish.log 2>&1

# Reddit engagement - crawl for threads every 4 hours
0 */4 * * * /usr/bin/php /var/www/securitydrama/cli/reddit-crawl.php >> /var/log/securitydrama/reddit-crawl.log 2>&1

# Reddit engagement - generate and post comments every 2 hours (respects daily limits internally)
30 */2 * * * /usr/bin/php /var/www/securitydrama/cli/reddit-engage.php >> /var/log/securitydrama/reddit-engage.log 2>&1

# Log purge - daily at 3am
0 3 * * * /usr/bin/php /var/www/securitydrama/cli/purge-logs.php >> /var/log/securitydrama/purge.log 2>&1
```

All CLI scripts must check `config.pipeline_enabled` at the start and exit immediately if set to 0.

The Reddit engagement scripts (`reddit-crawl.php`, `reddit-engage.php`) must additionally check `config.reddit_engagement_enabled` and exit if set to 0. This allows the main pipeline to continue running while Reddit engagement is paused independently.

All CLI scripts must acquire a file lock (`/tmp/securitydrama_{module}.lock`) to prevent overlapping execution. Use `flock()` in PHP.

---

## 15. Third-party API integrations

### Anthropic Claude API (Script Generation)

- **Docs:** https://docs.anthropic.com/en/api/messages
- **Endpoint:** `POST https://api.anthropic.com/v1/messages`
- **Auth:** `x-api-key` header
- **Model:** `claude-sonnet-4-20250514`
- **Rate limits:** Varies by plan. Default tier: 50 requests/minute, 40,000 tokens/minute

### HeyGen API (Video Generation)

- **Docs:** https://docs.heygen.com/
- **API Reference:** https://docs.heygen.com/reference
- **Key endpoints:**
  - List templates: `GET https://api.heygen.com/v2/templates`
  - Get template details: `GET https://api.heygen.com/v2/template/{id}`
  - Generate from template: `POST https://api.heygen.com/v2/template/{id}/generate`
  - Generate avatar video: `POST https://api.heygen.com/v2/video/generate`
  - Check video status: `GET https://api.heygen.com/v1/video_status.get?video_id={id}`
  - List avatars: `GET https://api.heygen.com/v2/avatars`
  - List voices: `GET https://api.heygen.com/v2/voices`
- **Auth:** `x-api-key` header
- **Credit costs:** ~2 credits/minute for standard video, varies by avatar type

### YouTube Data API v3 (Video Upload)

- **Docs:** https://developers.google.com/youtube/v3/docs
- **Upload guide:** https://developers.google.com/youtube/v3/guides/uploading_a_video
- **Resumable upload:** https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol
- **Endpoint:** `POST https://www.googleapis.com/upload/youtube/v3/videos`
- **Auth:** OAuth 2.0 (authorization code flow, offline access for refresh token)
- **Quota:** 10,000 units/day. Upload costs 1,600 units. Max ~6 uploads/day.

### X (Twitter) API v2 (Tweet with Video)

- **Docs:** https://developer.x.com/en/docs
- **Media upload:** https://developer.x.com/en/docs/twitter-api/v1/media/upload-media/api-reference/post-media-upload
- **Create tweet:** https://developer.x.com/en/docs/twitter-api/tweets/manage-tweets/api-reference/post-tweets
- **Auth:** OAuth 1.0a (consumer key/secret + access token/secret)
- **Rate limits:** Varies by access level. Free tier: 1,500 tweets/month (50/day effective)

### Reddit API (Submit Post)

- **Docs:** https://www.reddit.com/dev/api/
- **Auth:** OAuth 2.0 (script app type)
- **Endpoint:** `POST https://oauth.reddit.com/api/submit`
- **Rate limits:** 60 requests/minute, rate-limit headers in response

### Instagram Graph API (Reels Upload)

- **Docs:** https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/content-publishing
- **Reels guide:** https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/content-publishing#reels
- **Auth:** Long-lived Page Access Token (60-day expiry, auto-refresh required)
- **Requirements:** Instagram Professional account linked to Facebook Page. App must pass Meta App Review for `instagram_content_publish` permission.

### Facebook Graph API (Video Upload)

- **Docs:** https://developers.facebook.com/docs/video-api/guides/publishing
- **Endpoint:** `POST https://graph-video.facebook.com/v21.0/{page_id}/videos`
- **Auth:** Long-lived Page Access Token with `publish_video` permission
- **Requirements:** Facebook Page. App must pass Meta App Review.

### DigitalOcean Spaces API (S3-compatible Object Storage)

- **Docs:** https://docs.digitalocean.com/products/spaces/reference/s3-sdk-examples/
- **SDK:** `aws/aws-sdk-php` with custom endpoint configuration
- **Auth:** Access Key + Secret Key (generated in DO control panel)

### NVD CVE API (Feed Ingestion)

- **Docs:** https://nvd.nist.gov/developers/vulnerabilities
- **Endpoint:** `GET https://services.nvd.nist.gov/rest/json/cves/2.0`
- **Auth:** None required (optional API key for higher rate limits)
- **Rate limits:** 5 requests per 30-second window (no key), 50 per 30s (with key)

### OSV.dev API (Feed Ingestion)

- **Docs:** https://google.github.io/osv.dev/
- **Endpoint:** `POST https://api.osv.dev/v1/query`
- **Auth:** None required
- **Rate limits:** Not documented, be respectful (max 1 req/sec)

### Missinglettr API (Multi-platform Social Publishing)

- **Docs:** https://docs.missinglettr-api.com
- **API Reference:** https://docs.missinglettr-api.com/reference/api-reference
- **Landing page:** https://missinglettr-api.com/
- **Key endpoints:**
  - Create post: `POST /api/v1/posts` — schedule or immediately publish to 1-12 platforms in a single call
  - Get post status: `GET /api/v1/posts/{id}` — check publishing status
  - List workspaces: `GET /api/v1/workspaces` — get workspace IDs
  - Webhooks: real-time notifications when posts are published, fail, or accounts need attention
- **Auth:** API key via `X-API-Key` header
- **Supported platforms:** twitter, linkedin, facebook, instagram, pinterest, tiktok, youtube, threads, reddit, google_business, mastodon, bluesky
- **Rate limits:** Free tier: 100 requests/hour, 500 posts/month. Pro ($49/month): 2,000 requests/hour, 10,000 posts/month.
- **SDKs:** Python and Node.js SDKs available (not needed — we use raw curl via HttpClient.php)
- **Key features for our use case:** AI-powered optimal scheduling, built-in URL shortening with click tracking, analytics/engagement data per post
- **Note:** Missinglettr-api.com is a separate product from missinglettr.com (the main scheduling tool). A lifetime missinglettr.com account may not include API access — verify with Missinglettr support. API access may require a separate API key from https://app.missinglettr-api.com/register

---

## 16. API keys and subscriptions required

All paid services and accounts needed before the system can operate:

| Service | Type | Estimated Cost | Required For | Signup URL |
|---|---|---|---|---|
| Anthropic (Claude API) | API key + usage billing | ~$0.10-0.50 per script | Script generation | https://console.anthropic.com/ |
| HeyGen | API subscription | ~$30-100/month (plan dependent) | Video generation | https://app.heygen.com/settings/api |
| Missinglettr API | API key | Free (500 posts/mo) or $49/mo Pro | Multi-platform publishing | https://app.missinglettr-api.com/register |
| DigitalOcean Droplet | Infrastructure | $6/month (1GB) | Hosting | https://cloud.digitalocean.com/ |
| DigitalOcean Spaces | Object storage | $5/month (250GB) | Video storage | https://cloud.digitalocean.com/ |
| Google Cloud Project | Free (quota limits) | Free | YouTube Data API (direct adapter) | https://console.cloud.google.com/ |
| X Developer Account | Free or Basic ($100/mo) | Free tier: 1,500 tweets/month | Future direct X adapter | https://developer.x.com/ |
| Reddit Developer App | Free | Free | Future direct Reddit adapter | https://www.reddit.com/prefs/apps |
| Meta Developer Account | Free (requires app review) | Free | Future direct Instagram + Facebook | https://developers.facebook.com/ |
| NVD API Key | Free | Free (optional, for higher rate limits) | Feed ingestion | https://nvd.nist.gov/developers/request-an-api-key |

### Account setup prerequisites (manual, before system can post)

**Required at launch:**
1. **Missinglettr API:** Register at missinglettr-api.com → Get API key → Create workspace → Connect social accounts (X, LinkedIn, Facebook, Instagram, TikTok, Reddit, etc.) within the Missinglettr dashboard → Note workspace_id and API key → Store in `.env`
2. **YouTube (direct adapter):** Create Google Cloud project → Enable YouTube Data API v3 → Create OAuth 2.0 credentials (Web application type) → Run one-time OAuth flow to obtain refresh token → Store refresh token in `.env`
3. **HeyGen:** Sign up → Subscribe to API plan → Generate API key → Create video template with branded elements and `{{script}}` variable → Note template_id → Store in config

**Required later (when migrating platforms to direct adapters):**
4. **X (Twitter) — when migrating from Missinglettr:** Apply for developer account → Create project and app → Generate OAuth 1.0a consumer keys and access tokens → Store in `.env` → Change `platform_config.adapter` for X from `missinglettr` to `direct`
5. **Reddit — when migrating from Missinglettr:** Go to reddit.com/prefs/apps → Create "script" type application → Note client_id and secret → Store in `.env`
6. **Instagram/Facebook — when migrating from Missinglettr:** Create Meta Developer account → Create app → Add Instagram Graph API product → Link Facebook Page to Instagram Professional account → Request `instagram_content_publish` and `pages_manage_posts` permissions → Submit for App Review (takes days/weeks) → Generate long-lived Page Access Token → Store in `.env`

---

## 17. Error handling and logging

### Principles

- Every module wraps its main logic in try/catch
- Errors are logged to `pipeline_log` table via `Logger.php`
- Failed queue items are set to status `failed` with `failure_reason`
- Items with `retry_count < max_retry_count` config are automatically retried by resetting status to the previous pending state (handled by a retry sweep in the orchestrator)
- Critical errors (DB connection failure, missing API keys) log to both the DB (if available) and to `/var/log/securitydrama/critical.log` via `error_log()`
- All external API calls log request/response status codes
- Never log full API keys or secrets — mask to first 4 and last 4 characters

### Logger (`src/Logger.php`)

```php
<?php
class Logger
{
    public static function log(string $module, string $level, string $message, array $context = []): void
    {
        // Insert into pipeline_log table
        // Also error_log() for critical level
    }

    public static function debug(string $module, string $message, array $context = []): void;
    public static function info(string $module, string $message, array $context = []): void;
    public static function warning(string $module, string $message, array $context = []): void;
    public static function error(string $module, string $message, array $context = []): void;
    public static function critical(string $module, string $message, array $context = []): void;
}
```

---

## 18. Security hardening

**A cybersecurity awareness platform with poor security is a reputational death sentence.** Every component must be hardened. This section is not optional — it is part of the core build.

### 18.1 — SQL injection prevention

- **ALL database queries MUST use PDO prepared statements with parameterised binding.** No exceptions. Never concatenate user input or external data into SQL strings.
- The `Database.php` wrapper should ONLY expose `prepare()`, `execute()`, and fetch methods. Do NOT expose a raw `query()` method that accepts unsanitised strings.
- Even internal data (feed item titles, CVE descriptions) ingested from external sources must be treated as untrusted and parameterised, as these could contain crafted payloads.

```php
// CORRECT — always use this pattern
$stmt = $db->prepare('SELECT * FROM feed_items WHERE external_id = :id');
$stmt->execute(['id' => $cveId]);

// NEVER DO THIS — even for "internal" data
$db->query("SELECT * FROM feed_items WHERE external_id = '$cveId'");
```

### 18.2 — Cross-site scripting (XSS) prevention

- **ALL output rendered in the admin dashboard must be escaped.** Use `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` on every variable output in HTML templates.
- Create a helper function `e()` in Bootstrap.php: `function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }`
- Use `e()` everywhere in view templates: `<?= e($item['title']) ?>`
- For JSON output in `web/api.php`, use `json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` flags.
- Set Content-Type headers explicitly: `Content-Type: application/json; charset=utf-8` for API responses, `Content-Type: text/html; charset=utf-8` for pages.
- Feed item descriptions from external RSS/JSON sources may contain HTML. Strip all tags with `strip_tags()` before storing in the database, or store raw but ALWAYS escape on output.

### 18.3 — Cross-site request forgery (CSRF) protection

- The admin dashboard uses HTTP Basic Auth which provides some CSRF resistance, but all state-changing POST requests to `web/api.php` must additionally include a CSRF token.
- Generate a CSRF token per session using `bin2hex(random_bytes(32))`. Store in `$_SESSION`.
- Include the token as a hidden field in forms and as a custom header (`X-CSRF-Token`) in AJAX requests.
- Validate the token on every POST/PUT/DELETE request. Reject with HTTP 403 if missing or mismatched.

### 18.4 — Admin dashboard rate limiting

- Implement rate limiting on `web/api.php` using a simple file-based or database counter approach (no Redis needed).
- Track request counts per IP per minute in the `pipeline_log` table or a dedicated `rate_limits` table.
- Limits: 60 requests per minute per IP for GET, 20 requests per minute per IP for POST.
- Return HTTP 429 with `Retry-After` header when limit exceeded.
- HTTP Basic Auth already limits brute-force exposure, but additionally implement a lockout: after 5 failed auth attempts from an IP within 10 minutes, block that IP for 30 minutes. Log all failed auth attempts.

### 18.5 — Input validation and sanitisation

- **Config values:** When saving config via the dashboard, validate types and ranges. `daily_video_target` must be integer 1-10. `min_relevance_score` must be integer 0-100. `log_retention_days` must be integer 1-365. Reject invalid values with a clear error.
- **Feed source URLs:** Validate that URLs in `feed_sources` are well-formed (`filter_var($url, FILTER_VALIDATE_URL)`) and use only `http` or `https` schemes. Reject `file://`, `php://`, `data://`, or any other scheme that could enable SSRF.
- **Platform config JSON:** Validate that `platform_config_json` is valid JSON before storing. Enforce a maximum size (10KB).
- **All string inputs from the dashboard:** Trim whitespace, enforce maximum lengths matching database column sizes, reject null bytes.

### 18.6 — API key and secret protection

- The `.env` file must be readable only by the application user: `chmod 600 .env`, owned by `www-data`.
- `.env` must be in `.gitignore` and NEVER committed to version control.
- Apache must be configured to deny access to `.env`, `composer.json`, `composer.lock`, `vendor/`, `src/`, `cli/`, and `config/` directories from web requests. Only `web/` is the document root. This is handled via `.htaccess` rules and the Apache VirtualHost `DocumentRoot` directive.
- API keys in error logs are masked: show first 4 and last 4 characters only (e.g., `sk-a****xyz3`). Implement a `maskSecret(string $secret): string` function in Logger.php.
- Database credentials must use a dedicated MySQL user with permissions limited to the `securitydrama` database only. No `GRANT ALL` on `*.*`.

### 18.7 — HTTP security headers

Add these headers to all Apache responses for the admin dashboard. Enable `mod_headers` and place in the VirtualHost config or `.htaccess`:

```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; img-src 'self' data: https://*.digitaloceanspaces.com; media-src 'self' https://*.digitaloceanspaces.com; connect-src 'self'"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
```

### 18.8 — HTTPS and TLS

- The admin dashboard MUST be served over HTTPS only. No HTTP fallback.
- Add an Apache VirtualHost that redirects port 80 to 443. Enable `mod_rewrite`:
  ```apache
  <VirtualHost *:80>
      ServerName admin.securitydrama.com
      RewriteEngine On
      RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]
  </VirtualHost>
  ```
- Use Let's Encrypt with automatic renewal via certbot.
- TLS 1.2 minimum. Disable TLS 1.0 and 1.1.

### 18.9 — Outbound request validation (SSRF prevention)

- The feed ingester makes HTTP requests to external URLs. Validate all URLs before making requests:
  - Resolve the hostname and reject private/internal IP ranges (127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, ::1, fc00::/7).
  - Only allow `http` and `https` schemes.
  - Set a strict timeout on all outbound requests: connect timeout 10 seconds, total timeout 30 seconds.
  - Do not follow more than 3 redirects.
- Apply this validation in `HttpClient.php` so it protects all outbound requests system-wide.

### 18.10 — Dependency security

- Run `composer audit` as part of deployment to check for known vulnerabilities in the single dependency (`aws/aws-sdk-php`).
- Pin the `aws/aws-sdk-php` version in `composer.json` (use `^3.300` not `*`).
- The minimal dependency footprint (one package) dramatically reduces supply chain risk. Do not add packages unnecessarily.

### 18.11 — File system security

- The web document root is `/var/www/securitydrama/web/`. No other directory should be web-accessible.
- Apache runs as `www-data`. The application directory is owned by `www-data` but source code files should be `644` (not writable by web server). Only `/tmp/securitydrama/`, log directories, and specific upload paths need write permission.
- Disable dangerous PHP functions in `php.ini`: `disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_multi_exec,parse_ini_file,show_source`
  - **Exception:** If any CLI pipeline script needs `exec()` for valid reasons, create a separate `php-cli.ini` with these functions enabled, while keeping them disabled in the Apache `php.ini`.
- Set `open_basedir` in `php.ini` (or per-VirtualHost via `php_admin_value`) to restrict file access: `open_basedir = /var/www/securitydrama:/tmp/securitydrama:/var/log/securitydrama`

### 18.12 — Database security

- MySQL should listen only on `127.0.0.1`, not on external interfaces. Set `bind-address = 127.0.0.1` in MySQL config.
- Create a dedicated MySQL user for the application with minimum required privileges: `SELECT, INSERT, UPDATE, DELETE` on the `securitydrama` database. No `CREATE`, `DROP`, `ALTER`, or `GRANT` privileges in production (use a separate migration user for schema changes).
- Set `max_connections = 20` to prevent resource exhaustion.
- Enable MySQL slow query log for queries over 2 seconds: `slow_query_log = 1`, `long_query_time = 2`.

### 18.13 — Server hardening

- Enable UFW firewall. Allow only: SSH (22 or custom port), HTTP (80), HTTPS (443). Block everything else.
  ```bash
  ufw default deny incoming
  ufw default allow outgoing
  ufw allow ssh
  ufw allow 80/tcp
  ufw allow 443/tcp
  ufw enable
  ```
- Change SSH port from 22 to a non-standard port. Disable password authentication — SSH key only.
- Install and enable `fail2ban` to protect against SSH brute-force attacks. Also configure a jail for Apache HTTP auth failures (`apache-auth` jail).
- Keep the system updated: enable unattended-upgrades for security patches.
- Disable root login via SSH. Use a non-root user with sudo access.

### 18.14 — Logging and monitoring for security events

- Log all failed authentication attempts to the admin dashboard (captured by Apache `mod_auth_basic`, viewable in Apache error log).
- Log all API rate limit hits with source IP.
- Log all outbound API call failures (could indicate credential leaks or account suspension).
- The `pipeline_log` table should include the source IP for all web-originated requests.
- Set up a cron job to email an alert (or write to a monitored log) if more than 10 failed auth attempts occur in an hour. Use a simple bash script checking Apache error logs.

### 18.15 — Security review checklist

Before deployment, verify:

- [ ] All SQL queries use prepared statements (grep codebase for raw query patterns)
- [ ] All HTML output uses `e()` escaping
- [ ] CSRF tokens are validated on all POST endpoints
- [ ] `.env` is not accessible via web browser (test: `curl https://admin.securitydrama.com/.env` should return 403)
- [ ] `composer.json`, `src/`, `cli/`, `config/` are not accessible via web (test each)
- [ ] HTTP security headers are present (test: `curl -I https://admin.securitydrama.com/`)
- [ ] HTTPS redirect works (test: `curl -I http://admin.securitydrama.com/`)
- [ ] UFW firewall is active with correct rules
- [ ] SSH is key-only, non-standard port
- [ ] MySQL is not accessible from external IPs (test: `nmap -p 3306 {server_ip}`)
- [ ] PHP dangerous functions are disabled in Apache's php.ini
- [ ] fail2ban is running with apache-auth jail
- [ ] No API keys are visible in any log file (grep logs for key patterns)

---

## 19. Memory and performance constraints

### Hard limits for 1GB RAM droplet

| Component | Max Memory |
|---|---|
| MySQL (innodb_buffer_pool_size) | 256MB |
| PHP CLI process (pipeline) | 128MB |
| PHP mod_php (admin dashboard) | 64MB per worker, max 3 workers |
| Apache | ~30MB |
| OS + system processes | ~200MB |
| **Buffer/headroom** | **~280MB** |

### Rules to enforce

1. **One pipeline step at a time.** Use file locks. Never run two ingestion cycles simultaneously.
2. **Stream large files.** When downloading videos from HeyGen or uploading to DO Spaces/YouTube, use curl with `CURLOPT_FILE` to write directly to disk. Never use `file_get_contents()` on video files.
3. **Process feeds sequentially.** One feed source at a time. After processing each source, unset large variables.
4. **Paginate DB queries.** Never `SELECT *` from feed_items without a LIMIT. Dashboard queries paginate at 50 rows.
5. **Purge temp files.** After publishing, delete local temp video files in `/tmp/`. Implement cleanup in a `finally` block or shutdown function.
6. **Limit log verbosity.** In production, set a config `log_level` defaulting to `info`. Debug logging only when troubleshooting.

---

## 20. File and directory structure

Create these directories on deploy:

```bash
mkdir -p /var/www/securitydrama
mkdir -p /var/log/securitydrama
mkdir -p /tmp/securitydrama
chown -R www-data:www-data /var/www/securitydrama
chown -R www-data:www-data /var/log/securitydrama
chown -R www-data:www-data /tmp/securitydrama
```

### Apache VirtualHost config (`/etc/apache2/sites-available/securitydrama.conf`)

Enable required modules first:
```bash
a2enmod rewrite ssl headers
```

```apache
# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName admin.securitydrama.com
    RewriteEngine On
    RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName admin.securitydrama.com

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/admin.securitydrama.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/admin.securitydrama.com/privkey.pem
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1

    DocumentRoot /var/www/securitydrama/web

    <Directory /var/www/securitydrama/web>
        AllowOverride All
        Require all granted
    </Directory>

    # Security headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; img-src 'self' data: https://*.digitaloceanspaces.com; media-src 'self' https://*.digitaloceanspaces.com; connect-src 'self'"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"

    # Restrict PHP memory for web requests
    php_admin_value memory_limit 64M
    php_admin_value open_basedir "/var/www/securitydrama:/tmp/securitydrama:/var/log/securitydrama"

    ErrorLog ${APACHE_LOG_DIR}/securitydrama-error.log
    CustomLog ${APACHE_LOG_DIR}/securitydrama-access.log combined
</VirtualHost>
```

### `.htaccess` file (`/var/www/securitydrama/web/.htaccess`)

This file handles URL rewriting, HTTP Basic Auth, and blocks access to sensitive files. Apache's `AllowOverride All` in the VirtualHost enables this.

```apache
# URL rewriting — route all requests to index.php
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?$1 [QSA,L]

# HTTP Basic Auth
AuthType Basic
AuthName "Security Drama Admin"
AuthUserFile /etc/apache2/.htpasswd
Require valid-user

# Block access to sensitive files
<FilesMatch "\.(env|json|lock|md|yml|yaml|xml|sh)$">
    Require all denied
</FilesMatch>

# Block access to hidden files (dotfiles)
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
```

### Block parent directories

Since `DocumentRoot` is set to `/var/www/securitydrama/web/`, Apache will not serve files from `/var/www/securitydrama/src/`, `/var/www/securitydrama/cli/`, `/var/www/securitydrama/config/`, or `/var/www/securitydrama/vendor/`. The `DocumentRoot` directive is the primary protection. The `.htaccess` file adds defence-in-depth for files within the web root itself.

### Generate `.htpasswd`

```bash
htpasswd -c /etc/apache2/.htpasswd admin
# Enter the password from .env ADMIN_PASS
```

---

## 21. Implementation order

Build in this exact order. Each phase should be fully functional and testable before moving to the next.

### Phase 1: Foundation
1. Set up directory structure and `composer.json`
2. Create `src/Bootstrap.php`, `Database.php`, `Config.php`, `Logger.php`, `HttpClient.php`
3. Implement the `e()` HTML escaping helper and `maskSecret()` in Logger
4. Create all database tables including `platform_config` (write a `cli/migrate.php` script)
5. Create `config/feeds.php` with all feed source definitions
6. Create `cli/seed-feeds.php` to populate `feed_sources` and `platform_config`

### Phase 2: Feed Ingestion
7. Build `src/Ingest/Normaliser.php`
8. Build `src/Ingest/Parsers/RssParser.php`
9. Build `src/Ingest/Parsers/JsonApiParser.php` and `JsonDownloadParser.php`
10. Build `src/Ingest/Parsers/NvdApiParser.php`
11. Build `src/Ingest/FeedIngester.php` with SSRF validation on all outbound URLs
12. Build `cli/ingest.php`
13. **TEST:** Run ingestion, verify feed_items are populated correctly

### Phase 3: Scoring & Selection
14. Build `src/Scoring/RelevanceScorer.php`
15. Build `src/Selection/ContentSelector.php`
16. Build `cli/score.php` and `cli/select.php`
17. **TEST:** Run scoring and selection, verify content_queue is populated

### Phase 4: Script Generation
18. Build `src/Script/ClaudeClient.php`
19. Create all prompt templates in `config/prompts/`
20. Build `src/Script/ScriptGenerator.php`
21. Build `cli/generate-script.php`
22. **TEST:** Generate scripts for queued items, verify quality

### Phase 5: Video Generation
23. Build `src/Video/VideoGeneratorInterface.php`
24. Build `src/Video/Adapters/HeyGenAdapter.php`
25. Build `src/Storage.php` (DO Spaces upload — stream to disk, never to memory)
26. Build `src/Video/VideoOrchestrator.php`
27. Build `cli/generate-video.php` and `cli/poll-video.php`
28. **TEST:** Generate a video end-to-end, verify it's stored in DO Spaces

### Phase 6: Social Publishing (Pluggable)
29. Build `src/Publish/PublishAdapterInterface.php`
30. Build `src/Publish/PlatformFormatter.php` (per-platform caption/title formatting)
31. Build `src/Publish/Adapters/MissinglettrAdapter.php` (handles X, LinkedIn, Facebook, Instagram, TikTok, Reddit, Threads, Bluesky, Mastodon, Pinterest via single API)
32. Build `src/Publish/Adapters/DirectYouTubeAdapter.php` (resumable upload, chunked)
33. Create stub files for future direct adapters: `DirectXAdapter.php`, `DirectInstagramAdapter.php`, `DirectFacebookAdapter.php`, `DirectRedditAdapter.php` (each throws RuntimeException)
34. Build `src/Publish/SocialPublisher.php` (orchestrator that reads platform_config, routes to correct adapter, publishes YouTube first)
35. Build `cli/publish.php`
36. **TEST:** Publish a video to YouTube (direct) and at least 2 other platforms (via Missinglettr)

### Phase 7: Pipeline Orchestration
37. Build `src/Pipeline/Orchestrator.php`
38. Build `cli/pipeline.php`
39. Set up all cron jobs
40. Build `cli/purge-logs.php`
41. Add file locking (`flock()`) to all CLI scripts
42. **TEST:** Run full pipeline end-to-end

### Phase 8: Admin Dashboard
43. Build `web/index.php` router and `web/api.php` with CSRF token validation
44. Build `web/views/layout.php` with security headers
45. Build all dashboard views (dashboard, queue, feeds, videos, posts, config with per-platform adapter dropdowns, logs)
46. Build `web/assets/app.js` for AJAX interactions (include CSRF token in all POST requests)
47. Configure Apache VirtualHost with security headers, HTTPS redirect, and `.htaccess`
48. Implement rate limiting on `web/api.php`
49. **TEST:** Verify dashboard shows live data, config changes work, platform adapter dropdowns update `platform_config`

### Phase 9: Reddit Engagement Crawler
50. Build `src/Reddit/RedditCrawler.php` (subreddit monitoring, keyword matching)
51. Build `src/Reddit/ThreadMatcher.php` (match discovered threads to existing videos)
52. Build `src/Reddit/CommentGenerator.php` (Claude-powered contextual comment generation)
53. Build `src/Reddit/RedditCommenter.php` (post comments with rate limiting and anti-spam safeguards)
54. Build `src/Reddit/EngagementOrchestrator.php`
55. Build `cli/reddit-crawl.php` and `cli/reddit-engage.php`
56. Create `reddit_watch_keywords` and `reddit_threads` tables (add to `cli/migrate.php`)
57. Seed `reddit_watch_keywords` with initial package names and security terms
58. Build `web/views/reddit.php` dashboard page with comment history and pause button
59. **TEST:** Run crawler against live subreddits, verify thread discovery, generate sample comments (review manually before enabling auto-posting). Start with `reddit_engagement_enabled = 0` and review the first 20 generated comments before switching to 1.

### Phase 10: Security Hardening
60. Audit all SQL queries — verify every one uses prepared statements (grep for string concatenation patterns)
61. Audit all HTML output — verify every variable uses `e()` escaping
62. Verify `.env` and source directories are inaccessible via web
63. Configure UFW firewall rules
64. Configure fail2ban for SSH and Apache auth
65. Disable dangerous PHP functions in Apache's php.ini
66. Set MySQL `bind-address = 127.0.0.1` and create restricted application user
67. Change SSH to key-only auth on non-standard port
68. Run the full security review checklist from Section 18.15
69. Test memory usage under load (monitor with `memory_get_peak_usage()`)
70. Set up logrotate for `/var/log/securitydrama/`
71. Document the deployment process in a `DEPLOY.md` file
72. **TEST:** Run all checklist items from Section 18.15 and document results
