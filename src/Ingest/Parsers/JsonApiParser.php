<?php

declare(strict_types=1);

namespace SecurityDrama\Ingest\Parsers;

use SecurityDrama\HttpClient;
use SecurityDrama\Logger;

final class JsonApiParser
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Fetch JSON from an API and map fields using a response_map config.
     *
     * The response_map uses dot-notation with wildcard (*) to describe
     * where items live and how to map their fields. Example:
     *   {
     *     "items_path": "data.items.*",
     *     "title": "name",
     *     "description": "summary",
     *     "link": "url"
     *   }
     *
     * "items_path" defines where the array of items is (the * marks the array).
     * Other keys map normalised field names to sub-paths within each item.
     *
     * @param array $source Feed source row including 'url' and 'response_map'.
     * @return array<int, array> List of raw items.
     */
    public function parse(array $source): array
    {
        $url = $source['url'];
        $responseMap = $source['response_map'] ?? [];

        if (is_string($responseMap)) {
            $responseMap = json_decode($responseMap, true) ?? [];
        }

        Logger::info('ingest', "JsonApiParser: fetching {$url}");

        $body = $this->http->get($url);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new \RuntimeException("JsonApiParser: invalid JSON from {$url}");
        }

        return $this->extractItems($data, $responseMap);
    }

    /**
     * Extract items from the JSON payload using the response map.
     *
     * @return array<int, array>
     */
    private function extractItems(array $data, array $responseMap): array
    {
        $itemsPath = $responseMap['items_path'] ?? '*';
        unset($responseMap['items_path']);

        // Resolve the items array from the dot-path
        $rawItems = $this->resolvePath($data, $itemsPath);

        if (!is_array($rawItems)) {
            return [];
        }

        // If resolvePath returned a single item (no wildcard), wrap it
        if ($rawItems !== [] && !array_is_list($rawItems)) {
            $rawItems = [$rawItems];
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $mapped = [];
            foreach ($responseMap as $normalisedField => $sourcePath) {
                $mapped[$normalisedField] = $this->resolveValue($rawItem, (string) $sourcePath);
            }

            $items[] = $mapped;
        }

        return $items;
    }

    /**
     * Resolve a dot-notation path with wildcard support against a data structure.
     *
     * "data.items.*" on {"data":{"items":[{...},{...}]}} returns the array of items.
     * "data.count" returns the scalar value.
     */
    private function resolvePath(mixed $data, string $path): mixed
    {
        if ($path === '*') {
            return is_array($data) ? $data : [$data];
        }

        $segments = explode('.', $path);

        $current = $data;
        foreach ($segments as $segment) {
            if ($segment === '*') {
                // Return the current array (the wildcard marks "iterate here")
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
     * Resolve a value from a single item using dot-notation (no wildcards).
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
}
