<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Bootstrap;

use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;

final readonly class ContextEngineContext
{
    /**
     * @param Retriever $retriever
     * @param IngestionPipeline $ingestion
     * @param RagPipeline $rag
     * @param EmbeddingProvider $embeddings
     * @param VectorStore $store
     */
    public function __construct(
        public Retriever         $retriever,
        public IngestionPipeline $ingestion,
        public RagPipeline       $rag,
        public EmbeddingProvider $embeddings,
        public VectorStore       $store,
    ) {}
}
