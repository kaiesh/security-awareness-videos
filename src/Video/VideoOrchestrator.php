<?php

declare(strict_types=1);

namespace SecurityDrama\Video;

use RuntimeException;
use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\Logger;
use SecurityDrama\Storage;
use SecurityDrama\Video\Adapters\HeyGenAdapter;
use SecurityDrama\Video\Adapters\SeedanceAdapter;
use SecurityDrama\Video\BrollFetcher;
use SecurityDrama\Video\Compositor;
use SecurityDrama\Video\MusicPicker;

final class VideoOrchestrator
{
    private const MODULE = 'video';
    private const TEMP_DIR = '/tmp/securitydrama';

    private Database $db;
    private Config $config;
    private Storage $storage;
    private VideoGeneratorInterface $generator;
    private BrollFetcher $brollFetcher;
    private MusicPicker $musicPicker;
    private Compositor $compositor;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
        $this->storage = Storage::getInstance();
        $this->generator = $this->createAdapter();
        $this->brollFetcher = new BrollFetcher();
        $this->musicPicker  = new MusicPicker();
        $this->compositor   = new Compositor();

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
            'SELECT id, script_id FROM content_queue WHERE status = ?',
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
                        'UPDATE content_queue SET status = ?, failure_reason = ? WHERE id = ?',
                        ['failed', 'Script not found', $item['id']]
                    );
                    continue;
                }

                $scriptData = [
                    'title'            => $script['title_youtube'] ?? $script['hook_line'] ?? '',
                    'narration'        => $script['narration_text'] ?? '',
                    'visual_direction' => $script['visual_direction'] ?? '',
                ];

                $providerVideoId = $this->generator->submitJob($scriptData, []);

                Logger::info(self::MODULE, 'Video job submitted', [
                    'queue_id'          => $item['id'],
                    'provider'          => $this->generator->getProviderName(),
                    'provider_video_id' => $providerVideoId,
                ]);

                $this->db->execute(
                    'INSERT INTO videos (queue_id, script_id, provider, provider_video_id, provider_status, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [
                        $item['id'],
                        $item['script_id'],
                        $this->generator->getProviderName(),
                        $providerVideoId,
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
                    'UPDATE content_queue SET status = ?, failure_reason = ? WHERE id = ?',
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
            'SELECT v.*, cq.id AS queue_id, cq.content_type,
                    s.id AS script_row_id, s.visual_direction AS script_visual_direction
             FROM videos v
             JOIN content_queue cq ON cq.id = v.queue_id
             LEFT JOIN scripts s ON s.id = v.script_id
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
                $status = $this->generator->checkStatus($video['provider_video_id']);

                Logger::debug(self::MODULE, 'Polled video status', [
                    'video_id'          => $video['id'],
                    'provider_video_id' => $video['provider_video_id'],
                    'status'            => $status['status'],
                ]);

                if ($status['status'] === 'completed' && $status['video_url'] !== null) {
                    $tempFile = self::TEMP_DIR . '/' . $video['provider_video_id'] . '.mp4';

                    $this->generator->downloadVideo($status['video_url'], $tempFile);

                    [$uploadSource, $composited, $composedTempFile] = $this->addBackingTrackIfPossible($video, $tempFile);

                    $remotePath = 'videos/' . $video['provider_video_id'] . '.mp4';
                    $storageUrl = $this->storage->upload($uploadSource, $remotePath);

                    $fileSize = filesize($uploadSource) ?: null;

                    $this->db->execute(
                        'UPDATE videos
                         SET provider_status = ?, storage_path = ?, storage_url = ?,
                             file_size_bytes = ?, composited = ?, completed_at = NOW()
                         WHERE id = ?',
                        ['completed', $remotePath, $storageUrl, $fileSize, $composited, $video['id']]
                    );

                    $this->db->execute(
                        'UPDATE content_queue SET status = ? WHERE id = ?',
                        ['pending_publish', $video['queue_id']]
                    );

                    if ($composedTempFile !== null && file_exists($composedTempFile)) {
                        @unlink($composedTempFile);
                    }

                    Logger::info(self::MODULE, 'Video completed and uploaded', [
                        'video_id'    => $video['id'],
                        'storage_url' => $storageUrl,
                        'composited'  => $composited,
                    ]);

                    $processed++;
                } elseif ($status['status'] === 'failed') {
                    $errorReason = $status['error'] ?? 'Unknown provider error';

                    $this->db->execute(
                        'UPDATE videos SET provider_status = ?, provider_error = ? WHERE id = ?',
                        ['failed', $errorReason, $video['id']]
                    );

                    $this->db->execute(
                        'UPDATE content_queue SET status = ?, failure_reason = ? WHERE id = ?',
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
            'heygen'   => new HeyGenAdapter(),
            'seedance' => new SeedanceAdapter(),
            default    => throw new RuntimeException("Unknown video provider: {$provider}"),
        };
    }

    /**
     * Remix the background music on already-completed, already-uploaded
     * videos. Downloads the current mp4 from Spaces, picks a fresh track
     * for the queue item's content_type, runs the music mix, re-uploads
     * to the same storage path, and updates the DB row.
     *
     * @param int|null $videoId  Specific videos.id to remix, or null to
     *                           remix every completed video.
     * @param bool     $force    If false, skip videos that already have
     *                           composited = 1.
     * @return int Number of videos successfully remixed.
     */
    public function remixMusic(?int $videoId = null, bool $force = false): int
    {
        $where = "v.provider_status = 'completed' AND v.storage_path IS NOT NULL";
        $params = [];

        if ($videoId !== null) {
            $where .= ' AND v.id = ?';
            $params[] = $videoId;
        } elseif (!$force) {
            $where .= ' AND v.composited = 0';
        }

        $videos = $this->db->fetchAll(
            "SELECT v.id, v.provider_video_id, v.storage_path, v.composited,
                    cq.id AS queue_id, cq.content_type,
                    s.id AS script_row_id
             FROM videos v
             JOIN content_queue cq ON cq.id = v.queue_id
             LEFT JOIN scripts s ON s.id = v.script_id
             WHERE {$where}
             ORDER BY v.id",
            $params
        );

        if (empty($videos)) {
            Logger::info(self::MODULE, 'Remix: no matching videos', [
                'video_id' => $videoId,
                'force'    => $force,
            ]);
            return 0;
        }

        $remixed = 0;

        foreach ($videos as $video) {
            if ((int) $video['composited'] === 1 && !$force && $videoId === null) {
                continue;
            }

            $sourceTemp = self::TEMP_DIR . '/remix_src_' . $video['provider_video_id'] . '.mp4';
            $mixedTemp  = self::TEMP_DIR . '/remix_out_' . $video['provider_video_id'] . '.mp4';
            $music      = null;

            try {
                $music = $this->musicPicker->pickForCategory((string) $video['content_type']);
                if ($music === null) {
                    Logger::warning(self::MODULE, 'Remix: no active music for category', [
                        'video_id'     => $video['id'],
                        'content_type' => $video['content_type'],
                    ]);
                    continue;
                }

                $this->storage->download((string) $video['storage_path'], $sourceTemp);
                if (!file_exists($sourceTemp)) {
                    throw new \RuntimeException("Failed to download {$video['storage_path']} from storage");
                }

                $this->compositor->addBackingTrack($sourceTemp, $music, $mixedTemp);

                $storageUrl = $this->storage->upload($mixedTemp, (string) $video['storage_path']);
                $fileSize = filesize($mixedTemp) ?: null;

                $this->db->execute(
                    'UPDATE videos
                     SET composited = 1, storage_url = ?, file_size_bytes = ?
                     WHERE id = ?',
                    [$storageUrl, $fileSize, $video['id']]
                );

                $this->appendMusicCredit((int) $video['script_row_id'], $music);

                Logger::info(self::MODULE, 'Remix complete', [
                    'video_id'    => $video['id'],
                    'storage_url' => $storageUrl,
                    'music'       => $music['name'] ?? null,
                ]);

                $remixed++;
            } catch (\Throwable $e) {
                Logger::error(self::MODULE, 'Remix failed', [
                    'video_id' => $video['id'],
                    'error'    => $e->getMessage(),
                ]);
            } finally {
                foreach ([$sourceTemp, $mixedTemp] as $tmp) {
                    if (file_exists($tmp)) {
                        @unlink($tmp);
                    }
                }
                if ($music !== null && isset($music['local_path']) && file_exists($music['local_path'])) {
                    @unlink($music['local_path']);
                }
            }
        }

        Logger::info(self::MODULE, "Remix pass complete: {$remixed} video(s) remixed");
        return $remixed;
    }

    /**
     * Mix a background music track under the provider's finished mp4.
     * The Video Agent (HeyGen) already produces a cut-together video, so
     * we don't do our own b-roll compositing — we just lay a quiet music
     * bed under the narrator audio and re-upload. Returns
     * [uploadSource, composited (0|1), composedTempFile|null].
     * On any failure, soft-falls back to the source file.
     *
     * @return array{0:string,1:int,2:?string}
     */
    private function addBackingTrackIfPossible(array $video, string $sourceMp4): array
    {
        $music = null;
        $mixedOut = self::TEMP_DIR . '/mixed_' . $video['provider_video_id'] . '.mp4';

        try {
            $music = $this->musicPicker->pickForCategory((string) $video['content_type']);
            if ($music === null) {
                return [$sourceMp4, 0, null];
            }

            $this->compositor->addBackingTrack($sourceMp4, $music, $mixedOut);

            $this->appendMusicCredit((int) $video['script_row_id'], $music);

            return [$mixedOut, 1, $mixedOut];
        } catch (\Throwable $e) {
            Logger::warning(self::MODULE, 'Backing track mix failed, falling back to source', [
                'video_id' => $video['id'],
                'error'    => $e->getMessage(),
            ]);
            if (file_exists($mixedOut)) {
                @unlink($mixedOut);
            }
            return [$sourceMp4, 0, null];
        } finally {
            if ($music !== null && isset($music['local_path']) && file_exists($music['local_path'])) {
                @unlink($music['local_path']);
            }
        }
    }

    /**
     * Append a music attribution line to the script's YouTube description.
     * No-op when the track has no credit_text set.
     */
    private function appendMusicCredit(int $scriptId, array $music): void
    {
        if ($scriptId <= 0 || empty($music['credit_text'])) {
            return;
        }

        $script = $this->db->fetchOne(
            'SELECT description_youtube FROM scripts WHERE id = ?',
            [$scriptId]
        );
        if ($script === null) {
            return;
        }

        $body = (string) ($script['description_youtube'] ?? '');
        $block = "\n\n---\nMusic: " . $music['credit_text'] . "\n";

        $this->db->execute(
            'UPDATE scripts SET description_youtube = ? WHERE id = ?',
            [$body . $block, $scriptId]
        );
    }
}
