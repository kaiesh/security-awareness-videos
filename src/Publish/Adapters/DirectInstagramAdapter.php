<?php

declare(strict_types=1);

namespace SecurityDrama\Publish\Adapters;

use RuntimeException;
use SecurityDrama\Publish\PublishAdapterInterface;

final class DirectInstagramAdapter implements PublishAdapterInterface
{
    public function getAdapterName(): string
    {
        return 'direct_instagram';
    }

    public function supportsPlatform(string $platform): bool
    {
        return $platform === 'instagram';
    }

    public function publish(string $platform, array $videoData, array $contentData, array $platformConfig): array
    {
        throw new RuntimeException('Direct adapter not yet implemented for instagram');
    }
}
