<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;

interface VectorStore
{
    /** @param non-empty-list<EmbeddedChunk> $chunks */ public function storeBatch(array $chunks): void;
    /** @return list<VectorSearchResult> */ public function search(VectorSearchQuery $query): array;
}
