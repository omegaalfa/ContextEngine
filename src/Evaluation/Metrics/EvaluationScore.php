<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Metrics;

use InvalidArgumentException;

final readonly class EvaluationScore
{
    public function __construct(public string $name, public float $value, public bool $passed)
    {
        if (trim($name) === '' || !is_finite($value) || $value < 0 || $value > 1) {
            throw new InvalidArgumentException('Evaluation score must have a name and a finite value between 0 and 1.');
        }
    }
}
