<?php

declare(strict_types=1);

use function SecurityDrama\e;
?>
<div id="dashboard-page" data-auto-refresh="60">
    <h2 class="text-2xl font-bold mb-6">Dashboard</h2>

    <!-- Stats cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Feed Items Today</div>
            <div class="text-3xl font-bold mt-1" id="stat-feed-today">--</div>
            <div class="text-xs text-gray-500 mt-1">All-time: <span id="stat-feed-total">--</span></div>
        </div>

        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Queue Status</div>
            <div id="stat-queue-breakdown" class="mt-2 space-y-1 text-sm">
                <div class="text-gray-500">Loading...</div>
            </div>
        </div>

        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Videos Today</div>
            <div class="mt-1">
                <span class="text-3xl font-bold" id="stat-videos-today">--</span>
                <span class="text-gray-500 text-lg">/ <span id="stat-video-target">--</span></span>
            </div>
        </div>

        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Posts by Platform</div>
            <div id="stat-posts-platform" class="mt-2 space-y-1 text-sm">
                <div class="text-gray-500">Loading...</div>
            </div>
        </div>
    </div>

    <!-- Recent log entries -->
    <div class="bg-gray-800 rounded-lg border border-gray-700">
        <div class="px-4 py-3 border-b border-gray-700">
            <h3 class="text-lg font-semibold">Recent Pipeline Logs</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-400 border-b border-gray-700">
                        <th class="px-4 py-2">Time</th>
                        <th class="px-4 py-2">Module</th>
                        <th class="px-4 py-2">Level</th>
                        <th class="px-4 py-2">Message</th>
                    </tr>
                </thead>
                <tbody id="dashboard-logs">
                    <tr><td colspan="4" class="px-4 py-3 text-gray-500">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
