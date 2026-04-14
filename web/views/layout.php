<?php

declare(strict_types=1);

use function SecurityDrama\e;

$navItems = [
    'dashboard' => ['label' => 'Dashboard',     'icon' => '&#9632;'],
    'queue'     => ['label' => 'Content Queue',  'icon' => '&#9776;'],
    'feeds'     => ['label' => 'Feeds',          'icon' => '&#8635;'],
    'videos'    => ['label' => 'Videos',         'icon' => '&#9654;'],
    'posts'     => ['label' => 'Social Posts',   'icon' => '&#9993;'],
    'config'    => ['label' => 'Config',         'icon' => '&#9881;'],
    'reddit'    => ['label' => 'Reddit',         'icon' => '&#9673;'],
    'music'     => ['label' => 'Music',          'icon' => '&#9835;'],
    'logs'      => ['label' => 'Logs',           'icon' => '&#9783;'],
];
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Security Drama Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="h-full bg-gray-900 text-gray-100">
    <div class="flex h-full">
        <!-- Sidebar -->
        <aside class="w-56 bg-gray-800 border-r border-gray-700 flex flex-col flex-shrink-0">
            <div class="px-4 py-5 border-b border-gray-700">
                <h1 class="text-lg font-bold text-red-400 tracking-wide">Security Drama</h1>
                <div id="pipeline-status" class="mt-1 text-xs text-gray-400">
                    Pipeline: <span id="pipeline-status-badge" class="inline-block w-2 h-2 rounded-full bg-gray-500 align-middle"></span>
                    <span id="pipeline-status-text">loading...</span>
                </div>
            </div>
            <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
                <?php foreach ($navItems as $navPage => $navInfo): ?>
                    <a href="?page=<?= e($navPage) ?>"
                       class="flex items-center px-3 py-2 rounded text-sm font-medium transition-colors
                              <?= $page === $navPage
                                  ? 'bg-gray-700 text-white'
                                  : 'text-gray-300 hover:bg-gray-700 hover:text-white' ?>">
                        <span class="mr-3 text-base"><?= $navInfo['icon'] ?></span>
                        <?= e($navInfo['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <!-- Main content -->
        <main class="flex-1 overflow-y-auto">
            <div class="p-6">
                <?= $pageContent ?>
            </div>
        </main>
    </div>

    <script src="assets/app.js"></script>
</body>
</html>
