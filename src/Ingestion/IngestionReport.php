<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use InvalidArgumentException;

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
        public ?IngestionFailure $failure,
        public array $affectedBatchSequences,
        public bool $complete,
        public int $documentsActivated = 0,
        public int $documentVersionsFailed = 0,
    ) {
        if (min($batchesPlanned, $batchesStarted, $batchesCompleted, $batchesPersisted, $batchesDiscarded, $chunksProduced, $chunksSent, $chunksPersisted, $documentsActivated, $documentVersionsFailed) < 0) {
            throw new InvalidArgumentException('Report counters cannot be negative.');
        }
        if ($batchesStarted > $batchesPlanned || $batchesCompleted > $batchesStarted || $batchesPersisted > $batchesCompleted || $batchesDiscarded > $batchesCompleted) {
            throw new InvalidArgumentException('Report batch counters are inconsistent.');
        }
        if ($chunksSent > $chunksProduced || $chunksPersisted > $chunksSent) {
            throw new InvalidArgumentException('Report chunk counters are inconsistent.');
        }
        if ($complete === ($failure !== null)) {
            throw new InvalidArgumentException('Complete reports cannot contain failures and partial reports must contain one.');
        }
        if ($complete && $documentVersionsFailed !== 0 || !$complete && $documentVersionsFailed < 1) {
            throw new InvalidArgumentException('Document version counters must reflect the ingestion outcome.');
        }
    }

    public function firstFailure(): ?string
    {
        return $this->failure?->message;
    }
}
