<?php

declare(strict_types=1);

namespace SecurityDrama\Ingest;

final class Normaliser
{
    /**
     * Ecosystems that indicate a "vibe_coder" audience.
     */
    private const VIBE_CODER_KEYWORDS = [
        'npm', 'pypi', 'next.js', 'nextjs', 'react', 'supabase', 'vercel',
        'node.js', 'nodejs', 'node', 'golang', 'go ', 'rust', 'crates.io',
        'composer', 'php', 'pip', 'yarn', 'pnpm', 'deno', 'bun',
    ];

    /**
     * Keywords that indicate an SMB audience.
     */
    private const SMB_KEYWORDS = [
        'small business', 'phishing', 'ransomware', 'email',
        'smb', 'small-business',
    ];

    /**
     * Map a raw parsed item to the feed_items schema.
     *
     * @param array  $rawItem        Parsed item from any parser.
     * @param int    $sourceId       feed_sources.id
     * @param string $sourceCategory Source category (cve, breach, scam, news, etc.)
     * @return array Row ready for INSERT into feed_items.
     */
    public function normalise(array $rawItem, int $sourceId, string $sourceCategory): array
    {
        $title       = trim((string) ($rawItem['title'] ?? ''));
        $description = trim((string) ($rawItem['description'] ?? ''));
        $link        = trim((string) ($rawItem['link'] ?? ''));
        $publishedAt = $this->parseDate($rawItem['published_at'] ?? $rawItem['pubDate'] ?? null);
        $cveId       = $rawItem['cve_id'] ?? $this->extractCveId($title . ' ' . $description);
        $cvssScore   = isset($rawItem['cvss_score']) ? (float) $rawItem['cvss_score'] : null;
        $severity    = $rawItem['severity'] ?? $this->mapSeverity($cvssScore);

        $affectedProducts = $rawItem['affected_products'] ?? $this->extractAffectedProducts($rawItem);
        if (!is_string($affectedProducts)) {
            $affectedProducts = json_encode(array_values(array_unique((array) $affectedProducts)), JSON_UNESCAPED_SLASHES);
        }

        $audienceTags = $this->deriveAudienceTags($affectedProducts, $sourceCategory, $description);
        $contentHash  = hash('sha256', $title . $description);

        return [
            'source_id'         => $sourceId,
            'title'             => mb_substr($title, 0, 512),
            'description'       => $description,
            'url'               => mb_substr($link, 0, 2048),
            'cve_id'            => $cveId ? mb_substr($cveId, 0, 20) : null,
            'cvss_score'        => $cvssScore,
            'severity'          => $severity,
            'affected_products' => $affectedProducts,
            'audience_tags'     => json_encode($audienceTags, JSON_UNESCAPED_SLASHES),
            'content_hash'      => $contentHash,
            'published_at'      => $publishedAt,
            'ingested_at'       => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Map a CVSS score to a severity label.
     */
    public function mapSeverity(?float $cvss): string
    {
        if ($cvss === null || $cvss === 0.0) {
            return 'unknown';
        }

        return match (true) {
            $cvss >= 9.0  => 'critical',
            $cvss >= 7.0  => 'high',
            $cvss >= 4.0  => 'medium',
            $cvss >= 0.1  => 'low',
            default       => 'unknown',
        };
    }

    /**
     * Extract affected products from CPE names, npm packages, or keywords.
     *
     * @return string[]
     */
    private function extractAffectedProducts(array $rawItem): array
    {
        $products = [];

        // CPE names (NVD format: cpe:2.3:a:vendor:product:version:...)
        if (!empty($rawItem['cpe_names'])) {
            foreach ((array) $rawItem['cpe_names'] as $cpe) {
                $parts = explode(':', $cpe);
                if (count($parts) >= 5) {
                    $vendor  = $parts[3] ?? '';
                    $product = $parts[4] ?? '';
                    if ($product !== '' && $product !== '*') {
                        $products[] = $vendor !== '*' ? "{$vendor}/{$product}" : $product;
                    }
                }
            }
        }

        // npm / package names
        if (!empty($rawItem['package_name'])) {
            $products[] = (string) $rawItem['package_name'];
        }

        // Categories or tags that look like product names
        if (!empty($rawItem['categories'])) {
            foreach ((array) $rawItem['categories'] as $cat) {
                $cat = trim((string) $cat);
                if ($cat !== '') {
                    $products[] = $cat;
                }
            }
        }

        return $products;
    }

    /**
     * Derive audience tags based on products, source category, and description.
     *
     * @return string[]
     */
    private function deriveAudienceTags(string $productsJson, string $sourceCategory, string $description): array
    {
        $tags = [];
        $haystack = strtolower($productsJson . ' ' . $description);

        // Check for vibe-coder ecosystems
        foreach (self::VIBE_CODER_KEYWORDS as $kw) {
            if (str_contains($haystack, $kw)) {
                $tags[] = 'vibe_coder';
                break;
            }
        }

        // Check for SMB relevance
        $smbCategories = ['breach', 'scam'];
        if (in_array($sourceCategory, $smbCategories, true)) {
            $tags[] = 'smb';
        } else {
            foreach (self::SMB_KEYWORDS as $kw) {
                if (str_contains($haystack, $kw)) {
                    $tags[] = 'smb';
                    break;
                }
            }
        }

        if (empty($tags)) {
            $tags[] = 'general';
        }

        return array_unique($tags);
    }

    /**
     * Extract CVE ID from text (CVE-YYYY-NNNNN).
     */
    private function extractCveId(string $text): ?string
    {
        if (preg_match('/CVE-\d{4}-\d{4,}/', $text, $m)) {
            return $m[0];
        }
        return null;
    }

    /**
     * Parse a date string into Y-m-d H:i:s, or return null.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ts = strtotime((string) $value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
