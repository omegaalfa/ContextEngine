<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

final readonly class VersionConflict
{
    public function __construct(
        public string $documentId,
        public string $leftVersionId,
        public string $rightVersionId,
        public string $reason,
    ) {}
}
