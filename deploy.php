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

// ──────────────────────────────────────────────
// Helper functions
// ──────────────────────────────────────────────

function out(string $msg): void
{
    echo $msg;
}

function info(string $msg): void
{
    echo "\033[34m[INFO]\033[0m $msg\n";
}

function success(string $msg): void
{
    echo "\033[32m[OK]\033[0m $msg\n";
}

function warn(string $msg): void
{
    echo "\033[33m[WARN]\033[0m $msg\n";
}

function error(string $msg): void
{
    echo "\033[31m[ERROR]\033[0m $msg\n";
}

function banner(string $msg): void
{
    $len = strlen($msg) + 4;
    $border = str_repeat('═', $len);
    echo "\n\033[36m╔{$border}╗\033[0m\n";
    echo "\033[36m║\033[0m  $msg  \033[36m║\033[0m\n";
    echo "\033[36m╚{$border}╝\033[0m\n\n";
}

function prompt(string $question, string $default = '', bool $required = true, bool $secret = false): string
{
    $defaultDisplay = $default !== '' ? " [\033[33m{$default}\033[0m]" : '';
    $requiredDisplay = $required && $default === '' ? ' \033[31m(required)\033[0m' : '';

    out("  {$question}{$defaultDisplay}{$requiredDisplay}: ");

    if ($secret) {
        system('stty -echo 2>/dev/null');
        $value = trim(fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
    } else {
        $value = trim(fgets(STDIN));
    }

    if ($value === '' && $default !== '') {
        return $default;
    }

    if ($value === '' && $required) {
        error("This field is required.");
        return prompt($question, $default, $required, $secret);
    }

    return $value;
}

function confirm(string $question, bool $default = true): bool
{
    $hint = $default ? 'Y/n' : 'y/N';
    out("  {$question} [{$hint}]: ");
    $answer = strtolower(trim(fgets(STDIN)));

    if ($answer === '') {
        return $default;
    }
    return in_array($answer, ['y', 'yes']);
}

function sshExec(array $config, string $command, bool $silent = false): string
{
    $port = $config['ssh_port'];
    $user = $config['ssh_user'];
    $host = $config['server_ip'];
    $keyFlag = isset($config['ssh_key']) && $config['ssh_key'] !== ''
        ? "-i " . escapeshellarg($config['ssh_key'])
        : '';

    $sshCmd = sprintf(
        'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %s %s %s@%s %s 2>&1',
        escapeshellarg($port),
        $keyFlag,
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($command)
    );

    if (!$silent) {
        info("Running: $command");
    }

    $output = shell_exec($sshCmd);
    return $output ?? '';
}

function sshExecStream(array $config, string $command): int
{
    $port = $config['ssh_port'];
    $user = $config['ssh_user'];
    $host = $config['server_ip'];
    $keyFlag = isset($config['ssh_key']) && $config['ssh_key'] !== ''
        ? "-i " . escapeshellarg($config['ssh_key'])
        : '';

    $sshCmd = sprintf(
        'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %s %s %s@%s %s 2>&1',
        escapeshellarg($port),
        $keyFlag,
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($command)
    );

    passthru($sshCmd, $exitCode);
    return $exitCode;
}

function scpUpload(array $config, string $localPath, string $remotePath): bool
{
    $port = $config['ssh_port'];
    $user = $config['ssh_user'];
    $host = $config['server_ip'];
    $keyFlag = isset($config['ssh_key']) && $config['ssh_key'] !== ''
        ? "-i " . escapeshellarg($config['ssh_key'])
        : '';

    $cmd = sprintf(
        'scp -o StrictHostKeyChecking=no -P %s %s -r %s %s@%s:%s 2>&1',
        escapeshellarg($port),
        $keyFlag,
        escapeshellarg($localPath),
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($remotePath)
    );

    $output = shell_exec($cmd);
    return $output !== null;
}

function rsyncUpload(array $config, string $localPath, string $remotePath): bool
{
    $port = $config['ssh_port'];
    $user = $config['ssh_user'];
    $host = $config['server_ip'];
    $keyFlag = isset($config['ssh_key']) && $config['ssh_key'] !== ''
        ? "-i " . escapeshellarg($config['ssh_key'])
        : '';

    $sshOpt = sprintf(
        'ssh -o StrictHostKeyChecking=no -p %s %s',
        escapeshellarg($port),
        $keyFlag
    );

    $cmd = sprintf(
        'rsync -avz --exclude=".git" --exclude="vendor" --exclude=".env" --exclude="*.log" --exclude="security-drama-technical-spec.md" --exclude="deploy.php" -e %s %s %s@%s:%s 2>&1',
        escapeshellarg($sshOpt),
        escapeshellarg(rtrim($localPath, '/') . '/'),
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($remotePath)
    );

    info("Uploading application code...");
    passthru($cmd, $exitCode);
    return $exitCode === 0;
}

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
$config['ssh_user'] = prompt('SSH username', 'root');
$config['ssh_port'] = prompt('SSH port', '22');
$config['ssh_key'] = prompt('SSH private key path (leave empty for default)', '', false);
$config['domain'] = prompt('Admin dashboard domain (e.g., admin.securitydrama.com)');
$config['new_ssh_port'] = prompt('New SSH port for hardening (leave empty to keep current)', '', false);

// Test SSH connection
info("Testing SSH connection...");
$testOutput = sshExec($config, 'echo "SSH_OK"', true);
if (strpos($testOutput, 'SSH_OK') === false) {
    error("Cannot connect to {$config['server_ip']}. Check your credentials.");
    error("Output: $testOutput");
    exit(1);
}
success("SSH connection successful.");

// ─── Step 2: Database credentials ───

banner('Step 2: Database Configuration');

$config['db_name'] = prompt('MySQL database name', 'securitydrama');
$config['db_user'] = prompt('MySQL application username', 'securitydrama');
$config['db_pass'] = prompt('MySQL application password (will be created)', '', true, true);
$config['db_root_pass'] = prompt('MySQL root password (will be set)', '', true, true);

// ─── Step 3: API keys ───

banner('Step 3: API Keys');

echo "Enter your API keys. These will be written to the .env file on the server.\n";
echo "Leave optional keys empty if not yet available.\n\n";

$config['anthropic_api_key'] = prompt('Anthropic (Claude) API key', '', true, true);
$config['heygen_api_key'] = prompt('HeyGen API key', '', false, true);

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
sshExecStream($config, 'export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && apt-get install -y -qq software-properties-common 2>&1 | tail -5');

sshExecStream($config, 'export DEBIAN_FRONTEND=noninteractive && add-apt-repository -y ppa:ondrej/php 2>&1 | tail -3');

sshExecStream($config, 'export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && apt-get install -y -qq \
    php8.3 php8.3-cli php8.3-mysql php8.3-curl php8.3-xml php8.3-mbstring php8.3-zip php8.3-gd php8.3-intl \
    libapache2-mod-php8.3 \
    apache2 \
    mysql-server \
    certbot python3-certbot-apache \
    fail2ban \
    ufw \
    unzip curl git \
    2>&1 | tail -10');

success("System packages installed.");

// ─── Install Composer ───

info("Installing Composer...");
sshExec($config, 'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer 2>&1');
success("Composer installed.");

// ─── Create directory structure ───

info("Creating directory structure...");
sshExec($config, 'mkdir -p /var/www/securitydrama && mkdir -p /var/log/securitydrama && mkdir -p /tmp/securitydrama');
sshExec($config, 'chown -R www-data:www-data /var/log/securitydrama /tmp/securitydrama');

// ─── Upload application code ───

info("Uploading application code...");
$projectDir = dirname(__FILE__);
$uploaded = rsyncUpload($config, $projectDir, '/var/www/securitydrama');
if (!$uploaded) {
    error("Failed to upload application code.");
    exit(1);
}
success("Application code uploaded.");

// ─── Set ownership and permissions ───

info("Setting file permissions...");
sshExec($config, 'chown -R www-data:www-data /var/www/securitydrama');
sshExec($config, 'find /var/www/securitydrama -type f -exec chmod 644 {} \;');
sshExec($config, 'find /var/www/securitydrama -type d -exec chmod 755 {} \;');

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

// Write .env via SSH (avoids file transfer issues with special chars)
$escapedEnv = str_replace("'", "'\\''", $envContent);
sshExec($config, "cat > /var/www/securitydrama/.env << 'ENVEOF'\n{$envContent}\nENVEOF");
sshExec($config, 'chmod 600 /var/www/securitydrama/.env && chown www-data:www-data /var/www/securitydrama/.env');
success(".env file written.");

// ─── Install Composer dependencies ───

info("Installing Composer dependencies...");
sshExecStream($config, 'cd /var/www/securitydrama && composer install --no-dev --optimize-autoloader 2>&1 | tail -10');
success("Composer dependencies installed.");

// ─── Configure MySQL ───

info("Configuring MySQL...");

// Secure MySQL installation
$escapedRootPass = str_replace("'", "'\\''", $config['db_root_pass']);
$escapedDbPass = str_replace("'", "'\\''", $config['db_pass']);
$dbName = $config['db_name'];
$dbUser = $config['db_user'];

sshExec($config, "mysql -u root << 'SQLEOF'
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '{$escapedRootPass}';
CREATE DATABASE IF NOT EXISTS {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$escapedDbPass}';
GRANT SELECT, INSERT, UPDATE, DELETE ON {$dbName}.* TO '{$dbUser}'@'localhost';
FLUSH PRIVILEGES;
SQLEOF");

// MySQL hardening
sshExec($config, "cat >> /etc/mysql/mysql.conf.d/mysqld.cnf << 'MYCNF'

# Security Drama optimizations
bind-address = 127.0.0.1
innodb_buffer_pool_size = 256M
max_connections = 20
slow_query_log = 1
long_query_time = 2
slow_query_log_file = /var/log/mysql/mysql-slow.log
MYCNF");

sshExec($config, 'systemctl restart mysql');
success("MySQL configured.");

// ─── Run migrations (as root user who has CREATE TABLE privileges) ───

info("Running database migrations...");
// Grant temporary CREATE privilege for migrations
sshExec($config, "mysql -u root -p'{$escapedRootPass}' -e \"GRANT CREATE, ALTER, INDEX, REFERENCES ON {$dbName}.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");

sshExecStream($config, 'cd /var/www/securitydrama && php cli/migrate.php 2>&1');

// Revoke CREATE privilege after migrations
sshExec($config, "mysql -u root -p'{$escapedRootPass}' -e \"REVOKE CREATE, ALTER, INDEX, REFERENCES ON {$dbName}.* FROM '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");
success("Migrations complete.");

// ─── Seed data ───

info("Seeding feed sources, platform config, and defaults...");
sshExecStream($config, 'cd /var/www/securitydrama && php cli/seed-feeds.php 2>&1');
success("Data seeded.");

// ─── Configure Apache ───

info("Configuring Apache...");

// Enable required modules
sshExec($config, 'a2enmod rewrite ssl headers php8.3 2>&1');
sshExec($config, 'a2dissite 000-default 2>&1');

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

sshExec($config, "cat > /etc/apache2/sites-available/securitydrama.conf << 'VHOSTEOF'\n{$vhostConfig}\nVHOSTEOF");

// Set Apache MaxRequestWorkers for 1GB RAM
sshExec($config, "cat > /etc/apache2/mods-available/mpm_prefork.conf << 'MPMEOF'
<IfModule mpm_prefork_module>
    StartServers           1
    MinSpareServers        1
    MaxSpareServers        2
    MaxRequestWorkers      3
    MaxConnectionsPerChild 1000
</IfModule>
MPMEOF");

// Create .htpasswd
$escapedAdminPass = str_replace("'", "'\\''", $config['admin_pass']);
sshExec($config, "htpasswd -cb /etc/apache2/.htpasswd '{$config['admin_user']}' '{$escapedAdminPass}'");

// Enable site (start with HTTP-only temporarily for certbot)
sshExec($config, 'a2ensite securitydrama 2>&1');

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
    sshExec($config, "cat > /etc/apache2/sites-available/securitydrama.conf << 'TVHOSTEOF'\n{$tempVhost}\nTVHOSTEOF");
    sshExec($config, 'systemctl restart apache2 2>&1');

    sshExecStream($config, "certbot --apache -d {$domain} --non-interactive --agree-tos --email admin@{$domain} --redirect 2>&1");

    // Now write the full VHost with SSL
    sshExec($config, "cat > /etc/apache2/sites-available/securitydrama.conf << 'VHOSTEOF'\n{$vhostConfig}\nVHOSTEOF");
    sshExec($config, 'systemctl restart apache2 2>&1');
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
    sshExec($config, "cat > /etc/apache2/sites-available/securitydrama.conf << 'HVHOSTEOF'\n{$httpVhost}\nHVHOSTEOF");
    sshExec($config, 'systemctl restart apache2 2>&1');
}

// ─── PHP Configuration ───

info("Configuring PHP...");

// CLI php.ini overrides
sshExec($config, "cat > /etc/php/8.3/cli/conf.d/99-securitydrama.ini << 'PHPEOF'
memory_limit = 128M
max_execution_time = 300
PHPEOF");

// Apache php.ini - disable dangerous functions
sshExec($config, "cat > /etc/php/8.3/apache2/conf.d/99-securitydrama.ini << 'PHPEOF'
memory_limit = 64M
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,parse_ini_file,show_source
PHPEOF");

sshExec($config, 'systemctl restart apache2 2>&1');
success("PHP configured.");

// ─── Firewall (UFW) ───

info("Configuring firewall...");
$sshPort = $config['new_ssh_port'] ?: $config['ssh_port'];
sshExec($config, "ufw default deny incoming 2>&1");
sshExec($config, "ufw default allow outgoing 2>&1");
sshExec($config, "ufw allow {$sshPort}/tcp 2>&1");
sshExec($config, "ufw allow 80/tcp 2>&1");
sshExec($config, "ufw allow 443/tcp 2>&1");
sshExec($config, "echo 'y' | ufw enable 2>&1");
success("Firewall configured.");

// ─── Fail2Ban ───

info("Configuring fail2ban...");
sshExec($config, "cat > /etc/fail2ban/jail.local << 'F2BEOF'
[sshd]
enabled = true
maxretry = 5
bantime = 3600

[apache-auth]
enabled = true
maxretry = 5
bantime = 1800
F2BEOF");

sshExec($config, 'systemctl enable fail2ban && systemctl restart fail2ban 2>&1');
success("fail2ban configured.");

// ─── SSH Hardening ───

if ($config['new_ssh_port'] && $config['new_ssh_port'] !== $config['ssh_port']) {
    info("Hardening SSH (changing port to {$config['new_ssh_port']})...");
    sshExec($config, "sed -i 's/^#\\?Port .*/Port {$config['new_ssh_port']}/' /etc/ssh/sshd_config");
    sshExec($config, "sed -i 's/^#\\?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config");
    sshExec($config, "sed -i 's/^#\\?PermitRootLogin .*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config");
    sshExec($config, 'systemctl restart sshd 2>&1');
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

sshExec($config, "echo '{$cronContent}' | crontab -u www-data -");
success("Cron jobs installed.");

// ─── Log rotation ───

info("Setting up log rotation...");
sshExec($config, "cat > /etc/logrotate.d/securitydrama << 'LREOF'
/var/log/securitydrama/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
}
LREOF");
success("Log rotation configured.");

// ─── Unattended upgrades ───

info("Enabling unattended security upgrades...");
sshExec($config, 'apt-get install -y -qq unattended-upgrades 2>&1 | tail -2');
sshExec($config, "dpkg-reconfigure -f noninteractive unattended-upgrades 2>&1");
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
