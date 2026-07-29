<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Ingestion\BatchEmbeddingResult;

interface BatchEmbeddingExecutor
{
    /**
     * @param iterable<non-empty-list<Chunk>> $batches
     * @return iterable<BatchEmbeddingResult>
     */
    public function execute(iterable $batches, EmbeddingProvider $provider): iterable;
}
