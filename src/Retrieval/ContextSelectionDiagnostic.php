<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class ContextSelectionDiagnostic
{
    public function __construct(
        public string $chunkId,
        public bool $selected,
        public ContextSelectionReason $reason,
    ) {
        if (trim($chunkId) === '') {
            throw new InvalidArgumentException('Selection diagnostic chunk id cannot be empty.');
        }
    }
}
