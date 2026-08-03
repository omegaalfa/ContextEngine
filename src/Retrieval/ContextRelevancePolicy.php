<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class ContextRelevancePolicy
{
    public function __construct(
        public float $maximumDistanceGap = 0.08,
        public int $minimumSources = 1,
        public int $maximumSources = 5,
        public bool $preferSameDocument = true,
    ) {
        if (!is_finite($maximumDistanceGap) || $maximumDistanceGap < 0) {
            throw new InvalidArgumentException('Maximum distance gap must be finite and non-negative.');
        }
        if ($minimumSources < 1 || $maximumSources < $minimumSources) {
            throw new InvalidArgumentException('Context source limits must be positive and ordered.');
        }
    }
}
