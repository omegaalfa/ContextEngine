<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class RetrievalPolicy
{
    public function __construct(public int $limit = 5, public VectorMetric $metric = VectorMetric::COSINE, public ?float $maximumDistance = null)
    {
        if ($limit < 1 || $limit > 100 || $maximumDistance !== null && $maximumDistance < 0) {
            throw new InvalidArgumentException('Invalid retrieval policy.');
        }
    }
}
