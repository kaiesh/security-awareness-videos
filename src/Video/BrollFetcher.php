<?php

declare(strict_types=1);

namespace SecurityDrama\Video;

use RuntimeException;
use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\HttpClient;
use SecurityDrama\Logger;
use SecurityDrama\Storage;

final class BrollFetcher
{
    private const MODULE  = 'broll';
    private const TEMP_DIR = '/tmp/securitydrama';
    private const SPACES_PREFIX = 'broll/';
    private const PEXELS_API = 'https://api.pexels.com/videos/search';

    private Database $db;
    private Storage $storage;
    private HttpClient $http;
    private Config $config;

    public function __construct()
    {
        $this->db      = Database::getInstance();
        $this->storage = Storage::getInstance();
        $this->http    = new HttpClient();
        $this->config  = Config::getInstance();

        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0750, true);
        }
    }

    /**
     * Resolve every `broll` segment to a local mp4. Returns a map of
     * segment_index => local_temp_path. Narrator segments are skipped.
     *
     * Throws on any failure so the caller can soft-fallback to narrator-only.
     *
     * @param array $segments  Ordered list from scripts.visual_direction.segments
     * @return array<int,string>
     */
    public function fetchForSegments(array $segments): array
    {
        $assets = [];

        foreach ($segments as $i => $segment) {
            $mode = $segment['visual_mode'] ?? '';
            if ($mode !== 'broll') {
                continue;
            }

            $query = trim((string) ($segment['broll_query'] ?? ''));
            if ($query === '') {
                throw new RuntimeException("Segment {$i} is broll but has empty broll_query");
            }

            $minDuration = (float) ($segment['duration_seconds'] ?? 0);

            $assets[$i] = $this->resolveQuery($query, $minDuration);
        }

        return $assets;
    }

    private function resolveQuery(string $query, float $minDuration): string
    {
        $hash = sha1(mb_strtolower($query));
        $localPath = self::TEMP_DIR . '/broll_' . $hash . '.mp4';

        $cached = $this->db->fetchOne(
            'SELECT * FROM broll_cache WHERE query_hash = ?',
            [$hash]
        );

        if ($cached !== null) {
            Logger::debug(self::MODULE, 'Cache hit', ['query' => $query, 'hash' => $hash]);
            $this->storage->download($cached['storage_path'], $localPath);
            if (!file_exists($localPath)) {
                throw new RuntimeException("Cached b-roll missing in Spaces: {$cached['storage_path']}");
            }
            return $localPath;
        }

        Logger::info(self::MODULE, 'Cache miss, fetching from Pexels', ['query' => $query]);

        $chosen = $this->searchPexels($query, $minDuration);

        $this->http->downloadToFile($chosen['file_link'], $localPath);

        $remotePath = self::SPACES_PREFIX . $hash . '.mp4';
        $this->storage->upload($localPath, $remotePath);

        $this->db->execute(
            'INSERT INTO broll_cache
             (query_hash, query_text, source, source_video_id, storage_path,
              duration_seconds, width, height, credit_text, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $hash,
                $query,
                'pexels',
                (string) $chosen['source_video_id'],
                $remotePath,
                $chosen['duration'],
                $chosen['width'],
                $chosen['height'],
                $chosen['credit_text'],
            ]
        );

        return $localPath;
    }

    /**
     * @return array{file_link:string,source_video_id:int|string,duration:float,width:int,height:int,credit_text:string}
     */
    private function searchPexels(string $query, float $minDuration): array
    {
        $apiKey = (string) $this->config->get('PEXELS_API_KEY', '');
        if ($apiKey === '') {
            throw new RuntimeException('PEXELS_API_KEY is not configured');
        }

        $orientation = (string) $this->config->get('broll_aspect', 'portrait');
        $minWidth    = (int) $this->config->get('broll_min_width', 1080);

        $url = self::PEXELS_API
            . '?query=' . urlencode($query)
            . '&per_page=10'
            . '&orientation=' . urlencode($orientation);

        $response = $this->http->get($url, [
            'Authorization' => $apiKey,
            'Accept'        => 'application/json',
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(
                "Pexels API HTTP {$response['status']}: " . substr($response['body'], 0, 300)
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['videos'])) {
            throw new RuntimeException("Pexels returned no videos for query: {$query}");
        }

        // Pick the first video that's long enough, falling back to longest available.
        $candidate = null;
        foreach ($data['videos'] as $video) {
            if (((float) ($video['duration'] ?? 0)) >= $minDuration) {
                $candidate = $video;
                break;
            }
        }
        if ($candidate === null) {
            usort($data['videos'], static fn($a, $b) => ((float) ($b['duration'] ?? 0)) <=> ((float) ($a['duration'] ?? 0)));
            $candidate = $data['videos'][0];
        }

        $files = $candidate['video_files'] ?? [];
        if (empty($files)) {
            throw new RuntimeException("Pexels video {$candidate['id']} has no video_files");
        }

        // Prefer hd at >= minWidth, fall back to the largest available file.
        $best = null;
        foreach ($files as $f) {
            $w = (int) ($f['width'] ?? 0);
            $quality = (string) ($f['quality'] ?? '');
            if ($quality === 'hd' && $w >= $minWidth) {
                $best = $f;
                break;
            }
        }
        if ($best === null) {
            usort($files, static fn($a, $b) => ((int) ($b['width'] ?? 0)) <=> ((int) ($a['width'] ?? 0)));
            $best = $files[0];
        }

        $userName = $candidate['user']['name'] ?? 'Pexels';

        return [
            'file_link'       => (string) $best['link'],
            'source_video_id' => $candidate['id'] ?? '',
            'duration'        => (float) ($candidate['duration'] ?? 0),
            'width'           => (int) ($best['width'] ?? 0),
            'height'          => (int) ($best['height'] ?? 0),
            'credit_text'     => "Video by {$userName} from Pexels",
        ];
    }
}
