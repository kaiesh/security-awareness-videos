<?php

declare(strict_types=1);

use function SecurityDrama\e;

$db = \SecurityDrama\Database::getInstance();
$tracks = $db->fetchAll(
    "SELECT id, category, name, storage_path, duration_seconds, volume,
            credit_text, is_active, uploaded_at
     FROM background_music
     ORDER BY category, name"
);

$categories = ['cve_alert', 'scam_drama', 'security_101', 'vibe_roast', 'breach_story'];
?>
<div id="music-page">
    <h2 class="text-2xl font-bold mb-6">Background Music</h2>

    <div class="bg-gray-800 rounded-lg border border-gray-700 p-4 mb-6">
        <h3 class="text-lg font-semibold mb-3">Upload Track</h3>
        <form id="music-upload-form" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="md:col-span-2">
                <label class="block text-xs text-gray-400 mb-1">Audio file</label>
                <input type="file" name="audio" accept="audio/mpeg,audio/mp4,audio/wav,audio/x-wav,audio/ogg" required
                       class="w-full text-sm text-gray-300 file:mr-2 file:px-2 file:py-1 file:rounded file:border-0 file:bg-gray-700 file:text-gray-100">
            </div>

            <div>
                <label class="block text-xs text-gray-400 mb-1">Name</label>
                <input type="text" name="name" required maxlength="200"
                       class="w-full px-2 py-1 rounded bg-gray-900 border border-gray-700 text-sm">
            </div>

            <div>
                <label class="block text-xs text-gray-400 mb-1">Category</label>
                <select name="category" required class="w-full px-2 py-1 rounded bg-gray-900 border border-gray-700 text-sm">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs text-gray-400 mb-1">Volume (0–1)</label>
                <input type="number" name="volume" min="0" max="1" step="0.05" value="0.15" required
                       class="w-full px-2 py-1 rounded bg-gray-900 border border-gray-700 text-sm">
            </div>

            <div class="md:col-span-1">
                <button type="submit"
                        class="w-full px-3 py-1.5 rounded bg-blue-700 hover:bg-blue-600 text-white text-sm font-medium">
                    Upload
                </button>
            </div>

            <div class="md:col-span-6">
                <label class="block text-xs text-gray-400 mb-1">Credit text (optional)</label>
                <input type="text" name="credit_text" maxlength="300" placeholder="e.g. &quot;Track Name by Artist (CC-BY 4.0)&quot;"
                       class="w-full px-2 py-1 rounded bg-gray-900 border border-gray-700 text-sm">
            </div>
        </form>
        <p id="music-upload-status" class="mt-2 text-xs text-gray-400"></p>
    </div>

    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-400 border-b border-gray-700">
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Category</th>
                    <th class="px-4 py-2">Volume</th>
                    <th class="px-4 py-2">Credit</th>
                    <th class="px-4 py-2">Uploaded</th>
                    <th class="px-4 py-2">Active</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tracks as $track): ?>
                <tr class="border-b border-gray-700 hover:bg-gray-750" data-music-id="<?= (int) $track['id'] ?>">
                    <td class="px-4 py-2 font-medium"><?= e($track['name']) ?></td>
                    <td class="px-4 py-2">
                        <span class="px-2 py-0.5 rounded text-xs bg-gray-700"><?= e($track['category']) ?></span>
                    </td>
                    <td class="px-4 py-2">
                        <input type="number" min="0" max="1" step="0.05"
                               value="<?= e(number_format((float) $track['volume'], 2)) ?>"
                               onchange="updateMusicVolume(<?= (int) $track['id'] ?>, this.value, this)"
                               class="w-20 px-2 py-1 rounded bg-gray-900 border border-gray-700 text-sm">
                    </td>
                    <td class="px-4 py-2 text-gray-400 max-w-xs truncate" title="<?= e($track['credit_text'] ?? '') ?>">
                        <?= e($track['credit_text'] ?? '') ?>
                    </td>
                    <td class="px-4 py-2 text-gray-400"><?= e($track['uploaded_at']) ?></td>
                    <td class="px-4 py-2">
                        <button onclick="toggleMusic(<?= (int) $track['id'] ?>, this)"
                                class="px-2 py-0.5 rounded text-xs font-medium <?= $track['is_active'] ? 'bg-green-700 text-green-100' : 'bg-gray-700 text-gray-400' ?>">
                            <?= $track['is_active'] ? 'Active' : 'Inactive' ?>
                        </button>
                    </td>
                    <td class="px-4 py-2">
                        <button onclick="deleteMusic(<?= (int) $track['id'] ?>, this)"
                                class="px-2 py-1 rounded text-xs bg-red-700 hover:bg-red-600 text-white">
                            Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tracks)): ?>
                <tr><td colspan="7" class="px-4 py-3 text-gray-500">No background music tracks uploaded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('music-upload-form');
    if (!form) return;

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var status = document.getElementById('music-upload-status');
        status.textContent = 'Uploading...';

        var fd = new FormData(form);
        fetch('api.php?action=music-upload', {
            method: 'POST',
            body: fd
        }).then(function (resp) {
            return resp.json().then(function (data) {
                if (!resp.ok) {
                    status.textContent = data.error || 'Upload failed';
                    showToast(data.error || 'Upload failed', 'error');
                    return;
                }
                status.textContent = 'Uploaded.';
                showToast('Track uploaded');
                setTimeout(function () { window.location.reload(); }, 600);
            });
        }).catch(function () {
            status.textContent = 'Upload failed';
            showToast('Upload failed', 'error');
        });
    });

    window.toggleMusic = function (id, btn) {
        btn.disabled = true;
        fetchApi('music-toggle', { id: id }, 'POST').then(function (data) {
            btn.disabled = false;
            if (data.is_active) {
                btn.className = 'px-2 py-0.5 rounded text-xs font-medium bg-green-700 text-green-100';
                btn.textContent = 'Active';
            } else {
                btn.className = 'px-2 py-0.5 rounded text-xs font-medium bg-gray-700 text-gray-400';
                btn.textContent = 'Inactive';
            }
            showToast('Track toggled');
        }).catch(function () {
            btn.disabled = false;
        });
    };

    window.updateMusicVolume = function (id, value, input) {
        input.disabled = true;
        fetchApi('music-update-volume', { id: id, volume: parseFloat(value) }, 'POST').then(function () {
            input.disabled = false;
            showToast('Volume updated');
        }).catch(function () {
            input.disabled = false;
        });
    };

    window.deleteMusic = function (id, btn) {
        if (!confirm('Delete this track? This removes the file from storage.')) return;
        btn.disabled = true;
        fetchApi('music-delete', { id: id }, 'POST').then(function () {
            showToast('Track deleted');
            var row = btn.closest('tr');
            if (row) row.remove();
        }).catch(function () {
            btn.disabled = false;
        });
    };
})();
</script>
