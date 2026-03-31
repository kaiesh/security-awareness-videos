<?php

declare(strict_types=1);

use function SecurityDrama\e;
?>
<div id="queue-page">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold">Content Queue</h2>
        <div>
            <select id="queue-status-filter" class="bg-gray-800 border border-gray-600 text-gray-100 text-sm rounded px-3 py-2">
                <option value="">All Statuses</option>
                <option value="pending_script">Pending Script</option>
                <option value="generating_script">Generating Script</option>
                <option value="pending_video">Pending Video</option>
                <option value="generating_video">Generating Video</option>
                <option value="pending_publish">Pending Publish</option>
                <option value="publishing">Publishing</option>
                <option value="published">Published</option>
                <option value="failed">Failed</option>
            </select>
        </div>
    </div>

    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-400 border-b border-gray-700">
                    <th class="px-4 py-2">ID</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Type</th>
                    <th class="px-4 py-2">Feed Item</th>
                    <th class="px-4 py-2">Audience</th>
                    <th class="px-4 py-2">Priority</th>
                    <th class="px-4 py-2">Created</th>
                </tr>
            </thead>
            <tbody id="queue-table-body">
                <tr><td colspan="7" class="px-4 py-3 text-gray-500">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <div id="queue-pagination" class="mt-4 flex justify-center space-x-2"></div>

    <!-- Detail modal -->
    <div id="queue-detail-modal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60">
        <div class="bg-gray-800 rounded-lg border border-gray-700 w-full max-w-3xl max-h-[90vh] overflow-y-auto p-6 relative">
            <button onclick="document.getElementById('queue-detail-modal').classList.add('hidden')"
                    class="absolute top-3 right-3 text-gray-400 hover:text-white text-xl">&times;</button>
            <div id="queue-detail-content">Loading...</div>
        </div>
    </div>
</div>
