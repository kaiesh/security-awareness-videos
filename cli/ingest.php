<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;
use SecurityDrama\Config;
use SecurityDrama\Ingest\FeedIngester;

Bootstrap::init();

if (Config::getInstance()->get('pipeline_enabled', '1') === '0') {
    echo "Pipeline is disabled. Set pipeline_enabled to 1 to resume.\n";
    exit(0);
}

$lockFile = fopen('/tmp/securitydrama_ingest.lock', 'c');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another ingest process is already running.\n";
    exit(1);
}

$ingester = new FeedIngester();
$result = $ingester->run();

echo "Ingestion complete.\n";
echo "Sources processed: " . ($result['sources_processed'] ?? 0) . "\n";
echo "Items ingested: " . ($result['items_ingested'] ?? 0) . "\n";
