<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;

/**
 * Optional full-text search capability over the same chunk storage used by VectorStore.
 */
interface LexicalSearchStore
{
    /**
     * @return list<VectorSearchResult>
     */
    public function searchLexical(LexicalSearchQuery $query): array;
}
