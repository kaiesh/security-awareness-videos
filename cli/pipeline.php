<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;
use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\Pipeline\Orchestrator;
use SecurityDrama\Storage;

Bootstrap::init();

// ─── --validate-only: cheap in-process health check for sync.php ───
// Verifies DB connectivity, config table readability, required env vars,
// and that the Storage S3 client can be constructed. Does NOT acquire
// the pipeline lock and does NOT honor pipeline_enabled (sync.php calls
// this while the kill switch is engaged).
if (in_array('--validate-only', $argv ?? [], true)) {
    try {
        $db = Database::getInstance();
        $db->fetchOne('SELECT 1 AS ok');

        // Prove the config table is queryable. We don't care about the value.
        Config::getInstance()->get('pipeline_enabled', '1');

        $requiredEnv = [
            'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
            'DO_SPACES_KEY', 'DO_SPACES_SECRET', 'DO_SPACES_REGION', 'DO_SPACES_BUCKET',
            'ANTHROPIC_API_KEY',
        ];
        foreach ($requiredEnv as $key) {
            if (($_ENV[$key] ?? '') === '') {
                throw new RuntimeException("Required env var {$key} is empty");
            }
        }

        // Storage::getInstance() constructs the S3 client with the credentials
        // above. We skip the API round-trip (no cheap-read wrapper exists),
        // but a bad config blows up at construction time.
        Storage::getInstance();

        echo "VALIDATE_OK\n";
        exit(0);
    } catch (\Throwable $e) {
        echo "VALIDATE_FAIL: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if (Config::getInstance()->get('pipeline_enabled', '1') === '0') {
    echo "Pipeline is disabled. Set pipeline_enabled to 1 to resume.\n";
    exit(0);
}

$lockFile = fopen('/tmp/securitydrama_pipeline.lock', 'c');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another pipeline process is already running.\n";
    exit(1);
}

$orchestrator = new Orchestrator();
$orchestrator->run();

echo "Pipeline cycle complete.\n";
