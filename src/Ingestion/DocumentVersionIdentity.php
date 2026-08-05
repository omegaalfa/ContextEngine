<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

final readonly class DocumentVersionIdentity
{
    public function __construct(
        public string $documentId,
        public string $versionId,
        public int $revision,
    ) {}
}
