<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;
use SecurityDrama\Config;
use SecurityDrama\Reddit\EngagementOrchestrator;

Bootstrap::init();

if (Config::getInstance()->get('pipeline_enabled', '1') === '0') {
    echo "Pipeline is disabled. Set pipeline_enabled to 1 to resume.\n";
    exit(0);
}

if (Config::getInstance()->get('reddit_engagement_enabled', '0') === '0') {
    echo "Reddit engagement is disabled. Set reddit_engagement_enabled to 1 to enable.\n";
    exit(0);
}

$lockFile = fopen('/tmp/securitydrama_reddit_engage.lock', 'c');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another Reddit engagement process is already running.\n";
    exit(1);
}

$orchestrator = new EngagementOrchestrator();
$orchestrator->run();

echo "Reddit engagement complete.\n";
