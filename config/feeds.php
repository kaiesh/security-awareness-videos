<?php

declare(strict_types=1);

/**
 * Feed source definitions for seeding into feed_sources table.
 *
 * Each entry: name, slug, category, feed_type, url, polling_interval_minutes.
 * Categories: cve, exploit, breach, news, vendor, scam, community
 * Feed types: rss, json_api, json_download, nvd_api, html_scrape
 */

return [

    // =========================================================================
    // TIER 1 - 360 min (6-hourly) - Primary security data sources
    // =========================================================================

    [
        'name'                     => 'NVD API',
        'slug'                     => 'nvd-api',
        'category'                 => 'cve',
        'feed_type'                => 'nvd_api',
        'url'                      => 'https://services.nvd.nist.gov/rest/json/cves/2.0',
        'polling_interval_minutes' => 360,
    ],
    [
        'name'                     => 'OSV.dev',
        'slug'                     => 'osv-dev',
        'category'                 => 'cve',
        'feed_type'                => 'json_api',
        'url'                      => 'https://api.osv.dev/v1/query',
        'polling_interval_minutes' => 360,
        'response_map'             => json_encode([
            'items_path'  => 'vulns.*',
            'title'       => 'id',
            'description' => 'summary',
            'link'        => 'references.0.url',
            'cve_id'      => 'aliases.0',
        ]),
    ],
    [
        'name'                     => 'CISA KEV',
        'slug'                     => 'cisa-kev',
        'category'                 => 'cve',
        'feed_type'                => 'json_download',
        'url'                      => 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json',
        'polling_interval_minutes' => 360,
        'response_map'             => json_encode([
            'items_path'  => 'vulnerabilities.*',
            'title'       => 'vulnerabilityName',
            'description' => 'shortDescription',
            'cve_id'      => 'cveID',
            'link'        => 'notes',
        ]),
    ],
    [
        'name'                     => 'CVEFeed RSS',
        'slug'                     => 'cvefeed-rss',
        'category'                 => 'cve',
        'feed_type'                => 'rss',
        'url'                      => 'https://cvefeed.io/rssfeed/latest.xml',
        'polling_interval_minutes' => 360,
    ],
    [
        'name'                     => 'Exploit-DB RSS',
        'slug'                     => 'exploit-db',
        'category'                 => 'exploit',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.exploit-db.com/rss.xml',
        'polling_interval_minutes' => 360,
    ],
    [
        'name'                     => 'HIBP Breaches',
        'slug'                     => 'hibp-breaches',
        'category'                 => 'breach',
        'feed_type'                => 'rss',
        'url'                      => 'https://feeds.feedburner.com/HaveIBeenPwnedLatestBreaches',
        'polling_interval_minutes' => 360,
    ],
    [
        'name'                     => 'BleepingComputer',
        'slug'                     => 'bleepingcomputer',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.bleepingcomputer.com/feed/',
        'polling_interval_minutes' => 360,
    ],
    [
        'name'                     => 'The Hacker News',
        'slug'                     => 'the-hacker-news',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://feeds.feedburner.com/TheHackersNews',
        'polling_interval_minutes' => 360,
    ],

    // =========================================================================
    // TIER 2 - 720 min (12-hourly) - Important secondary sources
    // =========================================================================

    [
        'name'                     => 'SANS ISC',
        'slug'                     => 'sans-isc',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://isc.sans.edu/rssfeed.xml',
        'polling_interval_minutes' => 720,
    ],
    [
        'name'                     => 'ZDI Advisories',
        'slug'                     => 'zdi-advisories',
        'category'                 => 'cve',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.zerodayinitiative.com/rss/published/',
        'polling_interval_minutes' => 720,
    ],
    [
        'name'                     => 'Node.js Security',
        'slug'                     => 'nodejs-security',
        'category'                 => 'cve',
        'feed_type'                => 'rss',
        'url'                      => 'https://nodejs.org/en/feed/vulnerability.xml',
        'polling_interval_minutes' => 720,
    ],
    [
        'name'                     => 'Chrome Releases',
        'slug'                     => 'chrome-releases',
        'category'                 => 'vendor',
        'feed_type'                => 'rss',
        'url'                      => 'https://chromereleases.googleblog.com/feeds/posts/default',
        'polling_interval_minutes' => 720,
    ],
    [
        'name'                     => 'AWS Security Bulletins',
        'slug'                     => 'aws-security-bulletins',
        'category'                 => 'vendor',
        'feed_type'                => 'rss',
        'url'                      => 'https://aws.amazon.com/security/security-bulletins/feed/',
        'polling_interval_minutes' => 720,
    ],
    [
        'name'                     => 'MSRC Blog',
        'slug'                     => 'msrc-blog',
        'category'                 => 'vendor',
        'feed_type'                => 'rss',
        'url'                      => 'https://msrc.microsoft.com/blog/feed',
        'polling_interval_minutes' => 720,
    ],
    [
        'name'                     => 'CVE Daily',
        'slug'                     => 'cve-daily',
        'category'                 => 'cve',
        'feed_type'                => 'rss',
        'url'                      => 'https://cve.mitre.org/data/downloads/allitems-cvrf-year-2025.xml',
        'polling_interval_minutes' => 720,
    ],

    // =========================================================================
    // TIER 3 - 1440 min (24-hourly) - News blogs, vendor feeds, community
    // =========================================================================

    // -- Security news blogs --

    [
        'name'                     => 'Krebs on Security',
        'slug'                     => 'krebs-on-security',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://krebsonsecurity.com/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Dark Reading',
        'slug'                     => 'dark-reading',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.darkreading.com/rss.xml',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'SecurityWeek',
        'slug'                     => 'securityweek',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.securityweek.com/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Threatpost',
        'slug'                     => 'threatpost',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://threatpost.com/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'SC Magazine',
        'slug'                     => 'sc-magazine',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.scmagazine.com/feed',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'CSO Online',
        'slug'                     => 'cso-online',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.csoonline.com/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Infosecurity Magazine',
        'slug'                     => 'infosecurity-magazine',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.infosecurity-magazine.com/rss/news/',
        'polling_interval_minutes' => 1440,
    ],

    // -- Vendor feeds --

    [
        'name'                     => 'Mozilla Security Advisories',
        'slug'                     => 'mozilla-security',
        'category'                 => 'vendor',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.mozilla.org/en-US/security/advisories/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Apple Security Updates',
        'slug'                     => 'apple-security',
        'category'                 => 'vendor',
        'feed_type'                => 'rss',
        'url'                      => 'https://support.apple.com/en-us/HT201222/rss',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Microsoft Security Response Center',
        'slug'                     => 'microsoft-msrc',
        'category'                 => 'vendor',
        'feed_type'                => 'rss',
        'url'                      => 'https://api.msrc.microsoft.com/update-guide/rss',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Google Project Zero',
        'slug'                     => 'google-project-zero',
        'category'                 => 'vendor',
        'feed_type'                => 'rss',
        'url'                      => 'https://googleprojectzero.blogspot.com/feeds/posts/default',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'GitHub Security Advisories',
        'slug'                     => 'github-security',
        'category'                 => 'vendor',
        'feed_type'                => 'json_api',
        'url'                      => 'https://api.github.com/advisories?per_page=50',
        'polling_interval_minutes' => 1440,
        'response_map'             => json_encode([
            'items_path'  => '*',
            'title'       => 'summary',
            'description' => 'description',
            'link'        => 'html_url',
            'cve_id'      => 'cve_id',
        ]),
    ],

    // -- Community / Reddit --

    [
        'name'                     => 'r/netsec',
        'slug'                     => 'reddit-netsec',
        'category'                 => 'community',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.reddit.com/r/netsec/.rss',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'r/cybersecurity',
        'slug'                     => 'reddit-cybersecurity',
        'category'                 => 'community',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.reddit.com/r/cybersecurity/.rss',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'r/vibecoding',
        'slug'                     => 'reddit-vibecoding',
        'category'                 => 'community',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.reddit.com/r/vibecoding/.rss',
        'polling_interval_minutes' => 1440,
    ],

    // -- Scam / Consumer --

    [
        'name'                     => 'FTC Consumer Alerts',
        'slug'                     => 'ftc-consumer-alerts',
        'category'                 => 'scam',
        'feed_type'                => 'rss',
        'url'                      => 'https://consumer.ftc.gov/rss/consumer-alerts.xml',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'AARP Fraud Watch',
        'slug'                     => 'aarp-fraud-watch',
        'category'                 => 'scam',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.aarp.org/money/scams-fraud/rss.xml',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Snopes Fact Check',
        'slug'                     => 'snopes',
        'category'                 => 'scam',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.snopes.com/feed/',
        'polling_interval_minutes' => 1440,
    ],

    // -- Breach / Data leak focused --

    [
        'name'                     => 'DataBreaches.net',
        'slug'                     => 'databreaches-net',
        'category'                 => 'breach',
        'feed_type'                => 'rss',
        'url'                      => 'https://databreaches.net/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Graham Cluley',
        'slug'                     => 'graham-cluley',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://grahamcluley.com/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Naked Security (Sophos)',
        'slug'                     => 'naked-security',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://nakedsecurity.sophos.com/feed/',
        'polling_interval_minutes' => 1440,
    ],

    // -- Phishing / Malware URL feeds --

    [
        'name'                     => 'PhishTank',
        'slug'                     => 'phishtank',
        'category'                 => 'scam',
        'feed_type'                => 'json_api',
        'url'                      => 'https://data.phishtank.com/data/online-valid.json',
        'polling_interval_minutes' => 1440,
        'response_map'             => json_encode([
            'items_path'  => '*',
            'title'       => 'target',
            'description' => 'url',
            'link'        => 'phish_detail_url',
        ]),
    ],
    [
        'name'                     => 'URLhaus Recent',
        'slug'                     => 'urlhaus-recent',
        'category'                 => 'scam',
        'feed_type'                => 'rss',
        'url'                      => 'https://urlhaus.abuse.ch/feeds/rss/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'OpenPhish',
        'slug'                     => 'openphish',
        'category'                 => 'scam',
        'feed_type'                => 'rss',
        'url'                      => 'https://openphish.com/feed.txt',
        'polling_interval_minutes' => 1440,
    ],

    // -- Additional Tier 3 sources to reach ~53 total --

    [
        'name'                     => 'US-CERT Alerts',
        'slug'                     => 'us-cert-alerts',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.cisa.gov/uscert/ncas/alerts.xml',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'US-CERT Current Activity',
        'slug'                     => 'us-cert-current-activity',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.cisa.gov/uscert/ncas/current-activity.xml',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Schneier on Security',
        'slug'                     => 'schneier-on-security',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.schneier.com/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Talos Intelligence Blog',
        'slug'                     => 'talos-intelligence',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://blog.talosintelligence.com/rss/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Rapid7 Blog',
        'slug'                     => 'rapid7-blog',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://blog.rapid7.com/rss/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Qualys Threat Protection Blog',
        'slug'                     => 'qualys-blog',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://blog.qualys.com/feed',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Packet Storm Security',
        'slug'                     => 'packet-storm',
        'category'                 => 'exploit',
        'feed_type'                => 'rss',
        'url'                      => 'https://rss.packetstormsecurity.com/files/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Full Disclosure Mailing List',
        'slug'                     => 'full-disclosure',
        'category'                 => 'exploit',
        'feed_type'                => 'rss',
        'url'                      => 'https://seclists.org/rss/fulldisclosure.rss',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'NIST NVD Recent',
        'slug'                     => 'nist-nvd-recent',
        'category'                 => 'cve',
        'feed_type'                => 'rss',
        'url'                      => 'https://nvd.nist.gov/feeds/xml/cve/misc/nvd-rss.xml',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Troy Hunt Blog',
        'slug'                     => 'troy-hunt',
        'category'                 => 'breach',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.troyhunt.com/rss/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Wordfence Blog',
        'slug'                     => 'wordfence',
        'category'                 => 'cve',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.wordfence.com/blog/feed/',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'WPScan Vulnerability Database',
        'slug'                     => 'wpscan',
        'category'                 => 'cve',
        'feed_type'                => 'rss',
        'url'                      => 'https://wpscan.com/wordpresses.rss',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Malwarebytes Labs',
        'slug'                     => 'malwarebytes-labs',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://www.malwarebytes.com/blog/feed',
        'polling_interval_minutes' => 1440,
    ],
    [
        'name'                     => 'Sucuri Blog',
        'slug'                     => 'sucuri-blog',
        'category'                 => 'news',
        'feed_type'                => 'rss',
        'url'                      => 'https://blog.sucuri.net/feed/',
        'polling_interval_minutes' => 1440,
    ],
];
