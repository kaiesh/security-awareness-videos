<?php

declare(strict_types=1);

namespace SecurityDrama\Publish\Adapters;

use RuntimeException;
use SecurityDrama\Publish\PublishAdapterInterface;

final class DirectRedditAdapter implements PublishAdapterInterface
{
    public function getAdapterName(): string
    {
        return 'direct_reddit';
    }

    public function supportsPlatform(string $platform): bool
    {
        return $platform === 'reddit';
    }

    public function publish(string $platform, array $videoData, array $contentData, array $platformConfig): array
    {
        throw new RuntimeException('Direct adapter not yet implemented for reddit');
    }
}
