(function () {
    'use strict';

    // ============================================================
    // CSRF token
    // ============================================================
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ============================================================
    // Toast notifications
    // ============================================================
    var toastContainer = null;

    function ensureToastContainer() {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
    }

    function showToast(message, type) {
        ensureToastContainer();
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + (type || 'success');
        toast.textContent = message;
        toastContainer.appendChild(toast);
        setTimeout(function () {
            toast.remove();
        }, 4000);
    }

    window.showToast = showToast;

    // ============================================================
    // Escape HTML
    // ============================================================
    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    // ============================================================
    // fetchApi helper
    // ============================================================
    function fetchApi(action, params, method) {
        method = method || 'GET';
        var url = 'api.php?action=' + encodeURIComponent(action);

        if (method === 'GET' && params) {
            for (var key in params) {
                if (params.hasOwnProperty(key)) {
                    url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
                }
            }
        }

        var opts = {
            method: method,
            headers: {}
        };

        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = getCsrfToken();
            if (params) {
                opts.body = JSON.stringify(params);
            }
        }

        return fetch(url, opts).then(function (resp) {
            if (resp.status === 429) {
                showToast('Rate limited. Please wait.', 'error');
                return Promise.reject(new Error('Rate limited'));
            }
            return resp.json().then(function (data) {
                if (resp.ok) return data;
                showToast(data.error || 'Request failed', 'error');
                return Promise.reject(data);
            });
        });
    }

    window.fetchApi = fetchApi;

    // ============================================================
    // Status badge helper
    // ============================================================
    function statusBadge(status) {
        return '<span class="px-2 py-0.5 rounded text-xs badge-' + esc(status) + '">' + esc(status) + '</span>';
    }

    function levelBadge(level) {
        return '<span class="level-' + esc(level) + '">' + esc(level) + '</span>';
    }

    // ============================================================
    // Pagination helper
    // ============================================================
    function renderPagination(containerId, currentPage, totalPages, onPageClick) {
        var container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '';

        if (totalPages <= 1) return;

        var start = Math.max(1, currentPage - 3);
        var end = Math.min(totalPages, currentPage + 3);

        if (currentPage > 1) {
            var prev = document.createElement('button');
            prev.className = 'page-btn';
            prev.textContent = 'Prev';
            prev.onclick = function () { onPageClick(currentPage - 1); };
            container.appendChild(prev);
        }

        for (var i = start; i <= end; i++) {
            var btn = document.createElement('button');
            btn.className = 'page-btn' + (i === currentPage ? ' active' : '');
            btn.textContent = i;
            btn.onclick = (function (p) {
                return function () { onPageClick(p); };
            })(i);
            container.appendChild(btn);
        }

        if (currentPage < totalPages) {
            var next = document.createElement('button');
            next.className = 'page-btn';
            next.textContent = 'Next';
            next.onclick = function () { onPageClick(currentPage + 1); };
            container.appendChild(next);
        }
    }

    // ============================================================
    // Platform emoji map
    // ============================================================
    var platformIcons = {
        youtube: '&#9654;',
        x: '&#120143;',
        reddit: '&#9673;',
        instagram: '&#9737;',
        facebook: '&#402;',
        tiktok: '&#9835;',
        linkedin: '&#8476;',
        threads: '&#9788;',
        bluesky: '&#9729;',
        mastodon: '&#9775;',
        pinterest: '&#9826;'
    };

    // ============================================================
    // DASHBOARD
    // ============================================================
    function loadDashboard() {
        fetchApi('dashboard-stats').then(function (data) {
            document.getElementById('stat-feed-today').textContent = data.feed_items_today;
            document.getElementById('stat-feed-total').textContent = data.feed_items_total;
            document.getElementById('stat-videos-today').textContent = data.videos_today;
            document.getElementById('stat-video-target').textContent = data.daily_video_target;

            // Queue breakdown
            var queueHtml = '';
            if (data.queue_breakdown.length === 0) {
                queueHtml = '<div class="text-gray-500">No items</div>';
            } else {
                data.queue_breakdown.forEach(function (row) {
                    queueHtml += '<div class="flex justify-between">' +
                        statusBadge(row.status) +
                        '<span class="text-gray-300">' + esc(row.cnt) + '</span></div>';
                });
            }
            document.getElementById('stat-queue-breakdown').innerHTML = queueHtml;

            // Posts by platform
            var postsHtml = '';
            if (data.posts_by_platform.length === 0) {
                postsHtml = '<div class="text-gray-500">No posts</div>';
            } else {
                data.posts_by_platform.forEach(function (row) {
                    postsHtml += '<div class="flex justify-between">' +
                        '<span>' + (platformIcons[row.platform] || '') + ' ' + esc(row.platform) + '</span>' +
                        '<span class="text-gray-300">' + esc(row.cnt) + '</span></div>';
                });
            }
            document.getElementById('stat-posts-platform').innerHTML = postsHtml;

            // Pipeline status indicator
            var badge = document.getElementById('pipeline-status-badge');
            var statusText = document.getElementById('pipeline-status-text');
            if (data.pipeline_enabled) {
                badge.className = 'inline-block w-2 h-2 rounded-full bg-green-500 align-middle';
                statusText.textContent = 'running';
            } else {
                badge.className = 'inline-block w-2 h-2 rounded-full bg-red-500 align-middle';
                statusText.textContent = 'paused';
            }

            // Recent logs
            var logsHtml = '';
            if (data.recent_logs.length === 0) {
                logsHtml = '<tr><td colspan="4" class="px-4 py-3 text-gray-500">No logs.</td></tr>';
            } else {
                data.recent_logs.forEach(function (log) {
                    logsHtml += '<tr class="border-b border-gray-700">' +
                        '<td class="px-4 py-2 text-gray-400 whitespace-nowrap">' + esc(log.created_at) + '</td>' +
                        '<td class="px-4 py-2">' + esc(log.module) + '</td>' +
                        '<td class="px-4 py-2">' + levelBadge(log.level) + '</td>' +
                        '<td class="px-4 py-2 text-gray-300">' + esc(log.message) + '</td>' +
                        '</tr>';
                });
            }
            document.getElementById('dashboard-logs').innerHTML = logsHtml;
        });
    }

    // ============================================================
    // QUEUE
    // ============================================================
    var queuePage = 1;

    function loadQueue(page) {
        page = page || 1;
        queuePage = page;
        var status = document.getElementById('queue-status-filter');
        var statusVal = status ? status.value : '';

        var params = { page: page };
        if (statusVal) params.status = statusVal;

        fetchApi('queue', params).then(function (data) {
            var html = '';
            if (data.items.length === 0) {
                html = '<tr><td colspan="7" class="px-4 py-3 text-gray-500">No items found.</td></tr>';
            } else {
                data.items.forEach(function (item) {
                    html += '<tr class="border-b border-gray-700 cursor-pointer" onclick="showQueueDetail(' + item.id + ')">' +
                        '<td class="px-4 py-2">' + esc(item.id) + '</td>' +
                        '<td class="px-4 py-2">' + statusBadge(item.status) + '</td>' +
                        '<td class="px-4 py-2">' + esc(item.content_type) + '</td>' +
                        '<td class="px-4 py-2 max-w-xs truncate">' + esc(item.feed_title) + '</td>' +
                        '<td class="px-4 py-2">' + esc(item.target_audience) + '</td>' +
                        '<td class="px-4 py-2">' + esc(item.priority) + '</td>' +
                        '<td class="px-4 py-2 text-gray-400 whitespace-nowrap">' + esc(item.created_at) + '</td>' +
                        '</tr>';
                });
            }
            document.getElementById('queue-table-body').innerHTML = html;

            renderPagination('queue-pagination', data.page, data.total_pages, function (p) {
                loadQueue(p);
            });
        });
    }

    window.showQueueDetail = function (id) {
        var modal = document.getElementById('queue-detail-modal');
        var content = document.getElementById('queue-detail-content');
        modal.classList.remove('hidden');
        content.innerHTML = 'Loading...';

        fetchApi('queue-detail', { id: id }).then(function (data) {
            var html = '<h3 class="text-xl font-bold mb-4">Queue Item #' + esc(data.item.id) + '</h3>';
            html += '<div class="space-y-3 text-sm">';
            html += '<div><strong>Status:</strong> ' + statusBadge(data.item.status) + '</div>';
            html += '<div><strong>Type:</strong> ' + esc(data.item.content_type) + '</div>';
            html += '<div><strong>Feed Item:</strong> ' + esc(data.item.feed_title) + '</div>';
            html += '<div><strong>Feed URL:</strong> <a href="' + esc(data.item.feed_url || '') +
                    '" class="text-blue-400 hover:underline" target="_blank">' + esc(data.item.feed_url || 'N/A') + '</a></div>';
            html += '<div><strong>Description:</strong> <p class="text-gray-400 mt-1">' + esc(data.item.feed_description || '') + '</p></div>';

            if (data.item.failure_reason) {
                html += '<div class="text-red-400"><strong>Failure:</strong> ' + esc(data.item.failure_reason) + '</div>';
            }

            if (data.script) {
                html += '<hr class="border-gray-700 my-4">';
                html += '<h4 class="font-semibold mb-2">Script</h4>';
                html += '<div><strong>Hook:</strong> ' + esc(data.script.hook_line) + '</div>';
                html += '<div class="mt-2"><strong>Narration:</strong></div>';
                html += '<pre class="bg-gray-900 p-3 rounded text-xs text-gray-300 whitespace-pre-wrap mt-1">' + esc(data.script.narration_text) + '</pre>';
                if (data.script.cta_text) {
                    html += '<div class="mt-2"><strong>CTA:</strong> ' + esc(data.script.cta_text) + '</div>';
                }
            }

            if (data.video) {
                html += '<hr class="border-gray-700 my-4">';
                html += '<h4 class="font-semibold mb-2">Video</h4>';
                html += '<div><strong>Provider:</strong> ' + esc(data.video.provider) + '</div>';
                html += '<div><strong>Status:</strong> ' + esc(data.video.provider_status || '') + '</div>';
                if (data.video.storage_url) {
                    html += '<div class="mt-2"><video controls class="w-full max-w-md rounded">' +
                            '<source src="' + esc(data.video.storage_url) + '" type="video/mp4"></video></div>';
                }
            }

            if (data.posts && data.posts.length > 0) {
                html += '<hr class="border-gray-700 my-4">';
                html += '<h4 class="font-semibold mb-2">Social Posts</h4>';
                data.posts.forEach(function (post) {
                    html += '<div class="flex items-center space-x-3 py-1">';
                    html += '<span>' + (platformIcons[post.platform] || '') + ' ' + esc(post.platform) + '</span>';
                    html += statusBadge(post.status);
                    if (post.platform_url) {
                        html += '<a href="' + esc(post.platform_url) + '" class="text-blue-400 hover:underline text-xs" target="_blank">View</a>';
                    }
                    html += '</div>';
                });
            }

            html += '</div>';
            content.innerHTML = html;
        });
    };

    // ============================================================
    // FEEDS
    // ============================================================
    window.toggleFeed = function (id, btn) {
        btn.disabled = true;
        fetchApi('feed-toggle', { id: id }, 'POST').then(function (data) {
            btn.disabled = false;
            if (data.is_active) {
                btn.className = 'px-2 py-0.5 rounded text-xs font-medium bg-green-700 text-green-100';
                btn.textContent = 'Active';
            } else {
                btn.className = 'px-2 py-0.5 rounded text-xs font-medium bg-gray-700 text-gray-400';
                btn.textContent = 'Inactive';
            }
            showToast('Feed toggled successfully');
        }).catch(function () {
            btn.disabled = false;
        });
    };

    window.pollFeed = function (id, btn) {
        btn.disabled = true;
        btn.textContent = 'Polling...';
        fetchApi('feed-poll', { id: id }, 'POST').then(function (data) {
            btn.disabled = false;
            btn.textContent = 'Poll Now';
            showToast(data.message || 'Feed polled');
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = 'Poll Now';
        });
    };

    // ============================================================
    // VIDEOS
    // ============================================================
    function loadVideos() {
        // Reuse queue-detail to load videos; or direct query
        // For simplicity, load all videos from the queue endpoint
        // Actually, videos don't have their own API endpoint yet.
        // Let's query via a simple approach using existing data.
        var body = document.getElementById('videos-table-body');
        if (!body) return;

        // We don't have a dedicated videos API endpoint, so we fetch through
        // the queue endpoint and extract video data. In practice, the admin
        // would query the DB directly. For now, show a helpful message.
        // Actually, let's fetch the queue items that have videos.
        fetchApi('queue', { status: 'published', page: 1 }).then(function (data) {
            if (data.items.length === 0) {
                body.innerHTML = '<tr><td colspan="8" class="px-4 py-3 text-gray-500">No videos found.</td></tr>';
                return;
            }

            var promises = data.items.map(function (item) {
                return fetchApi('queue-detail', { id: item.id });
            });

            Promise.all(promises).then(function (details) {
                var html = '';
                details.forEach(function (d) {
                    if (!d.video) return;
                    var v = d.video;
                    var sizeStr = v.file_size_bytes ? (v.file_size_bytes / (1024 * 1024)).toFixed(1) + ' MB' : '--';
                    var durStr = v.duration_seconds ? v.duration_seconds + 's' : '--';
                    html += '<tr class="border-b border-gray-700">' +
                        '<td class="px-4 py-2">' + esc(v.id) + '</td>' +
                        '<td class="px-4 py-2 max-w-xs truncate">' + esc(d.item.feed_title) + '</td>' +
                        '<td class="px-4 py-2">' + esc(v.provider) + '</td>' +
                        '<td class="px-4 py-2">' + esc(v.provider_status || '') + '</td>' +
                        '<td class="px-4 py-2">' + durStr + '</td>' +
                        '<td class="px-4 py-2">' + sizeStr + '</td>' +
                        '<td class="px-4 py-2">';
                    if (v.storage_url) {
                        html += '<button onclick="previewVideo(\'' + esc(v.storage_url) + '\')" ' +
                                'class="text-blue-400 hover:underline text-xs">Preview</button>';
                    } else {
                        html += '<span class="text-gray-600">--</span>';
                    }
                    html += '</td>' +
                        '<td class="px-4 py-2 text-gray-400 whitespace-nowrap">' + esc(v.created_at) + '</td>' +
                        '</tr>';
                });
                if (!html) {
                    html = '<tr><td colspan="8" class="px-4 py-3 text-gray-500">No videos found.</td></tr>';
                }
                body.innerHTML = html;
            });
        });
    }

    window.previewVideo = function (url) {
        var modal = document.getElementById('video-preview-modal');
        var player = document.getElementById('video-preview-player');
        var source = player.querySelector('source');
        source.src = url;
        player.load();
        modal.classList.remove('hidden');
    };

    // ============================================================
    // POSTS
    // ============================================================
    var postsPage = 1;

    function loadPosts(page) {
        page = page || 1;
        postsPage = page;
        var body = document.getElementById('posts-table-body');
        if (!body) return;

        var platformFilter = document.getElementById('posts-platform-filter');
        var platform = platformFilter ? platformFilter.value : '';

        // Posts also need a dedicated API endpoint. We'll use the queue items.
        // For a proper implementation, we would add a posts API action.
        // Let's collect posts across all published queue items.
        var params = { status: 'published', page: page };
        fetchApi('queue', params).then(function (data) {
            if (data.items.length === 0) {
                body.innerHTML = '<tr><td colspan="5" class="px-4 py-3 text-gray-500">No posts found.</td></tr>';
                return;
            }

            var promises = data.items.map(function (item) {
                return fetchApi('queue-detail', { id: item.id });
            });

            Promise.all(promises).then(function (details) {
                var allPosts = [];
                details.forEach(function (d) {
                    if (d.posts) {
                        d.posts.forEach(function (p) { allPosts.push(p); });
                    }
                });

                if (platform) {
                    allPosts = allPosts.filter(function (p) { return p.platform === platform; });
                }

                var html = '';
                if (allPosts.length === 0) {
                    html = '<tr><td colspan="5" class="px-4 py-3 text-gray-500">No posts found.</td></tr>';
                } else {
                    allPosts.forEach(function (post) {
                        html += '<tr class="border-b border-gray-700">' +
                            '<td class="px-4 py-2">' + (platformIcons[post.platform] || '') + ' ' + esc(post.platform) + '</td>' +
                            '<td class="px-4 py-2 text-gray-400">' + esc(post.adapter) + '</td>' +
                            '<td class="px-4 py-2">' + statusBadge(post.status) + '</td>' +
                            '<td class="px-4 py-2">';
                        if (post.platform_url) {
                            html += '<a href="' + esc(post.platform_url) + '" class="text-blue-400 hover:underline text-xs" target="_blank">' +
                                    esc(post.platform_url) + '</a>';
                        } else {
                            html += '<span class="text-gray-600">--</span>';
                        }
                        html += '</td><td class="px-4 py-2 text-gray-400 whitespace-nowrap">' + esc(post.posted_at || '--') + '</td></tr>';
                    });
                }
                body.innerHTML = html;
            });

            renderPagination('posts-pagination', data.page, data.total_pages, function (p) {
                loadPosts(p);
            });
        });
    }

    // ============================================================
    // CONFIG
    // ============================================================
    function loadConfig() {
        fetchApi('config').then(function (data) {
            // Build a map of config keys
            var cfgMap = {};
            data.config.forEach(function (row) {
                cfgMap[row.key] = row.value;
            });

            // General settings
            var fields = ['daily_video_target', 'video_provider', 'min_relevance_score',
                          'log_retention_days', 'pipeline_enabled'];
            fields.forEach(function (key) {
                var el = document.getElementById('cfg-' + key);
                if (el) {
                    if (el.tagName === 'SELECT') {
                        el.value = cfgMap[key] || el.options[0].value;
                    } else {
                        el.value = cfgMap[key] || '';
                    }
                }
            });

            // HeyGen settings
            ['heygen_template_id', 'heygen_avatar_id', 'heygen_voice_id'].forEach(function (key) {
                var el = document.getElementById('cfg-' + key);
                if (el) el.value = cfgMap[key] || '';
            });

            // Seedance settings
            ['seedance_resolution', 'seedance_duration'].forEach(function (key) {
                var el = document.getElementById('cfg-' + key);
                if (el) el.value = cfgMap[key] || '';
            });

            // Show/hide provider-specific sections
            toggleProviderSections();

            // Platform config
            renderPlatformConfig(data.platform_config);
        });
    }

    function renderPlatformConfig(platforms) {
        var container = document.getElementById('platform-config-container');
        if (!container) return;

        var nonStub = window._nonStubAdapters || ['youtube'];
        var html = '';

        platforms.forEach(function (pc) {
            var platform = pc.platform;
            var directDisabled = nonStub.indexOf(platform) === -1;

            html += '<div class="border border-gray-700 rounded-lg p-4 mb-4" data-platform="' + esc(platform) + '">';
            html += '<h4 class="font-semibold mb-3 text-base capitalize">' + (platformIcons[platform] || '') + ' ' + esc(platform) + '</h4>';
            html += '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">';

            // Enabled toggle
            html += '<div><label class="block text-xs text-gray-400 mb-1">Enabled</label>';
            html += '<select class="w-full bg-gray-700 border border-gray-600 rounded px-2 py-1.5 text-sm text-gray-100 plat-is_enabled">';
            html += '<option value="1"' + (pc.is_enabled == 1 ? ' selected' : '') + '>Yes</option>';
            html += '<option value="0"' + (pc.is_enabled == 0 ? ' selected' : '') + '>No</option>';
            html += '</select></div>';

            // Adapter dropdown
            html += '<div><label class="block text-xs text-gray-400 mb-1">Adapter</label>';
            html += '<select class="w-full bg-gray-700 border border-gray-600 rounded px-2 py-1.5 text-sm text-gray-100 plat-adapter">';
            if (directDisabled) {
                html += '<option value="direct" disabled class="text-gray-500">Direct (Coming soon)</option>';
            } else {
                html += '<option value="direct"' + (pc.adapter === 'direct' ? ' selected' : '') + '>Direct</option>';
            }
            html += '<option value="missinglettr"' + (pc.adapter === 'missinglettr' ? ' selected' : '') + '>Missinglettr</option>';
            html += '<option value="disabled"' + (pc.adapter === 'disabled' ? ' selected' : '') + '>Disabled</option>';
            html += '</select></div>';

            // Post type
            html += '<div><label class="block text-xs text-gray-400 mb-1">Post Type</label>';
            html += '<select class="w-full bg-gray-700 border border-gray-600 rounded px-2 py-1.5 text-sm text-gray-100 plat-post_type">';
            ['native_video', 'link_to_youtube', 'text_with_link'].forEach(function (pt) {
                html += '<option value="' + pt + '"' + (pc.post_type === pt ? ' selected' : '') + '>' + pt + '</option>';
            });
            html += '</select></div>';

            // Max daily posts
            html += '<div><label class="block text-xs text-gray-400 mb-1">Max Daily Posts</label>';
            html += '<input type="number" min="1" value="' + esc(String(pc.max_daily_posts || 10)) + '" ' +
                    'class="w-full bg-gray-700 border border-gray-600 rounded px-2 py-1.5 text-sm text-gray-100 plat-max_daily_posts"></div>';

            html += '</div>';

            // Platform config JSON
            html += '<div class="mt-3"><label class="block text-xs text-gray-400 mb-1">Platform Config JSON</label>';
            html += '<textarea class="w-full bg-gray-700 border border-gray-600 rounded px-2 py-1.5 text-sm text-gray-100 plat-platform_config_json h-16 font-mono">' +
                    esc(pc.platform_config_json || '') + '</textarea></div>';

            // Save button
            html += '<div class="mt-3"><button onclick="savePlatformConfig(\'' + esc(platform) + '\')" ' +
                    'class="px-3 py-1.5 bg-blue-700 hover:bg-blue-600 rounded text-xs font-medium">Save ' + esc(platform) + '</button></div>';
            html += '</div>';
        });

        container.innerHTML = html;
    }

    window.saveGeneralConfig = function () {
        var data = {};
        ['daily_video_target', 'video_provider', 'min_relevance_score', 'log_retention_days', 'pipeline_enabled'].forEach(function (key) {
            var el = document.getElementById('cfg-' + key);
            if (el) data[key] = el.value;
        });

        fetchApi('config-save', data, 'POST').then(function (resp) {
            if (resp.success) {
                showToast('General settings saved');
            } else if (resp.errors) {
                showToast(resp.errors.join(', '), 'error');
            }
        });
    };

    window.saveHeygenConfig = function () {
        var data = {};
        ['heygen_template_id', 'heygen_avatar_id', 'heygen_voice_id'].forEach(function (key) {
            var el = document.getElementById('cfg-' + key);
            if (el) data[key] = el.value;
        });

        fetchApi('config-save', data, 'POST').then(function (resp) {
            if (resp.success) {
                showToast('HeyGen settings saved');
            }
        });
    };

    window.saveSeedanceConfig = function () {
        var data = {};
        ['seedance_resolution', 'seedance_duration'].forEach(function (key) {
            var el = document.getElementById('cfg-' + key);
            if (el) data[key] = el.value;
        });

        fetchApi('config-save', data, 'POST').then(function (resp) {
            if (resp.success) {
                showToast('Seedance settings saved');
            }
        });
    };

    window.toggleProviderSections = function () {
        var provider = document.getElementById('cfg-video_provider');
        var heygenSection = document.getElementById('config-heygen-form');
        var seedanceSection = document.getElementById('seedance-settings-section');
        if (!provider) return;

        var val = provider.value;
        if (heygenSection) {
            heygenSection.closest('.bg-gray-800').style.display = val === 'heygen' ? '' : 'none';
        }
        if (seedanceSection) {
            seedanceSection.style.display = val === 'seedance' ? '' : 'none';
        }
    };

    window.savePlatformConfig = function (platform) {
        var container = document.querySelector('[data-platform="' + platform + '"]');
        if (!container) return;

        var data = {
            platform: platform,
            is_enabled: container.querySelector('.plat-is_enabled').value,
            adapter: container.querySelector('.plat-adapter').value,
            post_type: container.querySelector('.plat-post_type').value,
            max_daily_posts: container.querySelector('.plat-max_daily_posts').value,
            platform_config_json: container.querySelector('.plat-platform_config_json').value
        };

        fetchApi('platform-save', data, 'POST').then(function (resp) {
            if (resp.success) {
                showToast(platform + ' config saved');
            }
        });
    };

    // ============================================================
    // LOGS
    // ============================================================
    var logsPage = 1;

    function loadLogs(page) {
        page = page || 1;
        logsPage = page;

        var moduleEl = document.getElementById('logs-module-filter');
        var levelEl = document.getElementById('logs-level-filter');

        var params = { page: page };
        if (moduleEl && moduleEl.value) params.module = moduleEl.value;
        if (levelEl && levelEl.value) params.level = levelEl.value;

        fetchApi('logs', params).then(function (data) {
            var html = '';
            if (data.items.length === 0) {
                html = '<tr><td colspan="5" class="px-4 py-3 text-gray-500">No logs found.</td></tr>';
            } else {
                data.items.forEach(function (log) {
                    var ctxStr = log.context || '';
                    var ctxTruncated = ctxStr.length > 60 ? ctxStr.substring(0, 60) + '...' : ctxStr;

                    html += '<tr class="border-b border-gray-700">' +
                        '<td class="px-4 py-2 text-gray-400 whitespace-nowrap">' + esc(log.created_at) + '</td>' +
                        '<td class="px-4 py-2">' + esc(log.module) + '</td>' +
                        '<td class="px-4 py-2">' + levelBadge(log.level) + '</td>' +
                        '<td class="px-4 py-2 text-gray-300">' + esc(log.message) + '</td>' +
                        '<td class="px-4 py-2">';
                    if (ctxStr) {
                        html += '<span class="context-expandable text-gray-500 text-xs" onclick="this.classList.toggle(\'expanded\')">' +
                                esc(ctxStr) + '</span>';
                    } else {
                        html += '<span class="text-gray-600">--</span>';
                    }
                    html += '</td></tr>';
                });
            }
            document.getElementById('logs-table-body').innerHTML = html;

            renderPagination('logs-pagination', data.page, data.total_pages, function (p) {
                loadLogs(p);
            });
        });
    }

    // ============================================================
    // REDDIT
    // ============================================================
    var redditPage = 1;

    function loadRedditStats() {
        fetchApi('reddit-stats').then(function (data) {
            document.getElementById('reddit-stat-discovered').textContent = data.discovered_today;
            document.getElementById('reddit-stat-matched').textContent = data.matched_today;
            document.getElementById('reddit-stat-commented').textContent = data.commented_today;
            document.getElementById('reddit-stat-skipped').textContent = data.skipped_today;

            var statusEl = document.getElementById('reddit-engagement-status');
            if (data.engagement_enabled) {
                statusEl.textContent = 'Engagement is active';
                statusEl.className = 'ml-3 text-sm text-green-400';
            } else {
                statusEl.textContent = 'Engagement is PAUSED';
                statusEl.className = 'ml-3 text-sm text-red-400 font-bold';
            }
        });
    }

    function loadRedditThreads(page) {
        page = page || 1;
        redditPage = page;

        var statusEl = document.getElementById('reddit-status-filter');
        var subEl = document.getElementById('reddit-subreddit-filter');

        var params = { page: page };
        if (statusEl && statusEl.value) params.status = statusEl.value;
        if (subEl && subEl.value) params.subreddit = subEl.value;

        fetchApi('reddit-threads', params).then(function (data) {
            var html = '';
            if (data.items.length === 0) {
                html = '<tr><td colspan="5" class="px-4 py-3 text-gray-500">No threads found.</td></tr>';
            } else {
                data.items.forEach(function (thread) {
                    var titleTrunc = (thread.title || '').length > 80
                        ? thread.title.substring(0, 80) + '...'
                        : (thread.title || '');
                    var commentPreview = (thread.comment_text || '').length > 60
                        ? thread.comment_text.substring(0, 60) + '...'
                        : (thread.comment_text || '--');

                    html += '<tr class="border-b border-gray-700 cursor-pointer" onclick="showRedditThread(' + thread.id + ')">' +
                        '<td class="px-4 py-2">' + statusBadge(thread.status) + '</td>' +
                        '<td class="px-4 py-2 text-gray-300">r/' + esc(thread.subreddit) + '</td>' +
                        '<td class="px-4 py-2 max-w-xs truncate">' + esc(titleTrunc) + '</td>' +
                        '<td class="px-4 py-2 text-gray-400">' + esc(thread.matched_video_id || '--') + '</td>' +
                        '<td class="px-4 py-2 text-gray-500 text-xs">' + esc(commentPreview) + '</td>' +
                        '</tr>';
                });
            }
            document.getElementById('reddit-threads-body').innerHTML = html;

            renderPagination('reddit-threads-pagination', data.page, data.total_pages, function (p) {
                loadRedditThreads(p);
            });
        });
    }

    window.showRedditThread = function (id) {
        var modal = document.getElementById('reddit-thread-detail-modal');
        var content = document.getElementById('reddit-thread-detail-content');
        modal.classList.remove('hidden');
        content.innerHTML = 'Loading...';

        fetchApi('reddit-threads', { status: '', page: 1 }).then(function (data) {
            var thread = null;
            data.items.forEach(function (t) {
                if (t.id == id) thread = t;
            });

            if (!thread) {
                content.innerHTML = '<p class="text-gray-500">Thread not found in current page.</p>';
                return;
            }

            var html = '<h3 class="text-xl font-bold mb-4">' + esc(thread.title) + '</h3>';
            html += '<div class="space-y-3 text-sm">';
            html += '<div><strong>Subreddit:</strong> r/' + esc(thread.subreddit) + '</div>';
            html += '<div><strong>Author:</strong> u/' + esc(thread.author) + '</div>';
            html += '<div><strong>Status:</strong> ' + statusBadge(thread.status) + '</div>';

            if (thread.body || thread.post_body) {
                html += '<div><strong>Body:</strong></div>';
                html += '<pre class="bg-gray-900 p-3 rounded text-xs text-gray-300 whitespace-pre-wrap">' +
                        esc(thread.body || thread.post_body) + '</pre>';
            }

            if (thread.matched_keywords) {
                html += '<div><strong>Matched Keywords:</strong> ' + esc(thread.matched_keywords) + '</div>';
            }

            if (thread.matched_video_id) {
                html += '<div><strong>Matched Video ID:</strong> ' + esc(String(thread.matched_video_id)) + '</div>';
            }

            if (thread.comment_text) {
                html += '<div><strong>Generated Comment:</strong></div>';
                html += '<pre class="bg-gray-900 p-3 rounded text-xs text-gray-300 whitespace-pre-wrap">' +
                        esc(thread.comment_text) + '</pre>';
            }

            if (thread.permalink) {
                var redditUrl = 'https://www.reddit.com' + thread.permalink;
                html += '<div><strong>Reddit Link:</strong> <a href="' + esc(redditUrl) +
                        '" class="text-blue-400 hover:underline" target="_blank">' + esc(redditUrl) + '</a></div>';
            }

            if (thread.skip_reason) {
                html += '<div class="text-yellow-400"><strong>Skip Reason:</strong> ' + esc(thread.skip_reason) + '</div>';
            }

            html += '</div>';
            content.innerHTML = html;
        });
    };

    window.pauseRedditEngagement = function () {
        if (!confirm('Are you sure you want to pause all Reddit engagement?')) return;

        fetchApi('reddit-pause', {}, 'POST').then(function (data) {
            if (data.success) {
                showToast('Reddit engagement paused');
                loadRedditStats();
            }
        });
    };

    window.toggleRedditKeyword = function (id, btn) {
        btn.disabled = true;
        fetchApi('reddit-keyword-toggle', { id: id }, 'POST').then(function (data) {
            btn.disabled = false;
            if (data.is_active) {
                btn.className = 'px-2 py-0.5 rounded text-xs font-medium bg-green-700 text-green-100';
                btn.textContent = 'Active';
            } else {
                btn.className = 'px-2 py-0.5 rounded text-xs font-medium bg-gray-700 text-gray-400';
                btn.textContent = 'Inactive';
            }
            showToast('Keyword toggled');
        }).catch(function () {
            btn.disabled = false;
        });
    };

    window.addRedditKeyword = function () {
        var keywordEl = document.getElementById('new-keyword');
        var typeEl = document.getElementById('new-keyword-type');

        var keyword = keywordEl.value.trim();
        if (!keyword) {
            showToast('Enter a keyword', 'error');
            return;
        }

        fetchApi('reddit-keyword-add', { keyword: keyword, keyword_type: typeEl.value }, 'POST').then(function (data) {
            if (data.success) {
                showToast('Keyword added');
                keywordEl.value = '';
                // Add row to table
                var tbody = document.getElementById('keywords-table-body');
                var emptyRow = tbody.querySelector('td[colspan]');
                if (emptyRow) emptyRow.parentElement.remove();

                var tr = document.createElement('tr');
                tr.className = 'border-b border-gray-700';
                tr.setAttribute('data-keyword-id', data.id);
                tr.innerHTML = '<td class="px-4 py-2">' + esc(keyword) + '</td>' +
                    '<td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs bg-gray-700">' + esc(typeEl.value) + '</span></td>' +
                    '<td class="px-4 py-2"><button onclick="toggleRedditKeyword(' + data.id + ', this)" ' +
                    'class="px-2 py-0.5 rounded text-xs font-medium bg-green-700 text-green-100">Active</button></td>';
                tbody.appendChild(tr);
            }
        });
    };

    // ============================================================
    // Page initialization and auto-refresh
    // ============================================================
    function initPage() {
        var params = new URLSearchParams(window.location.search);
        var page = params.get('page') || 'dashboard';

        switch (page) {
            case 'dashboard':
                loadDashboard();
                // Auto-refresh every 60 seconds
                setInterval(loadDashboard, 60000);
                break;

            case 'queue':
                loadQueue(1);
                var queueFilter = document.getElementById('queue-status-filter');
                if (queueFilter) {
                    queueFilter.addEventListener('change', function () { loadQueue(1); });
                }
                break;

            case 'feeds':
                // Feeds are server-rendered, nothing to load
                break;

            case 'videos':
                loadVideos();
                break;

            case 'posts':
                loadPosts(1);
                var postsFilter = document.getElementById('posts-platform-filter');
                if (postsFilter) {
                    postsFilter.addEventListener('change', function () { loadPosts(1); });
                }
                break;

            case 'config':
                loadConfig();
                break;

            case 'logs':
                loadLogs(1);
                var logsModuleFilter = document.getElementById('logs-module-filter');
                var logsLevelFilter = document.getElementById('logs-level-filter');
                if (logsModuleFilter) {
                    logsModuleFilter.addEventListener('change', function () { loadLogs(1); });
                }
                if (logsLevelFilter) {
                    logsLevelFilter.addEventListener('change', function () { loadLogs(1); });
                }
                break;

            case 'reddit':
                loadRedditStats();
                loadRedditThreads(1);
                var redditStatusFilter = document.getElementById('reddit-status-filter');
                var redditSubFilter = document.getElementById('reddit-subreddit-filter');
                if (redditStatusFilter) {
                    redditStatusFilter.addEventListener('change', function () { loadRedditThreads(1); });
                }
                if (redditSubFilter) {
                    var debounceTimer = null;
                    redditSubFilter.addEventListener('input', function () {
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(function () { loadRedditThreads(1); }, 500);
                    });
                }
                break;
        }

        // Load pipeline status for sidebar on all pages
        fetchApi('dashboard-stats').then(function (data) {
            var badge = document.getElementById('pipeline-status-badge');
            var statusText = document.getElementById('pipeline-status-text');
            if (badge && statusText) {
                if (data.pipeline_enabled) {
                    badge.className = 'inline-block w-2 h-2 rounded-full bg-green-500 align-middle';
                    statusText.textContent = 'running';
                } else {
                    badge.className = 'inline-block w-2 h-2 rounded-full bg-red-500 align-middle';
                    statusText.textContent = 'paused';
                }
            }
        }).catch(function () {
            // Ignore errors on status fetch
        });
    }

    // Close modals on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal').forEach(function (m) {
                m.classList.add('hidden');
            });
        }
    });

    // Close modals on backdrop click
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('modal')) {
            e.target.classList.add('hidden');
        }
    });

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPage);
    } else {
        initPage();
    }
})();
