<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\Retrieval\VersionedSourceProvenance;

final readonly class Answer
{
    /**
     * @param list<VectorSearchResult> $sources
     * @param list<VersionedSourceProvenance|null> $sourceProvenance
     */
    public function __construct(
        public string $content,
        /** @var list<VectorSearchResult> */
        public array $sources = [],
        /** @var list<VersionedSourceProvenance|null> */
        public array $sourceProvenance = [],
    ) {}
}
