<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

final readonly class IngestionFailure
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $documentId = null,
        public ?int $batchSequence = null,
    ) {
        if (trim($code) === '' || trim($message) === '') {
            throw new \InvalidArgumentException('Ingestion failure code and message cannot be empty.');
        }
        if ($batchSequence !== null && $batchSequence < 0) {
            throw new \InvalidArgumentException('Batch sequence cannot be negative.');
        }
    }
}
