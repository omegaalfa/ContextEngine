<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use RuntimeException;

final class VersionValidationException extends RuntimeException
{
    /** @param list<VersionConflict> $conflicts */
    public function __construct(
        public string $documentId,
        public array $conflicts = [],
        string $message = 'Version validation failed.',
    ) {
        parent::__construct($message);
    }
}
