<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;

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

    /**
     * @param VectorSearchQuery $query
     * @return list<VectorSearchResult>
     */
    public function search(VectorSearchQuery $query): array;

    /**
     * Removes one chunk from one vector space.
     *
     * @param ChunkDeleteQuery $query
     * @return int
     */
    public function deleteChunk(ChunkDeleteQuery $query): int;

    /**
     * Removes a document from one vector space, or from all spaces when none is specified.
     *
     * @param DocumentDeleteQuery $query
     * @return int
     */
    public function deleteDocument(DocumentDeleteQuery $query): int;

    /**
     * Removes every vector in one tenant collection.
     *
     * @param CollectionDeleteQuery $query
     * @return int
     */
    public function clearCollection(CollectionDeleteQuery $query): int;
}
