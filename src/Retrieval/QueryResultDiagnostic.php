<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

final readonly class QueryResultDiagnostic
{
    public function __construct(
        public string $query,
        public int $rank,
        public string $chunkId,
        public string $documentId,
        public int $position,
        public float $distance,
    ) {}
}
