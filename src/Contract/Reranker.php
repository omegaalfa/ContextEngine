<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;

/** Reordena candidatos já recuperados sem executar uma nova busca. */
interface Reranker
{
    /**
     * @param list<VectorSearchResult> $results
     * @return list<VectorSearchResult>
     */
    public function rerank(Question $question, array $results): array;
}
