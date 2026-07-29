<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use RuntimeException;
use Throwable;

final class BatchWindowException extends RuntimeException
{
    /**
     * @param list<int> $started
     * @param list<int> $completed
     * @param list<int> $discarded
     * @param array<int,int> $chunkCounts
     */
    public function __construct(public readonly int $failedSequence, public readonly array $started, public readonly array $completed, public readonly array $discarded, public readonly array $chunkCounts, Throwable $previous)
    {
        parent::__construct("Embedding batch {$failedSequence} failed; remaining started batches were drained and discarded.", 0, $previous);
    }
}
