<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

final readonly class IngestionReport
{
    /** @param list<int> $affectedBatchSequences */
    public function __construct(
        public int $batchesPlanned,
        public int $batchesStarted,
        public int $batchesCompleted,
        public int $batchesPersisted,
        public int $batchesDiscarded,
        public int $chunksProduced,
        public int $chunksSent,
        public int $chunksPersisted,
        public ?string $firstFailure,
        public array $affectedBatchSequences,
        public bool $complete,
    ) {
        if (min($batchesPlanned, $batchesStarted, $batchesCompleted, $batchesPersisted, $batchesDiscarded, $chunksProduced, $chunksSent, $chunksPersisted) < 0) {
            throw new \InvalidArgumentException('Report counters cannot be negative.');
        }
    }
}
