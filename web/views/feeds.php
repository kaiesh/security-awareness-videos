<?php

declare(strict_types=1);

use function SecurityDrama\e;

$db = \SecurityDrama\Database::getInstance();
$feeds = $db->fetchAll("SELECT * FROM feed_sources ORDER BY name");
?>
<div id="feeds-page">
    <h2 class="text-2xl font-bold mb-6">Feed Sources</h2>

    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-400 border-b border-gray-700">
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Category</th>
                    <th class="px-4 py-2">Type</th>
                    <th class="px-4 py-2">Last Polled</th>
                    <th class="px-4 py-2">Last Success</th>
                    <th class="px-4 py-2">Error</th>
                    <th class="px-4 py-2">Items</th>
                    <th class="px-4 py-2">Active</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($feeds as $feed): ?>
                <tr class="border-b border-gray-700 hover:bg-gray-750" data-feed-id="<?= (int) $feed['id'] ?>">
                    <td class="px-4 py-2 font-medium"><?= e($feed['name']) ?></td>
                    <td class="px-4 py-2">
                        <span class="px-2 py-0.5 rounded text-xs bg-gray-700"><?= e($feed['category']) ?></span>
                    </td>
                    <td class="px-4 py-2 text-gray-400"><?= e($feed['feed_type']) ?></td>
                    <td class="px-4 py-2 text-gray-400"><?= $feed['last_polled_at'] ? e($feed['last_polled_at']) : '<span class="text-gray-600">Never</span>' ?></td>
                    <td class="px-4 py-2 text-gray-400"><?= $feed['last_successful_at'] ? e($feed['last_successful_at']) : '<span class="text-gray-600">Never</span>' ?></td>
                    <td class="px-4 py-2">
                        <?php if ($feed['last_error']): ?>
                            <span class="text-red-400 text-xs" title="<?= e($feed['last_error']) ?>">Error</span>
                        <?php else: ?>
                            <span class="text-green-400 text-xs">OK</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2 text-gray-300"><?= (int) $feed['items_fetched_total'] ?></td>
                    <td class="px-4 py-2">
                        <button onclick="toggleFeed(<?= (int) $feed['id'] ?>, this)"
                                class="px-2 py-0.5 rounded text-xs font-medium <?= $feed['is_active'] ? 'bg-green-700 text-green-100' : 'bg-gray-700 text-gray-400' ?>">
                            <?= $feed['is_active'] ? 'Active' : 'Inactive' ?>
                        </button>
                    </td>
                    <td class="px-4 py-2">
                        <button onclick="pollFeed(<?= (int) $feed['id'] ?>, this)"
                                class="px-2 py-1 rounded text-xs bg-blue-700 hover:bg-blue-600 text-white">
                            Poll Now
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($feeds)): ?>
                <tr><td colspan="9" class="px-4 py-3 text-gray-500">No feed sources configured.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
