<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;
use SecurityDrama\Config;
use SecurityDrama\Video\VideoOrchestrator;

Bootstrap::init();

if (Config::getInstance()->get('pipeline_enabled', '1') === '0') {
    echo "Pipeline is disabled. Set pipeline_enabled to 1 to resume.\n";
    exit(0);
}

$lockFile = fopen('/tmp/securitydrama_poll_video.lock', 'c');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another video polling process is already running.\n";
    exit(1);
}

$orchestrator = new VideoOrchestrator();
$polled = $orchestrator->pollInProgress();

echo "Video polling complete. Jobs polled: {$polled}\n";
