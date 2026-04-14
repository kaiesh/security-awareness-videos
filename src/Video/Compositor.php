<?php

declare(strict_types=1);

namespace SecurityDrama\Video;

use RuntimeException;
use SecurityDrama\Logger;

final class Compositor
{
    private const MODULE   = 'compositor';
    private const TEMP_DIR = '/tmp/securitydrama';
    private const DURATION_TOLERANCE_SECS = 3.0;

    /**
     * Build a composited mp4 from a narrator video and a segment plan.
     *
     * @param string                $narratorMp4   Local path to the HeyGen narrator mp4.
     * @param array                 $segments      Ordered segment plan from the script.
     * @param array<int,string>     $brollAssets   Map of segment_index => local b-roll mp4 path.
     * @param array<string,mixed>|null $music      MusicPicker descriptor or null.
     * @param string                $outputPath    Where to write the final mp4.
     */
    public function compose(
        string $narratorMp4,
        array $segments,
        array $brollAssets,
        ?array $music,
        string $outputPath
    ): void {
        if (!file_exists($narratorMp4)) {
            throw new RuntimeException("Narrator mp4 not found: {$narratorMp4}");
        }
        if (empty($segments)) {
            throw new RuntimeException('Cannot compose with empty segments');
        }

        $workDir = self::TEMP_DIR . '/compose_' . bin2hex(random_bytes(4));
        if (!mkdir($workDir, 0750, true) && !is_dir($workDir)) {
            throw new RuntimeException("Failed to create work dir: {$workDir}");
        }

        try {
            $narratorDuration = $this->probeDuration($narratorMp4);

            $segmentSum = 0.0;
            foreach ($segments as $seg) {
                $segmentSum += (float) ($seg['duration_seconds'] ?? 0);
            }

            $delta = abs($segmentSum - $narratorDuration);
            if ($delta > self::DURATION_TOLERANCE_SECS) {
                throw new RuntimeException(sprintf(
                    'Segment durations sum to %.2fs but narrator is %.2fs (delta %.2fs > tolerance %.2fs)',
                    $segmentSum,
                    $narratorDuration,
                    $delta,
                    self::DURATION_TOLERANCE_SECS
                ));
            }

            $cumulative = 0.0;
            $listLines = [];

            foreach ($segments as $i => $seg) {
                $duration = (float) ($seg['duration_seconds'] ?? 0);
                $start = $cumulative;
                $end = $cumulative + $duration;

                // Clamp the final segment to the actual narrator length so the
                // last few hundred ms aren't lost when Claude rounds durations.
                if ($i === array_key_last($segments)) {
                    $end = $narratorDuration;
                }

                $segPath = $workDir . '/seg_' . $i . '.mp4';
                $mode = $seg['visual_mode'] ?? 'narrator';

                if ($mode === 'broll' && isset($brollAssets[$i])) {
                    $this->renderBrollSegment(
                        $brollAssets[$i],
                        $narratorMp4,
                        $start,
                        $end,
                        $segPath
                    );
                } else {
                    $this->renderNarratorSegment($narratorMp4, $start, $end, $segPath);
                }

                $listLines[] = "file '" . $segPath . "'";
                $cumulative = $end;
            }

            $listPath = $workDir . '/list.txt';
            file_put_contents($listPath, implode("\n", $listLines) . "\n");

            $roughPath = $workDir . '/rough.mp4';
            $this->runFfmpeg([
                '-y',
                '-f', 'concat',
                '-safe', '0',
                '-i', $listPath,
                '-c', 'copy',
                $roughPath,
            ]);

            if ($music !== null) {
                $this->mixMusic($roughPath, $music, $outputPath);
            } else {
                if (!rename($roughPath, $outputPath)) {
                    throw new RuntimeException("Failed to move rough cut to {$outputPath}");
                }
            }

            Logger::info(self::MODULE, 'Composition complete', [
                'output'           => $outputPath,
                'segments'         => count($segments),
                'narrator_duration' => $narratorDuration,
                'music'            => $music['name'] ?? null,
            ]);
        } finally {
            $this->rrmdir($workDir);
        }
    }

    private function renderNarratorSegment(string $narratorMp4, float $start, float $end, string $outPath): void
    {
        $this->runFfmpeg([
            '-y',
            '-ss', sprintf('%.3f', $start),
            '-to', sprintf('%.3f', $end),
            '-i', $narratorMp4,
            '-vf', 'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1,fps=30',
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '23',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-ar', '48000',
            '-ac', '2',
            $outPath,
        ]);
    }

    private function renderBrollSegment(
        string $brollMp4,
        string $narratorMp4,
        float $start,
        float $end,
        string $outPath
    ): void {
        $duration = max(0.1, $end - $start);
        $this->runFfmpeg([
            '-y',
            '-stream_loop', '-1',
            '-t', sprintf('%.3f', $duration),
            '-i', $brollMp4,
            '-ss', sprintf('%.3f', $start),
            '-to', sprintf('%.3f', $end),
            '-i', $narratorMp4,
            '-filter_complex',
                '[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1,fps=30[v]',
            '-map', '[v]',
            '-map', '1:a',
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '23',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-ar', '48000',
            '-ac', '2',
            '-shortest',
            $outPath,
        ]);
    }

    private function mixMusic(string $roughPath, array $music, string $outputPath): void
    {
        $this->addBackingTrack($roughPath, $music, $outputPath);
    }

    /**
     * Mix a background music track under an arbitrary finished mp4.
     *
     * Stream-copies the video (no quality loss) and only re-encodes audio.
     * The narrator audio passes through at unity — `normalize=0` stops
     * amix from halving both inputs when there are two sources — and the
     * music is attenuated to the per-track volume. `-stream_loop -1` loops
     * music that's shorter than the video and `-shortest` clamps the output
     * length to the narrator side so trailing music is trimmed.
     *
     * @param array{local_path:string,volume:float,name?:string} $music
     */
    public function addBackingTrack(string $inputMp4, array $music, string $outputPath): void
    {
        if (!file_exists($inputMp4)) {
            throw new RuntimeException("Input mp4 not found for backing track: {$inputMp4}");
        }

        $volume = max(0.0, min(1.0, (float) ($music['volume'] ?? 0.15)));
        $musicPath = (string) ($music['local_path'] ?? '');
        if ($musicPath === '' || !file_exists($musicPath)) {
            throw new RuntimeException("Music track file missing: {$musicPath}");
        }

        $this->runFfmpeg([
            '-y',
            '-i', $inputMp4,
            '-stream_loop', '-1',
            '-i', $musicPath,
            '-filter_complex',
                sprintf(
                    '[1:a]volume=%.3f[bg];[0:a][bg]amix=inputs=2:duration=first:dropout_transition=0:normalize=0[aout]',
                    $volume
                ),
            '-map', '0:v',
            '-map', '[aout]',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-shortest',
            $outputPath,
        ]);

        Logger::info(self::MODULE, 'Backing track mixed', [
            'input'  => $inputMp4,
            'output' => $outputPath,
            'music'  => $music['name'] ?? null,
            'volume' => $volume,
        ]);
    }

    private function probeDuration(string $videoPath): float
    {
        $cmd = [
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ];

        [$exit, $stdout, $stderr] = $this->runProcess($cmd);
        if ($exit !== 0) {
            throw new RuntimeException("ffprobe failed (exit {$exit}): {$stderr}");
        }

        $duration = (float) trim($stdout);
        if ($duration <= 0) {
            throw new RuntimeException("ffprobe returned invalid duration: {$stdout}");
        }
        return $duration;
    }

    private function runFfmpeg(array $args): void
    {
        $cmd = array_merge(['ffmpeg', '-hide_banner', '-loglevel', 'error'], $args);
        [$exit, , $stderr] = $this->runProcess($cmd);
        if ($exit !== 0) {
            throw new RuntimeException(
                'ffmpeg failed (exit ' . $exit . '): ' . substr(trim($stderr), 0, 1000)
            );
        }
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runProcess(array $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to spawn process: ' . implode(' ', $cmd));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($proc);

        return [$exit, $stdout, $stderr];
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
