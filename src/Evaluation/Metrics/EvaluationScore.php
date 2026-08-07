<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Metrics;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Evaluation\EvaluationStatus;

final readonly class EvaluationScore
{
    public EvaluationStatus $status;

    public function __construct(public string $name, public float $value, public bool $passed, public mixed $details = null)
    {
        if (trim($name) === '' || !is_finite($value) || $value < 0 || $value > 1) {
            throw new InvalidArgumentException('Evaluation score must have a name and a finite value between 0 and 1.');
        }
        $this->status = $passed ? EvaluationStatus::PASSED : EvaluationStatus::FAILED;
    }
}
