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

                    [$uploadSource, $composited, $composedTempFile] = $this->composeIfPossible($video, $tempFile);

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
     * Try to composite the narrator mp4 with b-roll + background music.
     * Returns [uploadSource, composited (0|1), composedTempFile|null].
     * On any failure, soft-falls back to the narrator-only file.
     *
     * @return array{0:string,1:int,2:?string}
     */
    private function composeIfPossible(array $video, string $narratorTempFile): array
    {
        $rawDirection = $video['script_visual_direction'] ?? null;
        if ($rawDirection === null || $rawDirection === '') {
            return [$narratorTempFile, 0, null];
        }

        $decoded  = json_decode((string) $rawDirection, true);
        $segments = is_array($decoded) ? ($decoded['segments'] ?? []) : [];

        if (!is_array($segments) || empty($segments)) {
            return [$narratorTempFile, 0, null];
        }

        $brollAssets = [];
        $music = null;
        $composeOut = self::TEMP_DIR . '/composed_' . $video['provider_video_id'] . '.mp4';

        try {
            $brollAssets = $this->brollFetcher->fetchForSegments($segments);
            $music = $this->musicPicker->pickForCategory((string) $video['content_type']);
            $this->compositor->compose($narratorTempFile, $segments, $brollAssets, $music, $composeOut);

            $this->appendCredits((int) $video['script_row_id'], $brollAssets, $music);

            return [$composeOut, 1, $composeOut];
        } catch (\Throwable $e) {
            Logger::warning(self::MODULE, 'Compose failed, falling back to narrator-only', [
                'video_id' => $video['id'],
                'error'    => $e->getMessage(),
            ]);
            if (file_exists($composeOut)) {
                @unlink($composeOut);
            }
            return [$narratorTempFile, 0, null];
        } finally {
            foreach ($brollAssets as $p) {
                if (is_string($p) && file_exists($p)) {
                    @unlink($p);
                }
            }
            if ($music !== null && isset($music['local_path']) && file_exists($music['local_path'])) {
                @unlink($music['local_path']);
            }
        }
    }

    /**
     * Append b-roll and music credits to the script's youtube description.
     * Pexels TOS requires attribution for any video we publish; the music
     * credit is only included when the track row provides one.
     *
     * @param array<int,string> $brollAssets segment_index => local_path (only used
     *                                       to size the lookup; the canonical credit
     *                                       text comes from the broll_cache row)
     */
    private function appendCredits(int $scriptId, array $brollAssets, ?array $music): void
    {
        if ($scriptId <= 0) {
            return;
        }

        $creditLines = [];

        foreach ($brollAssets as $localPath) {
            // Local filename is "broll_<sha1>.mp4" — recover the hash for lookup.
            $base = basename((string) $localPath, '.mp4');
            $hash = str_starts_with($base, 'broll_') ? substr($base, 6) : '';
            if ($hash === '') {
                continue;
            }
            $row = $this->db->fetchOne(
                'SELECT credit_text FROM broll_cache WHERE query_hash = ?',
                [$hash]
            );
            if ($row !== null && !empty($row['credit_text'])) {
                $creditLines[] = (string) $row['credit_text'];
            }
        }

        $creditLines = array_values(array_unique($creditLines));

        $musicLine = null;
        if ($music !== null && !empty($music['credit_text'])) {
            $musicLine = 'Music: ' . $music['credit_text'];
        }

        if (empty($creditLines) && $musicLine === null) {
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
        $block = "\n\n---\n";
        if (!empty($creditLines)) {
            $block .= "Footage credits:\n" . implode("\n", $creditLines) . "\n";
        }
        if ($musicLine !== null) {
            $block .= $musicLine . "\n";
        }

        $this->db->execute(
            'UPDATE scripts SET description_youtube = ? WHERE id = ?',
            [$body . $block, $scriptId]
        );
    }
}
