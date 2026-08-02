<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\VectorStore;

use InvalidArgumentException;

final readonly class PgVectorSchema
{
    /**
     * @param string $table
     * @param string $chunkId
     * @param string $documentId
     * @param string $tenantId
     * @param string $collection
     * @param string $status
     * @param string $content
     * @param string $position
     * @param string $metadata
     * @param string $embedding
     * @param string $embeddingProvider
     * @param string $embeddingModel
     * @param string $embeddingDimensions
     * @param string $embeddingRevision
     * @param string $embeddingFingerprint
     */
    public function __construct(public string $table = 'context_chunks', public string $chunkId = 'chunk_id', public string $documentId = 'document_id', public string $documentVersion = 'document_version', public string $ingestionState = 'ingestion_state', public string $tenantId = 'tenant_id', public string $collection = 'collection', public string $status = 'status', public string $content = 'content', public string $position = 'position', public string $metadata = 'metadata', public string $embedding = 'embedding', public string $embeddingProvider = 'embedding_provider', public string $embeddingModel = 'embedding_model', public string $embeddingDimensions = 'embedding_dimensions', public string $embeddingRevision = 'embedding_revision', public string $embeddingFingerprint = 'embedding_space_fingerprint')
    {
        foreach ((array)$this as $identifier) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
                throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
            }
        }
    }
}
