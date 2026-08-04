<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use Omegaalfa\ContextEngine\Retrieval\RetrievalDiagnostics;
use Omegaalfa\ContextEngine\Retrieval\VersionedSourceProvenance;

final readonly class RagDiagnostics
{
    /**
     * @param array<string, float> $timingsMilliseconds
     * @param list<VersionedSourceProvenance|null> $sourceProvenance
     */
    public function __construct(
        public RetrievalDiagnostics $retrieval,
        public int $promptCharacters,
        public bool $modelCalled,
        /** @var array<string, float> */
        public array $timingsMilliseconds,
        /** @var list<VersionedSourceProvenance|null> */
        public array $sourceProvenance = [],
    ) {}
}
