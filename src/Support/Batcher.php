<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Support;

use InvalidArgumentException;

final class Batcher
{
    /**
     * @template T
     * @param iterable<T> $items
     * @return iterable<non-empty-list<T>>
     */
    public function batches(iterable $items, int $size): iterable
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Batch size must be positive.');
        }
        $batch = [];
        foreach ($items as $item) {
            $batch[] = $item;
            if (count($batch) === $size) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield $batch;
        }
    }
}
