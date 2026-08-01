<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\VectorStore;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

final readonly class DocumentDeleteQuery
{
    public function __construct(
        public string $tenantId,
        public string $collection,
        public string $documentId,
        public ?EmbeddingSpace $space = null,
    ) {
        if (trim($tenantId) === '' || trim($collection) === '' || trim($documentId) === '') {
            throw new InvalidArgumentException('Document deletion scope values cannot be empty.');
        }
    }
}
