# Security Drama

Fully automated content pipeline that ingests security vulnerability feeds, generates dramatic short-form video content using AI, and publishes to social media platforms. Runs on a single DigitalOcean droplet (1GB RAM) with a PHP/MySQL backend.

## System Overview

```
Feed Sources (53)  ──►  Ingest  ──►  Score & Select  ──►  Script (Claude AI)
                                                              │
                        Publish  ◄──  Video (HeyGen/Seedance)  ◄─┘
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
5. **Video** - Submits scripts to the configured video provider — HeyGen (avatar presenter) or Seedance 2.0 (cinematic AI via fal.ai). Pluggable adapter pattern. Polls for completion. Downloads and uploads to DigitalOcean Spaces.
6. **Publish** - Posts to YouTube (direct API with resumable upload) and all other platforms via Missinglettr API. Per-platform adapter selection configurable via admin dashboard.
7. **Reddit Engagement** - Crawls relevant subreddits, matches threads to existing videos, generates contextual comments via Claude, posts with strict anti-spam safeguards.

### Architecture

- **Runtime:** PHP 8.3 CLI (pipeline) + Apache mod_php (admin dashboard)
- **Database:** MySQL 8.0
- **Storage:** DigitalOcean Spaces (S3-compatible)
- **Dependencies:** Single Composer package (`aws/aws-sdk-php`). All HTTP via built-in curl.
- **Video:** HeyGen API (avatar) or Seedance 2.0 via fal.ai (cinematic) — selectable in admin dashboard
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
│   ├── kill-switch.php         # Toggle pipeline_enabled on/off
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
│   ├── health.php              # Unauthenticated health endpoint (for sync.php)
│   ├── .htaccess               # Auth, rewrites, file blocking
│   ├── assets/                 # CSS + JS
│   └── views/                  # Dashboard pages
├── deploy.php                  # Single-script deployment tool
├── sync.php                    # Incremental code sync tool
├── deploy-lib.php              # Shared SSH/sudo helpers for deploy + sync
├── composer.json
├── .env.example
└── .gitignore
```

## Deployment

### Prerequisites

- A DigitalOcean droplet (Ubuntu 24.04, 1GB RAM, $6/month)
- A DigitalOcean Spaces bucket
- SSH access to the droplet as a **non-root user with sudo privileges** (see below)
- DNS A record pointing your admin domain to the droplet IP
- API keys (see below)

#### SSH user setup

`deploy.php` does **not** require or recommend SSH-as-root. Create a dedicated deploy user with sudo on the droplet before running the script:

```bash
# On a fresh droplet, still logged in as root:
adduser deploy
usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh && chmod 600 /home/deploy/.ssh/authorized_keys

# Recommended: enable passwordless sudo for the deploy user so the script
# can escalate non-interactively.
echo 'deploy ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/deploy
chmod 440 /etc/sudoers.d/deploy
```

If you prefer not to enable NOPASSWD, leave `/etc/sudoers.d/deploy` unchanged — the deploy script will prompt for the sudo password once at startup and stream it via `sudo -S` for each privileged call.

The script still supports `ssh_user = root` for legacy setups, but this is discouraged. Either way, all privileged operations (`apt-get`, writes to `/etc`, `systemctl`, `certbot`, `ufw`, `crontab -u www-data`, etc.) are wrapped correctly.

### Required API Keys

| Service | Required At Launch | Cost |
|---|---|---|
| Anthropic (Claude) | Yes | ~$0.10-0.50/script |
| HeyGen | Yes (if using HeyGen provider) | ~$30-100/month |
| fal.ai (Seedance 2.0) | Yes (if using Seedance provider) | ~$2-3 per 10s video |
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

## Syncing code updates

After the initial `deploy.php` run, use `sync.php` to ship ongoing changes. It coordinates with the running pipeline, takes a rollback snapshot, runs migrations/seeds only when they're needed, and smoke-tests the box before re-enabling the pipeline.

### Prerequisites

- `deploy.php` has already been run against the target droplet.
- Your local git working tree is **clean** (`git status` shows no changes). `sync.php` refuses to run otherwise — commit or stash first.
- Same SSH/sudo setup as `deploy.php`: a non-root sudoer is strongly recommended, ideally with NOPASSWD.

### Command

```bash
php sync.php                    # interactive, uses ~/.security-drama/sync.json
php sync.php --dry-run          # rsync --dry-run only, no writes
php sync.php --rollback         # interactive picker over retained snapshots
php sync.php --config=/path.json
```

On first run, `sync.php` prompts for connection details and offers to save them to `~/.security-drama/sync.json` (chmod 600). The saved file includes `server_ip`, `ssh_port`, `ssh_user`, `ssh_key`, `sudo_pass`, `domain`, `app_dir`, `snapshot_dir`, and `keep_snapshots`.

**About `sudo_pass`**: stored in plaintext at mode 600. This is the same trade-off as storing API keys in `.env`. If you want to avoid it entirely, configure NOPASSWD sudo on the server (recommended — see "SSH user setup" above) and leave the field blank.

### What happens during sync

1. **Preflight** — refuses to run with a dirty git tree; captures the current short SHA.
2. **Probe** — SSH + sudo reachability, confirms the app directory exists.
3. **Sentinel lock** — writes `/var/lock/securitydrama-sync.lock` so a second operator can't run a concurrent sync.
4. **Kill switch** — sets `pipeline_enabled=0` and waits (up to 5 min) for any in-flight pipeline run to finish via its `/tmp/securitydrama_pipeline.lock` file.
5. **Snapshot** — `cp -al /var/www/securitydrama /var/www/securitydrama-snapshots/<timestamp>-<sha>`. Hardlink tree; near-zero cost, near-zero time, complete rollback point.
6. **Diff** — hashes the remote `composer.lock`, `cli/migrate.php`, `cli/seed-feeds.php`, and `config/feeds.php` against the local copies to decide which post-sync steps to offer.
7. **rsync** — pushes code with `--delete` (stale files are removed). `.env`, `vendor/`, `.git/`, `*.log`, and the deploy/sync tooling itself are excluded.
8. **composer install** — only if `composer.lock` changed.
9. **Migrations** — always prompt (default answer = yes if `migrate.php` changed, otherwise no).
10. **Seed** — prompt only if feed config changed.
11. **VERSION file** — writes the short SHA to `/var/www/securitydrama/VERSION`.
12. **Smoke tests** — both must pass:
    - `php cli/pipeline.php --validate-only` (DB + config + env + S3 client)
    - `curl http://localhost/health.php` (served via Apache, unauthenticated, returns the SHA)
13. **Kill switch release** — `pipeline_enabled=1`.
14. **Prune** — deletes all but the most recent `keep_snapshots` snapshots.
15. **Done** — success banner, sentinel released.

### What happens when something fails after the snapshot is taken

Any error in phases 6–12 triggers an automatic rollback: `rsync -a --delete` from the just-taken snapshot restores the previous tree, the smoke test runs again against the rolled-back code, and the pipeline kill switch is released. You land back on the previous release and see a clear "rolled back" banner.

**Migrations are not rolled back.** Our schema uses `CREATE TABLE IF NOT EXISTS`, so most forward-only migrations are backwards-compatible with older code. If your migration adds a dropped column or renamed table, you must author a reversal migration — that's out of scope for `sync.php`.

### Explicit rollback

```bash
php sync.php --rollback
```

Lists retained snapshots (most recent first), prompts for which one to restore, engages the kill switch, rsyncs the snapshot back over `/var/www/securitydrama`, smoke-tests, and releases the kill switch.

### Health endpoint

`https://<your-domain>/health.php` is served without HTTP Basic auth and returns:

```json
{"status":"ok","db":true,"config":true,"git_sha":"abc1234"}
```

It exists for the sync tool but you can also curl it from monitoring / uptime checks.

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

Use `php sync.php` from the project root. See the **Syncing code updates** section above for the full flow. It handles rsync, composer, migrations, seeding, VERSION tracking, smoke tests, and rollback in one command.

If `sync.php` is unavailable for some reason, the raw fallback is:

```bash
# From your local machine, in the project directory:
rsync -avz --exclude=".git" --exclude="vendor" --exclude=".env" \
  -e "ssh -p YOUR_SSH_PORT" \
  ./ user@server:/var/www/securitydrama/

# On the server, if composer.json changed:
sudo -u www-data bash -c 'cd /var/www/securitydrama && HOME=/tmp/securitydrama composer install --no-dev --optimize-autoloader'

# If migrations changed:
sudo -u www-data php /var/www/securitydrama/cli/migrate.php
```

Remember to set `pipeline_enabled=0` in the admin dashboard first and back to `1` after, so the cron pipeline doesn't run against half-updated code.

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

### Switching Video Providers

Two providers are available out of the box:

- **HeyGen** (`heygen`) — Avatar-based presenter reads the narration script. Good for authoritative "expert explains" content.
- **Seedance 2.0** (`seedance`) — Cinematic AI video from text prompts via fal.ai. Produces dynamic multi-shot video with native audio. Good for dramatic, visually engaging content.

Switch between them in the admin dashboard Config page using the "Video Provider" dropdown, or set `video_provider` directly in the database.

### Adding a New Video Provider

1. Create a new class in `src/Video/Adapters/` implementing `VideoGeneratorInterface`
2. Add it to the `match` expression in `VideoOrchestrator.php`
3. Add a `<option>` to the provider dropdown in `web/views/config.php`
4. Set `video_provider` config to your new adapter's name

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
