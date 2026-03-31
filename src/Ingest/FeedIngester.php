<?php

declare(strict_types=1);

namespace SecurityDrama\Ingest;

use SecurityDrama\Database;
use SecurityDrama\HttpClient;
use SecurityDrama\Logger;
use SecurityDrama\Ingest\Normaliser;
use SecurityDrama\Ingest\Parsers\RssParser;
use SecurityDrama\Ingest\Parsers\JsonApiParser;
use SecurityDrama\Ingest\Parsers\JsonDownloadParser;
use SecurityDrama\Ingest\Parsers\NvdApiParser;

final class FeedIngester
{
    private Database $db;
    private HttpClient $http;
    private Normaliser $normaliser;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->http = new HttpClient();
        $this->normaliser = new Normaliser();
    }

    /**
     * Main ingestion loop: poll all due sources, parse, normalise, store.
     *
     * @return array{sources_processed: int, items_inserted: int, errors: int}
     */
    public function run(): array
    {
        $stats = ['sources_processed' => 0, 'items_inserted' => 0, 'errors' => 0];

        $sources = $this->getDueSources();
        Logger::info("FeedIngester: " . count($sources) . " sources due for polling");

        foreach ($sources as $source) {
            try {
                $inserted = $this->processSource($source);
                $stats['sources_processed']++;
                $stats['items_inserted'] += $inserted;

                $this->updateSourceSuccess($source['id'], $inserted);

                Logger::info("FeedIngester: {$source['slug']} - inserted {$inserted} items");
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->updateSourceError($source['id'], $e->getMessage());
                Logger::error("FeedIngester: {$source['slug']} - {$e->getMessage()}");
            }

            // Free memory between sources
            unset($inserted);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        Logger::info("FeedIngester: complete - " . json_encode($stats));

        return $stats;
    }

    /**
     * Get all active feed_sources that are due for polling.
     *
     * @return array<int, array>
     */
    private function getDueSources(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM feed_sources
             WHERE is_active = 1
               AND (
                   last_polled_at IS NULL
                   OR last_polled_at < DATE_SUB(NOW(), INTERVAL polling_interval_minutes MINUTE)
               )
             ORDER BY last_polled_at ASC"
        );
    }

    /**
     * Process a single source: parse, normalise, insert.
     *
     * @return int Number of items inserted.
     */
    private function processSource(array $source): int
    {
        $parser = $this->getParser($source['feed_type']);
        $rawItems = $parser->parse($source);

        if (empty($rawItems)) {
            return 0;
        }

        $inserted = 0;
        $sourceId = (int) $source['id'];
        $category = $source['category'] ?? 'news';

        foreach ($rawItems as $rawItem) {
            $normalised = $this->normaliser->normalise($rawItem, $sourceId, $category);

            // Skip items with empty titles
            if ($normalised['title'] === '') {
                continue;
            }

            try {
                $this->db->execute(
                    "INSERT IGNORE INTO feed_items
                        (source_id, title, description, url, cve_id, cvss_score, severity,
                         affected_products, audience_tags, content_hash, published_at, ingested_at)
                     VALUES
                        (:source_id, :title, :description, :url, :cve_id, :cvss_score, :severity,
                         :affected_products, :audience_tags, :content_hash, :published_at, :ingested_at)",
                    [
                        'source_id'         => $normalised['source_id'],
                        'title'             => $normalised['title'],
                        'description'       => $normalised['description'],
                        'url'               => $normalised['url'],
                        'cve_id'            => $normalised['cve_id'],
                        'cvss_score'        => $normalised['cvss_score'],
                        'severity'          => $normalised['severity'],
                        'affected_products' => $normalised['affected_products'],
                        'audience_tags'     => $normalised['audience_tags'],
                        'content_hash'      => $normalised['content_hash'],
                        'published_at'      => $normalised['published_at'],
                        'ingested_at'       => $normalised['ingested_at'],
                    ]
                );

                // PDO rowCount() returns 0 for INSERT IGNORE that was ignored
                $inserted++;
            } catch (\PDOException $e) {
                // Duplicate content_hash - skip silently
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    continue;
                }
                throw $e;
            }
        }

        // Free the raw items from memory
        unset($rawItems);

        return $inserted;
    }

    /**
     * Get the appropriate parser for a feed type.
     */
    private function getParser(string $feedType): RssParser|JsonApiParser|JsonDownloadParser|NvdApiParser
    {
        return match ($feedType) {
            'rss'           => new RssParser($this->http),
            'json_api'      => new JsonApiParser($this->http),
            'json_download' => new JsonDownloadParser($this->http),
            'nvd_api'       => new NvdApiParser($this->http),
            default         => throw new \InvalidArgumentException("Unknown feed type: {$feedType}"),
        };
    }

    /**
     * Update source after successful poll.
     */
    private function updateSourceSuccess(int $sourceId, int $itemsInserted): void
    {
        $this->db->execute(
            "UPDATE feed_sources
             SET last_polled_at = NOW(),
                 items_fetched_total = items_fetched_total + :items,
                 last_error = NULL
             WHERE id = :id",
            ['items' => $itemsInserted, 'id' => $sourceId]
        );
    }

    /**
     * Update source after a failed poll.
     */
    private function updateSourceError(int $sourceId, string $error): void
    {
        $this->db->execute(
            "UPDATE feed_sources
             SET last_polled_at = NOW(),
                 last_error = :error
             WHERE id = :id",
            ['error' => mb_substr($error, 0, 1000), 'id' => $sourceId]
        );
    }
}
