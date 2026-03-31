<?php

declare(strict_types=1);

namespace SecurityDrama\Ingest\Parsers;

use SecurityDrama\HttpClient;
use SecurityDrama\Logger;

final class RssParser
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Fetch and parse an RSS 2.0 or Atom feed.
     *
     * @param array $source Feed source row from the database.
     * @return array<int, array> List of raw items.
     */
    public function parse(array $source): array
    {
        $url = $source['url'];
        Logger::info("RssParser: fetching {$url}");

        $xml = $this->http->get($url);

        if ($xml === '' || $xml === false) {
            throw new \RuntimeException("RssParser: empty response from {$url}");
        }

        // Suppress warnings from malformed XML, handle errors manually.
        $previousUseErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if ($doc === false) {
            throw new \RuntimeException("RssParser: failed to parse XML from {$url}");
        }

        // Detect format and delegate
        $rootName = $doc->getName();

        if ($rootName === 'feed') {
            return $this->parseAtom($doc);
        }

        // RSS 2.0 (root is <rss> with <channel>)
        return $this->parseRss2($doc);
    }

    /**
     * Parse RSS 2.0 format.
     *
     * @return array<int, array>
     */
    private function parseRss2(\SimpleXMLElement $xml): array
    {
        $items = [];

        $channel = $xml->channel ?? $xml;

        foreach ($channel->item as $entry) {
            $categories = [];
            foreach ($entry->category as $cat) {
                $val = trim((string) $cat);
                if ($val !== '') {
                    $categories[] = $val;
                }
            }

            $description = (string) ($entry->description ?? '');

            // Some feeds put full content in content:encoded
            $namespaces = $entry->getNamespaces(true);
            if (isset($namespaces['content'])) {
                $content = $entry->children($namespaces['content']);
                if (isset($content->encoded) && (string) $content->encoded !== '') {
                    $description = (string) $content->encoded;
                }
            }

            $items[] = [
                'title'        => trim((string) ($entry->title ?? '')),
                'description'  => strip_tags($description),
                'link'         => trim((string) ($entry->link ?? '')),
                'published_at' => (string) ($entry->pubDate ?? ''),
                'categories'   => $categories,
            ];
        }

        return $items;
    }

    /**
     * Parse Atom format.
     *
     * @return array<int, array>
     */
    private function parseAtom(\SimpleXMLElement $xml): array
    {
        $items = [];

        // Register Atom namespace for xpath if needed
        $ns = $xml->getNamespaces(true);
        $atomNs = $ns[''] ?? 'http://www.w3.org/2005/Atom';

        foreach ($xml->entry as $entry) {
            $categories = [];
            foreach ($entry->category as $cat) {
                $term = (string) ($cat['term'] ?? '');
                if ($term !== '') {
                    $categories[] = $term;
                }
            }

            // Atom links: pick href from rel="alternate" or first link
            $link = '';
            foreach ($entry->link as $l) {
                $rel = (string) ($l['rel'] ?? 'alternate');
                if ($rel === 'alternate' || $link === '') {
                    $link = (string) ($l['href'] ?? '');
                }
            }

            $description = (string) ($entry->summary ?? '');
            if ($description === '' && isset($entry->content)) {
                $description = (string) $entry->content;
            }

            $publishedAt = (string) ($entry->published ?? $entry->updated ?? '');

            $items[] = [
                'title'        => trim((string) ($entry->title ?? '')),
                'description'  => strip_tags($description),
                'link'         => trim($link),
                'published_at' => $publishedAt,
                'categories'   => $categories,
            ];
        }

        return $items;
    }
}
