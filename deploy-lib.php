<?php
/**
 * Security Drama - Shared deploy/sync helpers
 *
 * Functions in this file are used by both deploy.php (initial server setup)
 * and sync.php (ongoing code updates). Keeping them in one place ensures the
 * SSH/sudo model stays consistent across both tools.
 *
 * Guarantees:
 *   - Privileged commands work whether the SSH user is root or a non-root
 *     sudoer (NOPASSWD or password-prompted).
 *   - File writes to /etc/** are atomic and land with correct ownership/perms
 *     in one step (no window where the file is visible with wrong perms).
 *   - Sudo passwords are never logged.
 *
 * Do not include application code here — this file is loaded from CLI tools
 * on the developer's machine, not on the server.
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
// Terminal output helpers
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

// ──────────────────────────────────────────────
// Interactive prompts
// ──────────────────────────────────────────────

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

// ──────────────────────────────────────────────
// Plain SSH/SCP/rsync (no privilege escalation)
// ──────────────────────────────────────────────

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

function scpDownload(array $config, string $remotePath, string $localPath): bool
{
    $port = $config['ssh_port'];
    $user = $config['ssh_user'];
    $host = $config['server_ip'];
    $keyFlag = isset($config['ssh_key']) && $config['ssh_key'] !== ''
        ? "-i " . escapeshellarg($config['ssh_key'])
        : '';

    $cmd = sprintf(
        'scp -o StrictHostKeyChecking=no -P %s %s %s@%s:%s %s 2>&1',
        escapeshellarg($port),
        $keyFlag,
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($remotePath),
        escapeshellarg($localPath)
    );

    exec($cmd, $output, $exitCode);
    return $exitCode === 0;
}

/**
 * rsync upload with configurable excludes and flags.
 *
 * @param string[] $excludes   Relative paths/globs to exclude
 * @param string[] $extraFlags Additional rsync flags (e.g., ['--delete', '--dry-run'])
 */
function rsyncUpload(
    array $config,
    string $localPath,
    string $remotePath,
    array $excludes = ['.git', 'vendor', '.env', '*.log', 'security-drama-technical-spec.md', 'deploy.php', 'sync.php', 'deploy-lib.php'],
    array $extraFlags = []
): bool {
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

    $excludeFlags = '';
    foreach ($excludes as $exclude) {
        $excludeFlags .= ' --exclude=' . escapeshellarg($exclude);
    }

    $extraFlagsStr = '';
    foreach ($extraFlags as $flag) {
        $extraFlagsStr .= ' ' . $flag;
    }

    $cmd = sprintf(
        'rsync -avz%s%s -e %s %s %s@%s:%s 2>&1',
        $excludeFlags,
        $extraFlagsStr,
        escapeshellarg($sshOpt),
        escapeshellarg(rtrim($localPath, '/') . '/'),
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($remotePath)
    );

    passthru($cmd, $exitCode);
    return $exitCode === 0;
}

// ──────────────────────────────────────────────
// Sudo helpers
// ──────────────────────────────────────────────
//
// These wrap sshExec/sshExecStream so privileged commands run correctly whether
// the SSH user is root or a non-root sudoer (with NOPASSWD or password-prompted
// sudo). The $command string may contain pipes, && chains, and redirections —
// we wrap it in `bash -c '...'` so the sudo context covers the whole pipeline
// instead of just the first binary.

function sudoWrap(array $config, string $command): string
{
    // root: no wrapping needed.
    if (($config['ssh_user'] ?? '') === 'root') {
        return $command;
    }

    $inner = escapeshellarg($command);

    if (empty($config['sudo_pass'])) {
        // NOPASSWD path: fail fast if sudo would prompt.
        return "sudo -n bash -c {$inner}";
    }

    // Password path: stream the password via -S. -p '' suppresses the prompt
    // string so sudo doesn't echo "[sudo] password for user:" to stderr.
    $pass = escapeshellarg($config['sudo_pass']);
    return "printf '%s\\n' {$pass} | sudo -S -p '' bash -c {$inner}";
}

function sudoWrapAs(array $config, string $runAs, string $command): string
{
    // Already running as the target user: no wrap needed.
    if (($config['ssh_user'] ?? '') === $runAs) {
        return $command;
    }

    $inner = escapeshellarg($command);

    // Running as root via SSH: use sudo -u without password (root can always).
    if (($config['ssh_user'] ?? '') === 'root') {
        return "sudo -u " . escapeshellarg($runAs) . " bash -c {$inner}";
    }

    if (empty($config['sudo_pass'])) {
        return "sudo -n -u " . escapeshellarg($runAs) . " bash -c {$inner}";
    }

    $pass = escapeshellarg($config['sudo_pass']);
    return "printf '%s\\n' {$pass} | sudo -S -p '' -u " . escapeshellarg($runAs) . " bash -c {$inner}";
}

function sshSudo(array $config, string $command, bool $silent = false): string
{
    $wrapped = sudoWrap($config, $command);

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
        escapeshellarg($wrapped)
    );

    if (!$silent) {
        // Log the original command, never the wrapped one (which may contain the sudo password).
        info("Running (sudo): $command");
    }

    $output = shell_exec($sshCmd);
    return $output ?? '';
}

function sshSudoStream(array $config, string $command): int
{
    $wrapped = sudoWrap($config, $command);

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
        escapeshellarg($wrapped)
    );

    info("Running (sudo): $command");
    passthru($sshCmd, $exitCode);
    return $exitCode;
}

function sshSudoAs(array $config, string $runAs, string $command, bool $silent = false): string
{
    $wrapped = sudoWrapAs($config, $runAs, $command);

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
        escapeshellarg($wrapped)
    );

    if (!$silent) {
        info("Running (as $runAs): $command");
    }

    $output = shell_exec($sshCmd);
    return $output ?? '';
}

function sshSudoAsStream(array $config, string $runAs, string $command): int
{
    $wrapped = sudoWrapAs($config, $runAs, $command);

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
        escapeshellarg($wrapped)
    );

    info("Running (as $runAs): $command");
    passthru($sshCmd, $exitCode);
    return $exitCode;
}

/**
 * Probe sudo access. Must be called after the SSH connection test succeeds.
 * Returns true if sudo -n true (NOPASSWD) or `printf pass | sudo -S true`
 * (password mode) returns success.
 */
function sshSudoProbe(array $config): bool
{
    if (($config['ssh_user'] ?? '') === 'root') {
        return true;
    }

    $output = sshSudo($config, 'true && echo SUDO_OK', true);
    return strpos($output, 'SUDO_OK') !== false;
}

/**
 * Atomically write a file to a privileged location.
 *
 * 1. Writes the content to /tmp/sd-deploy-<random>.tmp as the deploy user (no sudo).
 * 2. `sudo install -m MODE -o OWNER -g GROUP` atomically places the file with
 *    correct perms and ownership in one operation (no race window).
 * 3. Removes the /tmp copy.
 *
 * Using `install` (vs. mv + chmod + chown) guarantees the final file is never
 * visible with incorrect perms — critical for .env (mode 600).
 */
function sshWriteFile(
    array $config,
    string $remotePath,
    string $content,
    string $owner = 'root',
    string $group = 'root',
    string $mode = '644'
): void {
    $tmpPath = '/tmp/sd-deploy-' . bin2hex(random_bytes(6)) . '.tmp';

    // Step 1: write as the deploy user to /tmp. No sudo needed — /tmp is world-writable.
    // We build the heredoc remote-side; the remote login shell interprets it.
    $heredoc = "cat > " . escapeshellarg($tmpPath) . " << 'SDDEPLOYEOF'\n{$content}\nSDDEPLOYEOF";

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
        escapeshellarg($heredoc)
    );

    info("Writing: $remotePath (owner $owner:$group mode $mode)");
    shell_exec($sshCmd);

    // Step 2: install (atomic move + chmod + chown) into place.
    sshSudo(
        $config,
        sprintf(
            'install -m %s -o %s -g %s %s %s && rm -f %s',
            escapeshellarg($mode),
            escapeshellarg($owner),
            escapeshellarg($group),
            escapeshellarg($tmpPath),
            escapeshellarg($remotePath),
            escapeshellarg($tmpPath)
        ),
        true  // silent — the info() above already logged it
    );
}
