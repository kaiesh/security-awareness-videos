<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

\SecurityDrama\Bootstrap::init(dirname(__DIR__));

session_start();

header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$validPages = ['dashboard', 'queue', 'feeds', 'videos', 'posts', 'config', 'logs', 'reddit'];
$page = $_GET['page'] ?? 'dashboard';

if (!in_array($page, $validPages, true)) {
    $page = 'dashboard';
}

$pageFile = __DIR__ . '/views/' . $page . '.php';
if (!file_exists($pageFile)) {
    $page = 'dashboard';
    $pageFile = __DIR__ . '/views/dashboard.php';
}

$csrfToken = $_SESSION['csrf_token'];

ob_start();
require $pageFile;
$pageContent = ob_get_clean();

require __DIR__ . '/views/layout.php';
