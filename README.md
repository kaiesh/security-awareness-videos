# Security Drama

Fully automated content pipeline that ingests security vulnerability feeds, generates dramatic short-form video content using AI, and publishes to social media platforms. Runs on a single DigitalOcean droplet (1GB RAM) with a PHP/MySQL backend.

## System Overview

```
Feed Sources (53)  ──►  Ingest  ──►  Score & Select  ──►  Script (Claude AI)
                                                              │
                        Publish  ◄──  Video (HeyGen)  ◄──────┘
                           │
            ┌──────────────┼──────────────────┐
            ▼              ▼                  ▼
        YouTube      Missinglettr        Reddit Engagement
        (direct)     (X, LinkedIn,       (contextual comments
                      Instagram,          with video links)
                      Facebook,
                      TikTok, etc.)
```

### Pipeline Flow

1. **Ingest** - Polls 53 security feeds (NVD, CISA KEV, BleepingComputer, Hacker News, etc.) every 6-24 hours. Deduplicates by content hash.
2. **Score** - Rates each item 0-100 based on severity, vibe-coder relevance, recency, source authority, and exploitability signals.
3. **Select** - Promotes top-scoring items to the content queue. Determines content type (CVE alert, scam drama, security 101, vibe roast, breach story) and target audience.
4. **Script** - Sends context to Claude API, receives structured JSON scripts with narration, visual cues, on-screen text, hashtags, and platform-specific titles.
5. **Video** - Submits scripts to HeyGen (pluggable adapter pattern). Polls for completion. Downloads and uploads to DigitalOcean Spaces.
6. **Publish** - Posts to YouTube (direct API with resumable upload) and all other platforms via Missinglettr API. Per-platform adapter selection configurable via admin dashboard.
7. **Reddit Engagement** - Crawls relevant subreddits, matches threads to existing videos, generates contextual comments via Claude, posts with strict anti-spam safeguards.

### Architecture

- **Runtime:** PHP 8.3 CLI (pipeline) + Apache mod_php (admin dashboard)
- **Database:** MySQL 8.0
- **Storage:** DigitalOcean Spaces (S3-compatible)
- **Dependencies:** Single Composer package (`aws/aws-sdk-php`). All HTTP via built-in curl.
- **Video:** HeyGen API (pluggable interface for future providers)
- **Publishing:** YouTube direct + Missinglettr for 11 other platforms
- **AI:** Anthropic Claude API for script generation and Reddit comment generation

### File Structure

```
├── cli/                        # CLI entry points (cron-triggered)
│   ├── ingest.php              # Feed ingestion
│   ├── score.php               # Relevance scoring
│   ├── select.php              # Content queue selection
│   ├── generate-script.php     # AI script generation
│   ├── generate-video.php      # Video generation submission
│   ├── poll-video.php          # Video status polling
│   ├── publish.php             # Social media publishing
│   ├── pipeline.php            # Full pipeline (all steps)
│   ├── reddit-crawl.php        # Reddit thread discovery
│   ├── reddit-engage.php       # Reddit comment posting
│   ├── migrate.php             # Database table creation
│   ├── seed-feeds.php          # Seed feeds, platforms, config
│   └── purge-logs.php          # Log cleanup
├── config/
│   ├── feeds.php               # 53 feed source definitions
│   └── prompts/                # LLM prompt templates
│       ├── cve_alert.txt
│       ├── scam_drama.txt
│       ├── security_101.txt
│       ├── vibe_roast.txt
│       └── breach_story.txt
├── src/
│   ├── Bootstrap.php           # App init, autoloader, env loader
│   ├── Database.php            # PDO wrapper (prepared statements only)
│   ├── Config.php              # Config from .env + DB
│   ├── Logger.php              # Pipeline logging to DB
│   ├── HttpClient.php          # Curl wrapper with SSRF protection
│   ├── Storage.php             # DO Spaces S3 client
│   ├── Ingest/                 # Feed ingestion module
│   ├── Scoring/                # Relevance scoring
│   ├── Selection/              # Content queue selection
│   ├── Script/                 # Claude API + script generation
│   ├── Video/                  # Video generation (HeyGen adapter)
│   ├── Publish/                # Social publishing (YouTube, Missinglettr, stubs)
│   ├── Reddit/                 # Reddit engagement crawler
│   └── Pipeline/               # Master orchestrator
├── web/
│   ├── index.php               # Dashboard router
│   ├── api.php                 # AJAX API with CSRF + rate limiting
│   ├── .htaccess               # Auth, rewrites, file blocking
│   ├── assets/                 # CSS + JS
│   └── views/                  # Dashboard pages
├── deploy.php                  # Single-script deployment tool
├── composer.json
├── .env.example
└── .gitignore
```

## Deployment

### Prerequisites

- A DigitalOcean droplet (Ubuntu 24.04, 1GB RAM, $6/month)
- A DigitalOcean Spaces bucket
- SSH access to the droplet
- DNS A record pointing your admin domain to the droplet IP
- API keys (see below)

### Required API Keys

| Service | Required At Launch | Cost |
|---|---|---|
| Anthropic (Claude) | Yes | ~$0.10-0.50/script |
| HeyGen | Yes (for video) | ~$30-100/month |
| Missinglettr API | Yes (for multi-platform posting) | Free (500 posts/mo) |
| DigitalOcean Spaces | Yes | $5/month |
| YouTube OAuth | Yes (for Shorts) | Free (quota: ~6 uploads/day) |
| Reddit API | Optional (for engagement) | Free |
| NVD API Key | Optional (higher rate limits) | Free |

### One-Command Deploy

```bash
php deploy.php
```

The deploy script interactively prompts for everything:

1. **Server connection** - IP, SSH user, port, key path, domain name
2. **Database** - Names and passwords (MySQL is installed and configured automatically)
3. **API keys** - Claude, HeyGen, Missinglettr, DO Spaces, YouTube, Reddit (optional ones can be skipped)
4. **Admin credentials** - Username and password for the dashboard

The script then automatically:

- Installs PHP 8.3, MySQL 8, Apache 2.4, Composer, certbot, fail2ban, UFW
- Uploads all application code via rsync
- Writes the `.env` file with your credentials
- Configures MySQL (dedicated user, buffer pool, slow query log, bind to localhost)
- Runs database migrations (11 tables)
- Seeds feed sources (53 feeds), platform config (11 platforms), and default settings
- Configures Apache with security headers, HTTPS redirect, restricted document root
- Obtains SSL certificate via Let's Encrypt
- Configures PHP (memory limits, disabled functions for web)
- Sets up UFW firewall (SSH + HTTP + HTTPS only)
- Configures fail2ban for SSH and Apache auth
- Optionally hardens SSH (custom port, key-only auth)
- Installs all cron jobs
- Sets up log rotation

## Execution

### Automatic (Cron)

After deployment, the pipeline runs automatically via cron:

| Schedule | Script | Purpose |
|---|---|---|
| Every 6 hours | `ingest.php` | Poll security feeds |
| +15 min after ingest | `score.php` | Score new items |
| +20 min after ingest | `select.php` | Select for content queue |
| Every 2 hours | `generate-script.php` | Generate AI scripts |
| Every 2 hours | `generate-video.php` | Submit video jobs |
| Every 5 minutes | `poll-video.php` | Check video render status |
| Every 30 minutes | `publish.php` | Publish completed videos |
| Every 4 hours | `reddit-crawl.php` | Discover Reddit threads |
| Every 2 hours | `reddit-engage.php` | Post Reddit comments |
| Daily at 3am | `purge-logs.php` | Clean old log entries |

### Manual Execution

Run any pipeline step manually:

```bash
# Full pipeline (all steps in sequence)
php /var/www/securitydrama/cli/pipeline.php

# Individual steps
php /var/www/securitydrama/cli/ingest.php
php /var/www/securitydrama/cli/score.php
php /var/www/securitydrama/cli/select.php
php /var/www/securitydrama/cli/generate-script.php
php /var/www/securitydrama/cli/generate-video.php
php /var/www/securitydrama/cli/poll-video.php
php /var/www/securitydrama/cli/publish.php
```

### Kill Switch

Set `pipeline_enabled` to `0` in the admin dashboard Config page (or directly in the DB) to immediately halt all automation. Each CLI script checks this flag on startup.

Reddit engagement has its own independent switch: `reddit_engagement_enabled`.

### Admin Dashboard

Access at `https://your-domain.com/` (HTTP Basic Auth).

Pages:
- **Dashboard** - Live stats, queue breakdown, videos today vs target, recent logs
- **Content Queue** - Pipeline items with status tracking, click for full detail
- **Feeds** - 53 feed sources with toggle active, manual poll, error status
- **Videos** - Generated videos with preview player
- **Social Posts** - Per-platform post tracking with direct links
- **Config** - All settings including per-platform adapter selection (direct/Missinglettr/disabled)
- **Reddit** - Engagement monitoring, thread history, keyword management, pause button
- **Logs** - Filterable pipeline log viewer

## Maintenance

### Updating Application Code

```bash
# From your local machine, in the project directory:
rsync -avz --exclude=".git" --exclude="vendor" --exclude=".env" \
  -e "ssh -p YOUR_SSH_PORT" \
  ./ user@server:/var/www/securitydrama/

# On the server, if composer.json changed:
cd /var/www/securitydrama && composer install --no-dev --optimize-autoloader
```

### Database Migrations

After code updates that add new tables:

```bash
# Temporarily grant CREATE privileges
mysql -u root -p -e "GRANT CREATE, ALTER, INDEX, REFERENCES ON securitydrama.* TO 'securitydrama'@'localhost'; FLUSH PRIVILEGES;"

php /var/www/securitydrama/cli/migrate.php

# Revoke after
mysql -u root -p -e "REVOKE CREATE, ALTER, INDEX, REFERENCES ON securitydrama.* FROM 'securitydrama'@'localhost'; FLUSH PRIVILEGES;"
```

### Monitoring

```bash
# Check pipeline is running
tail -f /var/log/securitydrama/ingest.log

# Check for errors
grep -r "ERROR\|CRITICAL" /var/log/securitydrama/

# Check cron is executing
grep securitydrama /var/log/syslog

# Check disk usage (videos can accumulate)
du -sh /var/www/securitydrama/
df -h

# Check memory usage
free -m
```

### Log Management

Pipeline logs in the database are automatically purged after 30 days (configurable via `log_retention_days`). File logs in `/var/log/securitydrama/` are rotated daily with 14-day retention via logrotate.

### SSL Certificate Renewal

Let's Encrypt certificates auto-renew via certbot's systemd timer. Verify:

```bash
certbot renew --dry-run
```

### Backup

```bash
# Database backup
mysqldump -u root -p securitydrama > backup_$(date +%Y%m%d).sql

# Config backup
cp /var/www/securitydrama/.env ~/env_backup_$(date +%Y%m%d)
```

### Adding a New Video Provider

1. Create a new class in `src/Video/Adapters/` implementing `VideoGeneratorInterface`
2. Add it to the `match` expression in `VideoOrchestrator.php`
3. Set `video_provider` config to your new adapter's name

### Migrating a Platform from Missinglettr to Direct API

1. Build the direct adapter class (implement `PublishAdapterInterface`)
2. Replace the stub file in `src/Publish/Adapters/`
3. In the admin dashboard Config page, change the platform's adapter dropdown from "missinglettr" to "direct"
4. Add the platform's API credentials to `.env`

### Memory Constraints (1GB Droplet)

| Component | Allocation |
|---|---|
| MySQL buffer pool | 256MB |
| PHP CLI (pipeline) | 128MB |
| PHP web (dashboard) | 64MB x 3 workers |
| Apache | ~30MB |
| OS + system | ~200MB |

The pipeline processes one item at a time with file locking. Videos are streamed to/from disk, never loaded into memory.

## Security

- All SQL queries use PDO prepared statements (no raw queries exposed)
- All HTML output escaped via `e()` helper
- CSRF tokens on all dashboard POST requests
- SSRF protection on all outbound HTTP requests (blocks private IPs)
- API keys masked in logs (first 4 + last 4 chars only)
- `.env` file is `chmod 600`, not web-accessible
- Apache document root restricted to `web/` only
- Dangerous PHP functions disabled in Apache's php.ini
- UFW firewall (SSH + HTTP + HTTPS only)
- fail2ban for SSH and Apache auth brute-force protection
- MySQL bound to localhost only, dedicated user with minimal privileges
- HTTP security headers (CSP, HSTS, X-Frame-Options, etc.)
- Rate limiting on dashboard API (60 GET/min, 20 POST/min per IP)
