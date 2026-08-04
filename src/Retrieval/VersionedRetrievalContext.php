<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use DateTimeImmutable;

final readonly class VersionedRetrievalContext
{
    public function __construct(
        public string $documentVersionId,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
        public ?string $status = null,
        public ?int $revision = null,
        public ?string $supersedesVersionId = null,
    ) {}
}
