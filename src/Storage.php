<?php

declare(strict_types=1);

namespace SecurityDrama;

use Aws\S3\S3Client;
use RuntimeException;

final class Storage
{
    private static ?self $instance = null;
    private S3Client $s3;
    private string $bucket;
    private string $cdnUrl;

    private function __construct()
    {
        $this->bucket = $_ENV['DO_SPACES_BUCKET'] ?? 'securitydrama-media';
        $this->cdnUrl = rtrim($_ENV['DO_SPACES_CDN_URL'] ?? '', '/');

        $this->s3 = new S3Client([
            'version'     => 'latest',
            'region'      => $_ENV['DO_SPACES_REGION'] ?? 'sgp1',
            'endpoint'    => $_ENV['DO_SPACES_ENDPOINT'] ?? 'https://sgp1.digitaloceanspaces.com',
            'credentials' => [
                'key'    => $_ENV['DO_SPACES_KEY'] ?? '',
                'secret' => $_ENV['DO_SPACES_SECRET'] ?? '',
            ],
        ]);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Upload a local file to DO Spaces. Returns the CDN URL.
     */
    public function upload(string $localPath, string $remotePath, string $contentType = 'video/mp4'): string
    {
        if (!file_exists($localPath)) {
            throw new RuntimeException("Local file not found: {$localPath}");
        }

        $remotePath = ltrim($remotePath, '/');

        $this->s3->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $remotePath,
            'SourceFile'  => $localPath,
            'ContentType' => $contentType,
            'ACL'         => 'public-read',
        ]);

        return $this->getUrl($remotePath);
    }

    /**
     * Download a file from DO Spaces to a local path. Streams to disk.
     */
    public function download(string $remotePath, string $localPath): bool
    {
        $remotePath = ltrim($remotePath, '/');

        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key'    => $remotePath,
            'SaveAs' => $localPath,
        ]);

        return file_exists($localPath);
    }

    public function delete(string $remotePath): bool
    {
        $remotePath = ltrim($remotePath, '/');

        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $remotePath,
        ]);

        return true;
    }

    /**
     * Build the CDN URL for a remote path.
     */
    public function getUrl(string $remotePath): string
    {
        $remotePath = ltrim($remotePath, '/');
        return "{$this->cdnUrl}/{$remotePath}";
    }
}
