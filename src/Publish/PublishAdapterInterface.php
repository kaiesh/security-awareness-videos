<?php

declare(strict_types=1);

namespace SecurityDrama\Publish;

interface PublishAdapterInterface
{
    public function publish(string $platform, array $videoData, array $contentData, array $platformConfig): array;

    public function getAdapterName(): string;

    public function supportsPlatform(string $platform): bool;
}
