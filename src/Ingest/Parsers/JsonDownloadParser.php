<?php

declare(strict_types=1);

namespace SecurityDrama\Ingest\Parsers;

use SecurityDrama\HttpClient;
use SecurityDrama\Logger;

final class JsonDownloadParser
{
    private const CACHE_DIR = '/tmp/securitydrama';

    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Download a JSON file, diff against previous download, return new entries.
     *
     * The source config should include:
     *   - url: URL to the JSON file
     *   - slug: unique identifier used to key the cache file
     *   - response_map: optional map with 'items_path' to locate the array
     *
     * @param array $source Feed source row.
     * @return array<int, array> Newly added items since last download.
     */
    public function parse(array $source): array
    {
        $url  = $source['url'];
        $slug = $source['slug'];

        $responseMap = $source['response_map'] ?? [];
        if (is_string($responseMap)) {
            $responseMap = json_decode($responseMap, true) ?? [];
        }

        Logger::info("JsonDownloadParser: downloading {$url}");

        $body = $this->http->get($url);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new \RuntimeException("JsonDownloadParser: invalid JSON from {$url}");
        }

        // Extract the items array from the payload
        $itemsPath = $responseMap['items_path'] ?? null;
        $currentItems = $itemsPath ? $this->resolvePath($data, $itemsPath) : $data;

        if (!is_array($currentItems)) {
            $currentItems = [];
        }

        // Load previous snapshot
        $previousItems = $this->loadPrevious($slug);

        // Compute new entries by building a set of content hashes from previous
        $previousHashes = [];
        foreach ($previousItems as $item) {
            $hash = $this->itemHash($item);
            $previousHashes[$hash] = true;
        }

        $newItems = [];
        foreach ($currentItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hash = $this->itemHash($item);
            if (!isset($previousHashes[$hash])) {
                $newItems[] = $this->mapItem($item, $responseMap);
            }
        }

        // Save current snapshot for next diff
        $this->saveCurrent($slug, $currentItems);

        Logger::info("JsonDownloadParser: found " . count($newItems) . " new items for {$slug}");

        return $newItems;
    }

    /**
     * Generate a hash for an item to detect duplicates.
     */
    private function itemHash(array $item): string
    {
        return hash('sha256', json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Map a raw item to normalised field names using response_map.
     */
    private function mapItem(array $item, array $responseMap): array
    {
        $fieldMap = $responseMap;
        unset($fieldMap['items_path']);

        if (empty($fieldMap)) {
            // No mapping defined; return common fields by best guess
            return [
                'title'        => $item['vulnerabilityName'] ?? $item['cveID'] ?? $item['title'] ?? $item['name'] ?? '',
                'description'  => $item['shortDescription'] ?? $item['description'] ?? $item['summary'] ?? '',
                'link'         => $item['url'] ?? $item['link'] ?? '',
                'cve_id'       => $item['cveID'] ?? $item['cve_id'] ?? null,
                'published_at' => $item['dateAdded'] ?? $item['published'] ?? $item['pubDate'] ?? null,
            ];
        }

        $mapped = [];
        foreach ($fieldMap as $normalisedField => $sourcePath) {
            $mapped[$normalisedField] = $this->resolveValue($item, (string) $sourcePath);
        }

        return $mapped;
    }

    /**
     * Resolve a dot-notation path against a data structure.
     */
    private function resolvePath(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if ($segment === '*') {
                return is_array($current) ? $current : [];
            }
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * Resolve a single value from dot-notation path.
     */
    private function resolveValue(array $item, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $item;

        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * Load the previous download snapshot from cache.
     */
    private function loadPrevious(string $slug): array
    {
        $path = $this->cachePath($slug);

        if (!file_exists($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save the current download snapshot to cache.
     */
    private function saveCurrent(string $slug, array $items): void
    {
        $dir = self::CACHE_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $this->cachePath($slug);
        file_put_contents($path, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Cache file path for a given source slug.
     */
    private function cachePath(string $slug): string
    {
        return self::CACHE_DIR . '/' . preg_replace('/[^a-z0-9_-]/', '_', strtolower($slug)) . '.json';
    }
}
