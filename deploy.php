#!/usr/bin/env php
<?php
/**
 * Security Drama - Single-script deployment tool
 *
 * Deploys the entire Security Drama pipeline to a DigitalOcean droplet.
 * Run locally: php deploy.php
 *
 * This script will:
 * 1. Prompt for all required configuration (server, API keys, domain)
 * 2. Connect to the server via SSH
 * 3. Install all system dependencies (PHP 8.3, MySQL 8, Apache, Composer)
 * 4. Upload the application code
 * 5. Configure MySQL, Apache, SSL, firewall
 * 6. Run migrations, seed data, install Composer dependencies
 * 7. Set up cron jobs
 * 8. Harden the server (firewall, fail2ban, SSH)
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Require PHP 8.0+ (for the deploy script itself - server will have 8.3)
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die("PHP 8.0+ required to run this deploy script.\n");
}

require_once __DIR__ . '/deploy-lib.php';

// ──────────────────────────────────────────────
// Main deployment flow
// ──────────────────────────────────────────────

banner('Security Drama - Deployment Tool');

echo "This script will deploy Security Drama to your DigitalOcean droplet.\n";
echo "You will be prompted for all required configuration.\n";
echo "Make sure you have SSH access to the server before continuing.\n\n";

if (!confirm("Ready to proceed?")) {
    echo "Aborted.\n";
    exit(0);
}

// ─── Step 1: Server connection details ───

banner('Step 1: Server Connection');

$config = [];
$config['server_ip'] = prompt('Server IP address');
$config['ssh_user'] = prompt('SSH username (non-root sudoer recommended)', 'deploy');
$config['ssh_port'] = prompt('SSH port', '22');
$config['ssh_key'] = prompt('SSH private key path (leave empty for default)', '', false);
$config['domain'] = prompt('Admin dashboard domain (e.g., admin.securitydrama.com)');
$config['new_ssh_port'] = prompt('New SSH port for hardening (leave empty to keep current)', '', false);

// Sudo password — only relevant for non-root SSH users.
$config['sudo_pass'] = '';
if ($config['ssh_user'] !== 'root') {
    echo "\n";
    echo "  The SSH user '{$config['ssh_user']}' is not root. The script will use sudo for\n";
    echo "  privileged operations. If you have passwordless sudo (NOPASSWD) configured for\n";
    echo "  this user, leave the password blank. Otherwise enter your sudo password.\n\n";
    $config['sudo_pass'] = prompt('Sudo password (leave empty for NOPASSWD)', '', false, true);
}

// Test SSH connection
info("Testing SSH connection...");
$testOutput = sshExec($config, 'echo "SSH_OK"', true);
if (strpos($testOutput, 'SSH_OK') === false) {
    error("Cannot connect to {$config['server_ip']}. Check your credentials.");
    error("Output: $testOutput");
    exit(1);
}
success("SSH connection successful.");

// Test sudo access before doing anything that needs it.
if ($config['ssh_user'] !== 'root') {
    info("Testing sudo access...");
    if (!sshSudoProbe($config)) {
        error("sudo access check failed.");
        error("The SSH user '{$config['ssh_user']}' must be able to run sudo.");
        error("");
        error("To enable passwordless sudo, run on the server as root:");
        error("  echo '{$config['ssh_user']} ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/{$config['ssh_user']}");
        error("  chmod 440 /etc/sudoers.d/{$config['ssh_user']}");
        error("");
        error("Or re-run this script and enter the sudo password when prompted.");
        exit(1);
    }
    success("Sudo access confirmed.");
}

// ─── Step 2: Database credentials ───

banner('Step 2: Database Configuration');

$config['db_name'] = prompt('MySQL database name', 'securitydrama');
$config['db_user'] = prompt('MySQL application username', 'securitydrama');
$config['db_pass'] = prompt('MySQL application password (will be created)', '', true, true);
// Note: we intentionally don't prompt for a MySQL root password. Ubuntu 24.04's
// MySQL uses auth_socket for root, so we invoke `sudo mysql` for admin DDL —
// no password is ever stored or transmitted. The application connects only
// as the dedicated $db_user with minimal privileges.

// ─── Step 3: API keys ───

banner('Step 3: API Keys');

echo "Enter your API keys. These will be written to the .env file on the server.\n";
echo "Leave optional keys empty if not yet available.\n\n";

$config['anthropic_api_key'] = prompt('Anthropic (Claude) API key', '', true, true);
$config['heygen_api_key'] = prompt('HeyGen API key', '', false, true);
$config['fal_api_key'] = prompt('fal.ai API key (for Seedance video generation)', '', false, true);

echo "\n";
$config['missinglettr_api_key'] = prompt('Missinglettr API key', '', false, true);
$config['missinglettr_workspace_id'] = prompt('Missinglettr workspace ID', '', false);

echo "\n";
$config['do_spaces_key'] = prompt('DigitalOcean Spaces access key', '', false, true);
$config['do_spaces_secret'] = prompt('DigitalOcean Spaces secret key', '', false, true);
$config['do_spaces_region'] = prompt('DigitalOcean Spaces region', 'sgp1');
$config['do_spaces_bucket'] = prompt('DigitalOcean Spaces bucket name', 'securitydrama-media');

echo "\n";
$useDirect = confirm("Configure YouTube direct API credentials?", false);
if ($useDirect) {
    $config['youtube_client_id'] = prompt('YouTube OAuth client ID');
    $config['youtube_client_secret'] = prompt('YouTube OAuth client secret', '', true, true);
    $config['youtube_refresh_token'] = prompt('YouTube OAuth refresh token', '', true, true);
} else {
    $config['youtube_client_id'] = '';
    $config['youtube_client_secret'] = '';
    $config['youtube_refresh_token'] = '';
}

echo "\n";
$useReddit = confirm("Configure Reddit API credentials?", false);
if ($useReddit) {
    $config['reddit_client_id'] = prompt('Reddit client ID');
    $config['reddit_client_secret'] = prompt('Reddit client secret', '', true, true);
    $config['reddit_username'] = prompt('Reddit username');
    $config['reddit_password'] = prompt('Reddit password', '', true, true);
} else {
    $config['reddit_client_id'] = '';
    $config['reddit_client_secret'] = '';
    $config['reddit_username'] = '';
    $config['reddit_password'] = '';
}

// Future platform keys - leave empty
$config['x_consumer_key'] = '';
$config['x_consumer_secret'] = '';
$config['x_access_token'] = '';
$config['x_access_token_secret'] = '';
$config['meta_page_access_token'] = '';
$config['meta_ig_user_id'] = '';
$config['meta_page_id'] = '';

// ─── Step 4: Admin dashboard ───

banner('Step 4: Admin Dashboard');

$config['admin_user'] = prompt('Admin username', 'admin');
$config['admin_pass'] = prompt('Admin password', '', true, true);

// ─── Step 5: Confirm and deploy ───

banner('Step 5: Deployment Summary');

echo "  Server:     {$config['server_ip']}:{$config['ssh_port']}\n";
echo "  Domain:     {$config['domain']}\n";
echo "  Database:   {$config['db_name']} (user: {$config['db_user']})\n";
echo "  Admin:      {$config['admin_user']}\n";
echo "  Claude API: " . (strlen($config['anthropic_api_key']) > 8
    ? substr($config['anthropic_api_key'], 0, 4) . '****' . substr($config['anthropic_api_key'], -4)
    : '(set)') . "\n";
echo "  HeyGen:     " . ($config['heygen_api_key'] ? 'configured' : 'not set') . "\n";
echo "  Seedance:   " . ($config['fal_api_key'] ? 'configured' : 'not set') . "\n";
echo "  Missinglettr: " . ($config['missinglettr_api_key'] ? 'configured' : 'not set') . "\n";
echo "  DO Spaces:  " . ($config['do_spaces_key'] ? 'configured' : 'not set') . "\n";
echo "  YouTube:    " . ($config['youtube_client_id'] ? 'configured' : 'not set') . "\n";
echo "  Reddit:     " . ($config['reddit_client_id'] ? 'configured' : 'not set') . "\n";
echo "\n";

if (!confirm("Deploy with these settings?")) {
    echo "Aborted.\n";
    exit(0);
}

// ──────────────────────────────────────────────
// DEPLOYMENT EXECUTION
// ──────────────────────────────────────────────

banner('Deploying Security Drama');

// ─── Install system packages ───

info("Installing system packages (PHP 8.3, MySQL 8, Apache, etc.)...");
sshSudoStream($config, 'export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && apt-get install -y -qq software-properties-common 2>&1 | tail -5');

sshSudoStream($config, 'export DEBIAN_FRONTEND=noninteractive && add-apt-repository -y ppa:ondrej/php 2>&1 | tail -3');

sshSudoStream($config, 'export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && apt-get install -y -qq \
    php8.3 php8.3-cli php8.3-mysql php8.3-curl php8.3-xml php8.3-mbstring php8.3-zip php8.3-gd php8.3-intl \
    libapache2-mod-php8.3 \
    apache2 apache2-utils \
    mysql-server \
    certbot python3-certbot-apache \
    fail2ban \
    ufw \
    unzip curl git rsync \
    2>&1 | tail -10');

success("System packages installed.");

// ─── Install Composer ───

info("Installing Composer...");
// sshSudo wraps the command in `sudo bash -c '...'`, so the pipe runs entirely
// under the elevated shell — both curl and the php installer write under root.
sshSudo($config, 'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer');
success("Composer installed.");

// ─── Create directory structure ───

info("Creating directory structure...");
sshSudo($config, 'mkdir -p /var/www/securitydrama /var/log/securitydrama /tmp/securitydrama');
sshSudo($config, 'chown -R www-data:www-data /var/log/securitydrama /tmp/securitydrama');

// ─── Upload application code ───

// rsync over ssh runs the remote side as the deploy user. Since the target
// parent (/var/www/securitydrama) is owned by www-data at this point, we
// temporarily hand ownership to the deploy user, rsync, then hand it back.
// This avoids needing `--rsync-path="sudo rsync"` (and the sudoers entries
// that would require) and works uniformly with NOPASSWD and password sudo.
info("Preparing upload target...");
$sshUser = $config['ssh_user'];
sshSudo($config, "chown {$sshUser}:{$sshUser} /var/www/securitydrama");

info("Uploading application code...");
$projectDir = dirname(__FILE__);
$uploaded = rsyncUpload($config, $projectDir, '/var/www/securitydrama');
if (!$uploaded) {
    error("Failed to upload application code.");
    // Restore ownership even on failure so we don't leave a security hole.
    sshSudo($config, "chown -R www-data:www-data /var/www/securitydrama");
    exit(1);
}
success("Application code uploaded.");

// ─── Set ownership and permissions ───

info("Setting file permissions...");
sshSudo($config, 'chown -R www-data:www-data /var/www/securitydrama');
sshSudo($config, 'find /var/www/securitydrama -type f -exec chmod 644 {} \;');
sshSudo($config, 'find /var/www/securitydrama -type d -exec chmod 755 {} \;');

// ─── Write .env file ───

info("Writing .env configuration...");
$doEndpoint = "https://{$config['do_spaces_region']}.digitaloceanspaces.com";
$doCdnUrl = "https://{$config['do_spaces_bucket']}.{$config['do_spaces_region']}.cdn.digitaloceanspaces.com";

$envContent = <<<ENV
# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME={$config['db_name']}
DB_USER={$config['db_user']}
DB_PASS={$config['db_pass']}

# Anthropic (Claude)
ANTHROPIC_API_KEY={$config['anthropic_api_key']}

# HeyGen
HEYGEN_API_KEY={$config['heygen_api_key']}

# Seedance / fal.ai
FAL_API_KEY={$config['fal_api_key']}

# Missinglettr API
MISSINGLETTR_API_KEY={$config['missinglettr_api_key']}
MISSINGLETTR_WORKSPACE_ID={$config['missinglettr_workspace_id']}

# DigitalOcean Spaces
DO_SPACES_KEY={$config['do_spaces_key']}
DO_SPACES_SECRET={$config['do_spaces_secret']}
DO_SPACES_REGION={$config['do_spaces_region']}
DO_SPACES_BUCKET={$config['do_spaces_bucket']}
DO_SPACES_ENDPOINT={$doEndpoint}
DO_SPACES_CDN_URL={$doCdnUrl}

# YouTube
YOUTUBE_CLIENT_ID={$config['youtube_client_id']}
YOUTUBE_CLIENT_SECRET={$config['youtube_client_secret']}
YOUTUBE_REFRESH_TOKEN={$config['youtube_refresh_token']}

# X / Twitter
X_CONSUMER_KEY={$config['x_consumer_key']}
X_CONSUMER_SECRET={$config['x_consumer_secret']}
X_ACCESS_TOKEN={$config['x_access_token']}
X_ACCESS_TOKEN_SECRET={$config['x_access_token_secret']}

# Reddit
REDDIT_CLIENT_ID={$config['reddit_client_id']}
REDDIT_CLIENT_SECRET={$config['reddit_client_secret']}
REDDIT_USERNAME={$config['reddit_username']}
REDDIT_PASSWORD={$config['reddit_password']}

# Instagram / Facebook / Meta
META_PAGE_ACCESS_TOKEN={$config['meta_page_access_token']}
META_IG_USER_ID={$config['meta_ig_user_id']}
META_PAGE_ID={$config['meta_page_id']}

# Admin Dashboard
ADMIN_USER={$config['admin_user']}
ADMIN_PASS={$config['admin_pass']}
ENV;

// Atomic privileged write: /tmp stage → sudo install -m 600 -o www-data -g www-data.
sshWriteFile($config, '/var/www/securitydrama/.env', $envContent, 'www-data', 'www-data', '600');
success(".env file written.");

// ─── Install Composer dependencies ───

info("Installing Composer dependencies...");
// Must run as www-data so Composer's cache/temp goes to www-data's home and
// vendor/ ends up owned correctly. www-data can read .env (which it owns).
sshSudoAsStream($config, 'www-data', 'cd /var/www/securitydrama && HOME=/tmp/securitydrama composer install --no-dev --optimize-autoloader 2>&1 | tail -10');
success("Composer dependencies installed.");

// ─── Configure MySQL ───

info("Configuring MySQL...");

$escapedDbPass = str_replace("'", "''", $config['db_pass']);
$dbName = $config['db_name'];
$dbUser = $config['db_user'];

// Create the database and application user. On Ubuntu 24.04, fresh-install
// root MySQL uses auth_socket — requires UID 0 — so we invoke via sudo.
$createSql = "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
    . "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$escapedDbPass}'; "
    . "ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$escapedDbPass}'; "
    . "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$dbName}`.* TO '{$dbUser}'@'localhost'; "
    . "FLUSH PRIVILEGES;";
sshSudo($config, "mysql -e " . escapeshellarg($createSql));

// MySQL tuning — ship as a drop-in file rather than mutating the default
// mysqld.cnf. Idempotent across re-runs and safe if Ubuntu later updates
// the default config.
$mycnfContent = <<<'MYCNF'
[mysqld]
# Security Drama optimizations
bind-address = 127.0.0.1
innodb_buffer_pool_size = 256M
max_connections = 20
slow_query_log = 1
long_query_time = 2
slow_query_log_file = /var/log/mysql/mysql-slow.log
MYCNF;
sshWriteFile($config, '/etc/mysql/mysql.conf.d/99-securitydrama.cnf', $mycnfContent, 'root', 'root', '644');

sshSudo($config, 'systemctl restart mysql');
success("MySQL configured.");

// ─── Run migrations (temporarily grant CREATE/ALTER privileges) ───

info("Running database migrations...");
$grantSql = "GRANT CREATE, ALTER, INDEX, REFERENCES ON `{$dbName}`.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;";
sshSudo($config, "mysql -e " . escapeshellarg($grantSql));

// Run migrations as www-data so it can read .env (chmod 600 www-data:www-data).
sshSudoAsStream($config, 'www-data', 'cd /var/www/securitydrama && HOME=/tmp/securitydrama php cli/migrate.php 2>&1');

$revokeSql = "REVOKE CREATE, ALTER, INDEX, REFERENCES ON `{$dbName}`.* FROM '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;";
sshSudo($config, "mysql -e " . escapeshellarg($revokeSql));
success("Migrations complete.");

// ─── Seed data ───

info("Seeding feed sources, platform config, and defaults...");
sshSudoAsStream($config, 'www-data', 'cd /var/www/securitydrama && HOME=/tmp/securitydrama php cli/seed-feeds.php 2>&1');
success("Data seeded.");

// ─── Configure Apache ───

info("Configuring Apache...");

// Enable required modules
sshSudo($config, 'a2enmod rewrite ssl headers php8.3 2>&1');
sshSudo($config, 'a2dissite 000-default 2>&1');

// Write VirtualHost config
$domain = $config['domain'];
$vhostConfig = <<<VHOST
# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName {$domain}
    RewriteEngine On
    RewriteRule ^(.*)\$ https://%{HTTP_HOST}\$1 [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName {$domain}

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/{$domain}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/{$domain}/privkey.pem
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

    ErrorLog \${APACHE_LOG_DIR}/securitydrama-error.log
    CustomLog \${APACHE_LOG_DIR}/securitydrama-access.log combined
</VirtualHost>
VHOST;

sshWriteFile($config, '/etc/apache2/sites-available/securitydrama.conf', $vhostConfig, 'root', 'root', '644');

// Set Apache MaxRequestWorkers for 1GB RAM
$mpmConfig = <<<'MPMCONF'
<IfModule mpm_prefork_module>
    StartServers           1
    MinSpareServers        1
    MaxSpareServers        2
    MaxRequestWorkers      3
    MaxConnectionsPerChild 1000
</IfModule>
MPMCONF;
sshWriteFile($config, '/etc/apache2/mods-available/mpm_prefork.conf', $mpmConfig, 'root', 'root', '644');

// Create .htpasswd — file lives under /etc/apache2/, so we must sudo.
// htpasswd -cb writes the file itself; running via sudo ensures correct ownership.
$escapedAdminPass = escapeshellarg($config['admin_pass']);
$escapedAdminUser = escapeshellarg($config['admin_user']);
sshSudo($config, "htpasswd -cb /etc/apache2/.htpasswd {$escapedAdminUser} {$escapedAdminPass}");
sshSudo($config, 'chmod 640 /etc/apache2/.htpasswd && chown root:www-data /etc/apache2/.htpasswd');

// Enable site (start with HTTP-only temporarily for certbot)
sshSudo($config, 'a2ensite securitydrama 2>&1');

success("Apache configured.");

// ─── SSL Certificate ───

info("Obtaining SSL certificate via Let's Encrypt...");
echo "\n";
warn("Make sure DNS for {$domain} points to {$config['server_ip']} before continuing.");
if (confirm("DNS is configured and ready for SSL?")) {
    // Temporarily enable HTTP-only VHost for certbot
    $tempVhost = <<<TVHOST
<VirtualHost *:80>
    ServerName {$domain}
    DocumentRoot /var/www/securitydrama/web
    <Directory /var/www/securitydrama/web>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
TVHOST;
    sshWriteFile($config, '/etc/apache2/sites-available/securitydrama.conf', $tempVhost, 'root', 'root', '644');
    sshSudo($config, 'systemctl restart apache2 2>&1');

    $escapedDomain = escapeshellarg($domain);
    sshSudoStream($config, "certbot --apache -d {$escapedDomain} --non-interactive --agree-tos --email admin@{$domain} --redirect 2>&1");

    // Now write the full VHost with SSL
    sshWriteFile($config, '/etc/apache2/sites-available/securitydrama.conf', $vhostConfig, 'root', 'root', '644');
    sshSudo($config, 'systemctl restart apache2 2>&1');
    success("SSL certificate obtained and configured.");
} else {
    warn("Skipping SSL. You can run 'certbot --apache -d {$domain}' later.");
    // Write HTTP-only config
    $httpVhost = <<<HVHOST
<VirtualHost *:80>
    ServerName {$domain}
    DocumentRoot /var/www/securitydrama/web

    <Directory /var/www/securitydrama/web>
        AllowOverride All
        Require all granted
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"

    php_admin_value memory_limit 64M
    php_admin_value open_basedir "/var/www/securitydrama:/tmp/securitydrama:/var/log/securitydrama"

    ErrorLog \${APACHE_LOG_DIR}/securitydrama-error.log
    CustomLog \${APACHE_LOG_DIR}/securitydrama-access.log combined
</VirtualHost>
HVHOST;
    sshWriteFile($config, '/etc/apache2/sites-available/securitydrama.conf', $httpVhost, 'root', 'root', '644');
    sshSudo($config, 'systemctl restart apache2 2>&1');
}

// ─── PHP Configuration ───

info("Configuring PHP...");

// CLI php.ini overrides
$cliPhpIni = <<<'PHPCLI'
memory_limit = 128M
max_execution_time = 300
PHPCLI;
sshWriteFile($config, '/etc/php/8.3/cli/conf.d/99-securitydrama.ini', $cliPhpIni, 'root', 'root', '644');

// Apache php.ini - disable dangerous functions
$apachePhpIni = <<<'PHPAPACHE'
memory_limit = 64M
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,parse_ini_file,show_source
PHPAPACHE;
sshWriteFile($config, '/etc/php/8.3/apache2/conf.d/99-securitydrama.ini', $apachePhpIni, 'root', 'root', '644');

sshSudo($config, 'systemctl restart apache2 2>&1');
success("PHP configured.");

// ─── Firewall (UFW) ───

info("Configuring firewall...");
$sshPort = $config['new_ssh_port'] ?: $config['ssh_port'];
sshSudo($config, "ufw default deny incoming 2>&1");
sshSudo($config, "ufw default allow outgoing 2>&1");
sshSudo($config, "ufw allow {$sshPort}/tcp 2>&1");
sshSudo($config, "ufw allow 80/tcp 2>&1");
sshSudo($config, "ufw allow 443/tcp 2>&1");
// ufw enable prompts interactively; --force skips the prompt (cleaner than `echo y |`).
sshSudo($config, "ufw --force enable 2>&1");
success("Firewall configured.");

// ─── Fail2Ban ───

info("Configuring fail2ban...");
$jailLocal = <<<'JAILCONF'
[sshd]
enabled = true
maxretry = 5
bantime = 3600

[apache-auth]
enabled = true
maxretry = 5
bantime = 1800
JAILCONF;
sshWriteFile($config, '/etc/fail2ban/jail.local', $jailLocal, 'root', 'root', '644');

sshSudo($config, 'systemctl enable fail2ban && systemctl restart fail2ban 2>&1');
success("fail2ban configured.");

// ─── SSH Hardening ───

if ($config['new_ssh_port'] && $config['new_ssh_port'] !== $config['ssh_port']) {
    info("Hardening SSH (changing port to {$config['new_ssh_port']})...");
    $newPort = (int) $config['new_ssh_port'];
    sshSudo($config, "sed -i 's/^#\\?Port .*/Port {$newPort}/' /etc/ssh/sshd_config");
    sshSudo($config, "sed -i 's/^#\\?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config");
    sshSudo($config, "sed -i 's/^#\\?PermitRootLogin .*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config");
    sshSudo($config, 'systemctl restart sshd 2>&1');
    success("SSH hardened. New port: {$config['new_ssh_port']}");
    warn("Update your SSH config to use port {$config['new_ssh_port']} for future connections.");
}

// ─── Cron Jobs ───

info("Setting up cron jobs...");
$cronContent = <<<'CRON'
# Security Drama Pipeline - Automated Cron Jobs

# Feed ingestion - every 6 hours
0 */6 * * * /usr/bin/php /var/www/securitydrama/cli/ingest.php >> /var/log/securitydrama/ingest.log 2>&1

# Scoring and selection - 15 minutes after ingestion
15 */6 * * * /usr/bin/php /var/www/securitydrama/cli/score.php >> /var/log/securitydrama/score.log 2>&1
20 */6 * * * /usr/bin/php /var/www/securitydrama/cli/select.php >> /var/log/securitydrama/select.log 2>&1

# Script generation - every 2 hours
30 */2 * * * /usr/bin/php /var/www/securitydrama/cli/generate-script.php >> /var/log/securitydrama/script.log 2>&1

# Video generation submit - every 2 hours
45 */2 * * * /usr/bin/php /var/www/securitydrama/cli/generate-video.php >> /var/log/securitydrama/video.log 2>&1

# Video status polling - every 5 minutes
*/5 * * * * /usr/bin/php /var/www/securitydrama/cli/poll-video.php >> /var/log/securitydrama/poll.log 2>&1

# Publishing - every 30 minutes
*/30 * * * * /usr/bin/php /var/www/securitydrama/cli/publish.php >> /var/log/securitydrama/publish.log 2>&1

# Reddit engagement - crawl every 4 hours
0 */4 * * * /usr/bin/php /var/www/securitydrama/cli/reddit-crawl.php >> /var/log/securitydrama/reddit-crawl.log 2>&1

# Reddit engagement - engage every 2 hours
30 */2 * * * /usr/bin/php /var/www/securitydrama/cli/reddit-engage.php >> /var/log/securitydrama/reddit-engage.log 2>&1

# Log purge - daily at 3am
0 3 * * * /usr/bin/php /var/www/securitydrama/cli/purge-logs.php >> /var/log/securitydrama/purge.log 2>&1
CRON;

// Stage cron content to /tmp as the deploy user, then install via sudo.
// Avoids fragile `echo '...' | sudo crontab -u www-data -` pipe.
$tmpCronPath = '/tmp/sd-cron-' . bin2hex(random_bytes(6));
$cronHeredoc = "cat > " . escapeshellarg($tmpCronPath) . " << 'SDCRONEOF'\n{$cronContent}\nSDCRONEOF";
sshExec($config, $cronHeredoc);
sshSudo($config, "crontab -u www-data {$tmpCronPath} && rm -f {$tmpCronPath}");
success("Cron jobs installed.");

// ─── Log rotation ───

info("Setting up log rotation...");
$logrotateConfig = <<<'LROTATE'
/var/log/securitydrama/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
}
LROTATE;
sshWriteFile($config, '/etc/logrotate.d/securitydrama', $logrotateConfig, 'root', 'root', '644');
success("Log rotation configured.");

// ─── Unattended upgrades ───

info("Enabling unattended security upgrades...");
sshSudo($config, 'export DEBIAN_FRONTEND=noninteractive && apt-get install -y -qq unattended-upgrades 2>&1 | tail -2');
sshSudo($config, "export DEBIAN_FRONTEND=noninteractive && dpkg-reconfigure -f noninteractive unattended-upgrades 2>&1");
success("Unattended upgrades enabled.");

// ─── Final verification ───

banner('Deployment Complete');

echo "Security Drama has been deployed to your server.\n\n";

echo "  \033[32mAdmin Dashboard:\033[0m https://{$config['domain']}/\n";
echo "  \033[32mAdmin User:\033[0m     {$config['admin_user']}\n\n";

echo "  \033[33mNext steps:\033[0m\n";
echo "  1. Visit the admin dashboard and verify it loads\n";
echo "  2. Check the Config page to set HeyGen template/avatar IDs\n";
echo "  3. Run a manual feed ingestion test:\n";
echo "     ssh {$config['ssh_user']}@{$config['server_ip']} 'php /var/www/securitydrama/cli/ingest.php'\n";
echo "  4. Monitor the pipeline via the dashboard Logs page\n";
echo "  5. The pipeline will automatically run via cron on its schedule\n\n";

if ($config['new_ssh_port'] && $config['new_ssh_port'] !== $config['ssh_port']) {
    warn("SSH port changed to {$config['new_ssh_port']}. Update your connection settings.");
}

success("Deployment finished successfully!");
