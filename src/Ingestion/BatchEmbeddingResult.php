<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;

final readonly class BatchEmbeddingResult
{
    /**
     * @param non-empty-list<Chunk> $chunks
     * @param non-empty-list<Embedding> $embeddings
     */
    public function __construct(
        public int $sequence,
        public array $chunks,
        public array $embeddings,
        public BatchExecutionProgress $progress,
    ) {
        if ($sequence < 0 || count($chunks) !== count($embeddings)) {
            throw new \InvalidArgumentException('Batch result must preserve sequence and cardinality.');
        }
        foreach ($embeddings as $embedding) {
            if ($embedding->space->fingerprint() !== $embeddings[0]->space->fingerprint()) {
                throw new \InvalidArgumentException('Batch result cannot mix vector spaces.');
            }
        }
    }
}
