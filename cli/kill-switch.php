<?php

declare(strict_types=1);

/**
 * Tiny admin helper to flip the pipeline_enabled kill switch.
 *
 * Usage:
 *   php cli/kill-switch.php on    # pipeline_enabled = 1
 *   php cli/kill-switch.php off   # pipeline_enabled = 0
 *
 * sync.php calls this during its sync flow to pause the pipeline before
 * rsync/migrate and resume it after the smoke tests pass. The operator can
 * also run it manually from the server if the dashboard is unreachable.
 *
 * Runs as www-data so it uses the existing .env credentials to reach MySQL.
 */

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;
use SecurityDrama\Database;

Bootstrap::init();

$state = $argv[1] ?? '';
if (!in_array($state, ['on', 'off'], true)) {
    fwrite(STDERR, "usage: kill-switch.php on|off\n");
    exit(1);
}

$value = $state === 'on' ? '1' : '0';

Database::getInstance()->execute(
    'UPDATE config SET config_value = ? WHERE config_key = ?',
    [$value, 'pipeline_enabled']
);

echo "pipeline_enabled={$value}\n";
