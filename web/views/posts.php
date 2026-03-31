<?php

declare(strict_types=1);

use function SecurityDrama\e;
?>
<div id="posts-page">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold">Social Posts</h2>
        <div>
            <select id="posts-platform-filter" class="bg-gray-800 border border-gray-600 text-gray-100 text-sm rounded px-3 py-2">
                <option value="">All Platforms</option>
                <option value="youtube">YouTube</option>
                <option value="x">X (Twitter)</option>
                <option value="reddit">Reddit</option>
                <option value="instagram">Instagram</option>
                <option value="facebook">Facebook</option>
                <option value="tiktok">TikTok</option>
                <option value="linkedin">LinkedIn</option>
                <option value="threads">Threads</option>
                <option value="bluesky">Bluesky</option>
                <option value="mastodon">Mastodon</option>
                <option value="pinterest">Pinterest</option>
            </select>
        </div>
    </div>

    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-400 border-b border-gray-700">
                    <th class="px-4 py-2">Platform</th>
                    <th class="px-4 py-2">Adapter</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Post URL</th>
                    <th class="px-4 py-2">Posted At</th>
                </tr>
            </thead>
            <tbody id="posts-table-body">
                <tr><td colspan="5" class="px-4 py-3 text-gray-500">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <div id="posts-pagination" class="mt-4 flex justify-center space-x-2"></div>
</div>
