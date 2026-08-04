<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class VersionedSourceProvenance
{
    public function __construct(
        public string $documentVersionId,
        public ?int $revision = null,
        public ?string $status = null,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
        public ?string $supersedesVersionId = null,
    ) {
        if (trim($documentVersionId) === '') {
            throw new InvalidArgumentException('Document version id cannot be empty.');
        }
    }
}
