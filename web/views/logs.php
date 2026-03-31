<?php

declare(strict_types=1);

use function SecurityDrama\e;
?>
<div id="logs-page">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold">Pipeline Logs</h2>
        <div class="flex space-x-2">
            <select id="logs-module-filter" class="bg-gray-800 border border-gray-600 text-gray-100 text-sm rounded px-3 py-2">
                <option value="">All Modules</option>
                <option value="ingest">Ingest</option>
                <option value="score">Score</option>
                <option value="script">Script</option>
                <option value="video">Video</option>
                <option value="publish">Publish</option>
                <option value="admin">Admin</option>
                <option value="RedditCrawler">RedditCrawler</option>
            </select>
            <select id="logs-level-filter" class="bg-gray-800 border border-gray-600 text-gray-100 text-sm rounded px-3 py-2">
                <option value="">All Levels</option>
                <option value="debug">Debug</option>
                <option value="info">Info</option>
                <option value="warning">Warning</option>
                <option value="error">Error</option>
                <option value="critical">Critical</option>
            </select>
        </div>
    </div>

    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-400 border-b border-gray-700">
                    <th class="px-4 py-2">Timestamp</th>
                    <th class="px-4 py-2">Module</th>
                    <th class="px-4 py-2">Level</th>
                    <th class="px-4 py-2">Message</th>
                    <th class="px-4 py-2">Context</th>
                </tr>
            </thead>
            <tbody id="logs-table-body">
                <tr><td colspan="5" class="px-4 py-3 text-gray-500">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <div id="logs-pagination" class="mt-4 flex justify-center space-x-2"></div>
</div>
