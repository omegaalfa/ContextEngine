<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Retrieval\NeighborSearchQuery;

interface NeighborAwareVectorStore extends VectorStore
{
    /** @return list<Chunk> Ordered by document position. */
    public function neighbors(NeighborSearchQuery $query): array;
}
