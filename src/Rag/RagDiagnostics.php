<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use Omegaalfa\ContextEngine\Retrieval\RetrievalDiagnostics;

final readonly class RagDiagnostics
{
    /** @param array<string, float> $timingsMilliseconds */
    public function __construct(
        public RetrievalDiagnostics $retrieval,
        public int $promptCharacters,
        public bool $modelCalled,
        public array $timingsMilliseconds,
    ) {}
}
