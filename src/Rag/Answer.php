<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;

final readonly class Answer
{
    /** @param list<VectorSearchResult> $sources */
    public function __construct(public string $content, public array $sources = []) {}
}
