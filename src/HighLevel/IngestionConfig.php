<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\HighLevel;

final readonly class IngestionConfig
{
    public function __construct(
        public ?int $batchSize = null,
        public ?int $concurrency = null,
        public ?int $chunkSize = null,
        public ?int $chunkOverlap = null,
    ) {
    }
}
