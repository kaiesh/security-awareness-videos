<?php

declare(strict_types=1);

namespace SecurityDrama;

use RuntimeException;

final class HttpClient
{
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 30;
    private const MAX_REDIRECTS = 3;
    private const USER_AGENT = 'SecurityDrama/1.0';

    // RFC 1918 + link-local + loopback ranges
    private const BLOCKED_IPV4_RANGES = [
        ['127.0.0.0',   '127.255.255.255'],   // 127.0.0.0/8
        ['10.0.0.0',    '10.255.255.255'],     // 10.0.0.0/8
        ['172.16.0.0',  '172.31.255.255'],     // 172.16.0.0/12
        ['192.168.0.0', '192.168.255.255'],     // 192.168.0.0/16
        ['169.254.0.0', '169.254.255.255'],     // 169.254.0.0/16
    ];

    public function get(string $url, array $headers = [], array $options = []): array
    {
        return $this->request('GET', $url, null, $headers, $options);
    }

    public function post(string $url, mixed $body = null, array $headers = [], array $options = []): array
    {
        return $this->request('POST', $url, $body, $headers, $options);
    }

    public function put(string $url, mixed $body = null, array $headers = [], array $options = []): array
    {
        return $this->request('PUT', $url, $body, $headers, $options);
    }

    public function delete(string $url, array $headers = [], array $options = []): array
    {
        return $this->request('DELETE', $url, null, $headers, $options);
    }

    /**
     * Stream a remote file directly to disk.
     */
    public function downloadToFile(string $url, string $localPath, array $headers = []): bool
    {
        $this->validateUrl($url);

        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open file for writing: {$localPath}");
        }

        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_FILE           => $fp,
                CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => 300, // longer timeout for downloads
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
                CURLOPT_FAILONERROR    => true,
            ]);

            $result = curl_exec($ch);
            if ($result === false) {
                $error = curl_error($ch);
                throw new RuntimeException("Download failed: {$error}");
            }

            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $statusCode >= 200 && $statusCode < 300;
        } finally {
            curl_close($ch);
            fclose($fp);
        }
    }

    private function request(string $method, string $url, mixed $body, array $headers, array $options): array
    {
        $this->validateUrl($url);

        $ch = curl_init();
        $responseHeaders = [];

        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ];

        switch ($method) {
            case 'POST':
                $curlOpts[CURLOPT_POST] = true;
                break;
            case 'PUT':
                $curlOpts[CURLOPT_CUSTOMREQUEST] = 'PUT';
                break;
            case 'DELETE':
                $curlOpts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
        }

        if ($body !== null) {
            if (is_array($body)) {
                $body = json_encode($body);
                $headers['Content-Type'] ??= 'application/json';
            }
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
        }

        $curlOpts[CURLOPT_HTTPHEADER] = $this->formatHeaders($headers);

        curl_setopt_array($ch, $curlOpts);

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status'  => $statusCode,
            'body'    => $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * SSRF protection: reject private/internal IPs and non-HTTP schemes.
     */
    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new RuntimeException('Invalid URL');
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Blocked scheme: {$scheme}");
        }

        $host = $parsed['host'];

        // Resolve hostname to IP(s) before checking
        $ips = gethostbynamel($host);
        if ($ips === false) {
            // Try IPv6
            $records = dns_get_record($host, DNS_AAAA);
            $ips = array_column($records ?: [], 'ipv6');
            if (empty($ips)) {
                throw new RuntimeException("Cannot resolve hostname: {$host}");
            }
        }

        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }
    }

    private function assertPublicIp(string $ip): void
    {
        // IPv6 checks
        if (str_contains($ip, ':')) {
            $lower = strtolower($ip);
            if ($lower === '::1') {
                throw new RuntimeException('SSRF blocked: loopback address');
            }
            // fc00::/7 — unique local addresses
            if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
                throw new RuntimeException('SSRF blocked: private IPv6 address');
            }
            return;
        }

        // IPv4 checks
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            throw new RuntimeException("Invalid IP address: {$ip}");
        }

        foreach (self::BLOCKED_IPV4_RANGES as [$start, $end]) {
            if ($ipLong >= ip2long($start) && $ipLong <= ip2long($end)) {
                throw new RuntimeException('SSRF blocked: private/internal IP address');
            }
        }
    }

    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                $formatted[] = $value;
            } else {
                $formatted[] = "{$key}: {$value}";
            }
        }
        return $formatted;
    }
}
