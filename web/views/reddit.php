<?php

declare(strict_types=1);

use function SecurityDrama\e;

$db = \SecurityDrama\Database::getInstance();
$keywords = $db->fetchAll("SELECT * FROM reddit_watch_keywords ORDER BY keyword");
?>
<div id="reddit-page">
    <h2 class="text-2xl font-bold mb-6">Reddit Engagement</h2>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Discovered Today</div>
            <div class="text-3xl font-bold mt-1" id="reddit-stat-discovered">--</div>
        </div>
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Matched</div>
            <div class="text-3xl font-bold mt-1" id="reddit-stat-matched">--</div>
        </div>
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Commented</div>
            <div class="text-3xl font-bold mt-1" id="reddit-stat-commented">--</div>
        </div>
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Skipped</div>
            <div class="text-3xl font-bold mt-1" id="reddit-stat-skipped">--</div>
        </div>
    </div>

    <!-- Emergency pause button -->
    <div class="mb-6">
        <button id="reddit-pause-btn" onclick="pauseRedditEngagement()"
                class="px-6 py-3 bg-red-700 hover:bg-red-600 rounded text-sm font-bold text-white border border-red-500">
            Pause All Reddit Engagement
        </button>
        <span id="reddit-engagement-status" class="ml-3 text-sm text-gray-400"></span>
    </div>

    <!-- Threads table -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
        <div class="px-4 py-3 border-b border-gray-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Reddit Threads</h3>
            <div class="flex space-x-2">
                <select id="reddit-status-filter" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded px-3 py-2">
                    <option value="">All Statuses</option>
                    <option value="discovered">Discovered</option>
                    <option value="evaluating">Evaluating</option>
                    <option value="approved">Approved</option>
                    <option value="commented">Commented</option>
                    <option value="skipped">Skipped</option>
                    <option value="failed">Failed</option>
                </select>
                <input type="text" id="reddit-subreddit-filter" placeholder="Subreddit..."
                       class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded px-3 py-2 w-40">
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-400 border-b border-gray-700">
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Subreddit</th>
                        <th class="px-4 py-2">Title</th>
                        <th class="px-4 py-2">Matched Video</th>
                        <th class="px-4 py-2">Comment Preview</th>
                    </tr>
                </thead>
                <tbody id="reddit-threads-body">
                    <tr><td colspan="5" class="px-4 py-3 text-gray-500">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="reddit-threads-pagination" class="p-4 flex justify-center space-x-2"></div>
    </div>

    <!-- Expanded thread detail -->
    <div id="reddit-thread-detail-modal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60">
        <div class="bg-gray-800 rounded-lg border border-gray-700 w-full max-w-3xl max-h-[90vh] overflow-y-auto p-6 relative">
            <button onclick="document.getElementById('reddit-thread-detail-modal').classList.add('hidden')"
                    class="absolute top-3 right-3 text-gray-400 hover:text-white text-xl">&times;</button>
            <div id="reddit-thread-detail-content">Loading...</div>
        </div>
    </div>

    <!-- Watch keywords -->
    <div class="bg-gray-800 rounded-lg border border-gray-700">
        <div class="px-4 py-3 border-b border-gray-700">
            <h3 class="text-lg font-semibold">Watch Keywords</h3>
        </div>
        <div class="p-4">
            <!-- Add keyword form -->
            <div class="flex space-x-2 mb-4">
                <input type="text" id="new-keyword" placeholder="Keyword..."
                       class="flex-1 bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-gray-100">
                <select id="new-keyword-type" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-sm text-gray-100">
                    <option value="topic">Topic</option>
                    <option value="package">Package</option>
                    <option value="cve">CVE</option>
                    <option value="technology">Technology</option>
                </select>
                <button onclick="addRedditKeyword()" class="px-4 py-2 bg-blue-700 hover:bg-blue-600 rounded text-sm font-medium">
                    Add
                </button>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-400 border-b border-gray-700">
                        <th class="px-4 py-2">Keyword</th>
                        <th class="px-4 py-2">Type</th>
                        <th class="px-4 py-2">Active</th>
                    </tr>
                </thead>
                <tbody id="keywords-table-body">
                    <?php foreach ($keywords as $kw): ?>
                    <tr class="border-b border-gray-700" data-keyword-id="<?= (int) $kw['id'] ?>">
                        <td class="px-4 py-2"><?= e($kw['keyword']) ?></td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs bg-gray-700"><?= e($kw['keyword_type']) ?></span>
                        </td>
                        <td class="px-4 py-2">
                            <button onclick="toggleRedditKeyword(<?= (int) $kw['id'] ?>, this)"
                                    class="px-2 py-0.5 rounded text-xs font-medium <?= $kw['is_active'] ? 'bg-green-700 text-green-100' : 'bg-gray-700 text-gray-400' ?>">
                                <?= $kw['is_active'] ? 'Active' : 'Inactive' ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($keywords)): ?>
                    <tr><td colspan="3" class="px-4 py-3 text-gray-500">No keywords configured.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
