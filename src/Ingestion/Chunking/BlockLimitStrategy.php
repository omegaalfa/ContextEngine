<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

use InvalidArgumentException;

final readonly class BlockLimitStrategy implements ChunkingStrategy
{
    public function __construct(public int $limit = 1)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Block limit must be greater than zero.');
        }
    }

    public function fingerprint(): string
    {
        return 'blocks:' . $this->limit;
    }

    public function fits(string $content, int $blockCount): bool
    {
        return $blockCount <= $this->limit;
    }

    public function split(string $content): array
    {
        return [trim($content)];
    }
}
