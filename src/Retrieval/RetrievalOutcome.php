<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

final readonly class RetrievalOutcome
{
    /** @param list<VectorSearchResult> $results */
    public function __construct(public array $results, public RetrievalDiagnostics $diagnostics) {}
}
