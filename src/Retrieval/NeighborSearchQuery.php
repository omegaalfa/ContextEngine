<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

final readonly class NeighborSearchQuery
{
    public function __construct(
        public string $tenantId,
        public string $collection,
        public string $status,
        public string $documentId,
        public string $documentVersion,
        public EmbeddingSpace $space,
        public int $position,
        public int $before,
        public int $after,
    ) {
        if (trim($tenantId) === '' || trim($collection) === '' || trim($status) === ''
            || trim($documentId) === '' || trim($documentVersion) === '' || $position < 0
            || $before < 0 || $after < 0) {
            throw new InvalidArgumentException('Neighbor search scope and position must be valid.');
        }
    }
}
