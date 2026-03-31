<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;
use SecurityDrama\Config;
use SecurityDrama\Database;

Bootstrap::init();

if (Config::getInstance()->get('pipeline_enabled', '1') === '0') {
    echo "Pipeline is disabled. Set pipeline_enabled to 1 to resume.\n";
    exit(0);
}

$lockFile = fopen('/tmp/securitydrama_purge_logs.lock', 'c');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another log purge process is already running.\n";
    exit(1);
}

$retentionDays = (int) Config::getInstance()->get('log_retention_days', '30');

$db = Database::getInstance();
$stmt = $db->execute(
    'DELETE FROM pipeline_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
    [$retentionDays]
);

$deleted = $stmt->rowCount();

echo "Log purge complete. Deleted {$deleted} entries older than {$retentionDays} days.\n";
