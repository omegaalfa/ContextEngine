<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Metrics;

final readonly class GenerationMetrics
{
    public function __construct(
        public ?float $exactMatch = null,
        public ?float $containsExpectedTerms = null,
        public ?float $strictExactMatch = null,
        public ?float $normalizedExactMatch = null,
    ) {}
}
