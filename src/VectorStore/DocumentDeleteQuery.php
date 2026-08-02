<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\VectorStore;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

final readonly class DocumentDeleteQuery
{
    /**
     * @param string $tenantId
     * @param string|null $collection
     * @param string|null $documentId
     * @param EmbeddingSpace|null $space
     */
    public function __construct(
        public string $tenantId,
        public ?string $collection = null,
        public ?string $documentId = null,
        public ?EmbeddingSpace $space = null,
    ) {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('Document deletion tenant cannot be empty.');
        }
        if ($collection !== null && trim($collection) === '') {
            throw new InvalidArgumentException('Document deletion collection cannot be empty when specified.');
        }
        if ($documentId !== null && trim($documentId) === '') {
            throw new InvalidArgumentException('Document deletion document id cannot be empty when specified.');
        }
    }
}
