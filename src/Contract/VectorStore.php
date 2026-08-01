<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;

interface VectorStore
{
    /**
     * Atomically persists the complete batch. An empty batch is a no-op.
     *
     * If persistence fails, no item from this batch may remain persisted.
     *
     * @param list<EmbeddedChunk> $chunks
     */
    public function storeBatch(array $chunks): void;

    /** @return list<VectorSearchResult> */ public function search(VectorSearchQuery $query): array;
}
