<?php

declare(strict_types=1);

use function SecurityDrama\e;
?>
<div id="videos-page">
    <h2 class="text-2xl font-bold mb-6">Videos</h2>

    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-400 border-b border-gray-700">
                    <th class="px-4 py-2">ID</th>
                    <th class="px-4 py-2">Queue Item</th>
                    <th class="px-4 py-2">Provider</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Duration</th>
                    <th class="px-4 py-2">Size</th>
                    <th class="px-4 py-2">Storage</th>
                    <th class="px-4 py-2">Created</th>
                </tr>
            </thead>
            <tbody id="videos-table-body">
                <tr><td colspan="8" class="px-4 py-3 text-gray-500">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Video preview modal -->
    <div id="video-preview-modal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60">
        <div class="bg-gray-800 rounded-lg border border-gray-700 w-full max-w-2xl p-6 relative">
            <button onclick="document.getElementById('video-preview-modal').classList.add('hidden')"
                    class="absolute top-3 right-3 text-gray-400 hover:text-white text-xl">&times;</button>
            <video id="video-preview-player" controls class="w-full rounded">
                <source src="" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        </div>
    </div>
</div>
