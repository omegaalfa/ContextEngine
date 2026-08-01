<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\VectorStore;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

final readonly class ChunkDeleteQuery
{
    public function __construct(
        public string $tenantId,
        public string $collection,
        public string $chunkId,
        public EmbeddingSpace $space,
    ) {
        if (trim($tenantId) === '' || trim($collection) === '' || trim($chunkId) === '') {
            throw new InvalidArgumentException('Chunk deletion scope values cannot be empty.');
        }
    }
}
