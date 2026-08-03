<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class QueryMatch
{
    public function __construct(public string $query, public int $rank, public float $distance)
    {
        if (trim($query) === '' || $rank < 1 || !is_finite($distance) || $distance < 0) {
            throw new InvalidArgumentException('A query match requires a query, positive rank, and valid distance.');
        }
    }
}
