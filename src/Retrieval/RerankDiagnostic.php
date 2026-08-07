<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

/** Mostra quanto um candidato subiu ou desceu após o reranking. */
final readonly class RerankDiagnostic
{
    public function __construct(
        public string $chunkId,
        public int $rankBefore,
        public int $rankAfter,
        public float $vectorDistance,
        public ?float $lexicalScore,
        public ?float $fusionScore,
        public ?float $rerankerScore,
    ) {}
}
