<?php
/**
 * Security Drama - Incremental code sync tool
 *
 * Ships code changes from a clean local repo to a droplet already set up by
 * deploy.php. Runs from the operator's machine. Reuses deploy-lib.php for
 * all SSH/sudo transport.
 *
 * Usage:
 *   php sync.php                      # interactive, uses ~/.security-drama/sync.json
 *   php sync.php --config=/path.json  # override config path
 *   php sync.php --dry-run            # rsync --dry-run, no writes, no kill switch
 *   php sync.php --rollback           # interactive snapshot picker
 *
 * Flow (sync mode):
 *   0. Preflight (clean git tree, confirm)
 *   1. Load or create local config
 *   2. SSH + sudo probe
 *   3. Acquire remote sentinel lock
 *   4. Engage kill switch; wait for in-flight pipeline to finish
 *   5. Snapshot current deployment via hardlink tree
 *   6. Diff remote vs local to decide composer/migrate/seed
 *   7. rsync --delete
 *   8. composer install (if needed)
 *   9. migrate (always prompt)
 *  10. seed-feeds (prompt only if needed)
 *  11. Write VERSION file
 *  12. Smoke tests (validate-only + health.php)
 *  13. Release kill switch
 *  14. Prune old snapshots
 *  15. Release sentinel, success banner
 *
 * Any failure after Phase 5 triggers a rollback from the snapshot taken in
 * Phase 5, followed by a re-run of Phase 12 and release of the kill switch.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die("PHP 8.0+ required.\n");
}

require_once __DIR__ . '/deploy-lib.php';

// ──────────────────────────────────────────────
// Constants
// ──────────────────────────────────────────────

const SYNC_SENTINEL_PATH = '/var/lock/securitydrama-sync.lock';
const SYNC_DEFAULT_CONFIG = '~/.security-drama/sync.json';
const SYNC_PIPELINE_LOCK = '/tmp/securitydrama_pipeline.lock';

// ──────────────────────────────────────────────
// Arg parsing
// ──────────────────────────────────────────────

$dryRun    = false;
$rollback  = false;
$cfgPath   = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--rollback') {
        $rollback = true;
    } elseif (str_starts_with($arg, '--config=')) {
        $cfgPath = substr($arg, 9);
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "Usage: php sync.php [--dry-run] [--rollback] [--config=PATH]\n";
        exit(0);
    } else {
        error("Unknown argument: {$arg}");
        exit(1);
    }
}

$cfgPath = $cfgPath ?? expandHome(SYNC_DEFAULT_CONFIG);

// ──────────────────────────────────────────────
// Dispatch
// ──────────────────────────────────────────────

try {
    if ($rollback) {
        runRollback($cfgPath);
    } else {
        runSync($cfgPath, $dryRun);
    }
} catch (\Throwable $e) {
    error($e->getMessage());
    exit(1);
}

// ══════════════════════════════════════════════
// Main flows
// ══════════════════════════════════════════════

function runSync(string $cfgPath, bool $dryRun): void
{
    banner('Security Drama - Sync Tool' . ($dryRun ? ' (DRY RUN)' : ''));

    // ─── Phase 0: local preflight ───
    [$gitSha, $gitBranch] = preflightGit();

    // ─── Phase 1: config ───
    $config = loadOrCreateConfig($cfgPath);

    echo "\n";
    info("Target:    {$config['ssh_user']}@{$config['server_ip']}:{$config['ssh_port']}");
    info("App dir:   {$config['app_dir']}");
    info("Git SHA:   {$gitSha} (branch {$gitBranch})");
    info("Dry run:   " . ($dryRun ? 'yes' : 'no'));
    echo "\n";

    if (!confirm('Proceed with sync?', true)) {
        echo "Aborted.\n";
        return;
    }

    // ─── Phase 2: connection + sudo probe ───
    banner('Phase 2: Connection + sudo probe');
    probeConnection($config);

    // ─── Dry run short-circuit ───
    // We still do the rsync dry run so the user can see what would change,
    // but we skip the sentinel, kill switch, snapshot, migrations, and smoke
    // tests. Nothing is persisted on the server.
    if ($dryRun) {
        banner('Phase 7: rsync (dry run)');
        performRsync($config, __DIR__, true);
        success('Dry run complete. No changes were made.');
        return;
    }

    // ─── Phase 3: sentinel lock ───
    banner('Phase 3: Acquire sync sentinel');
    acquireSentinel($config);

    $snapshotName = null;
    $killSwitchEngaged = false;

    try {
        // ─── Phase 4: kill switch + drain pipeline ───
        banner('Phase 4: Engage kill switch');
        engageKillSwitch($config);
        $killSwitchEngaged = true;
        waitForPipelineToFinish($config);

        // ─── Phase 5: snapshot ───
        banner('Phase 5: Snapshot current deployment');
        $snapshotName = takeSnapshot($config, $gitSha);
        success("Snapshot created: {$snapshotName}");

        // ─── Phase 6: diff remote vs local ───
        banner('Phase 6: Detect what needs to run');
        [$needsComposer, $needsMigration, $needsSeed] = detectChanges($config);
        info('composer install needed: ' . ($needsComposer ? 'yes' : 'no'));
        info('migrations changed:      ' . ($needsMigration ? 'yes' : 'no'));
        info('seed/feeds changed:      ' . ($needsSeed ? 'yes' : 'no'));

        // ─── Phase 7: rsync ───
        banner('Phase 7: rsync');
        performRsync($config, __DIR__, false);
        sshSudo($config, "chown -R www-data:www-data " . escapeshellarg($config['app_dir']));

        // ─── Phase 8: composer ───
        if ($needsComposer) {
            banner('Phase 8: composer install');
            runComposer($config);
        } else {
            info('Skipping composer install (composer.lock unchanged).');
        }

        // ─── Phase 9: migrations ───
        banner('Phase 9: Migrations');
        if (confirm('Run migrations?', $needsMigration)) {
            runMigrate($config);
        } else {
            info('Skipping migrations at operator request.');
        }

        // ─── Phase 10: seed ───
        if ($needsSeed) {
            banner('Phase 10: Seed feeds');
            if (confirm('Re-run seed-feeds?', true)) {
                runSeed($config);
            } else {
                info('Skipping seed-feeds at operator request.');
            }
        }

        // ─── Phase 11: VERSION ───
        banner('Phase 11: Write VERSION file');
        writeVersionFile($config, $gitSha);

        // ─── Phase 12: smoke tests ───
        banner('Phase 12: Smoke tests');
        smokeTest($config, $gitSha);

        // ─── Phase 13: release kill switch ───
        banner('Phase 13: Release kill switch');
        releaseKillSwitch($config);
        $killSwitchEngaged = false;

        // ─── Phase 14: prune snapshots ───
        banner('Phase 14: Prune old snapshots');
        pruneSnapshots($config);
    } catch (\Throwable $e) {
        error('Sync failed: ' . $e->getMessage());

        $rollbackOk = false;
        if ($snapshotName !== null) {
            warn("Attempting rollback to snapshot: {$snapshotName}");
            try {
                rollbackTo($config, $snapshotName);
                $rollbackOk = true;
            } catch (\Throwable $rb) {
                error('ROLLBACK FAILED: ' . $rb->getMessage());
                error('Snapshot still available at: ' . $config['snapshot_dir'] . '/' . $snapshotName);
            }
        } else {
            warn('No snapshot was taken — nothing to roll back to.');
        }

        // Always try to release the kill switch — leaving it engaged silently
        // pauses the live pipeline, which is the worst end state.
        if ($killSwitchEngaged) {
            try {
                releaseKillSwitch($config);
                $killSwitchEngaged = false;
            } catch (\Throwable $ks) {
                error('Failed to release kill switch: ' . $ks->getMessage());
                error("Set pipeline_enabled=1 manually: sudo mysql <db> -e \"UPDATE config SET config_value='1' WHERE config_key='pipeline_enabled'\"");
            }
        }

        // Snapshot-agnostic check: the rolled-back code may predate validate-only
        // or health.php (e.g. first sync after deploy). Just confirm the server
        // is reachable and the DB responds.
        if ($rollbackOk) {
            try {
                smokeTestMinimal($config);
                success('Rollback complete — server is back on the previous release.');
            } catch (\Throwable $sm) {
                error('Post-rollback minimal smoke test failed: ' . $sm->getMessage());
                error('The server is in a broken state. Manual intervention required.');
            }
        } else {
            error('The server is in a broken state. Manual intervention required.');
        }

        releaseSentinel($config);
        throw $e;
    }

    // ─── Phase 15: teardown ───
    releaseSentinel($config);
    banner('Sync Complete');
    success("Deployed {$gitSha} to {$config['server_ip']}");
    info("Snapshot retained: {$snapshotName}");
    info("Dashboard: https://{$config['domain']}/");
}

function runRollback(string $cfgPath): void
{
    banner('Security Drama - Rollback Tool');

    $config = loadOrCreateConfig($cfgPath);

    probeConnection($config);

    $snapshots = listSnapshots($config);
    if (count($snapshots) === 0) {
        error('No snapshots found at ' . $config['snapshot_dir']);
        exit(1);
    }

    echo "\nAvailable snapshots (most recent first):\n";
    foreach ($snapshots as $i => $name) {
        printf("  [%d] %s\n", $i + 1, $name);
    }
    echo "\n";

    $choice = (int) prompt('Pick a snapshot number', '1');
    if ($choice < 1 || $choice > count($snapshots)) {
        error('Invalid choice.');
        exit(1);
    }
    $snapshotName = $snapshots[$choice - 1];

    if (!confirm("Roll back to {$snapshotName}? This will overwrite {$config['app_dir']}.", false)) {
        echo "Aborted.\n";
        return;
    }

    acquireSentinel($config);
    $killSwitchEngaged = false;
    try {
        engageKillSwitch($config);
        $killSwitchEngaged = true;
        waitForPipelineToFinish($config);

        rollbackTo($config, $snapshotName);
        // The picked snapshot may predate the smoke-test infrastructure
        // (validate-only / health.php). Use the snapshot-agnostic check.
        smokeTestMinimal($config);

        releaseKillSwitch($config);
        $killSwitchEngaged = false;
    } catch (\Throwable $e) {
        error('Rollback failed: ' . $e->getMessage());
        if ($killSwitchEngaged) {
            warn('Kill switch is still engaged. Set pipeline_enabled=1 manually once the server is healthy.');
        }
        releaseSentinel($config);
        throw $e;
    }

    releaseSentinel($config);
    banner('Rollback Complete');
    success("Rolled back to {$snapshotName}");
}

// ══════════════════════════════════════════════
// Phase 0: local git preflight
// ══════════════════════════════════════════════

/**
 * @return array{0:string,1:string} [shortSha, branch]
 */
function preflightGit(): array
{
    $insideRepo = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --is-inside-work-tree 2>/dev/null'));
    if ($insideRepo !== 'true') {
        throw new RuntimeException('sync.php must run from inside a git checkout of the security-drama repo.');
    }

    $status = (string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' status --porcelain 2>/dev/null');
    if (trim($status) !== '') {
        error('Working tree is not clean. Commit or stash changes before syncing:');
        echo $status . "\n";
        throw new RuntimeException('Dirty working tree.');
    }

    $sha    = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --short HEAD 2>/dev/null'));
    $branch = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --abbrev-ref HEAD 2>/dev/null'));

    if ($sha === '') {
        throw new RuntimeException('Could not read git SHA — is this a git repository?');
    }

    return [$sha, $branch];
}

// ══════════════════════════════════════════════
// Phase 1: config load / create
// ══════════════════════════════════════════════

function loadOrCreateConfig(string $path): array
{
    if (is_file($path)) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read config file: {$path}");
        }
        $cfg = json_decode($raw, true);
        if (!is_array($cfg)) {
            throw new RuntimeException("Config file is not valid JSON: {$path}");
        }
        info("Loaded config from {$path}");
        return normalizeConfig($cfg);
    }

    info("No config at {$path} — let's create one.");
    $cfg = promptConfigFields();

    if (confirm("Save to {$path}?", true)) {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create config directory: {$dir}");
        }
        file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT) . "\n");
        chmod($path, 0600);
        success("Wrote {$path} (mode 600)");
    }

    return normalizeConfig($cfg);
}

function promptConfigFields(): array
{
    $cfg = [];
    $cfg['server_ip']      = prompt('Server IP address');
    $cfg['ssh_port']       = prompt('SSH port', '22');
    $cfg['ssh_user']       = prompt('SSH username', 'deploy');
    $cfg['ssh_key']        = prompt('SSH private key path (leave empty for default)', '', false);
    $cfg['sudo_pass']      = ($cfg['ssh_user'] === 'root')
        ? ''
        : prompt('Sudo password (leave empty for NOPASSWD)', '', false, true);
    $cfg['domain']         = prompt('Admin domain (e.g. securitydrama.example)');
    $cfg['app_dir']        = prompt('App directory', '/var/www/securitydrama');
    $cfg['snapshot_dir']   = prompt('Snapshot directory', '/var/www/securitydrama-snapshots');
    $cfg['keep_snapshots'] = (int) prompt('Snapshots to retain', '5');
    return $cfg;
}

function normalizeConfig(array $cfg): array
{
    $defaults = [
        'server_ip'      => '',
        'ssh_port'       => '22',
        'ssh_user'       => 'deploy',
        'ssh_key'        => '',
        'sudo_pass'      => '',
        'domain'         => '',
        'app_dir'        => '/var/www/securitydrama',
        'snapshot_dir'   => '/var/www/securitydrama-snapshots',
        'keep_snapshots' => 5,
    ];
    $cfg = array_merge($defaults, $cfg);
    $cfg['keep_snapshots'] = max(1, (int) $cfg['keep_snapshots']);
    if ($cfg['server_ip'] === '' || $cfg['ssh_user'] === '') {
        throw new RuntimeException('Config is missing server_ip or ssh_user.');
    }
    return $cfg;
}

// ══════════════════════════════════════════════
// Phase 2: connection + sudo probe
// ══════════════════════════════════════════════

function probeConnection(array $config): void
{
    $output = sshExec($config, 'echo SSH_OK', true);
    if (strpos($output, 'SSH_OK') === false) {
        throw new RuntimeException("SSH connection failed:\n" . $output);
    }
    success('SSH connection OK.');

    if (!sshSudoProbe($config)) {
        error("sudo access check failed.");
        error("The SSH user must either be root or have sudo privileges.");
        error("For NOPASSWD sudo, add to /etc/sudoers.d/{$config['ssh_user']}:");
        error("  {$config['ssh_user']} ALL=(ALL) NOPASSWD:ALL");
        throw new RuntimeException('sudo probe failed.');
    }
    success('sudo access confirmed.');

    $check = sshSudo(
        $config,
        'test -d ' . escapeshellarg($config['app_dir']) . ' && echo APP_DIR_OK',
        true
    );
    if (strpos($check, 'APP_DIR_OK') === false) {
        throw new RuntimeException(
            "Target app directory {$config['app_dir']} does not exist on the server. "
            . "Run deploy.php first."
        );
    }
    success("App directory exists: {$config['app_dir']}");
}

// ══════════════════════════════════════════════
// Phase 3: sentinel lock
// ══════════════════════════════════════════════

function acquireSentinel(array $config): void
{
    $sentinel = SYNC_SENTINEL_PATH;

    $check = sshSudo(
        $config,
        'test -f ' . escapeshellarg($sentinel) . ' && cat ' . escapeshellarg($sentinel) . ' || echo NO_LOCK',
        true
    );

    if (strpos($check, 'NO_LOCK') === false) {
        error("Remote sentinel already exists at {$sentinel}:");
        echo $check . "\n";
        error('Another sync may be running, or a previous one crashed.');
        error("If you are sure no sync is in progress, remove it with:");
        error("  ssh {$config['ssh_user']}@{$config['server_ip']} sudo rm {$sentinel}");
        throw new RuntimeException('Sync sentinel already present.');
    }

    $content = "pid=" . getmypid() . "\n"
             . "host=" . gethostname() . "\n"
             . "start=" . date('c') . "\n";

    sshWriteFile($config, $sentinel, $content, 'root', 'root', '644');
    success("Acquired sentinel {$sentinel}");
}

function releaseSentinel(array $config): void
{
    sshSudo($config, 'rm -f ' . escapeshellarg(SYNC_SENTINEL_PATH), true);
}

// ══════════════════════════════════════════════
// Phase 4: kill switch + drain
// ══════════════════════════════════════════════

function engageKillSwitch(array $config): void
{
    setKillSwitch($config, '0');
    success('Kill switch engaged (pipeline_enabled=0).');
}

function releaseKillSwitch(array $config): void
{
    setKillSwitch($config, '1');
    success('Kill switch released (pipeline_enabled=1).');
}

/**
 * Flip pipeline_enabled via direct MySQL access. Goes through `sudo mysql`
 * (auth_socket), the same mechanism deploy.php uses for DDL. Deliberately
 * does NOT call cli/kill-switch.php — that file may not exist on the server
 * yet during the sync that introduces it (chicken-and-egg), and we want the
 * kill switch engaged *before* we rsync new code.
 */
function setKillSwitch(array $config, string $value): void
{
    // We can't easily discover the DB name from sync.php (it lives in .env,
    // which is chmod 600 www-data). Grepping .env as www-data is the cleanest
    // option and preserves the "no DB name in sync config" design.
    $db = trim(sshSudoAs(
        $config,
        'www-data',
        "grep -E '^DB_NAME=' " . escapeshellarg(rtrim($config['app_dir'], '/') . '/.env') . " | cut -d= -f2-"
    ));
    $db = trim($db, "\"' \t\n\r");
    if ($db === '') {
        throw new RuntimeException('Could not read DB_NAME from remote .env — is the app deployed?');
    }

    $sql = "UPDATE config SET config_value='" . $value . "' WHERE config_key='pipeline_enabled'";
    sshSudo(
        $config,
        'mysql ' . escapeshellarg($db) . ' -e ' . escapeshellarg($sql),
        true
    );

    // Verify the write landed.
    $check = sshSudo(
        $config,
        'mysql ' . escapeshellarg($db) . " -N -e \"SELECT config_value FROM config WHERE config_key='pipeline_enabled'\"",
        true
    );
    if (trim($check) !== $value) {
        throw new RuntimeException(
            "Kill switch did not take effect (wanted {$value}, got '" . trim($check) . "')"
        );
    }
}

function waitForPipelineToFinish(array $config): void
{
    info('Waiting for any in-flight pipeline run to finish...');
    for ($i = 0; $i < 60; $i++) {
        $out = sshSudo(
            $config,
            'fuser ' . escapeshellarg(SYNC_PIPELINE_LOCK) . ' 2>/dev/null || echo CLEAR',
            true
        );
        if (strpos($out, 'CLEAR') !== false) {
            success('Pipeline lock is clear.');
            return;
        }
        sleep(5);
    }
    throw new RuntimeException(
        'Pipeline lock is still held after 5 minutes. Investigate with '
        . "`sudo fuser {$config['ssh_user']}@{$config['server_ip']}:/tmp/securitydrama_pipeline.lock`"
    );
}

// ══════════════════════════════════════════════
// Phase 5: snapshot
// ══════════════════════════════════════════════

function takeSnapshot(array $config, string $gitSha): string
{
    $name = date('Ymd-His') . '-' . $gitSha;
    $dest = rtrim($config['snapshot_dir'], '/') . '/' . $name;

    sshSudo($config, 'mkdir -p ' . escapeshellarg($config['snapshot_dir']));
    sshSudo(
        $config,
        'cp -al ' . escapeshellarg($config['app_dir']) . ' ' . escapeshellarg($dest)
    );

    $check = sshSudo(
        $config,
        'test -d ' . escapeshellarg($dest) . ' && echo SNAP_OK',
        true
    );
    if (strpos($check, 'SNAP_OK') === false) {
        throw new RuntimeException("Snapshot directory was not created: {$dest}");
    }
    return $name;
}

// ══════════════════════════════════════════════
// Phase 6: diff remote vs local
// ══════════════════════════════════════════════

/**
 * @return array{0:bool,1:bool,2:bool} [needsComposer, needsMigration, needsSeed]
 */
function detectChanges(array $config): array
{
    return [
        remoteFileDiffers($config, __DIR__ . '/composer.lock', '/composer.lock'),
        remoteFileDiffers($config, __DIR__ . '/cli/migrate.php', '/cli/migrate.php'),
        remoteFileDiffers($config, __DIR__ . '/cli/seed-feeds.php', '/cli/seed-feeds.php')
            || remoteFileDiffers($config, __DIR__ . '/config/feeds.php', '/config/feeds.php'),
    ];
}

function remoteFileDiffers(array $config, string $localPath, string $remoteRel): bool
{
    if (!is_file($localPath)) {
        // If the local file is missing, we can't compare — treat as "changed"
        // so the caller prompts and the operator can decide.
        return true;
    }

    $remoteAbs = rtrim($config['app_dir'], '/') . $remoteRel;

    $out = sshSudo(
        $config,
        'sha1sum ' . escapeshellarg($remoteAbs) . ' 2>/dev/null || echo MISSING',
        true
    );
    if (strpos($out, 'MISSING') !== false) {
        return true;
    }

    $parts = preg_split('/\s+/', trim($out));
    $remoteSha = $parts[0] ?? '';
    $localSha  = sha1_file($localPath);

    return $remoteSha !== $localSha;
}

// ══════════════════════════════════════════════
// Phase 7: rsync
// ══════════════════════════════════════════════

function performRsync(array $config, string $projectDir, bool $dryRun): void
{
    $excludes = [
        '.git',
        'vendor',
        '.env',
        '*.log',
        'security-drama-technical-spec.md',
        'deploy.php',
        'sync.php',
        'deploy-lib.php',
        '/VERSION',
        'node_modules',
    ];
    $extraFlags = ['--delete'];
    if ($dryRun) {
        $extraFlags[] = '--dry-run';
    }

    // Before rsync, give the deploy user write access to app_dir so rsync can
    // land files. We chown back to www-data after (Phase 7 tail).
    sshSudo(
        $config,
        'chown -R ' . escapeshellarg($config['ssh_user']) . ':' . escapeshellarg($config['ssh_user'])
        . ' ' . escapeshellarg($config['app_dir'])
    );

    $ok = rsyncUpload($config, $projectDir, $config['app_dir'], $excludes, $extraFlags);
    if (!$ok) {
        throw new RuntimeException('rsync failed.');
    }
    success($dryRun ? 'rsync dry run complete.' : 'rsync complete.');
}

// ══════════════════════════════════════════════
// Phase 8–10: app commands as www-data
// ══════════════════════════════════════════════

function runComposer(array $config): void
{
    $cmd = 'cd ' . escapeshellarg($config['app_dir'])
        . ' && HOME=/tmp/securitydrama composer install --no-dev --optimize-autoloader';
    $code = sshSudoAsStream($config, 'www-data', $cmd);
    if ($code !== 0) {
        throw new RuntimeException("composer install exited with code {$code}");
    }
    success('composer install complete.');
}

function runMigrate(array $config): void
{
    // migrate.php must run as root: it uses MySQL auth_socket for DDL because
    // the runtime app user (securitydrama) is intentionally restricted to
    // SELECT/INSERT/UPDATE/DELETE only. Connecting as root via the unix socket
    // is the same auth path deploy.php uses for its initial bootstrap.
    $cmd = 'cd ' . escapeshellarg($config['app_dir']) . ' && php cli/migrate.php';
    $code = sshSudoStream($config, $cmd);
    if ($code !== 0) {
        throw new RuntimeException("migrate.php exited with code {$code}");
    }
    success('Migrations complete.');
}

function runSeed(array $config): void
{
    $cmd = 'cd ' . escapeshellarg($config['app_dir']) . ' && php cli/seed-feeds.php';
    $code = sshSudoAsStream($config, 'www-data', $cmd);
    if ($code !== 0) {
        throw new RuntimeException("seed-feeds.php exited with code {$code}");
    }
    success('Seed complete.');
}

// ══════════════════════════════════════════════
// Phase 11: VERSION file
// ══════════════════════════════════════════════

function writeVersionFile(array $config, string $gitSha): void
{
    $path = rtrim($config['app_dir'], '/') . '/VERSION';
    sshWriteFile($config, $path, $gitSha . "\n", 'www-data', 'www-data', '644');
}

// ══════════════════════════════════════════════
// Phase 12: smoke tests
// ══════════════════════════════════════════════

function smokeTest(array $config, ?string $expectedGitSha): void
{
    // In-process: validate-only exercises DB, config, env, Storage.
    $out = sshSudoAs(
        $config,
        'www-data',
        'cd ' . escapeshellarg($config['app_dir']) . ' && php cli/pipeline.php --validate-only'
    );
    if (strpos($out, 'VALIDATE_OK') === false) {
        throw new RuntimeException("pipeline --validate-only failed:\n" . trim($out));
    }
    success('In-process validate OK.');

    // HTTPS: hit the public FQDN. Apache redirects HTTP→HTTPS, so curling
    // localhost over plain HTTP just lands on a 301. Hitting the real domain
    // also exercises the TLS cert and vhost config — both things we actually
    // want a smoke test to cover.
    $domain = $config['domain'] ?? '';
    if ($domain === '') {
        throw new RuntimeException('Config is missing "domain" — required for HTTPS smoke test.');
    }
    $url = "https://{$domain}/health.php";
    $out = sshExec($config, 'curl -fsS ' . escapeshellarg($url), true);
    $decoded = json_decode($out, true);
    if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'ok') {
        throw new RuntimeException("health.php at {$url} did not return ok:\n" . trim($out));
    }
    success("HTTPS health check OK ({$url}).");

    if ($expectedGitSha !== null) {
        $reported = trim((string) ($decoded['git_sha'] ?? ''));
        if ($reported !== $expectedGitSha) {
            throw new RuntimeException(
                "health.php git_sha mismatch: reported '{$reported}', expected '{$expectedGitSha}'"
            );
        }
        success("VERSION file reports git SHA {$expectedGitSha}.");
    }
}

/**
 * Snapshot-agnostic smoke test, used after a rollback. The previous release
 * may not contain --validate-only or web/health.php (e.g. the very first sync
 * after deploy). All we check is that the server is reachable, MySQL is up,
 * and Apache is serving requests.
 */
function smokeTestMinimal(array $config): void
{
    // 1. MySQL responds via auth_socket (same path used by setKillSwitch).
    $db = trim(sshSudoAs(
        $config,
        'www-data',
        "grep -E '^DB_NAME=' " . escapeshellarg(rtrim($config['app_dir'], '/') . '/.env') . " | cut -d= -f2-"
    ));
    $db = trim($db, "\"' \t\n\r");
    if ($db === '') {
        throw new RuntimeException('Could not read DB_NAME from remote .env.');
    }
    $out = sshSudo($config, 'mysql ' . escapeshellarg($db) . ' -N -e "SELECT 1"', true);
    if (trim($out) !== '1') {
        throw new RuntimeException("MySQL SELECT 1 returned: " . trim($out));
    }
    success('MySQL responds.');

    // 2. Apache is serving requests. The admin path is basic-auth protected,
    // so a 401 is success here — it proves Apache + PHP-FPM are alive without
    // requiring health.php to exist on the rolled-back tree.
    $code = trim(sshExec(
        $config,
        "curl -s -o /dev/null -w '%{http_code}' http://localhost/",
        true
    ));
    if (!in_array($code, ['200', '301', '302', '401', '403'], true)) {
        throw new RuntimeException("Apache returned unexpected status: {$code}");
    }
    success("Apache responds (HTTP {$code}).");
}

// ══════════════════════════════════════════════
// Phase 14: prune snapshots
// ══════════════════════════════════════════════

function pruneSnapshots(array $config): void
{
    $keep = max(1, (int) $config['keep_snapshots']);
    $snapDir = escapeshellarg(rtrim($config['snapshot_dir'], '/'));

    // Timestamped names sort lexicographically == chronologically.
    $cmd = "ls -1 {$snapDir} 2>/dev/null | sort | head -n -{$keep} "
         . "| xargs -r -I{} rm -rf {$snapDir}/{}";
    sshSudo($config, $cmd);

    $remaining = trim(sshSudo($config, "ls -1 {$snapDir} 2>/dev/null | wc -l", true));
    info("Snapshots retained: {$remaining} (target: {$keep})");
}

// ══════════════════════════════════════════════
// Rollback
// ══════════════════════════════════════════════

/**
 * @return string[] Most recent first.
 */
function listSnapshots(array $config): array
{
    $out = sshSudo(
        $config,
        'ls -1 ' . escapeshellarg(rtrim($config['snapshot_dir'], '/')) . ' 2>/dev/null || true',
        true
    );
    $names = array_filter(array_map('trim', explode("\n", $out)), fn($x) => $x !== '');
    rsort($names);
    return array_values($names);
}

function rollbackTo(array $config, string $snapshotName): void
{
    $snapPath = rtrim($config['snapshot_dir'], '/') . '/' . $snapshotName;
    $appDir   = rtrim($config['app_dir'], '/');

    // Verify the snapshot exists first.
    $check = sshSudo(
        $config,
        'test -d ' . escapeshellarg($snapPath) . ' && echo SNAP_OK',
        true
    );
    if (strpos($check, 'SNAP_OK') === false) {
        throw new RuntimeException("Snapshot not found: {$snapPath}");
    }

    info("Rolling back {$appDir} from {$snapPath}...");
    sshSudo(
        $config,
        'rsync -a --delete ' . escapeshellarg($snapPath . '/') . ' ' . escapeshellarg($appDir . '/')
    );
    sshSudo($config, 'chown -R www-data:www-data ' . escapeshellarg($appDir));
    success("Rolled back to {$snapshotName}");
}

// ══════════════════════════════════════════════
// Utilities
// ══════════════════════════════════════════════

function expandHome(string $path): string
{
    if (str_starts_with($path, '~/')) {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        if ($home === '') {
            throw new RuntimeException('Could not determine $HOME to expand ~.');
        }
        return rtrim($home, '/') . '/' . substr($path, 2);
    }
    return $path;
}
