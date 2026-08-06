<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Metrics;

final readonly class RetrievalMetrics
{
    public function __construct(
        public ?float $recall = null,
        public ?float $precision = null,
        public ?float $reciprocalRank = null,
        public ?float $hitRate = null,
    ) {}
}
