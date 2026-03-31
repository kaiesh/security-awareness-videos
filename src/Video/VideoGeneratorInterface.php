<?php

declare(strict_types=1);

namespace SecurityDrama\Video;

interface VideoGeneratorInterface
{
    public function submitJob(array $scriptData, array $options): string;
    public function checkStatus(string $jobId): array;
    public function downloadVideo(string $videoUrl, string $localPath): bool;
    public function getProviderName(): string;
}
