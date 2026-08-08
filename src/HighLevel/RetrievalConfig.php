<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\HighLevel;

final readonly class RetrievalConfig
{
    public function __construct(
        public ?bool $heuristicQueryPlanning = null,
        public ?int $retrievalLimit = null,
        public ?int $fusedLimit = null,
        public ?int $contextChunkLimit = null,
        public ?float $maximumDistance = null,
        public ?bool $hybridSearch = null,
        public ?float $vectorWeight = null,
        public ?float $lexicalWeight = null,
        public ?int $lexicalCandidateLimit = null,
        public ?int $rerankerCandidateLimit = null,
        public ?string $textSearchConfiguration = null,
    ) {}
}
