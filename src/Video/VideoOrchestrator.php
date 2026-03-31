<?php

declare(strict_types=1);

namespace SecurityDrama\Video;

use RuntimeException;
use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\Logger;
use SecurityDrama\Storage;
use SecurityDrama\Video\Adapters\HeyGenAdapter;

final class VideoOrchestrator
{
    private const MODULE = 'video';
    private const TEMP_DIR = '/tmp/securitydrama';

    private Database $db;
    private Config $config;
    private Storage $storage;
    private VideoGeneratorInterface $generator;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
        $this->storage = Storage::getInstance();
        $this->generator = $this->createAdapter();

        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0750, true);
        }
    }

    /**
     * Submit video generation jobs for all content_queue items with status pending_video.
     */
    public function submitPending(): int
    {
        $pending = $this->db->fetchAll(
            'SELECT id, script_id, topic_id FROM content_queue WHERE status = ?',
            ['pending_video']
        );

        if (empty($pending)) {
            Logger::debug(self::MODULE, 'No pending_video items in queue');
            return 0;
        }

        $submitted = 0;

        foreach ($pending as $item) {
            try {
                $script = $this->db->fetchOne(
                    'SELECT * FROM scripts WHERE id = ?',
                    [$item['script_id']]
                );

                if ($script === null) {
                    Logger::error(self::MODULE, 'Script not found for queue item', [
                        'queue_id'  => $item['id'],
                        'script_id' => $item['script_id'],
                    ]);
                    $this->db->execute(
                        'UPDATE content_queue SET status = ?, error_reason = ? WHERE id = ?',
                        ['failed', 'Script not found', $item['id']]
                    );
                    continue;
                }

                $scriptData = [
                    'title'     => $script['title'] ?? '',
                    'narration' => $script['narration'] ?? $script['content'] ?? '',
                ];

                $providerJobId = $this->generator->submitJob($scriptData, []);

                Logger::info(self::MODULE, 'Video job submitted', [
                    'queue_id'        => $item['id'],
                    'provider'        => $this->generator->getProviderName(),
                    'provider_job_id' => $providerJobId,
                ]);

                $this->db->execute(
                    'INSERT INTO videos (queue_id, script_id, provider, provider_job_id, provider_status, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [
                        $item['id'],
                        $item['script_id'],
                        $this->generator->getProviderName(),
                        $providerJobId,
                        'pending',
                    ]
                );

                $this->db->execute(
                    'UPDATE content_queue SET status = ? WHERE id = ?',
                    ['generating_video', $item['id']]
                );

                $submitted++;
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, 'Failed to submit video job', [
                    'queue_id' => $item['id'],
                    'error'    => $e->getMessage(),
                ]);
                $this->db->execute(
                    'UPDATE content_queue SET status = ?, error_reason = ? WHERE id = ?',
                    ['failed', 'Video submission error: ' . $e->getMessage(), $item['id']]
                );
            }
        }

        Logger::info(self::MODULE, "Submitted {$submitted} video jobs");
        return $submitted;
    }

    /**
     * Poll all in-progress video jobs, download completed ones, and update statuses.
     */
    public function pollInProgress(): int
    {
        $inProgress = $this->db->fetchAll(
            'SELECT v.*, cq.id AS queue_id
             FROM videos v
             JOIN content_queue cq ON cq.id = v.queue_id
             WHERE v.provider_status IN (?, ?)',
            ['pending', 'processing']
        );

        if (empty($inProgress)) {
            Logger::debug(self::MODULE, 'No in-progress video jobs to poll');
            return 0;
        }

        $processed = 0;

        foreach ($inProgress as $video) {
            $tempFile = null;

            try {
                $status = $this->generator->checkStatus($video['provider_job_id']);

                Logger::debug(self::MODULE, 'Polled video status', [
                    'video_id'        => $video['id'],
                    'provider_job_id' => $video['provider_job_id'],
                    'status'          => $status['status'],
                ]);

                if ($status['status'] === 'completed' && $status['video_url'] !== null) {
                    $tempFile = self::TEMP_DIR . '/' . $video['provider_job_id'] . '.mp4';

                    $this->generator->downloadVideo($status['video_url'], $tempFile);

                    $remotePath = 'videos/' . $video['provider_job_id'] . '.mp4';
                    $storageUrl = $this->storage->upload($tempFile, $remotePath);

                    $fileSize = filesize($tempFile) ?: null;

                    $this->db->execute(
                        'UPDATE videos
                         SET provider_status = ?, video_url = ?, storage_url = ?,
                             file_size = ?, completed_at = NOW()
                         WHERE id = ?',
                        ['completed', $status['video_url'], $storageUrl, $fileSize, $video['id']]
                    );

                    $this->db->execute(
                        'UPDATE content_queue SET status = ? WHERE id = ?',
                        ['pending_publish', $video['queue_id']]
                    );

                    Logger::info(self::MODULE, 'Video completed and uploaded', [
                        'video_id'    => $video['id'],
                        'storage_url' => $storageUrl,
                    ]);

                    $processed++;
                } elseif ($status['status'] === 'failed') {
                    $errorReason = $status['error'] ?? 'Unknown provider error';

                    $this->db->execute(
                        'UPDATE videos SET provider_status = ?, error_reason = ? WHERE id = ?',
                        ['failed', $errorReason, $video['id']]
                    );

                    $this->db->execute(
                        'UPDATE content_queue SET status = ?, error_reason = ? WHERE id = ?',
                        ['failed', 'Video generation failed: ' . $errorReason, $video['queue_id']]
                    );

                    Logger::error(self::MODULE, 'Video generation failed', [
                        'video_id' => $video['id'],
                        'error'    => $errorReason,
                    ]);

                    $processed++;
                } else {
                    // Still pending or processing - update status if changed
                    if ($status['status'] !== $video['provider_status']) {
                        $this->db->execute(
                            'UPDATE videos SET provider_status = ? WHERE id = ?',
                            [$status['status'], $video['id']]
                        );
                    }
                }
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, 'Error polling video status', [
                    'video_id' => $video['id'],
                    'error'    => $e->getMessage(),
                ]);
            } finally {
                if ($tempFile !== null && file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        }

        Logger::info(self::MODULE, "Processed {$processed} video jobs");
        return $processed;
    }

    private function createAdapter(): VideoGeneratorInterface
    {
        $provider = $this->config->get('video_provider', 'heygen');

        return match ($provider) {
            'heygen' => new HeyGenAdapter(),
            default  => throw new RuntimeException("Unknown video provider: {$provider}"),
        };
    }
}
