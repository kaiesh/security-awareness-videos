<?php

declare(strict_types=1);

use function SecurityDrama\e;

// Non-stub direct adapters (only YouTube has a real implementation)
$nonStubAdapters = ['youtube'];
?>
<div id="config-page">
    <h2 class="text-2xl font-bold mb-6">Configuration</h2>

    <!-- General settings -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
        <div class="px-4 py-3 border-b border-gray-700">
            <h3 class="text-lg font-semibold">General Settings</h3>
        </div>
        <div class="p-4 space-y-4" id="config-general-form">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Daily Video Target (1-10)</label>
                    <input type="number" id="cfg-daily_video_target" min="1" max="10"
                           class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-gray-100">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Video Provider</label>
                    <input type="text" id="cfg-video_provider" readonly
                           class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-gray-400">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Min Relevance Score (0-100)</label>
                    <input type="number" id="cfg-min_relevance_score" min="0" max="100"
                           class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-gray-100">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Log Retention Days (1-365)</label>
                    <input type="number" id="cfg-log_retention_days" min="1" max="365"
                           class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-gray-100">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Pipeline Enabled</label>
                    <select id="cfg-pipeline_enabled" class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-gray-100">
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </div>
            </div>
            <button onclick="saveGeneralConfig()" class="px-4 py-2 bg-blue-700 hover:bg-blue-600 rounded text-sm font-medium">
                Save General Settings
            </button>
        </div>
    </div>

    <!-- HeyGen settings -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
        <div class="px-4 py-3 border-b border-gray-700">
            <h3 class="text-lg font-semibold">HeyGen Settings</h3>
        </div>
        <div class="p-4 space-y-4" id="config-heygen-form">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Template ID</label>
                    <input type="text" id="cfg-heygen_template_id"
                           class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-gray-100">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Avatar ID</label>
                    <input type="text" id="cfg-heygen_avatar_id"
                           class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-gray-100">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Voice ID</label>
                    <input type="text" id="cfg-heygen_voice_id"
                           class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-gray-100">
                </div>
            </div>
            <button onclick="saveHeygenConfig()" class="px-4 py-2 bg-blue-700 hover:bg-blue-600 rounded text-sm font-medium">
                Save HeyGen Settings
            </button>
        </div>
    </div>

    <!-- Platform publishing -->
    <div class="bg-gray-800 rounded-lg border border-gray-700">
        <div class="px-4 py-3 border-b border-gray-700">
            <h3 class="text-lg font-semibold">Platform Publishing</h3>
        </div>
        <div class="p-4" id="platform-config-container">
            <div class="text-gray-500">Loading platform configuration...</div>
        </div>
    </div>
</div>

<script>
    window._nonStubAdapters = <?= json_encode($nonStubAdapters) ?>;
</script>
