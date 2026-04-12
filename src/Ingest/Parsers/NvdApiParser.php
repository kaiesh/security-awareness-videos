<?php

declare(strict_types=1);

namespace SecurityDrama\Ingest\Parsers;

use SecurityDrama\HttpClient;
use SecurityDrama\Logger;

final class NvdApiParser
{
    private const BASE_URL = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

    /**
     * Rate limit: 5 requests per 30 seconds = sleep 6s between requests.
     */
    private const RATE_LIMIT_SLEEP = 6;

    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Query NVD CVE API for HIGH and CRITICAL CVEs since last poll.
     *
     * @param array $source Feed source row with 'last_polled_at'.
     * @return array<int, array> List of raw parsed items.
     */
    public function parse(array $source): array
    {
        $lastPolled = $source['last_polled_at'] ?? null;
        $startDate  = $lastPolled
            ? (new \DateTimeImmutable($lastPolled))->format('Y-m-d\TH:i:s.000')
            : (new \DateTimeImmutable('-24 hours'))->format('Y-m-d\TH:i:s.000');
        $endDate = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.000');

        Logger::info('ingest', "NvdApiParser: querying {$startDate} to {$endDate}");

        $items = [];

        // Query HIGH severity
        $highItems = $this->fetchSeverity($startDate, $endDate, 'HIGH');
        $items = array_merge($items, $highItems);

        // Rate limit pause
        sleep(self::RATE_LIMIT_SLEEP);

        // Query CRITICAL severity
        $criticalItems = $this->fetchSeverity($startDate, $endDate, 'CRITICAL');
        $items = array_merge($items, $criticalItems);

        Logger::info('ingest', "NvdApiParser: fetched " . count($items) . " CVEs");

        return $items;
    }

    /**
     * Fetch a single severity level from the NVD API with pagination.
     *
     * @return array<int, array>
     */
    private function fetchSeverity(string $startDate, string $endDate, string $severity): array
    {
        $startIndex = 0;
        $allItems = [];

        do {
            $url = self::BASE_URL . '?' . http_build_query([
                'pubStartDate'  => $startDate,
                'pubEndDate'    => $endDate,
                'cvssV3Severity' => $severity,
                'startIndex'    => $startIndex,
                'resultsPerPage' => 100,
            ]);

            Logger::info('ingest', "NvdApiParser: GET {$url}");

            $body = $this->http->get($url);
            $data = json_decode($body, true);

            if (!is_array($data)) {
                Logger::error('ingest', "NvdApiParser: invalid JSON response");
                break;
            }

            $vulnerabilities = $data['vulnerabilities'] ?? [];
            $totalResults    = $data['totalResults'] ?? 0;

            foreach ($vulnerabilities as $vuln) {
                $cve = $vuln['cve'] ?? [];
                $item = $this->mapCve($cve);
                if ($item !== null) {
                    $allItems[] = $item;
                }
            }

            $startIndex += count($vulnerabilities);

            // If more pages remain, respect rate limit
            if ($startIndex < $totalResults) {
                sleep(self::RATE_LIMIT_SLEEP);
            }

        } while ($startIndex < $totalResults && count($vulnerabilities) > 0);

        return $allItems;
    }

    /**
     * Map a single NVD CVE object to a raw item array.
     */
    private function mapCve(array $cve): ?array
    {
        $cveId = $cve['id'] ?? null;
        if ($cveId === null) {
            return null;
        }

        // Description: pick English
        $description = '';
        foreach ($cve['descriptions'] ?? [] as $desc) {
            if (($desc['lang'] ?? '') === 'en') {
                $description = $desc['value'] ?? '';
                break;
            }
        }

        // CVSS v3.1 score and severity
        $cvssScore = null;
        $severity  = null;
        $metrics   = $cve['metrics'] ?? [];

        // Try cvssMetricV31, then cvssMetricV30
        foreach (['cvssMetricV31', 'cvssMetricV30'] as $metricKey) {
            if (!empty($metrics[$metricKey])) {
                $primary = $metrics[$metricKey][0] ?? [];
                $cvssData = $primary['cvssData'] ?? [];
                $cvssScore = $cvssData['baseScore'] ?? null;
                $severity  = strtolower($cvssData['baseSeverity'] ?? '');
                break;
            }
        }

        // Affected CPE names
        $cpeNames = [];
        foreach ($cve['configurations'] ?? [] as $config) {
            foreach ($config['nodes'] ?? [] as $node) {
                foreach ($node['cpeMatch'] ?? [] as $match) {
                    $criteria = $match['criteria'] ?? '';
                    if ($criteria !== '') {
                        $cpeNames[] = $criteria;
                    }
                }
            }
        }

        // References
        $references = [];
        foreach ($cve['references'] ?? [] as $ref) {
            $refUrl = $ref['url'] ?? '';
            if ($refUrl !== '') {
                $references[] = $refUrl;
            }
        }

        $link = $references[0] ?? "https://nvd.nist.gov/vuln/detail/{$cveId}";

        $publishedAt = $cve['published'] ?? null;

        return [
            'title'             => $cveId . ($description ? ': ' . mb_substr($description, 0, 120) : ''),
            'description'       => $description,
            'link'              => $link,
            'cve_id'            => $cveId,
            'cvss_score'        => $cvssScore !== null ? (float) $cvssScore : null,
            'severity'          => $severity ?: null,
            'cpe_names'         => $cpeNames,
            'affected_products' => $cpeNames,
            'published_at'      => $publishedAt,
            'references'        => $references,
        ];
    }
}
