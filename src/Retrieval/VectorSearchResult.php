<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use Omegaalfa\ContextEngine\Chunk\Chunk;

final readonly class VectorSearchResult
{
    public function __construct(public Chunk $chunk, public float $distance)
    {
        if (!is_finite($distance) || $distance < 0) {
            throw new \InvalidArgumentException('Vector distance must be finite and non-negative.');
        }
    }
}
