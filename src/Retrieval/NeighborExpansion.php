<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class NeighborExpansion
{
    /**
     * @param int $before
     * @param int $after
     */
    public function __construct(public int $before = 0, public int $after = 0)
    {
        if ($before < 0 || $after < 0 || $before > 20 || $after > 20) {
            throw new InvalidArgumentException('Neighbor ranges must be between zero and twenty.');
        }
    }

    /**
     * @return bool
     */
    public function enabled(): bool
    {
        return $this->before > 0 || $this->after > 0;
    }
}
