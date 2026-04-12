<?php

declare(strict_types=1);

/**
 * Standalone health endpoint. Does NOT route through api.php — no sessions,
 * no CSRF, no HTTP Basic auth (whitelisted in web/.htaccess).
 *
 * Used by sync.php's smoke test phase. Called locally on the server as:
 *   curl -fsS http://localhost/health.php
 *
 * Returns 200 with {"status":"ok",...} on success or 503 with
 * {"status":"fail","error":"..."} on any failure. The git_sha field is
 * read from the VERSION file written by sync.php / deploy.php.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/../src/Bootstrap.php';
    \SecurityDrama\Bootstrap::init(dirname(__DIR__));

    // Cheap DB ping — proves PDO can connect and the credentials in .env work.
    $row = \SecurityDrama\Database::getInstance()->fetchOne('SELECT 1 AS ok');
    if (($row['ok'] ?? null) !== 1) {
        throw new RuntimeException('DB ping returned unexpected value');
    }

    // Prove the config table is readable — the Config class lazy-loads from
    // both .env and the DB, so a successful get() exercises both.
    \SecurityDrama\Config::getInstance()->get('pipeline_enabled', '1');

    $versionFile = dirname(__DIR__) . '/VERSION';
    $gitSha = is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : null;

    http_response_code(200);
    echo json_encode([
        'status'  => 'ok',
        'db'      => true,
        'config'  => true,
        'git_sha' => $gitSha,
    ]);
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'fail',
        'error'  => $e->getMessage(),
    ]);
}
