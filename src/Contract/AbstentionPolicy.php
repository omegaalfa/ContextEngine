<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Retrieval\AbstentionDecision;

interface AbstentionPolicy
{
    /** @param list<\Omegaalfa\ContextEngine\Retrieval\VectorSearchResult> $results */
    public function evaluate(string $question, array $results): AbstentionDecision;
}
