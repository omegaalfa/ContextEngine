<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use InvalidArgumentException;

final readonly class BatchExecutionProgress
{
    public function __construct(
        public int $scheduled,
        public int $started,
        public int $completed,
        public int $discarded,
        public int $chunksScheduled,
    ) {
        if (min($scheduled, $started, $completed, $discarded, $chunksScheduled) < 0) {
            throw new InvalidArgumentException('Batch execution counters cannot be negative.');
        }
        if ($started > $scheduled || $completed > $started || $discarded > $completed) {
            throw new InvalidArgumentException('Batch execution counters are inconsistent.');
        }
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0);
    }
}
