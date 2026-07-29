<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Embedding;

use Omegaalfa\ContextEngine\Exception\InvalidEmbeddingException;

final readonly class Embedding
{
    /** @var list<float> */ public array $values;
    /** @param array<int, mixed> $values */
    public function __construct(array $values, public EmbeddingSpace $space)
    {
        if ($values === [] || !array_is_list($values)) {
            throw new InvalidEmbeddingException('Embedding values are required.');
        }
        foreach ($values as $value) {
            if (!is_int($value) && !is_float($value) || !is_finite((float) $value)) {
                throw new InvalidEmbeddingException('Embedding values must be finite numbers.');
            }
        }
        $this->values = array_map(static fn (int|float $v): float => (float) $v, $values);
        if (count($this->values) !== $space->dimensions) {
            throw new InvalidEmbeddingException("Embedding space expects {$space->dimensions} dimensions, got " . count($this->values) . '.');
        }
    }
    public function dimensions(): int
    {
        return count($this->values);
    }
    public function model(): string
    {
        return $this->space->model;
    }
}
