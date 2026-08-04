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
     * @param string $documentVersionIdentity
     * @param string $versionStatus
     * @param string $versionRevision
     * @param string $validFrom
     * @param string $validUntil
     * @param string $supersedesVersionId
     */
    public function __construct(public string $table = 'context_chunks', public string $chunkId = 'chunk_id', public string $documentId = 'document_id', public string $documentVersion = 'document_version', public string $ingestionState = 'ingestion_state', public string $tenantId = 'tenant_id', public string $collection = 'collection', public string $status = 'status', public string $content = 'content', public string $position = 'position', public string $metadata = 'metadata', public string $embedding = 'embedding', public string $embeddingProvider = 'embedding_provider', public string $embeddingModel = 'embedding_model', public string $embeddingDimensions = 'embedding_dimensions', public string $embeddingRevision = 'embedding_revision', public string $embeddingFingerprint = 'embedding_space_fingerprint', public string $documentVersionIdentity = 'document_version_identity', public string $versionStatus = 'version_status', public string $versionRevision = 'version_revision', public string $validFrom = 'valid_from', public string $validUntil = 'valid_until', public string $supersedesVersionId = 'supersedes_version_id')
    {
        foreach ((array)$this as $identifier) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
                throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
            }
        }
    }
}
