<?php

declare(strict_types=1);

namespace SecurityDrama\Publish;

use RuntimeException;
use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\Logger;
use SecurityDrama\Storage;
use SecurityDrama\Publish\Adapters\DirectFacebookAdapter;
use SecurityDrama\Publish\Adapters\DirectInstagramAdapter;
use SecurityDrama\Publish\Adapters\DirectRedditAdapter;
use SecurityDrama\Publish\Adapters\DirectXAdapter;
use SecurityDrama\Publish\Adapters\DirectYouTubeAdapter;
use SecurityDrama\Publish\Adapters\MissinglettrAdapter;

final class SocialPublisher
{
    private const TEMP_DIR = '/tmp/securitydrama/';
    private const MODULE = 'publish';

    private Database $db;
    private Storage $storage;
    private Config $config;
    private PlatformFormatter $formatter;

    /** @var array<string, string> Map of downloaded video_id => local path */
    private array $downloadedVideos = [];

    /** @var string|null YouTube URL from the first YouTube publish */
    private ?string $youtubeUrl = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->storage = Storage::getInstance();
        $this->config = Config::getInstance();
        $this->formatter = new PlatformFormatter();
    }

    /**
     * Run the social publishing pipeline for all pending queue items.
     */
    public function run(): void
    {
        Logger::info(self::MODULE, 'Social publishing run started');

        $this->ensureTempDir();

        $queueItems = $this->db->fetchAll(
            "SELECT * FROM content_queue WHERE status = 'pending_publish' ORDER BY created_at ASC"
        );

        if (empty($queueItems)) {
            Logger::info(self::MODULE, 'No pending_publish items in queue');
            return;
        }

        Logger::info(self::MODULE, sprintf('Found %d items to publish', count($queueItems)));

        foreach ($queueItems as $queueItem) {
            try {
                $this->processQueueItem($queueItem);
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, "Failed to process queue item {$queueItem['id']}: {$e->getMessage()}");

                $this->db->execute(
                    "UPDATE content_queue SET status = 'publish_failed', error_message = ?, updated_at = NOW() WHERE id = ?",
                    [$e->getMessage(), $queueItem['id']]
                );
            }
        }

        $this->cleanupTempFiles();

        Logger::info(self::MODULE, 'Social publishing run complete');
    }

    private function processQueueItem(array $queueItem): void
    {
        $videoId = $queueItem['video_id'];
        $scriptId = $queueItem['script_id'];

        Logger::info(self::MODULE, "Processing queue item {$queueItem['id']}", [
            'video_id'  => $videoId,
            'script_id' => $scriptId,
        ]);

        $video = $this->db->fetchOne('SELECT * FROM videos WHERE id = ?', [$videoId]);
        if ($video === null) {
            throw new RuntimeException("Video record not found: {$videoId}");
        }

        $script = $this->db->fetchOne('SELECT * FROM scripts WHERE id = ?', [$scriptId]);
        if ($script === null) {
            throw new RuntimeException("Script record not found: {$scriptId}");
        }

        // Download video locally once per video
        $localPath = $this->downloadVideo($video);
        $video['local_path'] = $localPath;

        // Get enabled platform configs
        $platformConfigs = $this->db->fetchAll(
            "SELECT * FROM platform_config WHERE is_enabled = 1 AND adapter != 'disabled' ORDER BY priority ASC"
        );

        if (empty($platformConfigs)) {
            Logger::warning(self::MODULE, 'No enabled platform configurations found');
            return;
        }

        // Reset YouTube URL for this queue item
        $this->youtubeUrl = null;

        // Separate YouTube from other platforms - YouTube goes first
        $youtubeConfigs = [];
        $otherConfigs = [];

        foreach ($platformConfigs as $pc) {
            if ($pc['platform'] === 'youtube') {
                $youtubeConfigs[] = $pc;
            } else {
                $otherConfigs[] = $pc;
            }
        }

        // Publish to YouTube first
        foreach ($youtubeConfigs as $pc) {
            $this->publishToPlatform($pc, $video, $script);
        }

        // Then publish to all other platforms
        foreach ($otherConfigs as $pc) {
            $this->publishToPlatform($pc, $video, $script);
        }

        // Mark queue item as published
        $this->db->execute(
            "UPDATE content_queue SET status = 'published', updated_at = NOW() WHERE id = ?",
            [$queueItem['id']]
        );

        Logger::info(self::MODULE, "Queue item {$queueItem['id']} published successfully");
    }

    private function publishToPlatform(array $platformConfig, array $video, array $script): void
    {
        $platform = $platformConfig['platform'];
        $adapterName = $platformConfig['adapter'];

        Logger::info(self::MODULE, "Publishing to {$platform} via {$adapterName}");

        // Create social_posts record
        $this->db->execute(
            "INSERT INTO social_posts (video_id, script_id, platform, adapter, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())",
            [$video['id'], $script['id'], $platform, $adapterName]
        );
        $postRecordId = $this->db->lastInsertId();

        try {
            $adapter = $this->instantiateAdapter($adapterName);

            // Format content for this platform
            $contentData = $this->formatter->format($platform, $script);

            // If post_type is link_to_youtube, inject YouTube URL
            $postType = $platformConfig['post_type'] ?? 'native_video';
            if ($postType === 'link_to_youtube' && $this->youtubeUrl !== null) {
                $platformConfig['youtube_url'] = $this->youtubeUrl;
            }

            $result = $adapter->publish($platform, $video, $contentData, $platformConfig);

            if ($result['success']) {
                // Capture YouTube URL for link_to_youtube post types
                if ($platform === 'youtube' && !empty($result['post_url'])) {
                    $this->youtubeUrl = $result['post_url'];
                }

                $this->db->execute(
                    "UPDATE social_posts SET status = 'published', external_post_id = ?, external_post_url = ?, updated_at = NOW() WHERE id = ?",
                    [$result['post_id'], $result['post_url'], $postRecordId]
                );

                Logger::info(self::MODULE, "Published to {$platform}: {$result['post_url']}");
            } else {
                $this->db->execute(
                    "UPDATE social_posts SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?",
                    [$result['error'], $postRecordId]
                );

                Logger::error(self::MODULE, "Publish to {$platform} failed: {$result['error']}");
            }
        } catch (\Throwable $e) {
            $this->db->execute(
                "UPDATE social_posts SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?",
                [$e->getMessage(), $postRecordId]
            );

            Logger::error(self::MODULE, "Exception publishing to {$platform}: {$e->getMessage()}");
        }
    }

    private function instantiateAdapter(string $adapterName): PublishAdapterInterface
    {
        return match ($adapterName) {
            'missinglettr'     => new MissinglettrAdapter(),
            'direct_youtube'   => new DirectYouTubeAdapter(),
            'direct_x'         => new DirectXAdapter(),
            'direct_instagram' => new DirectInstagramAdapter(),
            'direct_facebook'  => new DirectFacebookAdapter(),
            'direct_reddit'    => new DirectRedditAdapter(),
            default            => throw new RuntimeException("Unknown adapter: {$adapterName}"),
        };
    }

    /**
     * Download video from DO Spaces to local temp directory. Caches per video ID.
     */
    private function downloadVideo(array $video): string
    {
        $videoId = (string) $video['id'];

        if (isset($this->downloadedVideos[$videoId])) {
            return $this->downloadedVideos[$videoId];
        }

        $remotePath = $video['storage_path'] ?? $video['spaces_path'] ?? '';
        if ($remotePath === '') {
            throw new RuntimeException("No storage path for video {$videoId}");
        }

        $filename = basename($remotePath);
        $localPath = self::TEMP_DIR . $filename;

        Logger::info(self::MODULE, "Downloading video {$videoId} to {$localPath}");

        $success = $this->storage->download($remotePath, $localPath);
        if (!$success) {
            throw new RuntimeException("Failed to download video {$videoId} from storage");
        }

        $this->downloadedVideos[$videoId] = $localPath;

        return $localPath;
    }

    private function ensureTempDir(): void
    {
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }
    }

    private function cleanupTempFiles(): void
    {
        foreach ($this->downloadedVideos as $videoId => $localPath) {
            if (file_exists($localPath)) {
                unlink($localPath);
                Logger::debug(self::MODULE, "Cleaned up temp file: {$localPath}");
            }
        }
        $this->downloadedVideos = [];
    }
}
